<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * The live `/api/v1` route table, reduced to the facts an OpenAPI document needs.
 *
 * ## Why this walks `Route::getRoutes()` and nothing else
 *
 * The point of todo 53 is that the published contract cannot drift from the
 * running application. A hand-written endpoint list is a copy: correct on the
 * day it is written and wrong the first time a route is added, renamed or
 * deleted, with nothing failing. So paths, methods, middleware and request
 * rules are all READ here, from the same objects the HTTP kernel dispatches
 * on, and nothing in this class is transcribed from `routes/api.php`.
 *
 * Three consequences worth stating, because they are why the walk is worth more
 * than the document it produces:
 *
 * 1. **Middleware becomes documentation for free.** `permission:booking.buat`
 *    and `tipe:dokter` are on the route, so they appear in the spec. A spec that
 *    had to maintain that list separately would be wrong the first time.
 * 2. **The DoD check falls out of the same walk.** Whether a write endpoint has
 *    a `FormRequest` is answered by reflecting the controller method the route
 *    already points at ({@see formRequestFor()}), not by grepping source.
 * 3. **The route count is a measurement**, taken from the collection the kernel
 *    dispatches on rather than from a list this project maintains.
 *
 * ## The `api/v1` filter is on the URI, not on a group name
 *
 * `bootstrap/app.php` mounts `routes/api.php` under `apiPrefix: 'api/v1'` and
 * `Route::getRoutes()` reports the already-prefixed URI, so the filter is
 * `str_starts_with($uri, 'api/v1')`. That correctly excludes `/up`, the SPA
 * shell from `routes/web.php`, and `GET|POST /api/broadcasting/auth` --
 * which `withBroadcasting()` registers under `api`, not `api/v1`, deliberately,
 * because the Dart realtime socket posts there.
 *
 * ## Why the `FormRequest` is constructed by hand
 *
 * Reading a `FormRequest`'s rules means calling `rules()`, and
 * `App\Http\Requests\Referensi\IndexReferensiRequest` reads
 * `$this->route()?->getName()` to decide which endpoint it serves. The instance
 * therefore cannot come from the container: for a `FormRequest` the container
 * triggers `validateResolved()` on the empty payload and throws a
 * `ValidationException` naming every required field. Instead the request is
 * constructed with an empty payload and a route resolver, which is the state
 * the framework itself puts it in before validation runs.
 *
 * ## Reflection failures are collected, never swallowed
 *
 * A `FormRequest` whose `rules()` throws is a real defect -- the route would
 * throw for a client too -- so it lands in {@see failures()} and the command
 * refuses to publish. Emitting a body-less schema instead would produce a spec
 * wrong in the direction nobody notices: it would look complete.
 *
 * ## Closure rules become a marker, not a guess
 *
 * Some rules are closures (a cross-row existence check, a `required_if`
 * guard). Their JSON-Schema equivalent is not derivable, so they are published
 * as `x-laravel-rule: closure` beside the string rules they accompany.
 * Inventing a `oneOf` for a closure would be a lie a generated client compiles
 * against.
 *
 * @see OpenApiDocumentBuilder for how this data becomes YAML
 */
final class RouteInventory
{
    public const API_PREFIX = 'api/v1';

    /**
     * The three write routes this project documents as legitimately
     * `FormRequest`-free, keyed `METHOD path`.
     *
     * A literal list, deliberately, and the only literal list in this class. A
     * derived rule would be worse in both directions: "any route without
     * `auth:sanctum`" would excuse every future unauthenticated write endpoint
     * from having validation, and "any route whose controller takes a bare
     * `Request`" is the violation being detected. Each entry below names one
     * route and the specific fact that excuses it.
     *
     * {@see FormRequestExemptionPolicyTest} asserts every entry here is still a
     * real, still-write, still-unvalidated route, and that the count is exactly
     * three -- so a route that later grows a `FormRequest` makes its own
     * exemption fail loudly instead of leaving a stale hole, and a fourth
     * unvalidated write route fails rather than being quietly added here.
     *
     * Two distinct reasons, and the difference matters:
     *
     * - **no body to validate** (`PUT /notifikasi/{id}/baca`,
     *   `PUT /notifikasi/baca-semua`): the write is a state transition on rows
     *   the caller's identity already selects. The id is a PATH parameter, and a
     *   `FormRequest` with an empty `rules()` would validate nothing while
     *   appearing to. Authorisation is `permission:notifikasi.lihat` plus the
     *   `user_id` filter in the query; there is no caller-supplied field left to
     *   rule on. An empty-rules `FormRequest` is ceremony that a later reader
     *   would trust.
     * - **body verified, not field-validated** (`POST /webhook/payment/{gateway}`):
     *   a payment gateway is not a user of this system, so the route is
     *   unauthenticated by design and the HMAC-SHA256 over the raw body is what
     *   stands in for a token. The payload is gateway-specific and cannot be
     *   described by a shared rule set.
     *
     * @var array<string, string>
     */
    private const FORM_REQUEST_EXEMPTIONS = [
        'POST /webhook/payment/{gateway}' => 'body verified by HMAC-SHA256 over the raw body, not field-validated: '
            .'a payment gateway holds no Sanctum token and cannot be given one, and its payload is '
            .'gateway-specific.',
        'PUT /notifikasi/{id}/baca' => 'no body: the id is a PATH parameter and the write is a `dibaca_at` '
            .'transition on a row selected by the caller\'s own `user_id`. There is no caller-supplied field '
            .'left to validate, so a FormRequest would have an empty `rules()` and imply a contract it does '
            .'not make.',
        'PUT /notifikasi/baca-semua' => 'no body: a bulk `dibaca_at` transition over every unread row the '
            .'caller owns. Same reasoning as `PUT /notifikasi/{id}/baca` -- the request names nothing the '
            .'server could check.',
    ];

    /** @var list<array<string, mixed>> */
    private array $operations = [];

    /** @var list<string> */
    private array $failures = [];

    private int $routesRead = 0;

    public function __construct()
    {
        $this->walk();
    }

    /**
     * Every registered `/api/v1` operation, sorted for deterministic output.
     *
     * Sorted by (path, method) with `strcmp`: two runs of the generator must
     * produce byte-identical files, and registration order in `routes/api.php`
     * is an authoring preference rather than an API fact.
     *
     * @return list<array<string, mixed>>
     */
    public function operations(): array
    {
        $sorted = $this->operations;

        usort($sorted, static function (array $a, array $b): int {
            $byPath = strcmp((string) $a['path'], (string) $b['path']);

            return $byPath !== 0 ? $byPath : strcmp((string) $a['method'], (string) $b['method']);
        });

        return $sorted;
    }

    /**
     * How many `Route` objects under `api/v1` the walk read.
     *
     * This is the number the report quotes. It is NOT the count of distinct
     * paths ({@see uniquePaths()}, which is smaller because nine paths carry two
     * methods each); quoting the wrong one would make the generator's honesty
     * unfalsifiable.
     */
    public function routesRead(): int
    {
        return $this->routesRead;
    }

    /**
     * Distinct `/api/v1` paths, which is fewer than the route count.
     */
    public function uniquePaths(): int
    {
        $paths = [];

        foreach ($this->operations as $operation) {
            $paths[(string) $operation['path']] = true;
        }

        return count($paths);
    }

    /**
     * Write operations with no `FormRequest`, each classified exempt or not.
     *
     * @return list<array{method: string, path: string, action: string, exempt: bool, reason: string}>
     */
    public function writesWithoutFormRequest(): array
    {
        $offenders = [];

        foreach ($this->operations as $operation) {
            if (! $operation['is_write'] || $operation['form_request'] !== null) {
                continue;
            }

            // Keyed on the URI WITHOUT the `api/v1` prefix, because that is how
            // `routes/api.php` spells it and how the exemptions are written --
            // a key that only matched the prefixed form would make every
            // exemption silently inert.
            $key = $operation['method'].' /'.substr((string) $operation['uri'], strlen(self::API_PREFIX) + 1);
            $exempt = array_key_exists($key, self::FORM_REQUEST_EXEMPTIONS);

            $offenders[] = [
                'method' => (string) $operation['method'],
                'path' => (string) $operation['path'],
                'action' => (string) $operation['action'],
                'exempt' => $exempt,
                'reason' => $exempt ? self::FORM_REQUEST_EXEMPTIONS[$key] : 'no FormRequest parameter on the controller method',
            ];
        }

        return $offenders;
    }

    /**
     * @return array<string, string>
     */
    public function formRequestExemptions(): array
    {
        return self::FORM_REQUEST_EXEMPTIONS;
    }

    /**
     * @return list<string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    private function walk(): void
    {
        /** @var Route $route */
        foreach (RouteFacade::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, self::API_PREFIX)) {
                continue;
            }

            $this->routesRead++;

            $action = $route->getActionName();
            $formRequest = $this->formRequestFor($action);
            $rules = [];
            $ruleDetails = [];

            if ($formRequest !== null) {
                try {
                    [$rules, $ruleDetails] = $this->readRules($formRequest, $route);
                } catch (Throwable $e) {
                    $this->failures[] = sprintf(
                        'Cannot read rules() from %s (route %s): %s: %s',
                        $formRequest,
                        $uri,
                        $e::class,
                        $e->getMessage(),
                    );

                    continue;
                }
            }

            foreach ($route->methods() as $method) {
                // A `GET` route also answers `HEAD`, registered on the same Route
                // object. `HEAD` is not published: a generated client must not be
                // handed an endpoint whose body is discarded by definition.
                if ($method === 'HEAD' || $method === 'OPTIONS') {
                    continue;
                }

                $this->operations[] = [
                    'method' => $method,
                    'uri' => $uri,
                    'path' => '/'.ltrim($uri, '/'),
                    'name' => $route->getName(),
                    'action' => $action,
                    'middleware' => $this->describeMiddleware($route),
                    'parameters' => $this->pathParameters($route, $action),
                    'form_request' => $formRequest,
                    'rules' => $rules,
                    'rule_details' => $ruleDetails,
                    'is_write' => in_array($method, ['POST', 'PUT', 'PATCH'], true),
                ];
            }
        }
    }

    /**
     * The `FormRequest` a controller method validates with, or null.
     *
     * Read from the METHOD SIGNATURE rather than the route's action array,
     * because that is where the dependency is: a controller that stops
     * injecting its `FormRequest` and starts calling `$this->validate()`
     * inline removes the signature and nothing else, so this is the check that
     * fails. Reading the action for a `'uses'` key would keep reporting a
     * `FormRequest` that is no longer wired in.
     */
    private function formRequestFor(string $action): ?string
    {
        [$class, $method] = array_pad(explode('@', $action), 2, null);

        if ($method === null || ! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();

            if (is_a($name, FormRequest::class, true)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Read a `FormRequest`'s `rules()` without validating an empty body.
     *
     * @return array{0: array<string, list<string>>, 1: array<string, list<string>>}
     */
    private function readRules(string $formRequest, Route $route): array
    {
        $verb = $route->methods()[0] ?? 'GET';
        $request = HttpRequest::create(
            '/'.ltrim($route->uri(), '/'),
            in_array($verb, ['GET', 'HEAD'], true) ? 'GET' : 'POST',
        );

        $resolver = static fn (): Route => $route;
        $request->setRouteResolver($resolver);

        $instance = new $formRequest([], [], [], [], [], $request->server->all());
        $instance->setRouteResolver($resolver);

        $declared = $instance->rules();

        $rules = [];
        $details = [];

        foreach ($declared as $field => $ruleSet) {
            if (is_string($ruleSet)) {
                $ruleSet = explode('|', $ruleSet);
            }

            if (! is_array($ruleSet)) {
                // A rule value the generator cannot name. Recorded rather than
                // dropped, because a dropped rule is a silently weaker contract.
                $rules[(string) $field] = [];
                $details[(string) $field] = ['unrepresentable: '.get_debug_type($ruleSet)];

                continue;
            }

            $named = [];
            $unnamed = [];

            foreach ($ruleSet as $rule) {
                if (is_string($rule)) {
                    $named[] = $rule;

                    continue;
                }

                $unnamed[] = $this->describeRuleObject($rule);
            }

            $rules[(string) $field] = $named;
            $details[(string) $field] = $unnamed;
        }

        ksort($rules, SORT_STRING);
        ksort($details, SORT_STRING);

        return [$rules, $details];
    }

    /**
     * A stable, human-readable name for a non-string validation rule.
     *
     * `Rule::in([...])` becomes an inline JSON array of its values, which is the
     * single most useful thing the document can carry: it turns an opaque
     * object into a closed set a generated client can compile against.
     */
    private function describeRuleObject(mixed $rule): string
    {
        if ($rule instanceof \Closure) {
            return 'closure';
        }

        if (is_array($rule)) {
            $encoded = json_encode($rule, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return 'in:'.($encoded === false ? '[]' : $encoded);
        }

        if (! is_object($rule)) {
            return get_debug_type($rule);
        }

        if ($rule instanceof In || $rule instanceof NotIn) {
            $values = $this->protectedValues($rule);

            if ($values !== null) {
                $encoded = json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                return ($rule instanceof NotIn ? 'not_in:' : 'in:')
                    .($encoded === false ? '[]' : $encoded);
            }
        }

        $short = strrchr($rule::class, '\\');
        $short = $short === false ? $rule::class : substr($short, 1);

        return match (true) {
            str_contains($short, 'Exists') => 'exists',
            str_contains($short, 'Unique') => 'unique',
            str_contains($short, 'Enum') => 'enum',
            default => $short,
        };
    }

    /**
     * Read a rule object's `$values` bag.
     *
     * `Illuminate\Validation\Rules\In` keeps it `protected`, and there is no
     * accessor, so reflection is the only way to see the actual closed set. The
     * alternative -- parsing `In::__toString()`, which renders
     * `in:"chat","video_call"` -- is worse: the quoting is documented only by
     * the implementation, so a value containing a comma or a quote would split
     * or merge and the published enum would be wrong in a way nothing
     * downstream could detect. Reading the array cannot misparse it.
     *
     * @return list<mixed>|null
     */
    private function protectedValues(object $rule): ?array
    {
        if (! property_exists($rule, 'values')) {
            return null;
        }

        $property = new \ReflectionProperty($rule::class, 'values');
        $property->setAccessible(true);

        $values = $property->getValue($rule);

        if (! is_array($values) || ! array_is_list($values)) {
            return null;
        }

        return $values;
    }

    /**
     * The route's middleware, normalised to the names a client can act on.
     *
     * The `api` group is dropped: it is the framework's stateless group, present
     * on every route, and repeating it 74 times is noise. `throttle:<name>` is
     * KEPT and becomes a real `429` response in the document rather than prose:
     * the limit is a contract fact, and the plan is explicit that the published
     * rate-limit responses must be the ones the code actually serves.
     *
     * @return list<string>
     */
    private function describeMiddleware(Route $route): array
    {
        $described = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if ($middleware === 'api' || $middleware === 'web') {
                continue;
            }

            $described[] = $middleware;
        }

        return $described;
    }

    /**
     * The route's `{placeholders}`, typed from the controller signature.
     *
     * The type comes from the controller method's scalar parameter rather than
     * from the route's `->where()` constraints, because the controller is what
     * casts with: `int $id` and `string $nomorSurat` are the two cases in this
     * codebase and they are genuinely different JSON Schema types, so guessing
     * `string` for all of them would be wrong for `{id}`.
     *
     * @return list<array{name: string, type: string, required: bool}>
     */
    private function pathParameters(Route $route, string $action): array
    {
        preg_match_all('/\{([^}]+)\}/', $route->uri(), $matches);

        if ($matches[1] === []) {
            return [];
        }

        $types = $this->scalarParameterTypes($action);

        $parameters = [];

        foreach ($matches[1] as $placeholder) {
            $parameters[] = [
                'name' => $placeholder,
                'type' => $types[$placeholder] ?? 'string',
                'required' => true,
            ];
        }

        return $parameters;
    }

    /**
     * Map a controller method's scalar parameter names onto their declared types.
     *
     * `int`, `float` and `bool` become `integer`, `number` and `boolean`;
     * everything else is `string`. An untyped or non-scalar parameter is left
     * out, so the placeholder falls back to `string` and is published as such
     * rather than being given an invented type.
     *
     * @return array<string, string>
     */
    private function scalarParameterTypes(string $action): array
    {
        [$class, $method] = array_pad(explode('@', $action), 2, null);

        if ($method === null || ! class_exists($class) || ! method_exists($class, $method)) {
            return [];
        }

        $types = [];

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || ! $type->isBuiltin()) {
                continue;
            }

            $types[$parameter->getName()] = match ($type->getName()) {
                'int' => 'integer',
                'float' => 'number',
                'bool' => 'boolean',
                default => 'string',
            };
        }

        return $types;
    }
}
