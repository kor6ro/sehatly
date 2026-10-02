<?php

declare(strict_types=1);

use App\Models\Dokter;
use App\Models\Pasien;
use App\Models\User;
use App\Support\Rbac\RoleAssigner;
use App\Support\Uang\Uang;
use App\Support\WaktuIndonesia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| F14 admin-clinic fixtures
|--------------------------------------------------------------------------
|
| Shared by every `tests/Feature/Admin/*` file, and required once per file the
| way `pdp47-helpers.php` and `f12-helpers.php` are.
|
| **Every row below is written by the test.** `RefreshDatabase` performs one
| `migrate:fresh` per process WITHOUT `--seed`, so `master_spesialisasi` is empty
| and no doctor, booking or invoice pre-exists. That is what makes the counts in
| these files exact: a report asserting "exactly three bookings" cannot be
| satisfied by a leftover fixture from `DevFixtureSeeder`.
|
| **Nothing here reads `SELECT CURDATE()`.** `booking.tanggal_kunjungan` is a
| `DATE`, `dokter.str_berlaku_sampai` is a `DATE` and the F14 services compare
| both against the CLINIC's calendar day through {@see WaktuIndonesia}. The MySQL
| session is pinned to `+00:00`, so `CURDATE()` is the UTC day and a fixture
| built from it is a day behind the predicate it is testing for seven hours every
| day. `DokterDirectoryTest` documents the bug that produced; `f14HariIni()` is the
| fix, asked the same way the service asks it.
|
| **No `$fillable` exists anywhere in this codebase.** Eloquent's default guard is
| `['*']`, so `Model::create()` writes nothing, and the query builder does not run
| mutators. Every fixture therefore either assigns properties one at a time (for a
| model that carries a mutator or a cast worth exercising) or inserts an explicit
| column list through `DB::table()` (for a row no model is needed to write).
|
*/

/**
 * The one fixed 16-digit STR the masking assertions use.
 *
 * A fixed value, because `NikMasker` keeps the first four and last four characters:
 * `3312345678901234` -> `3312••••••••1234`. Any test that asserts a mask asserts it
 * against THIS number, so "the response published the credential" would be a
 * readable failure rather than "the response published some digits".
 */
const F14_NOMOR_STR = '3312345678901234';

/**
 * The masked form of {@see F14_NOMOR_STR}, stated once.
 *
 * The `U+2022` bullet run is the masker's own character, so this constant and
 * `NikMasker::mask()` cannot drift apart by accident - a hand-typed ASCII `*` would.
 */
const F14_NOMOR_STR_TERMASKING = '3312••••••••1234';

/**
 * Delete the `audit_log` rows the FIXTURES in this directory generated.
 *
 * `AuditObserver` is registered on every model reachable by foreign key from
 * `users`, so every `User` a fixture creates writes a `create` row of its own. That
 * is the mechanism working, not a defect - but it makes a count assertion about the
 * trail impossible unless the noise is removed first. Called after the fixtures and
 * before the rows a test is actually about.
 *
 * Only `tabel_target = 'users'` is removed, so a domain row a fixture produced (a
 * `dokter` create, say) survives and can still be asserted.
 */
function f14BersihkanAuditPengguna(): void
{
    DB::table('audit_log')->where('tabel_target', 'users')->delete();
}

/**
 * A `users` row and the model over it.
 *
 * `uuid` (:133), `nama_lengkap` (:135), `no_telepon` (:137) and
 * `kata_sandi_hash` (:138) are the four NOT NULL columns with no default, and
 * `tipe` is the seven-value ENUM at :139. `status` is `aktif` (:140) so no test in
 * this directory is ever excluded on the ACCOUNT's state - every exclusion under
 * test is on a `dokter` column, which is what the F14 rule is about.
 */
function f14User(string $nama, string $tipe = 'admin'): User
{
    $user = new User;
    $user->uuid = (string) Str::uuid();
    $user->nama_lengkap = $nama;
    $user->email = Str::lower(Str::random(12)).'@example.test';
    $user->no_telepon = '08'.random_int(100000000, 999999999);
    $user->kata_sandi_hash = password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
    $user->tipe = $tipe;
    $user->status = 'aktif';
    $user->bahasa = 'id';
    $user->save();

    return $user;
}

/**
 * An `admin` account: `users.tipe = 'admin'` plus the `admin` role.
 *
 * The role is not optional. `RbacCatalog::ROLE_PERMISSIONS['admin']` is what holds
 * `dokter.lihat`, `jadwal.lihat`, `audit.lihat`, `laporan.lihat`, `pdp.kelola` and F01's
 * `pasien.kelola`,
 * and `EnsurePermission` resolves the code against the seeded `permissions` table -
 * an account with the type but not the grant answers 403 at the middleware, and
 * every "admin can read" test would then be measuring the wrong thing.
 */
function f14Admin(string $nama = 'Admin Uji'): User
{
    $user = f14User($nama, 'admin');

    app(RoleAssigner::class)->assign((int) $user->getKey(), 'admin');

    return $user;
}

/**
 * A `superadmin` account: the type plus the role, which holds the whole catalogue.
 *
 * Used for the "the other account type the party gate admits" test rather than as
 * the default caller, so every admin test is proven on `admin` and not on the
 * account that can do everything.
 */
function f14Superadmin(): User
{
    $user = f14User('Superadmin Uji', 'superadmin');

    app(RoleAssigner::class)->assign((int) $user->getKey(), 'superadmin');

    return $user;
}

/**
 * A `pasien` account: the type, the `pasien` row, and the `pasien` role.
 *
 * `pasien` holds `dokter.lihat` and `jadwal.lihat` in `ROLE_PERMISSIONS`, so this
 * fixture is the one that proves the F14 reads are not merely permission-gated: a
 * patient who holds the read grant must STILL be refused, because the party gate
 * `tipe:admin,superadmin` is the narrower of the two and both are required.
 *
 * `nik` is `nik_cipher TEXT` and not a column any more, so nothing here passes a
 * NIK; `jenis_kelamin` (:225), `tanggal_lahir` (:226) and `alamat_lengkap` (:234)
 * are the NOT NULL columns with no default.
 *
 * @param  array<string, mixed>  $ubah
 * @return array{user: User, pasien: Pasien}
 */
function f14Pasien(array $ubah = []): array
{
    $user = f14User('Pasien Uji '.Str::upper(Str::random(6)), 'pasien');

    $pasienId = (int) DB::table('pasien')->insertGetId(array_merge([
        'user_id' => $user->getKey(),
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Admin No. 7, Jakarta',
    ], $ubah));

    app(RoleAssigner::class)->assign((int) $user->getKey(), 'pasien');

    return [
        'user' => User::query()->findOrFail($user->getKey()),
        'pasien' => Pasien::query()->findOrFail($pasienId),
    ];
}

/**
 * A `dokter` row, with every field that can make it INELIGIBLE to the public
 * directory set in one place.
 *
 * The defaults are the DDL's own (telemedicine_test.sql:409-435): `status_verifikasi`
 * is `pending` (`:427`), and both flags default to 1 (`:430`, `:426`). A test that
 * wants a fully eligible doctor therefore states `status_verifikasi` explicitly and
 * changes nothing else, and a test that wants an invisible one changes exactly one
 * field - which is what makes "one rule, one difference" checkable by reading.
 *
 * `nomor_str` and `nomor_sip` are UNIQUE (`:413`) and `user_id` is UNIQUE too (`:411`),
 * so the default credential is derived per row; a test that asserts the MASKED shape
 * passes {@see F14_NOMOR_STR} explicitly instead, because "3312••••••••1234" is only
 * a meaningful expectation against a known fixture.
 *
 * @param  array<string, mixed>  $ubah
 */
function f14Dokter(?User $user = null, array $ubah = []): Dokter
{
    $dokter = new Dokter;
    $dokter->user_id = ($user ?? f14User('Dokter Uji '.Str::upper(Str::random(6)), 'dokter'))->getKey();
    $dokter->tipe = 'dokter_umum';
    $dokter->nomor_str = '3312'.random_int(1000000000, 9999999999);
    $dokter->str_berlaku_sampai = '2099-12-31';
    $dokter->nomor_sip = '3112'.random_int(1000000000, 9999999999);
    $dokter->sip_berlaku_sampai = '2099-12-31';
    $dokter->nomor_ihs_satusehat = 'IHS-'.Str::upper(Str::random(8));
    $dokter->pengalaman_tahun = 5;
    $dokter->bio = 'Profil uji F14.';
    $dokter->biaya_konsultasi_online = '150000.00';
    $dokter->durasi_default_menit = 15;
    $dokter->rating_rata_rata = '4.80';
    $dokter->jumlah_ulasan = 12;
    $dokter->jumlah_konsultasi = 40;
    $dokter->tersedia_telemedisin = true;
    // The DDL DEFAULT, so a fixture with no override is a row that has never been
    // decided about - which is the state F14's verify endpoint exists to change.
    $dokter->status_verifikasi = 'pending';
    $dokter->file_str_url = 'https://example.test/f14/str.pdf';
    $dokter->file_sip_url = 'https://example.test/f14/sip.pdf';
    $dokter->status_aktif = true;

    foreach ($ubah as $kolom => $nilai) {
        $dokter->{$kolom} = $nilai;
    }

    $dokter->save();

    return $dokter;
}

/**
 * A `dokter_jadwal` row. The table (:470-:488) has no write endpoint outside F14,
 * so every pre-existing window in these tests is authored here.
 *
 * `hari` is `1` (Senin) and the window is 08:00-10:00 with no end date, which is
 * the shape the F14 form's default produces. Written through the query builder with
 * an explicit column list rather than through the model, so the fixture cannot
 * accidentally exercise a cast the service does not.
 *
 * @param  array<string, mixed>  $ubah
 */
function f14Jadwal(int $dokterId, array $ubah = []): int
{
    return (int) DB::table('dokter_jadwal')->insertGetId(array_merge([
        'dokter_id' => $dokterId,
        'faskes_id' => null,
        'tipe_layanan' => 'online',
        'hari' => 1,
        'jam_mulai' => '08:00:00',
        'jam_selesai' => '10:00:00',
        'durasi_slot_menit' => 15,
        // NULL is a real value here and means "no quota set"; `0` means "no
        // slots". The distinction is one of the things under test.
        'kuota_per_sesi' => null,
        'berlaku_mulai' => '2026-01-01',
        'berlaku_sampai' => null,
        'status_aktif' => 1,
    ], $ubah));
}

/**
 * A `dokter_libur` row.
 *
 * The table (:490-:496) is `dokter_id`, `tanggal`, `alasan` and an id, with no
 * timestamps - so `DokterLibur::$timestamps` is false and there is nothing else to
 * write. There is no time column to pass, which is the whole reason the F14 leave
 * surface is whole-day only.
 */
function f14Libur(int $dokterId, string $tanggal, ?string $alasan = 'Hari libur nasional'): int
{
    return (int) DB::table('dokter_libur')->insertGetId([
        'dokter_id' => $dokterId,
        'tanggal' => $tanggal,
        'alasan' => $alasan,
    ]);
}

/**
 * A `booking` row written directly.
 *
 * `booking` (:180-:230) has several NOT NULL columns with no default; the ones that
 * matter to the F14 reports are `tanggal_kunjungan` (a `DATE`, the report's key),
 * `status` (the booking report's group and the attendance report's group) and
 * `jadwal_id` (the FK that makes a window undeletable).
 *
 * @param  array<string, mixed>  $ubah
 */
function f14BookingRow(int $dokterId, array $ubah = []): int
{
    $pasienId = $ubah['pasien_id'] ?? f14Pasien()['pasien']->getKey();

    unset($ubah['pasien_id']);

    return (int) DB::table('booking')->insertGetId(array_merge([
        'nomor_booking' => 'BK-F14-'.Str::upper(Str::random(10)),
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'jadwal_id' => null,
        'faskes_id' => null,
        'tipe_layanan' => 'video_call',
        'tanggal_kunjungan' => f14HariIni(),
        'slot_mulai' => '08:00:00',
        'slot_selesai' => '08:15:00',
        'status' => 'terjadwal',
        'dibuat_oleh_user_id' => f14User('Pembuat Uji', 'admin')->getKey(),
    ], $ubah));
}

/**
 * An `invoice` row. `total` is `DECIMAL(14,2)` and is stored as a string, because
 * the revenue report sums the stored value and must not recompute it.
 *
 * `lunas_at` is a `DATETIME` holding a UTC instant under this application's pinned
 * session, so a fixture writes it through {@see WaktuIndonesia::toInstant()} - the
 * same converter `RentangHari` uses - and reads it back through the report. The
 * clinic-local WALL CLOCK is what the caller supplies (`tanggal_lunas` plus
 * `jam_lunas`), because that is the fact a report groups on; passing a UTC instant
 * here would be testing the wrong boundary.
 *
 * `jam_lunas` is the boundary case the revenue report's WIB grouping exists for:
 * a WIB time before 07:00 is the PREVIOUS day in UTC, which is exactly the hour a
 * naive implementation loses.
 *
 * @param  array<string, mixed>  $ubah
 */
function f14Invoice(int $pasienId, array $ubah = []): int
{
    $tanggalLunas = (string) ($ubah['tanggal_lunas'] ?? f14HariIni());
    $jamLunas = (string) ($ubah['jam_lunas'] ?? '10:00');

    unset($ubah['tanggal_lunas'], $ubah['jam_lunas']);

    return (int) DB::table('invoice')->insertGetId(array_merge([
        'nomor_invoice' => 'INV-F14-'.Str::upper(Str::random(10)),
        'pasien_id' => $pasienId,
        'referensi_tipe' => 'booking',
        'referensi_id' => 1,
        'subtotal' => '150000.00',
        'diskon' => '0.00',
        'biaya_admin' => '0.00',
        'biaya_pengiriman' => '0.00',
        'total' => '150000.00',
        'status' => 'lunas',
        'jatuh_tempo' => null,
        'lunas_at' => WaktuIndonesia::toInstant($tanggalLunas, $jamLunas)->utc()->format('Y-m-d H:i:s'),
    ], $ubah));
}

/**
 * Today, as the CLINIC sees it: `Asia/Jakarta`, `Y-m-d`.
 *
 * The single most important fixture in this directory. See the file header for why
 * `CURDATE()` is wrong here and {@see WaktuIndonesia} is right.
 */
function f14HariIni(): string
{
    return WaktuIndonesia::tanggal();
}

/**
 * A clinic date `n` days from today, as `Y-m-d`.
 *
 * Built in the clinic's zone and then formatted, so adding a day across a WIB
 * midnight moves the calendar day rather than the instant.
 */
function f14Hari(int $offset): string
{
    return Carbon::parse(f14HariIni(), WaktuIndonesia::ZONA)
        ->addDays($offset)
        ->format('Y-m-d');
}

/**
 * Make the next request carry a real Sanctum bearer token for `$user`.
 *
 * `forgetGuards()` first, for the reason `BookingTest::bkuAs()` documents: a cached
 * principal from an earlier request in the same test would decide the caller for
 * every later one. Void on purpose - a helper whose name starts with `as` that
 * returns the TestCase is a trap for the next reader.
 */
function f14As(User $user): void
{
    app('auth')->forgetGuards();

    test()->withToken($user->createToken('f14-test', ['*'], now()->addHour())->plainTextToken);
}

/**
 * Forget the token, so the next request is anonymous.
 *
 * `withoutHeader('Authorization')` rather than `flushHeaders()`, which would also
 * drop the `Accept: application/json` `json()` sets and make this a different test.
 */
function f14TanpaToken(): void
{
    app('auth')->forgetGuards();

    test()->withoutHeader('Authorization');
}

/**
 * The money string a report must publish for `$nilai`.
 *
 * `Uang::normal()` is the project's string-safe formatter, so a test asserting
 * "the report says 300000.00" states the expectation through the same helper the
 * report uses - and a float would be the thing under test otherwise.
 */
function f14Uang(string $nilai): string
{
    return Uang::normal($nilai);
}
