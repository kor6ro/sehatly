<?php

declare(strict_types=1);

use App\Models\Dokter;
use App\Models\User;
use App\Services\Dokter\DokterDirectoryService;
use App\Support\WaktuIndonesia;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The STR boundary, on the clinic's calendar day rather than the server's
|--------------------------------------------------------------------------
|
| `dokter.str_berlaku_sampai` is a `DATE` (`telemedicine_test.sql:414`). A
| `DATE` is a mark on a wall clock, not an instant, so the only question the
| directory can ask about it is "is this date still ahead of the clinic's
| today". Which raises the one thing a `DATE` comparison can get wrong that no
| other type can: **whose** today.
|
| `config/app.php` is `UTC`, and `config/database.php` pins the MySQL session to
| `+00:00`. Asia/Jakarta is UTC+07:00, so for **seven hours every day, from
| 00:00 to 07:00 WIB, the UTC calendar day and the Jakarta calendar day are
| different dates.** Any predicate that resolves "today" through either
| `SELECT CURDATE()` or `Carbon::today()` is therefore a day out for a quarter of
| the day, and a defect that only appears in that window is a defect that
| survives: it is green for the other seventeen hours, and the suite is run
| whenever the suite is run.
|
| That is the whole reason this file exists, and the reason it pins the clock
| rather than reading a real one.
|
| ## The two pinned instants, and why both are in one test
|
| | constant | UTC | Asia/Jakarta | the two bases |
| | --- | --- | --- | --- |
| | `STR51B_JAM_MALAM` | 2026-09-29 18:30 | 2026-09-30 01:30 | **a day apart** |
| | `STR51B_JAM_TENGAH` | 2026-09-30 06:30 | 2026-09-30 13:30 | the same day |
|
| A test that only pins the first is a test that is red for seven hours a day.
| A test that only pins the second cannot tell the two bases apart at all and
| would pass against the bug. Both are asserted here, in one test, so the file
| is red on the defect and green on the fix at any hour of any day: the first
| block distinguishes the bases, the second proves the fix did not simply
| substitute one wrong basis for another (a predicate wired to "yesterday", or to
| UTC, fails the second block).
|
| ## The clock is released in `finally`, and then asserted to be released
|
| A leaked `Carbon::setTestNow()` is worse than the bug this file pins: it
| silently freezes every later test in the same process at a date in 2026, and
| nothing about the failure points back here. Every block therefore resets in a
| `finally`, and the last assertion of the test reads the clock back and requires
| it to be real again.
|
| ## The prefix
|
| Pest loads every test file into ONE process and its helpers are global
| functions. `direktori*` belongs to `DokterDirectoryTest` and `jsl*` to
| `DokterJadwalSlotEndpointTest`; everything here is `str51b*`.
|
| ## No route is registered
|
| `GET /api/v1/dokter` and `GET /api/v1/dokter/{dokter}` are in the real
| `routes/api.php`, so the real route table is what answers. A test that
| registered its own copy would pass against a table the application does not
| ship.
*/

/**
 * 2026-09-29T18:30:00Z, which is 2026-09-30 01:30 in `Asia/Jakarta`.
 *
 * Inside the seven-hour window: the UTC day is `2026-09-29` and the clinic's day
 * is `2026-09-30`, so a boundary read off either clock gives a different answer.
 */
const STR51B_JAM_MALAM = '2026-09-29T18:30:00Z';

/** 2026-09-30T06:30:00Z, which is 2026-09-30 13:30 in `Asia/Jakarta`. */
const STR51B_JAM_TENGAH = '2026-09-30T06:30:00Z';

/**
 * A `users` row. `status` is `aktif` (`:140`) and `tipe` is the seven-value
 * ENUM at `:139`, so no fixture here is ever excluded on the ACCOUNT's state --
 * that would be a different rule from the one under test.
 */
function str51bUser(string $nama): User
{
    $user = new User;
    $user->uuid = (string) Str::uuid();
    $user->nama_lengkap = $nama;
    $user->email = Str::lower(Str::random(12)).'@example.test';
    $user->no_telepon = '08'.random_int(100000000, 999999999);
    $user->kata_sandi_hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
    $user->tipe = 'dokter';
    $user->status = 'aktif';
    $user->save();

    return $user;
}

/**
 * An eligible `dokter` row whose STR expires on `$strBerlakuSampai`.
 *
 * Every other field is the value `v_dokter_katalog`'s own `WHERE`
 * (`telemedicine_test.sql:1183`-`:1185`) demands, so the STR date is the ONLY
 * thing that decides whether the row appears. That is what makes the assertion a
 * statement about one predicate and not about three.
 */
function str51bDokter(string $strBerlakuSampai): Dokter
{
    $dokter = new Dokter;
    $dokter->user_id = str51bUser('Dokter '.Str::upper(Str::random(6)))->getKey();
    $dokter->tipe = 'dokter_umum';
    $dokter->nomor_str = 'STR-51B-'.Str::upper(Str::random(8));
    $dokter->str_berlaku_sampai = $strBerlakuSampai;
    $dokter->nomor_sip = 'SIP-51B-'.Str::upper(Str::random(8));
    $dokter->sip_berlaku_sampai = '2099-12-31';
    $dokter->durasi_default_menit = 15;
    $dokter->tersedia_telemedisin = true;
    $dokter->status_verifikasi = 'terverifikasi';
    $dokter->status_aktif = true;
    $dokter->save();

    return $dokter;
}

/**
 * The `dokter` ids a list response carried.
 *
 * @return list<int>
 */
function str51bIds(array $json): array
{
    return array_map('intval', array_column($json['data']['dokter'] ?? [], 'id'));
}

/**
 * Every scalar the directory bound while `$aktif` was on, from the query log.
 *
 * @return list<string>
 */
function str51bBindings(): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    app(DokterDirectoryService::class)->list([]);

    $catatan = DB::getQueryLog();
    DB::disableQueryLog();

    $bindings = [];
    foreach ($catatan as $baris) {
        $bindings = array_merge($bindings, array_map('strval', $baris['bindings']));
    }

    return $bindings;
}

test('the STR boundary is read on the clinic calendar day, at BOTH ends of the seven-hour window', function (): void {
    /*
    |----------------------------------------------------------------------
    | Instant A -- 2026-09-30 01:30 WIB, where the two bases are a day apart
    |----------------------------------------------------------------------
    */

    Carbon::setTestNow(CarbonImmutable::parse(STR51B_JAM_MALAM));

    try {
        // The premise, asserted rather than assumed. If a future change made the
        // two clocks agree at this instant, the block below would stop proving
        // anything and the test would go quietly vacuous.
        expect(CarbonImmutable::now('UTC')->toDateString())->toBe('2026-09-29')
            ->and(WaktuIndonesia::tanggal())->toBe('2026-09-30');

        /**
         * Three licences, one calendar day apart, read on the clinic's clock.
         *
         * `2026-09-29` is yesterday in Jakarta AND today in UTC -- the single
         * value that separates the two bases, and therefore the assertion that
         * is red against the defect and green against the fix. The other two pin
         * the inclusive boundary itself: a licence expiring today is still
         * licensed today, and one expiring tomorrow is not in question.
         */
        $kemarinWib = (int) str51bDokter('2026-09-29')->getKey();
        $hariIniWib = (int) str51bDokter('2026-09-30')->getKey();
        $besokWib = (int) str51bDokter('2026-10-01')->getKey();

        $ids = str51bIds($this->getJson('/api/v1/dokter')->assertOk()->json());

        expect($ids)->not->toContain($kemarinWib)
            ->and($ids)->toContain($hariIniWib)
            ->and($ids)->toContain($besokWib);

        // The same boundary on the detail route, because the two share one query
        // and a fix that reached only `list()` would still leak a doctor.
        $this->getJson('/api/v1/dokter/'.$kemarinWib)->assertNotFound();
        $this->getJson('/api/v1/dokter/'.$hariIniWib)->assertOk();

        /**
         * The BOUND VALUE, not only the outcome.
         *
         * The outcome is what a patient experiences; the bound value is what
         * names the basis. `2026-09-29` is the UTC day, so its presence in the
         * bindings is the defect stated in the application's own SQL, and this
         * assertion cannot be satisfied by a fixture that happens to agree.
         */
        $bindings = str51bBindings();

        expect($bindings)->toContain('2026-09-30')
            ->and($bindings)->not->toContain('2026-09-29');
    } finally {
        Carbon::setTestNow();
    }

    /*
    |----------------------------------------------------------------------
    | Instant B -- 2026-09-30 13:30 WIB, where the two bases coincide
    |----------------------------------------------------------------------
    |
    | A fix that swapped the UTC day for the Jakarta day passes this block too.
    | A fix that substituted the day BEFORE either -- "yesterday", say, or
    | `now()->subDay()` -- hides a doctor whose licence runs to today, and fails
    | here while still passing block A. That is the whole reason it is here.
    */

    Carbon::setTestNow(CarbonImmutable::parse(STR51B_JAM_TENGAH));

    try {
        expect(CarbonImmutable::now('UTC')->toDateString())->toBe('2026-09-30')
            ->and(WaktuIndonesia::tanggal())->toBe('2026-09-30');

        $kemarin = (int) str51bDokter('2026-09-29')->getKey();
        $hariIni = (int) str51bDokter('2026-09-30')->getKey();
        $besok = (int) str51bDokter('2026-10-01')->getKey();

        $ids = str51bIds($this->getJson('/api/v1/dokter')->assertOk()->json());

        expect($ids)->not->toContain($kemarin)
            ->and($ids)->toContain($hariIni)
            ->and($ids)->toContain($besok);

        $this->getJson('/api/v1/dokter/'.$kemarin)->assertNotFound();
        $this->getJson('/api/v1/dokter/'.$hariIni)->assertOk();
    } finally {
        Carbon::setTestNow();
    }

    // And the clock really is the real clock again, read back through both names
    // because `Carbon::setTestNow()` writes one shared factory that
    // `CarbonImmutable::now()` also reads.
    expect(Carbon::getTestNow())->toBeNull()
        ->and(CarbonImmutable::getTestNow())->toBeNull()
        ->and(WaktuIndonesia::now()->toDateString())->toBe(
            CarbonImmutable::now(WaktuIndonesia::ZONA)->toDateString(),
        );
});
