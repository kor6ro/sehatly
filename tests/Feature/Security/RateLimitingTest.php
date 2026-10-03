<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserOtp;
use App\Providers\AppServiceProvider;
use App\Services\Auth\OtpSender;
use App\Services\Auth\OtpService;
use App\Support\ApiResponse;
use Database\Seeders\RbacSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeOtpSender;

/*
|--------------------------------------------------------------------------
| Todo 52 -- named rate limiters, the OTP burn, and the indistinguishable login
|--------------------------------------------------------------------------
|
| Three properties are asserted here, and each is asserted at the level that can
| actually fail rather than the level that merely reads well:
|
| **1. An unknown identifier and a wrong password are the same response.** Not
| "the same message" -- the same STATUS, the same BODY BYTES, the same SHA-256 and
| the same header set. `AuthFlowTest` already compares two bodies with `toBe()`;
| this file adds the status comparison, the header comparison, and a *sensitivity
| control* that proves the comparison would notice a single changed word, because
| a comparison nobody has falsified is not evidence.
|
| **2. Every limiter this application names is registered, with the ceiling and
| the window it claims.** The table is read back out of the running `RateLimiter`
| through the same reflection `OpenApiDocumentBuilder::limitFor()` uses, so a
| number in `AppServiceProvider` and a number in this table cannot drift.
|
| **3. A `429` carries a real `Retry-After`.** The framework's throttle exception
| does carry that header, but this application's `bootstrap/app.php` renders every
| `api/*` exception into the project envelope, and a fresh `JsonResponse` has no
| headers. So the 429 the client actually receives either has `Retry-After` or it
| does not, and only a real request can say which.
|
| ## Why the probe routes below still exist after F-002
|
| F-002 mounted seven of the ten limiters that had no route, so the probe routes
| are no longer the only place a limiter is exercised - and this file's older
| paragraph claiming "eight of the limiters ... are not mounted by any route" was
| true when it was written and is not any more. The probes are kept because they
| drive ONE limiter at a time with a bucket of its own, which is what lets this
| file assert a ceiling and a window per limiter without paying for a route's
| other guards - and because a route registered inside a test process cannot reach
| `docs/openapi.yaml`, which a separate console run generates. What the probe
| cannot prove is that the production route mounts the limiter; that half is
| `tests/Feature/Security/RouteThrottlingTest.php`, which reads the mounted set out
| of the route table and drives real routes to a 429. The three limiters F-002 left
| unmounted (`otp-kirim`, `otp-kirim-jam`, `auth-register`) are asserted UNMOUNTED
| there, because mounting them moves a documented ceiling rather than adding wiring.
|
| ## The helpers are local, not shared with `AuthFlowTest`
|
| `AuthFlowTest` declares `authRegisterPayload()` and friends as global functions.
| Depending on them from here would couple this suite to that file's name and load
| order for no gain, so the helpers this file needs are declared here under a
| `rate` prefix.
|
| ## Every non-ASCII string in this file is a byte-level claim
|
| The Indonesian message the first test asserts is read out of a live response by
| {@see rateLoginFailureMessage()}, not transcribed. A one-token typo in a
| hand-typed constant would make this file assert a message the application does
| not send, which is the same defect class this todo exists to catch.
|
*/

// ------------------------------------------------------------------ helpers

/**
 * The phone number every payload in this file uses, so a test that changes one
 * field knows the identifier itself never moved between the two sides of a
 * comparison.
 */
function rateTestPhone(): string
{
    return '081298765432';
}

/** The password that belongs to {@see rateTestPhone()}. */
function rateTestPassword(): string
{
    return 'kata-sandi-yang-kuat-123';
}

/**
 * A complete, valid `POST /auth/sign-up` body with overrides merged in.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rateRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'nama_lengkap' => 'Siti Rahayu',
        'no_telepon' => rateTestPhone(),
        'email' => 'siti.rahayu@example.test',
        'password' => rateTestPassword(),
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1992-11-03',
        'tempat_lahir' => 'Surabaya',
        'alamat_lengkap' => 'Jl. Pahlawan No. 9, Surabaya, Jawa Timur 60171',
        'bahasa' => 'id',
        // F01 decision #1: the two mandatory PDP consents are required by
        // `RegisterRequest`, so every registration payload in this file carries
        // them or the 429 under test would never be reached.
        'persetujuan_syarat_ketentuan' => true,
        'persetujuan_kebijakan_privasi' => true,
    ], $overrides);
}

/** The `FakeOtpSender` bound in `beforeEach`, read back out of the container. */
function rateSender(): FakeOtpSender
{
    return app(OtpSender::class);
}

/**
 * Register a patient and verify the registration OTP, returning the row.
 *
 * A real two-endpoint HTTP walk rather than a factory: the property under test is
 * what the *endpoints* do, and a hand-made fixture would not include the limiter's
 * own effect on the verify call.
 */
function rateRegisterVerified(): User
{
    test()->postJson('/api/v1/auth/sign-up', rateRegisterPayload())->assertCreated();

    test()->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => rateTestPhone(),
        'kode' => (string) rateSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON),
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ])->assertOk();

    return User::query()->where('no_telepon', rateTestPhone())->firstOrFail();
}

/**
 * Log in with the correct password, which mints a `login` OTP and no token.
 *
 * The two-step shape is load-bearing: `POST /auth/login` returns no token pair, so
 * a test that expected one here would be asserting an endpoint that does not
 * exist. The token arrives from `POST /auth/otp/verify`.
 */
function rateLoginMintsCode(): UserOtp
{
    $response = test()->postJson('/api/v1/auth/login', [
        'no_telepon' => rateTestPhone(),
        'password' => rateTestPassword(),
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    expect($response->json('data.token'))->toBeNull('`POST /auth/login` must not return a token.');

    return UserOtp::query()
        ->where('tujuan', OtpService::TUJUAN_LOGIN)
        ->orderByDesc('id')
        ->firstOrFail();
}

/**
 * The exact `message` string the running application sends for a failed login,
 * read out of a live response rather than transcribed.
 *
 * Reading it from the code under test is what makes the byte comparison in this
 * file falsifiable: a one-token typo in a hand-typed constant would turn the
 * strongest assertion here into one that can never fail.
 */
function rateLoginFailureMessage(): string
{
    $body = test()->postJson('/api/v1/auth/login', [
        'no_telepon' => '089999999997',
        'password' => rateTestPassword(),
    ])->assertStatus(401)->json('message');

    expect($body)->toBeString()->and($body)->not->toBe('');

    return (string) $body;
}

/**
 * Mount a throwaway route throttled by `$limiter`, and return its path.
 *
 * The point is to drive the *named* limiter through the framework's own
 * `ThrottleRequests` middleware rather than calling the registered closure by
 * hand: the closure proves the numbers, the middleware proves the refusal, and
 * only the middleware proves the `Retry-After` header.
 *
 * ## Why the probe is mounted under `/api/v1` and not at the root
 *
 * `routes/web.php:93` registers `Route::any('/{any?}')` -- the SPA shell -- with
 * the constraint `^(?!api|broadcasting|sanctum|up|build|storage|assets).*$`. That
 * route is registered while the application boots, so it is already in the route
 * collection when a test adds its own, and it matches first.
 *
 * A probe at `/__rate-limiter-probe/{limiter}` therefore never ran: every request
 * was answered by the shell (a `BinaryFileResponse`, status 200) and the limiter
 * was never invoked. The suite read that as "the limiter returned 200 instead of
 * 429" on all nine datasets, which is a failure that looks like a rate-limiting
 * bug and is actually a routing one.
 *
 * `api` is the first alternative in that lookahead precisely so this tree stays
 * reachable, and it is the prefix the real throttled routes already use.
 */
function rateLimiterProbe(string $limiter): string
{
    $uri = '/api/v1/__rate-limiter-probe/'.$limiter;

    Route::post($uri, fn (): JsonResponse => ApiResponse::success(['probe' => true], 'ok'))
        ->middleware('throttle:'.$limiter);

    return $uri;
}

/**
 * Trust `X-Forwarded-For`, so a test can present a different source address.
 *
 * `config/trustedproxy.php` does not exist in this application and
 * `TrustProxies` reads `config('trustedproxy.proxies')` at request time, so without
 * this a forwarded address below would be silently ignored and the test would
 * prove nothing. Every test that sets one goes through this helper, and one of
 * them additionally proves the header took effect.
 */
function rateTrustForwardedFor(): void
{
    Config::set('trustedproxy.proxies', '*');
}

/**
 * The sorted `name => value` pairs of a response, minus `date`.
 *
 * `Date` is the only header legitimately allowed to differ between two responses
 * to two different requests: it is stamped by the SAPI at send time and says
 * nothing about which account exists. Every other header -- `Content-Type`,
 * `Content-Length`, `Cache-Control`, `X-RateLimit-*` -- is something a client can
 * branch on, so all of them are compared.
 *
 * @return array<string, list<string>>
 */
function rateHeaderBag(TestResponse $response): array
{
    $bag = [];

    foreach ($response->headers->all() as $name => $values) {
        if (strtolower((string) $name) === 'date') {
            continue;
        }

        $bag[strtolower((string) $name)] = array_values((array) $values);
    }

    ksort($bag);

    return $bag;
}

/**
 * The closure `AppServiceProvider` registered under `$name`, read the way the
 * OpenAPI builder reads it.
 *
 * Reflection is not a shortcut here, it is the accessor the contract generator
 * uses, so a limiter this file can see is exactly a limiter the published
 * document can see.
 */
function rateRegisteredLimiter(string $name): callable
{
    $limiter = app(RateLimiter::class);

    $property = (new ReflectionObject($limiter))->getProperty('limiters');

    $property->setAccessible(true);

    /** @var array<string, callable> $all */
    $all = $property->getValue($limiter);

    expect(array_key_exists($name, $all))->toBeTrue(sprintf(
        'RateLimiter has no [%s] limiter. Registered: [%s].',
        $name,
        implode(', ', array_keys($all))
    ));

    return $all[$name];
}

/**
 * Call a registered limiter with a synthetic request, exactly as
 * `OpenApiDocumentBuilder::limitFor()` does, and return the `Limit` it produced.
 *
 * A synthetic request carries no route, so a limiter that keys on a route
 * parameter -- `webhook-payment` does -- is driven with an explicitly bound one
 * here rather than being special-cased at each call site.
 */
function rateLimitFor(string $name, array $input = [], ?string $routeParameter = null, string $ip = '203.0.113.7'): Limit
{
    $request = Request::create('/api/v1/', 'POST', $input);
    $request->server->set('REMOTE_ADDR', $ip);

    if ($routeParameter !== null) {
        $route = new RoutingRoute(['POST'], '/api/v1/webhook/payment/{gateway}', fn (): JsonResponse => ApiResponse::success());

        $route->bind($request);
        $request->setRouteResolver(fn () => $route->setParameter('gateway', $routeParameter));
    }

    $limit = rateRegisteredLimiter($name)($request);

    expect($limit)->toBeInstanceOf(Limit::class, sprintf(
        'The [%s] limiter must return exactly one Limit. The OpenAPI builder reads maxAttempts off whatever '
        .'comes back, so a limiter returning an array is a silent null in the published contract rather than '
        .'an error.',
        $name
    ));

    return $limit;
}

beforeEach(function (): void {
    // Same reason as `AuthFlowTest`: `register()` resolves the `pasien` role name
    // through `RbacCatalog`, which throws when the catalogue row is missing.
    $this->seed(RbacSeeder::class);

    $this->app->instance(OtpSender::class, new FakeOtpSender);
});

// =====================================================================
// 1. The login failure is indistinguishable, at byte level
// =====================================================================

test('an unknown identifier and a wrong password produce the same status, the same bytes and the same headers', function (): void {
    rateRegisterVerified();

    $unknown = $this->postJson('/api/v1/auth/login', [
        'no_telepon' => '089999999999',
        'password' => rateTestPassword(),
    ]);

    $wrong = $this->postJson('/api/v1/auth/login', [
        'no_telepon' => rateTestPhone(),
        'password' => 'kata-sandi-yang-salah',
    ]);

    expect($unknown->getStatusCode())->toBe(401)
        ->and($wrong->getStatusCode())->toBe($unknown->getStatusCode());

    // The body, compared as bytes. `toBe()` on two strings is `===`, which is a
    // byte comparison -- but the length and the digest are asserted too, so a
    // failure says *how* the two differ and not only that they do.
    expect(strlen($wrong->getContent()))->toBe(strlen($unknown->getContent()))
        ->and($wrong->getContent())->toBe($unknown->getContent())
        ->and(hash('sha256', $wrong->getContent()))
        ->toBe(hash('sha256', $unknown->getContent()));

    // The headers, which a body-only comparison would miss. A 401 that carried
    // `X-Account-Exists: 0` on one side only would be an oracle wearing a header.
    expect(rateHeaderBag($wrong))->toBe(rateHeaderBag($unknown))
        ->and(rateHeaderBag($wrong))->toHaveKey('content-type')
        ->and(rateHeaderBag($wrong)['content-type'])->toBe(['application/json']);

    // And the two sides are not vacuously equal: the body really does carry the
    // message a client keys off, and neither side echoes the identifier.
    $message = rateLoginFailureMessage();

    expect($unknown->json('message'))->toBe($message)
        ->and($message)->toContain('salah')
        ->and($unknown->json('errors'))->toBe([])
        ->and($unknown->getContent())->not->toContain(rateTestPhone())
        ->and($wrong->getContent())->not->toContain(rateTestPhone());
});

test('the byte comparison is sensitive to ONE changed word, which is the whole point', function (): void {
    rateRegisterVerified();

    $real = $this->postJson('/api/v1/auth/login', [
        'no_telepon' => rateTestPhone(),
        'password' => 'kata-sandi-yang-salah',
    ])->getContent();

    $message = rateLoginFailureMessage();

    // The mutation a real regression looks like: a developer "improving" the
    // message so it says the number is not registered. This is the single most
    // common way an enumeration oracle is introduced by accident, and it is a
    // change of one clause in one sentence.
    $mutated = str_replace($message, 'Nomor telepon tidak terdaftar.', $real);

    // The mutation is real -- the replacement actually happened...
    expect($mutated)->not->toBe($real)
        ->and($mutated)->not->toContain($message);

    // ...and the comparison this file relies on catches it. Had it not, every other
    // test here would be proving nothing.
    expect(hash('sha256', $mutated))->not->toBe(hash('sha256', $real))
        ->and($mutated)->not->toBe($real);
});

test('the two login failures cost the same wall time, so an identical body is not the only defence', function (): void {
    rateRegisterVerified();

    // A measurement, and the tolerance is sized for the one variable that matters:
    // `phpunit.xml` pins `BCRYPT_ROUNDS=4`, so a bcrypt verification costs about a
    // millisecond here and the two paths cannot drift apart by more than
    // scheduling noise. The production-rounds figure -- the one that actually
    // decides whether this endpoint is an oracle -- is measured against
    // `php artisan serve` and recorded in `.omo/evidence/task-52-sehatly.md`,
    // because a 4-round number taken in CI is a comforting number that proves
    // nothing about a 12-round deployment.
    //
    // The decoy hash is warmed first, deliberately. `AuthController::passwordMatches()`
    // keeps it in a `private static`, and a process that has already served one
    // unknown-identifier request holds it; `php artisan serve` gives every request
    // its own process, so it does not. That difference is a finding, and this
    // test measures the *warm* shape on purpose so the runtime measurement of the
    // cold shape can be compared against a known baseline.
    $this->postJson('/api/v1/auth/login', [
        'no_telepon' => '089999999998',
        'password' => rateTestPassword(),
    ])->assertStatus(401);

    $measure = function (array $payload): float {
        $best = INF;

        for ($run = 0; $run < 3; $run++) {
            $start = hrtime(true);

            test()->postJson('/api/v1/auth/login', $payload)->assertStatus(401);

            $best = min($best, (hrtime(true) - $start) / 1e6);
        }

        return $best;
    };

    $unknown = $measure(['no_telepon' => '089999999999', 'password' => rateTestPassword()]);
    $wrong = $measure(['no_telepon' => rateTestPhone(), 'password' => 'kata-sandi-yang-salah']);

    expect(abs($unknown - $wrong))->toBeLessThan(
        60.0,
        sprintf(
            'Unknown-identifier login took %.2f ms and wrong-password login took %.2f ms. A gap that large is a '
            .'timing oracle even when the two bodies are byte-identical.',
            $unknown,
            $wrong
        )
    );
});

// =====================================================================
// 2. Every named limiter, with the ceiling and window it claims
// =====================================================================

test('every limiter this application names is registered with its documented ceiling and window', function (): void {
    $expected = [
        // name => [maxAttempts, decaySeconds]
        'auth-login' => [5, 60],
        'auth-login-ip' => [60, 60],
        'auth-otp-send' => [10, 60],
        'auth-otp-resend' => [3, 300],
        'otp-kirim' => [3, 60],
        'otp-kirim-jam' => [10, 3600],
        'auth-otp-verify' => [5, OtpService::TTL_MENIT * 60],
        'auth-register' => [3, 3600],
        'auth-refresh' => [30, 60],
        'booking' => [10, 60],
        'checkout' => [5, 60],
        'webhook-payment' => [60, 60],
        'promo-validasi' => [20, 60],
        'chat' => [60, 60],
        'notifikasi-baca' => [10, 60],
    ];

    $read = [];

    foreach ($expected as $name => [$max, $decay]) {
        $input = match ($name) {
            'auth-login', 'auth-otp-send', 'auth-otp-resend', 'otp-kirim', 'otp-kirim-jam' => ['no_telepon' => rateTestPhone()],
            'auth-otp-verify' => ['no_telepon' => rateTestPhone(), 'tujuan' => OtpService::TUJUAN_LOGIN],
            'auth-refresh' => ['refresh_token' => str_repeat('a', 64)],
            default => [],
        };

        $limit = rateLimitFor($name, $input, $name === 'webhook-payment' ? 'midtrans' : null);

        $read[$name] = [(int) $limit->maxAttempts, (int) $limit->decaySeconds];

        expect((int) $limit->maxAttempts)->toBe($max, sprintf('Limiter [%s] has the wrong ceiling.', $name))
            ->and((int) $limit->decaySeconds)->toBe($decay, sprintf('Limiter [%s] has the wrong window.', $name));
    }

    expect($read)->toBe($expected);
});

test('the login limiter keys on the identifier ALONE, and a nameless request still gets its own bucket', function (): void {
    $keyFor = function (array $input, string $ip = '198.51.100.9'): string {
        $request = Request::create('/api/v1/auth/login', 'POST', $input);
        $request->server->set('REMOTE_ADDR', $ip);

        return (string) rateRegisteredLimiter('auth-login')($request)->key;
    };

    $fromThisHost = $keyFor(['no_telepon' => rateTestPhone()]);
    $fromAnotherHost = $keyFor(['no_telepon' => rateTestPhone()], '198.51.100.200');

    // Five attempts against ONE account is five attempts, whoever sent them: the
    // per-identifier half is what bounds a distributed guessing run, and a key
    // that folded the caller's address in would hand that attacker one fresh
    // budget per source address.
    expect($fromAnotherHost)->toBe($fromThisHost)
        // A different account is a different budget.
        ->and($keyFor(['no_telepon' => '081200000000']))->not->toBe($fromThisHost)
        // Case variants of an address share one budget, so capitalisation cannot
        // double the ceiling.
        ->and($keyFor(['email' => 'Siti.Rahayu@Example.Test']))
        ->toBe($keyFor(['email' => 'siti.rahayu@example.test']));

    // A request that failed validation names no account at all. It must NOT fall
    // into a single global bucket, or one client could exhaust everybody's.
    $noIdentifier = $keyFor([]);

    expect($noIdentifier)->toContain('198.51.100.9')
        ->and($keyFor([], '198.51.100.200'))->not->toBe($noIdentifier);
});

test('the per-IP login ceiling is a SEPARATE bucket, and it is keyed on the forwarded address', function (): void {
    rateTrustForwardedFor();

    $path = rateLimiterProbe('auth-login-ip');

    for ($attempt = 1; $attempt <= 60; $attempt++) {
        $this->postJson($path)->assertOk();
    }

    // The 61st, from the same host, naming an account nobody has ever used.
    $this->postJson($path, ['no_telepon' => '081299999999'])->assertStatus(429);

    // The control this test needs: the forwarded header is genuinely in effect, so
    // the 429 above was the IP bucket and not some unrelated total. Without this
    // the assertion would also pass with the header ignored.
    $this->withHeaders(['X-Forwarded-For' => '198.51.100.42'])
        ->postJson($path, ['no_telepon' => '081299999998'])
        ->assertOk();
});

test('the 21st promo validation in a minute is refused, with a real Retry-After', function (): void {
    $path = rateLimiterProbe('promo-validasi');

    for ($call = 1; $call <= 20; $call++) {
        $this->postJson($path)->assertOk();
    }

    $refused = $this->postJson($path);

    $refused->assertStatus(429)->assertJsonPath('success', false);

    // The envelope, so a client parses one body shape for every failure.
    expect($refused->json('errors'))->toBe([]);

    // `Retry-After` is in the CORS-safelisted response-header set, so a browser
    // client can read it with nothing exposed by the server.
    $retryAfter = $refused->headers->get('Retry-After');

    expect($retryAfter)->not->toBeNull('A 429 with no Retry-After tells the client nothing about when to return.')
        ->and($retryAfter)->toMatch('/^\d+$/')
        ->and((int) $retryAfter)->toBeGreaterThan(0)
        ->and((int) $retryAfter)->toBeLessThanOrEqual(60);
});

// =====================================================================
// 3. The 429 the mounted routes actually produce
// =====================================================================

test('the sixth login attempt in a minute is refused with 429, the envelope and a real Retry-After', function (): void {
    rateRegisterVerified();

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'no_telepon' => rateTestPhone(),
            'password' => 'kata-sandi-yang-salah',
        ])->assertStatus(401);
    }

    // The header the framework adds to a request that was ALLOWED, so the ceiling
    // is visible before it is reached and not only after it is passed.
    $allowed = $this->postJson('/api/v1/auth/login', [
        'no_telepon' => '081288888888',
        'password' => 'kata-sandi-yang-salah',
    ]);

    expect($allowed->headers->get('X-RateLimit-Limit'))->toBe('5');

    $refused = $this->postJson('/api/v1/auth/login', [
        'no_telepon' => rateTestPhone(),
        'password' => rateTestPassword(),
    ]);

    $refused->assertStatus(429)->assertJsonPath('success', false);

    expect($refused->json('errors'))->toBe([])
        ->and($refused->headers->get('Retry-After'))->not->toBeNull()
        ->and((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and((int) $refused->headers->get('Retry-After'))->toBeLessThanOrEqual(60)
        // A limiter that echoed the identifier would be an oracle of its own.
        ->and($refused->getContent())->not->toContain(rateTestPhone());
});

test('the eleventh registration attempt in a minute is refused with the envelope and a real Retry-After', function (): void {
    $this->postJson('/api/v1/auth/sign-up', rateRegisterPayload())->assertCreated();

    for ($attempt = 1; $attempt <= 9; $attempt++) {
        $this->postJson('/api/v1/auth/sign-up', rateRegisterPayload())->assertStatus(422);
    }

    $refused = $this->postJson('/api/v1/auth/sign-up', rateRegisterPayload());

    $refused->assertStatus(429)->assertJsonPath('success', false);

    expect($refused->headers->get('Retry-After'))->not->toBeNull()
        ->and((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(0);
});

// =====================================================================
// 4. The OTP burn
// =====================================================================

test('a code gets five attempts, and the sixth burns it in the database', function (): void {
    rateRegisterVerified();

    $otp = rateLoginMintsCode();

    $attempt = fn (): TestResponse => $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => rateTestPhone(),
        'kode' => '999999',
        'tujuan' => OtpService::TUJUAN_LOGIN,
    ]);

    for ($n = 1; $n <= 5; $n++) {
        $attempt()->assertStatus(422);
    }

    expect((bool) $otp->fresh()->sudah_dipakai)->toBeFalse(
        'The code must still be usable at five attempts; the burn is on the sixth.'
    );

    $attempt()->assertStatus(429);

    // The burn is a DATABASE write, not a cache flag, so it survives a restarted
    // cache, a second web server and a rewritten client -- which is the only
    // property that makes it a burn rather than a suggestion.
    expect((bool) $otp->fresh()->sudah_dipakai)->toBeTrue(
        'The sixth attempt must set user_otp.sudah_dipakai = 1. A cache-only counter is undone by flushing the '
        .'cache and a client-side counter is undone by the client.'
    );
});

test('the burn is keyed on the ISSUED CODE, so a new address, device or session buys no extra attempt', function (): void {
    rateRegisterVerified();

    $otp = rateLoginMintsCode();

    // The key itself: the same code, presented from two different source
    // addresses, resolves to ONE limiter key. Nothing the caller controls is part
    // of it except the identifier, which is what identifies the code.
    //
    // Read BEFORE the attempts below. `rateLimitFor()` mirrors what
    // `OpenApiDocumentBuilder::limitFor()` does at contract-generation time --
    // it invokes the closure on a fresh bucket and reads the `Limit` back -- and
    // once five attempts have been spent the closure answers with the finished
    // 429 instead, which is the correct behaviour and no `Limit` at all.
    $keyFor = fn (string $ip): string => (string) rateLimitFor(
        'auth-otp-verify',
        ['no_telepon' => rateTestPhone(), 'tujuan' => OtpService::TUJUAN_LOGIN],
        null,
        $ip
    )->key;

    expect($keyFor('198.51.100.5'))->toBe($keyFor('198.51.100.6'))
        ->and($keyFor('198.51.100.5'))->toContain((string) $otp->getKey());

    for ($n = 1; $n <= 5; $n++) {
        $this->postJson('/api/v1/auth/otp/verify', [
            'no_telepon' => rateTestPhone(),
            'kode' => '999999',
            'tujuan' => OtpService::TUJUAN_LOGIN,
        ])->assertStatus(422);
    }

    // And the endpoint agrees: the sixth attempt from a different source address,
    // carrying a different bearer header and naming a different device, is still
    // refused. `rateTrustForwardedFor()` makes the address change real, and
    // `the per-IP login ceiling ...` proves the same header is honoured elsewhere.
    rateTrustForwardedFor();

    $this->withHeaders([
        'X-Forwarded-For' => '198.51.100.77',
        'Authorization' => 'Bearer not-a-real-token',
    ])->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => rateTestPhone(),
        'kode' => '999999',
        'tujuan' => OtpService::TUJUAN_LOGIN,
        'device_id' => 'hp-penyerang-0001',
    ])->assertStatus(429);

    expect((bool) $otp->fresh()->sudah_dipakai)->toBeTrue();
});

test('a patient is not locked out by a burn: a NEW code is a new budget', function (): void {
    rateRegisterVerified();

    $burned = rateLoginMintsCode();

    for ($n = 1; $n <= 6; $n++) {
        $this->postJson('/api/v1/auth/otp/verify', [
            'no_telepon' => rateTestPhone(),
            'kode' => '999999',
            'tujuan' => OtpService::TUJUAN_LOGIN,
        ])->assertStatus($n === 6 ? 429 : 422);
    }

    expect((bool) $burned->fresh()->sudah_dipakai)->toBeTrue();

    // Ask for a new code the way a patient actually would: sign in again. That
    // mints a NEW `user_otp` row, the limiter's key changes with it, and the
    // honest code goes straight through -- so a burn costs a code, not an account.
    $fresh = rateLoginMintsCode();

    expect((int) $fresh->getKey())->toBeGreaterThan((int) $burned->getKey())
        ->and((bool) $fresh->sudah_dipakai)->toBeFalse();

    $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => rateTestPhone(),
        'kode' => (string) rateSender()->lastKodeFor(OtpService::TUJUAN_LOGIN),
        'tujuan' => OtpService::TUJUAN_LOGIN,
    ])->assertOk()->assertJsonPath('data.token.token_type', 'Bearer');
});

test('a code the caller CANNOT re-request is refused but never burned, so it cannot be used to lock a patient out', function (): void {
    // A registration OTP is the one code in this application with no re-request
    // path: `POST /auth/sign-up` is closed to a number that already exists by the
    // `unique:users,no_telepon` rule, and there is no resend endpoint. Burning
    // that code would therefore hand any six anonymous requests a permanent denial
    // of service against a real patient, and a denial of service is a worse
    // outcome than the extra guesses a 300-second limiter already bounds.
    $this->postJson('/api/v1/auth/sign-up', rateRegisterPayload())->assertCreated();

    // Captured before the attempts below, for the same reason as in the burn test:
    // `rateLimitFor()` reads a `Limit` off a fresh bucket, and after six attempts
    // the closure answers with the finished 429.
    $verifyKey = (string) rateLimitFor('auth-otp-verify', [
        'no_telepon' => rateTestPhone(),
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ])->key;

    for ($n = 1; $n <= 6; $n++) {
        $this->postJson('/api/v1/auth/otp/verify', [
            'no_telepon' => rateTestPhone(),
            'kode' => '999999',
            'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
        ])->assertStatus($n === 6 ? 429 : 422);
    }

    $otp = UserOtp::query()
        ->where('tujuan', OtpService::TUJUAN_VERIFIKASI_TELEPON)
        ->orderByDesc('id')
        ->firstOrFail();

    expect((bool) $otp->fresh()->sudah_dipakai)->toBeFalse(
        'A registration code with no resend endpoint must not be burned. The 429 is still enforced from the '
        .'cache; only the irreversible database write is withheld.'
    );

    // The patient can still finish signing up, and the way this is proved is the
    // point of the whole test: the limiter's counter lives in the CACHE, so
    // clearing that one key clears the refusal, while the account's own state --
    // the code row and the `users` row -- is untouched. The limiter is the
    // transient half; the burn would have been the permanent half.
    // `RateLimiter::clear()` counts the same entry `ThrottleRequests` counts, and
    // that entry is `md5($limiterName.$limit->key)`, NOT the raw `Limit::by()` key.
    // Clearing the raw key would silently clear an entry nothing ever writes, the
    // refusal would survive, and this test would pass for the wrong reason.
    app(RateLimiter::class)->clear(md5('auth-otp-verify'.$verifyKey));

    $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => rateTestPhone(),
        'kode' => (string) rateSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON),
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ])->assertOk()->assertJsonPath('data.token.token_type', 'Bearer');
});

test('a second login mints a new row, and the limiter key follows the row rather than the account', function (): void {
    rateRegisterVerified();

    $first = rateLoginMintsCode();

    $second = rateLoginMintsCode();

    expect((int) $second->getKey())->toBeGreaterThan((int) $first->getKey())
        ->and((bool) $first->fresh()->sudah_dipakai)->toBeFalse();

    $keyForCurrentCode = (string) rateLimitFor('auth-otp-verify', [
        'no_telepon' => rateTestPhone(),
        'tujuan' => OtpService::TUJUAN_LOGIN,
    ])->key;

    expect($keyForCurrentCode)->toContain((string) $second->getKey())
        ->and($keyForCurrentCode)->not->toContain('|'.$first->getKey().'|');
});

// =====================================================================
// 5. The limiters whose routes are not mounted yet
// =====================================================================

test('each unmounted limiter really refuses through the framework middleware, at its own ceiling', function (string $limiter, int $ceiling): void {
    $path = rateLimiterProbe($limiter);

    for ($call = 1; $call <= $ceiling; $call++) {
        $this->postJson($path)->assertOk();
    }

    $this->postJson($path)->assertStatus(429);
})->with([
    'auth-register' => ['auth-register', 3],
    'otp-kirim' => ['otp-kirim', 3],
    'otp-kirim-jam' => ['otp-kirim-jam', 10],
    'auth-refresh' => ['auth-refresh', 30],
    'booking' => ['booking', 10],
    'checkout' => ['checkout', 5],
    'promo-validasi' => ['promo-validasi', 20],
    'chat' => ['chat', 60],
    'webhook-payment' => ['webhook-payment', 60],
]);

test('AppServiceProvider is the only place a named limiter is registered', function (): void {
    // Not a tautology. A second `RateLimiter::for()` anywhere else is a second
    // place for a number to be wrong, which is precisely what
    // `OpenApiDocumentBuilder` warns about when it reads the provider -- and the
    // generated contract names `x-ratelimit.limiter` from whatever the route table
    // says, so a limiter defined in two places has no single authority.
    $files = app()->make(Filesystem::class);

    $offenders = [];

    foreach ($files->allFiles(app_path()) as $file) {
        if ($file->getFilename() === 'AppServiceProvider.php') {
            continue;
        }

        if (str_contains($file->getContents(), 'RateLimiter::for(')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);

    // And the provider this file exercises is the class the contract generator
    // reads, so the two can never be different providers.
    expect(class_exists(AppServiceProvider::class))->toBeTrue();
});
