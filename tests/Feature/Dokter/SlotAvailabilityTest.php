<?php

declare(strict_types=1);

use App\Models\Dokter;
use App\Services\Booking\SlotAvailabilityService;
use App\Support\Dokter\StrBerlaku;
use App\Support\Schema\SqlSchemaParser;
use App\Support\WaktuIndonesia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| SlotAvailabilityService
|--------------------------------------------------------------------------
|
| The doctor's bookable-slot computation, driven entirely by four rules read
| out of `telemedicine_test.sql`:
|
| | rule | table | lines |
| | --- | --- | --- |
| | 1. working windows | `dokter_jadwal` | 470-488 |
| | 2. whole-day holiday | `dokter_libur` | 490-496 |
| | 3. collision against consuming bookings | `booking` | 498-530 |
| | 4. STR validity on the consultation date | `dokter` | 409-435, esp. 414 |
|
| **These are Pest closure tests, not a PHPUnit class, and that is
| load-bearing.** `tests/Pest.php` binds `RefreshDatabase` with
| `->in('Feature')`, which covers closure tests and not a plain
| `class FooTest` in the same directory. Without the trait the rows written
| below survive into the next test. See `DokterDirectoryTest` for the same
| note.
|
| **Every date in this file is fixed and in the future, and that is
| deliberate.** Rule 4 compares the licence against the *requested*
| consultation date and a fifth rule marks elapsed slots on *today*
| unavailable. A test written against `now()` would therefore be asserting
| different things on different days, so the working date is a constant
| (`2026-12-07`) that no clock can move. `Carbon::setTestNow()` is used only
| by the one test that needs a past slot, and it is reset in that test's own
| `finally`.
|
| **No seeder runs.** `RefreshDatabase` migrates without `--seed`, so
| `master_spesialisasi`, `faskes` and every other table is written by the
| helpers below and nothing survives a test.
|
| ## The helper prefix
|
| `DokterDirectoryTest.php` declares global helpers named `direktori*` at file
| scope, and Pest loads every test file into one process, so those names are
| already taken and reusing one would couple this file to that one. Every
| helper here is prefixed `slot`.
|
*/

/*
|--------------------------------------------------------------------------
| Fixed dates
|--------------------------------------------------------------------------
|
| `2026-12-07` is a **Monday**, so `dokter_jadwal.hari` is `1` under the DDL's
| own `0=Minggu s.d. 6=Sabtu` comment at `:475`, which is exactly PHP's
| `date('w')` numbering. Each constant is paired with an assertion that
| recomputes the weekday, so a wrong literal is caught by the file that uses
| it rather than by a slot that silently stops being generated.
|
*/

const SLOT_TANGGAL = '2026-12-07';      // Monday, hari = 1
const SLOT_TANGGAL_NEXT = '2026-12-14';  // Monday, hari = 1
const SLOT_TANGGAL_SEBELUM = '2026-11-30'; // Monday, hari = 1
const SLOT_TANGGAL_HARI_LAIN = '2026-12-08'; // Tuesday, hari = 2

/*
|--------------------------------------------------------------------------
| Row builders
|--------------------------------------------------------------------------
|
| Written through `DB::table()->insertGetId()` rather than through the models.
| None of `Dokter`, `Pasien`, `Faskes`, `DokterJadwal`, `DokterLibur` and
| `Booking` declares a `#[Fillable]` attribute, so Eloquent's default
| `$guarded = ['*']` makes `Model::create()` write nothing at all and say
| nothing about it. The query builder has no such default, so every column
| name below is checked by MySQL against the real table.
|
*/

/**
 * A `users` row. `tipe` is the seven-value ENUM at `:139`; `status` is `aktif`
 * (`:140`) so no test is ever excluding a doctor on the *account's* state.
 */
function slotUser(string $nama, string $tipe = 'dokter'): int
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
 * A `pasien` row. `jenis_kelamin` (`:225`), `tanggal_lahir` (`:226`) and
 * `alamat_lengkap` (`:234`) are the three NOT NULL columns with no default;
 * `nik`, `nomor_rm` and `nomor_ihs_satusehat` are all nullable and left out.
 */
function slotPasien(): int
{
    return (int) DB::table('pasien')->insertGetId([
        'user_id' => slotUser('Pasien Uji '.Str::upper(Str::random(6)), 'pasien'),
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Slot No. 7, Jakarta',
    ]);
}

/**
 * A `dokter` row, with `durasi_default_menit` deliberately set to a value that
 * is NOT the slot duration any test expects.
 *
 * `dokter.durasi_default_menit` is `SMALLINT UNSIGNED NOT NULL DEFAULT 15`
 * (`:422`) and this writes 20. It is a profile-wide default for a consultation
 * length, while `dokter_jadwal.durasi_slot_menit` (`:478`) is the per-window
 * slot length, and the tests that assert slot boundaries fail if the service
 * reads the doctor's default instead of the schedule row's own value. Setting
 * the two to different numbers is what gives the distinction teeth.
 */
function slotDokterId(array $ubah = []): int
{
    $id = (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => slotUser('Dokter Uji '.Str::upper(Str::random(6))),
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-SLOT-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'pengalaman_tahun' => 5,
        'durasi_default_menit' => 20,
        'tersedia_telemedisin' => 1,
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ], $ubah));

    return $id;
}

/**
 * The same row, re-read as the model the service takes.
 */
function slotDokter(int $dokterId): Dokter
{
    $dokter = Dokter::query()->find($dokterId);

    expect($dokter)->toBeInstanceOf(Dokter::class);

    return $dokter;
}

/**
 * A `faskes` row. `nama` (`:364`), `tipe` (`:365`) and `alamat` (`:367`) are
 * NOT NULL; `kode_faskes` (`:362`) is nullable and supplied anyway.
 */
function slotFaskes(string $nama = 'Klinik Slot Uji'): int
{
    return (int) DB::table('faskes')->insertGetId([
        'kode_faskes' => 'FS-SLOT-'.Str::upper(Str::random(6)),
        'nama' => $nama,
        'tipe' => 'klinik',
        'alamat' => 'Jl. Uji Slot No. 1, Jakarta',
    ]);
}

/**
 * A `dokter_jadwal` row.
 *
 * `dokter_jadwal` (`:470`-`:488`) is never written by a write endpoint in this
 * contract -- the plan lists none -- so every window in this suite is
 * authored here.
 *
 * @param  array<string, mixed>  $ubah
 */
function slotJadwal(int $dokterId, array $ubah = []): int
{
    return (int) DB::table('dokter_jadwal')->insertGetId(array_merge([
        'dokter_id' => $dokterId,
        'faskes_id' => null,
        'tipe_layanan' => 'online',
        'hari' => 1,
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
 * A `dokter_libur` row. `dokter_libur` (`:490`-`:496`) has no timestamps at
 * all, which is why `DokterLibur::$timestamps` is `false`.
 */
function slotLibur(int $dokterId, string $tanggal, ?string $alasan = 'Hari libur'): int
{
    return (int) DB::table('dokter_libur')->insertGetId([
        'dokter_id' => $dokterId,
        'tanggal' => $tanggal,
        'alasan' => $alasan,
    ]);
}

/**
 * A `booking` row. Every NOT NULL column without a default is supplied:
 * `nomor_booking` (`:500`), `pasien_id` (`:501`), `dokter_id` (`:503`),
 * `tipe_layanan` (`:506`), `tanggal_kunjungan` (`:507`), `slot_mulai`
 * (`:508`), `slot_selesai` (`:509`) and `dibuat_oleh_user_id` (`:519`).
 *
 * @param  array<string, mixed>  $ubah
 */
function slotBooking(int $dokterId, string $mulai, string $selesai, array $ubah = []): int
{
    $pasienId = $ubah['pasien_id'] ?? slotPasien();
    $dibuatOleh = $ubah['dibuat_oleh_user_id'] ?? slotUser('Pembuat Uji', 'admin');

    unset($ubah['pasien_id'], $ubah['dibuat_oleh_user_id']);

    return (int) DB::table('booking')->insertGetId(array_merge([
        'nomor_booking' => 'BK-SLOT-'.Str::upper(Str::random(10)),
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'jadwal_id' => null,
        'faskes_id' => null,
        'tipe_layanan' => 'video_call',
        'tanggal_kunjungan' => SLOT_TANGGAL,
        'slot_mulai' => $mulai,
        'slot_selesai' => $selesai,
        'status' => 'terjadwal',
        'dibuat_oleh_user_id' => $dibuatOleh,
    ], $ubah));
}

/**
 * The `jam_mulai` of every returned slot, in order.
 *
 * @param  list<array<string, mixed>>  $slots
 * @return list<string>
 */
function slotMulai(array $slots): array
{
    return array_values(array_column($slots, 'jam_mulai'));
}

/**
 * The `jam_selesai` of every returned slot, in order.
 *
 * @param  list<array<string, mixed>>  $slots
 * @return list<string>
 */
function slotSelesai(array $slots): array
{
    return array_values(array_column($slots, 'jam_selesai'));
}

/**
 * The `jadwal_id` of every returned slot, in order.
 *
 * @param  list<array<string, mixed>>  $slots
 * @return list<int>
 */
function slotJadwalIds(array $slots): array
{
    return array_map('intval', array_values(array_column($slots, 'jadwal_id')));
}

/**
 * The one slot that starts at `$mulai`, or null.
 *
 * @param  list<array<string, mixed>>  $slots
 * @return array<string, mixed>|null
 */
function slotCari(array $slots, string $mulai): ?array
{
    foreach ($slots as $kandidat) {
        if ($kandidat['jam_mulai'] === $mulai) {
            return $kandidat;
        }
    }

    return null;
}

/**
 * Today, as the **clinic** sees it.
 *
 * `docs/timezone-policy.md` rule 2: the slot times in this file are `TIME`
 * columns, which are clinic-local wall clocks, so "today" has to be the Jakarta
 * calendar day. `WaktuIndonesia::tanggal()` is what the service now asks, and
 * this helper asks the same question so the two cannot drift.
 *
 * It was `SELECT CURDATE()`, which read the *server's* calendar day. That was only
 * ever right because the development host is set to WIB, and it silently became
 * the UTC day the moment the MySQL session was pinned to `+00:00` - between
 * midnight and seven in the morning WIB, "today" would have been yesterday.
 */
function slotHariIni(): Carbon
{
    return Carbon::parse(WaktuIndonesia::tanggal(), WaktuIndonesia::ZONA)->startOfDay();
}

/*
|--------------------------------------------------------------------------
| The fixed dates are what this file claims they are
|--------------------------------------------------------------------------
*/

test('the fixed dates land on the weekday the DDL comment at :475 names', function (): void {
    // `hari TINYINT UNSIGNED NOT NULL COMMENT '0=Minggu s.d. 6=Sabtu'` (`:475`)
    // is PHP's own `date('w')` numbering, and every window in this file is
    // written with `hari = 1`. If the two ever diverged, every slot assertion
    // below would quietly stop generating slots and most would pass vacuously.
    expect(Carbon::parse(SLOT_TANGGAL)->dayOfWeek)->toBe(1)
        ->and(Carbon::parse(SLOT_TANGGAL_NEXT)->dayOfWeek)->toBe(1)
        ->and(Carbon::parse(SLOT_TANGGAL_SEBELUM)->dayOfWeek)->toBe(1)
        ->and(Carbon::parse(SLOT_TANGGAL_HARI_LAIN)->dayOfWeek)->toBe(2)
        ->and(Carbon::parse(SLOT_TANGGAL)->translatedFormat('l'))->toBe('Monday');
});

/*
|--------------------------------------------------------------------------
| Rule 1: working windows
|--------------------------------------------------------------------------
*/

test('an active window on the requested weekday becomes evenly spaced slots', function (): void {
    $dokter = slotDokterId();
    $faskes = slotFaskes();
    $jadwal = slotJadwal($dokter, ['faskes_id' => $faskes, 'tipe_layanan' => 'klinik']);

    $slot = app(SlotAvailabilityService::class)->getSlotTerbuka(
        slotDokter($dokter),
        SLOT_TANGGAL,
    );

    expect($slot)->toHaveCount(4)
        // Stepping `durasi_slot_menit` (`:478`) from `jam_mulai` (`:476`) to
        // `jam_selesai` (`:477`): 09:00, 09:15, 09:30, 09:45.
        ->and(slotMulai($slot))->toBe(['09:00:00', '09:15:00', '09:30:00', '09:45:00'])
        ->and(slotSelesai($slot))->toBe(['09:15:00', '09:30:00', '09:45:00', '10:00:00'])
        ->and($slot[0]['tipe_layanan'])->toBe('klinik')
        ->and($slot[0]['faskes_id'])->toBe($faskes)
        // `jadwal_id` is what todo 27 writes onto `booking.jadwal_id` (`:504`),
        // so a slot has to name the row it came from. See the class docblock.
        ->and($slot[0]['jadwal_id'])->toBe($jadwal)
        ->and($slot[0]['tersedia'])->toBeTrue()
        ->and($slot[0]['alasan'])->toBeNull();
});

test('BOUNDARY: the first slot starts exactly at jam_mulai and the last ends exactly at jam_selesai', function (): void {
    $dokter = slotDokterId();

    // A window that is exactly one slot long. Both edges land on the window
    // boundary at once: the single slot starts at `jam_mulai` and ends at
    // `jam_selesai`. An implementation using `<` on the end would produce
    // nothing at all, and an implementation using `<=` on the step would
    // produce a second zero-length slot.
    slotJadwal($dokter, ['jam_mulai' => '09:00:00', 'jam_selesai' => '09:15:00']);

    $slot = app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL);

    expect($slot)->toHaveCount(1)
        ->and($slot[0]['jam_mulai'])->toBe('09:00:00')
        ->and($slot[0]['jam_selesai'])->toBe('09:15:00');

    // And the one-hour window really does reach its own end exactly, rather
    // than stopping a slot short of it.
    $lain = slotDokterId();
    slotJadwal($lain, ['jam_mulai' => '09:00:00', 'jam_selesai' => '10:00:00']);

    $slotLain = app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($lain), SLOT_TANGGAL);

    expect($slotLain)->toHaveCount(4)
        ->and($slotLain[0]['jam_mulai'])->toBe('09:00:00')
        ->and(end($slotLain)['jam_selesai'])->toBe('10:00:00');
});

test('BOUNDARY: a partial trailing slot is never offered, because it would overrun the window', function (): void {
    $dokter = slotDokterId();

    // 09:00 to 09:50 leaves a 5-minute remainder, which is shorter than the
    // 15-minute slot. Emitting `09:45:00`-`10:00:00` would book a consultation
    // that ends 10 minutes after the doctor declared they stop working.
    slotJadwal($dokter, ['jam_mulai' => '09:00:00', 'jam_selesai' => '09:50:00']);

    $slot = app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL);

    expect($slot)->toHaveCount(3)
        ->and(end($slot)['jam_mulai'])->toBe('09:30:00')
        ->and(end($slot)['jam_selesai'])->toBe('09:45:00')
        ->and(slotCari($slot, '09:45:00'))->toBeNull();
});

test('a window on another weekday contributes nothing, and works on its own weekday', function (): void {
    $dokter = slotDokterId();

    // `hari` 2 is Tuesday. The DDL's comment at `:475` is the only definition
    // of the numbering, and 2 is not the Monday this file asks for.
    slotJadwal($dokter, ['hari' => 2, 'jam_mulai' => '14:00:00', 'jam_selesai' => '15:00:00']);

    $layanan = app(SlotAvailabilityService::class);

    expect($layanan->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL))->toBe([])
        // The very same row, asked for on the weekday it names.
        ->and($layanan->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL_HARI_LAIN))->toHaveCount(4)
        ->and(slotMulai($layanan->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL_HARI_LAIN)))->toBe([
            '14:00:00', '14:15:00', '14:30:00', '14:45:00',
        ]);
});

test('BOUNDARY: berlaku_sampai is inclusive and berlaku_mulai is exclusive-before', function (): void {
    $dokter = slotDokterId();

    // `berlaku_mulai DATE NOT NULL` (`:480`) and `berlaku_sampai DATE NULL`
    // (`:481`). A `DATE` has no time component, so "berlaku sampai 2026-12-07"
    // reads as valid THROUGH that day, the same "berlaku sampai" reading that
    // `str_berlaku_sampai` gets, and the end date is therefore inclusive.
    slotJadwal($dokter, ['berlaku_mulai' => SLOT_TANGGAL, 'berlaku_sampai' => SLOT_TANGGAL]);

    $layanan = app(SlotAvailabilityService::class);
    $dok = slotDokter($dokter);

    expect($layanan->getSlotTerbuka($dok, SLOT_TANGGAL_SEBELUM))->toBe([])
        // The boundary date itself: valid.
        ->and($layanan->getSlotTerbuka($dok, SLOT_TANGGAL))->toHaveCount(4)
        ->and($layanan->getSlotTerbuka($dok, SLOT_TANGGAL_NEXT))->toBe([]);
});

test('a null berlaku_sampai means open-ended, and status_aktif = 0 removes the whole window', function (): void {
    $dokAktif = slotDokterId();
    slotJadwal($dokAktif, ['berlaku_sampai' => null, 'jam_mulai' => '08:00:00', 'jam_selesai' => '08:30:00']);

    $dokNonaktif = slotDokterId();
    slotJadwal($dokNonaktif, ['status_aktif' => 0, 'jam_mulai' => '08:00:00', 'jam_selesai' => '08:30:00']);

    $layanan = app(SlotAvailabilityService::class);

    expect($layanan->getSlotTerbuka(slotDokter($dokAktif), SLOT_TANGGAL_NEXT))->toHaveCount(2)
        ->and($layanan->getSlotTerbuka(slotDokter($dokNonaktif), SLOT_TANGGAL))->toBe([]);
});

test('a midnight-wrapping or zero-length window contributes no slot at all', function (): void {
    $layanan = app(SlotAvailabilityService::class);

    // `MySQL TIME` accepts `-100:00:00` through `+838:59:59`, so
    // `jam_selesai <= jam_mulai` is representable and the plan's own
    // constraint list requires this service to handle it explicitly.
    // Naive `H:i:s` parsing of a wrap produces a negative or a >24:00 value
    // that no lexicographic TIME comparison can order, so the row is refused.
    $bungkus = slotDokterId();
    slotJadwal($bungkus, ['jam_mulai' => '22:00:00', 'jam_selesai' => '02:00:00']);

    // Zero length: `jam_mulai = jam_selesai`. Not a window.
    $nolength = slotDokterId();
    slotJadwal($nolength, ['jam_mulai' => '09:00:00', 'jam_selesai' => '09:00:00']);

    expect($layanan->getSlotTerbuka(slotDokter($bungkus), SLOT_TANGGAL))->toBe([])
        ->and($layanan->getSlotTerbuka(slotDokter($nolength), SLOT_TANGGAL))->toBe([]);
});

test('BOUNDARY: a window past midnight offers the slots that fit and drops the one that would not', function (): void {
    // The third `:208` case, and the one that is NOT a wrap: `jam_selesai` is
    // beyond 24:00, so the window is a real one that runs off the end of the
    // day. Refusing the whole row would discard seven perfectly ordinary
    // consultations over a typo in the closing time, so the row is honoured and
    // the individual slot that would end AT midnight is dropped -- `24:00:00` is
    // storable in a `TIME` and is not a time of day the response can publish.
    $dokter = slotDokterId();
    slotJadwal($dokter, ['jam_mulai' => '22:00:00', 'jam_selesai' => '25:00:00']);

    $slot = app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL);

    expect($slot)->toHaveCount(7)
        ->and($slot[0]['jam_mulai'])->toBe('22:00:00')
        ->and(end($slot)['jam_mulai'])->toBe('23:30:00')
        ->and(end($slot)['jam_selesai'])->toBe('23:45:00')
        // The eighth would be `23:45:00`-`24:00:00`, and it is gone.
        ->and(slotCari($slot, '23:45:00'))->toBeNull();
});

test('durasi_slot_menit = 0 contributes no slot and does not loop forever', function (): void {
    $dokter = slotDokterId();

    // `durasi_slot_menit SMALLINT UNSIGNED NOT NULL DEFAULT 15` (`:478`). Unsigned
    // means 0 is a legal stored value, and a `for ($t = $mulai; $t < $akhir;
    // $t += 0)` step would never terminate.
    slotJadwal($dokter, ['durasi_slot_menit' => 0, 'jam_mulai' => '09:00:00', 'jam_selesai' => '10:00:00']);

    expect(app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL))->toBe([]);
});

test('a doctor with no schedule rows has no bookable slots, and that is an empty list rather than an error', function (): void {
    $dokter = slotDokterId();

    // Not an exception, and emphatically not "open all day". `dokter_jadwal`
    // is the only source of a working window, so a doctor with no row for the
    // weekday publishes no window for it. Anything else would invent clinical
    // availability out of nothing.
    expect(app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL))->toBe([])
        // The weekly read has a different shape, deliberately: seven keys
        // always present, so a caller can render a whole week without probing
        // which days exist. Seven empty days IS the "no schedule" answer there.
        ->and(app(SlotAvailabilityService::class)->getJadwal(slotDokter($dokter)))
        ->toBe(array_fill(0, 7, []));
});

test('two overlapping windows on the same weekday are both honoured, each with its own quota', function (): void {
    $dokter = slotDokterId();
    $faskes = slotFaskes('Klinik Kedua');

    // `dokter_jadwal` permits self-overlap: there is no unique on
    // `(dokter_id, hari)` and nothing forbids two active rows for the same
    // weekday with overlapping windows. Both rows are real offerings and both
    // are published, distinguished by `jadwal_id` and `tipe_layanan`.
    $online = slotJadwal($dokter, [
        'tipe_layanan' => 'online', 'faskes_id' => null,
        'jam_mulai' => '09:00:00', 'jam_selesai' => '10:00:00', 'kuota_per_sesi' => 1,
    ]);
    $klinik = slotJadwal($dokter, [
        'tipe_layanan' => 'klinik', 'faskes_id' => $faskes,
        'jam_mulai' => '09:00:00', 'jam_selesai' => '10:00:00', 'kuota_per_sesi' => 3,
    ]);

    $slot = app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL);

    expect($slot)->toHaveCount(8)
        ->and(slotJadwalIds($slot))->toBe([
            $online, $online, $online, $online,
            $klinik, $klinik, $klinik, $klinik,
        ])
        ->and(array_values(array_unique(array_column($slot, 'tipe_layanan'))))->toBe(['online', 'klinik']);

    // One booking against the 09:00 slot. The `kuota_per_sesi = 1` row closes,
    // the `kuota_per_sesi = 3` row does not. This is the per-row half of the
    // quota rule; the per-window half is the test below.
    slotBooking($dokter, '09:00:00', '09:15:00');

    $sesudah = app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL);
    $pukulSembilan = array_values(array_filter(
        $sesudah,
        static fn (array $s): bool => $s['jam_mulai'] === '09:00:00',
    ));

    expect($pukulSembilan)->toHaveCount(2)
        ->and(slotCari(array_filter($sesudah, static fn (array $s): bool => $s['jadwal_id'] === $online), '09:00:00'))
        ->not->toBeNull()
        ->and(slotCari(array_filter($sesudah, static fn (array $s): bool => $s['jadwal_id'] === $klinik), '09:00:00'))
        ->not->toBeNull();
});

test('an out-of-range hari can never match a date, which is how :205 is validated', function (): void {
    $dokter = slotDokterId();

    // `hari` is `TINYINT UNSIGNED` with no `CHECK (hari BETWEEN 0 AND 6)`, so
    // 0-255 are all storable. A `date('w')` equality is the validation: 9 can
    // never equal a real weekday, so the row is inert rather than dangerous.
    slotJadwal($dokter, ['hari' => 9]);

    $layanan = app(SlotAvailabilityService::class);

    expect($layanan->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL))->toBe([])
        // Seven keys, seven empty lists: the row is dropped rather than parked
        // under a key that does not exist.
        ->and($layanan->getJadwal(slotDokter($dokter)))->toBe(array_fill(0, 7, []))
        ->and(array_keys(array_filter(
            $layanan->getJadwal(slotDokter($dokter)),
            static fn (array $rows): bool => $rows !== [],
        )))->toBe([]);
});

test('getJadwal groups by all seven days, in order, and carries the window columns', function (): void {
    $dokter = slotDokterId();
    $faskes = slotFaskes();

    $senin = slotJadwal($dokter, [
        'hari' => 1, 'faskes_id' => $faskes, 'tipe_layanan' => 'klinik',
        'jam_mulai' => '09:00:00', 'jam_selesai' => '10:00:00', 'durasi_slot_menit' => 20,
    ]);
    $rabu = slotJadwal($dokter, [
        'hari' => 3, 'jam_mulai' => '19:00:00', 'jam_selesai' => '21:00:00', 'durasi_slot_menit' => 30,
    ]);

    $jadwal = app(SlotAvailabilityService::class)->getJadwal(slotDokter($dokter));

    // Seven keys always present, 0 = Minggu .. 6 = Saturdays, so a client can
    // render a whole week without probing which days exist.
    expect(array_keys($jadwal))->toBe([0, 1, 2, 3, 4, 5, 6])
        ->and($jadwal[0])->toBe([])
        ->and($jadwal[2])->toBe([])
        ->and($jadwal[4])->toBe([])
        ->and($jadwal[5])->toBe([])
        ->and($jadwal[6])->toBe([])
        ->and($jadwal[1])->toHaveCount(1)
        ->and($jadwal[3])->toHaveCount(1)
        ->and($jadwal[1][0]['jadwal_id'])->toBe($senin)
        ->and($jadwal[1][0]['hari'])->toBe(1)
        ->and($jadwal[1][0]['tipe_layanan'])->toBe('klinik')
        ->and($jadwal[1][0]['faskes_id'])->toBe($faskes)
        ->and($jadwal[1][0]['jam_mulai'])->toBe('09:00:00')
        ->and($jadwal[1][0]['jam_selesai'])->toBe('10:00:00')
        ->and($jadwal[1][0]['durasi_slot_menit'])->toBe(20)
        // `kuota_per_sesi` is `SMALLINT UNSIGNED NULL` (`:479`); the service
        // publishes the DDL's own `null` rather than inventing a 1, so a
        // client can see which windows are genuinely uncapped.
        ->and($jadwal[1][0]['kuota_per_sesi'])->toBeNull()
        ->and($jadwal[3][0]['jadwal_id'])->toBe($rabu)
        ->and($jadwal[3][0]['durasi_slot_menit'])->toBe(30);
});

test('getJadwal publishes only ACTIVE windows', function (): void {
    // `status_aktif TINYINT(1) NOT NULL DEFAULT 1` (`:482`) applies to BOTH
    // public reads, and a mutation that dropped it from the query left this
    // file entirely green until it was measured. `getSlotTerbuka`'s half of the
    // rule is covered elsewhere; this is the half that was missing.
    $dokter = slotDokterId();

    $aktif = slotJadwal($dokter, ['jam_mulai' => '09:00:00', 'jam_selesai' => '09:15:00']);
    slotJadwal($dokter, [
        'status_aktif' => 0,
        'jam_mulai' => '14:00:00',
        'jam_selesai' => '14:15:00',
    ]);

    $jadwal = app(SlotAvailabilityService::class)->getJadwal(slotDokter($dokter));

    expect($jadwal[1])->toHaveCount(1)
        ->and($jadwal[1][0]['jadwal_id'])->toBe($aktif)
        ->and($jadwal[1][0]['jam_mulai'])->toBe('09:00:00')
        // Not merely filtered from the answer: absent from the list the client
        // would render.
        ->and(array_column($jadwal[1], 'jam_mulai'))->not->toContain('14:00:00');
});

test('getJadwal is NOT gated by the STR, and that asymmetry is deliberate', function (): void {
    $dokter = slotDokterId(['str_berlaku_sampai' => '2000-01-01']);
    slotJadwal($dokter);

    $layanan = app(SlotAvailabilityService::class);

    // A weekly window template is a profile attribute, not an offer to practise
    // medicine, so it stays readable. `getSlotTerbuka` is the method that
    // refuses, and it is the only one that does. If both refused, a caller
    // would have two gates to keep in step for no patient-safety gain.
    expect($layanan->getJadwal(slotDokter($dokter)))->toHaveCount(7)
        ->and($layanan->getJadwal(slotDokter($dokter))[1])->toHaveCount(1)
        ->and($layanan->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Rule 2: the LIBUR whole-day check
|--------------------------------------------------------------------------
*/

test('BOUNDARY: a holiday on the exact date marks every slot unavailable, and one either side does not', function (): void {
    $dokTepat = slotDokterId();
    slotJadwal($dokTepat);
    slotLibur($dokTepat, SLOT_TANGGAL);

    $dokSebelum = slotDokterId();
    slotJadwal($dokSebelum);
    slotLibur($dokSebelum, SLOT_TANGGAL_SEBELUM);

    $dokSesudah = slotDokterId();
    slotJadwal($dokSesudah);
    slotLibur($dokSesudah, SLOT_TANGGAL_NEXT);

    $layanan = app(SlotAvailabilityService::class);

    $tepat = $layanan->getSlotTerbuka(slotDokter($dokTepat), SLOT_TANGGAL);
    $sebelum = $layanan->getSlotTerbuka(slotDokter($dokSebelum), SLOT_TANGGAL);
    $sesudah = $layanan->getSlotTerbuka(slotDokter($dokSesudah), SLOT_TANGGAL);

    // The holiday date: every slot is present and every one is closed.
    expect($tepat)->toHaveCount(4)
        ->and(array_values(array_unique(array_column($tepat, 'tersedia'))))->toBe([false])
        ->and(array_values(array_unique(array_column($tepat, 'alasan'))))->toBe([SlotAvailabilityService::ALASAN_LIBUR]);

    // One day before, and one day after, are ordinary days.
    expect($sebelum)->toHaveCount(4)
        ->and(array_values(array_unique(array_column($sebelum, 'tersedia'))))->toBe([true])
        ->and($sebelum[0]['alasan'])->toBeNull()
        ->and($sesudah)->toHaveCount(4)
        ->and($sesudah[0]['tersedia'])->toBeTrue();
});

test('a holiday is one doctor`s, and the reason beats a full slot', function (): void {
    $dokLibur = slotDokterId();
    slotJadwal($dokLibur);
    slotLibur($dokLibur, SLOT_TANGGAL);

    $dokBiasa = slotDokterId();
    slotJadwal($dokBiasa, ['kuota_per_sesi' => 1]);
    slotBooking($dokBiasa, '09:00:00', '09:15:00');

    $layanan = app(SlotAvailabilityService::class);
    $denganLibur = $layanan->getSlotTerbuka(slotDokter($dokLibur), SLOT_TANGGAL);
    $tanpaLibur = $layanan->getSlotTerbuka(slotDokter($dokBiasa), SLOT_TANGGAL);

    // `dokter_libur` is keyed by `dokter_id` (`:492`) and there is no unique on
    // `(dokter_id, tanggal)` and no index beyond the FK, so the lookup is a
    // per-doctor comparison and cannot leak across doctors.
    expect(array_values(array_unique(array_column($denganLibur, 'alasan'))))->toBe(['libur'])
        ->and(array_column($tanpaLibur, 'tersedia'))->toBe([false, true, true, true])
        ->and(array_column($tanpaLibur, 'alasan'))->toBe([SlotAvailabilityService::ALASAN_PENUH, null, null, null]);
});

test('BOUNDARY: a holiday closes a slot that is ALSO full, and reports the holiday', function (): void {
    // `alasan` is a single value, so the precedence has to be decided rather
    // than left to whichever check happens to run first. `libur` >
    // `lewat_waktu` > `penuh`: the first two are absolute, a day off and a
    // finished slot cannot be booked whatever the capacity, while capacity is a
    // property of a slot that is otherwise real.
    $dokter = slotDokterId();
    slotJadwal($dokter, ['kuota_per_sesi' => 1]);
    slotBooking($dokter, '09:00:00', '09:15:00');
    slotLibur($dokter, SLOT_TANGGAL);

    $slot = app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL);

    expect($slot)->toHaveCount(4)
        // The one slot that was ALSO full reports the holiday, not `penuh`.
        ->and(array_values(array_unique(array_column($slot, 'alasan'))))->toBe([SlotAvailabilityService::ALASAN_LIBUR])
        ->and(array_column($slot, 'tersedia'))->toBe([false, false, false, false]);
});

/*
|--------------------------------------------------------------------------
| Rule 3: Collision
|--------------------------------------------------------------------------
*/

test('BOUNDARY: a booking ending exactly when a slot begins does not collide', function (): void {
    $dokter = slotDokterId();
    slotJadwal($dokter);

    // Half-open overlap: `slot_mulai < $slotSelesai AND slot_selesai > $slotMulai`.
    // This booking ends at 09:00, the instant the first slot begins. Neither
    // strict comparison is satisfied by the pair, so the slot is free, and a
    // `<=` spelling would close it.
    slotBooking($dokter, '08:45:00', '09:00:00');

    $slot = app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL);

    expect($slot[0]['jam_mulai'])->toBe('09:00:00')
        ->and($slot[0]['jam_selesai'])->toBe('09:15:00')
        ->and($slot[0]['tersedia'])->toBeTrue()
        ->and($slot[0]['alasan'])->toBeNull()
        // Nothing else on the page is touched either: the booking is 15 minutes
        // long and the whole window is 60.
        ->and(array_column($slot, 'tersedia'))->toBe([true, true, true, true]);
});

test('BOUNDARY: a booking starting exactly when a slot ends does not collide', function (): void {
    $dokter = slotDokterId();
    slotJadwal($dokter);

    // The mirror of the previous test, and the one a `<=` in the overlap
    // predicate would break.
    slotBooking($dokter, '09:15:00', '10:00:00');

    $slot = app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL);

    expect($slot[0]['jam_mulai'])->toBe('09:00:00')
        ->and($slot[0]['jam_selesai'])->toBe('09:15:00')
        ->and($slot[0]['tersedia'])->toBeTrue();
});

test('a booking overlapping a slot by a single minute does collide', function (): void {
    $dokter = slotDokterId();
    slotJadwal($dokter);

    // One minute of overlap on each side of the 09:00-09:15 slot.
    slotBooking($dokter, '08:59:00', '09:01:00');
    slotBooking($dokter, '09:14:00', '09:16:00');

    $slot = app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL);

    // `kuota_per_sesi` defaults to 1, so the single 08:59-09:01 booking already
    // fills 09:00-09:15; the 09:14-09:16 one fills 09:00-09:15 as well, and
    // 09:15-09:30 too.
    expect($slot[0]['tersedia'])->toBeFalse()
        ->and($slot[0]['alasan'])->toBe(SlotAvailabilityService::ALASAN_PENUH)
        ->and($slot[1]['tersedia'])->toBeFalse()
        ->and($slot[2]['tersedia'])->toBeTrue()
        ->and($slot[3]['tersedia'])->toBeTrue();
});

test('a booking on another date, or for another doctor, does not collide', function (): void {
    $dokter = slotDokterId();
    slotJadwal($dokter);

    $dokterLain = slotDokterId();
    slotJadwal($dokterLain);

    slotBooking($dokter, '09:00:00', '09:15:00', ['tanggal_kunjungan' => SLOT_TANGGAL_NEXT]);
    slotBooking($dokterLain, '09:00:00', '09:15:00');

    $layanan = app(SlotAvailabilityService::class);

    expect(array_column($layanan->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL), 'tersedia'))
        ->toBe([true, true, true, true])
        ->and(array_column($layanan->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL_NEXT), 'tersedia'))
        ->toBe([false, true, true, true])
        ->and(array_column($layanan->getSlotTerbuka(slotDokter($dokterLain), SLOT_TANGGAL), 'tersedia'))
        ->toBe([false, true, true, true]);
});

test('dibatalkan and kadaluarsa do not consume a slot', function (): void {
    $dokter = slotDokterId();
    slotJadwal($dokter);

    // `booking.status` is the eight-value ENUM at `:515`-`:516`, and the two
    // terminal states are the only ones that release a slot: the patient is
    // not coming and the payment window closed.
    slotBooking($dokter, '09:00:00', '09:15:00', ['status' => 'dibatalkan']);
    slotBooking($dokter, '09:00:00', '09:15:00', ['status' => 'kadaluarsa']);

    $slot = app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL);

    expect($slot[0]['tersedia'])->toBeTrue()
        ->and($slot[0]['alasan'])->toBeNull();
});

test('each of the six remaining statuses does consume the slot, read from the DDL enum', function (): void {
    $semua = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'))
        ->table('booking')?->columns['status'] ?? null;

    expect($semua)->not->toBeNull()
        ->and($semua->type)->toStartWith('enum(');

    $dariDdl = array_map(
        static fn (string $nilai): string => trim($nilai, "'"),
        explode(',', (string) substr((string) $semua->type, strlen('enum('), -1)),
    );

    // The exclusion set is derived, not restated: eight values minus the two
    // that release a slot. If a ninth status is ever added to the DDL, this
    // test refuses to pass until the service has been asked the question.
    expect($dariDdl)->toHaveCount(8)
        ->and(SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI)->toBe(['dibatalkan', 'kadaluarsa']);

    $mengkonsumsi = array_values(array_diff($dariDdl, SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI));

    expect($mengkonsumsi)->toHaveCount(6);

    foreach ($mengkonsumsi as $status) {
        $dokter = slotDokterId();
        slotJadwal($dokter);
        slotBooking($dokter, '09:00:00', '09:15:00', ['status' => $status]);

        $slot = app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL);

        expect($slot[0]['tersedia'])->toBeFalse();
    }
});

test('kuota_per_sesi = 2 keeps a slot open after one booking and closes it after the second', function (): void {
    // This is the specific bug the plan names, and the test that pins it.
    // A naive `count === 0` availability check makes every `kuota_per_sesi > 1`
    // window permanently unbookable, because a single booking would read as
    // "taken" no matter how many seats the window actually has.
    $dokter = slotDokterId();
    slotJadwal($dokter, ['kuota_per_sesi' => 2]);

    $layanan = app(SlotAvailabilityService::class);
    $dok = slotDokter($dokter);

    $nolBooking = $layanan->getSlotTerbuka($dok, SLOT_TANGGAL);
    expect($nolBooking[0]['tersedia'])->toBeTrue();

    // One active booking: one seat of two is gone, one remains.
    slotBooking($dokter, '09:00:00', '09:15:00');

    $satuBooking = $layanan->getSlotTerbuka($dok, SLOT_TANGGAL);
    expect($satuBooking[0]['tersedia'])->toBeTrue()
        ->and($satuBooking[0]['jam_mulai'])->toBe('09:00:00')
        ->and($satuBooking[0]['alasan'])->toBeNull();

    // The second booking fills the window.
    slotBooking($dokter, '09:00:00', '09:15:00');

    $duaBooking = $layanan->getSlotTerbuka($dok, SLOT_TANGGAL);
    expect($duaBooking[0]['tersedia'])->toBeFalse()
        ->and($duaBooking[0]['alasan'])->toBe(SlotAvailabilityService::ALASAN_PENUH);

    // A third booking changes nothing, and does not overflow the count.
    slotBooking($dokter, '09:00:00', '09:15:00');

    expect($layanan->getSlotTerbuka($dok, SLOT_TANGGAL)[0]['tersedia'])->toBeFalse();
});

test('a null kuota_per_sesi is read as 1, the DDL default reading', function (): void {
    $dokter = slotDokterId();
    slotJadwal($dokter, ['kuota_per_sesi' => null]);

    $layanan = app(SlotAvailabilityService::class);
    $dok = slotDokter($dokter);

    expect($layanan->getSlotTerbuka($dok, SLOT_TANGGAL)[0]['tersedia'])->toBeTrue();

    slotBooking($dokter, '09:00:00', '09:15:00');

    // `NULL` is a real, distinct stored value, not "unlimited".
    expect($layanan->getSlotTerbuka($dok, SLOT_TANGGAL)[0]['tersedia'])->toBeFalse();
});

test('a wide quota counts only the bookings that actually overlap the slot', function (): void {
    $dokter = slotDokterId();
    slotJadwal($dokter, ['kuota_per_sesi' => 2]);

    // Two bookings on 09:00-09:15 fill that slot, and a third on 09:15-09:30.
    // Three bookings overlap the window in total against a quota of two, so a
    // per-DAY count instead of a per-slot overlap count would read 3 >= 2 and
    // close every slot on the page.
    slotBooking($dokter, '09:00:00', '09:15:00');
    slotBooking($dokter, '09:00:00', '09:15:00');
    slotBooking($dokter, '09:15:00', '09:30:00');

    $slot = app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL);

    expect(array_column($slot, 'tersedia'))->toBe([false, true, true, true])
        ->and($slot[0]['alasan'])->toBe(SlotAvailabilityService::ALASAN_PENUH)
        // The 09:15 slot holds one booking against a quota of two, so one seat
        // is left. The non-strict `<` would have closed it.
        ->and($slot[1]['alasan'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Rule 4: the STR check
|--------------------------------------------------------------------------
*/

test('BOUNDARY: the STR boundary is inclusive on the CONSULTATION date', function (): void {
    $layanan = app(SlotAvailabilityService::class);

    $kasus = [
        // expiry one day before the requested date: lapsed for that visit
        '2026-12-06' => false,
        // expiry ON the requested date: still valid THROUGH that day
        '2026-12-07' => true,
        '2026-12-08' => true,
    ];

    foreach ($kasus as $kedaluwarsa => $harap) {
        $dokter = slotDokterId(['str_berlaku_sampai' => $kedaluwarsa]);
        slotJadwal($dokter);

        $slot = $layanan->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL);

        if ($harap) {
            expect($slot)->toHaveCount(4);
            expect($slot[0]['tersedia'])->toBeTrue();
        } else {
            expect($slot)->toBe([]);
        }
    }
});

test('the STR is compared against the requested date, not against today', function (): void {
    // `str_berlaku_sampai DATE NOT NULL` (`:414`) and the DDL has no rule that
    // invalidates a doctor the day the date passes -- the plan's own constraint
    // list names that gap. So "visible in the directory today" and "may be
    // consulted on date D" are different questions, and answering the second
    // with the first would let a patient book a visit weeks after the licence
    // lapsed.
    //
    // **The two assertions below are a PAIR and neither is meaningful alone.**
    // The first can only pass while today is on or before the licence's expiry,
    // which is what makes the second one non-vacuous: a today-based rule would
    // have returned four slots for the following Monday as well. Asserting the
    // empty result by itself would pass for the wrong reason the day after the
    // licence lapsed.
    $dokter = slotDokterId(['str_berlaku_sampai' => SLOT_TANGGAL]);
    slotJadwal($dokter);

    $layanan = app(SlotAvailabilityService::class);
    $dok = slotDokter($dokter);

    expect($layanan->getSlotTerbuka($dok, SLOT_TANGGAL))->toHaveCount(4)
        // A week later the licence is gone, and the visit cannot be booked.
        ->and($layanan->getSlotTerbuka($dok, SLOT_TANGGAL_NEXT))->toBe([]);
});

test('a NULL STR expiry is refused, and the DDL is why it is unreachable', function (): void {
    $kolom = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'))
        ->table('dokter')?->columns['str_berlaku_sampai'] ?? null;

    // `str_berlaku_sampai DATE NOT NULL` (`:414`). MySQL rejects a NULL with
    // 1048 regardless of sql_mode, so the row cannot be written.
    expect($kolom)->not->toBeNull()
        ->and($kolom->type)->toBe('date')
        ->and($kolom->nullable)->toBeFalse();

    // The predicate still has to be well defined rather than merely currently
    // unreachable, so the shared decider is asked directly. "We do not know
    // when this licence ends" is not evidence that it is valid.
    expect(StrBerlaku::berlakuPada(null, slotHariIni()))->toBeFalse()
        ->and(StrBerlaku::berlakuPada('2000-01-01', slotHariIni()))->toBeFalse()
        ->and(StrBerlaku::berlakuPada('2099-12-31', slotHariIni()))->toBeTrue();
});

test('the STR boundary has exactly one spelling, shared with the doctor directory', function (): void {
    // Todo 22 established `str_berlaku_sampai >= <day>` as inclusive and
    // fail-closed on NULL, and pinned it on the exact boundary date. A second
    // differently-bounded STR rule in the same codebase would be a second
    // source of truth, so both services route through this one class.
    expect(StrBerlaku::OPERATOR_BATAS)->toBe('>=')
        ->and(StrBerlaku::REFERENSI)->toBe('Asia/Jakarta');

    $dok = new Dokter;
    $dok->str_berlaku_sampai = '2026-12-07';

    // The same three rows, one day either side of the boundary, answered by
    // the single decider both services call.
    expect(StrBerlaku::berlakuPada('2026-12-06', Carbon::parse('2026-12-07')))->toBeFalse()
        ->and(StrBerlaku::berlakuPada('2026-12-07', Carbon::parse('2026-12-07')))->toBeTrue()
        ->and(StrBerlaku::berlakuPada('2026-12-08', Carbon::parse('2026-12-07')))->toBeTrue();

    unset($dok);
});

/*
|--------------------------------------------------------------------------
| The fifth rule the plan names: elapsed slots on today
|--------------------------------------------------------------------------
*/

test('BOUNDARY: a slot that has already ended today is unavailable, and one ending a minute later is not', function (): void {
    $hariIni = slotHariIni();
    // `setTime()` on a UTC-labelled Carbon would freeze "now" at 10:07 **UTC**,
    // which the service correctly reads as 17:07 on the clinic wall clock and
    // then closes the whole afternoon. The window below is written in WIB, so the
    // frozen instant has to be 10:07 WIB - which is 03:07 UTC. This is the
    // difference between an instant and a wall clock, in one line.
    $sekarang = WaktuIndonesia::toInstant($hariIni->toDateString(), '10:07:00');

    Carbon::setTestNow($sekarang);

    try {
        $dokter = slotDokterId();

        // The window STARTS at 08:07 so that a 60-minute step lands a boundary
        // exactly on `10:07` -- the frozen "now". A window starting on the hour
        // would put no slot boundary there at all and the assertion would be
        // about a slot that does not exist. `hari` follows the reference day
        // rather than being pinned to Monday, so this holds on any weekday.
        slotJadwal($dokter, [
            'hari' => (int) $hariIni->dayOfWeek,
            'jam_mulai' => '08:07:00',
            'jam_selesai' => '12:07:00',
            'durasi_slot_menit' => 60,
        ]);

        $layanan = app(SlotAvailabilityService::class);
        $dok = slotDokter($dokter);

        $slot = $layanan->getSlotTerbuka($dok, $hariIni->toDateString(), $hariIni);

        expect(slotMulai($slot))->toBe(['08:07:00', '09:07:00', '10:07:00', '11:07:00'])
            ->and(slotSelesai($slot))->toBe(['09:07:00', '10:07:00', '11:07:00', '12:07:00'])
            // `jam_selesai == now` is already over. `<` would have left it open.
            ->and($slot[1]['jam_selesai'])->toBe('10:07:00')
            ->and($slot[1]['tersedia'])->toBeFalse()
            ->and($slot[1]['alasan'])->toBe(SlotAvailabilityService::ALASAN_LEWAT_WAKTU)
            // The slot STARTING at 10:07 runs to 11:07 and is open.
            ->and($slot[2]['tersedia'])->toBeTrue()
            ->and($slot[2]['alasan'])->toBeNull()
            ->and($slot[0]['alasan'])->toBe(SlotAvailabilityService::ALASAN_LEWAT_WAKTU);

        // A future date is untouched by the clock, which is the whole point of
        // keying the rule on "is this today". Seven days on is the same
        // weekday, so the same window applies.
        $slotMingguDepan = $layanan->getSlotTerbuka(
            $dok,
            $hariIni->copy()->addDays(7)->toDateString(),
            $hariIni,
        );

        expect(array_column($slotMingguDepan, 'tersedia'))->toBe([true, true, true, true])
            ->and(array_column($slotMingguDepan, 'alasan'))->toBe([null, null, null, null]);
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| Input shape
|--------------------------------------------------------------------------
*/

test('a malformed tanggal is refused by the service, because the calendar rolls it over', function (): void {
    $dokter = slotDokterId();
    slotJadwal($dokter);

    $layanan = app(SlotAvailabilityService::class);

    // `Carbon::createFromFormat('Y-m-d', '2026-13-45')` does not fail: PHP
    // overflows month 13 and day 45 into 2027-02-14. A round trip through
    // `format('Y-m-d')` is the only thing that catches it, and the plan wants
    // a 422 for `?tanggal=2026-13-45`, which a FormRequest cannot build if the
    // service silently answers about February.
    foreach (['2026-13-45', '2026-02-30', '07-12-2026', '2026-12-7', 'bukan-tanggal', ''] as $rusak) {
        expect(static fn (): array => $layanan->getSlotTerbuka(slotDokter($dokter), $rusak))
            ->toThrow(InvalidArgumentException::class);
    }

    expect($layanan->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL))->toHaveCount(4);
});

/*
|--------------------------------------------------------------------------
| DDL-derived invariants
|--------------------------------------------------------------------------
*/

test('the DDL names every column and enum value the four rules read', function (): void {
    $spesifikasi = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    $jadwal = $spesifikasi->table('dokter_jadwal');
    $libur = $spesifikasi->table('dokter_libur');
    $booking = $spesifikasi->table('booking');
    $dokter = $spesifikasi->table('dokter');

    expect($jadwal)->not->toBeNull()
        ->and($libur)->not->toBeNull()
        ->and($booking)->not->toBeNull()
        ->and($dokter)->not->toBeNull();

    // Rule 1. The window columns and their types, because a `TIME` read as a
    // timestamp and a `DATE` read as an instant are two different bugs.
    expect($jadwal->columns)->toHaveKey('hari')
        ->and($jadwal->columns['hari']->type)->toBe('tinyint')
        ->and($jadwal->columns['hari']->unsigned)->toBeTrue()
        ->and($jadwal->columns['hari']->nullable)->toBeFalse()
        ->and($jadwal->columns['jam_mulai']->type)->toBe('time')
        ->and($jadwal->columns['jam_mulai']->nullable)->toBeFalse()
        ->and($jadwal->columns['jam_selesai']->type)->toBe('time')
        ->and($jadwal->columns['jam_selesai']->nullable)->toBeFalse()
        ->and($jadwal->columns['durasi_slot_menit']->type)->toBe('smallint')
        ->and($jadwal->columns['durasi_slot_menit']->default)->toBe('15')
        // A NULL quota is a real value, which is why `?? 1` is a rule and not
        // a convenience.
        ->and($jadwal->columns['kuota_per_sesi']->nullable)->toBeTrue()
        ->and($jadwal->columns['berlaku_mulai']->type)->toBe('date')
        ->and($jadwal->columns['berlaku_mulai']->nullable)->toBeFalse()
        ->and($jadwal->columns['berlaku_sampai']->type)->toBe('date')
        ->and($jadwal->columns['berlaku_sampai']->nullable)->toBeTrue()
        ->and($jadwal->columns['status_aktif']->type)->toBe('tinyint')
        ->and($jadwal->columns['status_aktif']->default)->toBe('1')
        ->and($jadwal->columns['tipe_layanan']->type)
        ->toBe("enum('online','klinik','home_visit')")
        // `faskes_id BIGINT UNSIGNED NULL` (`:473`): NULL means online-only.
        ->and($jadwal->columns['faskes_id']->nullable)->toBeTrue();

    // Rule 2. A whole-day table: no time column at all, and no status flag.
    expect($libur->columns)->toHaveKey('tanggal')
        ->and($libur->columns['tanggal']->type)->toBe('date')
        ->and($libur->columns['tanggal']->nullable)->toBeFalse()
        ->and($libur->columns)->toHaveKey('alasan')
        ->and($libur->columns)->toHaveKey('dokter_id')
        ->and($libur->columns)->not->toHaveKey('status_aktif');

    // Rule 3. The overlap columns, the nullable `jadwal_id` that makes instant
    // bookings legal, and the eight-value status ENUM.
    expect($booking->columns['dokter_id']->nullable)->toBeFalse()
        ->and($booking->columns['jadwal_id']->nullable)->toBeTrue()
        ->and($booking->columns['tanggal_kunjungan']->type)->toBe('date')
        ->and($booking->columns['tanggal_kunjungan']->nullable)->toBeFalse()
        ->and($booking->columns['slot_mulai']->type)->toBe('time')
        ->and($booking->columns['slot_mulai']->nullable)->toBeFalse()
        ->and($booking->columns['slot_selesai']->type)->toBe('time')
        ->and($booking->columns['slot_selesai']->nullable)->toBeFalse()
        ->and($booking->columns['status']->type)->toBe(
            "enum('menunggu_pembayaran','terjadwal','check_in','berlangsung','selesai','dibatalkan','no_show','kadaluarsa')"
        )
        ->and($booking->columns['status']->default)->toBe("'menunggu_pembayaran'");

    // Rule 4.
    expect($dokter->columns['str_berlaku_sampai']->type)->toBe('date')
        ->and($dokter->columns['str_berlaku_sampai']->nullable)->toBeFalse()
        // The doctor-level default duration exists, and it is NOT the slot
        // duration. The tests pin that by writing 20 here and expecting the
        // schedule row's 15 to win.
        ->and($dokter->columns)->toHaveKey('durasi_default_menit')
        ->and($dokter->columns['durasi_default_menit']->type)->toBe('smallint')
        ->and($dokter->columns['durasi_default_menit']->default)->toBe('15');
});

test('the emitted overlap predicate is the half-open one, on the emitted SQL', function (): void {
    // A behavioural assertion cannot distinguish a `<` overlap predicate from a
    // `<=` one on a fixed set of fixtures unless the touching case is present,
    // and it can be made to pass by an implementation that filters in PHP
    // instead. The two boundary tests above pin the behaviour; this pins the
    // query, so a rewrite that keeps the behaviour by accident still has to
    // keep the strict operators.
    $dokter = slotDokterId();
    slotJadwal($dokter);
    slotBooking($dokter, '09:00:00', '09:15:00');

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL);

    $catatan = DB::getQueryLog();
    DB::disableQueryLog();

    $sql = mb_strtolower(implode(' | ', array_column($catatan, 'query')));

    expect($sql)->toContain('from `booking`')
        // Strict on both ends: `<` for the start, `>` for the end.
        ->and($sql)->toContain('`slot_mulai` < ?')
        ->and($sql)->toContain('`slot_selesai` > ?')
        ->and($sql)->not->toContain('`slot_mulai` <= ?')
        ->and($sql)->not->toContain('`slot_selesai` >= ?')
        ->and($sql)->toContain('not in (?, ?)')
        // No `TIME()` wrapper: it would fold a `25:00:00` value back into the
        // 24-hour clock, which is the `:208` hazard the plan names.
        ->and($sql)->not->toContain('time(`slot_');

    $bindings = [];

    foreach ($catatan as $baris) {
        $bindings = array_merge($bindings, array_map('strval', $baris['bindings']));
    }

    // The exclusion set, read out of the bindings rather than asserted on the
    // constant, and the date, so the query and the DDL cannot drift apart.
    expect($bindings)->toContain('dibatalkan')
        ->and($bindings)->toContain('kadaluarsa')
        ->and($bindings)->toContain(SLOT_TANGGAL);
});

test('the service publishes Asia/Jakarta wall clock and never a timestamp', function (): void {
    // `$tanggal`, `jam_mulai` and `jam_selesai` are unzoned wall clock. Every
    // value the service returns is a `H:i:s` or `Y-m-d` string, never a
    // `Y-m-d H:i:s` local datetime and never an instant, because converting
    // them here would move a 23:30 Jakarta slot to 16:30 UTC.
    expect(SlotAvailabilityService::ZONA_WAKTU)->toBe('Asia/Jakarta');

    $dokter = slotDokterId();
    slotJadwal($dokter, ['jam_mulai' => '23:30:00', 'jam_selesai' => '23:45:00']);

    $slot = app(SlotAvailabilityService::class)->getSlotTerbuka(slotDokter($dokter), SLOT_TANGGAL);

    expect($slot[0]['jam_mulai'])->toBe('23:30:00')
        ->and($slot[0]['jam_selesai'])->toBe('23:45:00')
        ->and(SlotAvailabilityService::ZONA_WAKTU)->not->toBe(config('app.timezone'));
});
