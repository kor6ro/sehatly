<?php

declare(strict_types=1);

use App\Services\Auth\OtpSender;
use App\Services\Auth\OtpService;
use Database\Seeders\RbacSeeder;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeOtpSender;

/*
|--------------------------------------------------------------------------
| Todo 52 -- security headers
|--------------------------------------------------------------------------
|
| A header that breaks authentication is worse than no header, so this file does
| not stop at "the header is present". Every property is asserted twice: once on
| an **anonymous** request, and once on a request that arrived with a **real
| Sanctum bearer token**, with the token's own `200` body asserted byte-exactly.
| A middleware that added a header to the response, and dropped or reordered the
| body on the way, would fail the second half and pass the first.
|
| ## Why an event listener and not a middleware
|
| The headers are attached to the already-built response by a listener on
| `Illuminate\Foundation\Http\Events\RequestHandled`, registered in
| `AppServiceProvider::boot()`. That is a deliberate choice with three
| consequences, all of which are asserted below:
|
| 1. **Nothing in `bootstrap/app.php` changes.** The kernel is the one file every
|    executor in this project shares, and a global `appendMiddleware()` there
|    would put this todo's blast radius on every other todo.
| 2. **Nothing in the envelope changes.** The listener adds headers to a
|    `Response`; it never touches `$response->getContent()`. `ApiResponse` is the
|    only place that builds a body and it is not on this path at all.
| 3. **It runs for every response, including the ones the exception renderer
|    produced.** A 401 from the guard, a 422 from a `FormRequest`, a 404 and a
|    429 all reach the client with the headers, which is where a client is most
|    likely to be handling untrusted input.
|
| ## What is asserted, and what is deliberately not
|
| `Content-Security-Policy` and `Cache-Control: no-store` are asserted on
| `/api/*` only. The SPA shell in `routes/web.php` is a *document*, and a
| `default-src 'none'` policy on it would break the application, so the policy is
| scoped to the JSON surface where it is inert and correct. `Strict-Transport-
| Security` is asserted only for a request the framework considers secure, because
| sending it over plain HTTP teaches a browser nothing and would make the local
| `php artisan serve` run misleading.
|
*/

// ------------------------------------------------------------------ helpers

function headerTestPhone(): string
{
    return '081277776666';
}

function headerTestPassword(): string
{
    return 'kata-sandi-yang-kuat-123';
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function headerRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'nama_lengkap' => 'Dewi Lestari',
        'no_telepon' => headerTestPhone(),
        'email' => 'dewi.lestari@example.test',
        'password' => headerTestPassword(),
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1988-07-21',
        'tempat_lahir' => 'Bandung',
        'alamat_lengkap' => 'Jl. Asia Afrika No. 12, Bandung, Jawa Barat 40111',
        'bahasa' => 'id',
        'persetujuan_syarat_ketentuan' => true,
        'persetujuan_kebijakan_privasi' => true,
    ], $overrides);
}

/** The `FakeOtpSender` bound in `beforeEach`. */
function headerSender(): FakeOtpSender
{
    return app(OtpSender::class);
}

/**
 * Register, verify, and return a REAL Sanctum access token.
 *
 * `$user->createToken()` is the token the login flow issues; `Sanctum::actingAs()`
 * is deliberately not used, because it installs a `TransientToken` and the point
 * of this file is to prove the bearer path a mobile client actually walks.
 */
function headerBearerToken(): string
{
    test()->postJson('/api/v1/auth/sign-up', headerRegisterPayload())->assertCreated();

    $verified = test()->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => headerTestPhone(),
        'kode' => (string) headerSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON),
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ]);

    $verified->assertOk();

    return (string) $verified->json('data.token.access_token');
}

/**
 * The header names this application promises, with the exact values.
 *
 * Returned as a map so a test can assert the whole set in one comparison, which
 * is what makes a *removed* header fail rather than a *changed* one.
 *
 * @return array<string, string>
 */
function headerExpectations(): array
{
    return [
        'x-content-type-options' => 'nosniff',
        'x-frame-options' => 'DENY',
        'referrer-policy' => 'no-referrer',
        'x-permitted-cross-domain-policies' => 'none',
        'cross-origin-opener-policy' => 'same-origin',
    ];
}

/**
 * Assert every promised header is present with its promised value.
 *
 * Reads the bag rather than `$response->headers->get()` so the assertion is
 * case-insensitive on the name and exact on the value, the way HTTP header names
 * actually behave.
 */
function assertSecurityHeaders(TestResponse $response): TestResponse
{
    $bag = [];

    foreach ($response->headers->all() as $name => $values) {
        $bag[strtolower((string) $name)] = implode(', ', array_map(strval(...), (array) $values));
    }

    foreach (headerExpectations() as $name => $value) {
        expect(array_key_exists($name, $bag))->toBeTrue(sprintf('Response is missing the [%s] header.', $name))
            ->and($bag[$name] ?? null)->toBe($value);
    }

    return $response;
}

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->app->instance(OtpSender::class, new FakeOtpSender);
});

// =====================================================================
// The API surface
// =====================================================================

test('an anonymous API response carries every promised header, and the body is untouched', function (): void {
    // `GET /referensi/provinsi` is the login screen's province picker: it is
    // reachable with no token by design (success criterion 8), so it is the
    // cheapest proof that the headers are added to the *unauthenticated* surface
    // and not only to responses a controller built.
    $response = $this->getJson('/api/v1/referensi/provinsi');

    $response->assertOk();
    assertSecurityHeaders($response);

    expect($response->json('success'))->toBeTrue()
        ->and($response->json('data'))->toBeArray()
        ->and($response->headers->get('Content-Type'))->toBe('application/json');

    // The API surface is also told not to be cached: `POST /auth/otp/verify`
    // answers with a token pair, and a shared cache that kept it would be a
    // credential leak through the infrastructure rather than through the code.
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('an AUTHENTICATED request still succeeds, byte for byte, with the headers applied', function (): void {
    $token = headerBearerToken();

    $authenticated = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me');

    $authenticated->assertOk();
    assertSecurityHeaders($authenticated);

    // A second, independent call with no token at all. If the headers had broken
    // the guard, these two would not be one 200 and one 401.
    //
    // Both lines are load-bearing. `flushHeaders()` clears the `Authorization`
    // default that `withHeader()` set, and `forgetGuards()` drops the user the
    // Sanctum guard already resolved: the guard is a container singleton for the
    // whole test, so without this the "anonymous" call is still the first
    // request's authenticated user and answers 200.
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $anonymous = $this->getJson('/api/v1/me');

    $anonymous->assertStatus(401)->assertJsonPath('success', false);
    assertSecurityHeaders($anonymous);

    // The envelope is byte-exact, in both directions, so "the headers did not
    // change the body" is a measurement and not a hope.
    expect($authenticated->getContent())->toBe(
        json_encode(
            [
                'success' => true,
                'data' => $authenticated->json('data'),
                'message' => $authenticated->json('message'),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )
    )->and($anonymous->getContent())->toBe('{"success":false,"message":"Unauthenticated.","errors":{}}');
});

test('the header set is identical on an authenticated response and on the 401 that precedes it', function (): void {
    $token = headerBearerToken();

    $bag = function (TestResponse $response): array {
        $collected = [];

        foreach ($response->headers->all() as $name => $values) {
            $key = strtolower((string) $name);

            if (in_array($key, ['date', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'content-length'], true)) {
                continue;
            }

            $collected[$key] = implode(', ', array_map(strval(...), (array) $values));
        }

        ksort($collected);

        return $collected;
    };

    // `withHeader()` installs a DEFAULT header for every later request in the test,
    // so the two calls below would both carry the same bearer token unless the
    // first is flushed. Getting this wrong makes the "rejected" case a second copy
    // of the "authenticated" one, and the test passes for the wrong reason.
    $rejected = $this->withHeader('Authorization', 'Bearer definitely-not-a-token')->getJson('/api/v1/me');

    $rejected->assertStatus(401);

    $this->flushHeaders();

    $authenticated = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me');

    $authenticated->assertOk();
    // `date` is excluded because the SAPI stamps it per send; the rate-limit
    // headers are excluded because they belong to a limiter, not to this todo, and
    // `/me` is not throttled; `content-length` because the two bodies differ by
    // design. Everything the security-header todo owns must match exactly.
    expect($bag($authenticated))->toBe($bag($rejected))
        ->and($bag($authenticated))->toMatchArray(headerExpectations());
});

test('a validation failure carries the headers and keeps the 422 envelope', function (): void {
    // The password is no longer REQUIRED (`LoginRequest` makes it `sometimes`, because
    // the sign-in screen sends none), so a bare identifier is a well-formed request now.
    // What this test owns is the 422 ENVELOPE and the headers riding on it, so it is
    // given a genuinely malformed password instead - a present field of the wrong shape.
    $response = $this->postJson('/api/v1/auth/login', [
        'no_telepon' => headerTestPhone(),
        'password' => ['bukan', 'string'],
    ]);

    $response->assertStatus(422)->assertJsonPath('success', false);
    assertSecurityHeaders($response);

    // Byte-exact, because this is the body `ApiKernelTest` froze and a header
    // change must not have been allowed to reshape it.
    expect(json_decode((string) $response->getContent(), true))->toHaveKeys(['success', 'message', 'errors'])
        ->and($response->json('message'))->toBe('The given data was invalid.')
        ->and($response->json('errors'))->toHaveKey('password');
});

test('a 404, a 403 and a 429 all carry the headers, because those are the untrusted-input paths', function (): void {
    $notFound = $this->getJson('/api/v1/referensi/tidak-ada');
    $notFound->assertStatus(404)->assertJsonPath('message', 'Resource not found.');
    assertSecurityHeaders($notFound);

    $forbidden = $this->getJson('/api/v1/notifikasi');
    $forbidden->assertStatus(401);

    // Now a real 403: an authenticated patient asking for a doctor's-only surface.
    $token = headerBearerToken();

    $refused = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/obat');

    $refused->assertStatus(403);
    assertSecurityHeaders($refused);

    // And a real 429, so the rate-limited body and the headers are asserted on the
    // same response a client sees when it is being throttled.
    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->postJson('/api/v1/auth/sign-up', headerRegisterPayload());
    }

    $throttled = $this->postJson('/api/v1/auth/sign-up', headerRegisterPayload());

    $throttled->assertStatus(429);
    assertSecurityHeaders($throttled);
});

test('the token-bearing response is marked uncacheable, and nothing rewrites its body', function (): void {
    $this->postJson('/api/v1/auth/sign-up', headerRegisterPayload())->assertCreated();

    $verified = $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => headerTestPhone(),
        'kode' => (string) headerSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON),
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ]);

    $verified->assertOk()->assertJsonPath('data.token.token_type', 'Bearer');
    assertSecurityHeaders($verified);

    expect($verified->headers->get('Cache-Control'))->toContain('no-store')
        ->and($verified->headers->get('Pragma'))->toBe('no-cache');
});

// =====================================================================
// The non-API surface
// =====================================================================

test('the SPA shell gets the three browser-facing headers but NOT the API-only policy', function (): void {
    $shell = $this->get('/dashboard/apa-saja');

    // A 503 while `web/dist` is unbuilt, a 200 once it exists. Both are a
    // *document*, and the assertion is about the headers either way.
    expect($shell->getStatusCode())->toBeIn([200, 503]);

    assertSecurityHeaders($shell);

    // `default-src 'none'` on an HTML document would break the application this
    // repository serves, so the policy is scoped to `/api/*`. Asserting its
    // ABSENCE here is what stops the next executor "fixing" the scope.
    expect($shell->headers->get('Content-Security-Policy'))->toBeNull();
});

test('the API-only policy is on the JSON surface', function (): void {
    $api = $this->getJson('/api/v1/referensi/provinsi');

    $api->assertOk();

    $policy = (string) $api->headers->get('Content-Security-Policy');

    expect($policy)->toContain("default-src 'none'")
        ->and($policy)->toContain("frame-ancestors 'none'")
        ->and($policy)->toContain("base-uri 'none'")
        ->and($policy)->toContain("form-action 'none'");
});

test('a plain-HTTP request is NOT given HSTS, because a header over plain HTTP teaches a browser nothing', function (): void {
    $response = $this->getJson('/api/v1/referensi/provinsi');

    $response->assertOk();

    expect($response->headers->get('Strict-Transport-Security'))->toBeNull(
        'HSTS is only honoured over HTTPS. Sending it over plain HTTP is not a weaker version of the policy, it '
        .'is a different one, and it would make the plain-HTTP `php artisan serve` run look like it had a policy.'
    );

    // The same request, marked as having arrived over TLS, must carry it.
    //
    // The mark is the URL scheme, NOT `serverVariables['HTTPS']`: Symfony's
    // `Request::create()` derives `HTTPS` from the URI and `unset()`s the server
    // var for any non-`https` URI, so faking the var is silently discarded. A
    // full `https://` URL is what makes `isSecure()` true, which is also the
    // only honest way to simulate TLS arrival in this harness.
    $secure = $this->getJson('https://localhost/api/v1/referensi/provinsi');

    $secure->assertOk();

    expect((string) $secure->headers->get('Strict-Transport-Security'))
        ->toContain('max-age=63072000')
        ->and((string) $secure->headers->get('Strict-Transport-Security'))->toContain('includeSubDomains');
});

test('the headers never change a response status, which is the failure mode that matters', function (): void {
    // Every one of these is a DIFFERENT layer's answer, and all of them must come
    // back with the header set intact. A listener that threw would surface as a
    // 500 here, and a listener that replaced the response object would surface as
    // a wrong body.
    $cases = [
        ['GET', '/api/v1/referensi/provinsi', 200],
        ['GET', '/api/v1/referensi/enums', 200],
        ['GET', '/api/v1/me', 401],
        ['GET', '/api/v1/pasien/profil', 401],
        ['GET', '/api/v1/tidak-ada-sama-sekali', 404],
        ['POST', '/api/v1/auth/login', 422],
    ];

    foreach ($cases as [$method, $uri, $expected]) {
        $response = $method === 'GET' ? $this->getJson($uri) : $this->postJson($uri, []);

        expect($response->status())->toBe($expected, sprintf('%s %s', $method, $uri));

        assertSecurityHeaders($response);

        expect($response->json('success'))->toBe($expected < 400);
    }
});
