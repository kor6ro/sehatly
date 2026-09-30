<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request as HttpRequest;
use ReflectionMethod;
use ReflectionObject;
use Symfony\Component\Yaml\Yaml;

/**
 * Turns a {@see RouteInventory} into an OpenAPI 3.1 document, deterministically.
 *
 * ## The envelope schemas are emitted ONCE and referenced everywhere
 *
 * `ApiResponse` is the single source of the `{success, data, message}` and
 * `{success, message, errors}` shapes -- `bootstrap/app.php` even registers the
 * `Response::apiSuccess()` / `Response::apiError()` macros as delegates to it so
 * a controller cannot drift. The document mirrors that structure: five envelope
 * components plus `PaginatedMeta`, and every response anywhere in the file is a
 * `$ref` to one of them. Writing the shape out per-operation would make 74
 * chances to spell it three slightly different ways.
 *
 * ## `meta` is a TOP-LEVEL SIBLING, and that is a contract fact
 *
 * `ApiResponse::success()` appends `meta` after `message`, as the fourth
 * top-level key, precisely so adding it cannot renumber the three keys every
 * existing client already reads. A generator that nested pagination inside
 * `data` would describe a shape the server has never produced, and a Dart DTO
 * generated from it would not parse one response. So `PaginatedEnvelope` is
 * declared with `success`, `data`, `message` AND `meta` at the same level, and
 * `PaginatedMeta` is a `$ref` from that fourth key.
 *
 * ## A 422 carries MULTIPLE MESSAGES PER FIELD, and the schema says so
 *
 * `ValidationException::errors()` returns `array<string, list<string>>`, and a
 * field really can collect several: `password` failing both `min:8` and
 * `confirmed`, or `kode` failing both `required` and a custom closure, yields two
 * messages for one key. `ErrorEnvelope` therefore types `errors` as
 * `additionalProperties: {type: array, items: {type: string}}` with
 * `minItems: 1`. Flattening it to `{type: string}` -- the easy mistake, and the
 * one that makes a client show only the first message and hide the rest -- would
 * be a schema that the framework itself cannot satisfy.
 *
 * `ApiResponse::error()` casts `errors` to `(object)`, so an empty set encodes
 * as `{}` and not `[]`; the schema's value type is therefore an object, which is
 * also why 401/403/404/500 can all reference the same `ErrorEnvelope` with no
 * `errors` property at all.
 *
 * ## The rate-limit responses are READ, not invented
 *
 * Three routes carry `throttle:<name>`. Rather than assert a number, the builder
 * calls each named limiter's own closure with a synthetic request and reads
 * `Limit::$maxAttempts` and `$decaySeconds` -- the values
 * `AppServiceProvider` registered. So `429` descriptions and the
 * `x-ratelimit-limit` extension carry 10/min, 5/min and 5/min because those are
 * the configured limits, and editing the provider changes the document. A
 * hard-coded "429 Too Many Requests, retry after a minute" would be prose about
 * a limit, and this project has already been bitten by invented limits once.
 *
 * `AppServiceProvider` is READ here, never written: another executor owns it.
 *
 * ## `security` is derived from `auth:sanctum` presence
 *
 * The route's gathered middleware contains `Illuminate\Auth\Middleware\Authenticate:sanctum`
 * for exactly the authenticated routes. That fact becomes `security:
 * [{sanctum: []}]`; its absence becomes `security: []`, which is meaningful in
 * OpenAPI and is what makes the anonymous half of the surface (the doctor
 * directory, the reference tables, the QR verification, the gateway webhook)
 * explicit rather than merely undecorated.
 *
 * ## The enum values come from `docs/enums.json`
 *
 * That file is itself generated from the live `information_schema` and
 * cross-checked against `telemedicine_test.sql` (todo 42). Reading it here means
 * a generated Dart/TypeScript enum cannot disagree with the database, and a
 * change to the schema flows through `sehatly:enums` -> this file -> the
 * clients, with no third transcription. The JSON is validated as JSON; a
 * malformed file is a hard failure rather than an empty enum block.
 *
 * @see OpenApiDocumentBuilder::render() for the YAML bytes
 */
final class OpenApiDocumentBuilder
{
    private const OPENAPI_VERSION = '3.1.0';

    /** @var list<string> */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** @var array<string, mixed>|null */
    private ?array $enums = null;

    /** @var array<string, array{max: int, decay_seconds: int}> */
    private array $limits = [];

    public function __construct(private readonly RouteInventory $inventory) {}

    /**
     * The document as a nested array, ready for YAML rendering.
     *
     * Path and method order is fixed by {@see RouteInventory::operations()}
     * (sorted by path, then method) and every keyed block is emitted in a
     * declared order rather than sorted at the end, so two runs over an
     * unchanged route table produce identical bytes.
     *
     * @return array<string, mixed>
     */
    public function document(): array
    {
        $paths = [];

        foreach ($this->inventory->operations() as $operation) {
            $path = (string) $operation['path'];

            if (! isset($paths[$path])) {
                $paths[$path] = [];

                // Emitted only when the path really has placeholders. An empty
                // `parameters: []` on all 56 static paths is noise that a reader
                // has to read past, and `openapi-typescript` treats an empty
                // list as "this path takes no parameters" -- true, but stating it
                // 56 times invites the reader to look for a difference.
                if ($operation['parameters'] !== []) {
                    $paths[$path]['parameters'] = $operation['parameters'];
                }
            }

            $paths[$path][strtolower((string) $operation['method'])] = $this->operation($operation);
        }

        return [
            'openapi' => self::OPENAPI_VERSION,
            'info' => $this->info(),
            // No `servers` block, on purpose: every `paths` key below is the FULL
            // `/api/v1/...` path as the kernel routes it. Pairing a full path
            // with a `/api/v1` server URL -- the obvious "tidy" arrangement --
            // makes a generated client request `/api/v1/api/v1/auth/login`, which
            // 404s. One of the two may carry the prefix and the full path is the
            // more useful of the two: it is what `route:list` prints, what a
            // curl recipe in `docs/modules/` contains, and what a reader greps
            // for.
            'tags' => $this->tags($paths),
            'security' => [['sanctum' => []]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'sanctum' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Sanctum personal access token',
                        'description' => 'Send `Authorization: Bearer <token>`. The token is issued by '
                            .'`POST /api/v1/auth/otp/verify` and refreshed by `POST /api/v1/auth/refresh`. '
                            .'Every operation below declares its own `security`, so an empty `security: []` '
                            .'means the route is intentionally anonymous.',
                    ],
                ],
                'schemas' => $this->schemas(),
            ],
        ];
    }

    /**
     * The document as YAML bytes.
     *
     * Rendered by `symfony/yaml`, which is already a locked dependency of
     * `laravel/framework` here -- so this adds no package to `composer.json`
     * and the escaping rules are the framework's own rather than a hand-rolled
     * string escaper. `$inline` is 6 so the envelope components render as
     * readable nested blocks instead of one very long flow mapping.
     *
     * The trailing-newline handling is explicit because `Yaml::dump()` ALREADY
     * ends its output with one: appending unconditionally produces a file
     * ending in `\n\n`, which `git diff` reports as a changed final line every
     * time the document is regenerated and which a reviewer learns to ignore.
     * Exactly one newline, asserted byte-wise by the test suite.
     */
    public function render(): string
    {
        $yaml = Yaml::dump(
            $this->document(),
            6,
            2,
            Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
                | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
        );

        return rtrim(str_replace("\r\n", "\n", $yaml), "\n")."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function info(): array
    {
        return [
            'title' => 'Sehatly telemedicine API',
            'version' => '1.0.0',
            'description' => $this->description(),
        ];
    }

    private function description(): string
    {
        return implode("\n", [
            'GENERATED FILE -- do not hand-edit. Run `php artisan sehatly:openapi` to rewrite it and',
            '`php artisan sehatly:openapi --check` to prove the committed copy is still a fresh export.',
            '',
            'Every path, method, middleware entry and request rule below was read from the running',
            'application\'s route table (`Route::getRoutes()`) and from each controller method\'s injected',
            '`FormRequest`. Nothing here is transcribed from `routes/api.php`, which is why the file cannot',
            'drift from the code: a route added, renamed or removed changes it on the next run, and',
            '`--check` fails until the regenerated file is committed.',
            '',
            sprintf(
                'This document describes %d routes across %d distinct paths, read from the live route table.',
                $this->inventory->routesRead(),
                $this->inventory->uniquePaths(),
            ),
            '',
            '## Envelopes',
            '',
            'Every response -- success or failure -- carries the same envelope. `success` is a boolean,',
            '`message` is a stable human-readable string that does not change with the request, and `errors`',
            'is present only on failures. A failure with no field-level detail (401, 403, 404, the sanitized',
            '500) carries `"errors": {}`.',
            '',
            'A list response additionally carries `meta`, as a TOP-LEVEL FOURTH KEY and not nested inside',
            '`data`. That position is fixed: `App\\Support\\ApiResponse` appends it after `message` precisely so',
            'adding pagination cannot renumber the three keys every existing client already reads. A response',
            'that is not paginated has NO `meta` key at all -- omission, never `"meta": null`, so "this is not',
            'a list" is unambiguous. `meta` carries `current_page`, `last_page`, `per_page`, `total`, `from`',
            'and `to`; `per_page` is the page size actually applied after the 100 cap, so it stays correct',
            'after the cap has clamped a larger request, and `from`/`to` are `null` on an empty page.',
            '',
            '## Validation errors',
            '',
            'A 422 body is `{"success":false,"message":"The given data was invalid.","errors":{...}}`.',
            '`message` is deliberately NOT `ValidationException::summarize()` output, because that promotes',
            'the first field error and appends "(and N more errors)", which would make `message`',
            'data-dependent and untranslatable. Field-level text lives in `errors`.',
            '',
            '`errors` maps a field name to an ARRAY OF MESSAGES, and the array genuinely holds more than one',
            'entry: a field failing both `min:8` and `confirmed`, or both `required` and a custom rule,',
            'produces two messages for one key. The schema types each value as a non-empty array of strings.',
            'Flattening it to a single string would describe something `ValidationException::errors()` cannot',
            'return, and a client built on that would show only the first message and hide the rest.',
            '',
            'Keys are the dotted attribute path as submitted, so an array field reports `items.0.obat_id` --',
            'the same string Laravel puts in `errors`, which is what lets a client map a message back to the',
            'form control that caused it.',
            '',
            '## Authentication',
            '',
            '`Authorization: Bearer <token>`, a Laravel Sanctum personal access token. Tokens are issued by',
            '`POST /auth/otp/verify` (the only endpoint that returns a token without one) and rotated by',
            '`POST /auth/refresh`. The server REVOKES the presented refresh token on use, so a refresh is a',
            'rotation: a client that presents a spent token gets 401 and must treat the session as',
            'unrecoverable rather than retrying. `GET /auth/devices` lists the caller\'s devices and',
            '`DELETE /auth/devices/{deviceId}` revokes one.',
            '',
            'Each operation declares its own `security`. An empty `security: []` is an explicit statement that',
            'the route is anonymous -- the doctor directory, the reference tables, the QR verification and the',
            'gateway webhook are, and each says so rather than being merely undecorated.',
            '',
            '## Rate limits',
            '',
            'Where a `429` is documented, the limit is the one `App\\Providers\\AppServiceProvider` registers',
            'for that limiter name, read at generation time and repeated on the operation in `x-ratelimit`',
            '(`limiter`, `max`, `decay_seconds`). Over-limit responses use the standard error envelope with an',
            'empty `errors` map. A limiter name that no registered limiter answers is published with no',
            '`429` at all, because `ThrottleRequests` answers 500 for it.',
            '',
            '## What this document does not claim',
            '',
            'Response payload schemas are `object`/untyped. Every response is enveloped and every envelope is',
            'exact, but the body inside `data` is whatever the resource for that endpoint returns, and',
            'deriving it would mean running the application -- not reading it. A client generated from this',
            'file gets the envelope, the auth scheme, the status codes, the error map and the request rules;',
            'the response shapes are the one thing a reader must confirm against',
            '`packages/sehatly_api_client/lib/src/model/dto.dart`, which is hand-written and covered by tests.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function operation(array $operation): array
    {
        $method = (string) $operation['method'];
        $middleware = $operation['middleware'];
        $authenticated = $this->isAuthenticated($middleware);
        $throttle = $this->throttleLimiter($middleware);

        $document = [
            'operationId' => $this->operationId($operation),
            'summary' => $this->summary($operation),
            'tags' => [$this->tagFor((string) $operation['path'])],
            'security' => $authenticated ? [['sanctum' => []]] : [],
            'x-route-name' => $operation['name'],
            'x-laravel-action' => $operation['action'],
            'x-middleware' => $middleware,
        ];

        if ($authenticated) {
            $document['x-auth'] = ['scheme' => 'sanctum', 'transport' => 'bearer'];
        }

        if ($throttle !== null) {
            $document['x-ratelimit'] = $throttle;
        }

        if ($operation['is_write']) {
            $document['requestBody'] = $this->requestBody($operation);
        }

        $document['responses'] = $this->responses($operation, $authenticated, $throttle);

        return $document;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function requestBody(array $operation): array
    {
        $rules = $operation['rules'];

        if ($operation['form_request'] === null) {
            // No `FormRequest`: the body is not field-validated at all. Published
            // as a free-form object, because asserting `{}` (no properties
            // allowed) would be a lie -- and the one route this applies to is the
            // documented exemption, whose body is HMAC-verified instead.
            return [
                'required' => false,
                'description' => 'This endpoint does not field-validate its body. The payload is provider-'
                    .'specific and verified by signature, so no property list can be published for it. '
                    .'A `422` is therefore not among this operation\'s responses.',
                'content' => [
                    'application/json' => ['schema' => ['type' => 'object']],
                ],
            ];
        }

        $schemaName = $this->schemaNameFor((string) $operation['form_request']);

        return [
            'required' => true,
            'description' => 'Validated by `'.$operation['form_request'].'::rules()`, which is read at '
                .'generation time -- these properties are the live rules, not a transcription.',
            'content' => [
                'application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$schemaName]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array{max: int, decay_seconds: int}|null  $throttle
     * @return array<string, mixed>
     */
    private function responses(array $operation, bool $authenticated, ?array $throttle): array
    {
        $responses = [
            $operation['method'] === 'POST' ? '201' : '200' => [
                'description' => $operation['method'] === 'POST'
                    ? 'Created. `data` holds the created resource.'
                    : 'Success. `data` holds the requested resource, and `meta` is present when the '
                        .'response is a list.',
                'content' => [
                    'application/json' => ['schema' => [
                        '$ref' => '#/components/schemas/'.$this->envelopeFor($operation),
                    ]],
                ],
            ],
        ];

        if ($operation['form_request'] !== null) {
            $responses['422'] = [
                'description' => 'Validation failed. `message` is the fixed string "The given data was '
                    .'invalid." and `errors` maps each field to an ARRAY of messages -- a field can fail '
                    .'more than one rule, and every message is carried. Keys are the dotted attribute path '
                    .'as submitted (`items.0.obat_id` for an array element).',
                'content' => [
                    'application/json' => ['schema' => [
                        '$ref' => '#/components/schemas/ValidationErrorEnvelope',
                    ]],
                ],
            ];
        }

        if ($authenticated) {
            $responses['401'] = [
                'description' => 'Unauthenticated. No token, an expired token, or a token that was revoked. '
                    .'`message` is the fixed string "Unauthenticated." and `errors` is `{}`.',
                'content' => [
                    'application/json' => ['schema' => [
                        '$ref' => '#/components/schemas/ErrorEnvelope',
                    ]],
                ],
            ];

            $responses['403'] = [
                'description' => 'Forbidden. The caller is authenticated but holds no grant for this '
                    .'operation (`permission:` middleware), or is not an account type this route allows '
                    .'(`tipe:` middleware). `message` is the fixed string "This action is unauthorized." and '
                    .'`errors` is `{}`.',
                'content' => [
                    'application/json' => ['schema' => [
                        '$ref' => '#/components/schemas/ErrorEnvelope',
                    ]],
                ],
            ];
        }

        $responses['404'] = [
            'description' => 'Resource not found. Also answers a `{placeholder}` outside the route\'s own '
                .'constraint, such as an unknown `gateway` on the webhook. `message` is the fixed string '
                .'"Resource not found." -- never a model or table name -- and `errors` is `{}`.',
            'content' => [
                'application/json' => ['schema' => [
                    '$ref' => '#/components/schemas/ErrorEnvelope',
                ]],
            ],
        ];

        if ($throttle !== null) {
            $responses['429'] = [
                'description' => 'Rate limited. This operation is limited to '
                    .$throttle['max'].' request(s) per '.$throttle['decay_seconds'].' second(s) by the `'
                    .'RateLimiter` named in `x-ratelimit.limiter`; the limit is read from the running '
                    .'application at generation time, not asserted here. `errors` is `{}`.',
                'content' => [
                    'application/json' => ['schema' => [
                        '$ref' => '#/components/schemas/ErrorEnvelope',
                    ]],
                ],
            ];
        }

        $responses['500'] = [
            'description' => 'Internal server error. The body is a fixed sanitized string; the diagnostic '
                .'detail is kept server-side and never sent to a client.',
            'content' => [
                'application/json' => ['schema' => [
                    '$ref' => '#/components/schemas/ErrorEnvelope',
                ]],
            ],
        ];

        return $responses;
    }

    /**
     * Which envelope an operation's success response carries.
     *
     * ## The rule is the controller's SOURCE, not its FormRequest rules
     *
     * Whether an operation carries `meta` is a fact about the action, and the only
     * place that fact exists is the action's body: every list response here calls
     * `ApiResponse::pageMeta()` or `ApiResponse::singlePageMeta()` as the fourth
     * argument. The previous rule - "the FormRequest declares `page` or `per_page`"
     * - could not tell "does not page" from "pages without being asked" (a GET route
     * has no FormRequest, so a single-page list was published as a three-key
     * envelope its own body violates), and it read a WRITE whose request happens to
     * carry `page`/`per_page` as a list (`POST /konsultasi/{id}/chat/baca`, which
     * sends no `meta`). Reading the reflected method's lines is exact for both.
     *
     * A method that cannot be reflected (a closure route, a missing action) answers
     * `false` and publishes the plain three-key envelope - the shape that exists for
     * every operation that is not a list.
     *
     * @see publishesMeta()
     */
    private function envelopeFor(array $operation): string
    {
        return $this->publishesMeta($operation) ? 'PaginatedEnvelope' : 'SuccessEnvelope';
    }

    /**
     * Does this operation's action emit a `meta` block?
     *
     * `pageMeta()` and `singlePageMeta()` are the only two producers in the
     * application - `ApiResponse`'s docblock names both - so the check reads the
     * reflected controller method's own lines for either call.
     *
     * @param  array<string, mixed>  $operation
     */
    private function publishesMeta(array $operation): bool
    {
        $action = (string) ($operation['action'] ?? '');

        if (! str_contains($action, '@')) {
            return false;
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return false;
        }

        $reflection = new ReflectionMethod($class, $method);
        $file = $reflection->getFileName();

        if (! is_string($file) || ! is_readable($file)) {
            return false;
        }

        $lines = file($file);

        $source = implode('', array_slice(
            $lines === false ? [] : $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        return str_contains($source, 'pageMeta(') || str_contains($source, 'singlePageMeta(');
    }

    /**
     * The shared components: five envelopes, the meta block, and one schema per
     * `FormRequest` plus the ENUM catalogue.
     *
     * @return array<string, mixed>
     */
    private function schemas(): array
    {
        $schemas = $this->envelopeSchemas();

        foreach ($this->formRequestSchemas() as $name => $schema) {
            $schemas[$name] = $schema;
        }

        foreach ($this->enumSchemas() as $name => $schema) {
            $schemas[$name] = $schema;
        }

        return $schemas;
    }

    /**
     * @return array<string, mixed>
     */
    private function envelopeSchemas(): array
    {
        $stringList = [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'minItems' => 1,
            'description' => 'Every message produced for this field. A field that fails more than one rule '
                .'carries more than one entry; nothing is flattened into a single string.',
        ];

        $error = [
            'type' => 'object',
            'description' => 'A field-keyed map of messages. `ApiResponse::error()` casts it to an object so '
                .'an empty set encodes as `{}` rather than `[]`. Keys are the dotted attribute path as '
                .'submitted, which is exactly what `ValidationException::errors()` produces.',
            'additionalProperties' => $stringList,
        ];

        $unenveloped = ['type' => 'object'];

        return [
            'SuccessEnvelope' => [
                'type' => 'object',
                'description' => '`{"success":true,"data":<data>,"message":<message>}`. Exactly these three '
                    .'keys: there is NO `meta` key, because omission is what makes "this response is not '
                    .'paginated" unambiguous. Key order is `success`, `data`, `message` and is load-bearing.',
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => true],
                    'data' => $unenveloped + ['description' => 'The endpoint\'s payload. See the operation\'s notes.'],
                    'message' => ['type' => 'string'],
                ],
                'required' => ['success', 'data', 'message'],
                'additionalProperties' => false,
            ],
            'PaginatedEnvelope' => [
                'type' => 'object',
                'description' => '`{"success":true,"data":<list>,"message":<message>,"meta":<meta>}`. `meta` '
                    .'is a TOP-LEVEL FOURTH KEY, a sibling of `data` -- not nested inside it. That position '
                    .'is fixed by `App\\Support\\ApiResponse`, which appends `meta` after `message` so adding '
                    .'pagination cannot renumber the three keys every existing client already reads.',
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => true],
                    'data' => [
                        'type' => 'object',
                        'description' => 'The page, keyed by the RESOURCE NAME -- `{"dokter":[...]}` and '
                            .'`{"provinsi":[...]}` rather than a bare list, because the key is what tells a '
                            .'client which resource it is reading. Every list controller wraps its collection '
                            .'in exactly one such key, so the object is the shape the application actually '
                            .'answers.',
                        'additionalProperties' => true,
                    ],
                    'message' => ['type' => 'string'],
                    'meta' => ['$ref' => '#/components/schemas/PaginatedMeta'],
                ],
                'required' => ['success', 'data', 'message', 'meta'],
                'additionalProperties' => false,
            ],
            'PaginatedMeta' => [
                'type' => 'object',
                'description' => 'The project-wide pagination block, derived from the paginator so no '
                    .'controller can spell it differently. `per_page` is the page size ACTUALLY applied after '
                    .'the 100 cap, so it is correct where the caller asked for more. `from` and `to` are '
                    .'`null` on an empty page -- "no rows" has no first and last row. A deliberately '
                    .'unpaginated list carries the same keys with `current_page` and `last_page` both 1.',
                'properties' => [
                    'current_page' => ['type' => 'integer', 'minimum' => 1],
                    'last_page' => ['type' => 'integer', 'minimum' => 1],
                    // No `maximum` here, and the omission is deliberate. The 100
                    // cap is on the REQUEST (`?per_page=` is clamped to 100), but
                    // `ApiResponse::singlePageMeta()` sets `per_page` to the row
                    // COUNT for a deliberately unpaginated list -- so a signed-in
                    // account with 150 devices reports `per_page: 150` and a
                    // `maximum: 100` would describe a response the server
                    // produces.
                    // The floor is 0, not 1. `singlePageMeta()` sets `per_page` to
                    // the ROW COUNT for a deliberately unpaginated list, so an empty
                    // one truthfully answers `per_page: 0`; the previous floor of 1
                    // rejected the application's own response.
                    'per_page' => ['type' => 'integer', 'minimum' => 0],
                    'total' => ['type' => 'integer', 'minimum' => 0],
                    'from' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    'to' => ['type' => ['integer', 'null'], 'minimum' => 1],
                ],
                'required' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
                'additionalProperties' => false,
            ],
            'ErrorEnvelope' => [
                'type' => 'object',
                'description' => '`{"success":false,"message":<message>,"errors":<errors>}`. Used for 401, '
                    .'403, 404, 429 and 500, all of which pass an empty map. `message` is a fixed string per '
                    .'status, never the underlying exception text.',
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => false],
                    'message' => ['type' => 'string'],
                    'errors' => $error,
                ],
                'required' => ['success', 'message', 'errors'],
                'additionalProperties' => false,
            ],
            'ValidationErrorEnvelope' => [
                'type' => 'object',
                'description' => 'The 422 envelope. Identical in shape to `ErrorEnvelope` and deliberately '
                    .'declared as its own component rather than reused, because the thing that makes a 422 '
                    .'hard to consume is `errors` carrying MORE THAN ONE MESSAGE PER FIELD, and a client '
                    .'that treats it as `{field: string}` silently drops every message after the first. '
                    .'`message` is the fixed string "The given data was invalid." -- never '
                    .'`ValidationException::summarize()`, which promotes the first field error and appends '
                    .'"(and N more errors)" and would make `message` data-dependent.',
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => false],
                    'message' => ['type' => 'string'],
                    'errors' => $error,
                ],
                'required' => ['success', 'message', 'errors'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * One component schema per `FormRequest`, named from its short class name.
     *
     * Deduplicated by class: `POST /konsultasi/{id}/rekam-medis`,
     * `PUT /rekam-medis/{id}` and `POST /rekam-medis/{id}/amandemen` share
     * `SimpanRekamMedisRequest`/`UbahRekamMedisRequest`'s rule sets by
     * inheritance, and emitting one schema per operation would put four copies
     * of a 27-property object in the file.
     *
     * @return array<string, mixed>
     */
    private function formRequestSchemas(): array
    {
        $schemas = [];

        foreach ($this->inventory->operations() as $operation) {
            $class = $operation['form_request'];

            if ($class === null) {
                continue;
            }

            $name = $this->schemaNameFor((string) $class);

            if (isset($schemas[$name])) {
                continue;
            }

            $schemas[$name] = $this->formRequestSchema((string) $class, $operation);
        }

        ksort($schemas, SORT_STRING);

        return $schemas;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function formRequestSchema(string $class, array $operation): array
    {
        $rules = $operation['rules'];
        $details = $operation['rule_details'];

        $properties = [];
        $required = [];

        foreach ($rules as $field => $ruleSet) {
            $property = $this->propertyFor((string) $field, $ruleSet, $details[$field] ?? []);

            if ($this->isRequired($ruleSet)) {
                $required[] = (string) $field;
            }

            $properties[(string) $field] = $property;
        }

        return [
            'type' => 'object',
            'title' => $this->shortName($class),
            'description' => 'Request body for operations validated by `'.$class.'`. The properties below '
                .'are read from that class\'s `rules()` at generation time.',
            'x-form-request' => $class,
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
            'x-enum-fields' => $this->enumFieldsFor(array_keys($properties)),
        ];
    }

    /**
     * One property's JSON Schema, from its string rules.
     *
     * Only the rules whose meaning IS the type are mapped (`string`, `integer`,
     * `boolean`, `array`, `date`, `date_format:...`, `email`, `url`, `uuid`).
     * Presence rules (`required`, `nullable`, `sometimes`, `prohibited`) are
     * translated into the parent object's `required` list and dropped from the
     * property, and everything else is published verbatim under
     * `x-laravel-rule` rather than guessed at: `exists:booking,id` is a
     * constraint no JSON Schema keyword expresses, and inventing one would be a
     * claim the server does not make.
     *
     * @param  list<string>  $ruleSet
     * @param  list<string>  $details
     * @return array<string, mixed>
     */
    private function propertyFor(string $field, array $ruleSet, array $details): array
    {
        $property = $this->typeFor($field, $ruleSet, $details);
        $kept = [];

        foreach ($ruleSet as $rule) {
            $name = strtok($rule, ':') ?: $rule;

            if (in_array($name, ['required', 'nullable', 'sometimes', 'prohibited', 'present'], true)) {
                if ($name === 'prohibited') {
                    // `not: {}` is the JSON-Schema idiom for "must not be
                    // present", and it is deliberately NOT emitted. Symfony's
                    // YAML dumper renders an empty map as `null` (and
                    // DUMP_EMPTY_ARRAY_AS_SEQUENCE renders `[]` as an empty
                    // SEQUENCE, not a map), so there is no way to emit `{}` here
                    // -- and `not: null` is a schema that means nothing and reads
                    // as a bug. The prohibition is published as a vendor
                    // extension plus a description instead: it is not
                    // expressible in portable JSON Schema 3.0/3.1 without the
                    // empty-map trick, and a generated client reading
                    // `x-laravel-prohibited` learns the same thing.
                    $property['x-laravel-prohibited'] = true;
                    $property['description'] = trim(
                        ($property['description'] ?? '').' PROHIBITED: sending this key at all is a validation '
                        .'error. It is a tenant key the server writes from the caller\'s own row.',
                    );
                }

                continue;
            }

            $kept[] = $rule;
        }

        foreach ($details as $detail) {
            $kept[] = $detail;
        }

        if ($kept !== []) {
            $property['x-laravel-rule'] = array_values(array_unique($kept));
        }

        return $property;
    }

    /**
     * @param  list<string>  $ruleSet
     * @return array<string, mixed>
     */
    private function typeFor(string $field, array $ruleSet, array $details = []): array
    {
        // Searched across BOTH buckets: a `Rule::in([...])` is read off the rule
        // object and therefore lands in `$details` as `in:["a","b"]`, so a
        // lookup that only scanned the string rules would miss every closed set
        // in the project and publish `type: string` with the values left as
        // prose in `x-laravel-rule` -- the weakest possible way to describe a
        // field whose entire domain is four values.
        $enum = $this->closedSetFor(array_merge($ruleSet, $details));

        if ($enum !== null) {
            return ['type' => 'string', 'enum' => $enum];
        }

        $property = [];

        foreach ($ruleSet as $rule) {
            if (str_starts_with($rule, 'date_format:')) {
                $format = substr($rule, strlen('date_format:'));

                return $format === 'Y-m-d'
                    ? ['type' => 'string', 'format' => 'date']
                    : ['type' => 'string', 'description' => 'A time of day formatted `'.$format.'`.'];
            }
        }

        $map = [
            'string' => ['type' => 'string'],
            'integer' => ['type' => 'integer'],
            'numeric' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => ['type' => 'object']],
            'date' => ['type' => 'string', 'format' => 'date-time'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'url' => ['type' => 'string', 'format' => 'uri'],
            'uuid' => ['type' => 'string', 'format' => 'uuid'],
        ];

        $named = array_values(array_filter(
            $ruleSet,
            static fn (string $rule): bool => array_key_exists($rule, $map),
        ));

        if ($named === []) {
            return [];
        }

        // `integer` and `numeric` together are not a conflict but a widening;
        // the first match in the map's order is the narrower statement.
        foreach (['boolean', 'array', 'integer', 'numeric', 'string'] as $candidate) {
            if (in_array($candidate, $named, true)) {
                $property = $map[$candidate];

                break;
            }
        }

        return $property;
    }

    /**
     * A `Rule::in([...])` value list as a JSON Schema `enum`, when there is one.
     *
     * @param  list<string>  $ruleSet
     * @return list<string>|null
     */
    private function closedSetFor(array $ruleSet): ?array
    {
        foreach ($ruleSet as $rule) {
            if (! str_starts_with($rule, 'in:')) {
                continue;
            }

            $decoded = json_decode(substr($rule, strlen('in:')), true);

            if (is_array($decoded) && $decoded !== [] && array_is_list($decoded)) {
                return array_values(array_map('strval', $decoded));
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $ruleSet
     */
    private function isRequired(array $ruleSet): bool
    {
        if (in_array('prohibited', $ruleSet, true)) {
            return false;
        }

        if (in_array('required', $ruleSet, true)) {
            return true;
        }

        foreach ($ruleSet as $rule) {
            if (str_starts_with($rule, 'required_if:') || str_starts_with($rule, 'required_with:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `docs/enums.json` catalogue as one component schema per column.
     *
     * Named `Enum{tablePascal}{columnPascal}` after the catalogue's own
     * `table.column` keys, and every value is the MySQL declaration order --
     * the same order `sehatly:enums` writes, because that file's values come
     * from `information_schema` and are cross-checked against
     * `telemedicine_test.sql` on every run. An inline `enum` here is what makes
     * a generated client's status values compile-time-checked instead of
     * arbitrary strings.
     *
     * @return array<string, mixed>
     */
    private function enumSchemas(): array
    {
        $schemas = [];

        foreach ($this->enums() as $column => $values) {
            [$table, $name] = explode('.', (string) $column, 2);

            $schemas['Enum'.$this->pascal($table).$this->pascal($name)] = [
                'type' => 'string',
                'title' => $column,
                'description' => 'The MySQL ENUM `'.$column.'` as declared in the reference DDL and mirrored '
                    .'in `docs/enums.json`. Values are in MySQL declaration order, which is the order the '
                    .'numeric index depends on.',
                'enum' => array_values(array_map('strval', $values)),
            ];
        }

        ksort($schemas, SORT_STRING);

        return $schemas;
    }

    /**
     * The ENUM catalogue, read from `docs/enums.json` once.
     *
     * A missing or malformed file throws. That is the right failure: this
     * document would otherwise publish no enums at all and look complete, which
     * is the specific way a generated client ends up accepting a status value
     * the database would reject with a 1264.
     *
     * @return array<string, list<string>>
     */
    private function enums(): array
    {
        if ($this->enums !== null) {
            return $this->enums;
        }

        $path = base_path('docs/enums.json');

        if (! is_file($path)) {
            throw new OpenApiGenerationException(
                'docs/enums.json is missing. Run `php artisan sehatly:enums` first; the OpenAPI document '
                .'reads its ENUM values from that file so a generated client cannot disagree with the '
                .'database.',
            );
        }

        $raw = (string) file_get_contents($path);
        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || $decoded === []) {
            throw new OpenApiGenerationException(
                'docs/enums.json is not a non-empty JSON object, or could not be decoded: '
                .json_last_error_msg().'. Run `php artisan sehatly:enums --check` to regenerate it.',
            );
        }

        $enums = [];

        foreach ($decoded as $column => $values) {
            if (! is_string($column) || ! is_array($values) || ! array_is_list($values)) {
                throw new OpenApiGenerationException(
                    'docs/enums.json has an unexpected shape at key "'.(is_string($column) ? $column : '?').'": '
                    .'expected `{"table.column": ["value", ...]}`.',
                );
            }

            $enums[$column] = array_values(array_map('strval', $values));
        }

        ksort($enums, SORT_STRING);

        return $this->enums = $enums;
    }

    /**
     * Which ENUM columns a body schema's field names could correspond to.
     *
     * Candidates, published under `x-enum-fields`, never an assertion: the
     * field-to-column mapping in this project is a naming convention
     * (`booking.status` <- `status`), so the document names the possible column
     * and lets a reader decide. A `status` field matching three different ENUM
     * columns is genuinely ambiguous, and silently picking one would be a claim
     * the document cannot support. A client generator uses the names to find
     * {@see enumSchemas()}; nothing at runtime depends on them.
     *
     * @param  list<string>  $fields
     * @return array<string, list<string>>
     */
    private function enumFieldsFor(array $fields): array
    {
        $columns = array_keys($this->enums());
        $matches = [];

        foreach ($fields as $field) {
            $leaf = str_contains($field, '.') ? substr($field, (int) strrpos($field, '.') + 1) : $field;
            $candidates = [];

            foreach ($columns as $column) {
                [, $name] = explode('.', (string) $column, 2);

                if ($name === $leaf) {
                    $candidates[] = (string) $column;
                }
            }

            if ($candidates !== []) {
                $matches[$field] = $candidates;
            }
        }

        ksort($matches, SORT_STRING);

        return $matches;
    }

    /**
     * The live rate limit for the limiter a route is throttled by, if any.
     *
     * Read from `Illuminate\Cache\RateLimiter`'s registered closures rather
     * than from a constant here. `AppServiceProvider` is the authority for every
     * limit in this application; duplicating the numbers would create a second
     * place to be wrong, and this document's whole argument is that it has
     * none.
     *
     * @param  list<string>  $middleware
     * @return array{limiter: string, max: int, decay_seconds: int}|null
     */
    private function throttleLimiter(array $middleware): ?array
    {
        foreach ($middleware as $entry) {
            if (! str_starts_with($entry, 'throttle:')) {
                continue;
            }

            $name = substr($entry, strlen('throttle:'));

            return $this->limitFor($name);
        }

        return null;
    }

    /**
     * @return array{limiter: string, max: int, decay_seconds: int}|null
     */
    private function limitFor(string $name): ?array
    {
        if (isset($this->limits[$name])) {
            $limit = $this->limits[$name];

            return [
                'limiter' => $name,
                'max' => $limit['max'],
                'decay_seconds' => $limit['decay_seconds'],
            ];
        }

        $limiter = app(RateLimiter::class);
        $property = (new ReflectionObject($limiter))->getProperty('limiters');
        $property->setAccessible(true);

        /** @var array<string, callable> $registered */
        $registered = $property->getValue($limiter);

        if (! isset($registered[$name])) {
            // A route names a limiter nobody registered. `ThrottleRequests`
            // answers 500 for that, so the document says 500 rather than
            // inventing a limit for a name that does not exist.
            return null;
        }

        $limit = $registered[$name](HttpRequest::create('/api/v1/', 'GET'));

        $this->limits[$name] = [
            'max' => (int) $limit->maxAttempts,
            'decay_seconds' => (int) $limit->decaySeconds,
        ];

        return [
            'limiter' => $name,
            'max' => $this->limits[$name]['max'],
            'decay_seconds' => $this->limits[$name]['decay_seconds'],
        ];
    }

    /**
     * @param  list<string>  $middleware
     */
    private function isAuthenticated(array $middleware): bool
    {
        foreach ($middleware as $entry) {
            if (str_ends_with($entry, 'Authenticate:sanctum') || $entry === 'auth:sanctum') {
                return true;
            }
        }

        return false;
    }

    /**
     * A stable, unique operation id, derived from the method and path.
     *
     * `getApiV1KonsultasiId` -- method first, then the path with separators
     * camel-cased. A generated client names its methods from this, so it must be
     * a pure function of the route: no counter, no ordering dependence, and no
     * collision between the two methods on a shared path.
     */
    private function operationId(array $operation): string
    {
        $path = trim((string) $operation['path'], '/');
        $segments = array_map(
            fn (string $segment): string => $this->pascal(str_replace(['{', '}', '-'], ['', '', '_'], $segment)),
            explode('/', $path),
        );

        return strtolower((string) $operation['method']).ucfirst(implode('', $segments));
    }

    private function summary(array $operation): string
    {
        $path = (string) $operation['path'];
        $verb = (string) $operation['method'];

        return trim(match (true) {
            $verb === 'POST' && str_ends_with($path, '/verifikasi') => 'Verify',
            $verb === 'GET' && str_ends_with($path, '/verify') => 'Verify by QR token',
            default => match ($verb) {
                'GET' => 'Read',
                'POST' => 'Create',
                'PUT' => 'Replace',
                'PATCH' => 'Update',
                'DELETE' => 'Delete',
                default => $verb,
            },
        }.' '.$path.'.');
    }

    /**
     * The tag for a path: its first segment.
     *
     * One tag per resource, which is what makes a generated client group its
     * calls the way a reader of `routes/api.php` already groups them.
     *
     * @param  array<string, mixed>  $paths
     * @return list<array{name: string, description: string}>
     */
    private function tags(array $paths): array
    {
        $names = [];

        foreach (array_keys($paths) as $path) {
            $names[$this->tagFor((string) $path)] = true;
        }

        ksort($names, SORT_STRING);

        $tags = [];

        foreach (array_keys($names) as $name) {
            $tags[] = [
                'name' => (string) $name,
                'description' => '`/api/v1/'.$name.'` operations. See the module summaries in `docs/modules/` '
                    .'for the curl recipes and the business rules behind these endpoints.',
            ];
        }

        return $tags;
    }

    /**
     * The tag for a path: its first segment after the `api/v1` prefix.
     *
     * Stripping the prefix rather than indexing `explode()` at a fixed offset,
     * because a fixed offset is how `tags: v1` happens: the paths here are
     * `/api/v1/...`, so segments[1] is the `v1` of the prefix and every
     * operation was filed under one meaningless tag.
     */
    private function tagFor(string $path): string
    {
        $relative = str_starts_with($path, '/'.RouteInventory::API_PREFIX.'/')
            ? substr($path, strlen('/'.RouteInventory::API_PREFIX.'/'))
            : trim($path, '/');

        $segments = explode('/', $relative);

        return $segments[0] === '' ? 'root' : (string) $segments[0];
    }

    private function schemaNameFor(string $class): string
    {
        return $this->shortName($class).'Body';
    }

    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    /**
     * `konsultasi_chat` -> `KonsultasiChat`; `id` -> `Id`.
     *
     * Locale-independent by construction: it splits on `_`/`-`/case boundaries
     * and never calls `ucfirst` on a multi-byte string.
     */
    private function pascal(string $value): string
    {
        $parts = preg_split('/[_\-\s]+/', $value) ?: [$value];

        $pascal = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $pascal .= ucfirst(strtolower(substr($part, 0, 1))).substr($part, 1);
        }

        return $pascal;
    }
}
