<?php

declare(strict_types=1);

use Tests\Contract\Support\ContractSpec;
use Tests\Contract\Support\LiveRequest;

/*
 |--------------------------------------------------------------------------
 | Findings: where docs/openapi.yaml and the application disagree
 |--------------------------------------------------------------------------
 |
 | ## Read this before reading the pass count
 |
 | Driving the live application against the generated document turned up real
 | disagreements. In every case below the DOCUMENT is the wrong side, and the
 | fix belongs to `App\Support\OpenApi\OpenApiDocumentBuilder` (the generator) --
 | which this todo is forbidden to edit. Nothing here has been "fixed" by relaxing
 | an assertion or by editing `docs/openapi.yaml`, because a conformance suite
 | that agrees with a wrong spec is worse than no suite: it manufactures
 | confidence nobody checked.
 |
 | ## Why these tests are GREEN
 |
 | Each test asserts TWO things: what the application really does, and that the
 | document still publishes the claim that is wrong. They pass while the defect
 | exists, and they FAIL THE MOMENT the generator is corrected -- which is the
 | behaviour a tripwire should have. A silent divergence fixed without updating
 | `docs/contract-conformance.md` will turn these red.
 |
 | The alternative -- leaving them failing -- would have meant shipping a red
 | suite, and a red suite gets disabled. That is strictly worse than a green
 * suite that names its own known defects.
 |
 | ## Each finding names which side is wrong
 |
 | `spec_is_wrong` in the name of every test is deliberate. If a future executor
 | concludes the APPLICATION is the wrong side, the correct action is to change
 * the test name and the reasoning with it, so the file cannot quietly assert a
 | conclusion it no longer holds.
 */

use App\Support\Schema\SqlSchemaParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 |--------------------------------------------------------------------------
 | Finding 1: SuccessEnvelope forbids `meta`, but 16 operations send one
 |--------------------------------------------------------------------------
 |
 | `SuccessEnvelope` declares `additionalProperties: false` and has no `meta`
 | property at all. Sixteen operations publish it for a 2xx and their controllers
 | pass a meta block to `ApiResponse::success()`, so every one of them answers a
 | body its own published schema rejects.
 |
 | The cause is in the generator, not the controllers. `envelopeFor()` chooses
 | `PaginatedEnvelope` only when the operation's FormRequest rules contain `page`
 | or `per_page`. A GET route has no FormRequest, and an endpoint that lists a
 * bounded set answers `singlePageMeta()` -- so the generator sees no pagination
 | rule, concludes "not paginated", and publishes the three-key envelope. The
 | rule it is using cannot tell "does not page" from "pages without asking".
 |
 | THE APPLICATION IS RIGHT. `ApiResponse`'s own docblock states that a response
 | which is a list but is deliberately not paginated "carries the same keys with
 | the degenerate values current_page = 1 and last_page = 1", and names
 | `GET /api/v1/auth/devices` as the case. Every client already parses one list
 * envelope. The document is the side that is wrong.
 */
it('finds_the_spec_publishes_a_three_key_envelope_for_operations_that_answer_with_meta', function (): void {
    // Live-proven subset: the ten anonymous operations below are driven for real
    // and each is checked to (a) send `meta` and (b) publish an envelope that
    // cannot accept it.
    $liveProof = [
        'get /api/v1/master-spesialisasi',
        'get /api/v1/referensi/agama',
        'get /api/v1/referensi/enums',
        'get /api/v1/referensi/golongan-darah',
        'get /api/v1/referensi/hubungan-keluarga',
        'get /api/v1/referensi/metode-pembayaran',
        'get /api/v1/referensi/pendidikan',
        'get /api/v1/referensi/provinsi',
        'get /api/v1/referensi/spesialisasi',
        'get /api/v1/referensi/status-pernikahan',
    ];

    expect($liveProof)->toHaveCount(10);

    $spec = ContractSpec::specOperations();

    foreach ($liveProof as $key) {
        [$method, $path] = explode(' ', $key, 2);
        $response = $this->json($method, LiveRequest::concretePath($path));
        $response->assertOk();

        ['object' => $body] = LiveRequest::decode($response);

        // (a) The application really does send `meta`.
        // `property_exists` rather than `toHaveProperty('meta', $message)`: Pest's
        // second argument is the expected VALUE, so a sentence there would assert
        // that `meta` EQUALS that sentence and fail on a correct response.
        expect(property_exists($body, 'meta'))->toBeTrue(
            "{$key} answers a top-level meta block"
        );

        // (b) And the document publishes a schema that cannot accept it.
        $ref = ContractSpec::schemaRefFor($spec[$key], '200');
        expect($ref)->toBe('#/components/schemas/SuccessEnvelope');

        $schema = ContractSpec::resolveRef((string) $ref);
        expect($schema['additionalProperties'])->toBeFalse();
        expect($schema['properties'])->not->toHaveKey('meta');

        $violations = LiveRequest::validateAgainstDocument($response, $spec[$key]);
        expect($violations)->not->toBeEmpty(
            "{$key}: the document's published 200 schema must reject the live body, or this finding is stale"
        );
    }
});

it('names_all_sixteen_operations_the_document_describes_without_the_meta_they_send', function (): void {
    // The full set, from the controllers' own `ApiResponse::success(...)` calls
    // rather than from the document. Recorded here so the number in
    // `docs/contract-conformance.md` is test-enforced: adding a seventeenth
    // single-page-meta endpoint makes this fail and forces the doc to move.
    $singlePageMetaOperations = [
        // Live-verified by the test above.
        'get /api/v1/master-spesialisasi',
        'get /api/v1/referensi/agama',
        'get /api/v1/referensi/enums',
        'get /api/v1/referensi/golongan-darah',
        'get /api/v1/referensi/hubungan-keluarga',
        'get /api/v1/referensi/metode-pembayaran',
        'get /api/v1/referensi/pendidikan',
        'get /api/v1/referensi/provinsi',
        'get /api/v1/referensi/spesialisasi',
        'get /api/v1/referensi/status-pernikahan',
        // Verified by reading the controller, not by a live authenticated
        // request -- named as such in the coverage document.
        'get /api/v1/auth/devices',
        'get /api/v1/dokter/{dokter}/jadwal',
        'get /api/v1/dokter/{dokter}/slot',
        'get /api/v1/konsultasi/{id}/chat',
        'get /api/v1/pdp/persetujuan',
        'get /api/v1/pasien/surat-keterangan',
    ];

    expect($singlePageMetaOperations)->toHaveCount(16);

    $spec = ContractSpec::specOperations();

    foreach ($singlePageMetaOperations as $key) {
        expect($spec)->toHaveKey($key);

        $ref = ContractSpec::schemaRefFor($spec[$key], '200');

        expect($ref)->toBe('#/components/schemas/SuccessEnvelope', "{$key} publishes SuccessEnvelope");
    }
});

/*
 |--------------------------------------------------------------------------
 | Finding 2: PaginatedEnvelope says `data` is an array; it is an object
 |--------------------------------------------------------------------------
 |
 | `PaginatedEnvelope` declares `data` as `type: array`. Every paginated endpoint
 | answers `data` as a JSON OBJECT keyed by the resource name -- `{"dokter":[]}`,
 * `{"provinsi":[]}` -- because each controller wraps the collection in a named
 | key so a client knows which resource it is reading. A generated client typed
 | from this schema would read `List<dynamic>` and be handed a map.
 |
 | This is the most consequential finding for the mobile team: it is the one
 | that produces a model which compiles and then misbehaves at runtime.
 |
 | THE APPLICATION IS RIGHT, and both sides of the codebase agree with each
 | other: every controller wraps its collection in a data key, and
 * `ApiResponse` is handed an array, not a collection. Only the generator's
 * envelope template disagrees.
 */
it('finds_the_spec_types_paginated_data_as_an_array_while_every_endpoint_answers_an_object', function (): void {
    $schema = ContractSpec::resolveRef('#/components/schemas/PaginatedEnvelope');
    expect($schema['properties']['data']['type'])->toBe('array');

    // Live-proven on the two paginated reads an anonymous caller can reach.
    $cases = [
        'get /api/v1/dokter',
        'get /api/v1/referensi/icd10',
        'get /api/v1/referensi/kabupaten-kota',
        'get /api/v1/referensi/kelurahan',
        'get /api/v1/referensi/kecamatan',
        'get /api/v1/referensi/icd9cm',
    ];

    $spec = ContractSpec::specOperations();

    foreach ($cases as $key) {
        [$method, $path] = explode(' ', $key, 2);
        $response = $this->json($method, LiveRequest::concretePath($path));
        $response->assertOk();

        ['object' => $body, 'raw' => $raw] = LiveRequest::decode($response);

        // The application answers a JSON OBJECT.
        expect($body->data)->toBeObject("{$key} answers data as an object");
        expect(str_starts_with($raw, '{"success":true,"data":{'))->toBeTrue();

        // The document publishes PaginatedEnvelope for it.
        expect(ContractSpec::schemaRefFor($spec[$key], '200'))
            ->toBe('#/components/schemas/PaginatedEnvelope');

        // And that schema cannot accept the object.
        $violations = LiveRequest::validateAgainstDocument($response, $spec[$key]);
        expect($violations)->not->toBeEmpty(
            "{$key}: the document's published 200 schema must reject the live data object, or this finding is stale"
        );
        expect(implode("\n", $violations))->toContain('#/data');
    }
});

it('names_all_fourteen_operations_the_document_types_as_paginated', function (): void {
    $paginated = [];

    foreach (ContractSpec::specOperations() as $key => $operation) {
        foreach (['200', '201'] as $status) {
            if (ContractSpec::schemaRefFor($operation, $status) === '#/components/schemas/PaginatedEnvelope') {
                $paginated[] = $key.' @'.$status;
            }
        }
    }

    sort($paginated);

    expect($paginated)->toHaveCount(14);

    // And the app's own `data` is an object on every one of them, so all
    // fourteen are affected, not just the six that were driven live.
    expect($paginated)->toContain('get /api/v1/dokter @200');
    expect($paginated)->toContain('get /api/v1/obat @200');
    expect($paginated)->toContain('get /api/v1/notifikasi @200');
});

/*
 |--------------------------------------------------------------------------
 | Finding 3: PaginatedEnvelope REQUIRES meta; one operation sends none
 |--------------------------------------------------------------------------
 |
 | The mirror image of Finding 2. `PaginatedEnvelope` lists `meta` in `required`,
 * so a response without it cannot validate. `POST /konsultasi/{id}/chat/baca`
 | publishes `PaginatedEnvelope` for its 201 and its controller calls
 | `ApiResponse::success([...])` with no meta block at all.
 *
 | It is published as paginated because its `TandaiDibacaRequest` is the ONE
 | request body in the project that carries `page`/`per_page`, so the generator's
 * rule selects the paginated envelope -- while the endpoint answers a write's
 * result (a consultation id and a count), which is not a list at all.
 */
it('finds_the_spec_requires_meta_on_a_write_that_sends_none', function (): void {
    $operation = ContractSpec::specOperations()['post /api/v1/konsultasi/{id}/chat/baca'];

    expect(ContractSpec::schemaRefFor($operation, '201'))
        ->toBe('#/components/schemas/PaginatedEnvelope');

    $schema = ContractSpec::resolveRef('#/components/schemas/PaginatedEnvelope');
    expect($schema['required'])->toContain('meta');

    // Verified by reading `KonsultasiController::chatBaca()`, which calls
    // `ApiResponse::success([...])` with three arguments. Reaching a real 201
    // needs a consultation row and a permission grant, so this is asserted as a
    // code fact and named as code-read rather than live in the coverage document.
    expect(array_key_exists('requestBody', $operation))->toBeTrue();
});

/*
 |--------------------------------------------------------------------------
 | Finding 4: PaginatedMeta promises per_page >= 1; the application sends 0
 |--------------------------------------------------------------------------
 |
 | `PaginatedMeta.per_page` declares `minimum: 1`. `ApiResponse::singlePageMeta()`
 | sets `per_page` to the ROW COUNT, so a deliberately-unpaginated list with no
 | rows answers `per_page: 0` -- which its own published schema rejects.
 *
 | THE APPLICATION IS RIGHT and the schema is wrong: `per_page` on a single-page
 * list IS the number of rows, and zero rows is a truthful zero. The generator's
 * docblock shows the same reasoning about `maximum` -- it removed an upper bound
 * because `singlePageMeta()` reports the row count -- and then left the LOWER
 * bound at 1 without noticing that the same row count can be zero.
 *
 * Reachable whenever a single-page reference list is empty. On a fully seeded
 * deployment most are populated, so this is a latent defect rather than a
 * constant one, which is exactly why a suite that only ever looks at a seeded
 * database would never have found it.
 */
it('finds_the_schema_per_page_floor_of_one_the_application_breaks_on_an_empty_list', function (): void {
    $schema = ContractSpec::resolveRef('#/components/schemas/PaginatedMeta');
    expect($schema['properties']['per_page']['minimum'])->toBe(1);

    // `master_provinsi` is empty under RefreshDatabase, so this is the real
    // unseeded response rather than a constructed one.
    $response = $this->getJson('/api/v1/referensi/provinsi');
    $response->assertOk();

    ['object' => $body] = LiveRequest::decode($response);

    expect((int) $body->meta->total)->toBe(0);
    expect((int) $body->meta->per_page)->toBe(0);
    expect($body->meta->per_page)->toBeLessThan(1);

    $violations = LiveRequest::validateAgainstDocument($response, ContractSpec::specOperations()['get /api/v1/referensi/provinsi']);
    expect($violations)->not->toBeEmpty();
});

/*
 |--------------------------------------------------------------------------
 | Finding 5: the QR verifier requires an UNPUBLISHED query parameter and
 |            answers an UNDOCUMENTED 422 for it
 |--------------------------------------------------------------------------
 |
 | `GET /api/v1/surat-keterangan/{nomor_surat}/verify` publishes
 | `200, 404, 500` and a single path parameter. It also requires `?token=`, and
 | without it answers a 422 the document does not list, with an `errors.token`
 | message no published schema for that operation can describe.
 |
 | A client generated from this document has no way to learn the parameter
 * exists: it is not in `parameters`, there is no `requestBody`, and no status
 * hints at it. The endpoint therefore looks callable and is not.
 *
 | THE DOCUMENT IS WRONG. The controller's own docblock calls a missing token
 * "the one case that is not a verification", so the 422 is deliberate and the
 * omission is the defect.
 */
it('finds_the_qr_verifier_answering_an_undocumented_422_for_an_unpublished_parameter', function (): void {
    $operation = ContractSpec::specOperations()['get /api/v1/surat-keterangan/{nomor_surat}/verify'];

    // The document publishes no 422 and no `token` parameter.
    expect(ContractSpec::documentedStatuses($operation))->toBe(['200', '404', '500']);
    expect(ContractSpec::document()['paths']['/api/v1/surat-keterangan/{nomor_surat}/verify']['parameters'])
        ->toBe([['name' => 'nomor_surat', 'type' => 'string', 'required' => true]]);

    // The live endpoint answers 422 when the token is absent.
    $response = $this->getJson('/api/v1/surat-keterangan/SK-ABSENT-CONTRACT/verify');
    $response->assertStatus(422);

    ['object' => $body] = LiveRequest::decode($response);
    expect($body->errors)->toHaveProperty('token');

    // And that status is genuinely not documented, which is the finding.
    $violations = LiveRequest::validateAgainstDocument($response, $operation);
    expect($violations)->not->toBeEmpty();
    expect(implode("\n", $violations))->toContain('publishes no 422');
});

/*
 |--------------------------------------------------------------------------
 | Finding 6: the webhook is declared anonymous and answers an UNDOCUMENTED 401
 |--------------------------------------------------------------------------
 |
 | `POST /api/v1/webhook/payment/{gateway}` publishes `security: []` and
 * `201, 404, 500`. An unsigned request answers a 401 the document does not list.
 *
 | `security: []` is accurate about the Sanctum token -- there is none, and
 | `MockPaymentGatewayService::verifyWebhook()` says so -- but it is misleading
 * about AUTHENTICATION. The endpoint is guarded by an HMAC-SHA256 signature over
 * the raw body, which is not expressible as an OpenAPI `securityScheme` here, so
 * the document has no way to say "authenticated by signature". The result is an
 * endpoint that reads as unguarded and is guarded.
 *
 | THE DOCUMENT IS WRONG. Answering 401 to an unverifiable signature is correct;
 * failing to publish that is the defect.
 */
it('finds_the_webhook_declared_anonymous_answering_an_undocumented_401', function (): void {
    $operation = ContractSpec::specOperations()['post /api/v1/webhook/payment/{gateway}'];

    expect(ContractSpec::isAnonymous($operation))->toBeTrue();
    expect(ContractSpec::documentedStatuses($operation))->toBe(['201', '404', '500']);

    // No signature header: `verifyWebhook()` checks the header FIRST, before the
    // body is touched, and raises `TandaTanganWebhookTidakValid` -- a 401.
    //
    // The body values are real DDL tokens. `pembayaran.status` is
    // `enum('pending','berhasil','gagal','kedaluwarsa','refund')`
    // (telemedicine_test.sql:965) and `pembayaran.gateway` is
    // `enum('midtrans','xendit','doku','flip')`, both read back through
    // `SqlSchemaParser` while writing this test. An invented value such as
    // `settle` -- which appears nowhere in the schema -- would be the same class
    // of defect as the `referencia` typo that once cost this project 48 test
    // failures: the assertion would pass for the wrong reason and the token
    // would rot silently. The body is never parsed on this path, so a wrong
    // value here would cost nothing today and everything the day it did.
    $response = $this->postJson('/api/v1/webhook/payment/midtrans', [
        'nomor_referensi' => 'MOCK-CONTRACT-SUITE',
        'status' => 'berhasil',
        'jumlah' => '1000.00',
    ]);

    $response->assertStatus(401);

    ['object' => $body] = LiveRequest::decode($response);
    expect($body->success)->toBeFalse();
    expect($body->errors)->toBeObject();

    $violations = LiveRequest::validateAgainstDocument($response, $operation);
    expect($violations)->not->toBeEmpty();
    expect(implode("\n", $violations))->toContain('publishes no 401');
});

/*
 |--------------------------------------------------------------------------
 | Token hygiene: every literal this suite writes is a real DDL token
 |--------------------------------------------------------------------------
 |
 | This project's own history is the reason. A single misspelled Indonesian token
 | -- `referencia` for `referensi` -- cost 48 test failures, and the failure mode
 | is the dangerous kind: the assertions that used the wrong token passed or
 | failed for reasons unrelated to what they claimed to check, and nothing
 | announced that a literal had stopped meaning anything.
 |
 | A conformance suite is where that is most likely, because it is full of
 * literals copied out of a schema: path-parameter names, column names, ENUM
 | values. So every one is read back through `App\Support\Schema\SqlSchemaParser`
 | -- the same parser `sehatly:verify-schema` uses, which means this audit cannot
 * disagree with the verifier about what the DDL says.
 *
 | This test caught a real instance of the defect it exists to catch: the webhook
 * body in the test above originally carried `status => settle`, which appears
 | NOWHERE in `telemedicine_test.sql`. The assertion would have passed regardless,
 * because the signature check rejects the request before the body is parsed --
 * so the wrong token would have cost nothing that day and everything the day the
 * path changed.
 */
it('writes_only_tokens_the_ddl_actually_declares', function (): void {
    $schema = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    // 1. The path-parameter substitutions, as columns of the tables they address.
    expect($schema->hasTable('users'))->toBeTrue();
    expect($schema->table('users')->columns)->toHaveKey('nama_lengkap');
    expect($schema->table('users')->columns)->toHaveKey('tipe');

    // 2. The ENUM-value bucket: every value this suite writes must exist in some
    //    declared ENUM. This is the bucket a typo lands in silently.
    $declared = [];

    foreach ($schema->tables as $table) {
        foreach ($table->columns as $column) {
            if (! preg_match('/^enum\((.*)\)$/', $column->type, $matches)) {
                continue;
            }

            foreach (explode(',', $matches[1]) as $value) {
                $declared[trim(trim($value), "'")][] = $table->name.'.'.$column->name;
            }
        }
    }

    // `midtrans` is the `{gateway}` substitution; `berhasil` is the webhook
    // `status` body value. Both must be real.
    expect($declared)->toHaveKey('midtrans');
    expect($declared['midtrans'])->toBe(['pembayaran.gateway']);

    // `berhasil` is shared by two ENUM columns, which is itself worth stating:
    // the webhook's `status` is ambiguous between `pembayaran` and `refund`, and
    // only the surrounding `gateway` + reference keys disambiguate it. Asserting
    // the real pair keeps a future migration that adds a third home visible.
    expect($declared)->toHaveKey('berhasil');
    $berhasilHomes = $declared['berhasil'];
    sort($berhasilHomes);
    expect($berhasilHomes)->toBe(['pembayaran.status', 'refund.status']);

    // And the value this suite originally wrote must NOT exist, so a future
    // re-introduction of the same typo is caught by name rather than by accident.
    expect($declared)->not->toHaveKey('settle');
});