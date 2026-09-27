<?php

declare(strict_types=1);

use App\Models\Dokter;
use App\Models\User;
use App\Services\Booking\SlotAvailabilityService;
use App\Support\Dokter\StrBerlaku;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The two schedule endpoints
|--------------------------------------------------------------------------
|
| `GET /api/v1/dokter/{dokter}/jadwal` and
| `GET /api/v1/dokter/{dokter}/slot?tanggal=YYYY-MM-DD` -- the HTTP surface of
| `App\Services\Booking\SlotAvailabilityService`, which todo 26 built and
| deliberately left unpublished (its own finding F1).
|
| ## This file asserts REUSE, not re-derivation
|
| The service owns four rules -- the window subtraction, the whole-day
| `dokter_libur` closure, the quota-aware half-open overlap count, and the
| inclusive STR boundary evaluated against the CONSULTATION date. Every one of
| them is re-asserted here *through the endpoint*, against fixtures chosen so
| that a second, differently-bounded implementation would answer differently:
| a quota of two, a holiday on the exact date, a licence that lapses between
| today and the requested date, a booking that ends exactly when a slot begins.
| The quota test is todo 26's pinned bug and it is repeated here on purpose: a
| route that computed its own availability could satisfy every other test in
| this file and still fail this one.
|
| There is also a direct parity test -- the decoded `slots` array must equal
| `getSlotTerbuka()`'s return value element for element -- which is the cheapest
| possible guard against any re-derivation at all.
|
| ## These are Pest closure tests, not a PHPUnit class
|
| `tests/Pest.php` binds `RefreshDatabase` with `->in('Feature')`, which covers
| closure tests and not a plain `class FooTest` beside them. Without the trait
| the rows written here survive into the next test.
|
| **The real route table is used, and no `beforeEach` registers anything.**
| `DokterDirectoryTest` registers the three directory routes in-process and only
| when they are absent, so it passes both before and after `routes/api.php`
| declares them; this file has nothing to register because the two routes under
| test are declared in the real table, and a test that registered its own copy
| would pass against a table the application does not ship.
|
| ## The helper prefix
|
| `direktori*`, `slot*`, `auth*`, `bku*`, `bkc*` and the unprefixed helpers are
| taken by the other Feature files, and Pest loads every one of them into a
| single process. Everything here is `jsl*`.
|
*/

const JSL_TANGGAL = '2026-12-07';        // Monday, hari = 1
const JSL_TANGGAL_LAIN = '2026-12-08';   // Tuesday, hari = 2
const JSL_HARI_KE = 1;                   // the DDL's 0=Minggu s.d. 6=Sabtu, so Monday

/**
 * A `users` row. `tipe` is the seven-value ENUM at `:139`; `status` is `aktif`
 * (`:140`), so no test here is ever excluding a doctor on the ACCOUNT's state --
 * that would be a different rule from the three the directory owns.
 */
function jslUser(string $nama, string $tipe = 'dokter'): User
{
    $user = new User;
    $user->uuid = (string) Str::uuid();
    $user->nama_lengkap = $nama;
    $user->email = Str::lower(Str::random(12)).'@example.test';
    $user->no_telepon = '08'.random_int(100000000, 999999999);
    $user->kata_sandi_hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
    $user->tipe = $tipe;
    $user->status = 'aktif';
    $user->save();

    return $user;
}

/**
 * An ELIGIBLE `dokter` row unless a test says otherwise.
 *
 * The defaults are the ones `v_dokter_katalog`'s own `WHERE` (`:1183`-`:1185`)
 * demands -- `status_verifikasi = 'terverifikasi'`, `status_aktif = 1`,
 * `tersedia_telemedisin = 1` -- and a licence that has not lapsed, so a test
 * that wants a served answer does not have to restate eligibility and a test
 * that wants a 404 changes exactly one field.
 *
 * @param  array<string, mixed>  $ubah
 */
function jslDokter(?User $user = null, array $ubah = []): Dokter
{
    $dokter = new Dokter;
    $dokter->user_id = ($user ?? jslUser('Dokter '.Str::upper(Str::random(6))))->getKey();
    $dokter->tipe = 'dokter_umum';
    $dokter->nomor_str = 'STR-JSL-'.Str::upper(Str::random(8));
    $dokter->str_berlaku_sampai = '2099-12-31';
    $dokter->durasi_default_menit = 15;
    $dokter->tersedia_telemedisin = true;
    $dokter->status_verifikasi = 'terverifikasi';
    $dokter->status_aktif = true;
    $dokter->save();

    foreach ($ubah as $kolom => $nilai) {
        $dokter->{$kolom} = $nilai;
    }

    $dokter->save();

    return $dokter;
}

/**
 * A `dokter_jadwal` window. Never written through a model: the table has no write
 * endpoint in this contract, so every window in this file is authored here.
 *
 * @param  array<string, mixed>  $ubah
 */
function jslJadwal(int $dokterId, array $ubah = []): int
{
    return (int) DB::table('dokter_jadwal')->insertGetId(array_merge([
        'dokter_id' => $dokterId,
        'faskes_id' => null,
        'tipe_layanan' => 'online',
        'hari' => JSL_HARI_KE,
        'jam_mulai' => '09:00:00',
        'jam_selesai' => '10:00:00',
        'durasi_slot_menit' => 15,
        'kuota_per_sesi' => null,
        'berlaku_mulai' => '2020-01-01',
        'berlaku_sampai' => null,
        'status_aktif' => true,
    ], $ubah));
}

/**
 * A `dokter_libur` row, which has no timestamps at all (`:490`-`:496`).
 */
function jslLibur(int $dokterId, string $tanggal): int
{
    return (int) DB::table('dokter_libur')->insertGetId([
        'dokter_id' => $dokterId,
        'tanggal' => $tanggal,
        'alasan' => 'Hari libur nasional',
    ]);
}

/**
 * A `pasien` row for the FK `booking.pasien_id` (`:501`).
 */
function jslPasienId(): int
{
    return (int) DB::table('pasien')->insertGetId([
        'user_id' => jslUser('Pasien '.Str::upper(Str::random(6)), 'pasien')->getKey(),
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Jadwal No. 3, Jakarta',
    ]);
}

/**
 * A `booking` row. `nomor_booking` (`:500`), `pasien_id` (`:501`), `dokter_id`
 * (`:503`), `tipe_layanan` (`:506`), `tanggal_kunjungan` (`:507`),
 * `slot_mulai`/`slot_selesai` (`:508`-`:509`) and `dibuat_oleh_user_id` (`:519`)
 * are the NOT NULL columns with no default.
 *
 * @param  array<string, mixed>  $ubah
 */
function jslBooking(int $dokterId, string $mulai, string $selesai, array $ubah = []): int
{
    return (int) DB::table('booking')->insertGetId(array_merge([
        'nomor_booking' => 'BK-JSL-'.Str::upper(Str::random(10)),
        'pasien_id' => jslPasienId(),
        'dokter_id' => $dokterId,
        'jadwal_id' => null,
        'faskes_id' => null,
        'tipe_layanan' => 'video_call',
        'tanggal_kunjungan' => JSL_TANGGAL,
        'slot_mulai' => $mulai,
        'slot_selesai' => $selesai,
        'status' => 'terjadwal',
        'dibuat_oleh_user_id' => jslUser('Pembuat '.Str::upper(Str::random(4)), 'admin')->getKey(),
    ], $ubah));
}

/**
 * Today, as the DATABASE sees it.
 *
 * `config/app.php` is UTC while the schema stores naive wall clock, so the two
 * services read their reference day from `SELECT CURDATE()`. A test that built
 * the day in PHP would be comparing two clocks.
 */
function jslHariIni(): Carbon
{
    return Carbon::parse((string) DB::selectOne('SELECT CURDATE() AS hari')->hari)->startOfDay();
}

/** The `jam_mulai` of every slot, in order. */
function jslMulai(array $slots): array
{
    return array_values(array_column($slots, 'jam_mulai'));
}

/** The one slot that starts at `$mulai`, or null. */
function jslCari(array $slots, string $mulai): ?array
{
    foreach ($slots as $kandidat) {
        if ($kandidat['jam_mulai'] === $mulai) {
            return $kandidat;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| The route surface
|--------------------------------------------------------------------------
*/

test('both routes exist, are GET-only, and carry no auth, permission or tipe gate', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())->keyBy(
        fn ($route): string => implode('|', $route->methods()).' '.$route->uri(),
    );

    foreach ([
        'GET|HEAD api/v1/dokter/{dokter}/jadwal',
        'GET|HEAD api/v1/dokter/{dokter}/slot',
    ] as $signature) {
        expect($routes)->toHaveKey($signature);

        foreach ($routes[$signature]->gatherMiddleware() as $middleware) {
            expect($middleware)->not->toStartWith('auth')
                ->and($middleware)->not->toStartWith('permission')
                ->and($middleware)->not->toStartWith('tipe');
        }
    }

    // A POST answers 405 rather than falling through to the wildcard, which is
    // what proves the verb is real rather than a test artefact.
    $dokter = (int) jslDokter()->getKey();

    $this->postJson('/api/v1/dokter/'.$dokter.'/jadwal')->assertStatus(405);
    $this->postJson('/api/v1/dokter/'.$dokter.'/slot')->assertStatus(405);
});

test('the two schedule routes cannot be shadowed by the directory wildcard', function (): void {
    // `dokter/{dokter}` is a TWO-segment pattern and both new routes are three
    // segments, so no registration order could let the wildcard swallow them. The
    // assertion is behavioural rather than a reading of `routes/api.php`: a doctor
    // that IS served must answer 200 on both, which it could not do if
    // `dokter.show` had answered first.
    $dokter = (int) jslDokter()->getKey();
    jslJadwal($dokter);

    $this->getJson('/api/v1/dokter/'.$dokter.'/jadwal')->assertOk();
    $this->getJson('/api/v1/dokter/'.$dokter.'/slot?tanggal='.JSL_TANGGAL)->assertOk();
});

/*
|--------------------------------------------------------------------------
| GET /jadwal -- the weekly window template
|--------------------------------------------------------------------------
*/

test('the weekly read answers the project envelope with a seven-key object', function (): void {
    $dokter = (int) jslDokter()->getKey();
    $jadwalId = jslJadwal($dokter, ['durasi_slot_menit' => 20, 'kuota_per_sesi' => 3]);

    $response = $this->getJson('/api/v1/dokter/'.$dokter.'/jadwal')->assertOk();

    // Key order is the project envelope's and is load-bearing: `success`, `data`,
    // `message`, then `meta` last so adding it renumbered nothing.
    $body = json_decode($response->getContent());
    expect(array_keys((array) $body))->toBe(['success', 'data', 'message', 'meta'])
        ->and($body->success)->toBeTrue()
        ->and(is_string($body->message))->toBeTrue()
        ->and($body->message)->not->toBe('');

    /**
     * A JSON OBJECT, not a JSON array.
     *
     * The service builds its map with `array_fill(0, 7, [])`, and PHP's
     * `json_encode` renders an integer-keyed 0..6 array as `[[], []]`. The plan,
     * the service docblock and the web client's `JadwalMinggu` all describe a
     * keyed map, so `DokterJadwalResource` casts it; this is the assertion that
     * the cast is still there.
     */
    $minggu = $body->data->jadwal;
    expect($minggu)->toBeObject();
    expect(count(get_object_vars($minggu)))->toBe(7);

    for ($hari = 0; $hari <= 6; $hari++) {
        expect($minggu->{(string) $hari})->toBeArray();
    }

    // `meta` is the project-wide block in its degenerate single-page form, and
    // `total` counts WINDOW ROWS, not the seven day keys.
    expect($response->json('meta'))->toBe([
        'current_page' => 1,
        'last_page' => 1,
        'per_page' => 1,
        'total' => 1,
        'from' => 1,
        'to' => 1,
    ]);

    // The one window, allow-listed to the eight `dokter_jadwal` columns. A pass
    // through `toArray()` would publish `dibuat_at` and `diubah_at` too.
    $senin = $response->json('data.jadwal.1');
    expect($senin)->toHaveCount(1)
        ->and($senin[0])->toBe([
            'jadwal_id' => $jadwalId,
            'hari' => JSL_HARI_KE,
            'tipe_layanan' => 'online',
            'faskes_id' => null,
            'jam_mulai' => '09:00:00',
            'jam_selesai' => '10:00:00',
            'durasi_slot_menit' => 20,
            'kuota_per_sesi' => 3,
        ]);
});

test('a null kuota_per_sesi stays null on the wire and is not substituted with 1', function (): void {
    $dokter = (int) jslDokter()->getKey();
    jslJadwal($dokter, ['kuota_per_sesi' => null]);

    $response = $this->getJson('/api/v1/dokter/'.$dokter.'/jadwal')->assertOk();

    // `dokter_jadwal.kuota_per_sesi` is `SMALLINT UNSIGNED NULL` (`:479`), and a
    // client rendering a weekly grid has to be able to tell a genuinely uncapped
    // window from one it must assume holds a single seat. The SERVICE substitutes
    // 1 when it computes availability; the published template must not.
    expect($response->json('data.jadwal.1.0.kuota_per_sesi'))->toBeNull()
        // And it is genuinely NULL, not the string "null" and not 1.
        ->and($response->json('data.jadwal.1.0'))->toHaveKey('kuota_per_sesi');
});

test('a doctor with no schedule rows answers seven EMPTY days, not an empty list', function (): void {
    $dokter = (int) jslDokter()->getKey();

    $response = $this->getJson('/api/v1/dokter/'.$dokter.'/jadwal')->assertOk();
    $minggu = json_decode($response->getContent())->data->jadwal;

    // The shape is the contract: seven keys so a client can render a week without
    // probing which days exist. Collapsing it to `[]` would make "no rows"
    // indistinguishable from "no such key".
    expect($minggu)->toBeObject()
        ->and(count(get_object_vars($minggu)))->toBe(7)
        ->and($minggu->{'1'})->toBe([]);

    expect($response->json('meta'))->toBe([
        'current_page' => 1,
        'last_page' => 1,
        'per_page' => 0,
        'total' => 0,
        'from' => null,
        'to' => null,
    ]);
});

test('an inactive window is absent from the published template, not merely unusable', function (): void {
    $dokter = (int) jslDokter()->getKey();
    $aktif = jslJadwal($dokter, ['jam_mulai' => '09:00:00', 'jam_selesai' => '09:15:00']);
    jslJadwal($dokter, [
        'status_aktif' => false,
        'jam_mulai' => '14:00:00',
        'jam_selesai' => '14:15:00',
    ]);

    $senin = $this->getJson('/api/v1/dokter/'.$dokter.'/jadwal')->assertOk()->json('data.jadwal.1');

    expect($senin)->toHaveCount(1)
        ->and($senin[0]['jadwal_id'])->toBe($aktif)
        ->and(array_column($senin, 'jam_mulai'))->not->toContain('14:00:00');
});

/*
|--------------------------------------------------------------------------
| GET /slot -- the bookable candidates on one date
|--------------------------------------------------------------------------
*/

test('the slot read answers tanggal, timezone and slots exactly as the plan names them', function (): void {
    $dokter = (int) jslDokter()->getKey();
    $jadwalId = jslJadwal($dokter);

    $response = $this->getJson('/api/v1/dokter/'.$dokter.'/slot?tanggal='.JSL_TANGGAL)->assertOk();

    $body = json_decode($response->getContent());
    expect(array_keys((array) $body))->toBe(['success', 'data', 'message', 'meta'])
        ->and($body->data->tanggal)->toBe(JSL_TANGGAL)
        // Asia/Jakarta, deliberately NOT `config('app.timezone')`, which is UTC.
        ->and($body->data->timezone)->toBe(SlotAvailabilityService::ZONA_WAKTU)
        ->and($body->data->timezone)->not->toBe(config('app.timezone'))
        ->and($body->data->slots)->toHaveCount(4);

    // Nine zero-oh-eight through nine forty-five: stepping `durasi_slot_menit`
    // (`:478`) from `jam_mulai` (`:476`) to `jam_selesai` (`:477`). The end is
    // reachable exactly and no partial trailing slot is published.
    expect(jslMulai($response->json('data.slots')))->toBe([
        '09:00:00', '09:15:00', '09:30:00', '09:45:00',
    ]);

    // One row, allow-listed to seven keys: the service's own row shape with no
    // timestamp added and no other booking's identity leaked.
    expect($response->json('data.slots.0'))->toBe([
        'jadwal_id' => $jadwalId,
        'jam_mulai' => '09:00:00',
        'jam_selesai' => '09:15:00',
        'tipe_layanan' => 'online',
        'faskes_id' => null,
        'tersedia' => true,
        'alasan' => null,
    ]);

    expect($response->json('meta'))->toBe([
        'current_page' => 1,
        'last_page' => 1,
        'per_page' => 4,
        'total' => 4,
        'from' => 1,
        'to' => 4,
    ]);
});

test('the endpoint publishes the service answer verbatim, so nothing is re-derived', function (): void {
    // The cheapest possible guard against a second implementation: the decoded
    // body must BE the service's return value. A route that computed its own
    // availability could agree on a simple fixture and disagree here.
    //
    // The fixture is built so three different answers coexist in one body: a
    // window whose quota of two is filled by two bookings that BOTH overlap its
    // first slot, a second slot in the same window that neither booking touches,
    // and a second window with a different `tipe_layanan`. A per-DAY count, a
    // closed-overlap predicate or a boolean check would each break exactly one of
    // those three and agree on the rest.
    $dokter = (int) jslDokter()->getKey();
    $jadwalId = jslJadwal($dokter, ['kuota_per_sesi' => 2, 'jam_selesai' => '09:45:00']);
    jslBooking($dokter, '09:00:00', '09:15:00');
    jslBooking($dokter, '09:00:00', '09:15:00');
    jslJadwal($dokter, [
        'jam_mulai' => '14:00:00',
        'jam_selesai' => '15:00:00',
        'tipe_layanan' => 'home_visit',
    ]);

    $response = $this->getJson('/api/v1/dokter/'.$dokter.'/slot?tanggal='.JSL_TANGGAL)->assertOk();

    $dariService = app(SlotAvailabilityService::class)->getSlotTerbuka(
        Dokter::query()->findOrFail($dokter),
        JSL_TANGGAL,
    );

    // Three slots in the 09:00-09:45 window plus four in the 14:00-15:00 one.
    expect($response->json('data.slots'))->toEqual($dariService)
        ->and($response->json('data.slots'))->toHaveCount(7)
        // Filled: two overlapping bookings against a quota of two.
        ->and(jslCari($dariService, '09:00:00')['tersedia'])->toBeFalse()
        ->and(jslCari($dariService, '09:00:00')['alasan'])->toBe(SlotAvailabilityService::ALASAN_PENUH)
        // Untouched: a booking ending exactly at 09:15 does not overlap 09:15-09:30.
        ->and(jslCari($dariService, '09:15:00')['tersedia'])->toBeTrue()
        ->and(jslCari($dariService, '09:30:00')['tersedia'])->toBeTrue()
        // A different window and a different service type, published on the same page.
        ->and(jslCari($dariService, '14:00:00')['tipe_layanan'])->toBe('home_visit')
        ->and(jslCari($dariService, '14:00:00')['jadwal_id'])->not->toBe($jadwalId);
});

test('BOUNDARY: the quota, not a boolean, decides a slot - two seats, one booking, still open', function (): void {
    // Todo 26's pinned bug, repeated on the HTTP surface. A `count == 0` check
    // makes every `kuota_per_sesi > 1` window permanently unbookable, and a route
    // that re-derived availability would reintroduce it while leaving every other
    // test in this file green.
    $dokter = (int) jslDokter()->getKey();
    jslJadwal($dokter, ['kuota_per_sesi' => 2]);

    $url = '/api/v1/dokter/'.$dokter.'/slot?tanggal='.JSL_TANGGAL;

    expect($this->getJson($url)->assertOk()->json('data.slots.0.tersedia'))->toBeTrue();

    jslBooking($dokter, '09:00:00', '09:15:00');

    $satu = $this->getJson($url)->assertOk();
    expect($satu->json('data.slots.0.tersedia'))->toBeTrue()
        ->and($satu->json('data.slots.0.alasan'))->toBeNull()
        // Only the touched slot moves. A per-DAY count instead of a per-slot
        // overlap count would close all four.
        ->and(array_column($satu->json('data.slots'), 'tersedia'))->toBe([true, true, true, true]);

    jslBooking($dokter, '09:00:00', '09:15:00');

    $dua = $this->getJson($url)->assertOk();
    expect($dua->json('data.slots.0.tersedia'))->toBeFalse()
        ->and($dua->json('data.slots.0.alasan'))->toBe(SlotAvailabilityService::ALASAN_PENUH)
        ->and(array_column($dua->json('data.slots'), 'tersedia'))->toBe([false, true, true, true]);
});

test('BOUNDARY: a booking that merely touches a slot does not collide with it', function (): void {
    /**
     * Half-open overlap, strict at BOTH ends, and the two cases are separate
     * doctors rather than one fixture.
     *
     * They cannot share a doctor. A booking that ENDS exactly when a slot begins
     * is 08:45-09:00 for the 09:00 slot; a booking that STARTS exactly when a slot
     * ENDS is 09:15-10:00 for the 09:00-09:15 slot -- and that second one runs
     * through 09:15-09:30 and 09:30-09:45, so putting both on one doctor would
     * close two slots and the assertion about "only the touching slot" would be
     * about a page that is closed for an unrelated reason.
     */
    $yangBerakhir = (int) jslDokter()->getKey();
    jslJadwal($yangBerakhir);
    jslBooking($yangBerakhir, '08:45:00', '09:00:00');

    $slots = $this->getJson('/api/v1/dokter/'.$yangBerakhir.'/slot?tanggal='.JSL_TANGGAL)
        ->assertOk()
        ->json('data.slots');

    expect(jslCari($slots, '09:00:00')['tersedia'])->toBeTrue()
        ->and(jslCari($slots, '09:00:00')['alasan'])->toBeNull()
        // Nothing else on the page is touched either.
        ->and(array_column($slots, 'tersedia'))->toBe([true, true, true, true]);

    $yangMulai = (int) jslDokter()->getKey();
    jslJadwal($yangMulai);
    jslBooking($yangMulai, '09:15:00', '10:00:00');

    $slots = $this->getJson('/api/v1/dokter/'.$yangMulai.'/slot?tanggal='.JSL_TANGGAL)
        ->assertOk()
        ->json('data.slots');

    expect(jslCari($slots, '09:00:00')['tersedia'])->toBeTrue()
        ->and(jslCari($slots, '09:00:00')['alasan'])->toBeNull()
        // And the two it genuinely covers are closed, so the free one is not free
        // because nothing was ever counted.
        ->and(jslCari($slots, '09:15:00')['tersedia'])->toBeFalse()
        ->and(jslCari($slots, '09:30:00')['tersedia'])->toBeFalse();
});

test('BOUNDARY: a holiday closes the day with alasan libur on every published slot', function (): void {
    $dokter = (int) jslDokter()->getKey();
    jslJadwal($dokter);
    jslLibur($dokter, JSL_TANGGAL);

    $response = $this->getJson('/api/v1/dokter/'.$dokter.'/slot?tanggal='.JSL_TANGGAL)->assertOk();
    $slots = $response->json('data.slots');

    // The candidates are still published, each closed. An empty list could not
    // distinguish a holiday from a weekday the doctor does not work.
    expect($slots)->toHaveCount(4)
        ->and(array_values(array_unique(array_column($slots, 'tersedia'))))->toBe([false])
        ->and(array_values(array_unique(array_column($slots, 'alasan'))))
        ->toBe([SlotAvailabilityService::ALASAN_LIBUR])
        // A holiday is one doctor's: the comparison is per `dokter_id` (`:492`).
        ->and($this->getJson('/api/v1/dokter/'.jslDokter()->getKey().'/slot?tanggal='.JSL_TANGGAL)
            ->assertOk()
            ->json('data.slots'))->toBe([]);
});

test('a dibatalkan booking does not consume a slot, and a consuming status does', function (): void {
    $dibatalkan = (int) jslDokter()->getKey();
    jslJadwal($dibatalkan);
    jslBooking($dibatalkan, '09:00:00', '09:15:00', ['status' => 'dibatalkan']);

    $kadaluarsa = (int) jslDokter()->getKey();
    jslJadwal($kadaluarsa);
    jslBooking($kadaluarsa, '09:00:00', '09:15:00', ['status' => 'kadaluarsa']);

    $terjadwal = (int) jslDokter()->getKey();
    jslJadwal($terjadwal);
    jslBooking($terjadwal, '09:00:00', '09:15:00', ['status' => 'terjadwal']);

    foreach ([$dibatalkan, $kadaluarsa] as $dokter) {
        $slots = $this->getJson('/api/v1/dokter/'.$dokter.'/slot?tanggal='.JSL_TANGGAL)->assertOk()->json('data.slots');
        expect($slots[0]['tersedia'])->toBeTrue()->and($slots[0]['alasan'])->toBeNull();
    }

    $slots = $this->getJson('/api/v1/dokter/'.$terjadwal.'/slot?tanggal='.JSL_TANGGAL)->assertOk()->json('data.slots');
    expect($slots[0]['tersedia'])->toBeFalse()->and($slots[0]['alasan'])->toBe(SlotAvailabilityService::ALASAN_PENUH);
});

test('a weekday with no window, and a doctor with no window at all, are both an empty list', function (): void {
    // Tuesday carries no window; this doctor has no window on any weekday.
    $salahHari = (int) jslDokter()->getKey();
    jslJadwal($salahHari);
    $tanpaJadwal = (int) jslDokter()->getKey();

    foreach ([$salahHari, $tanpaJadwal] as $dokter) {
        $response = $this->getJson('/api/v1/dokter/'.$dokter.'/slot?tanggal='.JSL_TANGGAL_LAIN)->assertOk();

        expect($response->json('data.slots'))->toBe([])
            ->and($response->json('data.tanggal'))->toBe(JSL_TANGGAL_LAIN)
            ->and($response->json('meta.total'))->toBe(0)
            ->and($response->json('meta.from'))->toBeNull();
    }
});

test('BOUNDARY: the STR is checked against the CONSULTATION date, and today only gates who is listed', function (): void {
    /**
     * The two halves are a PAIR and neither is meaningful alone.
     *
     * The doctor is licensed THROUGH today, so the directory gate admits them and
     * both routes answer 200. The licence then lapses before the requested date,
     * so `getSlotTerbuka()` returns nothing for it. A today-based rule would have
     * published three slots for the following Monday, and asserting the empty list
     * alone would pass for the wrong reason the day after the licence lapsed.
     */
    $hariIni = jslHariIni();
    $seninDepan = $hariIni->copy()->next(Carbon::MONDAY);

    $dokter = jslDokter(null, ['str_berlaku_sampai' => $hariIni->toDateString()]);
    $jadwalId = jslJadwal((int) $dokter->getKey(), ['hari' => (int) $seninDepan->dayOfWeek]);

    // The weekly template is NOT date-gated and does not need to be: it is a
    // profile attribute, and the doctor passed the eligibility gate above.
    $minggu = $this->getJson('/api/v1/dokter/'.$dokter->getKey().'/jadwal')->assertOk();
    expect($minggu->json('data.jadwal.'.JSL_HARI_KE.'.0.jadwal_id'))->toBe($jadwalId);

    // The date the licence does NOT cover.
    $slot = $this->getJson('/api/v1/dokter/'.$dokter->getKey().'/slot?tanggal='.$seninDepan->toDateString())
        ->assertOk();

    expect($slot->json('data.slots'))->toBe([])
        ->and($slot->json('data.tanggal'))->toBe($seninDepan->toDateString())
        // 200, not 404: the doctor is eligible, the DATE is not bookable.
        ->and($slot->status())->toBe(200);
});

test('a slot that has already ended today is published but closed, with alasan lewat_waktu', function (): void {
    $hariIni = jslHariIni();
    $sekarang = $hariIni->copy()->setTime(10, 7, 0);

    Carbon::setTestNow($sekarang);

    try {
        $dokter = (int) jslDokter()->getKey();

        // `hari` follows the reference day rather than being pinned to Monday, so
        // this holds whatever weekday the suite runs on. The window starts at
        // 08:07 so a 60-minute step lands a boundary exactly on the frozen "now".
        jslJadwal($dokter, [
            'hari' => (int) $hariIni->dayOfWeek,
            'jam_mulai' => '08:07:00',
            'jam_selesai' => '12:07:00',
            'durasi_slot_menit' => 60,
        ]);

        $slots = $this->getJson('/api/v1/dokter/'.$dokter.'/slot?tanggal='.$hariIni->toDateString())
            ->assertOk()
            ->json('data.slots');

        // `jam_selesai == now` is already over; the slot STARTING at 10:07 runs to
        // 11:07 and is open.
        expect(jslCari($slots, '09:07:00')['tersedia'])->toBeFalse()
            ->and(jslCari($slots, '09:07:00')['alasan'])->toBe(SlotAvailabilityService::ALASAN_LEWAT_WAKTU)
            ->and(jslCari($slots, '10:07:00')['tersedia'])->toBeTrue()
            ->and(jslCari($slots, '10:07:00')['alasan'])->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

test('a H:i:s wall clock is published unshifted, and never as a local datetime', function (): void {
    // `config/app.php` is UTC while the DDL stores naive wall clock. Converting
    // here would move a 23:30 Jakarta slot to 16:30 UTC, so `timezone` is the
    // label that lets a client render it correctly without a shift.
    $dokter = (int) jslDokter()->getKey();
    jslJadwal($dokter, ['jam_mulai' => '23:30:00', 'jam_selesai' => '23:45:00']);

    $response = $this->getJson('/api/v1/dokter/'.$dokter.'/slot?tanggal='.JSL_TANGGAL)->assertOk();

    expect($response->json('data.slots.0.jam_mulai'))->toBe('23:30:00')
        ->and($response->json('data.slots.0.jam_selesai'))->toBe('23:45:00')
        ->and($response->json('data.timezone'))->toBe('Asia/Jakarta')
        // Nothing anywhere in the body carries a date-time: a `Y-m-d H:i:s` would
        // be a value somebody had already decided to convert.
        ->and($response->getContent())->not->toMatch('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/');
});

/*
|--------------------------------------------------------------------------
| Input shape
|--------------------------------------------------------------------------
*/

test('BOUNDARY: a malformed tanggal is 422, including the calendar rollover PHP would accept', function (): void {
    $dokter = (int) jslDokter()->getKey();
    jslJadwal($dokter);

    /**
     * `2026-13-45` is the case the plan names, and it is the interesting one:
     * `DateTime::createFromFormat('Y-m-d', '2026-13-45')` does NOT fail, PHP
     * overflows month 13 and day 45 into 2027-02-14, and a rule that only asked
     * "did it parse" would answer about February. `date_format` round-trips the
     * parsed value back through `format()` and compares, and that comparison is
     * the whole trap.
     */
    foreach (['2026-13-45', '2026-02-30', '2026-12-7', '07-12-2026', 'bukan-tanggal', 'now', ''] as $rusak) {
        $response = $this->getJson('/api/v1/dokter/'.$dokter.'/slot?tanggal='.rawurlencode($rusak))
            ->assertUnprocessable();

        expect($response->json('success'))->toBeFalse()
            ->and($response->json('message'))->toBe('The given data was invalid.')
            ->and($response->json('errors'))->toHaveKey('tanggal');
    }

    // Absent is not malformed, and is answered by the same rule: there is no
    // sensible default date, because answering about today when the caller did
    // not say would be a silent and wrong substitution.
    $response = $this->getJson('/api/v1/dokter/'.$dokter.'/slot')->assertUnprocessable();
    expect($response->json('errors'))->toHaveKey('tanggal');

    // And the well-formed neighbour still works, so the rule is not just
    // refusing everything.
    $this->getJson('/api/v1/dokter/'.$dokter.'/slot?tanggal='.JSL_TANGGAL)->assertOk();
});

test('validation runs before the doctor lookup, so a bad date cannot probe existence', function (): void {
    $ada = (int) jslDokter()->getKey();
    $tidakAda = $ada + 1000;

    $sama = $this->getJson('/api/v1/dokter/'.$ada.'/slot?tanggal=2026-13-45')->assertUnprocessable()->json();
    $lain = $this->getJson('/api/v1/dokter/'.$tidakAda.'/slot?tanggal=2026-13-45')->assertUnprocessable()->json();

    // One body for both, so the 422 reveals nothing about whether the id exists.
    // The order is deliberate: the request shape is checked before the resource
    // is resolved, and a 404 for a malformed date would be the other order.
    expect($sama)->toBe($lain);
});

/*
|--------------------------------------------------------------------------
| Who gets a 404, and on which route
|--------------------------------------------------------------------------
*/

test('the four ineligible-doctor cases answer ONE 404 envelope on BOTH routes', function (): void {
    $hariIni = jslHariIni();

    $tidakAdaId = null;
    $belumVerifikasi = (int) jslDokter(null, ['status_verifikasi' => 'pending'])->getKey();
    $strKedaluwarsa = (int) jslDokter(null, [
        'str_berlaku_sampai' => $hariIni->copy()->subDay()->toDateString(),
    ])->getKey();

    $dihapus = jslDokter();
    $dihapus->user->forceFill(['dihapus_at' => $hariIni->copy()->addHour()])->save();
    $dihapusId = (int) $dihapus->getKey();

    $tidakAdaId = max($belumVerifikasi, $strKedaluwarsa, $dihapusId) + 1000;

    $kasus = [
        'absent' => (string) $tidakAdaId,
        'unverified' => (string) $belumVerifikasi,
        'str_expired' => (string) $strKedaluwarsa,
        'soft_deleted' => (string) $dihapusId,
        'zero' => '0',
        'non_numeric' => 'bukan-angka',
    ];

    $badan = [];

    foreach ($kasus as $label => $id) {
        foreach ([
            'jadwal' => '/api/v1/dokter/'.$id.'/jadwal',
            'slot' => '/api/v1/dokter/'.$id.'/slot?tanggal='.JSL_TANGGAL,
            // The sibling detail route, for the uniformity claim below.
            'show' => '/api/v1/dokter/'.$id,
        ] as $rute => $url) {
            $response = $this->getJson($url)->assertNotFound();

            expect($response->json('success'))->toBeFalse()
                ->and($response->json('message'))->toBe('Resource not found.')
                ->and($response->json('errors'))->toBe([]);

            $badan[$label.'.'.$rute] = $response->json();
        }
    }

    // Eighteen bodies, one shape. A caller that could tell "unverified" from
    // "absent" could enumerate the verification state of every doctor account
    // from an unauthenticated endpoint, and a caller that could tell "no such
    // doctor" from "malformed id segment" could enumerate valid ids. The
    // non-numeric case is the router's own 404 and it is byte-identical to the
    // controllers', which is what makes the claim hold.
    expect(array_unique(array_map('json_encode', $badan)))->toHaveCount(1);
});

test('an STR-expired doctor is 404 even on a date the licence WOULD have covered', function (): void {
    // The two STR rules are different questions, and this is the pair that keeps
    // them from being confused. The directory gate asks "may this doctor be shown
    // now" and answers no, so the endpoint is a 404 and the date never gets
    // asked about. The service's own rule 4 ("may a visit happen on date D")
    // would have said yes for a past date, and that difference is the point:
    // a 404 hides the licence state, a `slots: []` would not.
    $kemarin = jslHariIni()->copy()->subDay();
    $dokter = jslDokter(null, ['str_berlaku_sampai' => $kemarin->toDateString()]);
    jslJadwal((int) $dokter->getKey());

    $this->getJson('/api/v1/dokter/'.$dokter->getKey().'/slot?tanggal='.$kemarin->toDateString())
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

test('neither route pages, and a per_page sent anyway cannot grow or shrink the answer', function (): void {
    $dokter = (int) jslDokter()->getKey();
    jslJadwal($dokter);

    $polos = $this->getJson('/api/v1/dokter/'.$dokter.'/slot?tanggal='.JSL_TANGGAL)->assertOk();
    $dengan = $this->getJson(
        '/api/v1/dokter/'.$dokter.'/slot?tanggal='.JSL_TANGGAL.'&page=99&per_page=1000000',
    )->assertOk();

    // `page` and `per_page` are not rules on this endpoint, so they are ignored
    // rather than clamped -- and there is nothing to clamp, because the answer is
    // the candidate set of one date, already bounded by that weekday's
    // `dokter_jadwal` rows. Paging it would not truncate: it would publish a lie,
    // with the missing candidates reading as nonexistent rather than unavailable.
    expect($polos->json('data.slots'))->toHaveCount(4)
        ->and($polos->json('data.slots'))->toBe($dengan->json('data.slots'))
        ->and($dengan->json('meta'))->toBe([
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => 4,
            'total' => 4,
            'from' => 1,
            'to' => 4,
        ]);

    // The weekly read is structurally unpageable: seven keys that must all be
    // present, or a client cannot render a week.
    $mingguPolos = $this->getJson('/api/v1/dokter/'.$dokter.'/jadwal?page=99&per_page=1')->assertOk();
    expect(count(get_object_vars(json_decode($mingguPolos->getContent())->data->jadwal)))->toBe(7);
});

/*
|--------------------------------------------------------------------------
| The DDL is what the wire claims
|--------------------------------------------------------------------------
*/

test('every column the two responses publish is a real column, and no timestamp is published', function (): void {
    $spesifikasi = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    $jadwal = $spesifikasi->table('dokter_jadwal');
    expect($jadwal)->not->toBeNull();

    /**
     * The five window columns both responses carry, and the type each is read as.
     *
     * A `TIME` read as a timestamp and a `DATE` read as an instant are two
     * different bugs, and the `tipe_layanan` ENUM is the three-value
     * `dokter_jadwal` one rather than the four-value `booking` one.
     */
    expect($jadwal->columns['jam_mulai']->type)->toBe('time')
        ->and($jadwal->columns['jam_selesai']->type)->toBe('time')
        ->and($jadwal->columns['hari']->type)->toBe('tinyint')
        ->and($jadwal->columns['hari']->unsigned)->toBeTrue()
        ->and($jadwal->columns['durasi_slot_menit']->default)->toBe('15')
        ->and($jadwal->columns['kuota_per_sesi']->nullable)->toBeTrue()
        ->and($jadwal->columns['faskes_id']->nullable)->toBeTrue()
        ->and($jadwal->columns['tipe_layanan']->type)->toBe("enum('online','klinik','home_visit')");

    // The keys the two resources publish are all real columns of that table, with
    // `jadwal_id` being the row's own `id` under the name `booking.jadwal_id`
    // (`:504`) already gives it.
    foreach (['id', 'hari', 'tipe_layanan', 'faskes_id', 'jam_mulai', 'jam_selesai', 'durasi_slot_menit', 'kuota_per_sesi'] as $kolom) {
        expect($jadwal->columns)->toHaveKey($kolom);
    }

    /**
     * The timestamp names, and the convention that does NOT exist here.
     *
     * This schema spells them `dibuat_at`/`diubah_at` (`:483`-`:484`). A resource
     * that reached for Eloquent's `created_at`/`updated_at`, or a test that
     * asserted one of them, would be asserting a column the DDL does not have.
     */
    foreach (['dibuat_at', 'diubah_at'] as $cap) {
        expect($jadwal->columns)->toHaveKey($cap);
    }

    foreach (['created_at', 'updated_at', 'deleted_at'] as $palsu) {
        expect($jadwal->columns)->not->toHaveKey($palsu);
    }

    // The soft-delete marker is `users.dihapus_at` (`:148`) and nothing else, and
    // `dokter_libur` has no timestamps whatsoever (`:490`-`:496`).
    $libur = $spesifikasi->table('dokter_libur');
    expect($libur->columns)->toHaveKey('dokter_id')
        ->and($libur->columns)->toHaveKey('tanggal')
        ->and($libur->columns)->not->toHaveKey('dibuat_at')
        ->and($libur->columns)->not->toHaveKey('diubah_at');

    $users = $spesifikasi->table('users');
    expect($users->columns['dihapus_at']->nullable)->toBeTrue()
        ->and($users->columns)->not->toHaveKey('deleted_at');
});

test('the reasons a slot is closed are exactly the service constants and nothing else', function (): void {
    // `tersedia` and `alasan` are DERIVED, not columns, so the DDL cannot pin
    // them. What it can pin is the vocabulary: three closed reasons plus `null`
    // for available, and a fourth would be a compile-time-unreachable branch on
    // the web client's `AlasanSlot` union.
    //
    // Compared as a SET, not as a list: declaration order and precedence order are
    // two different things here (`libur` > `lewat_waktu` > `penuh` is decided per
    // slot, while the constants are declared libur, penuh, lewat_waktu), and
    // pinning one of those orders would pin an accident.
    $daftar = [
        SlotAvailabilityService::ALASAN_LIBUR,
        SlotAvailabilityService::ALASAN_PENUH,
        SlotAvailabilityService::ALASAN_LEWAT_WAKTU,
    ];
    $teracak = $daftar;
    sort($teracak);

    expect($teracak)->toBe(['lewat_waktu', 'libur', 'penuh'])
        ->and(array_unique($daftar))->toHaveCount(3)
        // The precedence the service applies is the reverse of alphabetical and is
        // its own decision; a slot closed by a day off and by capacity reports the
        // day off.
        ->and(SlotAvailabilityService::ALASAN_LIBUR)->toBe('libur')
        ->and(SlotAvailabilityService::ALASAN_LEWAT_WAKTU)->toBe('lewat_waktu')
        ->and(SlotAvailabilityService::ALASAN_PENUH)->toBe('penuh');

    // All three are reachable through the endpoint, each on a fixture built to
    // produce it, so the constants are not merely declared. `lewat_waktu` needs a
    // frozen clock because it is the only one of the three that depends on what
    // time it is, and the reset is in this test's own `finally`.
    $hariIni = jslHariIni();
    Carbon::setTestNow($hariIni->copy()->setTime(10, 7, 0));

    try {
        $libur = (int) jslDokter()->getKey();
        jslJadwal($libur);
        jslLibur($libur, JSL_TANGGAL);

        $penuh = (int) jslDokter()->getKey();
        jslJadwal($penuh, ['kuota_per_sesi' => 1]);
        jslBooking($penuh, '09:00:00', '09:15:00');

        $lewat = (int) jslDokter()->getKey();
        jslJadwal($lewat, [
            'hari' => (int) $hariIni->dayOfWeek,
            'jam_mulai' => '08:07:00',
            'jam_selesai' => '12:07:00',
            'durasi_slot_menit' => 60,
        ]);

        $terlihat = [];
        $tanggal = $hariIni->toDateString();

        foreach ([$libur, $penuh] as $dokter) {
            foreach ($this->getJson('/api/v1/dokter/'.$dokter.'/slot?tanggal='.JSL_TANGGAL)
                ->assertOk()
                ->json('data.slots') as $slot) {
                $terlihat[] = $slot['alasan'];
            }
        }

        foreach ($this->getJson('/api/v1/dokter/'.$lewat.'/slot?tanggal='.$tanggal)
            ->assertOk()
            ->json('data.slots') as $slot) {
            $terlihat[] = $slot['alasan'];
        }
    } finally {
        Carbon::setTestNow();
    }

    $tertutup = array_values(array_unique(array_filter($terlihat)));
    sort($tertutup);

    // Every reason the page carries is either `null` or one of the three; `null`
    // is the available one and is dropped by the filter above.
    $semua = array_values(array_unique($terlihat));
    $takTertutup = array_values(array_filter($semua, static fn ($alasan): bool => $alasan !== null));

    expect($tertutup)->toBe(['lewat_waktu', 'libur', 'penuh'])
        ->and(array_diff($takTertutup, $daftar))->toBe([])
        ->and(array_diff($daftar, $takTertutup))->toBe([]);
});

test('the timezone published is the service constant, and the STR class is the one STR class', function (): void {
    // Two callers, two reference days, ONE boundary. `StrBerlaku` exists so the
    // inclusive operator and the fail-closed NULL reading cannot be spelled twice;
    // an endpoint that re-derived either would reintroduce the second source.
    expect(SlotAvailabilityService::ZONA_WAKTU)->toBe('Asia/Jakarta')
        ->and(StrBerlaku::REFERENSI)->toBe(SlotAvailabilityService::ZONA_WAKTU)
        ->and(StrBerlaku::OPERATOR_BATAS)->toBe('>=');
});
