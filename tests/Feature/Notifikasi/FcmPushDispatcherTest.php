<?php

declare(strict_types=1);

use App\Services\Notifikasi\FcmPushDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| F-005 - the FCM v1 push dispatcher
|--------------------------------------------------------------------------
|
| `FcmPushDispatcher` sits behind `PushDispatcher`, which `NotificationService`
| calls once per active device from inside the service that raised the
| notification - inside a booking, a payment settlement, a prescription
| verification. So every failure path here must log and return: a push that threw
| would turn a delivered booking into a 500.
|
| These tests fake both Google calls - the OAuth token exchange and
| `messages:send` - and generate a throwaway RSA key so the JWT signing path is
| exercised for real without a Firebase project. The three properties asserted are
| the ones that matter: the request shape FCM v1 requires, the retirement of a
| dead token, and the refusal to throw when the credential is unusable.
|
*/

// ------------------------------------------------------------------ helpers

/** A `users` row, for the `user_devices` foreign key. */
function fcmUser(): int
{
    return (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => 'Pengguna FCM',
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => 'pasien',
        'status' => 'aktif',
    ]);
}

/** A `user_devices` row carrying `$token`. */
function fcmDevice(int $userId, string $token): int
{
    return (int) DB::table('user_devices')->insertGetId([
        'user_id' => $userId,
        'device_id' => 'uji-'.Str::lower(Str::random(8)),
        'platform' => 'android',
        'fcm_token' => $token,
        'aktif' => 1,
    ]);
}

/**
 * A THROWAWAY 2048-bit RSA key, used only to exercise the JWT signing path.
 *
 * It is generated once for this suite, has no privileges, is registered with no
 * Google project, and is not a credential: the real key is a service-account JSON
 * that lives outside the repository. It is embedded rather than generated per run
 * because `openssl_pkey_new()` needs an `openssl.cnf` that some PHP builds (this
 * one) do not ship, and a test that only passes where OpenSSL is configured is a
 * test that fails in CI for a reason unrelated to the code.
 */
function fcmPrivateKey(): string
{
    return <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQC1xciVGWQwIdK8
ozvU8m7OOSU4j1oSTpD1t74XgRTnsvHF/ZcQ/UL/AGdEwqdPnxKGawbP0Y0haKda
hDR31BZ7La4oGgCT64gQVFCX6Kv3CjXIaDrFJYjDAD0HavjL+sABSIoTaI0cZPOj
VXfRYfm9I89RfjmKWhe2SG3XlOwW4IIEFczEa30PeTIiE1O0SPYiuuMD32l772WQ
7+H9D4bpZFntPbHxuIJ6v7rt2joTh4yv/CPjjuLH6OaT3KFEfTSHAdWvl3J1MjBX
pEOVedC/LawEPu+f6h4mHQXosHpNy4u2tcrh50AjHMPcrYSOXoAhE1EiSOQf/Tt+
fYuxcMbPAgMBAAECgf8ThquFWLTqdresi9xhg6ljfcAB02RZkxb/Tj2dSMB2I0LV
geg4avxHaEOvnzlZ1DypM9YHfPssG5Hep1T7ikzf7ohGxoRJX4O+PVBrARN39iDQ
5mFpzB5k1CG6KkOPpd/6Q1CRxSljY3TIluXIGD47kx0yI90Kf6MMcbta7SRvUH/G
sHLuOCZjfnjtw6t8zepyO1xc/jmM1PTQcSV9hYUZ0gZWG3nmIDRHCP3o2GHBQrnr
f0NObMIJZ22Ub2nHBI/lQoN32DTKHbm0oqydQFA7KXnmNei0B5YO0X9QMNGrkwDA
PI66LuVof0vBXmpWynm7/TmgBQs470y0UfyyWzECgYEA+uuYGgZmBp++RPXlGxFL
lQtmChbWQayfEuNmSd5hSSMpD2+g8mwSSbAH6398CSZ1i6lCRzmBjAo9ing5Rq6c
DhlIM9B1qQGi78TcsLhJvZM4vNG+aTTWHCtaS5o+pfrkZS0Z5TakBt0ufHOQueav
+kgxdDfZud4q8x9K2ZoVRbECgYEAuXPUChA8kVvFh33CP5nrqTsxaFTL06A9pRmJ
9ddMZoaN50kuyMXdHcPV4pB3Z9kgJJTwA7rsKI2duVlrkHXwm5lGHWwW2tJN8xsO
gpNomlFsBeZKdJox5TzQLXHuRVPliekgAe7XBTj1sB0bM8ZX0BW4WYZo8CYX5VTF
29L/dH8CgYEAzLZBVZo6ytSxCtoCnN6zZ1nxWfHfw5zt+x8DaOjBPkYtTaapDJ8X
HH89Nui/bUevRs5EgI9uHa6NHtePAxeZwfbmbT95fvUS1cMquhLgNoZSF9qXGoY8
2KaV+HBTBF94zwLIybSWhfMV5fry7HhEwlD75/FY9MdJCylQi8+l40ECgYEAuBPD
hZWmOPYptBpc5DqI0zrBCPkaNF4asjUOXmotGJs4YTmf6YOUvkWzmKajDv5SzFzV
fC5e2MXwp5idjw+yGxBhqzSt3tferY7OsUdWDc8B9T0n7GNdYVginuk2g7QJ5AD1
V1XWMgXewMezR4n5gb79dEdZA/4bu/OQTWk7TUsCgYBDJ72HkTzdieT3tn7MrFgP
ZXmmdOSTbSbNjZJ8/1QJZZByy6ESvP0On9xNgbahfdnAKdDo4r/XK3cZifswJVDQ
+VJ5YxCjSCe+YteYJQ0i7ThvJWHB3p87PK7JrtFAIUxTjJ4KrHH5Q7uZ/XpPgyK2
1T3SxdfQgWu327NoeaQY/Q==
-----END PRIVATE KEY-----
PEM;
}

/** A service-account JSON on disk, built around {@see fcmPrivateKey()}. */
function fcmKredensial(): string
{
    static $path = null;

    if ($path !== null) {
        return $path;
    }

    $path = tempnam(sys_get_temp_dir(), 'fcm').'.json';

    file_put_contents($path, (string) json_encode([
        'client_email' => 'svc@uji.iam.gserviceaccount.com',
        'private_key' => fcmPrivateKey(),
        'project_id' => 'proyek-uji',
    ]));

    return $path;
}

// ------------------------------------------------------------------- tests

test('mengirim pesan FCM v1 dengan bearer token dan bentuk message yang benar', function (): void {
    config([
        'push.firebase.credentials' => fcmKredensial(),
        'push.firebase.project_id' => '',
        'push.firebase.token_uri' => 'https://oauth2.googleapis.test/token',
        'push.firebase.endpoint' => 'https://fcm.googleapis.test/v1',
    ]);

    Http::fake([
        'oauth2.googleapis.test/*' => Http::response(['access_token' => 'AKSES-UJI'], 200),
        'fcm.googleapis.test/*' => Http::response(['name' => 'projects/proyek-uji/messages/1'], 200),
    ]);

    app(FcmPushDispatcher::class)->kirim('TOKEN-HIDUP', [
        'judul' => 'Resep siap',
        'isi' => 'Resep Anda sudah diverifikasi.',
        'tipe' => 'resep',
        'tautan' => '/resep/9',
        'notifikasi_id' => 7,
    ]);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/projects/proyek-uji/messages:send')
        && $request->hasHeader('Authorization', 'Bearer AKSES-UJI')
        && $request['message']['token'] === 'TOKEN-HIDUP'
        && $request['message']['notification']['title'] === 'Resep siap'
        && $request['message']['data']['notifikasi_id'] === '7');
});

test('token yang UNREGISTERED menonaktifkan perangkat dan tidak melempar', function (): void {
    $user = fcmUser();
    $device = fcmDevice($user, 'TOKEN-MATI');

    config([
        'push.firebase.credentials' => fcmKredensial(),
        'push.firebase.token_uri' => 'https://oauth2.googleapis.test/token',
        'push.firebase.endpoint' => 'https://fcm.googleapis.test/v1',
    ]);

    Http::fake([
        'oauth2.googleapis.test/*' => Http::response(['access_token' => 'AKSES-UJI'], 200),
        'fcm.googleapis.test/*' => Http::response([
            'error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]],
        ], 404),
    ]);

    Log::spy();

    app(FcmPushDispatcher::class)->kirim('TOKEN-MATI', ['judul' => 'J', 'isi' => 'I', 'notifikasi_id' => 1]);

    expect((int) DB::table('user_devices')->where('id', $device)->value('aktif'))->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => $message === 'notifikasi.push.fcm_token_mati');
});

test('kredensial yang tidak terbaca dicatat sebagai error dan tidak melempar', function (): void {
    config(['push.firebase.credentials' => '/tidak/ada/kredensial.json']);

    Log::spy();
    Http::fake();

    // The caller is `NotificationService::dorong()`, inside a booking or a payment
    // settlement: this must return, not throw.
    app(FcmPushDispatcher::class)->kirim('TOKEN-APA-SAJA', ['judul' => 'J', 'isi' => 'I']);

    Http::assertNothingSent();
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => $message === 'notifikasi.push.fcm_gagal');
});
