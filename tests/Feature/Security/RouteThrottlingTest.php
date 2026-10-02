<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Auth\TokenService;
use App\Support\Rbac\RoleAssigner;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| F-002 - the limiters that have a route are mounted on a real one
|--------------------------------------------------------------------------
|
| The audit found thirteen limiters registered in `AppServiceProvider` and three
| mounted in `routes/api.php`; a registered limiter protects nobody until a route
| names it. F-002 mounted SEVEN - the ones whose ceiling does not move a documented
| number - and left three unmounted on purpose:
|
| - `otp-kirim` (3/min) and `otp-kirim-jam` (10/hour), both keyed on the
|   identifier, would drop `/auth/login`'s effective ceiling from the plan's 5/min
|   to 3/min and lock a patient out after ten attempts in an hour;
| - `auth-register` (3/hour, client IP) would drop `/auth/register` from 10/min to
|   3/hour per address, which behind carrier-grade NAT is one clinic's egress.
|
| Those three are asserted UNMOUNTED here rather than left to memory: the day
| somebody mounts one, this file says the ceiling decision has not been made.
|
| The mounted half is read back out of `Route::gatherMiddleware()` rather than
| transcribed from `routes/api.php` - a test that restates the list it checks
| proves nothing. The real-route half drives two anonymous surfaces and one
| authenticated one to a `429` and asserts a real `Retry-After`, because the
| kernel's envelope renderer builds a fresh `JsonResponse` and drops headers: the
| header is present only if `AppServiceProvider`'s own refusal put it there.
|
| `RateLimitingTest` drives each limiter's ceiling through a probe route; that file
| cannot prove a production route mounts one, and this file cannot prove a ceiling
| that has no route. The two measurements are deliberately different.
|
| Fixtures are local (prefix `rlt`), for the reason `RateLimitingTest` gives about
| `AuthFlowTest`: depending on another file's global helpers couples this suite to
| that file's load order.
|
*/

// ------------------------------------------------------------------ helpers

/**
 * The middleware the real route for `$method $uri` gathers, or a throw.
 *
 * `gatherMiddleware()` includes the group middleware, which is what makes the
 * `auth:sanctum` and `permission:` layers visible beside the `throttle:` one.
 */
function rltMiddleware(string $method, string $uri): array
{
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
            return $route->gatherMiddleware();
        }
    }

    throw new RuntimeException("Route {$method} /{$uri} is not registered");
}

/** Every limiter name mounted under `api/v1`, sorted and de-duplicated. */
function rltTerpasang(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1'))
        ->flatMap(fn ($route): array => $route->gatherMiddleware())
        ->filter(fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:'))
        ->map(fn (string $middleware): string => substr($middleware, strlen('throttle:')))
        ->unique()
        ->sort()
        ->values()
        ->all();
}

/** A `users` row of a given `tipe`, optionally carrying `$role`. */
function rltUser(string $tipe, string $nama, ?string $role = null): User
{
    $id = (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => $nama,
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => $tipe,
        'status' => 'aktif',
    ]);

    if ($role !== null) {
        app(RoleAssigner::class)->assign($id, $role);
    }

    return User::query()->findOrFail($id);
}

/** A `pasien` row with the three NOT NULL columns the DDL has no default for. */
function rltPasien(int $userId): int
{
    return (int) DB::table('pasien')->insertGetId([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Throttle No. 12, Jakarta',
    ]);
}

/** A `dokter` row eligible for the directory. */
function rltDokter(int $userId): int
{
    return (int) DB::table('dokter')->insertGetId([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-RLT-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'biaya_konsultasi_online' => '175000.00',
        'tersedia_telemedisin' => 1,
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ]);
}

/** A running `konsultasi` row, the shape the chat limiter is keyed on. */
function rltSesi(int $pasienId, int $dokterId): int
{
    return (int) DB::table('konsultasi')->insertGetId([
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'tipe' => 'chat',
        'status' => 'berlangsung',
        'room_id' => (string) Str::uuid(),
        'mulai_at' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
    ]);
}

/** Act as `$user` with a real Sanctum bearer token. */
function rltAs(User $user): mixed
{
    app('auth')->forgetGuards();

    return test()->withToken(
        $user->createToken('route-throttle-test', ['*'], now()->addHour())->plainTextToken,
    );
}

// ------------------------------------------------------------------- tests

test('setiap limiter F-002 menempel pada route yang benar', function (): void {
    $peta = [
        ['POST', 'api/v1/auth/login', 'throttle:auth-login-ip'],
        ['POST', 'api/v1/auth/otp/resend', 'throttle:auth-otp-resend'],
        ['POST', 'api/v1/auth/refresh', 'throttle:auth-refresh'],
        ['POST', 'api/v1/booking', 'throttle:booking'],
        ['POST', 'api/v1/konsultasi/{id}/chat', 'throttle:chat'],
        ['POST', 'api/v1/resep/{id}/checkout', 'throttle:checkout'],
        ['POST', 'api/v1/webhook/payment/{gateway}', 'throttle:webhook-payment'],
        ['POST', 'api/v1/promo/validasi', 'throttle:promo-validasi'],
        ['PUT', 'api/v1/notifikasi/{id}/baca', 'throttle:notifikasi-baca'],
        ['PUT', 'api/v1/notifikasi/baca-semua', 'throttle:notifikasi-baca'],
    ];

    foreach ($peta as [$method, $uri, $limiter]) {
        $middleware = rltMiddleware($method, $uri);

        if (! in_array($limiter, $middleware, true)) {
            $this->fail("{$method} /{$uri} must mount {$limiter}; gathered: ".implode(' | ', $middleware));
        }
    }

    expect($peta)->toHaveCount(10);
});

test('limiter yang terpasang tepat dua belas, dan tiga tetap sengaja unmounted', function (): void {
    $terpasang = rltTerpasang();

    // The inventory with F-002, F-009 and F01 applied: the three that were already
    // mounted, the seven F-002 added, `notifikasi-baca`, and F01's
    // `auth-otp-resend`. A name disappearing is a limiter that stopped being
    // mounted; a name appearing is a limiter nobody recorded here.
    expect($terpasang)->toBe([
        'auth-login',
        'auth-login-ip',
        'auth-otp-resend',
        'auth-otp-send',
        'auth-otp-verify',
        'auth-refresh',
        'booking',
        'chat',
        'checkout',
        'notifikasi-baca',
        'promo-validasi',
        'webhook-payment',
    ]);

    // The three deferred ones. Each moves a DOCUMENTED ceiling, so mounting one is
    // a decision and not a wiring change - this is the tripwire that says so.
    expect($terpasang)->not->toContain('otp-kirim');
    expect($terpasang)->not->toContain('otp-kirim-jam');
    expect($terpasang)->not->toContain('auth-register');
});

test('refresh dibalas 429 dengan Retry-After pada percobaan ke-31', function (): void {
    // One fixed token, so every request lands in ONE bucket: the limiter is keyed
    // on the SHA-256 of the presented refresh token.
    $body = ['refresh_token' => str_repeat('a', TokenService::REFRESH_TOKEN_PANJANG)];

    for ($attempt = 1; $attempt <= 30; $attempt++) {
        $response = $this->postJson('/api/v1/auth/refresh', $body);

        expect($response->getStatusCode())
            ->not->toBe(429, "attempt {$attempt} is inside the declared ceiling and must not be throttled");
    }

    $limited = $this->postJson('/api/v1/auth/refresh', $body);

    $limited->assertStatus(429);
    expect($limited->headers->get('Retry-After'))->not->toBeNull()
        ->and($limited->json('success'))->toBeFalse();
});

test('webhook dibalas 429 dengan Retry-After pada percobaan ke-61', function (): void {
    $body = ['order_id' => 'RLT-1'];
    $headers = ['X-Payment-Signature' => 'tidak-valid'];

    for ($attempt = 1; $attempt <= 60; $attempt++) {
        $response = $this->postJson('/api/v1/webhook/payment/midtrans', $body, $headers);

        expect($response->getStatusCode())
            ->not->toBe(429, "attempt {$attempt} is inside the declared ceiling and must not be throttled");
    }

    $limited = $this->postJson('/api/v1/webhook/payment/midtrans', $body, $headers);

    $limited->assertStatus(429);
    expect($limited->headers->get('Retry-After'))->not->toBeNull()
        ->and($limited->json('success'))->toBeFalse();
});

test('chat dibalas 429 dengan Retry-After pada pesan ke-61 dan pesan ke-61 tidak tersimpan', function (): void {
    $this->seed(RbacSeeder::class);

    $pasienUser = rltUser('pasien', 'Pasien Throttle', 'pasien');
    $pasien = rltPasien($pasienUser->getKey());
    $dokter = rltDokter(rltUser('dokter', 'Dokter Throttle', 'dokter')->getKey());
    $sesi = rltSesi($pasien, $dokter);

    for ($attempt = 1; $attempt <= 60; $attempt++) {
        $response = rltAs($pasienUser)->postJson('/api/v1/konsultasi/'.$sesi.'/chat', [
            'tipe_pesan' => 'teks',
            'isi' => 'Pesan uji '.$attempt,
        ]);

        expect($response->getStatusCode())
            ->not->toBe(429, "message {$attempt} is inside the declared ceiling and must not be throttled");
    }

    $limited = rltAs($pasienUser)->postJson('/api/v1/konsultasi/'.$sesi.'/chat', [
        'tipe_pesan' => 'teks',
        'isi' => 'Pesan ke-61 yang harus ditolak',
    ]);

    $limited->assertStatus(429);
    expect($limited->headers->get('Retry-After'))->not->toBeNull();

    // The refusal happens at the middleware, so the refused message is not written
    // and no audit row for it exists.
    expect(DB::table('konsultasi_chat')->where('konsultasi_id', $sesi)->count())->toBe(60)
        ->and(DB::table('konsultasi_chat')
            ->where('konsultasi_id', $sesi)
            ->where('isi', 'Pesan ke-61 yang harus ditolak')
            ->exists())->toBeFalse();
});
