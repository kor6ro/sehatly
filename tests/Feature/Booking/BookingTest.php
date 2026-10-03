<?php

declare(strict_types=1);

use App\Http\Requests\Booking\BookingRequest;
use App\Http\Requests\Booking\CancelBookingRequest;
use App\Http\Requests\Booking\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Dokter;
use App\Models\Invoice;
use App\Models\Pasien;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\SlotAvailabilityService;
use App\Support\Dokter\StrBerlaku;
use App\Support\Dokumen\NomorDokumen;
use App\Support\NikCipher;
use App\Support\NikMasker;
use App\Support\Rbac\RbacCatalog;
use App\Support\Rbac\RoleAssigner;
use App\Support\Schema\SqlSchemaParser;
use App\Support\WaktuIndonesia;
use Database\Seeders\RbacSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Booking: create, list, cancel
|--------------------------------------------------------------------------
|
| Four routes, one service, and one rule that decides the whole file: the
| `dokter` row is the ONLY serialisation point for a booking, for every
| `tipe_layanan` and whether or not `jadwal_id` is supplied. The interleaving
| proof for that is `BookingConcurrencyTest`, in its own file because it has to
| leave the `RefreshDatabase` wrapping transaction; nothing in this file is a
| substitute for it.
|
| **These are Pest closure tests, not a PHPUnit class, and that is
| load-bearing.** `tests/Pest.php` binds `RefreshDatabase` to `->in('Feature')`,
| which covers closure tests and not a plain `class FooTest` in the same
| directory. See `SlotAvailabilityTest` and `DokterDirectoryTest` for the same
| note.
|
| **The helper prefix is `bku`.** Pest loads every test file into one process,
| so `slot*` (todo 26) and `direktori*` (todo 22) are already taken at file
| scope, and this file deliberately does NOT reuse `asUser()` or
| `patientAccount()` from `PasienProfileTest`: binding this todo's suite to that
| file's fixtures is exactly the cross-dependency the prefix convention exists
| to prevent. What IS shared is a CLASS rather than a global function, which is
| the only kind of sharing this project treats as safe.
|
| **Every date is fixed and in the future.** The STR rule compares against the
| CONSULTATION date and the elapsed-slot rule compares against today, so a
| `now()`-relative assertion would be asserting different things on different
| days. `BKU_TANGGAL` is a Monday so it matches the `hari = 1` every window in
| this file is written with, and a test asserts that before anything else.
|
*/

// =====================================================================
// Fixed dates and row builders
// =====================================================================

/** Monday, so `dokter_jadwal.hari = 1` under the DDL's own numbering at :475. */
const BKU_TANGGAL = '2026-12-07';
const BKU_TANGGAL_LAIN = '2026-12-14';
const BKU_HARI = 1;

/**
 * The `dokter.biaya_konsultasi_online` every fixture writes, and the shape it
 * comes back in: `DECIMAL(12,2)` over the wire is a JSON **string**, not a
 * number. (:420)
 */
const BKU_BIAYA = '150000.00';

/**
 * A `users` row. `uuid` (:133), `nama_lengkap` (:135), `no_telepon` (:137) and
 * `kata_sandi_hash` (:138) are the four NOT NULL columns with no default.
 * `status` is `aktif` (:140) so nothing here is ever excluded on the ACCOUNT's
 * state, and `tipe` is the seven-value ENUM at :139.
 */
function bkuUser(string $nama, string $tipe = 'pasien'): int
{
    return (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => $nama,
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => $tipe,
        'status' => 'aktif',
    ]);
}

/**
 * A `pasien` row. `jenis_kelamin` (:225), `tanggal_lahir` (:226) and
 * `alamat_lengkap` (:234) are the NOT NULL columns with no default.
 *
 * `nik` is NOT a column any more: telemedicine_test.sql:222 is
 * `nik_cipher TEXT` since the NIK cipher migration. A caller still passes
 * `['nik' => $sixteen]` and the helper ENCRYPTS it, because the query builder
 * does not run Eloquent mutators and writing the plaintext into the payload
 * column would produce a row no resource can read.
 *
 * @param  array<string, mixed>  $ubah
 */
function bkuPasienRow(int $userId, array $ubah = []): int
{
    $nik = $ubah['nik'] ?? null;

    unset($ubah['nik']);

    if ($nik !== null) {
        $ubah['nik_cipher'] = NikCipher::encrypt((string) $nik);
    }

    return (int) DB::table('pasien')->insertGetId(array_merge([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Booking No. 3, Jakarta',
    ], $ubah));
}

/**
 * A patient account: a `users` row of `tipe = 'pasien'`, its `pasien` row, and
 * the `pasien` role that `POST /auth/sign-up` grants through `RoleAssigner`.
 *
 * The role is not optional: `POST /booking` carries `permission:booking.buat`,
 * and `RbacCatalog::ROLE_PERMISSIONS['pasien']` is what holds it. An account
 * without the grant answers 403 at the middleware, and every create test would
 * then be measuring the wrong thing.
 *
 * @param  array<string, mixed>  $ubah
 * @return array{user: User, pasien: Pasien}
 */
function bkuPatient(array $ubah = []): array
{
    $userId = bkuUser('Pasien Uji '.Str::upper(Str::random(6)));
    $pasienId = bkuPasienRow($userId, $ubah);

    app(RoleAssigner::class)->assign($userId, 'pasien');

    return [
        'user' => User::query()->findOrFail($userId),
        'pasien' => Pasien::query()->findOrFail($pasienId),
    ];
}

/**
 * A doctor account: a `users` row of `tipe = 'dokter'`, its `dokter` row, and
 * the `dokter` role. `RbacCatalog::ROLE_PERMISSIONS['dokter']` holds
 * `booking.lihat` and `booking.batal` but deliberately NOT `booking.buat`, which
 * the route test asserts rather than leaving as a comment.
 *
 * `durasi_default_menit` is 20 and never 15 on purpose. It is what an INSTANT
 * booking derives `slot_selesai` from, while a scheduled one derives it from
 * `dokter_jadwal.durasi_slot_menit` (:478), which is 15 in every window here. A
 * test that expected 15 from a 20-minute default would fail, and that is the
 * point.
 *
 * @param  array<string, mixed>  $ubah
 * @return array{user: User, dokter: Dokter}
 */
function bkuDoctor(array $ubah = []): array
{
    $userId = bkuUser('Dokter Uji '.Str::upper(Str::random(6)), 'dokter');
    $dokterId = (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-BKU-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'pengalaman_tahun' => 5,
        'biaya_konsultasi_online' => BKU_BIAYA,
        'durasi_default_menit' => 20,
        'tersedia_telemedisin' => 1,
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ], $ubah));

    app(RoleAssigner::class)->assign($userId, 'dokter');

    return [
        'user' => User::query()->findOrFail($userId),
        'dokter' => Dokter::query()->findOrFail($dokterId),
    ];
}

/**
 * A `dokter` row with NO `dokter_jadwal` row at all.
 *
 * `booking.jadwal_id` is `BIGINT UNSIGNED NULL` (:504) while `slot_mulai` and
 * `slot_selesai` are `NOT NULL` (:508-:509), so an instant booking is
 * representable and legal. This is the fixture that proves the two paths
 * coexist, and therefore the fixture the `dokter` row lock has to cover.
 *
 * @param  array<string, mixed>  $ubah
 * @return array{user: User, dokter: Dokter}
 */
function bkuDoctorTanpaJadwal(array $ubah = []): array
{
    return bkuDoctor($ubah);
}

/**
 * An account that holds NEITHER a `pasien` row NOR a `dokter` row, for the
 * 403-for-the-caller half of the ownership rule.
 *
 * @return array{user: User}
 */
function bkuPemanggilLain(string $tipe = 'admin'): array
{
    $userId = bkuUser('Pemanggil Uji '.Str::upper(Str::random(6)), $tipe);

    app(RoleAssigner::class)->assign($userId, $tipe === 'superadmin' ? 'superadmin' : 'admin');

    return ['user' => User::query()->findOrFail($userId)];
}

/**
 * A `dokter_jadwal` row. `dokter_jadwal` (:470-:488) has no write endpoint in
 * this contract, so every window in this file is authored by the test.
 *
 * @param  array<string, mixed>  $ubah
 */
function bkuJadwal(int $dokterId, array $ubah = []): int
{
    return (int) DB::table('dokter_jadwal')->insertGetId(array_merge([
        'dokter_id' => $dokterId,
        'faskes_id' => null,
        'tipe_layanan' => 'online',
        'hari' => BKU_HARI,
        'jam_mulai' => '09:00:00',
        'jam_selesai' => '10:00:00',
        'durasi_slot_menit' => 15,
        'kuota_per_sesi' => null,
        'berlaku_mulai' => '2020-01-01',
        'berlaku_sampai' => null,
        'status_aktif' => 1,
    ], $ubah));
}

/**
 * A `dokter_libur` row. The table (:490-:496) has no timestamps, which is why
 * `DokterLibur::$timestamps` is false.
 */
function bkuLibur(int $dokterId, string $tanggal): void
{
    DB::table('dokter_libur')->insert([
        'dokter_id' => $dokterId,
        'tanggal' => $tanggal,
        'alasan' => 'Hari libur nasional',
    ]);
}

/**
 * A `booking` row written directly, for the Collision fixtures.
 *
 * @param  array<string, mixed>  $ubah
 */
function bkuBookingRow(int $dokterId, string $mulai, string $selesai, array $ubah = []): int
{
    $pasienId = $ubah['pasien_id'] ?? bkuPatient()['pasien']->getKey();
    $dibuatOleh = $ubah['dibuat_oleh_user_id'] ?? bkuUser('Pembuat Uji', 'admin');

    unset($ubah['pasien_id'], $ubah['dibuat_oleh_user_id']);

    return (int) DB::table('booking')->insertGetId(array_merge([
        'nomor_booking' => 'BK-FIXTURE-'.Str::upper(Str::random(10)),
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'jadwal_id' => null,
        'faskes_id' => null,
        'tipe_layanan' => 'video_call',
        'tanggal_kunjungan' => BKU_TANGGAL,
        'slot_mulai' => $mulai,
        'slot_selesai' => $selesai,
        'status' => 'terjadwal',
        'dibuat_oleh_user_id' => $dibuatOleh,
    ], $ubah));
}

/**
 * Make the next request carry a real Sanctum bearer token for `$user`.
 *
 * `app('auth')->forgetGuards()` first, for the reason `PasienProfileTest` gives
 * in full: a cached principal from an earlier request in the same test would
 * decide the caller for every later one. The return value is deliberately void --
 * `withToken()` returns the TestCase, and a helper whose name ends in `As` that
 * hands back a TestCase is a trap for the next reader.
 */
function bkuAs(User $user): void
{
    app('auth')->forgetGuards();

    test()->withToken($user->createToken('booking-test', ['*'], now()->addHour())->plainTextToken);
}

/**
 * A minimal valid `POST /booking` body, with overrides merged in.
 *
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function bkuPayload(int $dokterId, array $ubah = []): array
{
    return array_merge([
        'dokter_id' => $dokterId,
        'jadwal_id' => null,
        'tipe_layanan' => 'video_call',
        'tanggal_kunjungan' => BKU_TANGGAL,
        'slot_mulai' => '09:00:00',
        'keluhan' => 'Sakit kepala sejak tiga hari.',
    ], $ubah);
}

/**
 * Today, as the **clinic** sees it, and as a value whose `setTime()` means
 * Jakarta time.
 *
 * `booking.tanggal_kunjungan` is a `DATE` and `booking.slot_mulai` is a `TIME`;
 * `docs/timezone-policy.md` rule 2 makes both Asia/Jakarta wall clocks. The two
 * boundaries under test here therefore both need the clinic's clock, and a test
 * that froze "12:00" in UTC would be freezing 19:00 at the clinic and asserting
 * against the wrong end of the schedule.
 *
 * It was `SELECT CURDATE()`, which reads the *session's* wall clock: right only
 * by accident while the MySQL session inherited this host's WIB, and the UTC day
 * the moment todo 51 pinned the connection to `+00:00`.
 */
function bkuHariIni(): Carbon
{
    return Carbon::parse(WaktuIndonesia::tanggal(), WaktuIndonesia::ZONA)->startOfDay();
}

/**
 * The `booking` row a create just wrote, read back through the table rather
 * than through the response, so the assertion is about storage.
 */
function bkuBookingTerakhir(int $pasienId): Booking
{
    return Booking::query()->where('pasien_id', $pasienId)->orderByDesc('id')->firstOrFail();
}

/**
 * The invoice a booking created, or null.
 */
function bkuInvoice(int $bookingId): ?Invoice
{
    return Invoice::query()
        ->where('referensi_tipe', 'booking')
        ->where('referensi_id', $bookingId)
        ->first();
}

/**
 * The eight `booking.status` values, read out of the DDL by the project's own
 * parser rather than transcribed, so a ninth value breaks a test instead of
 * quietly joining one service and not the other.
 *
 * @return list<string>
 */
function bkuStatusDdl(): array
{
    $kolom = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'))
        ->table('booking')?->columns['status'] ?? null;

    expect($kolom)->not->toBeNull()
        ->and($kolom->type)->toStartWith('enum(');

    return array_map(
        static fn (string $nilai): string => trim($nilai, "'"),
        explode(',', (string) substr((string) $kolom->type, strlen('enum('), -1)),
    );
}

// =====================================================================
// Setup
// =====================================================================

beforeEach(function (): void {
    // `RbacSeeder` writes `roles`, `permissions` and `role_permissions`.
    // `RoleAssigner::assign()` resolves a role name against `roles` and throws a
    // `LogicException` when the catalogue names a row that is not there, and
    // `EnsurePermission` resolves every `permission:` code against `permissions`.
    // Without it, every booking route answers 500 or 403.
    $this->seed(RbacSeeder::class);

    // `master_hubungan_keluarga` has a `TINYINT UNSIGNED PRIMARY KEY` with no
    // `AUTO_INCREMENT` and this todo does not seed master data, so
    // `pasien_anggota_keluarga.hubungan_id` (:265) has a real foreign key to a
    // table that is empty. One row is planted on demand, `insertOrIgnore` so a
    // future seeder landing here first is not fought with.
    DB::table('master_hubungan_keluarga')->insertOrIgnore([
        ['id' => 1, 'nama' => 'Pasangan'],
    ]);
});

// =====================================================================
// The wiring
// =====================================================================

test('the four booking routes are registered under /api/v1 with the middleware the plan names', function (): void {
    $peta = [];

    foreach (app('router')->getRoutes()->getRoutes() as $rute) {
        if (! $rute instanceof RoutingRoute) {
            continue;
        }

        $peta[$rute->methods()[0].' '.$rute->uri()] = $rute->gatherMiddleware();
    }

    $pintu = [
        'POST api/v1/booking',
        'GET api/v1/pasien/booking',
        'GET api/v1/dokter/booking',
        'PUT api/v1/booking/{id}/batalkan',
    ];

    foreach ($pintu as $satu) {
        expect($peta)->toHaveKey($satu);
    }

    // `auth:sanctum` on all four, so a route added later cannot be unprotected
    // by omission -- the reason the auth group names it explicitly.
    expect($peta['POST api/v1/booking'])->toContain('auth:sanctum')
        ->and($peta['GET api/v1/pasien/booking'])->toContain('auth:sanctum')
        ->and($peta['GET api/v1/dokter/booking'])->toContain('auth:sanctum')
        ->and($peta['PUT api/v1/booking/{id}/batalkan'])->toContain('auth:sanctum');

    // The three `booking.*` codes of `RbacCatalog::PERMISSIONS` are real, so they
    // are written rather than invented. The doctor-side list is the one route the
    // plan names `tipe:dokter` on, because "which account type is this" is
    // exactly the question it asks.
    expect($peta['POST api/v1/booking'])->toContain('permission:booking.buat')
        ->and($peta['GET api/v1/pasien/booking'])->toContain('permission:booking.lihat')
        ->and($peta['GET api/v1/dokter/booking'])->toContain('permission:booking.lihat')
        ->and($peta['GET api/v1/dokter/booking'])->toContain('tipe:dokter')
        ->and($peta['PUT api/v1/booking/{id}/batalkan'])->toContain('permission:booking.batal');

    // `dokter` holds `booking.lihat` and `booking.batal` but NOT `booking.buat`.
    // The asymmetry is deliberate and is why a doctor may read and cancel
    // bookings without being able to create one for themselves.
    expect(RbacCatalog::ROLE_PERMISSIONS['dokter'])->toContain('booking.lihat')
        ->and(RbacCatalog::ROLE_PERMISSIONS['dokter'])->toContain('booking.batal')
        ->and(RbacCatalog::ROLE_PERMISSIONS['dokter'])->not->toContain('booking.buat');
});

test('the request classes exist and carry the DDL vocabularies', function (): void {
    // The plan writes `App\Http\Requests\{StoreBookingRequest, CancelBookingRequest}`.
    // The repository's own convention, which this file follows, is one
    // sub-namespace per module (`Requests\Auth\`, `Requests\Pasien\`,
    // `Requests\Dokter\`), and all of those live under `app/Http/Requests/` as
    // the brief requires. Reported rather than silently deviated from.
    expect(class_exists(StoreBookingRequest::class))->toBeTrue()
        ->and(class_exists(CancelBookingRequest::class))->toBeTrue()
        ->and(class_exists(BookingRequest::class))->toBeTrue();

    // `booking.tipe_layanan` ENUM('chat','video_call','kunjungan_klinik','home_visit')
    // at :506, transcribed here and re-checked against the DDL below. `Rule::in`
    // and not `Rule::enum`, for the reason `AlergiRequest` gives: on
    // laravel/framework 13.33 both a string `enum:` cast and `Rule::enum` need a
    // real PHP enum class, and this project has none for a MySQL ENUM.
    expect(BookingRequest::TIPE_LAYANAN)->toBe(['chat', 'video_call', 'kunjungan_klinik', 'home_visit']);

    // `booking.status` is the eight-value ENUM at :515-:516. Six consume a slot
    // and two release it, and the two are REUSED from `SlotAvailabilityService`
    // rather than restated, which is asserted here as an identity.
    expect(BookingRequest::STATUS_TIDAK_MENGKONSUMSI)
        ->toBe(SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI)
        ->toHaveCount(2);
});

test('the fixed dates land on the weekday the DDL comment at :475 names', function (): void {
    // `hari TINYINT UNSIGNED NOT NULL COMMENT '0=Minggu s.d. 6=Sabtu'` (:475) is
    // PHP's own `date('w')` numbering, and every window in this file is written
    // with `hari = 1`. If the two ever diverged, every slot assertion below would
    // quietly stop generating a slot and most of them would pass vacuously.
    expect(Carbon::parse(BKU_TANGGAL)->dayOfWeek)->toBe(BKU_HARI)
        ->and(Carbon::parse(BKU_TANGGAL_LAIN)->dayOfWeek)->toBe(BKU_HARI)
        ->and(Carbon::parse(BKU_TANGGAL)->translatedFormat('l'))->toBe('Monday')
        // A weekday the doctor does not work, for the window tests.
        ->and(Carbon::parse(BKU_TANGGAL)->addDay()->dayOfWeek)->not->toBe(BKU_HARI);
});

// =====================================================================
// POST /api/v1/booking -- the happy path
// =====================================================================

test('a scheduled booking is created from a slot the schedule actually publishes', function (): void {
    $dokter = bkuDoctor();
    $jadwal = bkuJadwal($dokter['dokter']->getKey());
    $pasien = bkuPatient();

    bkuAs($pasien['user']);

    $response = test()->postJson('/api/v1/booking', bkuPayload(
        $dokter['dokter']->getKey(),
        ['jadwal_id' => $jadwal],
    ));

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.booking.nomor_booking', fn (string $n): bool => (bool) preg_match('/^BK\d{8}[A-Z0-9]{6}$/', $n))
        ->assertJsonPath('data.booking.dokter_id', (int) $dokter['dokter']->getKey())
        ->assertJsonPath('data.booking.jadwal_id', $jadwal)
        ->assertJsonPath('data.booking.tipe_layanan', 'video_call')
        ->assertJsonPath('data.booking.tanggal_kunjungan', BKU_TANGGAL)
        ->assertJsonPath('data.booking.slot_mulai', '09:00:00')
        // The request never sent `slot_selesai`: it is DERIVED from the schedule
        // row's `durasi_slot_menit` (:478), which is 15 here, and NOT from
        // `dokter.durasi_default_menit` (:422), which is 20.
        ->assertJsonPath('data.booking.slot_selesai', '09:15:00')
        ->assertJsonPath('data.booking.status', 'menunggu_pembayaran')
        ->assertJsonPath('data.booking.is_rujukan', false)
        ->assertJsonPath('data.booking.is_konsultasi_lanjutan', false)
        // `nomor_antrian SMALLINT UNSIGNED NULL` (:510) has no counter, no
        // uniqueness and no per-doctor sequence anywhere in the schema, so it is
        // left null rather than filled with a guess.
        ->assertJsonPath('data.booking.nomor_antrian', null)
        ->assertJsonPath('data.booking.dibatalkan_oleh', null);

    $booking = bkuBookingTerakhir((int) $pasien['pasien']->getKey());

    // `dibuat_oleh_user_id` (:519) is written from the authenticated account,
    // never from the body, and is not in the validated rules at all.
    expect((int) $booking->dibuat_oleh_user_id)->toBe((int) $pasien['user']->getKey())
        ->and((int) $booking->pasien_id)->toBe((int) $pasien['pasien']->getKey())
        ->and($booking->status)->toBe('menunggu_pembayaran')
        ->and((string) $booking->slot_selesai)->toBe('09:15:00')
        // `faskes_id` is `BIGINT UNSIGNED NULL` (:505) and mirrors
        // `dokter_jadwal.faskes_id` (:473), whose NULL means "online only".
        ->and($booking->faskes_id)->toBeNull();
});

test('a clinic window carries its faskes onto the booking', function (): void {
    // `dokter_jadwal.faskes_id BIGINT UNSIGNED NULL COMMENT 'NULL = layanan online
    // murni'` (:473) is the only place the venue of a scheduled visit is
    // recorded, and `booking.faskes_id` (:505) is the same idea. A clinic visit
    // with a null venue would be a visit to nowhere.
    $faskesId = (int) DB::table('faskes')->insertGetId([
        'kode_faskes' => 'FS-BKU-'.Str::upper(Str::random(6)),
        'nama' => 'Klinik Uji Booking',
        'tipe' => 'klinik',
        'alamat' => 'Jl. Uji Booking No. 1, Jakarta',
    ]);

    $dokter = bkuDoctor();
    $jadwal = bkuJadwal($dokter['dokter']->getKey(), ['faskes_id' => $faskesId, 'tipe_layanan' => 'klinik']);

    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    test()->postJson('/api/v1/booking', bkuPayload(
        $dokter['dokter']->getKey(),
        ['jadwal_id' => $jadwal, 'tipe_layanan' => 'kunjungan_klinik'],
    ))->assertCreated()->assertJsonPath('data.booking.faskes_id', $faskesId);
});

test('an INSTANT booking needs no schedule row and derives its length from the doctor default', function (): void {
    // `booking.jadwal_id BIGINT UNSIGNED NULL` (:504) exists precisely so a
    // `chat` / `video_call` request can be booked without a `dokter_jadwal` row,
    // and `dokter_jadwal.tipe_layanan` (:474) has only three values, none of them
    // `chat`, so an instant chat is NOT expressible as a schedule row at all.
    // This is the case the `dokter` row lock exists for.
    $dokter = bkuDoctorTanpaJadwal();

    expect(DB::table('dokter_jadwal')->where('dokter_id', $dokter['dokter']->getKey())->count())->toBe(0);

    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    test()->postJson('/api/v1/booking', bkuPayload(
        $dokter['dokter']->getKey(),
        ['tipe_layanan' => 'chat', 'slot_mulai' => '14:00:00'],
    ))
        ->assertCreated()
        ->assertJsonPath('data.booking.jadwal_id', null)
        ->assertJsonPath('data.booking.tipe_layanan', 'chat')
        ->assertJsonPath('data.booking.slot_mulai', '14:00:00')
        // `dokter.durasi_default_menit` (:422) is 20 in this fixture, so the
        // instant booking is twenty minutes long where a scheduled one for the
        // same doctor is fifteen. The two lengths come from two different
        // columns, and the two tests are what keeps them apart.
        ->assertJsonPath('data.booking.slot_selesai', '14:20:00');
});

test('the create writes a booking invoice at the doctor online fee with no discount', function (): void {
    $dokter = bkuDoctor();
    bkuJadwal($dokter['dokter']->getKey());

    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    // `is_rujukan: true` and `is_konsultasi_lanjutan: true` are recorded on the
    // booking and change NOTHING about the money. The spec says a referral
    // applies no automatic discount, and this is the assertion that stops a
    // later change from "helpfully" adding one.
    test()->postJson('/api/v1/booking', bkuPayload(
        $dokter['dokter']->getKey(),
        ['is_rujukan' => true, 'is_konsultasi_lanjutan' => true],
    ))->assertCreated()
        ->assertJsonPath('data.booking.is_rujukan', true)
        ->assertJsonPath('data.booking.is_konsultasi_lanjutan', true);

    $booking = bkuBookingTerakhir((int) $pasien['pasien']->getKey());
    $invoice = bkuInvoice((int) $booking->getKey());

    expect($invoice)->not->toBeNull()
        ->and($invoice->referensi_tipe)->toBe('booking')
        ->and((int) $invoice->referensi_id)->toBe((int) $booking->getKey())
        ->and($invoice->status)->toBe('menunggu_pembayaran')
        // `invoice.total DECIMAL(14,2) NOT NULL` (:946) and every money column in
        // this schema is a DECIMAL, so it arrives as a JSON **string**.
        ->and((string) $invoice->total)->toBe(BKU_BIAYA)
        ->and((string) $invoice->subtotal)->toBe(BKU_BIAYA)
        // The whole point: a referral is a fact about provenance, not a coupon.
        ->and((string) $invoice->diskon)->toBe('0.00')
        ->and((string) $invoice->biaya_admin)->toBe('0.00')
        ->and((string) $invoice->biaya_pengiriman)->toBe('0.00')
        // `dokter.biaya_konsultasi_online DECIMAL(12,2) NOT NULL DEFAULT 0` (:420)
        ->and((string) $dokter['dokter']->biaya_konsultasi_online)->toBe(BKU_BIAYA)
        ->and((int) $invoice->pasien_id)->toBe((int) $pasien['pasien']->getKey())
        // `invoice.nomor_invoice VARCHAR(30) NOT NULL UNIQUE` (:938), with the
        // same `PREFIX` + `Ymd` + 6 shape the plan names for the booking number.
        ->and((string) $invoice->nomor_invoice)->toMatch('/^INV\d{8}[A-Z0-9]{6}$/');
});

test('lampiran_keluhan round-trips as the {nama,url} list the JSON column stores', function (): void {
    // `booking.lampiran_keluhan JSON NULL` (:512) carries no shape constraint in
    // the schema, so the `{nama, url}` contract is the API's and has to be
    // validated here rather than assumed.
    $dokter = bkuDoctor();
    bkuJadwal($dokter['dokter']->getKey());
    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    $lampiran = [
        ['nama' => 'surat-rujukan.png', 'url' => 'https://cdn.example.test/surat-rujukan.png'],
        ['nama' => 'hasil-lab.pdf', 'url' => 'https://cdn.example.test/hasil-lab.pdf'],
    ];

    test()->postJson('/api/v1/booking', bkuPayload(
        $dokter['dokter']->getKey(),
        ['lampiran_keluhan' => $lampiran],
    ))->assertCreated()->assertJsonPath('data.booking.lampiran_keluhan', $lampiran);

    // A malformed element is a 422 on the indexed key rather than a silently
    // stored half-record.
    test()->postJson('/api/v1/booking', bkuPayload(
        $dokter['dokter']->getKey(),
        ['lampiran_keluhan' => [['nama' => 'tanpa-url.pdf']]],
    ))->assertUnprocessable()->assertJsonStructure(['errors' => ['lampiran_keluhan.0.url']]);
});

// =====================================================================
// POST /api/v1/booking -- validation
// =====================================================================

test('every closed vocabulary is a 422 with its own errors key', function (): void {
    $dokter = bkuDoctor();
    bkuJadwal($dokter['dokter']->getKey());
    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    $kasus = [
        ['tipe_layanan', 'broom', 'tipe_layanan'],
        // `Carbon::createFromFormat('Y-m-d', '2026-13-45')` does NOT fail: PHP
        // overflows month 13 and day 45 into 2027-02-14. A 422 is only reachable
        // if the validator is a real format check, which is why this is asserted
        // over HTTP and not on a service helper.
        ['tanggal_kunjungan', '2026-13-45', 'tanggal_kunjungan'],
        ['slot_mulai', '99:99:00', 'slot_mulai'],
        ['dokter_id', 'abc', 'dokter_id'],
    ];

    foreach ($kasus as [$kolom, $buruk, $kunci]) {
        test()->postJson('/api/v1/booking', bkuPayload($dokter['dokter']->getKey(), [$kolom => $buruk]))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['message', 'errors' => [$kunci]]);
    }

    // A missing required key is a 422 too, rather than a MySQL 1364 turning into
    // a 500.
    foreach (['dokter_id', 'tipe_layanan', 'tanggal_kunjungan', 'slot_mulai'] as $wajib) {
        $tanpa = bkuPayload($dokter['dokter']->getKey());
        unset($tanpa[$wajib]);

        test()->postJson('/api/v1/booking', $tanpa)
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => [$wajib]]);
    }
});

test('a patient cannot name the patient, and a schedule row belonging to another doctor is a 422', function (): void {
    $dokter = bkuDoctor();
    $jadwal = bkuJadwal($dokter['dokter']->getKey());

    $dokterLain = bkuDoctor();
    $jadwalMilikOrangLain = bkuJadwal($dokterLain['dokter']->getKey());

    $pasien = bkuPatient();
    $orangLain = bkuPatient();

    bkuAs($pasien['user']);

    // `pasien_id` is the tenant key, written from the caller's own row and never
    // read from a body. It is `prohibited` rather than merely absent from the
    // rules, so a client that tries is told so instead of being silently ignored
    // -- and, more importantly, so a test can prove the refusal exists.
    test()->postJson('/api/v1/booking', bkuPayload(
        $dokter['dokter']->getKey(),
        ['pasien_id' => $orangLain['pasien']->getKey()],
    ))->assertUnprocessable()->assertJsonStructure(['errors' => ['pasien_id']]);

    // A `dokter_jadwal` row belonging to a different doctor. The rule is
    // `jadwal_id` scoped to the booking's own `dokter_id`, and it is a 422 on
    // the field rather than a 404 on the row: the caller named a resource that
    // exists and the thing wrong with it is that it belongs to somebody else.
    test()->postJson('/api/v1/booking', bkuPayload(
        $dokter['dokter']->getKey(),
        ['jadwal_id' => $jadwalMilikOrangLain],
    ))->assertUnprocessable()->assertJsonStructure(['errors' => ['jadwal_id']]);

    // And the pairing that IS coherent still works, so the rule above is not
    // simply refusing every `jadwal_id`.
    test()->postJson('/api/v1/booking', bkuPayload(
        $dokter['dokter']->getKey(),
        ['jadwal_id' => $jadwal],
    ))->assertCreated();

    expect((int) bkuBookingTerakhir((int) $pasien['pasien']->getKey())->pasien_id)
        ->toBe((int) $pasien['pasien']->getKey());
});

test('a family member must belong to the caller', function (): void {
    // `booking.anggota_keluarga_id BIGINT UNSIGNED NULL COMMENT 'NULL = untuk
    // pasien sendiri'` (:502). The column is nullable, but "whose family member"
    // is a question only the owning patient can answer, so the rule is a
    // `PasienRecordAccess::anggotaKeluargaQuery()` scope and not a bare
    // `exists:pasien_anggota_keluarga,id`.
    $pasien = bkuPatient();
    $orangLain = bkuPatient();

    $milikSendiri = (int) DB::table('pasien_anggota_keluarga')->insertGetId([
        'pasien_id' => $pasien['pasien']->getKey(),
        'hubungan_id' => 1,
        'nama_lengkap' => 'Siti Aminah',
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1965-08-09',
    ]);

    $milikOrangLain = (int) DB::table('pasien_anggota_keluarga')->insertGetId([
        'pasien_id' => $orangLain['pasien']->getKey(),
        'hubungan_id' => 1,
        'nama_lengkap' => 'Budi Orang Lain',
        'jenis_kelamin' => 'L',
        'tanggal_lahir' => '1962-01-01',
    ]);

    $dokter = bkuDoctor();
    bkuJadwal($dokter['dokter']->getKey());
    bkuAs($pasien['user']);

    test()->postJson('/api/v1/booking', bkuPayload(
        $dokter['dokter']->getKey(),
        ['anggota_keluarga_id' => $milikOrangLain],
    ))->assertUnprocessable()->assertJsonStructure(['errors' => ['anggota_keluarga_id']]);

    // The booking is still FOR the patient row, not for the family member:
    // `pasien_id` is `NOT NULL` and names the account holder, and the family
    // member is the SUBJECT of the visit. Asserted because the alternative
    // reading -- writing the member's id into `pasien_id` -- is the mistake this
    // column pair exists to make impossible.
    test()->postJson('/api/v1/booking', bkuPayload(
        $dokter['dokter']->getKey(),
        ['anggota_keluarga_id' => $milikSendiri],
    ))->assertCreated()
        ->assertJsonPath('data.booking.pasien_id', (int) $pasien['pasien']->getKey())
        ->assertJsonPath('data.booking.anggota_keluarga_id', $milikSendiri);
});

// =====================================================================
// Ownership: 401 for anonymous, 403 for the caller, 404 for the row
// =====================================================================

test('an anonymous caller is 401 and an account with no patient row is 403', function (): void {
    $dokter = bkuDoctor();
    bkuJadwal($dokter['dokter']->getKey());

    test()->postJson('/api/v1/booking', bkuPayload($dokter['dokter']->getKey()))
        ->assertUnauthorized()
        ->assertJsonPath('success', false);

    // The 403 is about the CALLER: a `dokter`-typed account has no `pasien` row,
    // so it owns nothing this route can act on, and the answer says so without
    // disclosing anything about another patient.
    bkuAs(bkuDoctor()['user']);

    test()->postJson('/api/v1/booking', bkuPayload($dokter['dokter']->getKey()))
        ->assertForbidden()
        ->assertJsonPath('success', false);
});

test('the doctor-side list refuses a non-doctor before the controller runs', function (): void {
    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    test()->getJson('/api/v1/dokter/booking')
        ->assertForbidden()
        ->assertJsonPath('success', false);

    bkuAs(bkuDoctor()['user']);
    test()->getJson('/api/v1/dokter/booking')->assertOk();

    // A `dokter`-typed account with NO `dokter` row is refused by
    // `PasienRecordAccess::ownDokter()` returning null, which is 403 rather than
    // an empty list: the profile is incomplete, and an empty list would tell the
    // account that nothing is wrong with it.
    $tanpaDokter = bkuUser('Dokter Tanpa Profil', 'dokter');
    app(RoleAssigner::class)->assign($tanpaDokter, 'dokter');
    bkuAs(User::query()->findOrFail($tanpaDokter));

    test()->getJson('/api/v1/dokter/booking')->assertForbidden();
});

// =====================================================================
// The lists
// =====================================================================

test('the patient list is the caller own, filtered by status, paginated with the project meta block', function (): void {
    $dokter = bkuDoctor();
    $jadwalLain = bkuJadwal($dokter['dokter']->getKey());

    $pasien = bkuPatient();
    $orangLain = bkuPatient();

    bkuBookingRow($dokter['dokter']->getKey(), '09:00:00', '09:15:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
        'status' => 'terjadwal',
        'jadwal_id' => $jadwalLain,
    ]);
    bkuBookingRow($dokter['dokter']->getKey(), '09:15:00', '09:30:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
        'status' => 'menunggu_pembayaran',
    ]);
    bkuBookingRow($dokter['dokter']->getKey(), '09:30:00', '09:45:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
        'status' => 'dibatalkan',
    ]);

    // Somebody else's booking, which must not appear on this list at all.
    bkuBookingRow($dokter['dokter']->getKey(), '09:45:00', '10:00:00', [
        'pasien_id' => $orangLain['pasien']->getKey(),
    ]);

    bkuAs($pasien['user']);

    $semua = test()->getJson('/api/v1/pasien/booking')->assertOk();

    $semua->assertJsonCount(3, 'data.booking')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 15)
        // `dokter` and `jadwal` are eager-loaded on both list routes, per the
        // plan, so this is where an N+1 would show.
        ->assertJsonPath('data.booking.0.dokter_id', (int) $dokter['dokter']->getKey())
        ->assertJsonPath('data.booking.0.jadwal_id', $jadwalLain);

    $terjadwal = test()->getJson('/api/v1/pasien/booking?status=terjadwal')->assertOk();
    $terjadwal->assertJsonCount(1, 'data.booking')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.booking.0.status', 'terjadwal');

    // An out-of-enum status is a 422 naming the field, not an empty list.
    test()->getJson('/api/v1/pasien/booking?status=broom')
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['status']]);

    // Pagination really paginates.
    $halaman = test()->getJson('/api/v1/pasien/booking?per_page=2&page=2')->assertOk();
    $halaman->assertJsonCount(1, 'data.booking')
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2);

    // The order is total, so paging cannot repeat or skip a row. `id` alone is
    // the unique tiebreaker.
    $ids = array_map(
        static fn (array $b): int => (int) $b['id'],
        json_decode((string) $semua->getContent(), true)['data']['booking'],
    );

    expect($ids)->toBe(array_values(array_unique($ids)))->toHaveCount(3);
});

test('the doctor-side list is scoped to the caller own dokter row and filters by date and status', function (): void {
    $dokter = bkuDoctor();
    $dokterLain = bkuDoctor();

    $pasien = bkuPatient();

    bkuBookingRow($dokter['dokter']->getKey(), '09:00:00', '09:15:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
        'status' => 'terjadwal',
    ]);
    bkuBookingRow($dokter['dokter']->getKey(), '09:15:00', '09:30:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
        'tanggal_kunjungan' => BKU_TANGGAL_LAIN,
        'status' => 'terjadwal',
    ]);
    bkuBookingRow($dokter['dokter']->getKey(), '09:30:00', '09:45:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
        'status' => 'check_in',
    ]);

    // A booking on ANOTHER doctor's row, which this list must never carry.
    bkuBookingRow($dokterLain['dokter']->getKey(), '09:00:00', '09:15:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
    ]);

    bkuAs($dokter['user']);

    $semua = test()->getJson('/api/v1/dokter/booking')->assertOk();
    $semua->assertJsonCount(3, 'data.booking')->assertJsonPath('meta.total', 3);

    $perTanggal = test()->getJson('/api/v1/dokter/booking?tanggal='.BKU_TANGGAL)->assertOk();
    $perTanggal->assertJsonCount(2, 'data.booking')
        ->assertJsonPath('data.booking.0.tanggal_kunjungan', BKU_TANGGAL)
        ->assertJsonPath('data.booking.1.tanggal_kunjungan', BKU_TANGGAL);

    $perStatus = test()->getJson('/api/v1/dokter/booking?status=check_in')->assertOk();
    $perStatus->assertJsonCount(1, 'data.booking')->assertJsonPath('data.booking.0.status', 'check_in');

    test()->getJson('/api/v1/dokter/booking?tanggal=2026-13-45')
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['tanggal']]);
});

test('a doctor-side response publishes the patient with a MASKED nik and no unmasked 16-digit identifier anywhere', function (): void {
    // Success criterion 4 is global, and this is the one booking endpoint that
    // eager-loads `pasien` for somebody who is not the patient. `pasien.nik` is
    // `CHAR(16) NULL UNIQUE` (:222) and the plan's DoD forbids publishing it
    // unmasked, so the response carries `NikMasker::mask($nik)` and the assertion
    // is on the RAW BODY, not on one key.
    $nik = '3273123456780001';
    $nomorKk = '3273123456780002';

    $dokter = bkuDoctor();
    $pasien = bkuPatient(['nik' => $nik, 'nomor_kk' => $nomorKk]);

    bkuBookingRow($dokter['dokter']->getKey(), '09:00:00', '09:15:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
    ]);

    bkuAs($dokter['user']);

    $response = test()->getJson('/api/v1/dokter/booking')->assertOk();
    $body = (string) $response->getContent();

    $response->assertJsonPath('data.booking.0.pasien.id', (int) $pasien['pasien']->getKey())
        ->assertJsonPath('data.booking.0.pasien.nik', NikMasker::mask($nik))
        ->assertJsonPath('data.booking.0.pasien.nama_lengkap', $pasien['user']->nama_lengkap);

    // The two things that must never be in a booking body at all, and then the
    // general shape of the rule: no bare 16-digit run anywhere in the document.
    expect($body)->not->toContain($nik)
        ->and($body)->not->toContain($nomorKk)
        ->and($body)->not->toContain('kata_sandi_hash')
        ->and(preg_match('/(?<![0-9])[0-9]{16}(?![0-9])/', $body))->toBe(0);
});

// =====================================================================
// Cancel
// =====================================================================

test('a patient cancels their own booking, the row records who and why, and the invoice is cancelled with it', function (): void {
    $dokter = bkuDoctor();
    bkuJadwal($dokter['dokter']->getKey());
    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    test()->postJson('/api/v1/booking', bkuPayload($dokter['dokter']->getKey()))->assertCreated();

    $booking = bkuBookingTerakhir((int) $pasien['pasien']->getKey());
    $invoiceId = (int) bkuInvoice((int) $booking->getKey())->getKey();

    $response = test()->putJson('/api/v1/booking/'.$booking->getKey().'/batalkan', [
        'alasan_pembatalan' => 'Berubah pikiran, ingin konsultasi lain.',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.booking.status', 'dibatalkan')
        // `booking.dibatalkan_oleh ENUM('pasien','dokter','sistem')` (:517) is
        // taken from `users.tipe` (:139), so a patient cancellation records
        // `pasien`.
        ->assertJsonPath('data.booking.dibatalkan_oleh', 'pasien');

    $booking->refresh();
    $invoice = Invoice::query()->findOrFail($invoiceId);

    expect($booking->status)->toBe('dibatalkan')
        ->and($booking->dibatalkan_oleh)->toBe('pasien')
        ->and($booking->alasan_pembatalan)->toBe('Berubah pikiran, ingin konsultasi lain.')
        // `invoice.status` includes `dibatalkan` (:947-:948), so a cancelled
        // booking must not leave an invoice still asking for money.
        ->and($invoice->status)->toBe('dibatalkan');
});

test('the owning doctor cancels and records dokter, and the freed slot is bookable again', function (): void {
    $dokter = bkuDoctor();
    bkuJadwal($dokter['dokter']->getKey());

    $pasien = bkuPatient();
    bkuAs($pasien['user']);
    test()->postJson('/api/v1/booking', bkuPayload($dokter['dokter']->getKey()))->assertCreated();

    $booking = bkuBookingTerakhir((int) $pasien['pasien']->getKey());

    // The slot is full now, so a second create is refused -- that is what makes
    // the "bookable again" assertion below mean anything.
    test()->postJson('/api/v1/booking', bkuPayload($dokter['dokter']->getKey()))
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['slot']]);

    bkuAs($dokter['user']);

    test()->putJson('/api/v1/booking/'.$booking->getKey().'/batalkan', [
        'alasan_pembatalan' => 'Dokter berhalangan hadir.',
    ])->assertOk()->assertJsonPath('data.booking.dibatalkan_oleh', 'dokter');

    $booking->refresh();
    expect($booking->dibatalkan_oleh)->toBe('dokter');

    bkuAs($pasien['user']);
    test()->postJson('/api/v1/booking', bkuPayload($dokter['dokter']->getKey()))->assertCreated();
});

test('another patient booking is a 404 and is left completely untouched', function (): void {
    $dokter = bkuDoctor();
    bkuJadwal($dokter['dokter']->getKey());

    $pemilik = bkuPatient();
    $pencoba = bkuPatient();

    $bookingId = bkuBookingRow($dokter['dokter']->getKey(), '09:00:00', '09:15:00', [
        'pasien_id' => $pemilik['pasien']->getKey(),
        'status' => 'terjadwal',
    ]);

    bkuAs($pencoba['user']);

    // 404, not 403: a 403 would confirm the row exists, which is a cross-tenant
    // existence oracle. `PasienRecordAccess` owns that split, and the booking
    // accessors were ADDED to it rather than re-implemented here.
    test()->putJson('/api/v1/booking/'.$bookingId.'/batalkan', ['alasan_pembatalan' => 'Not mine.'])
        ->assertNotFound()
        ->assertJsonPath('success', false);

    $booking = Booking::query()->findOrFail($bookingId);

    expect($booking->status)->toBe('terjadwal')
        ->and($booking->dibatalkan_oleh)->toBeNull()
        ->and($booking->alasan_pembatalan)->toBeNull()
        ->and(DB::table('booking')->where('status', 'dibatalkan')->count())->toBe(0);
});

test('a doctor who does not own the booking row is a 404, and an account with neither row is a 403', function (): void {
    $dokterPemilik = bkuDoctor();
    $dokterAsing = bkuDoctor();

    $bookingId = bkuBookingRow($dokterPemilik['dokter']->getKey(), '09:00:00', '09:15:00');

    bkuAs($dokterAsing['user']);
    test()->putJson('/api/v1/booking/'.$bookingId.'/batalkan', [])->assertNotFound();

    // An `admin` holds `booking.batal` and so passes the route gate, but owns no
    // `pasien` row and no `dokter` row, so the service refuses with 403.
    // `dibatalkan_oleh` is a THREE-value ENUM (:517); attributing an admin's
    // cancellation to `pasien` or `dokter` would be a false record and
    // attributing it to `sistem` would misstate who acted.
    bkuAs(bkuPemanggilLain()['user']);
    test()->putJson('/api/v1/booking/'.$bookingId.'/batalkan', [])->assertForbidden();

    expect(Booking::query()->findOrFail($bookingId)->status)->toBe('terjadwal');
});

test('a booking that is already over cannot be cancelled', function (): void {
    // The plan names `selesai` and `berlangsung`. The two terminal states that
    // already release the slot -- `dibatalkan` and `kadaluarsa` -- are refused
    // for the same reason and for one more: "cancelling" them would overwrite the
    // fact of HOW they ended. All four are asserted, and the refusal must leave
    // the row exactly as it was.
    $dokter = bkuDoctor();
    bkuJadwal($dokter['dokter']->getKey());
    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    $terlarang = ['berlangsung', 'selesai', 'dibatalkan', 'kadaluarsa'];

    // The set is the DDL's, so a status added to the constant without being
    // listed here fails the test rather than passing vacuously.
    expect($terlarang)->toBe(BookingRequest::STATUS_TIDAK_BISA_DIBATALKAN)
        ->and($terlarang)->toBe(array_values(array_intersect(
            bkuStatusDdl(),
            ['berlangsung', 'selesai', 'dibatalkan', 'kadaluarsa'],
        )));

    foreach ($terlarang as $status) {
        $bookingId = bkuBookingRow($dokter['dokter']->getKey(), '09:00:00', '09:15:00', [
            'pasien_id' => $pasien['pasien']->getKey(),
            'status' => $status,
        ]);

        test()->putJson('/api/v1/booking/'.$bookingId.'/batalkan', ['alasan_pembatalan' => 'Too late.'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $booking = Booking::query()->findOrFail($bookingId);

        expect($booking->status)->toBe($status)
            ->and($booking->alasan_pembatalan)->toBeNull();
    }
});

test('dibatalkan_oleh is drawn from users.tipe and is total over the seven ENUM values', function (): void {
    // `RbacCatalog::ROLES` is asserted to be a strict subset of `users.tipe`
    // precisely because `dibatalkan_oleh` is drawn from `users.tipe`. The mapping
    // is total over the seven `tipe` values: the two account types that can own a
    // booking map to themselves and the remaining five map to `sistem`, which is
    // the only third value the ENUM offers.
    foreach (RbacCatalog::USER_TYPES as $tipe) {
        expect(BookingService::dibatalkanOleh($tipe))->toBeIn(['pasien', 'dokter', 'sistem']);
    }

    expect(BookingService::dibatalkanOleh('pasien'))->toBe('pasien')
        ->and(BookingService::dibatalkanOleh('dokter'))->toBe('dokter')
        ->and(BookingService::dibatalkanOleh('admin'))->toBe('sistem')
        ->and(BookingService::dibatalkanOleh('superadmin'))->toBe('sistem')
        ->and(BookingService::dibatalkanOleh('apoteker'))->toBe('sistem')
        ->and(BookingService::dibatalkanOleh('perawat'))->toBe('sistem')
        ->and(BookingService::dibatalkanOleh('kurir'))->toBe('sistem')
        // Total, not partial: a value outside the ENUM still answers, because a
        // missing entry must not become a `null` that MySQL 1264 would reject.
        ->and(BookingService::dibatalkanOleh('tipe-yang-tidak-ada'))->toBe('sistem');
});

// =====================================================================
// The Collision rule, read off the DDL
// =====================================================================

test('BOUNDARY: back-to-back bookings do not collide, and a one-minute overlap does', function (): void {
    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    // Half-open overlap, both ends strict, exactly as
    // `SlotAvailabilityService::hitungBertumpuk()` decides it. This booking ends
    // at 09:00, the instant the requested slot begins, so the slot is free. A
    // `<=` spelling would close it.
    $pertama = bkuDoctor();
    bkuJadwal($pertama['dokter']->getKey());
    bkuBookingRow($pertama['dokter']->getKey(), '08:45:00', '09:00:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
    ]);

    test()->postJson('/api/v1/booking', bkuPayload($pertama['dokter']->getKey()))
        ->assertCreated()
        ->assertJsonPath('data.booking.slot_mulai', '09:00:00')
        ->assertJsonPath('data.booking.slot_selesai', '09:15:00');

    // The mirror, on the other end: a booking that STARTS at 09:15 does not
    // collide with the 09:00-09:15 slot a non-strict `>=` would have closed.
    $kedua = bkuDoctor();
    bkuJadwal($kedua['dokter']->getKey());
    bkuBookingRow($kedua['dokter']->getKey(), '09:15:00', '10:00:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
    ]);

    test()->postJson('/api/v1/booking', bkuPayload($kedua['dokter']->getKey()))->assertCreated();

    // One minute of overlap is a collision, on a third doctor so the first two
    // bookings do not also count against it.
    $ketiga = bkuDoctor();
    bkuJadwal($ketiga['dokter']->getKey());
    bkuBookingRow($ketiga['dokter']->getKey(), '08:59:00', '09:01:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
    ]);

    test()->postJson('/api/v1/booking', bkuPayload($ketiga['dokter']->getKey()))
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['errors' => ['slot']]);
});

test('BOUNDARY: the quota is count < kuota_per_sesi, never count == 0', function (): void {
    $dokter = bkuDoctor();
    bkuJadwal($dokter['dokter']->getKey(), ['kuota_per_sesi' => 2]);

    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    // One booking: one seat of two is gone, one remains. A `count === 0` check
    // would already have refused this one, which is the specific bug
    // `dokter_jadwal.kuota_per_sesi` (:479) sets.
    bkuBookingRow($dokter['dokter']->getKey(), '09:00:00', '09:15:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
    ]);

    test()->postJson('/api/v1/booking', bkuPayload($dokter['dokter']->getKey()))->assertCreated();

    // The second fills the window, and the refused attempt must not have written
    // anything: the fixture plus the one created row, and no third row.
    test()->postJson('/api/v1/booking', bkuPayload($dokter['dokter']->getKey()))
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['slot']]);

    expect(DB::table('booking')->where('dokter_id', $dokter['dokter']->getKey())->count())->toBe(2);
});

test('BOUNDARY: a null kuota_per_sesi is read as 1, and dibatalkan / kadaluarsa release a slot', function (): void {
    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    // The two releasing statuses are REUSED from
    // `SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI`, asserted as an
    // identity rather than as two literals, so a ninth ENUM value cannot quietly
    // join one service and not the other.
    expect(BookingRequest::STATUS_TIDAK_MENGKONSUMSI)
        ->toBe(SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI)
        ->toHaveCount(2);

    foreach (BookingRequest::STATUS_TIDAK_MENGKONSUMSI as $status) {
        $dok = bkuDoctor();
        bkuJadwal($dok['dokter']->getKey(), ['kuota_per_sesi' => null]);
        bkuBookingRow($dok['dokter']->getKey(), '09:00:00', '09:15:00', [
            'pasien_id' => $pasien['pasien']->getKey(),
            'status' => $status,
        ]);

        test()->postJson('/api/v1/booking', bkuPayload($dok['dokter']->getKey()))->assertCreated();
    }

    // And the other six DO consume, one doctor each so the counts do not mix.
    $semua = bkuStatusDdl();
    expect($semua)->toHaveCount(8);

    foreach (array_values(array_diff($semua, BookingRequest::STATUS_TIDAK_MENGKONSUMSI)) as $status) {
        $dok = bkuDoctor();
        bkuJadwal($dok['dokter']->getKey());
        bkuBookingRow($dok['dokter']->getKey(), '09:00:00', '09:15:00', [
            'pasien_id' => $pasien['pasien']->getKey(),
            'status' => $status,
        ]);

        test()->postJson('/api/v1/booking', bkuPayload($dok['dokter']->getKey()))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['slot']]);
    }
});

test('BOUNDARY: a holiday and a lapsed STR are refused, and a slot outside the window is not a slot at all', function (): void {
    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    // Rule 2. A holiday closes the whole day; `SlotAvailabilityService` reports
    // it as `alasan = 'libur'` and this service refuses for the same reason.
    $dokLibur = bkuDoctor();
    bkuJadwal($dokLibur['dokter']->getKey());
    bkuLibur($dokLibur['dokter']->getKey(), BKU_TANGGAL);

    test()->postJson('/api/v1/booking', bkuPayload($dokLibur['dokter']->getKey()))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Slot tidak tersedia pada tanggal tersebut.');

    // Rule 4, and the reference day is the CONSULTATION date. The doctor is
    // licensed THROUGH the visit, so the visit is bookable, and a week later the
    // licence is gone and the same doctor refuses. `StrBerlaku::berlakuPada()`
    // is the decider both services call.
    $dokStr = bkuDoctor(['str_berlaku_sampai' => BKU_TANGGAL]);
    bkuJadwal($dokStr['dokter']->getKey());

    test()->postJson('/api/v1/booking', bkuPayload($dokStr['dokter']->getKey()))->assertCreated();

    test()->postJson('/api/v1/booking', bkuPayload(
        $dokStr['dokter']->getKey(),
        ['tanggal_kunjungan' => BKU_TANGGAL_LAIN],
    ))->assertUnprocessable();

    expect(StrBerlaku::berlakuPada(BKU_TANGGAL, Carbon::parse(BKU_TANGGAL)))->toBeTrue()
        ->and(StrBerlaku::berlakuPada('2026-12-06', Carbon::parse(BKU_TANGGAL)))->toBeFalse();

    // A slot the window does not contain. `getSlotTerbuka()` is asked which slots
    // the schedule publishes and the answer is that this one is not among them,
    // so no window arithmetic is re-derived here at all.
    $dokJendela = bkuDoctor();
    bkuJadwal($dokJendela['dokter']->getKey());

    test()->postJson('/api/v1/booking', bkuPayload($dokJendela['dokter']->getKey(), ['slot_mulai' => '14:00:00']))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Slot tidak dipublikasikan oleh jadwal dokter.');

    // A weekday the doctor does not work: the window above runs on Mondays
    // (`hari = 1`), so Tuesday 2026-12-08 publishes nothing for this doctor.
    // (`BKU_TANGGAL_LAIN` is a second Monday and IS worked, so it cannot carry
    // this assertion.)
    test()->postJson('/api/v1/booking', bkuPayload($dokJendela['dokter']->getKey(), [
        'tanggal_kunjungan' => '2026-12-08',
    ]))->assertUnprocessable();

    // `berlaku_sampai` is INCLUSIVE (:481) -- the same reading `StrBerlaku`
    // applies to `str_berlaku_sampai` -- so the window runs ON the date it names
    // and not on the one after.
    $dokBerlaku = bkuDoctor();
    bkuJadwal($dokBerlaku['dokter']->getKey(), [
        'berlaku_mulai' => BKU_TANGGAL,
        'berlaku_sampai' => BKU_TANGGAL,
    ]);

    test()->postJson('/api/v1/booking', bkuPayload($dokBerlaku['dokter']->getKey()))->assertCreated();
    test()->postJson('/api/v1/booking', bkuPayload($dokBerlaku['dokter']->getKey(), [
        'tanggal_kunjungan' => BKU_TANGGAL_LAIN,
    ]))->assertUnprocessable();
});

test('BOUNDARY: an instant booking today is refused once the slot has already ended', function (): void {
    // Rule 1b. `SlotAvailabilityService` compares `jam_selesai <= now` and only
    // when the requested date IS today, on the CLINIC's clock: `tanggal_kunjungan`
    // is a `DATE` and `jam_selesai` a `TIME`, and both are Asia/Jakarta wall
    // clocks under `docs/timezone-policy.md` rule 2. This service reads that clock
    // once and applies the same two comparisons to an instant booking, which has no
    // `dokter_jadwal` row for the service to answer about.
    $hariIni = bkuHariIni();

    Carbon::setTestNow($hariIni->copy()->setTime(12, 0, 0));

    try {
        $dok = bkuDoctorTanpaJadwal();
        $pasien = bkuPatient();
        bkuAs($pasien['user']);

        // 11:00 plus the fixture's 20-minute default is 11:15, which is over.
        test()->postJson('/api/v1/booking', bkuPayload(
            $dok['dokter']->getKey(),
            ['tipe_layanan' => 'chat', 'tanggal_kunjungan' => $hariIni->toDateString(), 'slot_mulai' => '11:00:00'],
        ))->assertUnprocessable()->assertJsonPath('message', 'Slot sudah lewat.');

        // 13:00 is still ahead, on the very same doctor and the very same day.
        test()->postJson('/api/v1/booking', bkuPayload(
            $dok['dokter']->getKey(),
            ['tipe_layanan' => 'chat', 'tanggal_kunjungan' => $hariIni->toDateString(), 'slot_mulai' => '13:00:00'],
        ))->assertCreated();

        // And the same elapsed slot on a FUTURE date is untouched by the clock,
        // which is the whole point of keying the rule on "is this today".
        test()->postJson('/api/v1/booking', bkuPayload(
            $dok['dokter']->getKey(),
            ['tipe_layanan' => 'chat', 'tanggal_kunjungan' => BKU_TANGGAL, 'slot_mulai' => '11:00:00'],
        ))->assertCreated();
    } finally {
        Carbon::setTestNow();
    }
});

test('a zero-length instant slot is refused rather than written', function (): void {
    // `dokter.durasi_default_menit` (:422) is `SMALLINT UNSIGNED NOT NULL`, so a
    // zero is a legal stored value and it would make `slot_selesai` equal
    // `slot_mulai`. A `TIME` needs a positive length, exactly as
    // `SlotAvailabilityService::windowLayak()` refuses a zero-length window.
    $dok = bkuDoctorTanpaJadwal(['durasi_default_menit' => 0]);
    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    test()->postJson('/api/v1/booking', bkuPayload($dok['dokter']->getKey(), [
        'tipe_layanan' => 'chat',
        'slot_mulai' => '23:00:00',
    ]))->assertUnprocessable();

    expect(DB::table('booking')->where('dokter_id', $dok['dokter']->getKey())->count())->toBe(0);
});

// =====================================================================
// The numbering, and its collision path
// =====================================================================

test('nomor_booking is BK + Ymd + 6 and fits the VARCHAR(30) the DDL declares', function (): void {
    $dokter = bkuDoctor();
    bkuJadwal($dokter['dokter']->getKey());
    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    test()->postJson('/api/v1/booking', bkuPayload($dokter['dokter']->getKey()))->assertCreated();

    $nomor = (string) bkuBookingTerakhir((int) $pasien['pasien']->getKey())->nomor_booking;

    expect($nomor)->toMatch('/^BK\d{8}[A-Z0-9]{6}$/')
        ->and(strlen($nomor))->toBe(16)
        ->and(strlen($nomor))->toBeLessThanOrEqual(30)
        // The date inside the number is the CONSULTATION date, not today: the
        // number is what a patient reads out to a clinic receptionist, so it has
        // to name the day they are coming.
        ->and(substr($nomor, 2, 8))->toBe(str_replace('-', '', BKU_TANGGAL));
});

test('a nomor_booking collision is retried, and three collisions are a 422 with nothing written', function (): void {
    // The plan's Oracle finding: `nomor_booking VARCHAR(30) NOT NULL UNIQUE`
    // (:500) means a collision under concurrency is an unhandled 500 unless it is
    // retried. The sequence source is injected so the candidates are PREDICTABLE,
    // which is the only way to plant a real duplicate-key violation: the rows
    // that collide are genuine `booking` rows and the violation is a genuine
    // MySQL 1062 surfaced as `UniqueConstraintViolationException`.
    app()->instance(NomorDokumen::class, new NomorDokumen(
        static fn (int $percobaan): string => sprintf('A%05d', $percobaan),
    ));

    $dokter = bkuDoctor();
    bkuJadwal($dokter['dokter']->getKey());
    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    $tanggal = str_replace('-', '', BKU_TANGGAL);

    // Plant the exact numbers the first two attempts will draw.
    foreach ([1, 2] as $percobaan) {
        bkuBookingRow($dokter['dokter']->getKey(), '08:00:00', '08:15:00', [
            'pasien_id' => $pasien['pasien']->getKey(),
            'nomor_booking' => 'BK'.$tanggal.'A'.sprintf('%05d', $percobaan),
        ]);
    }

    // Attempt one and two collide; attempt three is free and the create succeeds.
    test()->postJson('/api/v1/booking', bkuPayload($dokter['dokter']->getKey()))
        ->assertCreated()
        ->assertJsonPath('data.booking.nomor_booking', 'BK'.$tanggal.'A00003');

    // Now every attempt collides, on a different doctor so the first fixture's
    // numbers do not interfere with the accounting.
    $dokPenuh = bkuDoctor();
    bkuJadwal($dokPenuh['dokter']->getKey());
    $pasienKedua = bkuPatient();
    bkuAs($pasienKedua['user']);

    // The shared sequence already drew attempts 1-4 in the first half (three
    // booking candidates and one invoice number), so this half plants the
    // numbers the next three attempts will draw. Planting 1-3 again would
    // collide with the first half's own rows at FIXTURE time, outside any
    // retry, because `nomor_booking` is globally unique.
    foreach ([5, 6, 7] as $percobaan) {
        bkuBookingRow($dokPenuh['dokter']->getKey(), '08:00:00', '08:15:00', [
            'pasien_id' => $pasienKedua['pasien']->getKey(),
            'nomor_booking' => 'BK'.$tanggal.'A'.sprintf('%05d', $percobaan),
        ]);
    }

    $bookingSebelum = DB::table('booking')->count();
    $invoiceSebelum = DB::table('invoice')->count();

    test()->postJson('/api/v1/booking', bkuPayload($dokPenuh['dokter']->getKey(), [
        'tipe_layanan' => 'chat',
        'slot_mulai' => '15:00:00',
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Nomor booking tidak dapat dibuat. Silakan coba lagi.');

    // Nothing was written: the retry loop is inside the transaction, and a
    // rolled-back attempt leaves no `booking` and no `invoice` behind.
    expect(DB::table('booking')->count())->toBe($bookingSebelum)
        ->and(DB::table('invoice')->count())->toBe($invoiceSebelum);
});

test('the default sequence source is random, so two numbers are never the same by construction', function (): void {
    // Only the shape is asserted, never a value: a test that asserted a specific
    // random number would be asserting nothing. The uniqueness of the column is
    // what the retry loop exists for, and that is tested above.
    $satu = (new NomorDokumen)->berikutnya(NomorDokumen::PREFIX_BOOKING, '2026-12-07');
    $dua = (new NomorDokumen)->berikutnya(NomorDokumen::PREFIX_BOOKING, '2026-12-07');

    expect($satu)->toMatch('/^BK20261207[A-Z0-9]{6}$/')
        ->and(strlen($satu))->toBeLessThanOrEqual(30)
        ->and($satu)->not->toBe($dua);
});

// =====================================================================
// Schema invariants this feature depends on
// =====================================================================

test('the slot triple has NO unique index, and the guard is therefore application level', function (): void {
    // The plan's load-bearing fact. `booking` carries `INDEX idx_booking_dokter
    // (dokter_id, tanggal_kunjungan)` (:528) and `INDEX idx_booking_pasien` (:529)
    // and a UNIQUE on `nomor_booking` (:500) -- and NOTHING that constrains two
    // bookings onto the same `(dokter_id, tanggal_kunjungan, slot_mulai)`. The
    // constraint route is therefore unavailable, which is exactly why the
    // `dokter` row lock exists. Asserted against the LIVE table, not against a
    // migration file, so a migration that added one would fail here.
    $create = (string) DB::selectOne('SHOW CREATE TABLE booking')->{'Create Table'};

    $unik = [];
    foreach (DB::select('SHOW INDEX FROM booking') as $baris) {
        // `PRIMARY` is the clustered surrogate key and is not a constraint on any
        // column combination, so it is excluded before the "exactly one UNIQUE"
        // claim is made.
        if ((int) $baris->Non_unique === 0 && $baris->Key_name !== 'PRIMARY') {
            $unik[(string) $baris->Key_name][] = (string) $baris->Column_name;
        }
    }

    // Exactly one unique index, and it is the document number. Note the index
    // NAME: MySQL auto-names an inline `UNIQUE` after its column
    // (`telemedicine_test.sql:500` is `nomor_booking VARCHAR(30) NOT NULL
    // UNIQUE`), so the key is `booking_nomor_booking_unique` and not
    // `nomor_booking`. What matters is the COLUMN SET, not the name.
    expect(array_keys($unik))->toBe(['booking_nomor_booking_unique'])
        ->and($unik['booking_nomor_booking_unique'])->toBe(['nomor_booking']);

    foreach ($unik as $kolom) {
        expect($kolom)->not->toContain('dokter_id')
            ->and($kolom)->not->toContain('tanggal_kunjungan')
            ->and($kolom)->not->toContain('slot_mulai');
    }

    expect($create)->toContain('KEY `idx_booking_dokter` (`dokter_id`,`tanggal_kunjungan`)');
});

test('every column the create writes is in the DDL with the nullability the code assumes', function (): void {
    $spesifikasi = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
    $booking = $spesifikasi->table('booking');
    $invoice = $spesifikasi->table('invoice');
    $dokter = $spesifikasi->table('dokter');

    expect($booking)->not->toBeNull()
        ->and($invoice)->not->toBeNull()
        ->and($dokter)->not->toBeNull();

    // `jadwal_id BIGINT UNSIGNED NULL` (:504) is the nullable column the whole
    // instant-booking path exists for, while `slot_mulai` / `slot_selesai` are
    // `NOT NULL` (:508-:509), so an instant booking is a REAL row and not a row
    // with a hole in it.
    expect($booking->columns['jadwal_id']->nullable)->toBeTrue()
        ->and($booking->columns['pasien_id']->nullable)->toBeFalse()
        ->and($booking->columns['dokter_id']->nullable)->toBeFalse()
        ->and($booking->columns['tipe_layanan']->nullable)->toBeFalse()
        ->and($booking->columns['tanggal_kunjungan']->type)->toBe('date')
        ->and($booking->columns['tanggal_kunjungan']->nullable)->toBeFalse()
        ->and($booking->columns['slot_mulai']->type)->toBe('time')
        ->and($booking->columns['slot_mulai']->nullable)->toBeFalse()
        ->and($booking->columns['slot_selesai']->type)->toBe('time')
        ->and($booking->columns['slot_selesai']->nullable)->toBeFalse()
        ->and($booking->columns['dibuat_oleh_user_id']->nullable)->toBeFalse()
        ->and($booking->columns['nomor_booking']->nullable)->toBeFalse()
        ->and($booking->columns['nomor_antrian']->nullable)->toBeTrue()
        ->and($booking->columns['dibatalkan_oleh']->nullable)->toBeTrue()
        ->and($booking->columns['status']->default)->toBe("'menunggu_pembayaran'")
        ->and($booking->columns['dibatalkan_oleh']->type)->toBe("enum('pasien','dokter','sistem')")
        ->and($booking->columns['lampiran_keluhan']->type)->toBe('json')
        ->and($booking->columns['is_rujukan']->default)->toBe('0')
        ->and($booking->columns['is_konsultasi_lanjutan']->default)->toBe('0')
        // The three doctor columns this feature reads, so a rename is a failing
        // test rather than a null read.
        ->and($dokter->columns['durasi_default_menit']->type)->toBe('smallint')
        ->and($dokter->columns['durasi_default_menit']->default)->toBe('15')
        ->and($dokter->columns['biaya_konsultasi_online']->type)->toBe('decimal(12,2)')
        ->and($dokter->columns['biaya_konsultasi_online']->default)->toBe('0')
        ->and($dokter->columns['str_berlaku_sampai']->type)->toBe('date')
        ->and($dokter->columns['str_berlaku_sampai']->nullable)->toBeFalse();

    // `invoice` (:936-:956) has NO `metode_id`: the payment method lives on
    // `pembayaran` (:958-:959), so a booking invoice has no method to set and
    // `biaya_admin` is zero until one is chosen.
    expect($invoice->columns)->toHaveKey('nomor_invoice')
        ->and($invoice->columns)->not->toHaveKey('metode_id')
        ->and($invoice->columns['referensi_tipe']->type)
        ->toBe("enum('booking','konsultasi','resep','pesanan_obat','lab_permintaan','home_care')")
        // `referensi_id BIGINT UNSIGNED NOT NULL COMMENT 'Polimorfik'` (:941) has
        // NO foreign key, which is why the service can never rely on the database
        // to keep the reference honest.
        ->and($invoice->columns['referensi_id']->nullable)->toBeFalse()
        ->and($invoice->columns['total']->nullable)->toBeFalse()
        ->and($invoice->columns['diskon']->default)->toBe('0')
        ->and($invoice->columns['status']->default)->toBe("'menunggu_pembayaran'");
});

// =====================================================================
// Divergence from SlotAvailabilityService
// =====================================================================

test('the create guard and SlotAvailabilityService agree, case for case', function (): void {
    // The anti-divergence instrument. Six rules are shared with
    // `SlotAvailabilityService`; two of them are shared BY CODE (the exclusion set
    // and `StrBerlaku`) and the rest are shared BY PROVEN EQUIVALENCE, because
    // this service has to reach the same answer INSIDE a transaction without
    // re-deriving the window arithmetic. A second, differently-bounded rule for
    // any of them would be a second source of truth, so the two services are
    // asked the same question about the same fixtures and must answer
    // identically.
    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    // [existing booking start, existing booking end, requested slot, expected?]
    $kasus = [
        ['08:45:00', '09:00:00', '09:00:00', true, 'a booking ending exactly when the slot begins'],
        ['09:15:00', '10:00:00', '09:00:00', true, 'a booking starting exactly when the slot ends'],
        ['08:59:00', '09:01:00', '09:00:00', false, 'one minute of overlap at the start'],
        ['09:14:00', '09:16:00', '09:00:00', false, 'one minute of overlap at the end'],
        ['09:00:00', '09:15:00', '09:00:00', false, 'the slot itself'],
        ['09:00:00', '09:15:00', '09:15:00', true, 'the next slot along'],
        ['09:00:00', '09:15:00', '09:30:00', true, 'two slots along'],
    ];

    $layanan = app(SlotAvailabilityService::class);

    foreach ($kasus as [$mulai, $selesai, $diminta, $harap, $kenapa]) {
        $dok = bkuDoctor();
        bkuJadwal($dok['dokter']->getKey());

        bkuBookingRow($dok['dokter']->getKey(), $mulai, $selesai, [
            'pasien_id' => $pasien['pasien']->getKey(),
        ]);

        $slot = collect($layanan->getSlotTerbuka($dok['dokter'], BKU_TANGGAL))
            ->firstWhere('jam_mulai', $diminta);

        expect($slot)->not->toBeNull()
            ->and($slot['tersedia'])->toBe($harap, 'SlotAvailabilityService disagrees: '.$kenapa);

        $response = test()->postJson('/api/v1/booking', bkuPayload(
            $dok['dokter']->getKey(),
            ['slot_mulai' => $diminta],
        ));

        if ($harap) {
            $response->assertCreated();
        } else {
            $response->assertUnprocessable();
        }
    }
});

// =====================================================================
// The expiry rule the schema cannot express
// =====================================================================

test('the expiry sweep flips only a stale menunggu_pembayaran booking to kadaluarsa', function (): void {
    // `booking.status` includes `kadaluarsa` (:515-:516) and the schema has NO
    // column recording when the payment window closes, so the plan requires the
    // expiry to be COMPUTED from `tanggal_kunjungan` + `slot_mulai`. The
    // reference clock is the clinic's own, for the reason
    // `SlotAvailabilityService::hariIni()` gives and `docs/timezone-policy.md`
    // rule 2 requires: `tanggal_kunjungan` is a `DATE` and `slot_mulai` a `TIME`,
    // both Asia/Jakarta wall clocks, so the clock they are compared against has to
    // be the Jakarta one.
    $dokter = bkuDoctor();
    bkuJadwal($dokter['dokter']->getKey());
    $pasien = bkuPatient();
    bkuAs($pasien['user']);

    test()->postJson('/api/v1/booking', bkuPayload($dokter['dokter']->getKey()))->assertCreated();
    $basah = bkuBookingTerakhir((int) $pasien['pasien']->getKey());

    // A booking in the future must survive the sweep untouched. The status is
    // stated rather than inherited from the fixture default (`terjadwal`),
    // because only a `menunggu_pembayaran` row is even eligible to move.
    $akanDatang = bkuBookingRow($dokter['dokter']->getKey(), '09:15:00', '09:30:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
        'tanggal_kunjungan' => BKU_TANGGAL_LAIN,
        'status' => 'menunggu_pembayaran',
    ]);

    // One already past: the visit was yesterday.
    $kemarin = bkuHariIni()->copy()->subDay()->toDateString();

    $lewat = bkuBookingRow($dokter['dokter']->getKey(), '09:30:00', '09:45:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
        'tanggal_kunjungan' => $kemarin,
        'status' => 'menunggu_pembayaran',
    ]);

    // And one that is paid for, which the sweep must never touch however old it
    // is: `terjadwal` means the money arrived and the consultation is real.
    $sudahBayar = bkuBookingRow($dokter['dokter']->getKey(), '09:45:00', '10:00:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
        'tanggal_kunjungan' => $kemarin,
        'status' => 'terjadwal',
    ]);

    $baris = app(BookingService::class)->kadaluarsa();

    expect($baris)->toBe(1)
        ->and(Booking::query()->findOrFail($lewat)->status)->toBe('kadaluarsa')
        ->and(Booking::query()->findOrFail($akanDatang)->status)->toBe('menunggu_pembayaran')
        ->and(Booking::query()->findOrFail($sudahBayar)->status)->toBe('terjadwal')
        ->and(Booking::query()->findOrFail($basah->getKey())->status)->toBe('menunggu_pembayaran')
        // Running it again is a no-op, because a `kadaluarsa` row is not
        // `menunggu_pembayaran` any more.
        ->and(app(BookingService::class)->kadaluarsa())->toBe(0);

    // And the slot a `kadaluarsa` booking held is released, which is one of the
    // two reasons the status exists.
    $dokBaru = bkuDoctor();
    bkuJadwal($dokBaru['dokter']->getKey());
    bkuBookingRow($dokBaru['dokter']->getKey(), '09:00:00', '09:15:00', [
        'pasien_id' => $pasien['pasien']->getKey(),
        'tanggal_kunjungan' => $kemarin,
        'status' => 'kadaluarsa',
    ]);

    test()->postJson('/api/v1/booking', bkuPayload($dokBaru['dokter']->getKey()))->assertCreated();
});
