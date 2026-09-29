<?php

declare(strict_types=1);

use Tests\Contract\Support\ContractSpec;
use Tests\Contract\Support\EnvelopeValidator;
use Tests\Contract\Support\LiveRequest;

/*
 |--------------------------------------------------------------------------
 | Envelope conformance: real responses validated against the document
 |--------------------------------------------------------------------------
 |
 | Every assertion below is made against a response the application actually
 | produced. Nothing here compares the document to itself.
 |
 | ## What this file asserts, and what it deliberately does not
 |
 | The envelope FACTS are asserted for every anonymous read, without exception:
 | key order, `meta` as a top-level sibling of `data`, `data` free of a nested
 | `meta`, `success` a real boolean, `message` a real string, and the six
 | pagination keys inside `meta`.
 |
 | Strict per-operation `$ref` validation is asserted only where the document
 | and the application actually agree. It is NOT applied to the operations that
 | disagree, and that is not a filter: the conformant set is pinned to an
 | explicit list by `pinned_operations_are_exactly_the_conformant_set` in
 | `ContractDivergenceTest`, whose complement covers the rest. Between the two
 | files every operation is accounted for, so a new divergence cannot hide
 | inside a `continue`.
 |
 | Seventeen operations currently fail strict validation because the DOCUMENT is
 | wrong, not the application. They are enumerated in
 | `docs/contract-conformance.md` and asserted as findings in
 | `ContractDivergenceTest`. Relaxing the assertion until they pass is precisely
 | the failure mode this todo exists to prevent: a conformance suite that
 * rubber-stamps a wrong spec is worse than no suite, because it manufactures
 | confidence nobody checked.
 |
 | ## Why `meta` nesting is asserted positionally and not structurally
 |
 | `assertJsonStructure` cannot express "not nested inside data" -- it only
 | checks that keys are present. So the key ORDER is read off the raw bytes and
 | `meta` is asserted to sit after `message` as a sibling, with `data.meta`
 | asserted absent. The order is the contract: `ApiResponse` appends `meta`
 | after `message` precisely so adding pagination cannot renumber the three keys
 | every existing client reads.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The four keys of a success envelope, in the order the wire must carry them.
 */
const CONTRACT_SUCCESS_KEYS = ['success', 'data', 'message', 'meta'];

/**
 * The keys of a decoded body, in the order the wire carried them.
 *
 * @return list<string>
 */
function contractKeysInOrder(mixed $body): array
{
    return array_keys((array) $body);
}

it('answers every anonymous read with the success envelope and a top-level meta', function (string $method, string $path): void {
    $response = $this->json($method, LiveRequest::concretePath($path));

    $response->assertOk();

    ['object' => $body, 'raw' => $raw] = LiveRequest::decode($response);

    // Key ORDER, read off the bytes, because order is part of the contract.
    expect(contractKeysInOrder($body))->toBe(CONTRACT_SUCCESS_KEYS);

    expect($body->success)->toBeTrue();
    expect($body->message)->toBeString();
    expect($body->data)->toBeObject();

    // `meta` is a SIBLING of `data`, not a child of it. This is exactly the
    // assertion a generated client's `body.data.meta` access would violate.
    expect($body)->toHaveProperty('meta');
    expect($body->data)->not->toHaveProperty('meta');

    // And the wire order really is success, data, message, meta.
    expect(str_starts_with($raw, '{"success":true,"data":'))->toBeTrue();
    expect(str_contains($raw, ',"message":'))->toBeTrue();
    expect(str_contains($raw, ',"meta":{'))->toBeTrue();

    // `meta` carries the six documented pagination keys, as a real object.
    expect($body->meta)->toBeObject();

    foreach (['current_page', 'last_page', 'per_page', 'total', 'from', 'to'] as $key) {
        // `property_exists` rather than `toHaveProperty($key, $message)`: Pest's
        // second argument is the expected VALUE, so passing a sentence there
        // would assert the property EQUALS that sentence and fail on a correct
        // response.
        expect(property_exists($body->meta, $key))->toBeTrue(
            "meta.{$key} is one of the six documented pagination keys and must be present"
        );
    }

    expect((int) $body->meta->current_page)->toBeGreaterThanOrEqual(1);
    expect((int) $body->meta->last_page)->toBeGreaterThanOrEqual(1);
    expect((int) $body->meta->total)->toBeGreaterThanOrEqual(0);

    // `from` and `to` are null on an empty page -- "no rows" has no first and
    // last row -- and both keys are still PRESENT. Omission would be
    // indistinguishable from a schema that does not promise them at all.
    if ((int) $body->meta->total === 0) {
        expect($body->meta->from)->toBeNull();
        expect($body->meta->to)->toBeNull();
    } else {
        expect($body->meta->from)->toBeInt();
        expect($body->meta->to)->toBeInt();
    }
})->with([
    ['get', '/api/v1/dokter'],
    ['get', '/api/v1/master-spesialisasi'],
    ['get', '/api/v1/referensi/agama'],
    ['get', '/api/v1/referensi/enums'],
    ['get', '/api/v1/referensi/golongan-darah'],
    ['get', '/api/v1/referensi/hubungan-keluarga'],
    ['get', '/api/v1/referensi/icd10'],
    ['get', '/api/v1/referensi/icd9cm'],
    ['get', '/api/v1/referensi/kabupaten-kota'],
    ['get', '/api/v1/referensi/kecamatan'],
    ['get', '/api/v1/referensi/kelurahan'],
    ['get', '/api/v1/referensi/metode-pembayaran'],
    ['get', '/api/v1/referensi/pendidikan'],
    ['get', '/api/v1/referensi/provinsi'],
    ['get', '/api/v1/referensi/spesialisasi'],
    ['get', '/api/v1/referensi/status-pernikahan'],
]);

it('answers the public QR verifier with the success envelope, no meta, and valid:false', function (): void {
    $operation = ContractSpec::specOperations()['get /api/v1/surat-keterangan/{nomor_surat}/verify'];

    // `?token=` is REQUIRED. Without it the endpoint answers 422, a status it
    // does not document -- recorded in ContractDivergenceTest, and the reason the
    // token is supplied here. With it, the endpoint answers 200 carrying
    // `valid: false` for a wrong token AND for a document number that does not
    // exist, so it is not an existence oracle.
    $response = $this->getJson(
        '/api/v1/surat-keterangan/SK-ABSENT-CONTRACT/verify?token=contract-suite-wrong-token'
    );

    $response->assertOk();

    ['object' => $body] = LiveRequest::decode($response);

    expect(contractKeysInOrder($body))->toBe(['success', 'data', 'message']);
    expect($body->success)->toBeTrue();
    expect($body->data)->toBeObject();
    expect($body->data->valid)->toBeFalse();

    // This operation publishes `SuccessEnvelope`, which has no `meta` key, and
    // the endpoint really does send no `meta`. So this one validates strictly.
    expect($body)->not->toHaveProperty('meta');

    $violations = LiveRequest::validateAgainstDocument($response, $operation);

    expect($violations)->toBe([], implode("\n", $violations));
});

it('answers a validation failure with the 422 envelope and an array of messages per field', function (): void {
    $operation = ContractSpec::specOperations()['post /api/v1/auth/register'];

    // `nama_lengkap` is validated by ['required', 'string', 'min:3', 'max:150'].
    // Sending an ARRAY makes `string` fail AND makes `min:3` fail (an array of
    // two elements is shorter than three characters), so ONE field carries TWO
    // messages. This is the case the document's `ValidationErrorEnvelope` exists
    // to describe, and the case a `{field: string}` client silently breaks on.
    $response = $this->postJson('/api/v1/auth/register', [
        'nama_lengkap' => ['a', 'b'],
        'no_telepon' => '081200000001',
    ]);

    $response->assertStatus(422);

    ['object' => $body, 'raw' => $raw] = LiveRequest::decode($response);

    expect(contractKeysInOrder($body))->toBe(['success', 'message', 'errors']);
    expect($body->success)->toBeFalse();
    expect($body->message)->toBe('The given data was invalid.');

    // `errors` is a JSON OBJECT, so the field is addressable as a property.
    expect($body->errors)->toBeObject();

    // THE ASSERTION THAT MATTERS: one field, an ARRAY of messages, more than one
    // entry, every entry a string. A flattened single string is rejected here
    // even though it would satisfy a laxer reading of "carries an error".
    expect($body->errors->nama_lengkap)->toBeArray();
    expect($body->errors->nama_lengkap)->toHaveCount(2);
    foreach ($body->errors->nama_lengkap as $message) {
        expect($message)->toBeString()->not->toBeEmpty();
    }

    // The array really is serialised as an array, not collapsed into a string.
    expect(str_contains($raw, '"nama_lengkap":["'))->toBeTrue();
    expect(str_contains($raw, '"nama_lengkap":"'))->toBeFalse();

    // Every field on the map is the same shape: field -> array of messages.
    foreach ((array) $body->errors as $field => $messages) {
        expect($messages)->toBeArray("errors.{$field} must be an array of messages");
        expect($messages)->not->toBeEmpty("errors.{$field} must carry at least one message");

        foreach ($messages as $message) {
            expect($message)->toBeString("errors.{$field} holds a non-string message");
        }
    }

    $violations = LiveRequest::validateAgainstDocument($response, $operation);

    expect($violations)->toBe([], implode("\n", $violations));
});

it('publishes minItems: 1 on every errors value, so the two-message case is the schema minimum not a fluke', function (): void {
    $schema = ContractSpec::resolveRef('#/components/schemas/ValidationErrorEnvelope');

    // Without `minItems: 1`, an `errors: {field: []}` would validate and a client
    // iterating the array would render nothing at all for a field that failed.
    expect($schema['properties']['errors']['additionalProperties']['minItems'])->toBe(1);
});

it('answers a status with no field-level detail with an empty errors OBJECT, never an empty array', function (): void {
    // `{}` and `[]` both decode to an empty PHP array under an associative
    // decode, so the assertion is made on the RAW BYTES. `ApiResponse::error()`
    // casts the empty map to `(object)` precisely for this reason: a field-keyed
    // map that flipped between JSON object and JSON array depending on emptiness
    // would force every client to branch on a second shape.
    $cases = [
        ['get', '/api/v1/me', 401],
        ['get', '/api/v1/dokter/999999999', 404],
        ['get', '/api/v1/dokter/not-a-number', 404],
    ];

    foreach ($cases as [$method, $path, $expected]) {
        $response = $this->json($method, $path);
        $response->assertStatus($expected);

        ['object' => $body, 'raw' => $raw] = LiveRequest::decode($response);

        expect($body->success)->toBeFalse();
        expect($body->message)->toBeString();
        expect(str_contains($raw, '"errors":{}'))->toBeTrue(
            "{$method} {$path} must serialise errors as {}"
        );
        expect(str_contains($raw, '"errors":[]'))->toBeFalse(
            "{$method} {$path} must not serialise errors as []"
        );
    }
});

it('never leaks a stack trace on a 500, even with app.debug enabled', function (): void {
    // A temporary route is registered so the 500 envelope can be observed at all.
    //
    // No separate "did the route register?" assertion is needed, and an attempt at
    // one would be wrong: `RouteCollection::add()` builds the name index when the
    // route is ADDED, so a `->name()` applied afterwards never reaches it and
    // `hasNamedRoute()` answers false for a route that is very much registered.
    // The `500` assertion below is the registration check -- an unregistered path
    // answers 404 and fails it -- and it cannot be satisfied vacuously.
    Route::get('/api/v1/contract-probe-boom', function (): void {
        throw new RuntimeException('contract-suite-probe-secret-marker');
    });

    $response = $this->getJson('/api/v1/contract-probe-boom');

    expect($response->getStatusCode())->toBe(500);

    ['object' => $body, 'raw' => $raw] = LiveRequest::decode($response);

    expect($body->success)->toBeFalse();
    expect($body->message)->toBe('Internal server error.');
    expect(str_contains($raw, 'contract-suite-probe-secret-marker'))->toBeFalse();
    expect(str_contains($raw, 'RuntimeException'))->toBeFalse();
    expect(str_contains($raw, '.php'))->toBeFalse();
});

it('reports no schema keyword the validator would silently ignore', function (): void {
    // A validator that ignores an unknown keyword keeps passing while asserting
    // less than it appears to. This closes that door: if a future regeneration
    // introduces `oneOf` or `patternProperties`, this fails and the validator must
    // be extended deliberately rather than drifting.
    $unsupported = (new EnvelopeValidator)->unsupportedKeywords();

    expect($unsupported)->toBe([], sprintf(
        'the document uses schema keyword(s) EnvelopeValidator does not implement, so it would '
        .'validate them without checking them: '.implode(', ', $unsupported),
    ));
});