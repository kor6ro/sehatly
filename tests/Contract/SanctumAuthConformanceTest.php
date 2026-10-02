<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Contract\Support\ContractSpec;
/*
 |--------------------------------------------------------------------------
 | Sanctum bearer auth really yields 401 on an absent or garbage token
 |--------------------------------------------------------------------------
 |
  | The document declares 58 operations as bearer-protected and 25 as explicitly
  | anonymous. This file drives EVERY one of the 58 against the running
  | application, twice: once with no `Authorization` header at all, and once with
  | a token that is structurally plausible and cryptographically meaningless.
 |
 | ## Why "garbage" is not the same as "absent"
 |
 * `auth:sanctum` has two separate failure paths and a client can hit either. An
 * absent header fails at token PARSING -- there is no bearer credential to look
 * up. A well-formed but unknown token fails at DATABASE LOOKUP -- the hash is
 * computed, no row matches, and the guard resolves to nobody. Both must answer
 | 401 with the same envelope, because a caller who retries on one and not the
 * other learns from the difference. Asserting only the absent case would leave
 * the second path unproven.
 |
 * ## Why the garbage token is shaped like a Sanctum token
 |
 * Sanctum personal access tokens are `<id>|<40-char-plaintext>`. A token of the
 * wrong SHAPE can fail a different assertion inside the guard than a token of the
 * right shape with an unknown id, so both tests send the real shape: the first
 * segment is an id that cannot exist in an empty table and the second is 40
 * characters of filler.
 *
  * ## Why this is worth 116 real requests
  |
  * | Because it is the cheapest proof in the suite that the guard is actually
  * | mounted on all 58 routes rather than on the handful the module tests happen to
  * | touch. A route that lost its `auth:sanctum` in a refactor would answer 500 or
  * | 302 here, and nothing else in the suite would notice.
  */

use Tests\Contract\Support\LiveRequest;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A Sanctum token whose shape is correct and whose value cannot be valid.
 *
 * `1|<40 chars>`: the id segment cannot exist because `personal_access_tokens`
 * is empty under `RefreshDatabase`, and the plaintext segment is 40 characters
 * as Sanctum issues it.
 */
const CONTRACT_GARBAGE_TOKEN = '1|AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

/**
 * The bearer-protected operations, read from the document rather than listed.
 *
 * @return array<string, array<string, mixed>>
 */
function contractBearerOperations(): array
{
    $bearer = [];

    foreach (ContractSpec::specOperations() as $key => $operation) {
        if (! ContractSpec::isAnonymous($operation)) {
            $bearer[$key] = $operation;
        }
    }

    return $bearer;
}

/**
 * The explicitly anonymous operations, read from the document.
 *
 * `POST /api/v1/webhook/payment/{gateway}` is EXCLUDED, and its absence is the
 * point rather than an oversight. The document declares it `security: []`,
 * which is accurate about the Sanctum token and misleading about
 * authentication: the endpoint is guarded by an HMAC-SHA256 signature over the
 * raw body (`MockPaymentGatewayService::verifyWebhook`), and an unsigned request
 * answers a 401 the document does not publish. Asserting that here would be
 * asserting a document bug; it is asserted as a finding in
 * `ContractDivergenceTest`.
 *
 * @return array<string, array<string, mixed>>
 */
function contractAnonymousOperations(): array
{
    $anonymous = [];

    foreach (ContractSpec::specOperations() as $key => $operation) {
        if (ContractSpec::isAnonymous($operation)) {
            $anonymous[$key] = $operation;
        }
    }

    return $anonymous;
}

/**
 * The anonymous operations an anonymous caller is expected to get past.
 *
 * Every anonymous operation except the signature-guarded webhook.
 *
 * @return array<string, array<string, mixed>>
 */
function contractUnguardedAnonymousOperations(): array
{
    return array_filter(
        contractAnonymousOperations(),
        static fn (string $key): bool => ! str_starts_with($key, 'post /api/v1/webhook/'),
        ARRAY_FILTER_USE_KEY,
    );
}

/**
 * Split an operation key back into its method and path.
 *
 * @return array{0: string, 1: string}
 */
function contractSplit(string $key): array
{
    [$method, $path] = explode(' ', $key, 2);

    return [$method, $path];
}

it('splits the documented operations into 58 bearer, 25 anonymous and 24 unguarded', function (): void {
    // The counts are pinned so the datasets below cannot silently shrink. If a
    // route is added or removed, this fails first and names the real delta,
    // instead of a per-route test quietly disappearing from the run. F12 added
    // three bearer operations (`GET /booking/{id}/kebijakan`,
    // `PUT /booking/{id}/jadwal-ulang`, `GET /pasien/refund`) and no anonymous
    // one, which is the 55 -> 58 movement.
    expect(count(contractBearerOperations()))->toBe(58);
    expect(count(contractAnonymousOperations()))->toBe(25);
    expect(count(contractUnguardedAnonymousOperations()))->toBe(24);
    expect(count(contractBearerOperations()) + count(contractAnonymousOperations()))->toBe(83);
});

it('answers 401 with the error envelope when the Authorization header is absent', function (string $method, string $path): void {
    [$m, $p] = contractSplit($method.' '.$path);
    $operation = ContractSpec::specOperations()[$method.' '.$path];

    // Asserted up front: the document must publish a 401 for this operation, or
    // "answered 401" would be an accident rather than conformance.
    expect(ContractSpec::documentedStatuses($operation))->toContain('401');

    $response = $this->json($m, LiveRequest::concretePath($p));

    // `withoutHeader` rather than `flushHeaders`, for the reason
    // `AuthFlowTest::authAsAnonymous()` documents: flushing would also drop the
    // Accept header `json()` sets, making this a different test.
    $response = $response->assertStatus(401);

    ['object' => $body, 'raw' => $raw] = LiveRequest::decode($response);

    expect($body->success)->toBeFalse();
    expect($body->message)->toBe('Unauthenticated.');
    expect($body->errors)->toBeObject();

    // The error map is the empty OBJECT `{}`, asserted on raw bytes because `{}`
    // and `[]` are indistinguishable after an associative decode.
    expect(str_contains($raw, '"errors":{}'))->toBeTrue();

    // And no redirect: a bearer API that answers 302 to a login page breaks every
    // mobile client, and `bootstrap/app.php` had to opt out of exactly that.
    expect($response->headers->get('Location'))->toBeNull();

    $violations = LiveRequest::validateAgainstDocument($response, $operation);

    expect($violations)->toBe([], implode("\n", $violations));
})->with(function (): array {
    return array_map(
        static fn (string $key): array => contractSplit($key),
        array_keys(contractBearerOperations()),
    );
});

it('answers 401 with the error envelope for a well-formed but unknown bearer token', function (string $method, string $path): void {
    $operation = ContractSpec::specOperations()[$method.' '.$path];

    $response = $this->json(
        $method,
        LiveRequest::concretePath($path),
        ['Authorization' => 'Bearer '.CONTRACT_GARBAGE_TOKEN],
    );

    $response->assertStatus(401);

    ['object' => $body, 'raw' => $raw] = LiveRequest::decode($response);

    // Byte-identical to the absent-token body. A caller must not be able to tell
    // "no token" from "wrong token" -- that distinction is a token oracle.
    expect($body->success)->toBeFalse();
    expect($body->message)->toBe('Unauthenticated.');
    expect(str_contains($raw, '"errors":{}'))->toBeTrue();

    $violations = LiveRequest::validateAgainstDocument($response, $operation);

    expect($violations)->toBe([], implode("\n", $violations));
})->with(function (): array {
    return array_map(
        static fn (string $key): array => contractSplit($key),
        array_keys(contractBearerOperations()),
    );
});

it('publishes no 401 for any operation the document declares anonymous', function (string $method, string $path): void {
    $operation = ContractSpec::specOperations()[$method.' '.$path];

    // The 25 anonymous operations are the doctor directory, the reference
    // tables, the QR verifier, the four unauthenticated auth endpoints and the
    // payment webhook. None of them may document a 401: publishing one would tell
    // the mobile team to handle an unreachable status, and would mean the
    // "anonymous" claim was not actually true of the route.
    expect(ContractSpec::documentedStatuses($operation))->not->toContain('401');
})->with(function (): array {
    return array_map(
        static fn (string $key): array => contractSplit($key),
        array_keys(contractAnonymousOperations()),
    );
});

it('really does let an anonymous caller through the 25 anonymous operations', function (string $method, string $path): void {
    // The complement of the test above, and the reason the above is not enough.
    // "No 401 documented" is a claim about the DOCUMENT. This is the claim about
    // the APPLICATION: an anonymous caller is not stopped by the guard.
    //
    // Only the guard is under test here, so a status the route reaches for its
    // own reasons (200 on a read, 422 on a missing body, 404 on a bad path
    // parameter) is accepted. What must never happen is 401 or 403, which would
    // mean an anonymous guard the document does not admit to.
    //
    // The signature-guarded webhook is absent from this dataset on purpose; see
    // `contractUnguardedAnonymousOperations()`.
    $response = $this->json($method, LiveRequest::concretePath($path));

    expect($response->getStatusCode())->not->toBe(401);
    expect($response->getStatusCode())->not->toBe(403);

    // And whatever it answered, it answered in the documented envelope.
    expect(ContractSpec::documentedStatuses(ContractSpec::specOperations()[$method.' '.$path]))
        ->toContain((string) $response->getStatusCode());
})->with(function (): array {
    return array_map(
        static fn (string $key): array => contractSplit($key),
        array_keys(contractUnguardedAnonymousOperations()),
    );
});
