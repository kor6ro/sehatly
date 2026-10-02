<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The OpenAPI generator, its drift check, and the DoD it doubles as
|--------------------------------------------------------------------------
|
| THE POINT OF THIS FILE IS THE MUTATION TRANSCRIPT. A drift check that cannot
| fail is a file copy, and a file copy nobody has seen fail is indistinguishable
| from a working check until the first real drift -- which is the moment a reader
| most needs to trust it. So:
|
| - `the committed document is a fresh export` establishes the GREEN control.
| - `a hand-edit to one path in docs/openapi.yaml is DETECTED and refused`
|   establishes the RED, in the same file, in the same run.
| - `a mutated route table -- a POST with no FormRequest -- IS detected` does the
|   same for the second check the command performs.
|
| Each red test restores what it changed in a `finally`, so a failing mutation
| cannot leave the repository dirty for the next test or the next executor.
|
| Everything else here is non-vacuity. A generator that emits an empty document
| passes "the file is a fresh export" trivially, so the operation count, the
| middleware-presence count and the enum count are all asserted against values
| measured from the live route table and from `docs/enums.json` -- never against
| a literal that could be edited to match a broken generator.
|
| The helpers are file-scope CLOSURES, not functions: a test file that declares
| a global function fatals the whole suite the moment a second file declares
| the same name, and this repository has more than one Pest file.
*/

use App\Http\Controllers\Api\V1\NotifikasiController;
use App\Support\OpenApi\DartContractGenerator;
use App\Support\OpenApi\OpenApiDocumentBuilder;
use App\Support\OpenApi\RouteInventory;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The committed document, parsed.
 *
 * Read fresh on every call rather than cached in a shared variable, so a test
 * that mutates the file and a test that reads it cannot see each other's bytes
 * through a stale cache. The parser is Symfony's -- the same library that wrote
 * the file -- so a failure here is a genuine "this is not the YAML the generator
 * produces" rather than a toolchain disagreement.
 *
 * @return array<string, mixed>
 */
$document = function (): array {
    $path = base_path('docs/openapi.yaml');

    expect(is_file($path))->toBeTrue('docs/openapi.yaml does not exist; run `php artisan sehatly:openapi`');

    $parsed = Yaml::parseFile($path);

    expect($parsed)->toBeArray()->toHaveKey('paths')->toHaveKey('components');

    return $parsed;
};

/**
 * The document's operations, flattened to `METHOD path` keys.
 *
 * Flattened rather than compared whole, so a failure names the one operation
 * that moved instead of dumping all 74 of them.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, array<string, mixed>>
 */
$operations = function (array $document): array {
    $flattened = [];

    foreach ($document['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            if ($method === 'parameters') {
                continue;
            }

            $flattened[strtoupper((string) $method).' '.$path] = $operation;
        }
    }

    return $flattened;
};

/**
 * The route table's `/api/v1` operations, as `METHOD path` => Route.
 *
 * `HEAD` is excluded on both sides: the router registers it on the same Route
 * object as `GET` and the generator does not publish it, so including it here
 * would make the two counts differ for a reason that has nothing to do with
 * drift.
 *
 * @return array<string, Illuminate\Routing\Route>
 */
$liveRoutes = function (): array {
    $live = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD' || $method === 'OPTIONS') {
                continue;
            }

            $live[strtoupper($method).' /'.$route->uri()] = $route;
        }
    }

    return $live;
};

/**
 * A route's middleware with the framework's own groups removed.
 *
 * @return list<string>
 */
$guards = function (Illuminate\Routing\Route $route): array {
    return array_values(array_filter(
        $route->gatherMiddleware(),
        static fn (string $m): bool => $m !== 'api' && $m !== 'web',
    ));
};

/**
 * Is this middleware entry the Sanctum guard?
 *
 * BOTH spellings count, because the router carries both: a route that names
 * `auth:sanctum` on the route keeps the alias string, while a route inside a
 * `Route::middleware('auth:sanctum')->group()` carries the resolved
 * `Illuminate\Auth\Middleware\Authenticate:sanctum`. `POST /auth/logout` is the
 * first shape and most of the patient surface is the second, so a check that
 * only matched one of them would classify half the authenticated surface as
 * anonymous -- and the failure would look like a security bug in the document
 * rather than a bug in the test.
 *
 * `$middleware` is the list; the entry is passed separately so the helper can
 * be reused for a single entry.
 */
$isSanctum = function (string $entry): bool {
    return $entry === 'auth:sanctum'
        || $entry === Authenticate::class.':sanctum'
        || str_ends_with($entry, 'Authenticate:sanctum');
};

/**
 * Does a middleware list contain an entry with this PREFIX?
 *
 * @param  list<string>  $middleware
 */
$hasPrefix = function (array $middleware, string $prefix): bool {
    foreach ($middleware as $entry) {
        if (str_starts_with($entry, $prefix)) {
            return true;
        }
    }

    return false;
};

/**
 * The Dart class name `DartContractGenerator` gives an ENUM column.
 *
 * Duplicated here rather than imported from the generator, because a test that
 * asked the generator what it produced would agree with the generator by
 * construction. This spells the rule out independently -- split on `.` and `_`,
 * upper-case each part's first letter -- so a rename in the generator fails here.
 */
$dartEnumClass = function (string $column): string {
    $parts = preg_split('/[._]+/', $column) ?: [$column];
    $name = '';

    foreach ($parts as $part) {
        $name .= strtoupper(substr($part, 0, 1)).substr($part, 1);
    }

    return 'Enum'.$name;
};

test('the generator reports the route count it actually read', function () use ($liveRoutes) {
    expect(Artisan::call('sehatly:openapi', ['--json' => true]))->toBe(0);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()->toHaveKeys(['ok', 'routes_read', 'unique_paths', 'operations', 'sha256']);
    expect($report['ok'])->toBeTrue();
    expect($report['routes_read'])->toBeGreaterThan(0, 'the generator read no routes at all');

    // Cross-checked against the live route table rather than a literal, so this
    // cannot be satisfied by editing an expected number to match a broken
    // generator.
    $live = array_keys($liveRoutes());

    expect($report['routes_read'])->toBe(count($live), 'the generator and the live route table disagree on the route count');
    expect($report['operations'])->toBe(count($live), 'one route object must yield exactly one published operation');
    expect($report['unique_paths'])->toBeLessThan($report['routes_read']);
});

test('the committed document is a fresh export -- the GREEN control for the drift check', function () {
    expect(Artisan::call('sehatly:openapi', ['--check' => true]))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('UP TO DATE');
    expect(str_contains($output, 'DRIFT'))->toBeFalse('the GREEN control reported drift; the RED test below is what drift looks like');
});

test('a hand-edit to one path in docs/openapi.yaml is DETECTED and refused -- the RED', function () {
    $path = base_path('docs/openapi.yaml');
    $original = (string) file_get_contents($path);

    // The mutation is a REAL hand-edit: rename one published path. A check that
    // still passes here would be comparing something other than the file -- the
    // route table, or a cached parse -- and that is the bug this test exists to
    // catch.
    //
    // The key is QUOTED in the YAML (`'/api/v1/konsultasi/{id}/chat':`) because
    // it contains braces, so the anchor includes the quotes. That is derived
    // from the rendered document rather than assumed: the assertion below
    // refuses to pass if the substitution is a no-op, so a dumper change that
    // stopped quoting turns this into a loud failure instead of a test that
    // proves nothing.
    $anchor = "  '/api/v1/konsultasi/{id}/chat':";
    $mutated = str_replace(
        $anchor,
        "  '/api/v1/konsultasi/{id}/chats':",
        $original,
    );

    try {
        expect($mutated)->not->toBe($original, 'the hand-edit did not apply; the mutation is a no-op and this test proves nothing');

        file_put_contents($path, $mutated);

        $exit = Artisan::call('sehatly:openapi', ['--check' => true]);
        $output = Artisan::output();

        expect($exit)->toBe(1, 'the drift check PASSED on a hand-edited document. A check that cannot fail is a file copy.');
        expect($output)->toContain('DRIFT');
        expect($output)->toContain('first difference');
        expect($output)->toContain('No file was overwritten.');

        // The refusal has to be a refusal, not a silent repair. A check that
        // overwrites the hand-edit has destroyed the evidence of the drift.
        expect((string) file_get_contents($path))->toBe($mutated, 'the check overwrote the hand-edited file instead of refusing it');

        // And the machine-readable channel must agree, because CI reads JSON.
        expect(Artisan::call('sehatly:openapi', ['--check' => true, '--json' => true]))->toBe(1);

        $report = json_decode(Artisan::output(), true);

        expect($report['ok'])->toBeFalse();
        expect($report['exit_code'])->toBe(1);
        expect($report['matches_on_disk'])->toBeFalse();
        expect($report['on_disk_sha256'])->not->toBe($report['sha256']);
    } finally {
        file_put_contents($path, $original);
    }

    // Restored, and green again -- so the red above was caused by the mutation
    // and not by some ambient breakage that both runs share.
    expect(Artisan::call('sehatly:openapi', ['--check' => true]))->toBe(0);
});

test('regeneration is deterministic: two exports over an unchanged route table are byte-identical', function () {
    $path = base_path('docs/openapi.yaml');
    $original = (string) file_get_contents($path);

    try {
        expect(Artisan::call('sehatly:openapi'))->toBe(0);
        $first = (string) file_get_contents($path);
        $firstHash = hash('sha256', $first);

        expect(Artisan::call('sehatly:openapi'))->toBe(0);
        $second = (string) file_get_contents($path);
        $secondHash = hash('sha256', $second);

        expect($secondHash)->toBe($firstHash, 'two exports over an unchanged route table differ, so --check could never be stable');
        expect($second)->toBe($first);

        fwrite(STDERR, sprintf(
            "\n  [determinism] two exports agree: sha256=%s bytes=%d\n",
            $firstHash,
            strlen($first),
        ));
    } finally {
        file_put_contents($path, $original);
    }
});

test('the generated file carries no BOM, uses LF only, and ends with exactly one newline', function () {
    $path = base_path('docs/openapi.yaml');
    $raw = (string) file_get_contents($path);

    // Read as raw BYTES for the BOM check. A text-mode read with an encoding
    // argument would strip or interpret the BOM, which is exactly the byte this
    // assertion exists to catch.
    $firstThree = file_get_contents($path, false, null, 0, 3);

    expect(bin2hex((string) $firstThree))->not->toBe('efbbbf', 'the generated file starts with a UTF-8 BOM');

    expect(str_contains($raw, "\r"))->toBeFalse('the generated file contains a CR byte');

    expect(substr($raw, -1))->toBe("\n", 'the generated file does not end with a newline');
    expect(substr($raw, -2, 1))->not->toBe("\n", 'the generated file ends with more than one newline');

    // Pure ASCII. The descriptions are written in English on purpose, so a
    // non-ASCII byte is a sign someone typed prose the next machine's locale
    // will render differently -- and this file is compared byte for byte.
    $found = preg_match('/[\x80-\xFF]/', $raw, $matches, PREG_OFFSET_CAPTURE);

    expect($found)->toBe(0, 'the generated document has a non-ASCII byte at offset '.(int) ($matches[0][1] ?? -1));
});

test('the document publishes every api/v1 route, with the middleware that guards it', function () use ($document, $operations, $liveRoutes, $guards, $isSanctum, $hasPrefix) {
    $published = $operations($document());
    $live = $liveRoutes();

    expect($published)->toHaveCount(count($live), 'the document and the live route table describe a different number of operations');
    expect(array_keys($published))->toEqualCanonicalizing(array_keys($live), 'the published operations are not the registered routes');

    $withPermission = 0;
    $withThrottle = 0;
    $withType = 0;
    $anonymous = 0;

    foreach ($live as $key => $route) {
        $middleware = $guards($route);
        $operation = $published[$key];

        expect($operation['x-laravel-action'])->toBe($route->getActionName(), $key.' publishes the wrong controller action');
        expect($operation['x-route-name'])->toBe($route->getName(), $key.' publishes the wrong route name');
        expect($operation['x-middleware'])->toEqualCanonicalizing($middleware, $key.' publishes different middleware than the route carries');

        $authenticated = false;

        foreach ($middleware as $entry) {
            if ($isSanctum($entry)) {
                $authenticated = true;

                break;
            }
        }
        $hasPermission = $hasPrefix($middleware, 'permission:');
        $hasThrottle = $hasPrefix($middleware, 'throttle:');
        $hasType = $hasPrefix($middleware, 'tipe:');

        expect($operation['security'])->toBe(
            $authenticated ? [['sanctum' => []]] : [],
            $key.' publishes the wrong security declaration',
        );

        expect($operation)->toHaveKey('responses');
        expect(array_key_exists('401', $operation['responses']))->toBe($authenticated, $key.' 401 presence does not follow auth:sanctum');
        expect(array_key_exists('403', $operation['responses']))->toBe(
            $authenticated || $hasType,
            $key.' 403 presence does not follow the permission/tipe guards',
        );
        expect(array_key_exists('429', $operation['responses']))->toBe($hasThrottle, $key.' 429 presence does not follow throttle:');
        expect(array_key_exists('404', $operation['responses']))->toBeTrue($key.' must document 404');
        expect(array_key_exists('500', $operation['responses']))->toBeTrue($key.' must document 500');

        $withPermission += $hasPermission ? 1 : 0;
        $withThrottle += $hasThrottle ? 1 : 0;
        $withType += $hasType ? 1 : 0;
        $anonymous += $authenticated ? 0 : 1;
    }

    // Non-vacuity: the loop above would pass on a document with no guards at
    // all. These four counters are what make "no guards were found" a failure
    // rather than a quiet success.
    expect($withPermission)->toBeGreaterThan(0, 'no route carries a permission guard, so the guard publication is untested');
    expect($withThrottle)->toBeGreaterThan(0, 'no route carries a throttle, so the 429 publication is untested');
    expect($withType)->toBeGreaterThan(0, 'no route carries a tipe: guard, so the 403 publication is untested');
    expect($anonymous)->toBeGreaterThan(0, 'every route is authenticated, so the anonymous `security: []` publication is untested');
});

test('every api/v1 write route has a FormRequest, and the three exemptions are documented and still real', function () {
    $inventory = new RouteInventory;

    expect($inventory->failures())->toBe([], 'the inventory could not read every FormRequest: '.implode('; ', $inventory->failures()));

    $offenders = $inventory->writesWithoutFormRequest();
    $unexempt = array_values(array_filter($offenders, static fn (array $o): bool => $o['exempt'] === false));

    expect($unexempt)->toBe([], 'unvalidated write endpoints: '.implode(', ', array_map(
        static fn (array $o): string => $o['method'].' '.$o['path'],
        $unexempt,
    )));

    // The exemption list is a literal in production code, so the risk is not
    // that it is empty -- it is that it GROWS, or that an entry goes stale.
    // Both are asserted: exactly three, and every one still a real unvalidated
    // write route.
    $exemptions = $inventory->formRequestExemptions();

    expect($exemptions)->toHaveCount(3, 'the exemption list must not grow silently; a new exemption needs a written reason AND a test');
    expect($offenders)->toHaveCount(count($exemptions), 'an exemption names a route that is no longer missing a FormRequest');

    foreach ($exemptions as $key => $reason) {
        expect($reason)->not->toBe('', $key.' has an empty exemption reason');
        expect(strlen($reason))->toBeGreaterThan(60, $key.' exemption reason is too short to be a reason');
    }

    // And the write DoD has teeth: it must actually be looking at something.
    $writes = array_filter($inventory->operations(), static fn (array $o): bool => $o['is_write'] === true);
    $validated = array_filter($writes, static fn (array $o): bool => $o['form_request'] !== null);

    expect(count($writes))->toBeGreaterThanOrEqual(20, 'far too few write endpoints for the DoD to mean anything');
    expect(count($validated))->toBeGreaterThanOrEqual(20, 'almost no write endpoint has a FormRequest');
});

test('a mutated route table -- a POST with no FormRequest -- IS detected', function () {
    // The DoD is proved able to fail the same way the drift check was: by
    // putting a violating route into the LIVE route table and observing the
    // report name it.
    //
    // The route is registered in memory and removed in the `finally`, so
    // `routes/api.php` -- which other executors own -- is not touched. The
    // handler is an existing controller method whose signature takes a bare
    // `Illuminate\Http\Request`, which is what "validates inline instead of
    // through a FormRequest" looks like from the outside.
    Route::post('api/v1/openapi-dod-probe', [NotifikasiController::class, 'bacaSemua'])
        ->middleware(['api', 'auth:sanctum'])
        ->name('openapi.dod.probe');

    try {
        $inventory = new RouteInventory;

        $offenders = array_values(array_filter(
            $inventory->writesWithoutFormRequest(),
            static fn (array $o): bool => $o['path'] === '/api/v1/openapi-dod-probe',
        ));

        expect($offenders)->toHaveCount(1, 'the probe route was not picked up by the inventory, so this test proves nothing');
        expect($offenders[0]['exempt'])->toBeFalse('a brand-new unvalidated POST was accepted as exempt');
        expect($offenders[0]['method'])->toBe('POST');

        // And the command refuses to publish while it is there -- which is the
        // behaviour that matters, because a developer adding an endpoint finds
        // out at generation time rather than in CI three todos later.
        expect(Artisan::call('sehatly:openapi', ['--json' => true]))->toBe(1, 'the command published a document containing an unvalidated POST');

        $report = json_decode(Artisan::output(), true);

        expect($report['ok'])->toBeFalse();
        expect(array_column($report['unexempt_writes'], 'path'))->toContain('/api/v1/openapi-dod-probe');

        // Nothing was written.
        expect(Artisan::call('sehatly:openapi', ['--check' => true]))->toBe(1);
    } finally {
        // The router has no `removeRoute`, so the collection is rebuilt without
        // the probe. Every other Route OBJECT is reused, not re-registered, so
        // nothing about the real table changes.
        $clean = new RouteCollection;

        foreach (app('router')->getRoutes() as $route) {
            if ($route->getName() !== 'openapi.dod.probe') {
                $clean->add($route);
            }
        }

        app('router')->setRoutes($clean);
    }

    expect(Artisan::call('sehatly:openapi', ['--check' => true]))->toBe(0);
});

test('the envelope schemas model meta as a top-level sibling and 422 as many messages per field', function () use ($document) {
    $parsed = $document();
    $schemas = $parsed['components']['schemas'];

    // `toHaveKey($key, $value)`'s SECOND argument is the expected VALUE in Pest,
    // not a failure message -- passing prose there asserts the schema's value
    // equals that prose. So membership is asserted first and the message is
    // carried by the surrounding loop variable.
    foreach (['SuccessEnvelope', 'PaginatedEnvelope', 'PaginatedMeta', 'ErrorEnvelope', 'ValidationErrorEnvelope'] as $name) {
        expect(array_keys($schemas))->toContain($name);
    }

    // `meta` is the fourth TOP-LEVEL key, a sibling of `data` -- not inside it.
    // `ApiResponse` appends it after `message` precisely so adding pagination
    // cannot renumber the three keys every existing client already reads.
    $paginated = $schemas['PaginatedEnvelope']['properties'];

    expect(array_keys($paginated))->toEqual(['success', 'data', 'message', 'meta']);
    expect($paginated['meta'])->toBe(['$ref' => '#/components/schemas/PaginatedMeta']);
    expect($schemas['PaginatedEnvelope']['required'])->toEqual(['success', 'data', 'message', 'meta']);
    // F-007: `data` is an OBJECT keyed by the resource name (`{"dokter":[...]}`),
    // which is what every list controller answers. It was `array`, a shape no
    // endpoint sends.
    expect($paginated['data']['type'])->toBe('object');

    // A non-paginated envelope has NO `meta` at all, and says so with
    // `additionalProperties: false` -- which is what makes "this response is not
    // paginated" checkable rather than a client-side guess.
    expect(array_keys($schemas['SuccessEnvelope']['properties']))->toEqual(['success', 'data', 'message']);
    expect($schemas['SuccessEnvelope']['additionalProperties'])->toBeFalse();

    // The pagination block's own keys, read from `ApiResponse::pageMeta()`, plus
    // the four OPTIONAL review aggregate keys F04 merges into the same block on
    // `GET /api/v1/dokter/{dokter}/ulasan`. They are declared here rather than in
    // a separate component because the generator selects the envelope from the
    // controller's `pageMeta()` call; they are deliberately NOT in `required`,
    // because every other list omits them.
    expect(array_keys($schemas['PaginatedMeta']['properties']))->toEqualCanonicalizing(
        [
            'current_page', 'last_page', 'per_page', 'total', 'from', 'to',
            'distribusi', 'rata_rata', 'rata_rata_komunikasi', 'rata_rata_akurasi',
        ],
    );
    expect($schemas['PaginatedMeta']['required'])->toEqualCanonicalizing(
        ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
    );

    // A 422 carries MULTIPLE MESSAGES PER FIELD. This is the assertion that
    // fails if anyone "simplifies" errors to `{field: string}`.
    foreach (['ErrorEnvelope', 'ValidationErrorEnvelope'] as $name) {
        $errors = $schemas[$name]['properties']['errors'];

        expect($errors['type'])->toBe('object', $name.' errors must be an object so an empty set encodes as {}');
        expect($errors['additionalProperties']['type'])->toBe('array', $name.' errors values must be arrays, not strings');
        expect($errors['additionalProperties']['items']['type'])->toBe('string');
        expect($errors['additionalProperties']['minItems'])->toBe(1, $name.' an empty message array carries no information');

        expect($schemas[$name]['required'])->toEqual(['success', 'message', 'errors']);
        expect($schemas[$name]['properties']['success']['const'])->toBeFalse();
    }

    // And the success envelopes are the mirror image.
    foreach (['SuccessEnvelope', 'PaginatedEnvelope'] as $name) {
        expect($schemas[$name]['properties']['success']['const'])->toBeTrue();
    }

    // A paginated operation really does reference the paginated envelope -- the
    // two envelopes only matter if the operations pick the right one.
    $references = 0;

    foreach ($parsed['paths'] as $methods) {
        foreach ($methods as $method => $operation) {
            if ($method === 'parameters') {
                continue;
            }

            $schema = $operation['responses']['200']['content']['application/json']['schema']['$ref']
                ?? $operation['responses']['201']['content']['application/json']['schema']['$ref']
                ?? null;

            if ($schema === '#/components/schemas/PaginatedEnvelope') {
                $references++;
            }
        }
    }

    expect($references)->toBeGreaterThan(0, 'no operation references PaginatedEnvelope, so the distinction is untested');
});

test('the published rate limits are the ones the application registers, not invented numbers', function () use ($operations, $document, $liveRoutes, $guards) {
    $published = $operations($document());
    $throttled = [];

    foreach ($published as $key => $operation) {
        if (isset($operation['x-ratelimit'])) {
            $throttled[$key] = $operation['x-ratelimit'];
        }
    }

    expect($throttled)->not->toBeEmpty('no operation published a rate limit, so the 429 publication is vacuous');

    $limiter = app(RateLimiter::class);
    $property = (new ReflectionObject($limiter))->getProperty('limiters');
    $property->setAccessible(true);
    $registered = $property->getValue($limiter);

    foreach ($throttled as $key => $publishedLimit) {
        $name = (string) $publishedLimit['limiter'];

        // `toContain` on an array takes needles, not a trailing message, so the
        // "why" is carried by an explicit boolean assertion alongside it.
        expect(in_array($name, array_keys($registered), true))->toBeTrue(
            $key.' publishes a limiter nobody registers; ThrottleRequests answers 500 for that',
        );

        $actual = $registered[$name](Request::create('/api/v1/', 'GET'));

        expect($publishedLimit['max'])->toBe($actual->maxAttempts, $key.' publishes a limit that differs from the registered one');
        expect($publishedLimit['decay_seconds'])->toBe($actual->decaySeconds, $key.' publishes a decay that differs from the registered one');
    }

    // Every throttled ROUTE must appear in the document, and every limiter name a
    // route mounts must be REGISTERED. A route may mount more than one: since F-002
    // `/auth/login` mounts `auth-login` and `auth-login-ip`, and the document
    // publishes the FIRST, because `x-ratelimit` is one object and the 429 body
    // names one limiter. Counting middleware instances against published operations
    // therefore stopped being the invariant the moment a second limiter was mounted
    // - the invariant is the route set, plus the "mounted but registered nowhere"
    // check that this project's mid-bracket-limiter failure mode requires.
    $routed = [];
    $terpasang = [];

    foreach ($liveRoutes() as $route) {
        foreach ($guards($route) as $guard) {
            if (str_starts_with($guard, 'throttle:')) {
                $routed[$route->uri()] = true;
                $terpasang[substr($guard, strlen('throttle:'))] = true;
            }
        }
    }

    expect($routed)->not->toBeEmpty();
    expect(count($throttled))->toBe(count($routed), 'a throttled route published no rate limit, or an unthrottled one published one');

    foreach (array_keys($terpasang) as $name) {
        expect(array_key_exists($name, $registered))->toBeTrue(
            $name.' is mounted on a route but registered nowhere; ThrottleRequests answers 500 for that',
        );
    }

    // The one multi-limiter route, named rather than left implicit: the document
    // publishes `auth-login` (5/min, per identifier) and NOT `auth-login-ip`
    // (60/min, per address). The second ceiling is real at run time and invisible in
    // the document - a contract gap recorded as backlog in the F-002 report, and
    // asserted here so it cannot disappear without somebody noticing.
    $loginKey = null;

    foreach (array_keys($throttled) as $key) {
        if (is_string($key) && str_ends_with($key, 'auth/login')) {
            $loginKey = $key;
        }
    }

    expect($loginKey)->not->toBeNull()
        ->and($throttled[$loginKey]['limiter'])->toBe('auth-login');
});

test('the ENUM catalogue in the document is exactly docs/enums.json', function () use ($document) {
    $catalogue = json_decode((string) file_get_contents(base_path('docs/enums.json')), true);

    expect($catalogue)->toBeArray()->not->toBeEmpty('docs/enums.json is empty or unreadable');

    $enums = [];

    foreach ($document()['components']['schemas'] as $name => $schema) {
        if (str_starts_with((string) $name, 'Enum') && isset($schema['title'])) {
            $enums[(string) $schema['title']] = $schema['enum'];
        }
    }

    // Every column in the catalogue gets a component: a generated client needs
    // compile-time-checked status values, and a missing one is a plain `string`.
    expect($enums)->toHaveCount(count($catalogue), 'not every ENUM column in docs/enums.json became a component schema');
    expect(array_keys($enums))->toEqualCanonicalizing(array_keys($catalogue));

    foreach ($catalogue as $column => $values) {
        // Order too, not just membership: MySQL's ENUM numeric index IS the
        // declaration order, so a sorted list would be a different type.
        expect($enums[$column])->toBe($values, $column.' is published with different values or a different order');
    }

    // Spot-checked against the DDL the way this project checks everything else:
    // well-known columns, read out of the document.
    expect($enums['booking.status'])->toContain('menunggu_pembayaran');
    expect($enums['booking.status'])->toContain('kadaluarsa');
    expect($enums['users.tipe'])->toHaveCount(7);
});

test('request bodies are the live FormRequest rules, with closed sets as real enums', function () use ($document) {
    $schemas = $document()['components']['schemas'];

    $bodies = [];

    foreach ($schemas as $name => $schema) {
        if (isset($schema['x-form-request'])) {
            $bodies[(string) $name] = $schema;
        }
    }

    expect($bodies)->not->toBeEmpty('no FormRequest produced a request-body schema');
    expect(count($bodies))->toBeGreaterThanOrEqual(30, 'far too few request bodies for the rules harvest to mean anything');

    $withProperties = 0;

    foreach ($bodies as $name => $schema) {
        $class = (string) $schema['x-form-request'];

        expect(class_exists($class))->toBeTrue($name.' names a class that does not exist');

        $short = strrchr($class, '\\');
        expect($name)->toBe(substr((string) $short, 1).'Body', $name.' is not named after its FormRequest');
        expect($schema['type'])->toBe('object');
        expect($schema['additionalProperties'])->toBeFalse();

        if ($schema['properties'] !== []) {
            $withProperties++;
        }
    }

    expect($withProperties)->toBeGreaterThanOrEqual(30, 'almost every body schema is empty, so nothing was actually harvested');

    // A closed set published from a live `Rule::in(...)`: `tipe_layanan` is
    // `booking.tipe_layanan` in the DDL, four values, and this project already
    // has a test asserting that fact -- so the document repeating it is
    // checkable rather than a new assertion.
    expect($schemas)->toHaveKey('StoreBookingRequestBody');
    expect($schemas['StoreBookingRequestBody']['properties']['tipe_layanan']['enum'])
        ->toBe(['chat', 'video_call', 'kunjungan_klinik', 'home_visit']);

    // The DoD's other half: a tenant key stays prohibited, and is published as
    // such rather than silently accepted.
    expect($schemas['StoreBookingRequestBody']['properties']['pasien_id']['x-laravel-prohibited'])->toBeTrue();
    expect($schemas['StoreBookingRequestBody']['required'])->not->toContain('pasien_id');

    // And a required field is in `required`, derived from the live rule.
    expect($schemas['StoreBookingRequestBody']['required'])->toContain('dokter_id');

    // A `date_format:Y-m-d` rule became a real `format: date`, and a
    // `date_format:H:i:s` became a described string rather than a wrong format.
    expect($schemas['StoreBookingRequestBody']['properties']['tanggal_kunjungan']['format'])->toBe('date');
    expect($schemas['StoreBookingRequestBody']['properties']['slot_mulai'])->not->toHaveKey('format');

    // A closure rule is published as a marker, not invented as a oneOf.
    expect($schemas['StoreBookingRequestBody']['properties']['jadwal_id']['x-laravel-rule'])->toContain('closure');
});

test('the operation ids are unique and derived from the method and path', function () use ($operations, $document) {
    $flattened = $operations($document());
    $ids = [];

    foreach ($flattened as $key => $operation) {
        expect(array_key_exists('operationId', $operation))->toBeTrue($key.' publishes no operationId');

        $id = (string) $operation['operationId'];

        expect($id)->toMatch('/^[a-z][A-Za-z0-9]*$/', $key.' publishes an operationId a generated client cannot turn into a method name');

        expect(array_key_exists($id, $ids))->toBeFalse(
            'two operations share the operationId '.$id.'; a generated client would collapse them',
        );

        $ids[$id] = $key;
    }

    expect($ids)->toHaveCount(count($flattened));
});

test('the committed document is what the builder produces, byte for byte', function () {
    // The command is not the only thing that can produce the file: the builder
    // renders the same bytes in memory. This is what makes `--check` a claim
    // about the CODE rather than about a file somebody pasted into place.
    $fromBuilder = (new OpenApiDocumentBuilder(new RouteInventory))->render();
    $fromDisk = (string) file_get_contents(base_path('docs/openapi.yaml'));

    expect(hash('sha256', $fromBuilder))->toBe(
        hash('sha256', $fromDisk),
        'the committed document is not what the builder produces',
    );
});

test('the generated Dart files are a fresh export, and a hand-edit to one is DETECTED', function () {
    $directory = base_path('packages/sehatly_api_client/lib/src/generated');
    $originals = [];

    foreach (DartContractGenerator::FILES as $name) {
        $path = $directory.'/'.$name;

        expect(is_file($path))->toBeTrue($name.' was not generated');

        $originals[$name] = (string) file_get_contents($path);
    }

    // GREEN control first: every file is currently fresh.
    expect(Artisan::call('sehatly:openapi', ['--check' => true]))->toBe(0);

    $target = $directory.'/paths_table.dart';
    $mutated = str_replace(
        "const String apiv1dokter = '/api/v1/dokter';",
        "const String apiv1dokter = '/api/v1/doctor';",
        $originals['paths_table.dart'],
    );

    try {
        expect($mutated)->not->toBe($originals['paths_table.dart'], 'the Dart hand-edit did not apply');

        file_put_contents($target, $mutated);

        // RED: the same command that catches a hand-edit to the YAML catches a
        // hand-edit to the Dart, because both are rendered from one walk.
        expect(Artisan::call('sehatly:openapi', ['--check' => true]))->toBe(1, 'the drift check passed on a hand-edited Dart file');

        $output = Artisan::output();

        expect($output)->toContain('DRIFT');
        expect($output)->toContain('paths_table.dart');
        expect($output)->toContain('No file was overwritten.');
        expect((string) file_get_contents($target))->toBe($mutated, 'the check repaired the hand-edit instead of refusing it');

        expect(Artisan::call('sehatly:openapi', ['--check' => true, '--json' => true]))->toBe(1);

        $report = json_decode(Artisan::output(), true);

        expect($report['dart']['paths_table.dart']['matches_on_disk'])->toBeFalse();
        expect($report['dart']['enums.dart']['matches_on_disk'])->toBeTrue('the report names the wrong file as drifted');
        expect($report['dart_all_fresh'])->toBeFalse();
    } finally {
        file_put_contents($target, $originals['paths_table.dart']);
    }

    expect(Artisan::call('sehatly:openapi', ['--check' => true]))->toBe(0);
});

test('the generated Dart enums are exactly docs/enums.json, in declaration order', function () use ($dartEnumClass) {
    $catalogue = json_decode(
        (string) file_get_contents(base_path('docs/enums.json')),
        true,
    );

    expect($catalogue)->toBeArray()->not->toBeEmpty();

    $source = (string) file_get_contents(base_path('packages/sehatly_api_client/lib/src/generated/enums.dart'));

    foreach ($catalogue as $column => $values) {
        $expected = $dartEnumClass((string) $column);

        // `strpos(...) !== false` rather than `toContain($needle, $message)`:
        // Pest reads `toContain`'s second argument as another NEEDLE on a string
        // expectation, so the failure message would itself be asserted absent.
        expect(str_contains($source, 'enum '.$expected.' {'))->toBeTrue(
            $column.' produced no Dart enum (expected class '.$expected.')',
        );
        expect(str_contains($source, "static const String column = '".$column."';"))->toBeTrue(
            $column.' does not name its own DDL key',
        );

        // The values appear in DECLARATION order inside the enum body, because
        // MySQL's numeric index is that order and a sorted list is a different
        // type. Checked as a positional sequence, not as membership.
        $position = 0;

        foreach ($values as $value) {
            $needle = "('".$value."')";
            $found = strpos($source, $needle, $position);

            expect($found)->not->toBe(false, $column.' is missing the value '.$value);
            $position = $found + strlen($needle);
        }
    }

    // And the file imports nothing, least of all Flutter.
    expect($source)->not->toContain('import ');
    expect($source)->not->toContain('package:flutter');
});
