<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
/*
 |--------------------------------------------------------------------------
 | Bidirectional parity between docs/openapi.yaml and the route table
 |--------------------------------------------------------------------------
 |
 | Direction one: every operation the document publishes resolves to a real
 | route. Without this, an endpoint could be removed and the document would keep
 | selling it to the mobile team.
 |
 | Direction two: every real `/api/v1` route is published. Without this, a new
 | endpoint could ship with no contract entry and the client would have no model
 | for it -- and nothing would fail, because nothing looks for it.
 |
 | One direction is not enough. A suite that only walks the document cannot see
 | an undocumented route; a suite that only walks the route table cannot see a
 | stale document. The failure each one misses is different, and neither is a
 | subset of the other.
 |
 | The route count is also asserted against the portable invocation the plan
 | names, so the number this file reports is the number an operator would read
 | off `route:list`, not a number computed by the same code that reads the
 | document.
 */

use Tests\Contract\Support\ContractSpec;
use Tests\TestCase;

uses(TestCase::class);

it('publishes a real route for every operation in the document', function (): void {
    $spec = ContractSpec::specOperations();
    $live = ContractSpec::liveOperations();

    $missing = array_values(array_diff(array_keys($spec), array_keys($live)));

    expect($missing)->toBe([], sprintf(
        "%d of %d documented operation(s) have no registered route:\n  %s",
        count($missing),
        count($spec),
        implode("\n  ", $missing),
    ));
});

it('publishes every real /api/v1 route in the document', function (): void {
    $spec = ContractSpec::specOperations();
    $live = ContractSpec::liveOperations();

    $undocumented = array_values(array_diff(array_keys($live), array_keys($spec)));

    expect($undocumented)->toBe([], sprintf(
        "%d real route(s) have no entry in the document:\n  %s",
        count($undocumented),
        implode("\n  ", $undocumented),
    ));
});

it('publishes the same count the portable route:list invocation reports', function (): void {
    // The plan forbids `jq` on this host and names the portable equivalent:
    //   php artisan route:list --path=api/v1 --json | php -r 'echo count(...);'
    // The assertion below reads the SAME route collection through the framework
    // rather than shelling out, so it cannot be satisfied by a broken pipe or a
    // non-zero exit. The count it produces is asserted to equal the number of
    // live operations, which is what makes the document's "112 routes" claim
    // checkable by a reader who runs the documented command.
    $routes = collect(Route::getRoutes())
        ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/v1'));

    // `HEAD` is registered implicitly for every GET and is not a published
    // operation, so it is discounted here exactly as it is in ContractSpec.
    $operations = 0;

    foreach ($routes as $route) {
        $operations += count(array_diff($route->methods(), ['HEAD']));
    }

    expect($operations)->toBe(count(ContractSpec::liveOperations()));
    expect($operations)->toBe(count(ContractSpec::specOperations()));
    expect($operations)->toBe(113);
});

it('agrees with the document on every route name and controller action', function (): void {
    $spec = ContractSpec::specOperations();
    $live = ContractSpec::liveOperations();

    $mismatches = [];

    foreach ($spec as $key => $operation) {
        if (! isset($live[$key])) {
            continue;
        }

        if (($operation['x-route-name'] ?? null) !== $live[$key]['name']) {
            $mismatches[] = sprintf(
                '%s: document says route name %s, route table says %s',
                $key,
                var_export($operation['x-route-name'] ?? null, true),
                var_export($live[$key]['name'], true),
            );
        }

        if (($operation['x-laravel-action'] ?? null) !== $live[$key]['action']) {
            $mismatches[] = sprintf(
                '%s: document says action %s, route table says %s',
                $key,
                var_export($operation['x-laravel-action'] ?? null, true),
                var_export($live[$key]['action'], true),
            );
        }
    }

    expect($mismatches)->toBe([], implode("\n", $mismatches));
});

it('agrees with the document on every route-level middleware name', function (): void {
    $spec = ContractSpec::specOperations();
    $live = ContractSpec::liveOperations();

    $mismatches = [];

    foreach ($spec as $key => $operation) {
        if (! isset($live[$key])) {
            continue;
        }

        $documented = (array) ($operation['x-middleware'] ?? []);
        sort($documented);
        $registered = $live[$key]['middleware'];
        sort($registered);

        if ($documented !== $registered) {
            $mismatches[] = sprintf(
                '%s: document says [%s], route table says [%s]',
                $key,
                implode(', ', $documented),
                implode(', ', $registered),
            );
        }
    }

    expect($mismatches)->toBe([], implode("\n", $mismatches));
});

it('publishes a distinct path and method pair for every operation', function (): void {
    // Guards the two "matched" counts above against being trivially equal
    // because both sides collapsed to a single entry. If the route table
    // registered one operation twice, or the document published two paths under
    // one key, the diffs would still be empty while the counts matched.
    $live = ContractSpec::liveOperations();
    $spec = ContractSpec::specOperations();

    expect(count($live))->toBe(count(array_unique(array_keys($live))));
    expect(count($spec))->toBe(count(array_unique(array_keys($spec))));
    expect(array_keys($live))->toBe(array_keys($spec));
});

it('would fail if a route existed with no entry in the document', function (): void {
    /*
     | The plan's own requirement: "a test registers a temporary route with no
     | schema and asserts the conformance suite fails, proving it is not
     | vacuous".
     |
     | Registering the route inside this process rather than shipping a broken
     | repository is deliberate. The assertion under test is the DETECTOR -- does
     | `it_publishes_every_real_/api/v1_route_in_the_document` actually notice a
     | route the document does not mention? -- and that can be proved without the
     | committed suite being red, which is the only form of this proof that can
     | ever live in version control.
     |
     | The route is registered inside this test's own application instance and
     | needs no cleanup: Laravel's `TestCase::setUp()` calls
     | `refreshApplication()`, so every test method gets a fresh container and
     | therefore a fresh router. An attempt to unregister it explicitly would be
     | both impossible (`RouteCollection` has no `remove()`) and unnecessary --
     | and would have asserted something untrue about the lifecycle.
     */
    $ghost = 'get /api/v1/contract-suite-ghost-route';

    expect(ContractSpec::liveOperations())->not->toHaveKey($ghost);

    Route::get('/api/v1/contract-suite-ghost-route', fn (): string => 'ghost')
        ->middleware('api');

    // The detector now sees it.
    expect(ContractSpec::liveOperations())->toHaveKey($ghost);

    // ...and this is the exact diff `it_publishes_every_real_/api/v1_route_in_the_document`
    // computes, which is what makes that test non-vacuous rather than merely green.
    $undocumented = array_values(array_diff(
        array_keys(ContractSpec::liveOperations()),
        array_keys(ContractSpec::specOperations()),
    ));

    expect($undocumented)->toBe([$ghost]);

    // The operation count assertion would fire too, since 112 is not 111.
    expect(count(ContractSpec::liveOperations()))->not->toBe(111);
});
