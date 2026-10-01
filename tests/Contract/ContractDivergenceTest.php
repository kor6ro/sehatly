<?php

declare(strict_types=1);

use App\Support\Schema\SqlSchemaParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
/*
 |--------------------------------------------------------------------------
 | Findings: where docs/openapi.yaml and the application disagree
 |--------------------------------------------------------------------------
 |
 | ## Read this before reading the pass count
 |
 | Driving the live application against the generated document turned up real
 | disagreements. Findings 1-4 were the GENERATOR's fault: an envelope rule that
 | could not tell "does not page" from "pages without being asked", `data` typed as
 | an array where the application answers an object, `meta` required on a write that
 | sends none, and a `per_page` floor of 1 where the application truthfully sends 0.
 | F-007 corrected `App\Support\OpenApi\OpenApiDocumentBuilder`, regenerated the
 | document, and rewrote these four blocks to assert the CORRECTED document - each
 | still validated against a live response, so they cannot pass against a document
 | that was merely edited by hand.
 |
 | Findings 5, 6 and 7 remain OPEN. Their tests are tripwires: they pass while the
 | document publishes the wrong claim and go red the moment somebody fixes it
 | without updating `docs/contract-conformance.md`, which is the behaviour a
 | tripwire should have.
 |
 | ## Each open finding names which side is wrong
 |
 | `finds_the_spec_...` in the name of an open finding is deliberate. If a future
 | executor concludes the APPLICATION is the wrong side, the correct action is to
 | change the test name and the reasoning with it, so the file cannot quietly assert
 | a conclusion it no longer holds.
 */

use Tests\Contract\Support\ContractSpec;
use Tests\Contract\Support\LiveRequest;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 |--------------------------------------------------------------------------
 | Finding 1 (RESOLVED by F-007): list responses publish the meta envelope
 |--------------------------------------------------------------------------
 |
 | `SuccessEnvelope` declares `additionalProperties: false` and has no `meta`
 | property; sixteen operations publish a meta block in their body. The generator
 | used to choose `PaginatedEnvelope` only when the FormRequest rules contained
 | `page` or `per_page`, so a GET route with no FormRequest - and an endpoint that
 | answers `singlePageMeta()` - was published as a three-key envelope its own body
 | violated.
 |
 | F-007 makes the rule the controller method's own source: an action that calls
 | `ApiResponse::pageMeta()` or `ApiResponse::singlePageMeta()` publishes
 | `PaginatedEnvelope`, and nothing else does. These tests assert the corrected
 | document AND the live body, so a hand-edit to `docs/openapi.yaml` cannot satisfy
 | them.
 */
it('publishes_the_meta_envelope_for_operations_that_answer_with_meta', function (): void {
    // Live-proven: the ten anonymous operations below are driven for real and each
    // is checked to send `meta` AND to validate against its published schema.
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
        expect(property_exists($body, 'meta'))->toBeTrue("{$key} answers a top-level meta block");

        // (b) And the document now publishes the envelope that accepts it.
        expect(ContractSpec::schemaRefFor($spec[$key], '200'))
            ->toBe('#/components/schemas/PaginatedEnvelope');

        // (c) So the live body validates clean against its own published schema.
        expect(LiveRequest::validateAgainstDocument($response, $spec[$key]))
            ->toBe([], "{$key}: the published 200 schema must accept the live body");
    }
});

it('publishes_the_meta_envelope_for_all_seventeen_single_page_meta_operations', function (): void {
    // The full set, from the controllers' own `ApiResponse::success(...)` calls
    // rather than from the document. Recorded here so the coverage document's
    // number is test-enforced: adding an eighteenth single-page-meta endpoint makes
    // this fail and forces the doc to move.
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
        // F02's version authority, appended beside the checklist it serves.
        'get /api/v1/pdp/dokumen',
        'get /api/v1/pdp/persetujuan',
        'get /api/v1/pasien/surat-keterangan',
    ];

    expect($singlePageMetaOperations)->toHaveCount(17);

    $spec = ContractSpec::specOperations();

    foreach ($singlePageMetaOperations as $key) {
        expect($spec)->toHaveKey($key);

        $ref = ContractSpec::schemaRefFor($spec[$key], '200');

        expect($ref)->toBe('#/components/schemas/PaginatedEnvelope', "{$key} publishes PaginatedEnvelope");
    }
});

/*
 |--------------------------------------------------------------------------
 | Finding 2 (RESOLVED by F-007): PaginatedEnvelope types `data` as an object
 |--------------------------------------------------------------------------
 |
 | `PaginatedEnvelope` declared `data: {type: array}`. Every paginated endpoint
 | answers `data` as a JSON OBJECT keyed by the resource name -- `{"dokter":[]}`,
 | `{"provinsi":[]}` -- because each controller wraps its collection in a named key
 | so the client knows which resource it is reading. A client typed from the old
 | schema read `List<dynamic>` and was handed a map.
 |
 | F-007 publishes `data` as an object with `additionalProperties: true`, and the
 | tests below drive the anonymous paginated reads and assert the live object
 | validates instead of asserting that it is rejected.
 */
it('types_paginated_data_as_the_object_every_endpoint_answers', function (): void {
    $schema = ContractSpec::resolveRef('#/components/schemas/PaginatedEnvelope');
    expect($schema['properties']['data']['type'])->toBe('object');

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

        // And that schema now accepts the object.
        expect(LiveRequest::validateAgainstDocument($response, $spec[$key]))
            ->toBe([], "{$key}: the published 200 schema must accept the live data object");
    }
});

it('types_every_meta_operation_as_paginated', function (): void {
    $paginated = [];

    foreach (ContractSpec::specOperations() as $key => $operation) {
        foreach (['200', '201'] as $status) {
            if (ContractSpec::schemaRefFor($operation, $status) === '#/components/schemas/PaginatedEnvelope') {
                $paginated[] = $key.' @'.$status;
            }
        }
    }

    sort($paginated);

    // Thirty-four after F-007, F02, F09, F13 and F10: the fourteen that were
    // already paginated, plus the sixteen the old rule published as three-key
    // envelopes, minus the one write the old rule wrongly paginated, plus F02's
    // `GET /pdp/dokumen`, plus F09's pharmacist queue, plus F13's
    // `GET /api/v1/konsultasi` (the doctor's own list), plus F10's two
    // medical-record reads (`GET /api/v1/rekam-medis` and
    // `GET /api/v1/rekam-medis/{id}/akses`). Re-measured on the regenerated
    // document; the membership below stops the count drifting silently.
    expect($paginated)->toHaveCount(34);

    expect($paginated)->toContain('get /api/v1/dokter @200');
    expect($paginated)->toContain('get /api/v1/obat @200');
    expect($paginated)->toContain('get /api/v1/notifikasi @200');
    expect($paginated)->toContain('get /api/v1/auth/devices @200');
    expect($paginated)->toContain('get /api/v1/pdp/dokumen @200');
    expect($paginated)->toContain('get /api/v1/pasien/surat-keterangan @200');
    expect($paginated)->toContain('get /api/v1/resep @200');
    expect($paginated)->toContain('get /api/v1/konsultasi @200');
    expect($paginated)->toContain('get /api/v1/rekam-medis @200');
    expect($paginated)->toContain('get /api/v1/rekam-medis/{id}/akses @200');
    expect($paginated)->not->toContain('post /api/v1/konsultasi/{id}/chat/baca @201');
});

/*
 |--------------------------------------------------------------------------
 | Finding 3 (RESOLVED by F-007): the chat-read write is not a list
 |--------------------------------------------------------------------------
 |
 | `PaginatedEnvelope` lists `meta` in `required`, so a response without it cannot
 | validate. `POST /konsultasi/{id}/chat/baca` used to publish `PaginatedEnvelope`
 | for its 201 while its controller calls `ApiResponse::success([...])` with no meta
 | block at all - because its `TandaiDibacaRequest` is the ONE request body in the
 | project carrying `page`/`per_page`, and the old rule read that as "is a list".
 |
 | The endpoint answers a write's result (a consultation id and a count), and F-007
 | makes the rule the action's `pageMeta()`/`singlePageMeta()` call instead, so it
 | publishes `SuccessEnvelope` and its own body validates.
 */
it('publishes_the_three_key_envelope_for_the_chat_read_write', function (): void {
    $operation = ContractSpec::specOperations()['post /api/v1/konsultasi/{id}/chat/baca'];

    expect(ContractSpec::schemaRefFor($operation, '201'))
        ->toBe('#/components/schemas/SuccessEnvelope');

    $schema = ContractSpec::resolveRef('#/components/schemas/SuccessEnvelope');
    expect($schema['additionalProperties'])->toBeFalse();
    expect($schema['properties'])->not->toHaveKey('meta');

    // Verified by reading `KonsultasiController::chatBaca()`, which calls
    // `ApiResponse::success([...])` with three arguments. Reaching a real 201
    // needs a consultation row and a permission grant, so this is asserted as a
    // code fact and named as code-read rather than live in the coverage document.
    expect(array_key_exists('requestBody', $operation))->toBeTrue();
});

/*
 |--------------------------------------------------------------------------
 | Finding 4 (RESOLVED by F-007): per_page has a floor of 0
 |--------------------------------------------------------------------------
 |
 | `PaginatedMeta.per_page` declared `minimum: 1`, but `ApiResponse::singlePageMeta()`
 | sets `per_page` to the ROW COUNT, so a deliberately-unpaginated list with no rows
 | truthfully answers `per_page: 0` - which its own published schema rejected.
 |
 | F-007 lowers the floor to 0. This is reachable whenever a single-page reference
 | list is empty, which is the state `RefreshDatabase` leaves `master_provinsi` in,
 | so the assertion below is a real unseeded response rather than a constructed one.
 */
it('accepts_per_page_zero_on_an_empty_single_page_list', function (): void {
    $schema = ContractSpec::resolveRef('#/components/schemas/PaginatedMeta');
    expect($schema['properties']['per_page']['minimum'])->toBe(0);

    // `master_provinsi` is empty under RefreshDatabase, so this is the real
    // unseeded response rather than a constructed one.
    $response = $this->getJson('/api/v1/referensi/provinsi');
    $response->assertOk();

    ['object' => $body] = LiveRequest::decode($response);

    expect((int) $body->meta->total)->toBe(0);
    expect((int) $body->meta->per_page)->toBe(0);

    expect(LiveRequest::validateAgainstDocument(
        $response,
        ContractSpec::specOperations()['get /api/v1/referensi/provinsi'],
    ))->toBe([]);
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
 * `201, 404, 429, 500` (the 429 was added by F-002, which mounted
 * `throttle:webhook-payment` on the route). An unsigned request answers a 401 the
 * document does not list.
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
    expect(ContractSpec::documentedStatuses($operation))->toBe(['201', '404', '429', '500']);

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
