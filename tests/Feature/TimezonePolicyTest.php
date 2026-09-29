<?php

declare(strict_types=1);

use App\Models\Dokter;
use App\Models\MasterPromo;
use App\Services\Booking\SlotAvailabilityService;
use App\Services\Invoice\PromoService;
use App\Support\Schema\SqlSchemaParser;
use App\Support\WaktuIndonesia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| TimezonePolicyTest
|--------------------------------------------------------------------------
|
| The two rules of `docs/timezone-policy.md`, proved rather than asserted.
|
| **Rule 1 - an instant is stored UTC and published with a `Z` suffix.**
| **Rule 2 - a wall clock is stored and published exactly as authored.**
|
| ## Why the allow-lists below are written out in full
|
| The instant list cannot be derived from a name pattern. `master_promo.mulai_at`
| ends in `_at`, is a `DATETIME`, and is a **wall clock** an operator types in
| Indonesian local time. A suffix-based test ("every `_at` ends in `Z`") would pass
| the code and pin the bug. So the list is explicit, and the tests below assert it
| against the DDL itself -- a typo in a column name fails here rather than silently
| shrinking the coverage of the rule it is supposed to pin.
|
| ## Why the DDL is read through `SqlSchemaParser` and not grepped
|
| Same reason as `SlotAvailabilityTest`: the parsed model is what
| `sehatly:verify-schema` compares against, so a column this file claims exists is a
| column the parity verifier also knows about.
|
| ## `Carbon::setTestNow()` is set per test and cleared in `finally`
|
| The slot and promo rules are clock-dependent, so the clock is pinned rather than
| raced. `setTestNow()` is honoured through `Carbon::now($tz)`, which is what
| `WaktuIndonesia` is built on, so a frozen instant moves the Jakarta wall clock with
| it.
|
| ## These are Pest closure tests, not a PHPUnit class
|
| `tests/Pest.php` binds `RefreshDatabase` with `->in('Feature')`, which covers
| closure tests and not a plain `class FooTest`. The trait is what makes the rows
| below roll back.
|
*/

const ZONA_UTC = 'UTC';
const ZONA_WIB = 'Asia/Jakarta';

/*
|--------------------------------------------------------------------------
| The instant allow-list - rule 1
|--------------------------------------------------------------------------
|
| Every `TIMESTAMP` column (all 55, read out of the DDL) plus the `DATETIME`
| columns a machine writes. `docs/timezone-policy.md` carries the per-column
| reasoning; this list is the enforcement side of the same decision.
|
*/

const INSTAN = [
    // TIMESTAMP - all 55, machine-written audit columns.
    'akses_rekam_medis_log.dibuat_at', 'apotek_stok.diubah_at', 'artikel.dibuat_at',
    'artikel.diubah_at', 'audit_log.dibuat_at', 'booking.dibuat_at', 'booking.diubah_at',
    'dokter.dibuat_at', 'dokter.diubah_at', 'dokter_jadwal.dibuat_at',
    'dokter_jadwal.diubah_at', 'faskes.dibuat_at', 'faskes.diubah_at',
    'home_care_pesanan.dibuat_at', 'home_care_pesanan.diubah_at', 'invoice.dibuat_at',
    'invoice.diubah_at', 'klaim_bpjs.dibuat_at', 'klaim_bpjs.diubah_at',
    'konsultasi.dibuat_at', 'konsultasi.diubah_at', 'konsultasi_chat.terkirim_at',
    'lab_permintaan.dibuat_at', 'lab_permintaan.diubah_at', 'master_obat.dibuat_at',
    'master_obat.diubah_at', 'notifikasi.dibuat_at', 'pasien.dibuat_at',
    'pasien.diubah_at', 'pasien.dihapus_at', 'pasien_alergi.dibuat_at',
    'pasien_anggota_keluarga.dibuat_at', 'pasien_imunisasi.dibuat_at',
    'pasien_penjamin.dibuat_at', 'pasien_riwayat_penyakit.dibuat_at',
    'pasien_tanda_vital.dibuat_at', 'pembayaran.dibuat_at', 'pesanan_obat.dibuat_at',
    'pesanan_obat.diubah_at', 'promo_redemption.dibuat_at', 'refund.dibuat_at',
    'rekam_medis.dibuat_at', 'rekam_medis.diubah_at', 'rekam_medis_lampiran.dibuat_at',
    'resep.dibuat_at', 'resep.diubah_at', 'rujukan.dibuat_at',
    'surat_keterangan.dibuat_at', 'ulasan_dokter.dibuat_at', 'user_devices.dibuat_at',
    'user_otp.dibuat_at', 'user_refresh_tokens.dibuat_at', 'users.dibuat_at',
    'users.diubah_at', 'users.dihapus_at',

    // DATETIME written by a machine - instants.
    'artikel.published_at', 'invoice.jatuh_tempo', 'invoice.lunas_at',
    'konsultasi.mulai_at', 'konsultasi.selesai_at', 'konsultasi_chat.dibaca_at',
    'lab_hasil.tanggal_hasil', 'notifikasi.dibaca_at', 'pasien_tanda_vital.diukur_at',
    'pembayaran.dibayar_at', 'persetujuan_pdp.disetujui_at',
    'pesanan_obat_tracking.waktu', 'rekam_medis.tanggal_periksa',
    'rekam_medis.ditandatangani_at', 'rekam_medis_persetujuan.ditandatangani_at',
    'rekam_medis_tindakan.tanggal_tindakan', 'resep_verifikasi.diverifikasi_at',
    'ulasan_dokter.dibalas_at', 'user_devices.last_active_at', 'user_otp.kedaluwarsa_at',
    'user_refresh_tokens.kedaluwarsa_at', 'users.last_login_at',
];

/*
|--------------------------------------------------------------------------
| The wall-clock allow-list - rule 2
|--------------------------------------------------------------------------
|
| Every `DATE`, every `TIME`, the single `YEAR`, and the three `DATETIME`
| columns an operator authors in local business time. The three `DATETIME`
| entries are the reason this test cannot be written as a suffix rule.
|
*/

const WALL_CLOCK = [
    // TIME - never instants. The rule `docs/timezone-policy.md` leads with.
    'booking.slot_mulai', 'booking.slot_selesai', 'dokter_jadwal.jam_mulai',
    'dokter_jadwal.jam_selesai',

    // DATE - calendar dates.
    'apotek_stok.kedaluwarsa', 'booking.tanggal_kunjungan', 'dokter.str_berlaku_sampai',
    'dokter.sip_berlaku_sampai', 'dokter_jadwal.berlaku_mulai',
    'dokter_jadwal.berlaku_sampai', 'dokter_libur.tanggal', 'klaim_bpjs.tanggal_sep',
    'klaim_bpjs.tanggal_pulang', 'pasien.tanggal_lahir', 'pasien.tanggal_meninggal',
    'pasien_anggota_keluarga.tanggal_lahir', 'pasien_imunisasi.tanggal',
    'pasien_penjamin.masa_berlaku_akhir', 'rekam_medis.jadwal_kontrol',
    'resep.berlaku_sampai', 'rujukan.berlaku_sampai', 'surat_keterangan.tanggal_mulai',
    'surat_keterangan.tanggal_selesai',

    // YEAR - no month, no day, no zone.
    'pasien_riwayat_penyakit.tahun_terdiagnosis',

    // DATETIME authored by a human in Indonesian local time. These are the
    // counter-example the plan names: `_at`, DATETIME, and NOT an instant.
    'master_promo.mulai_at', 'master_promo.selesai_at', 'resep.tanggal_resep',
    'home_care_pesanan.jadwal_kunjungan',
];

/*
|--------------------------------------------------------------------------
| DDL access
|--------------------------------------------------------------------------
|
| Parsed once per process. `SqlSchemaParser` is strictly read-only - it never
| connects and never writes - so this costs nothing but the file read.
|
*/

function zonawaktuSpec(): App\Support\Schema\SchemaSpec
{
    static $spec = null;

    // `__DIR__` is `tests/Feature`, so two levels up is the repository root, which
    // is where `telemedicine_test.sql` lives.
    return $spec ??= (new SqlSchemaParser)->parseFile(
        dirname(__DIR__, 2).'/telemedicine_test.sql',
    );
}

/**
 * The declared base type of one `table.column`, lower-cased.
 */
function zonawaktuTipe(string $tabel, string $kolom): ?string
{
    $tabelSpec = zonawaktuSpec()->table($tabel);

    if ($tabelSpec === null || ! isset($tabelSpec->columns[$kolom])) {
        return null;
    }

    $tipe = strtolower($tabelSpec->columns[$kolom]->type);

    return trim(explode('(', $tipe)[0]);
}

/**
 * The values of an ENUM column, as the DDL wrote them.
 *
 * @return list<string>|null
 */
function zonawaktuEnum(string $tabel, string $kolom): ?array
{
    $tabelSpec = zonawaktuSpec()->table($tabel);

    if ($tabelSpec === null || ! isset($tabelSpec->columns[$kolom])) {
        return null;
    }

    $tipe = $tabelSpec->columns[$kolom]->type;

    if (preg_match("/^enum\((.*)\)$/i", $tipe, $m) !== 1) {
        return null;
    }

    preg_match_all("/'([^']*)'/", $m[1], $nilai);

    return $nilai[1];
}

/*
|--------------------------------------------------------------------------
| Row builders
|--------------------------------------------------------------------------
|
| Through `DB::table()->insertGetId()`, for the reason
| `SlotAvailabilityTest` gives: none of these models declares `#[Fillable]`,
| so Eloquent's default `$guarded = ['*']` would write nothing and say nothing
| about it. The query builder has no such default, so every column name below is
| checked by MySQL against the real table.
|
| The ENUM values are read from the DDL by {@see zonawaktuEnum()} and the first
| value used, so a typo cannot reach the database and cannot be hidden by a
| permissive default either.
|
*/

/** The first ENUM value the DDL declares for a column, so a row is always legal. */
function zonawaktuEnumPertama(string $tabel, string $kolom): string
{
    $nilai = zonawaktuEnum($tabel, $kolom);

    expect($nilai)->toBeArray()->not->toBeEmpty();

    return $nilai[0];
}

/** A `users` row; `status` is the literal `aktif` (`:140`) so nothing is excluded. */
function zonawaktuUser(string $tipe = 'dokter'): int
{
    return (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => 'Zona Waktu Uji '.Str::upper(Str::random(6)),
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => $tipe,
        'status' => 'aktif',
    ]);
}

/** A `dokter` row with a licence valid until 2099, so STR never gates a slot test. */
function zonawaktuDokterId(array $ubah = []): int
{
    return (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => zonawaktuUser(),
        'tipe' => zonawaktuEnumPertama('dokter', 'tipe'),
        'nomor_str' => 'STR-TZ-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'pengalaman_tahun' => 5,
        'durasi_default_menit' => 20,
        'tersedia_telemedisin' => 1,
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ], $ubah));
}

/**
 * A `dokter_jadwal` row. `jam_mulai`/`jam_selesai` are `TIME` (`:476`-`:477`)
 * and are written as bare wall clocks - no date, no offset, by construction.
 *
 * @param  array<string, mixed>  $ubah
 */
function zonawaktuJadwal(int $dokterId, array $ubah = []): int
{
    return (int) DB::table('dokter_jadwal')->insertGetId(array_merge([
        'dokter_id' => $dokterId,
        'faskes_id' => null,
        'tipe_layanan' => zonawaktuEnumPertama('dokter_jadwal', 'tipe_layanan'),
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

/** A `pasien` row; `tanggal_lahir` is a `DATE` (`:226`) and stays `Y-m-d`. */
function zonawaktuPasienId(string $tanggalLahir = '1990-05-17'): int
{
    return (int) DB::table('pasien')->insertGetId([
        'user_id' => zonawaktuUser(zonawaktuEnumPertama('users', 'tipe')),
        'jenis_kelamin' => zonawaktuEnumPertama('pasien', 'jenis_kelamin'),
        'tanggal_lahir' => $tanggalLahir,
        'alamat_lengkap' => 'Jl. Uji Zona Waktu No. 3, Jakarta',
    ]);
}

// ============================================================================
// Rule 0 - the runtime the policy is written against
// ============================================================================

it('runs the application in UTC and pins the MySQL session to +00:00', function (): void {
    // `config/app.php:68` hardcodes UTC. Asserted rather than assumed, because the
    // whole instant rule rests on it.
    expect(config('app.timezone'))->toBe(ZONA_UTC);

    // The half that was actually broken. `@@session.time_zone` was `SYSTEM`, which
    // on this host resolves to Asia/Jakarta, and MySQL converts `TIMESTAMP` on read
    // using the session zone - so every `dibuat_at` came back seven hours late with a
    // `Z` on the end of it.
    //
    // Asserted on a *fresh* connection, because the value is per-session and the
    // test process may already hold one.
    $sesi = DB::selectOne('SELECT @@session.time_zone AS zona');

    expect($sesi->zona)->toBe('+00:00');
});

it('exposes the pinned zone through config so the connector sets it', function (): void {
    // The assertion above only passes because of this key:
    // `MySqlConnector` issues `SET time_zone='...'` only when it is present
    // (`vendor/laravel/framework/.../Connectors/MySqlConnector.php:110-111`).
    expect(config('database.connections.mysql.timezone'))->toBe('+00:00');
});

// ============================================================================
// Rule 1 - instants
// ============================================================================

it('lists every rule-1 column in a form the DDL agrees with', function (): void {
    $hilang = [];

    foreach (INSTAN as $pasangan) {
        [$tabel, $kolom] = explode('.', $pasangan);

        if (zonawaktuTipe($tabel, $kolom) === null) {
            $hilang[] = $pasangan.' (no such column)';

            continue;
        }

        if (! in_array(zonawaktuTipe($tabel, $kolom), ['datetime', 'timestamp'], true)) {
            $hilang[] = $pasangan.' (declared '.zonawaktuTipe($tabel, $kolom).')';
        }
    }

    expect($hilang)->toBe([]);

    // 55 `TIMESTAMP` + 22 `DATETIME` instants. A drift in the DDL that adds or drops
    // a temporal column has to be a deliberate edit to this constant and to
    // `docs/timezone-policy.md`, not a silent change of coverage.
    expect(count(INSTAN))->toBe(77);
});

it('lists every rule-2 column in a form the DDL agrees with', function (): void {
    $hilang = [];

    foreach (WALL_CLOCK as $pasangan) {
        [$tabel, $kolom] = explode('.', $pasangan);

        if (zonawaktuTipe($tabel, $kolom) === null) {
            $hilang[] = $pasangan.' (no such column)';

            continue;
        }

        // `DATE`, `TIME`, `YEAR`, plus the four operator-authored `DATETIME`s.
        if (! in_array(zonawaktuTipe($tabel, $kolom), ['date', 'time', 'year', 'datetime'], true)) {
            $hilang[] = $pasangan.' (declared '.zonawaktuTipe($tabel, $kolom).')';
        }
    }

    expect($hilang)->toBe([]);
});

it('keeps the two allow-lists disjoint, so no column is classified twice', function (): void {
    expect(array_values(array_intersect(INSTAN, WALL_CLOCK)))->toBe([]);
});

it('reads a freshly written timestamp back as the same instant MySQL holds', function (): void {
    $id = zonawaktuUser();

    // `UTC_TIMESTAMP()` is the ground truth for the instant, independent of any
    // session zone. `dibuat_at` is `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` (`:146`),
    // so MySQL wrote it in UTC-native storage.
    $sebelum = DB::selectOne('SELECT UTC_TIMESTAMP() AS sekarang')->sekarang;

    $tersimpan = DB::table('users')->where('id', $id)->value('dibuat_at');

    $dibaca = Carbon::parse((string) $tersimpan, ZONA_UTC);
    $expected = Carbon::parse((string) $sebelum, ZONA_UTC);

    // A 7-hour drift is the failure this pins. One minute of slack covers the
    // statement boundary; nothing else would.
    expect(abs($dibaca->diffInSeconds($expected)))->toBeLessThan(60);
});

it('serialises every rule-1 instant with a Z suffix and nothing else', function (): void {
    // The wire format itself, proven on the columns a round-trip can reach without
    // building 77 fixtures: one `TIMESTAMP`, one machine-written `DATETIME`.
    $userId = zonawaktuUser();
    $pasienId = zonawaktuPasienId();

    $kunci = Carbon::parse('2026-03-04T05:06:07', ZONA_UTC);
    DB::table('users')->where('id', $userId)->update([
        'last_login_at' => $kunci->format('Y-m-d H:i:s'),
    ]);

    $carbon = Carbon::parse(
        (string) DB::table('users')->where('id', $userId)->value('last_login_at'),
        ZONA_UTC,
    );

    // The suffix, and the offset behind it.
    expect($carbon->toISOString())->toEndWith('Z');
    expect($carbon->getOffset())->toBe(0);
    expect($carbon->toISOString())->toBe('2026-03-04T05:06:07.000000Z');

    // And the same rule holds for a `TIMESTAMP` audit column, which MySQL converts
    // on read and which was the column actually wrong before the session was pinned.
    $audit = Carbon::parse(
        (string) DB::table('pasien')->where('id', $pasienId)->value('dibuat_at'),
        ZONA_UTC,
    );

    expect($audit->toISOString())->toEndWith('Z');
    expect($audit->getOffset())->toBe(0);
    expect($audit->toISOString())->toBe(
        Carbon::parse($audit->format('Y-m-d H:i:s'), ZONA_UTC)->toISOString(),
    );
});

// ============================================================================
// Rule 2 - wall clocks
// ============================================================================

it('serialises master_promo.mulai_at with NO offset, pinning the allow-list-not-suffix rule', function (): void {
    // An operator types a promo window in Indonesian local time. It ends in `_at`,
    // it is a `DATETIME`, and it is NOT an instant - so this field must never carry
    // a `Z`, and a suffix-based rule would get this exactly wrong.
    $mulai = '2026-10-05 17:00:00';

    $promoId = (int) DB::table('master_promo')->insertGetId([
        'kode' => 'PROMO-TZ-'.Str::upper(Str::random(6)),
        'nama' => 'Promo Uji Zona Waktu',
        'tipe_diskon' => zonawaktuEnumPertama('master_promo', 'tipe_diskon'),
        'nilai' => '10.00',
        'min_transaksi' => '0.00',
        'maks_diskon' => null,
        'kuota_total' => null,
        'kuota_per_user' => 1,
        'mulai_at' => $mulai,
        'selesai_at' => '2026-10-31 23:59:59',
        'status_aktif' => 1,
    ]);

    $promo = MasterPromo::query()->findOrFail($promoId);

    // No `Z`, no `+07:00`, no time component: exactly what was authored.
    expect($promo->mulai_at->format('Y-m-d H:i:s'))->toBe($mulai);
    expect($promo->mulai_at->format('c'))->not->toContain('+07:00');

    // The two readings differ by exactly seven hours. If they were equal this
    // column would be indistinguishable from an instant and the assertion above
    // would be meaningless.
    $sebagai_naif = Carbon::parse($mulai, ZONA_UTC);
    $sebagai_momen = WaktuIndonesia::toInstant('2026-10-05', '17:00:00');

    expect($sebagai_naif->getTimestamp())->not->toBe($sebagai_momen->getTimestamp());
    expect($sebagai_naif->getTimestamp() - $sebagai_momen->getTimestamp())->toBe(7 * 3600);
});

it('round-trips pasien.tanggal_lahir as Y-m-d with no off-by-one-day', function (): void {
    // A birthday is a calendar date. The 1st of a month is the case that a
    // `datetime` cast breaks: the cast resolves the literal in `app.timezone`
    // (UTC), and any zone east of Greenwich then reads the previous day.
    foreach (['1990-01-01', '2000-02-01', '2015-12-01'] as $lahir) {
        $id = zonawaktuPasienId($lahir);

        $pasien = App\Models\Pasien::query()->findOrFail($id);

        expect($pasien->tanggal_lahir->format('Y-m-d'))->toBe($lahir);

        $resource = (new App\Http\Resources\PasienResource($pasien))->toArray(request());

        expect($resource['tanggal_lahir'])->toBe($lahir)
            ->and($resource['tanggal_lahir'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
            ->and($resource['tanggal_lahir'])->not->toContain('T')
            ->and($resource['tanggal_lahir'])->not->toContain('Z');
    }
});

it('returns a 23:30 WIB slot as 23:30:00 and never shifts it to 16:30', function (): void {
    $dokterId = zonawaktuDokterId();
    $jadwalId = zonawaktuJadwal($dokterId, [
        'jam_mulai' => '23:00:00',
        'jam_selesai' => '23:59:59',
        'durasi_slot_menit' => 15,
    ]);

    $dokter = Dokter::query()->findOrFail($dokterId);
    $layanan = new SlotAvailabilityService;

    // A fixed Monday well in the future, so the elapsed-slot rule cannot interfere.
    $tanggal = '2026-12-07';
    expect(Carbon::parse($tanggal)->dayOfWeek)->toBe(1);

    $slot = collect($layanan->getSlotTerbuka($dokter, $tanggal))->all();

    // `23:45` is absent and correctly so: it would end at `24:00:00`, which is not a
    // time of day, and the service refuses to publish one.
    $jam = array_column($slot, 'jam_mulai');
    expect($jam)->toBe(['23:00:00', '23:15:00', '23:30:00']);

    // The exact string, not just its hour: `H:i:s`, no date, no offset.
    expect($jam[2])->toBe('23:30:00');
    expect($jam[2])->toMatch('/^\d{2}:\d{2}:\d{2}$/');
    expect($jam[2])->not->toContain('Z')
        ->and($jam[2])->not->toContain('+')
        ->and($jam[2])->not->toContain('2026');

    // And the stored value is the same wall clock the DDL holds: the window opens
    // at 23:00 and the service derived 23:30 from it without moving a second.
    expect((string) DB::table('dokter_jadwal')->where('id', $jadwalId)->value('jam_mulai'))
        ->toBe('23:00:00');

    // The shift this rule forbids, stated as the value that must never appear.
    expect($jam)->not->toContain('16:30:00');
});

it('never attaches a timezone suffix to a TIME column, in any of its four forms', function (): void {
    // The rule, stated as a property of the column type rather than of one row.
    foreach (['booking.slot_mulai', 'booking.slot_selesai', 'dokter_jadwal.jam_mulai', 'dokter_jadwal.jam_selesai'] as $pasangan) {
        expect(zonawaktuTipe(...explode('.', $pasangan)))->toBe('time');
    }

    $dokterId = zonawaktuDokterId();
    $jadwalId = zonawaktuJadwal($dokterId, [
        'jam_mulai' => '16:30:00',
        'jam_selesai' => '17:00:00',
    ]);

    // The driver hands the value back as a bare string. `H:i:s`, nothing else -
    // this is the value that reaches `BookingResource` unchanged.
    $mentah = DB::table('dokter_jadwal')->where('id', $jadwalId)->value('jam_mulai');

    expect((string) $mentah)->toBe('16:30:00')
        ->and((string) $mentah)->toMatch('/^\d{2}:\d{2}:\d{2}$/')
        ->and((string) $mentah)->not->toContain('Z')
        ->and((string) $mentah)->not->toContain('+07:00');
});

it('marks a slot that ended twenty minutes ago in Asia/Jakarta as unavailable', function (): void {
    $dokterId = zonawaktuDokterId();
    zonawaktuJadwal($dokterId, [
        'jam_mulai' => '09:00:00',
        'jam_selesai' => '10:30:00',
        'durasi_slot_menit' => 15,
    ]);

    $dokter = Dokter::query()->findOrFail($dokterId);
    $layanan = new SlotAvailabilityService;

    try {
        // 09:50 WIB is 02:50 UTC. The 09:30 slot ended at 09:45 WIB; the 09:45 slot
        // ends at 10:00 and is still ahead. That five-minute pair is the whole
        // boundary.
        //
        // Under the pre-fix code `Carbon::now()` answered 02:50 and was compared
        // against a WIB wall clock, so EVERY slot looked bookable until 16:50 WIB -
        // a seven-hour window in which a patient could book an appointment that had
        // already finished.
        Carbon::setTestNow(WaktuIndonesia::toInstant('2026-12-07', '09:50:00'));

        $slot = collect($layanan->getSlotTerbuka($dokter, '2026-12-07'))->all();
        expect($slot)->not->toBeEmpty();

        $lewat = collect($slot)->firstWhere('jam_mulai', '09:30:00');
        expect($lewat)->not->toBeNull();
        expect($lewat['tersedia'])->toBeFalse();
        expect($lewat['alasan'])->toBe(SlotAvailabilityService::ALASAN_LEWAT_WAKTU);

        // One slot later in the same day is still bookable, so the rule is a
        // boundary and not just "close the whole morning".
        $nanti = collect($slot)->firstWhere('jam_mulai', '09:45:00');
        expect($nanti)->not->toBeNull();
        expect($nanti['tersedia'])->toBeTrue();
        expect($nanti['alasan'])->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

it('reads today as the Jakarta calendar day even at 23:30 WIB, when UTC is still the previous day', function (): void {
    // The landmine the connection pin creates. With the session on `+00:00`,
    // `SELECT CURDATE()` answers the UTC day, and between 00:00 and 07:00 WIB that
    // is *yesterday* - so the book would open on the wrong date. A clinic's "today"
    // is a business fact, not a property of the server answering.
    $dokterId = zonawaktuDokterId();
    zonawaktuJadwal($dokterId, [
        'jam_mulai' => '09:00:00',
        'jam_selesai' => '10:00:00',
        'durasi_slot_menit' => 15,
    ]);

    $dokter = Dokter::query()->findOrFail($dokterId);
    $layanan = new SlotAvailabilityService;

    try {
        // 23:30 WIB on a Monday is 16:30 UTC on that SAME Monday, so `CURDATE()`
        // would still agree here. The 07:30 case is the one that splits, and it is
        // asserted separately in {@see it reports now and today in Asia/Jakarta}.
        Carbon::setTestNow(WaktuIndonesia::toInstant('2026-12-07', '23:30:00'));

        // Everything of the next Monday is bookable, because next Monday is not
        // today. `2026-12-14` and not `2026-12-08`: the schedule row is
        // `dokter_jadwal.hari = 1` (Monday), so the next Tuesday answers an empty
        // list for a reason that has nothing to do with a clock.
        $besok = collect($layanan->getSlotTerbuka($dokter, '2026-12-14'));
        expect(Carbon::parse('2026-12-14')->dayOfWeek)->toBe(1);
        expect($besok)->not->toBeEmpty();
        expect($besok->every(fn (array $s): bool => $s['alasan'] === null))->toBeTrue();

        // And today's own morning is entirely over, seven and a half hours ago.
        $hariIni = collect($layanan->getSlotTerbuka($dokter, '2026-12-07'));
        expect($hariIni)->not->toBeEmpty();
        expect($hariIni->every(fn (array $s): bool => $s['alasan'] === SlotAvailabilityService::ALASAN_LEWAT_WAKTU))->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('marks a slot still ahead in Asia/Jakarta as available even when UTC has passed its time', function (): void {
    $dokterId = zonawaktuDokterId();
    zonawaktuJadwal($dokterId, [
        'jam_mulai' => '09:00:00',
        'jam_selesai' => '10:00:00',
        'durasi_slot_menit' => 15,
    ]);

    $dokter = Dokter::query()->findOrFail($dokterId);
    $layanan = new SlotAvailabilityService;

    try {
        // 08:00 WIB is 01:00 UTC. The whole 09:00-10:00 window is still ahead on
        // the Jakarta clock, so every one of its slots is bookable. A UTC reading
        // of the same instant would still agree here, which is why the *previous*
        // test - one that crosses the 7-hour boundary - is the one with teeth.
        Carbon::setTestNow(WaktuIndonesia::toInstant('2026-12-07', '08:00:00'));

        $slot = collect($layanan->getSlotTerbuka($dokter, '2026-12-07'))->all();

        expect($slot)->not->toBeEmpty();

        foreach ($slot as $satu) {
            expect($satu['tersedia'])->toBeTrue();
            expect($satu['alasan'])->toBeNull();
        }
    } finally {
        Carbon::setTestNow();
    }
});

// ============================================================================
// The 7-hour promo regression
// ============================================================================

it('reports a promo starting one hour from now in Asia/Jakarta as inactive', function (): void {
    // The regression the plan names. `master_promo.mulai_at` is an operator's local
    // wall clock, so "starts in one hour" is one hour on the **Jakarta** clock. A
    // comparison against `now()` in UTC accepts the promo seven hours early.
    Carbon::setTestNow(WaktuIndonesia::toInstant('2026-10-05', '17:00:00'));

    try {
        $mulai = Carbon::parse('2026-10-05 18:00:00', ZONA_WIB);  // 18:00 WIB
        $selesai = Carbon::parse('2026-10-31 23:59:59', ZONA_WIB);

        $promoId = (int) DB::table('master_promo')->insertGetId([
            'kode' => 'PROMO-7JAM-'.Str::upper(Str::random(6)),
            'nama' => 'Promo Mulai Setengah Jam Depan',
            'tipe_diskon' => zonawaktuEnumPertama('master_promo', 'tipe_diskon'),
            'nilai' => '10.00',
            'min_transaksi' => '0.00',
            'maks_diskon' => null,
            'kuota_total' => null,
            'kuota_per_user' => 1,
            'mulai_at' => $mulai->format('Y-m-d H:i:s'),
            'selesai_at' => $selesai->format('Y-m-d H:i:s'),
            'status_aktif' => 1,
        ]);

        $hitungan = (new PromoService)->hitung(
            MasterPromo::query()->findOrFail($promoId),
            '100000.00',
            '20000.00',
            zonawaktuPasienId(),
        );

        expect($hitungan->ditolak())->toBeTrue();
        expect(array_column($hitungan->alasan, 'kode'))
            ->toContain(App\Services\Invoice\PromoHitungan::KODE_BELUM_MULAI);
    } finally {
        Carbon::setTestNow();
    }
});

// ============================================================================
// The one conversion helper
// ============================================================================

it('converts a DATE plus a TIME into an instant in exactly one place', function (): void {
    $momen = WaktuIndonesia::toInstant('2026-10-05', '17:00:00');

    expect(WaktuIndonesia::ZONA)->toBe(ZONA_WIB);
    expect($momen->getOffset())->toBe(7 * 3600);
    expect($momen->format('Y-m-d H:i:s'))->toBe('2026-10-05 17:00:00');
    expect($momen->copy()->setTimezone(ZONA_UTC)->format('Y-m-d H:i:s'))->toBe('2026-10-05 10:00:00');

    // Round trip: the instant, expressed on the Jakarta wall clock, is the pair the
    // database stores.
    expect($momen->format('Y-m-d'))->toBe('2026-10-05');
    expect($momen->format('H:i:s'))->toBe('17:00:00');
});

it('reports now and today in Asia/Jakarta, not in the application zone', function (): void {
    try {
        Carbon::setTestNow(Carbon::parse('2026-10-05 22:30:00', ZONA_UTC));

        // 22:30 UTC is already the next calendar day in Jakarta. This is the
        // 7-hour boundary where a UTC "today" and a Jakarta "today" disagree, and
        // the clinic's answer has to be the Jakarta one.
        expect(WaktuIndonesia::now()->format('Y-m-d H:i:s'))->toBe('2026-10-06 05:30:00');
        expect(WaktuIndonesia::tanggal())->toBe('2026-10-06');

        // The naive UTC reading is a different day, which is what makes the helper
        // worth having.
        expect(Carbon::now(ZONA_UTC)->format('Y-m-d'))->toBe('2026-10-05');
    } finally {
        Carbon::setTestNow();
    }
});