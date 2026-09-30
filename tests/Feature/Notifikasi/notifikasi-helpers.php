<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Rbac\RoleAssigner;
use App\Support\WaktuIndonesia;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| F3C - shared fixtures for the notification-wiring tests
|--------------------------------------------------------------------------
|
| `require_once`d by the test file, so the file is runnable on its own: the
| alternative is `php artisan test tests/Feature/Notifikasi/OneFile.php`
| reporting a wall of "undefined function" errors, which reads as a broken test
| rather than a missing include. This is the same reason
| `resep-helpers.php` and `payment-helpers.php` exist.
|
| The prefix is `ntf5` because Pest loads every test file into ONE process and
| these are global functions - `rx39`, `rx40`, `po46`, `pay45`, `inv44`,
| `kns`, `bkc`, `aud` and `rmd` are already taken.
|
| ## Nothing here writes a `notifikasi` row
|
| Every fixture in this file is built with the query builder or a bare Eloquent
| save on a table that is NOT in `AuditScope`'s person closure where possible.
| That is not tidiness: the whole point of the file is to count the
| `notifikasi` rows a DOMAIN ACTION produces, and a fixture that wrote one
| would make every count in it a measurement of the fixture.
*/

/**
 * A `users` row, with the role the flow under test needs.
 *
 * `uuid` (:133), `nama_lengkap` (:135), `no_telepon` (:137) and
 * `kata_sandi_hash` (:138) are the NOT NULL columns with no default. The
 * password is a random unusable hash, exactly as `DevFixtureSeeder` does,
 * because no test in this file logs in - it holds a Sanctum token instead.
 */
function ntf5User(string $nama, string $tipe = 'pasien', ?string $role = 'pasien'): User
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

/**
 * A `pasien` row. `jenis_kelamin` (:225), `tanggal_lahir` (:226) and
 * `alamat_lengkap` (:234) are NOT NULL with no default, and the address is
 * the one `PesananObatService` snapshots onto an order.
 *
 * `nik` is deliberately left NULL. See the F3C evidence file: `pasien.nik` is
 * a plaintext `CHAR(16)` with no blind index, which is an OPEN scope
 * violation owned by the orchestrator. A fixture that wrote a 16-digit value
 * here would put a number in the repository that reads as a person's NIK.
 */
function ntf5Pasien(int $userId): int
{
    return (int) DB::table('pasien')->insertGetId([
        'user_id' => $userId,
        'nomor_rm' => 'RM-NTF5-'.Str::upper(Str::random(8)),
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Notifikasi No. 1, Jakarta',
    ]);
}

/**
 * A `dokter` row with NO schedule.
 *
 * `user_id`, `tipe`, `nomor_str` and `str_berlaku_sampai` are the four NOT
 * NULL columns with no default (:411`-`:414`). The absence of a
 * `dokter_jadwal` row is what lets `tipe_layanan = 'chat'` take
 * `BookingService::geometriOtomatis()`'s instant path, so these tests do not
 * depend on a schedule existing.
 */
function ntf5Dokter(int $userId): int
{
    return (int) DB::table('dokter')->insertGetId([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-NTF5-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
        'tersedia_telemedisin' => 1,
        'durasi_default_menit' => 15,
        'biaya_konsultasi_online' => '50000.00',
    ]);
}

/**
 * The test case, already carrying `$user`'s bearer token.
 *
 * `forgetGuards()` first, because the auth manager is a singleton whose guard
 * caches the resolved user - without it the second request in a test would be
 * the first request's account.
 */
function ntf5As(User $user): TestCase
{
    app('auth')->forgetGuards();

    $token = $user->createToken('ntf5', ['*'], now()->addHour())->plainTextToken;

    return test()->withHeader('Authorization', 'Bearer '.$token);
}

/** The clinic's day after tomorrow, as the `Y-m-d` a booking request wants. */
function ntf5Tanggal(): string
{
    return WaktuIndonesia::now()->addDays(2)->format(WaktuIndonesia::FORMAT_TANGGAL);
}

/**
 * The `notifikasi` rows written for `$userId`, oldest first.
 *
 * Read through the query builder rather than the `Notifikasi` model on
 * purpose: the model carries a `casts()` for `payload` and hydrating it is
 * unnecessary for a row count, and the builder cannot fire an
 * `AuditObserver::created()` that would change the very count being measured.
 *
 * @return list<object>
 */
function ntf5Baris(int $userId): array
{
    return DB::table('notifikasi')
        ->where('user_id', $userId)
        ->orderBy('id')
        ->get()
        ->all();
}

/**
 * The single `notifikasi` row for `$userId` of `$tipe`, or null.
 *
 * `toBe(1)` on the count is asserted by the caller, so a second row of the
 * same type is a failure rather than a silently-wrong "first match".
 */
function ntf5Satu(int $userId, string $tipe): ?object
{
    $rows = array_values(array_filter(
        ntf5Baris($userId),
        static fn (object $row): bool => (string) $row->tipe === $tipe,
    ));

    if (count($rows) !== 1) {
        return null;
    }

    return $rows[0];
}

/**
 * The raw webhook body this project's mock gateway speaks, and its signature.
 *
 * The HMAC is computed HERE, in the test, from `config()` - never asked for
 * from the implementation - because the claim under test is "the settlement
 * ran", not "the implementation can sign".
 *
 * @return array{0: string, 1: array<string, string>}
 */
function ntf5Webhook(string $gateway, string $nomorReferensi, string $jumlah, string $status = 'berhasil'): array
{
    $body = (string) json_encode([
        'gateway' => $gateway,
        'nomor_referensi' => $nomorReferensi,
        'status' => $status,
        'jumlah' => $jumlah,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $rahasia = (string) config('services.payment.gateways.'.$gateway.'.webhook_secret');

    return [$body, ['HTTP_X_PAYMENT_SIGNATURE' => hash_hmac('sha256', $body, $rahasia)]];
}

/**
 * POST a signed webhook to `POST /api/v1/webhook/payment/{gateway}`.
 *
 * `call()` with a raw `$content`, because the signature is over the BYTES and
 * `postJson()` would re-encode the array through a second encoder.
 */
function ntf5Kirim(string $gateway, string $raw, array $server): TestResponse
{
    return test()->call(
        'POST',
        '/api/v1/webhook/payment/'.$gateway,
        [],
        [],
        [],
        array_merge($server, ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']),
        $raw,
    );
}

/**
 * A patient account plus its `pasien` row, a doctor account plus its `dokter`
 * row, and a pharmacist account - the three roles the five triggers span.
 *
 * @return array{pasien: User, pasienId: int, dokter: User, dokterId: int, apoteker: User}
 */
function ntf5Dunia(): array
{
    $pasienUser = ntf5User('Pasien Notifikasi', 'pasien', 'pasien');
    $dokterUser = ntf5User('Dokter Notifikasi', 'dokter', 'dokter');

    return [
        'pasien' => $pasienUser,
        'pasienId' => ntf5Pasien((int) $pasienUser->getKey()),
        'dokter' => $dokterUser,
        'dokterId' => ntf5Dokter((int) $dokterUser->getKey()),
        'apoteker' => ntf5User('Apoteker Notifikasi', 'apoteker', 'apoteker'),
    ];
}
