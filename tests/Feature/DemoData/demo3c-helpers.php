<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Auth\OtpSender;
use App\Services\Auth\OtpService;
use App\Support\Rbac\RoleAssigner;
use App\Support\WaktuIndonesia;
use Database\Seeders\ArtikelKategoriSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\IcdSeeder;
use Database\Seeders\LabSeeder;
use Database\Seeders\MasterUmumSeeder;
use Database\Seeders\MetodePembayaranSeeder;
use Database\Seeders\ObatSeeder;
use Database\Seeders\PenjaminSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SpesialisasiSeeder;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeOtpSender;

/*
|--------------------------------------------------------------------------
| F3-02 - shared fixtures for the demo-data test files
|--------------------------------------------------------------------------
|
| `require_once`d by each of them, for the reason `resep-helpers.php` gives:
| a single file must be runnable on its own, or `artisan test <one file>`
| reports a wall of undefined-function errors that reads as a broken test
| rather than a missing include.
|
| The prefix is `demo3c` because Pest loads every test file into ONE process
| and these are global functions - `ntf5`, `rx39`, `rx40`, `po46`, `pay45`,
| `inv44`, `kns`, `bkc`, `aud` and `rmd` are already taken.
|
| ## The seed GROUPS, and why `DatabaseSeeder` is not one of them
|
| `DatabaseSeeder::resetSeededTables()` TRUNCATEs 26 tables with foreign-key
| checking disabled. MySQL treats `TRUNCATE` as DDL and implicitly commits,
| which would commit the `RefreshDatabase` wrapper transaction every Feature
| test runs inside and leave the rest of the suite on a database nobody rolled
| back. So the individual seeders are called in the same order the chain calls
| them, which produces exactly the same rows - every one of them is a plain
| `insert`, and `RbacSeeder` is `upsert`/`insertOrIgnore` besides.
|
| The full `php artisan db:seed` chain IS exercised, twice, against a private
| scratch database; that transcript is in `.omo/evidence/F3C-notifications-and-demo-data.md`.
*/

/**
 * The ONE `master_provinsi` row `DemoDataSeeder` resolves, written with the
 * EXPLICIT `id` the DDL's own INSERT order would have given it.
 *
 * ## Why this exists instead of `MasterWilayahSeeder`, and it is not tidiness
 *
 * `master_provinsi.id` is `TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT`
 * (`telemedicine_test.sql:59`). 255 is both the row ceiling and the value MySQL
 * clamps the counter to once it is reached - and then **every** further insert
 * into the table fails with
 *
 * ```text
 * SQLSTATE[HY000]: General error: 1467 Failed to read auto-increment value from storage engine
 * ```
 *
 * which reads like an InnoDB statistics fault and is not one. Measured on this
 * host: a freshly created `sehatly_f3c_scratch` reports `auto_increment = 1`,
 * and after seventeen Feature tests that seed the 38-row province list it
 * reports `255` and refuses every insert, for the life of the database.
 * `telemedisin_db_test` is sitting at exactly `255` right now.
 *
 * **InnoDB does not roll the auto-increment counter back with a transaction**,
 * so every test that seeds the province list inside `RefreshDatabase`'s wrapper
 * burns 38 permanent counter values and throws them away. Six such tests
 * saturate a `TINYINT` key permanently.
 *
 * So the demo tests write the single row they need, at the id the DDL's own
 * tuple order assigns to `kode = '31'` - the eleventh province in
 * `telemedicine_test.sql:1203`-`:1214`, hence `11`. An explicit id makes InnoDB
 * set the counter to `max(counter, id + 1)`, so the value stops at 12 and never
 * grows. Nothing else in these three files reads `master_provinsi`.
 *
 * The schema itself is read-only law and is NOT changed here; the fix that would
 * remove the hazard belongs to whoever owns the DDL, and it is recorded in
 * `.omo/evidence/F3C-notifications-and-demo-data.md`.
 */
function demo3cProvinsi(): void
{
    DB::table('master_provinsi')->insertOrIgnore([
        'id' => 11,
        'kode' => '31',
        'nama' => 'DKI Jakarta',
    ]);
}

/**
 * Seed the chain in `DatabaseSeeder`'s order, minus the truncating entry point.
 *
 * @param  list<string>  $grup  `butuh-demo`, `master`, `rbac`, `demo`
 */
function demo3cSeed(array $grup): void
{
    $classes = [
        // The FOUR seeders `DemoDataSeeder` actually resolves against beyond the
        // province: the RBAC kernel it grants roles in, the four master rows its
        // patient carries, the specialisation its doctor links and the two drugs
        // it stocks. A file that asserts the SEEDER's behaviour has no business
        // loading 67 rows of ICD, laboratory, promo and payment-method catalogue
        // it never reads.
        'butuh-demo' => [
            MasterUmumSeeder::class,
            SpesialisasiSeeder::class,
            ObatSeeder::class,
            RbacSeeder::class,
        ],
        'master' => [
            MasterUmumSeeder::class,
            SpesialisasiSeeder::class,
            PenjaminSeeder::class,
            MetodePembayaranSeeder::class,
            IcdSeeder::class,
            ObatSeeder::class,
            LabSeeder::class,
            ArtikelKategoriSeeder::class,
        ],
        'rbac' => [RbacSeeder::class],
        'demo' => [DemoDataSeeder::class],
    ];

    $chosen = [];

    foreach ($grup as $satu) {
        foreach ($classes[$satu] ?? [] as $class) {
            $chosen[] = $class;
        }
    }

    // Before the seeders, and not as one of them: see {@see demo3cProvinsi()}.
    demo3cProvinsi();

    test()->seed($chosen);
}

/** The seeded demo account for `$nama` ({@see DemoDataSeeder::namaAkun()}). */
function demo3cUser(string $nama): User
{
    $akun = DemoDataSeeder::akun($nama);

    $id = (int) DB::table('users')->where('no_telepon', $akun['no_telepon'])->value('id');

    if ($id === 0) {
        throw new RuntimeException("demo3cUser: no seeded users row for [{$akun['no_telepon']}]. Did DemoDataSeeder run?");
    }

    return User::query()->findOrFail($id);
}

/** The role names one account holds, read through the same join the guards use. */
function demo3cRoles(int $userId): array
{
    return DB::table('user_roles')
        ->join('roles', 'roles.id', '=', 'user_roles.role_id')
        ->where('user_roles.user_id', $userId)
        ->orderBy('roles.nama')
        ->pluck('roles.nama')
        ->all();
}

/**
 * The demo doctor's `dokter` row id.
 */
function demo3cDokterId(): int
{
    return (int) DB::table('dokter')
        ->where('user_id', demo3cUser(DemoDataSeeder::AKUN_DOKTER)->getKey())
        ->value('id');
}

/**
 * A date the demo doctor certainly publishes a slot on.
 *
 * The seeder writes one `dokter_jadwal` row per weekday, so tomorrow always
 * resolves; tomorrow rather than today because `SlotAvailabilityService` drops
 * an ELAPSED slot on today (its rule 1b), which would make the choice of hour
 * a variable this helper is trying to remove.
 */
function demo3cTanggal(): string
{
    return WaktuIndonesia::now()->addDay()->format(WaktuIndonesia::FORMAT_TANGGAL);
}

/**
 * Bind an in-memory OTP sender for the current test.
 *
 * Called from the test file's own `beforeEach` rather than lazily inside
 * {@see demo3cToken()}, because `AuthFlowTest` binds it the same way and the
 * binding has to be in place BEFORE the first request: `OtpService` takes its
 * `OtpSender` through the constructor, and a sender swapped in after a service
 * has been resolved would leave that service holding the log channel.
 *
 * This is the one production seam a demo boot substitutes, and it substitutes
 * DELIVERY only: the code, the storage, the rate limits, the burn and the
 * verify are all the real ones.
 */
function demo3cIkatOtp(): void
{
    test()->instance(OtpSender::class, new FakeOtpSender);
    app('auth')->forgetGuards();
}

/**
 * Log a demo account in through the REAL two-step flow and return its bearer
 * token, or null when the flow did not produce one.
 *
 * ## Why this is not `$user->createToken(...)`
 *
 * `POST /api/v1/auth/login` deliberately returns NO token and
 * `POST /api/v1/auth/otp/verify` is the only issuer - the F3 gate verified that
 * as correct. A test that minted a Sanctum token directly would prove the demo
 * password works and nothing else, and the password is exactly what F3 could
 * not exercise. So the password is spent here, for real, and the OTP is read
 * from the bound {@see OtpSender} - the same seam a real SMS gateway replaces.
 */
function demo3cToken(string $noTelepon, string $kataSandi): ?string
{
    $pengirim = app(OtpSender::class);

    if (! $pengirim instanceof FakeOtpSender) {
        throw new RuntimeException('demo3cToken: no FakeOtpSender is bound. Call demo3cIkatOtp() from beforeEach.');
    }

    app('auth')->forgetGuards();

    $login = test()->postJson('/api/v1/auth/login', [
        'no_telepon' => $noTelepon,
        'password' => $kataSandi,
    ]);

    $login->assertOk();

    if ($login->json('data.token') !== null) {
        throw new RuntimeException('POST /auth/login returned a token, so the two-step flow this test relies on is gone.');
    }

    $kode = $pengirim->lastKodeFor(OtpService::TUJUAN_LOGIN);

    if ($kode === null) {
        throw new RuntimeException('The login step delivered no OTP, so otp/verify cannot be driven.');
    }

    $verify = test()->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => $noTelepon,
        'kode' => $kode,
        'tujuan' => OtpService::TUJUAN_LOGIN,
    ]);

    $verify->assertOk();

    return $verify->json('data.token.access_token');
}

/** A logged-in bearer token for one demo account, or null. */
function demo3cTokenAkun(string $nama): ?string
{
    $akun = DemoDataSeeder::akun($nama);

    return demo3cToken($akun['no_telepon'], $akun['kata_sandi']);
}

/**
 * The test case, optionally carrying a bearer token.
 *
 * `forgetGuards()` first, because the auth manager is a singleton whose guard
 * caches the resolved user: without it, the second request in a test would be
 * authenticated as the first request's account and a 403 would read as "the
 * guard refused them" when in fact the guard never ran.
 */
function demo3cAs(?string $token = null): TestCase
{
    app('auth')->forgetGuards();

    $test = test();

    if ($token === null) {
        return $test->flushHeaders();
    }

    return $test->withHeader('Authorization', 'Bearer '.$token);
}

/**
 * Grant a role to an account, for a test that needs a role the demo seeder
 * deliberately does not grant.
 */
function demo3cBeriPeran(int $userId, string $role): void
{
    app(RoleAssigner::class)->assign($userId, $role);
}

/**
 * The demo pharmacy's `faskes` row id.
 *
 * A checkout has to NAME a pharmacy when the prescription names none, and
 * `resep.apotek_id` is `NULL` on a prescription written through
 * `POST /konsultasi/{id}/resep` - which is exactly the situation a real
 * prescription is in.
 */
function demo3cApotekId(): int
{
    $id = (int) DB::table('faskes')->where('kode_faskes', 'FASKES-DEMO-001')->value('id');

    if ($id === 0) {
        throw new RuntimeException('demo3cApotekId: the demo pharmacy is missing. Did DemoDataSeeder run?');
    }

    return $id;
}

/**
 * The `master_obat` id for a `kode_obat`.
 */
function demo3cObat(string $kode = 'OBT-0002'): int
{
    $id = (int) DB::table('master_obat')->where('kode_obat', $kode)->value('id');

    if ($id === 0) {
        throw new RuntimeException("demo3cObat: master_obat has no kode_obat [{$kode}].");
    }

    return $id;
}

/**
 * The `master_metode_pembayaran` id of an ACTIVE method.
 */
function demo3cMetode(): int
{
    $id = (int) DB::table('master_metode_pembayaran')
        ->where('status_aktif', 1)
        ->orderBy('id')
        ->value('id');

    if ($id === 0) {
        throw new RuntimeException('demo3cMetode: master_metode_pembayaran holds no active method.');
    }

    return $id;
}

/**
 * A correctly signed webhook body and headers, plus the gateway name.
 *
 * The HMAC is computed HERE, from `config()`, and never asked for from the
 * implementation: the claim under test is that the settlement ran, not that the
 * application can sign.
 *
 * @return array{0: string, 1: array<string, string>, 2: string}
 */
function demo3cWebhook(string $gateway, string $nomorReferensi, string $jumlah, string $status = 'berhasil'): array
{
    $badan = (string) json_encode([
        'gateway' => $gateway,
        'nomor_referensi' => $nomorReferensi,
        'status' => $status,
        'jumlah' => $jumlah,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $rahasia = (string) config('services.payment.gateways.'.$gateway.'.webhook_secret');

    return [$badan, ['HTTP_X_PAYMENT_SIGNATURE' => hash_hmac('sha256', $badan, $rahasia)], $gateway];
}

/**
 * POST a signed webhook, with the RAW bytes the signature was computed over.
 */
function demo3cKirim(string $badan, array $server, string $gateway): TestResponse
{
    return test()->call(
        'POST',
        '/api/v1/webhook/payment/'.$gateway,
        [],
        [],
        [],
        array_merge($server, ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']),
        $badan,
    );
}
