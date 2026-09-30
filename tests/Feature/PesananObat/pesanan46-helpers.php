<?php

declare(strict_types=1);

use App\Models\Faskes;
use App\Models\Pasien;
use App\Models\Resep;
use App\Models\ResepItem;
use App\Models\User;
use App\Support\Rbac\RoleAssigner;
use App\Support\Schema\SchemaSpec;
use App\Support\Schema\SqlSchemaParser;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/*
|--------------------------------------------------------------------------
| Todo 46 - shared fixtures for the checkout, stock and tracking test files
|--------------------------------------------------------------------------
|
| `require_once`d by each of them, so a single file is runnable on its own -
| the same reason todo 39's `resep-helpers.php` and todo 40's `resep40-helpers.php`
| exist. The prefix is `po46` because Pest loads every test file into ONE
| process and `rx39`, `rx40`, `inv44`, `bkc`, `kns` and `slot` are taken.
|
| ## The clock is the test's
|
| `PO46_JAM` is `2026-03-11 10:00:00`, so `berlaku_sampai` for a prescription
| written against it is `2026-03-18` - the seven days
| `telemedicine_test.sql:755` names in its own COMMENT. The expiry test moves
| the clock FORWARD rather than writing a past date into the column, because
| nothing in the schema reacts to that date: the rule under test has to be a PHP
| comparison, and moving the clock is what makes that comparison the only thing
| being measured.
|
| ## Every fixture helper records what it inserted
|
| `po46Catat()` is the fixture list, so `po46Selesai()` cannot drift from it -
| the same collector `invoice-helpers.php` uses. The two-connection tests commit
| the `RefreshDatabase` wrapper, so their rows leave the transaction and have to
| be deleted by hand.
|
| ## The second connection, for the oversell proof
|
| `po46Mulai()` / `po46DiSisiLawan()` copy `BookingConcurrencyTest`'s
| choreography verbatim and for the same reason: a mocked assertion that
| `lockForUpdate()` was called proves nothing about contention, and only a
| second real connection can show that the lock is CONTENDED.
*/

const PO46_JAM = '2026-03-11 10:00:00';

/** The day `berlaku_sampai` lands on for a prescription written at {@see PO46_JAM}. */
const PO46_BERLAKU = '2026-03-18';

const PO46_KONEKSI_UTAMA = 'mysql';

const PO46_KONEKSI_LAWAN = 'po46_b';

/** MySQL's lock-wait timeout as a driver error code. */
const PO46_KODE_LOCK_WAIT = 1205;

function po46Jam(): Carbon
{
    return Carbon::parse(PO46_JAM);
}

function po46KunciJam(): void
{
    Carbon::setTestNow(po46Jam());
}

function po46LepasJam(): void
{
    Carbon::setTestNow();
}

/**
 * Record a row this test created, so the committed-fixture teardown can find it.
 *
 * @return int the id, so a helper can `return po46Catat(...)` directly
 */
function po46Catat(string $tabel, int $id): int
{
    $GLOBALS['po46_dibuat'][$tabel][] = $id;

    return $id;
}

/**
 * @return array<string, list<int>>
 */
function po46Dibuat(): array
{
    return (array) ($GLOBALS['po46_dibuat'] ?? []);
}

function po46Bersihkan(): void
{
    $GLOBALS['po46_dibuat'] = [];
}

/**
 * A `users` row.
 *
 * `uuid` (`:133`), `nama_lengkap` (`:135`), `no_telepon` (`:137`) and
 * `kata_sandi_hash` (`:138`) are the NOT NULL columns with no default, and
 * `tipe` is the seven-value ENUM at `:139`.
 */
function po46User(string $nama, string $tipe = 'pasien', ?string $role = null): User
{
    $id = po46Catat('users', (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => $nama,
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => $tipe,
        'status' => 'aktif',
    ]));

    if ($role !== null) {
        app(RoleAssigner::class)->assign($id, $role);
    }

    return User::query()->findOrFail($id);
}

/**
 * A `pasien` row.
 *
 * `jenis_kelamin` (`:225`), `tanggal_lahir` (`:226`) and `alamat_lengkap`
 * (`:234`) are NOT NULL with no default. The address is the one the checkout
 * falls back to for `pesanan_obat.alamat_kirim`, so it is deliberately
 * recognisable in a test.
 */
function po46Pasien(int $userId, array $ubah = []): int
{
    return po46Catat('pasien', (int) DB::table('pasien')->insertGetId(array_merge([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Checkout No. 1, Jakarta',
    ], $ubah)));
}

/**
 * A patient account plus its `pasien` row and the `pasien` role, which is what
 * `permission:pesanan.buat` needs.
 *
 * @return array{user: User, pasien: int}
 */
function po46AkunPasien(string $nama = 'Pasien Checkout'): array
{
    $user = po46User($nama, 'pasien', 'pasien');

    return ['user' => $user, 'pasien' => po46Pasien((int) $user->getKey())];
}

/**
 * A `dokter` row: the prescriber.
 *
 * `user_id`, `tipe`, `nomor_str` and `str_berlaku_sampai` are the four NOT NULL
 * columns with no default.
 */
function po46Dokter(int $userId): int
{
    return po46Catat('dokter', (int) DB::table('dokter')->insertGetId([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-PO46-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ]));
}

/**
 * A `faskes` row.
 *
 * `nama VARCHAR(200) NOT NULL` (`:364`), `tipe` the five-value ENUM at `:365` and
 * `alamat TEXT NOT NULL` (`:367`). `status_aktif TINYINT(1) NOT NULL DEFAULT 1`
 * is `:378`, so an inactive facility is a real stored state.
 */
function po46Faskes(string $tipe = 'apotek', array $ubah = []): int
{
    return po46Catat('faskes', (int) DB::table('faskes')->insertGetId(array_merge([
        'nama' => 'Apotek Uji Checkout '.(string) Str::upper(Str::random(4)),
        'tipe' => $tipe,
        'alamat' => 'Jl. Uji Apotek No. 2, Jakarta',
        'status_aktif' => 1,
    ], $ubah)));
}

/**
 * A `master_obat` row.
 *
 * `kode_obat` (`:710`), `nama_generik` (`:711`), `bentuk_sediaan` (`:713`-`:714`)
 * and `satuan` (`:716`) and `kelas_obat` (`:719`) are the NOT NULL columns.
 * `requires_resep TINYINT(1) NOT NULL DEFAULT 1` is `:720` and
 * `harga_jual DECIMAL(12,2) NOT NULL DEFAULT 0` is `:724`.
 */
function po46Obat(string $namaGenerik = 'Amoxicillin', array $ubah = []): int
{
    return po46Catat('master_obat', (int) DB::table('master_obat')->insertGetId(array_merge([
        'kode_obat' => 'PO46-'.Str::upper(Str::random(10)),
        'nama_generik' => $namaGenerik,
        'bentuk_sediaan' => 'tablet',
        'satuan' => 'tablet',
        'kelas_obat' => 'keras',
        'harga_jual' => '7500.00',
        'requires_resep' => 1,
        'status_aktif' => 1,
    ], $ubah)));
}

/**
 * An `apotek_stok` row.
 *
 * `apotek_id` (`:831`), `obat_id` (`:832`) and `jumlah_stok INT NOT NULL DEFAULT
 * 0` (`:833`) are the three NOT NULL columns. `jumlah_stok` is SIGNED - that is
 * the point of the whole oversell story - so a negative value is storable and
 * the test asserts it never appears.
 */
function po46Stok(int $apotekId, int $obatId, int $jumlah, array $ubah = []): int
{
    return po46Catat('apotek_stok', (int) DB::table('apotek_stok')->insertGetId(array_merge([
        'apotek_id' => $apotekId,
        'obat_id' => $obatId,
        'jumlah_stok' => $jumlah,
        'stok_minimum' => 5,
        'harga_jual' => '7500.00',
    ], $ubah)));
}

/**
 * A `resep` row plus its `resep_item` lines.
 *
 * `nomor_resep VARCHAR(30) NOT NULL UNIQUE` (`:744`), `pasien_id` (`:747`),
 * `dokter_id` (`:748`), `status` the eight-value ENUM wrapping `:751`-`:752`,
 * `tanggal_resep` (`:754`), `berlaku_sampai` (`:755`) and `qr_token
 * VARCHAR(100) NOT NULL` (`:758`) are the NOT NULL columns.
 *
 * @param  list<int>  $obatIds  catalogue drugs; an EMPTY list writes a racikan
 *                              line instead, which is `obat_id` NULL (`:770`)
 * @param  array<string, mixed>  $ubah  extra `resep` columns, e.g. `apotek_id`
 * @param  array<string, mixed>  $ubahItem  extra `resep_item` columns
 */
function po46Resep(
    int $pasienId,
    int $dokterId,
    array $obatIds,
    string $status = 'diverifikasi',
    array $ubah = [],
    array $ubahItem = [],
): Resep {
    $resep = new Resep;
    $resep->nomor_resep = 'RXPO46'.Str::upper(Str::random(10));
    $resep->konsultasi_id = null;
    $resep->rekam_medis_id = null;
    $resep->pasien_id = $pasienId;
    $resep->dokter_id = $dokterId;
    $resep->apotek_id = null;
    $resep->tipe = 'digital';
    $resep->status = $status;
    $resep->tanggal_resep = po46Jam();
    $resep->berlaku_sampai = PO46_BERLAKU;
    $resep->qr_token = (string) Str::uuid();

    foreach ($ubah as $kolom => $nilai) {
        $resep->{$kolom} = $nilai;
    }

    $resep->save();

    // Recorded, because the committed-fixture teardown deletes `resep` before
    // `faskes` and a `resep` this helper created but did not record leaves
    // `faskes` undeletable with a real MySQL 1451.
    po46Catat('resep', (int) $resep->getKey());

    $nama = DB::table('master_obat')->whereIn('id', $obatIds)->pluck('nama_generik', 'id');

    foreach ($obatIds as $obatId) {
        po46Item($resep, [
            'obat_id' => $obatId,
            'nama_obat' => (string) ($nama[$obatId] ?? 'Uji PO46'),
        ] + $ubahItem);
    }

    if ($obatIds === []) {
        po46Item($resep, [
            'obat_id' => null,
            'nama_obat' => 'Racikan Uji PO46',
            'is_racikan' => true,
            'racikan_nama' => 'Racikan Uji PO46',
            'harga_satuan' => '0.00',
            'subtotal' => '0.00',
        ] + $ubahItem);
    }

    return $resep;
}

/**
 * One `resep_item` row.
 *
 * `nama_obat VARCHAR(255) NOT NULL` (`:771`) is the SNAPSHOT of the catalogue
 * name at prescribing time, `aturan_pakai VARCHAR(255) NOT NULL` (`:772`) and
 * `jumlah SMALLINT UNSIGNED NOT NULL` (`:774`) are NOT NULL, and the money
 * columns `harga_satuan` and `subtotal` (`:778`-`:779`) default to 0.
 *
 * `subtotal` is written as `harga_satuan * jumlah` by this helper so a fixture
 * that does not test the "nothing maintains that column" case looks honest -
 * and {@see po46Resep()} with `$ubahItem = ['subtotal' => ...]` writes it wrong
 * on purpose so a test can prove the service does not trust it.
 */
function po46Item(Resep $resep, array $isi): ResepItem
{
    $baris = new ResepItem;
    $baris->resep_id = $resep->getKey();
    $baris->obat_id = $isi['obat_id'];
    $baris->nama_obat = (string) $isi['nama_obat'];
    $baris->kekuatan = $isi['kekuatan'] ?? '500 mg';
    $baris->aturan_pakai = $isi['aturan_pakai'] ?? '3 x 1 tablet';
    $baris->jumlah = (int) ($isi['jumlah'] ?? 10);
    $baris->satuan = $isi['satuan'] ?? 'tablet';
    $baris->is_racikan = (bool) ($isi['is_racikan'] ?? false);
    $baris->racikan_nama = $isi['racikan_nama'] ?? null;
    $baris->harga_satuan = (string) ($isi['harga_satuan'] ?? '7500.00');
    $baris->subtotal = (string) ($isi['subtotal'] ?? bcmul($baris->harga_satuan, (string) $baris->jumlah, 2));
    $baris->catatan_apoteker = $isi['catatan_apoteker'] ?? null;
    $baris->save();

    po46Catat('resep_item', (int) $baris->getKey());

    return $baris;
}

/**
 * The one `resep_verifikasi` row, whose `resep_id` is `NOT NULL UNIQUE` (`:788`).
 */
function po46Verifikasi(int $resepId, string $status = 'sesuai'): int
{
    return po46Catat('resep_verifikasi', (int) DB::table('resep_verifikasi')->insertGetId([
        'resep_id' => $resepId,
        'apoteker_user_id' => po46User('Apoteker Uji '.(string) Str::upper(Str::random(4)), 'apoteker')->getKey(),
        'status' => $status,
        'diverifikasi_at' => po46Jam(),
    ]));
}

/**
 * Bearer headers for `$user`, with a real Sanctum token.
 *
 * The expiry is ABSOLUTE rather than "one hour from now", because the expiry
 * test moves `Carbon::setTestNow()` FORWARD past `berlaku_sampai` and a
 * relative token would silently 401 on the second request of the same test - a
 * failure that reads as a broken endpoint rather than a fixture that aged out.
 * A year is far past any clock these tests move to and stays inside
 * `personal_access_tokens.expires_at`, which is a `TIMESTAMP` and stops at 2038.
 *
 * @return array<string, string>
 */
function po46As(User $user): array
{
    app('auth')->forgetGuards();

    $token = $user->createToken('po46', ['*'], po46Jam()->addYear())->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

/**
 * A checkout-ready world: a patient, their prescriber, a pharmacy and one
 * prescription for one drug.
 *
 * @return array{
 *     user: User, pasien: int, dokter: int, apotek: int, obat: int,
 *     resep: Resep, stok: int
 * }
 */
function po46Dunia(int $jumlahStok = 100, int $jumlahResep = 10, string $tipeFaskes = 'apotek'): array
{
    $pasienUser = po46User('Pasien Checkout '.(string) Str::upper(Str::random(4)), 'pasien', 'pasien');
    $pasien = po46Pasien((int) $pasienUser->getKey());
    $dokter = po46Dokter(po46User('Dokter Checkout '.(string) Str::upper(Str::random(4)), 'dokter')->getKey());
    $apotek = po46Faskes($tipeFaskes);
    $obat = po46Obat();
    po46Stok($apotek, $obat, $jumlahStok);

    $resep = po46Resep($pasien, $dokter, [$obat], 'diverifikasi', ['apotek_id' => $apotek], ['jumlah' => $jumlahResep]);

    return [
        'user' => $pasienUser,
        'pasien' => $pasien,
        'dokter' => $dokter,
        'apotek' => $apotek,
        'obat' => $obat,
        'resep' => $resep,
        'stok' => (int) DB::table('apotek_stok')->where('apotek_id', $apotek)->where('obat_id', $obat)->value('id'),
    ];
}

// =====================================================================
// DDL assertions
// =====================================================================

function po46Spec(): SchemaSpec
{
    return (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
}

/**
 * The ENUM values of one column, as the project's OWN parser reads them.
 *
 * `pesanan_obat.status` is a SEVENTH multi-line ENUM: the value list ends on
 * `:810` and the `NOT NULL DEFAULT` tail is on `:811`, so reading one line
 * yields six members with an empty tail and the column looks nullable with no
 * default. The parser is what makes the wrap a single unit, and this is the
 * helper that proves the app's own lists match it in ORDER.
 *
 * @return list<string>
 */
function po46Enum(string $table, string $column): array
{
    $type = po46Spec()->table($table)->columns[$column]->type;

    preg_match_all("/'([^']+)'/", (string) $type, $matches);

    return $matches[1];
}

function po46DdlLine(int $n): ?string
{
    $lines = file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);

    return $lines[$n - 1] ?? null;
}

/**
 * Assert `telemedicine_test.sql:$line` contains `$token`, so a citation in this
 * file is PROVED against the file rather than quoted from the plan.
 */
function po46AssertLine(int $line, string $token): void
{
    $actual = po46DdlLine($line);

    Assert::assertNotNull($actual, "telemedicine_test.sql:{$line} does not exist");

    Assert::assertStringContainsString(
        $token,
        (string) $actual,
        "telemedicine_test.sql:{$line} is [".(string) $actual.'] and does not contain ['.$token.']',
    );
}

/**
 * Assert `telemedicine_test.sql:$line` does NOT contain `$token`.
 *
 * The negative assertion is the load-bearing half for a citation about a
 * MISSING thing: `jumlah_stok` having no `CHECK`, `pesanan_obat` having no item
 * table, `resep_id` being nullable. A positive search cannot prove an absence.
 */
function po46AssertLineLacks(int $line, string $token): void
{
    $actual = po46DdlLine($line);

    Assert::assertNotNull($actual, "telemedicine_test.sql:{$line} does not exist");

    Assert::assertStringNotContainsString(
        $token,
        (string) $actual,
        "telemedicine_test.sql:{$line} is [".(string) $actual.'] and unexpectedly contains ['.$token.']',
    );
}

// =====================================================================
// The route census
// =====================================================================

/**
 * The three routes todo 46 registers, keyed by `METHOD uri`.
 *
 * The closed set is keyed by URI rather than collected from a `--path=` filter,
 * because `obat/{id}/stok` is an `obat` path while `resep/{id}/checkout` is a
 * `resep` path: a prefix filter cannot see all three at once. The plan's own
 * acceptance criterion says this surface "includes both routes" and its prose
 * names three, so the set is asserted by URI and the discrepancy is recorded in
 * `.omo/evidence/task-46-sehatly.md` rather than satisfied by deleting an
 * endpoint.
 *
 * @return array<string, mixed>
 */
function po46Routes(): array
{
    $uris = [
        'api/v1/resep/{id}/checkout',
        'api/v1/obat/{id}/stok',
        'api/v1/pesanan-obat/{id}',
    ];

    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array($route->uri(), $uris, true))
        ->keyBy(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->all();
}

/**
 * @param  array<string, mixed>  $routes
 * @return list<string>
 */
function po46Guards(array $routes, string $key): array
{
    return array_values(array_filter(
        $routes[$key]->gatherMiddleware(),
        static fn ($middleware): bool => is_string($middleware),
    ));
}

// =====================================================================
// The two-connection dance, for the oversell proof
// =====================================================================

/**
 * Leave the `RefreshDatabase` wrapping transaction and open a SECOND connection.
 *
 * The commit is what makes the fixtures visible to the other connection;
 * without it the second transaction would see an empty `apotek_stok` and every
 * assertion about contention would be meaningless. The second connection is a
 * copy of the `mysql` config under a new name, so `DatabaseManager` builds a
 * genuinely separate `Connection` with its own PDO.
 */
function po46Mulai(): void
{
    if (DB::connection(PO46_KONEKSI_UTAMA)->transactionLevel() > 0) {
        DB::commit();
    }

    config([
        'database.connections.'.PO46_KONEKSI_LAWAN => config('database.connections.'.PO46_KONEKSI_UTAMA),
    ]);

    DB::purge(PO46_KONEKSI_LAWAN);

    $lawan = DB::connection(PO46_KONEKSI_LAWAN);

    // One second, not MySQL's default 50: a `SELECT ... FOR UPDATE` that has to
    // wait therefore fails fast and deterministically instead of stalling the
    // suite.
    $lawan->statement('SET SESSION innodb_lock_wait_timeout = 1');

    // MySQL's own default, stated explicitly on BOTH connections so the test
    // proves the current-read hardening under REPEATABLE READ rather than
    // inheriting whatever the server was configured with.
    $lawan->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::connection(PO46_KONEKSI_UTAMA)->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

    Assert::assertSame('REPEATABLE-READ', $lawan->selectOne('SELECT @@transaction_isolation AS v')->v);
    Assert::assertSame(
        'REPEATABLE-READ',
        DB::connection(PO46_KONEKSI_UTAMA)->selectOne('SELECT @@transaction_isolation AS v')->v
    );
}

/**
 * Delete every committed fixture this test created, then re-open a transaction
 * so `RefreshDatabase`'s teardown still finds an active one.
 *
 * `RefreshDatabase` checks `! $pdo->inTransaction()` at teardown and, if that
 * holds, sets `RefreshDatabaseState::$migrated = false` - which would force a
 * full `migrate:fresh` for every remaining test in the process.
 *
 * The committed RBAC seed goes too, because {@see po46Mulai()} committed the
 * wrapper transaction and `RbacSeeder`'s inserts are not idempotent: the next
 * test's `beforeEach` would collide on `roles.roles_nama_unique`.
 */
function po46Selesai(): void
{
    if (DB::connection(PO46_KONEKSI_UTAMA)->transactionLevel() > 0) {
        DB::connection(PO46_KONEKSI_UTAMA)->rollBack();
    }

    $dibuat = po46Dibuat();

    // The two tables the SERVICE writes rather than a fixture: the invoice and
    // the order. Reachable from what the collector does see - every invoice and
    // every order name a patient this test created - so the collector does not
    // have to know about them.
    DB::table('promo_redemption')->whereIn('pasien_id', $dibuat['pasien'] ?? [])->delete();
    DB::table('invoice')->whereIn('pasien_id', $dibuat['pasien'] ?? [])->delete();
    DB::table('pesanan_obat_tracking')->whereIn('pesanan_obat_id', $dibuat['pesanan_obat'] ?? [])->delete();
    DB::table('pesanan_obat')->whereIn('pasien_id', $dibuat['pasien'] ?? [])->delete();

    // Children before parents, in foreign-key order. `master_obat` is here
    // because `apotek_stok.obat_id` and `resep_item.obat_id` both reference it,
    // and `resep_verifikasi` before `resep` for the same reason `resep_item` is.
    foreach ([
        'resep_verifikasi',
        'resep_item',
        'resep',
        'apotek_stok',
        'faskes',
        'dokter',
        'user_roles',
        'master_obat',
        'pasien',
        'users',
    ] as $tabel) {
        $ids = $dibuat[$tabel] ?? [];

        if ($ids !== []) {
            DB::table($tabel)->whereIn('id', $ids)->delete();
        }
    }

    DB::table('user_roles')->whereIn('user_id', $dibuat['users'] ?? [])->delete();

    // The committed RBAC seed goes too, because {@see po46Mulai()} committed the
    // wrapper transaction and `RbacSeeder`'s inserts are not idempotent: the next
    // test's `beforeEach` would collide on `roles.roles_nama_unique` with a real
    // MySQL 1062. The three tables are only ever written by that seeder, so
    // emptying all three is what lets the next seed run cleanly - and the next
    // seed DOES run, from the shared `beforeEach` in this file. This is
    // `inv44Selesai()`'s teardown exactly, and re-seeding here instead would
    // make the NEXT test's own `beforeEach` collide with it.
    try {
        foreach (['role_permissions', 'permissions', 'roles'] as $tabel) {
            DB::table($tabel)->delete();
        }
    } finally {
        if (DB::connection(PO46_KONEKSI_LAWAN)->transactionLevel() > 0) {
            DB::connection(PO46_KONEKSI_LAWAN)->rollBack();
        }

        DB::purge(PO46_KONEKSI_LAWAN);

        if (DB::connection(PO46_KONEKSI_UTAMA)->transactionLevel() === 0) {
            DB::beginTransaction();
        }

        po46Bersihkan();
    }
}

/**
 * Run `$aksi` on the SECOND connection with the default connection pointed at
 * it, then put the default back.
 *
 * `config('database.default')` is the single switch both `DB::transaction()` and
 * every Eloquent model read, so pointing it at the second connection is enough
 * to run the unmodified production service there.
 *
 * @template T
 *
 * @param  callable(): T  $aksi
 * @return T
 */
function po46DiSisiLawan(callable $aksi): mixed
{
    config(['database.default' => PO46_KONEKSI_LAWAN]);

    try {
        return $aksi();
    } finally {
        config(['database.default' => PO46_KONEKSI_UTAMA]);
    }
}

/**
 * Is `$e` a MySQL lock-wait timeout, whatever Laravel wrapped it in?
 *
 * The driver code is read out of `QueryException::$errorInfo` rather than
 * matched against a class, because whether 1205 becomes a `DeadlockException` or
 * a plain `QueryException` is a framework detail and the property under test is
 * the server's answer, not Laravel's mapping of it.
 */
function po46AdalahLockWait(Throwable $e): bool
{
    for ($tipe = $e; $tipe !== null; $tipe = $tipe->getPrevious()) {
        if ($tipe instanceof QueryException
            && (int) ($tipe->errorInfo[1] ?? 0) === PO46_KODE_LOCK_WAIT) {
            return true;
        }
    }

    return false;
}

/**
 * Run `$aksi` and return the `$tipe` it threw, or fail the test naming both.
 *
 * @template T of Throwable
 *
 * @param  class-string<T>  $tipe
 * @return T
 */
function po46Tangkap(callable $aksi, string $tipe): Throwable
{
    try {
        $hasil = $aksi();
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($tipe, 'Expected ['.$tipe.'] but got ['.get_class($e).']: '.$e->getMessage());

        /** @var T $e */
        return $e;
    }

    Assert::fail('Expected ['.$tipe.'] but nothing was thrown. Returned: '.var_export($hasil, true));
}

/*
|--------------------------------------------------------------------------
| Why the `beforeEach` lives in the TEST FILES and not here
|--------------------------------------------------------------------------
|
| This file is `require_once`d by three test files, so its body runs ONCE per
| process. A `beforeEach()` declared here is therefore registered for the FIRST
| file that requires it and for no other: the second and third files run with no
| hook at all.
|
| That is not a hypothetical. It showed up as 35 errors in a full-suite run
| whose symptom was `RbacCatalog::ROLES names pasien but `roles` holds no such
| row` in `CheckoutTest`, while the same three files each passed when run alone -
| because alone, each run had exactly one requiring file and so exactly one
| hook. The RBAC catalogue is deleted DURABLY by {@see po46Selesai()} (it has
| to be: the `RefreshDatabase` wrapper was committed), so a second file with no
| `beforeEach` finds an empty `roles` table.
|
| `invoice-helpers.php` avoids it the same way: its only `beforeEach` is scoped
| to what it owns, and each test file seeds the RBAC catalogue in its OWN.
*/
