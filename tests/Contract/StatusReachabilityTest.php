<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Contract\Support\ContractSpec;
/*
 |--------------------------------------------------------------------------
 | Status reachability: documented statuses reachable, undocumented ones absent
 |--------------------------------------------------------------------------
 |
 | The plan's requirement (4), in two halves:
 |
 | - every status the document publishes must be REACHABLE, so a client that
 |   handles it is handling something the server can actually send;
 | - every status the document does NOT publish must be ABSENT, so a client that
 |   does not handle it cannot be handed it.
 |
 | ## The vocabulary is closed, and the test says so
 |
 | `it_publishes_exactly_the_eight_statuses...` pins the set of statuses any
 | operation may document. Without it a regenerated document could introduce a
 | status this file has no vocabulary for and the absence checks would silently
 | stop covering it.
 |
 | ## Undocumented statuses
 |
 | Two are asserted as live findings in `ContractDivergenceTest`: the QR
 | verifier's 422 and the payment webhook's 401. This file owns the structural
 | half -- the closed status set, the resolvable `$ref`s, and the pairing rules --
 | and is honest in `docs/contract-conformance.md` about the reachability half it
 | cannot reach without a role-bearing token.
 |
 | ## What "reachable" is NOT claimed to mean here
 |
 | A 403 needs a role grant; a 200 on a clinical write needs a consultation and a
 | party to it. This suite mints no such fixtures and does not pretend to. What it
 * does prove is named operation by operation in the coverage document.
 */

use Tests\Contract\Support\LiveRequest;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Whether an operation publishes a status, comparing as strings.
 *
 * YAML parses `200:` as an INTEGER key, so a loose `in_array('422', $keys)`
 * silently never matches and the assertion stops testing anything. Normalising
 * here rather than at each call site means no individual check can quietly go
 * hollow.
 *
 * @param  array<string, mixed>  $operation
 */
function contractPublishes(array $operation, string $status): bool
{
    return in_array($status, ContractSpec::documentedStatuses($operation), true);
}

it('publishes exactly the eight statuses the envelope components define', function (): void {
    // The closed set of statuses any operation may document. Asserting it means a
    // new status cannot appear in the document without this file noticing, and
    // that the absence checks below have a vocabulary for every status in use.
    $published = [];

    foreach (ContractSpec::specOperations() as $operation) {
        foreach (ContractSpec::documentedStatuses($operation) as $status) {
            $published[$status] = true;
        }
    }

    ksort($published);

    expect(array_map(strval(...), array_keys($published)))
        ->toBe(['200', '201', '401', '403', '404', '422', '429', '500']);
});

it('resolves every published response to one of exactly four component schemas', function (): void {
    // A `$ref` pointing at a schema that is not in `components` would make the
    // document unresolvable by a generator. `resolveRef()` throws rather than
    // returning an empty schema, so a bad pointer fails here loudly.
    $refs = [];

    foreach (ContractSpec::specOperations() as $key => $operation) {
        foreach (ContractSpec::documentedStatuses($operation) as $status) {
            $ref = ContractSpec::schemaRefFor($operation, $status);

            expect($ref)->not->toBeNull("{$key} @{$status} publishes a JSON schema");
            expect(ContractSpec::resolveRef((string) $ref))->toBeArray();

            $refs[(string) $ref] = true;
        }
    }

    $resolved = array_keys($refs);
    sort($resolved);

    // Exactly the four envelope components a response may use. `PaginatedMeta` is
    // deliberately absent: it is reached through `PaginatedEnvelope` and is never
    // published directly as a response body.
    expect($resolved)->toBe([
        '#/components/schemas/ErrorEnvelope',
        '#/components/schemas/PaginatedEnvelope',
        '#/components/schemas/SuccessEnvelope',
        '#/components/schemas/ValidationErrorEnvelope',
    ]);
});

it('binds every published 401 and 403 to the same error envelope', function (): void {
    foreach (ContractSpec::specOperations() as $key => $operation) {
        foreach (['401', '403'] as $status) {
            if (! contractPublishes($operation, $status)) {
                continue;
            }

            expect(ContractSpec::schemaRefFor($operation, $status))
                ->toBe('#/components/schemas/ErrorEnvelope', "{$key} @{$status}");
        }
    }
});

it('publishes a 404 for all 83 operations, bound to the error envelope', function (): void {
    // Structural, not reachability. The claim is that no operation advertises a
    // 404 the error envelope cannot describe, and that the count is 83 -- so a
    // route appearing or disappearing moves this number rather than passing
    // quietly.
    $count = 0;

    foreach (ContractSpec::specOperations() as $key => $operation) {
        expect(contractPublishes($operation, '404'))->toBeTrue("{$key} publishes 404");
        expect(ContractSpec::schemaRefFor($operation, '404'))
            ->toBe('#/components/schemas/ErrorEnvelope', "{$key} @404");

        $count++;
    }

    expect($count)->toBe(83);
});

it('publishes 401 and 403 together, or neither, on every bearer operation', function (): void {
    // Both statuses come from `auth:sanctum` and the RBAC middleware, and both are
    // reachable on every bearer route. Publishing one without the other would tell
    // the mobile team to handle a status it can never see, or to miss one it can.
    // The 401 half is proven live for all 58 in `SanctumAuthConformanceTest`; the
    // 403 half needs a role-bearing token and is documented as uncovered.
    $count = 0;

    foreach (ContractSpec::specOperations() as $key => $operation) {
        if (ContractSpec::isAnonymous($operation)) {
            continue;
        }

        expect(contractPublishes($operation, '401'))->toBeTrue("{$key} publishes 401");
        expect(contractPublishes($operation, '403'))->toBeTrue("{$key} publishes 403");

        $count++;
    }

    expect($count)->toBe(58);
});

it('publishes 429 only where a named rate limiter is registered', function (): void {
    // As many operations publish a 429 as there are routes carrying a named
    // limiter, each with an `x-ratelimit` block naming the limiter read at export
    // time. A 429 with no named limiter would tell a client to handle a throttle
    // whose budget it cannot reason about; a named limiter with no 429 is the
    // harmless direction. The count is DERIVED from the route table rather than
    // written down: it was the literal 3 until F-002 mounted six more, and a
    // literal here makes a correct change look like a broken contract.
    $throttled = [];

    foreach (ContractSpec::specOperations() as $key => $operation) {
        if (contractPublishes($operation, '429')) {
            $throttled[$key] = $operation['x-ratelimit'] ?? null;
        }
    }

    $routed = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1'))
        ->filter(fn ($route): bool => collect($route->gatherMiddleware())
            ->contains(fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:')))
        ->map(fn ($route): string => $route->uri())
        ->unique()
        ->count();

    expect($routed)->toBeGreaterThan(0, 'no route carries a limiter, so the 429 publication is vacuous');
    expect($throttled)->toHaveCount($routed);

    foreach ($throttled as $key => $limit) {
        expect($limit)->toBeArray("{$key} publishes an x-ratelimit block");
        expect(array_keys($limit))->toBe(['limiter', 'max', 'decay_seconds']);
        expect($limit['limiter'])->toBeString()->not->toBeEmpty();
        expect($limit['max'])->toBeInt()->toBeGreaterThan(0);
        expect($limit['decay_seconds'])->toBeInt()->toBeGreaterThan(0);
    }
});

it('answers 422 with the validation envelope on every anonymous write with a body', function (string $method, string $path): void {
    $operation = ContractSpec::specOperations()[$method.' '.$path];

    expect(contractPublishes($operation, '422'))->toBeTrue();

    // An empty body against a required-field request is the cheapest way to reach
    // the 422 on every one of these without inventing business data. `otp/verify`
    // is included because it is the only endpoint that issues a token, so its
    // failure envelope is the one a client meets first and the least likely to be
    // exercised anywhere else.
    $response = $this->json($method, LiveRequest::concretePath($path), []);

    $response->assertStatus(422);

    $violations = LiveRequest::validateAgainstDocument($response, $operation);

    expect($violations)->toBe([], implode("\n", $violations));
})->with([
    ['post', '/api/v1/auth/register'],
    ['post', '/api/v1/auth/login'],
    ['post', '/api/v1/auth/refresh'],
    ['post', '/api/v1/auth/otp/verify'],
]);

it('answers 422 when a public slot read omits its required date', function (): void {
    // `IndexSlotDokterRequest` requires `tanggal` and the endpoint is public, so
    // its 422 is reachable without a token. It is a separate test rather than
    // another row above because it is a GET, and the empty-body trick does not
    // apply to a GET.
    $operation = ContractSpec::specOperations()['get /api/v1/dokter/{dokter}/slot'];

    expect(contractPublishes($operation, '422'))->toBeTrue();

    // No `?tanggal=` at all.
    $missing = $this->getJson('/api/v1/dokter/1/slot');
    $missing->assertStatus(422);

    ['object' => $body] = LiveRequest::decode($missing);
    expect($body->errors)->toHaveProperty('tanggal');

    // A well-formed date against a doctor that does not exist is a 404, not a
    // 422. Asserted because it is the boundary between the two documented
    // statuses, and getting it backwards would make a client show a validation
    // error for a genuine "not found".
    $this->getJson('/api/v1/dokter/1/slot?tanggal=2027-02-14')->assertStatus(404);

    // A malformed date IS the 422.
    $this->getJson('/api/v1/dokter/1/slot?tanggal=abc')->assertStatus(422);
});

it('answers 404 with the error envelope when a public read is given an absent identifier', function (string $method, string $path): void {
    $operation = ContractSpec::specOperations()[$method.' '.$path];

    expect(contractPublishes($operation, '404'))->toBeTrue();

    $response = $this->json($method, LiveRequest::concretePath($path));

    $response->assertStatus(404);

    $violations = LiveRequest::validateAgainstDocument($response, $operation);

    expect($violations)->toBe([], implode("\n", $violations));
})->with([
    ['get', '/api/v1/dokter/{dokter}'],
    ['get', '/api/v1/dokter/{dokter}/jadwal'],
]);

/*
 |--------------------------------------------------------------------------
  | Finding 7: 28 operations publish a 422 with nothing describing what fails it
  |--------------------------------------------------------------------------
  |
  | Every one of these is a GET. A GET carries its input in the query string, and
  | the document publishes no `parameters` and no `requestBody` for any of them --
  | so the 422 is advertised with nothing saying what can trigger it. A generated
  | client cannot learn that `?page=` exists, and therefore cannot know what a 422
  | on this operation would be complaining about.
  |
  | F09's pharmacist queue is the second-newest member and the finding is unchanged
  | by its arrival: `GET /api/v1/resep` really does validate `status`/`page`/
  | `per_page` through `AntreanResepRequest` and really does answer 422 for a bad
  | one, and the document really does omit all three parameters. The count moved
  | 23 -> 24; the defect is the same one, on one more endpoint.
  |
  | F13's doctor list is the newest member and repeats it: `GET /api/v1/konsultasi`
  | validates `status`/`page`/`per_page` through `IndexKonsultasiRequest` and
  | answers a real 422, and the document publishes none of the three. The count
  | moved 24 -> 25; the defect is still the same one.
  |
  | F10's two medical-record reads move it once more, 25 -> 27: `GET /api/v1/rekam-medis`
  | validates `q`/`tanggal_dari`/`tanggal_sampai`/`page`/`per_page` through
  | `IndexRekamMedisRequest` and `GET /api/v1/rekam-medis/{id}/akses` validates
  | `page`/`per_page` through `IndexAksesRekamMedisRequest`, and the document
  | publishes none of either. The defect is the same one, on two more endpoints.
  |
  | F12's patient refund list moves it 27 -> 28: `GET /api/v1/pasien/refund`
  | validates `page`/`per_page` through `IndexRefundRequest` exactly as every
  | other paginated list does, and the document publishes neither. The defect is
  | the same one, on one more endpoint.
  |
  | WORSE, on the eight non-paginating, non-searchable reference endpoints the 422
  | is reachable only by sending a query parameter the endpoint explicitly
  | REFUSES. Proved live below: `?q=<anything>` answers 422 with
  * `Parameter "q" is not accepted by this endpoint. Accepted: (none).`, while
  * `?page=abc`, `?page=-1` and `?per_page=0` all answer 200.
  |
  | So the published 422 on those eight has exactly one reachable cause, and it is
  * a client mistake the document never warned about.
  |
  | THE DOCUMENT IS WRONG on both counts. `IndexReferensiRequest` validates
  * `page`/`per_page` properly for the PAGINATING endpoints -- `?page=abc` on
  * `/referensi/icd10` really does answer a correct 422 -- but none of it is
  * published, and for the non-paginating eight the rule set is unreachable in
  * every legitimate use.
  */
it('finds_twenty_eight_gets_publishing_a_422_with_nothing_to_describe_what_fails_it', function (): void {
    $with422NoBody = [];

    foreach (ContractSpec::specOperations() as $key => $operation) {
        if (! contractPublishes($operation, '422') || array_key_exists('requestBody', $operation)) {
            continue;
        }

        $with422NoBody[] = $key;
    }

    expect($with422NoBody)->toHaveCount(28);

    // And not one of them publishes the query parameter its 422 is about.
    foreach ($with422NoBody as $key) {
        $path = explode(' ', $key, 2)[1];
        $item = ContractSpec::document()['paths'][$path];
        $parameters = $item['parameters'] ?? (ContractSpec::specOperations()[$key]['parameters'] ?? []);

        $publishedNames = array_column((array) $parameters, 'name');

        foreach (['page', 'per_page', 'q', 'tanggal'] as $queryParam) {
            expect($publishedNames)->not->toContain(
                $queryParam,
                "{$key}: it publishes a 422 on ?{$queryParam} but does not publish ?{$queryParam}",
            );
        }
    }
});

it('finds_the_reference_422_reachable_only_through_a_parameter_the_endpoint_refuses', function (): void {
    // The eight non-paginating, non-searchable reference lists.
    $nonSearching = [
        'get /api/v1/referensi/agama',
        'get /api/v1/referensi/golongan-darah',
        'get /api/v1/referensi/hubungan-keluarga',
        'get /api/v1/referensi/metode-pembayaran',
        'get /api/v1/referensi/pendidikan',
        'get /api/v1/referensi/provinsi',
        'get /api/v1/referensi/spesialisasi',
        'get /api/v1/referensi/status-pernikahan',
    ];

    $spec = ContractSpec::specOperations();

    foreach ($nonSearching as $key) {
        expect(contractPublishes($spec[$key], '422'))->toBeTrue("{$key} publishes 422");
    }

    // A pagination parameter is silently IGNORED on these: all three answer 200.
    $this->getJson('/api/v1/referensi/agama?page=abc')->assertOk();
    $this->getJson('/api/v1/referensi/agama?page=-1')->assertOk();
    $this->getJson('/api/v1/referensi/agama?per_page=0')->assertOk();

    // The only reachable 422 is the endpoint refusing a parameter it does not
    // accept -- a client mistake the document never warned about.
    $refused = $this->getJson('/api/v1/referensi/agama?q=contract-suite');
    $refused->assertStatus(422);

    ['object' => $body] = LiveRequest::decode($refused);
    expect($body->errors)->toHaveProperty('q');
    expect($body->errors->q[0])->toContain('is not accepted by this endpoint');

    // The contrast that proves the rule set is real rather than absent: on a
    // PAGINATING endpoint the same parameter produces a correct validation 422.
    $paginating = $this->getJson('/api/v1/referensi/icd10?page=abc');
    $paginating->assertStatus(422);

    ['object' => $paginatingBody] = LiveRequest::decode($paginating);
    expect($paginatingBody->errors)->toHaveProperty('page');
});
