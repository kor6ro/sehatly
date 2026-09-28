<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\User;
use App\Support\Rbac\RoleAssigner;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/*
|--------------------------------------------------------------------------
| Todo 44 - shared fixtures for the Invoice and Promo test files
|--------------------------------------------------------------------------
|
| `require_once`d by each of them, so a single file is runnable on its own.
| The prefix is `inv44` because Pest loads every test file into ONE process and
| `bkc*`, `bku*`, `slot*` and `rx39*` are already taken.
|
| ## Why the clock is frozen in UTC but the promo window is compared in WIB
|
| `master_promo.mulai_at` and `master_promo.selesai_at` are `DATETIME NOT NULL`
| (telemedicine_test.sql:995 and :996) and the DDL gives them no timezone, so
| they hold what an operator typed - Indonesian local wall-clock. The plan's
| todo 51 makes this explicit ("treating them as UTC makes every promo activate
| or expire 7 hours off") and names `WaktuIndonesia::now()` as the comparer;
| that class is todo 51's to write, so todo 44 puts the conversion in ONE
| private method and states the offset it applies.
|
| So the frozen instant below is a UTC INSTANT and every window fixture is a
| WIB WALL-CLOCK, and `INV44_SEKARANG_WIB` is asserted to equal the former plus
| seven hours so the relationship is a test, not a comment.
*/

const INV44_SEKARANG_UTC = '2026-03-11 10:00:00';

/** `INV44_SEKARANG_UTC` rendered in Asia/Jakarta (WIB, UTC+7, no DST). */
const INV44_SEKARANG_WIB = '2026-03-11 17:00:00';

/** The suffix `NomorDokumen` appends; `INV` + 8 date digits + 6 = 17 of VARCHAR(30). */
const INV44_PANJANG_NOMOR = 17;

/** MySQL's lock-wait timeout as a driver error code. */
const INV44_KODE_LOCK_WAIT = 1205;

const INV44_KONEKSI_UTAMA = 'mysql';

const INV44_KONEKSI_LAWAN = 'inv44_b';

function inv44KunciJam(): void
{
    Carbon::setTestNow(Carbon::parse(INV44_SEKARANG_UTC, 'UTC'));
}

function inv44LepasJam(): void
{
    Carbon::setTestNow();
}

/**
 * A `users` row; the NOT NULL columns with no default are `uuid` (:133),
 * `nama_lengkap` (:135), `no_telepon` (:137) and `kata_sandi_hash` (:138).
 */
function inv44User(string $nama, string $tipe = 'pasien', ?string $role = 'pasien'): User
{
    $id = inv44Catat('users', (int) DB::table('users')->insertGetId([
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
 * A `pasien` row. `jenis_kelamin` (:225), `tanggal_lahir` (:226) and
 * `alamat_lengkap` (:234) are NOT NULL with no default.
 */
function inv44Pasien(int $userId, array $ubah = []): int
{
    return inv44Catat('pasien', (int) DB::table('pasien')->insertGetId(array_merge([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Invoice No. 1, Jakarta',
    ], $ubah)));
}

/** A patient account plus its `pasien` row, as the pair a controller works with. */
function inv44AkunPasien(string $nama = 'Pasien Invoice'): array
{
    $user = inv44User($nama);
    $pasienId = inv44Pasien((int) $user->getKey());

    return [$user, $pasienId];
}

/** Bearer headers for `$user`, with a real Sanctum token. */
function inv44As(User $user): array
{
    app('auth')->forgetGuards();

    $token = $user->createToken('inv44', ['*'], now()->addHour())->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

/**
 * A `master_metode_pembayaran` row.
 *
 * `kode` (:927) and `nama` (:928) are NOT NULL with no default; `tipe` (:929) is
 * a nine-value ENUM; `biaya_admin_flat` (:931) and `biaya_admin_persen` (:932)
 * both default to 0 and are NOT NULL, so a method with no admin fee is legal and
 * needs no explicit zero.
 *
 * @param  array<string, mixed>  $ubah
 */
function inv44Metode(array $ubah = []): int
{
    return inv44Catat('master_metode_pembayaran', (int) DB::table('master_metode_pembayaran')->insertGetId(array_merge([
        'kode' => 'INV44-'.Str::upper(Str::random(8)),
        'nama' => 'Metode Uji Invoice',
        'tipe' => 'va_bank',
        'penyedia' => 'Bank Uji',
    ], $ubah)));
}

/**
 * A `master_promo` row.
 *
 * `kode` (:987), `nama` (:988), `tipe_diskon` (:989) and `nilai` (:990) have no
 * default; `min_transaksi` (:991), `kuota_per_user` (:994) and `status_aktif`
 * (:997) default; `maks_diskon` (:992) and `kuota_total` (:993) are the two
 * NULLABLE ones and the null case is meaningful in both directions -
 * `maks_diskon IS NULL` means uncapped and `kuota_total IS NULL` means
 * unlimited.
 *
 * The window defaults to the whole frozen day in WIB so a fixture that is not
 * testing the window never accidentally tests it.
 *
 * @param  array<string, mixed>  $ubah
 */
function inv44Promo(array $ubah = []): int
{
    return inv44Catat('master_promo', (int) DB::table('master_promo')->insertGetId(array_merge([
        'kode' => 'INV44'.Str::upper(Str::random(8)),
        'nama' => 'Promo Uji Invoice',
        'tipe_diskon' => 'persen',
        'nilai' => '10.00',
        'min_transaksi' => '0.00',
        'maks_diskon' => null,
        'kuota_total' => null,
        'kuota_per_user' => 1,
        'mulai_at' => '2026-03-01 00:00:00',
        'selesai_at' => '2026-03-31 23:59:59',
        'status_aktif' => 1,
    ], $ubah)));
}

/**
 * A `promo_redemption` row, for planting quota consumption without going
 * through the service. `nilai_diskon` (:1005) is NOT NULL with no default.
 */
function inv44Redemption(int $promoId, int $pasienId, int $invoiceId, string $nilai = '1000.00'): int
{
    return inv44Catat('promo_redemption', (int) DB::table('promo_redemption')->insertGetId([
        'promo_id' => $promoId,
        'pasien_id' => $pasienId,
        'invoice_id' => $invoiceId,
        'nilai_diskon' => $nilai,
    ]));
}

/**
 * An invoice to hang a redemption off, since `promo_redemption.invoice_id` is
 * NOT NULL (:1004) and is foreign-keyed to `invoice(id)` (:1009).
 */
function inv44InvoiceFor(int $pasienId, array $ubah = []): Invoice
{
    $row = new Invoice;
    $row->nomor_invoice = 'INVFIX'.Str::upper(Str::random(10));
    $row->pasien_id = $pasienId;
    $row->referensi_tipe = 'booking';
    $row->referensi_id = 1;
    $row->subtotal = '100000.00';
    $row->diskon = '0.00';
    $row->biaya_admin = '0.00';
    $row->biaya_pengiriman = '0.00';
    $row->total = '100000.00';

    foreach ($ubah as $kolom => $nilai) {
        $row->{$kolom} = $nilai;
    }

    $row->save();

    inv44Catat('invoice', (int) $row->getKey());

    return $row;
}

/**
 * A `booking` row, the `referensi_tipe = 'booking'` source.
 *
 * `pasien_id` (:501), `dokter_id` (:503) and the two slot `TIME` columns (:508,
 * :509) are NOT NULL, as is `dibuat_oleh_user_id` (:519).
 *
 * There is deliberately NO money column here: `booking` (:498-530) carries no
 * `biaya` column of any kind, which is why `BookingService` reads the
 * consultation fee off `dokter.biaya_konsultasi_online` instead. A source row
 * therefore supplies NO subtotal, and every amount on the invoice this service
 * writes comes from the `lines` argument.
 *
 * @param  array<string, mixed>  $ubah
 */
function inv44Booking(int $pasienId, array $ubah = []): int
{
    $dokterUserId = inv44User('Dokter Uji Booking', 'dokter', 'dokter')->getKey();
    $dokterId = inv44Dokter($dokterUserId);

    return inv44Catat('booking', (int) DB::table('booking')->insertGetId(array_merge([
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'dibuat_oleh_user_id' => $dokterUserId,
        'nomor_booking' => 'BFINV44'.Str::upper(Str::random(8)),
        'tipe_layanan' => 'video_call',
        'tanggal_kunjungan' => '2026-03-11',
        'slot_mulai' => '09:00:00',
        'slot_selesai' => '09:20:00',
        'status' => 'menunggu_pembayaran',
    ], $ubah)));
}

/**
 * A `dokter` row. `user_id`, `tipe`, `nomor_str` and `str_berlaku_sampai` are
 * the four NOT NULL columns with no default, measured off the live database.
 */
function inv44Dokter(int $userId, array $ubah = []): int
{
    return inv44Catat('dokter', (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-INV44-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ], $ubah)));
}

/** A `konsultasi` row, the `referensi_tipe = 'konsultasi'` source. `pasien_id` is :539. */
function inv44Konsultasi(int $pasienId, array $ubah = []): int
{
    $dokterUserId = inv44User('Dokter Uji Konsultasi', 'dokter', 'dokter')->getKey();
    $dokterId = inv44Dokter($dokterUserId);

    return inv44Catat('konsultasi', (int) DB::table('konsultasi')->insertGetId(array_merge([
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'room_id' => (string) Str::uuid(),
        'tipe' => 'chat',
        'status' => 'selesai',
    ], $ubah)));
}

/** A `resep` row, the `referensi_tipe = 'resep'` source. `pasien_id` is :747. */
function inv44Resep(int $pasienId, array $ubah = []): int
{
    $dokterUserId = inv44User('Dokter Uji Resep', 'dokter', 'dokter')->getKey();
    $dokterId = inv44Dokter($dokterUserId);

    return inv44Catat('resep', (int) DB::table('resep')->insertGetId(array_merge([
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'nomor_resep' => 'RXINV44'.Str::upper(Str::random(8)),
        'qr_token' => (string) Str::uuid(),
        'tipe' => 'digital',
        'status' => 'diverifikasi',
        'tanggal_resep' => '2026-03-11',
        'berlaku_sampai' => '2026-03-18',
    ], $ubah)));
}

/** A `pesanan_obat` row, the `referensi_tipe = 'pesanan_obat'` source. `pasien_id` is :801. */
function inv44PesananObat(int $pasienId, array $ubah = []): int
{
    $apotekId = inv44Catat('faskes', (int) DB::table('faskes')->insertGetId([
        'nama' => 'Apotek Uji Invoice',
        'tipe' => 'apotek',
        'alamat' => 'Jl. Uji Apotek No. 2, Jakarta',
        'status_aktif' => 1,
    ]));

    return inv44Catat('pesanan_obat', (int) DB::table('pesanan_obat')->insertGetId(array_merge([
        'nomor_pesanan' => 'POINV44'.Str::upper(Str::random(8)),
        'pasien_id' => $pasienId,
        'apotek_id' => $apotekId,
        'tipe' => 'resep_dokter',
        'status' => 'menunggu_pembayaran',
        'alamat_kirim' => 'Jl. Uji Invoice No. 1, Jakarta',
        'subtotal' => '120000.00',
        'biaya_kirim' => '20000.00',
        'total' => '140000.00',
    ], $ubah)));
}

/**
 * One invoice line as the service takes it.
 *
 * `harga_satuan` is a DECIMAL in the DDL and a JSON **string** on the wire, so
 * it is a string here and the rules reject a float rather than quietly casting
 * one - a float has already lost precision by the time it is parsed.
 *
 * @return array{harga_satuan: string, jumlah: int}
 */
function inv44Baris(string $hargaSatuan = '150000.00', int $jumlah = 1): array
{
    return ['harga_satuan' => $hargaSatuan, 'jumlah' => $jumlah];
}

/**
 * A `master_promo` row, and its code back.
 *
 * The code is returned rather than derived, because a fixture that guessed the
 * code would test a code nobody can guess: the row's own `kode` is the only
 * value the service will accept.
 *
 * @param  array<string, mixed>  $ubah
 */
function inv44Kode(array $ubah = []): string
{
    $promoId = inv44Promo($ubah);

    return (string) DB::table('master_promo')->where('id', $promoId)->value('kode');
}

/**
 * Run `$aksi` and return the `$tipe` it threw, or fail the test naming both.
 *
 * A test that lets the exception escape is a test whose failure message is a
 * stack trace; this turns "nothing was thrown" into a named assertion failure
 * at the call site, which is the difference between a red that says what is
 * wrong and a red that says `Error: Call to a member function on null`.
 *
 * @template T of Throwable
 *
 * @param  class-string<T>  $tipe
 * @return T
 */
function inv44Tangkap(callable $aksi, string $tipe): Throwable
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

// =====================================================================
// DDL assertions
// =====================================================================

function inv44Spec(): App\Support\Schema\SchemaSpec
{
    return (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
}

/**
 * @return list<string>
 */
function inv44Enum(string $table, string $column): array
{
    $type = inv44Spec()->table($table)->columns[$column]->type;

    preg_match_all("/'([^']+)'/", (string) $type, $matches);

    return $matches[1];
}

function inv44DdlLine(int $n): ?string
{
    $lines = file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);

    return $lines[$n - 1] ?? null;
}

/**
 * Assert `telemedicine_test.sql:$line` contains `$token`, so a citation is
 * proved against the file rather than quoted from the plan.
 */
function inv44AssertLine(int $line, string $token): void
{
    $actual = inv44DdlLine($line);

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
 * MISSING thing: `total` having no default, `referensi_id` having no foreign
 * key, `promo_redemption` having no unique key. A positive search cannot prove
 * an absence, so the absence is asserted directly against the line.
 */
function inv44AssertLineLacks(int $line, string $token): void
{
    $actual = inv44DdlLine($line);

    Assert::assertNotNull($actual, "telemedicine_test.sql:{$line} does not exist");

    Assert::assertStringNotContainsString(
        $token,
        (string) $actual,
        "telemedicine_test.sql:{$line} is [".(string) $actual.'] and unexpectedly contains ['.$token.']',
    );
}

// =====================================================================
// The two-connection dance, for the quota contention test
// =====================================================================

/**
 * Every row the committed-fixture dance has to remove again, recorded as the
 * fixtures are created.
 *
 * A hand-listed teardown is a second list of the fixtures, and the two drift -
 * which is how a `users` delete starts failing with error 1451 because a
 * `dokter` row this file created three helpers ago still points at it. The
 * collector is the fixture list, so a new helper that inserts a row registers
 * it by construction.
 *
 * @var array<string, list<int>>
 */
function inv44Catat(string $tabel, int $id): int
{
    $GLOBALS['inv44_dibuat'][$tabel][] = $id;

    return $id;
}

/**
 * @return array<string, list<int>>
 */
function inv44Dibuat(): array
{
    return (array) ($GLOBALS['inv44_dibuat'] ?? []);
}

function inv44Bersihkan(): void
{
    $GLOBALS['inv44_dibuat'] = [];
}

beforeEach(function (): void {
    inv44Bersihkan();
});

/**
 * Every table whose rows this file creates, in the order a foreign key
 * teardown must visit them: children before parents.
 *
 * @return list<string>
 */
function inv44Tabel(): array
{
    return [
        'promo_redemption',
        'invoice',
        'booking',
        'konsultasi',
        'resep',
        'resep_item',
        'pesanan_obat',
        'pesanan_obat_tracking',
        'dokter_jadwal',
        'dokter',
        'faskes',
        'user_roles',
        'user_devices',
        'user_refresh_tokens',
        'user_otp',
        'pasien',
        'master_promo',
        'master_metode_pembayaran',
        'users',
    ];
}

/**
 * Leave the `RefreshDatabase` wrapping transaction and open a second connection.
 *
 * The commit is what makes the fixtures visible to the other connection. The
 * second connection is a copy of the `mysql` config under a new name, and
 * `innodb_lock_wait_timeout = 1` makes a contended `SELECT ... FOR UPDATE` fail
 * fast and deterministically instead of stalling the suite.
 */
function inv44Mulai(): void
{
    if (DB::connection(INV44_KONEKSI_UTAMA)->transactionLevel() > 0) {
        DB::commit();
    }

    config([
        'database.connections.'.INV44_KONEKSI_LAWAN => config('database.connections.'.INV44_KONEKSI_UTAMA),
    ]);

    DB::purge(INV44_KONEKSI_LAWAN);

    $lawan = DB::connection(INV44_KONEKSI_LAWAN);

    $lawan->statement('SET SESSION innodb_lock_wait_timeout = 1');

    // MySQL's own default, stated explicitly on BOTH connections so the test
    // proves the current-read hardening under REPEATABLE READ rather than
    // inheriting whatever the server was configured with.
    $lawan->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::connection(INV44_KONEKSI_UTAMA)->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

    Assert::assertSame('REPEATABLE-READ', $lawan->selectOne('SELECT @@transaction_isolation AS v')->v);
    Assert::assertSame(
        'REPEATABLE-READ',
        DB::connection(INV44_KONEKSI_UTAMA)->selectOne('SELECT @@transaction_isolation AS v')->v
    );
}

/**
 * Delete every committed fixture this test created, then re-open a transaction
 * so `RefreshDatabase`'s teardown still finds an active one.
 *
 * `RefreshDatabase` checks `! $pdo->inTransaction()` at teardown and, if that
 * holds, sets `RefreshDatabaseState::$migrated = false` - which would force a
 * full `migrate:fresh` for every remaining test in the process. Re-opening the
 * transaction avoids that.
 *
 * The committed RBAC seed goes too, because {@see inv44Mulai()} committed the
 * wrapper transaction and `RbacSeeder`'s inserts are not idempotent: the next
 * test's `beforeEach` would collide on `roles.roles_nama_unique` (a real MySQL
 * 1062, and 31 of them across this file before the ordering was fixed).
 */
function inv44Selesai(): void
{
    // Roll back anything the test left open FIRST. A test that committed the
    // wrapper through {@see inv44Mulai()} and then failed may still be holding
    // a transaction, and deleting inside it would be undone by
    // `RefreshDatabase`'s rollback - so the cleanup would appear to run and
    // leave the committed RBAC seed behind, which then fails the NEXT test's
    // seed with 31 duplicate `roles` rows and hides the real cause entirely.
    if (DB::connection(INV44_KONEKSI_UTAMA)->transactionLevel() > 0) {
        DB::connection(INV44_KONEKSI_UTAMA)->rollBack();
    }

    $dibuat = inv44Dibuat();

    // The two tables whose rows are written by the SERVICE under test rather
    // than by a fixture, so the collector cannot see them. They are reachable
    // from what it does see: every invoice names a patient this test created
    // and every redemption names a promo it created. Without these two the
    // `pasien` delete fails with error 1451.
    DB::table('promo_redemption')->whereIn('promo_id', $dibuat['master_promo'] ?? [])->delete();
    DB::table('invoice')->whereIn('pasien_id', $dibuat['pasien'] ?? [])->delete();

    foreach (inv44Tabel() as $tabel) {
        $ids = $dibuat[$tabel] ?? [];

        if ($ids !== []) {
            DB::table($tabel)->whereIn('id', $ids)->delete();
        }
    }

    // Whatever is left that names a fixture indirectly. A source row with no
    // `invoice` behind it is harmless, but a `user_roles` row pointing at a
    // deleted `roles` row is not, so these go by USER and by nothing else.
    DB::table('user_roles')->whereIn('user_id', $dibuat['users'] ?? [])->delete();

    // The committed RBAC seed goes too, because {@see inv44Mulai()} committed
    // the wrapper transaction and `RbacSeeder`'s inserts are not idempotent.
    // In a `finally`, so a failure in the row cleanup above cannot leave the
    // catalogue half-deleted for the next test.
    try {
        foreach (['role_permissions', 'permissions', 'roles'] as $tabel) {
            DB::table($tabel)->delete();
        }
    } finally {
        if (DB::connection(INV44_KONEKSI_LAWAN)->transactionLevel() > 0) {
            DB::connection(INV44_KONEKSI_LAWAN)->rollBack();
        }

        DB::purge(INV44_KONEKSI_LAWAN);

        if (DB::connection(INV44_KONEKSI_UTAMA)->transactionLevel() === 0) {
            DB::beginTransaction();
        }

        inv44Bersihkan();
    }
}

/**
 * Run `$aksi` on the SECOND connection with the default connection pointed at
 * it, then put the default back.
 *
 * @template T
 *
 * @param  callable(): T  $aksi
 * @return T
 */
function inv44DiSisiLawan(callable $aksi): mixed
{
    config(['database.default' => INV44_KONEKSI_LAWAN]);

    try {
        return $aksi();
    } finally {
        config(['database.default' => INV44_KONEKSI_UTAMA]);
    }
}

/**
 * Is `$e` a MySQL lock-wait timeout, whatever Laravel wrapped it in?
 */
function inv44AdalahLockWait(Throwable $e): bool
{
    for ($tipe = $e; $tipe !== null; $tipe = $tipe->getPrevious()) {
        if ($tipe instanceof Illuminate\Database\QueryException
            && (int) ($tipe->errorInfo[1] ?? 0) === INV44_KODE_LOCK_WAIT) {
            return true;
        }
    }

    return false;
}
