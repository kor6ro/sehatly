<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DokterController;
use App\Models\Dokter;
use App\Models\Faskes;
use App\Models\MasterSpesialisasi;
use App\Models\User;
use App\Services\Dokter\DokterDirectoryService;
use App\Services\Dokter\DokterKatalog;
use App\Support\Rbac\RbacCatalog;
use App\Support\Schema\SqlSchemaParser;
use App\Support\WaktuIndonesia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The public doctor directory
|--------------------------------------------------------------------------
|
| `GET /api/v1/dokter`, `GET /api/v1/dokter/{dokter}` and
| `GET /api/v1/master-spesialisasi`, and the two eligibility rules the domain
| demands: a doctor is listed only when `status_verifikasi` is `terverifikasi`
| and only when their STR has not expired.
|
| **These are Pest closure tests, not a PHPUnit class, and that is
| load-bearing.** `tests/Pest.php` binds `RefreshDatabase` with `->in('Feature')`,
| which covers closure tests and not a plain `class FooTest` in the same
| directory. Without the trait the rows written below survive into the next test.
| See `AuthFlowTest` for the same note.
|
| **The routes are registered here, in-process, and that is a deliberate
| consequence of todo 21 owning `routes/api.php` this round.** `beforeEach`
| registers them **only when they are absent**, so:
|
| - before the orchestrator pastes the block from
|   `.omo/evidence/task-22-sehatly.md`, these tests drive an in-process
|   registration of exactly the URI, controller and route name the block
|   declares;
| - afterwards, `routes/api.php` is loaded during bootstrap and registers first,
|   so the very same tests drive the real route table and the in-process
|   registration is skipped.
|
| Nothing here is a probe. Every test goes through real HTTP, the real
| middleware stack, the real `FormRequest`, the real resources and the real
| service. Only the *registration* is local.
|
| **No seeder runs.** `RefreshDatabase` performs one `migrate:fresh` per process
| and does not pass `--seed`, so `master_spesialisasi` is empty and every row
| below is written by the test. That is what makes the counts exact: a test
| asserting "exactly one doctor is listed" cannot be satisfied by a leftover
| fixture from a previous test, and `meta.total` is a real count rather than
| `DevFixtureSeeder`'s two.
|
| ## The boundary tests are on the exact date, not on "some old date"
|
| `dokter.str_berlaku_sampai` is a `DATE` (`telemedicine_test.sql:414`) and the
| rule is inclusive: a doctor whose STR expires *today* is listed, one whose STR
| expired *yesterday* is not. Both sides of that line are asserted, once through
| HTTP against the clinic's own calendar day - `WaktuIndonesia::tanggal()`, the
| same primitive the service asks - and once through the service's explicit
| `$asOf` parameter with a frozen date, so no assertion can be broken by a
| midnight rollover mid-test.
|
| **The date basis is stated rather than inherited.** The rule compares a `DATE`
| against a day, so the day is `Asia/Jakarta`, and a fixture built from any
| other calendar is a test of a different predicate than the one that runs. That
| is not hypothetical: this fixture read `SELECT CURDATE()`, which is the
| *session's* wall clock and is the UTC day on this connection, so for seven
| hours every day it was a day out from the rule it was supposed to pin - and
| the suite read green for the other seventeen. `DokterStrZonaWaktuTest` pins the
| basis at both ends of that window.
|
*/

/*
|--------------------------------------------------------------------------
| Route registration
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $sudahAda = collect(Route::getRoutes()->getRoutes())
        ->contains(fn ($route): bool => $route->uri() === 'api/v1/dokter');

    if ($sudahAda) {
        return;
    }

    Route::get('api/v1/dokter', [DokterController::class, 'index'])->name('dokter.index');
    Route::get('api/v1/dokter/{dokter}', [DokterController::class, 'show'])->name('dokter.show');
    Route::get('api/v1/master-spesialisasi', [DokterController::class, 'spesialisasiIndex'])
        ->name('master-spesialisasi.index');
});

/*
|--------------------------------------------------------------------------
| Row builders
|--------------------------------------------------------------------------
|
| `Dokter`, `MasterSpesialisasi` and `Faskes` carry no `#[Fillable]` attribute, so
| Eloquent's default `$guarded = ['*']` makes `Model::create()` and
| `hasMany()->create()` silently write nothing. Direct property assignment plus
| `save()` is the route `AuthController` takes; the three child tables are
| written through `DB::table()->insert()` because they are `dokter_spesialisasi`
| (`:437`), `dokter_pendidikan` (`:457`) and `dokter_faskes` (`:447`) rows and no
| model is required to write one.
|
*/

/**
 * Insert a `master_spesialisasi` row and return its id.
 *
 * `id` is `SMALLINT UNSIGNED AUTO_INCREMENT` (`:403`) and the column list is
 * `(kode, nama, tipe)` with no `id`, so the server assigns it. `tipe` is the
 * THREE-value ENUM at `:406`, which shares exactly one member with
 * `dokter.tipe`; the two are never compared in this file.
 */
function direktoriSpesialisasi(string $kode, string $nama, string $tipe = 'spesialis'): int
{
    $row = new MasterSpesialisasi;
    $row->kode = $kode;
    $row->nama = $nama;
    $row->tipe = $tipe;
    $row->save();

    return (int) $row->getKey();
}

/**
 * Insert a `users` row and return it.
 *
 * `tipe` is the seven-value `users.tipe` ENUM (`:139`). `status` is `aktif`
 * (`:140`), so a test that wants a doctor excluded is always excluding on a
 * *doctor* column and never on the account's own state.
 */
function direktoriUser(string $nama, string $tipe = 'dokter'): User
{
    $user = new User;
    $user->uuid = (string) Str::uuid();
    $user->nama_lengkap = $nama;
    $user->email = Str::lower(Str::random(12)).'@example.test';
    $user->no_telepon = '08'.random_int(100000000, 999999999);
    $user->kata_sandi_hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
    $user->tipe = $tipe;
    $user->status = 'aktif';
    $user->bahasa = 'id';
    $user->telepon_terverifikasi = true;
    $user->email_terverifikasi = true;
    $user->save();

    return $user;
}

/**
 * A `dokter` row, with every field that can make it ineligible in one place.
 *
 * The defaults are the DDL's own (`telemedicine_test.sql:409-435`): a `pending`
 * verification is the column default (`:427`), and an active, telemedicine-
 * available doctor is the default on both flags (`:430`, `:426`). Every test
 * that wants a listed doctor therefore states `status_verifikasi` explicitly,
 * and every test that wants an excluded one changes exactly one field - which is
 * what makes "one rule, one difference" checkable by reading the test.
 *
 * @param  array<string, mixed>  $ubah
 */
function direktoriDokter(?User $user = null, array $ubah = []): Dokter
{
    $dokter = new Dokter;
    $dokter->user_id = ($user ?? direktoriUser('Dokter '.Str::upper(Str::random(6))))->getKey();
    $dokter->tipe = 'dokter_umum';
    $dokter->nomor_str = 'STR-TEST-'.Str::upper(Str::random(8));
    $dokter->str_berlaku_sampai = '2099-12-31';
    $dokter->nomor_sip = 'SIP-TEST-'.Str::upper(Str::random(8));
    $dokter->sip_berlaku_sampai = '2099-12-31';
    $dokter->nomor_ihs_satusehat = 'IHS-TEST-'.Str::upper(Str::random(8));
    $dokter->pengalaman_tahun = 5;
    $dokter->bio = 'Profil uji.';
    $dokter->biaya_konsultasi_online = '50000.00';
    $dokter->biaya_luar_jam = '75000.00';
    $dokter->durasi_default_menit = 15;
    $dokter->rating_rata_rata = '0.00';
    $dokter->jumlah_ulasan = 0;
    $dokter->jumlah_konsultasi = 0;
    $dokter->tersedia_telemedisin = true;
    $dokter->status_verifikasi = 'terverifikasi';
    $dokter->file_str_url = 'https://example.test/str.pdf';
    $dokter->file_sip_url = 'https://example.test/sip.pdf';
    $dokter->status_aktif = true;

    foreach ($ubah as $kolom => $nilai) {
        $dokter->{$kolom} = $nilai;
    }

    $dokter->save();

    return $dokter;
}

/**
 * Link a doctor to a specialisation, honouring `uq_dokter_spes` (`:444`).
 */
function direktoriSambungSpesialisasi(Dokter $dokter, int $spesialisasiId, bool $utama = false): void
{
    DB::table('dokter_spesialisasi')->insert([
        'dokter_id' => $dokter->getKey(),
        'spesialisasi_id' => $spesialisasiId,
        'is_utama' => $utama,
    ]);
}

/**
 * Append a `dokter_pendidikan` row.
 *
 * `tahun_lulus` is `SMALLINT UNSIGNED NULL` (`:462`), so `null` is a real,
 * orderable value rather than an absence, and it is exercised on purpose.
 */
function direktoriTambahPendidikan(Dokter $dokter, string $jenjang, string $institusi, ?int $tahun): void
{
    DB::table('dokter_pendidikan')->insert([
        'dokter_id' => $dokter->getKey(),
        'jenjang' => $jenjang,
        'institusi' => $institusi,
        'tahun_lulus' => $tahun,
    ]);
}

/**
 * Insert a `faskes` row and return it.
 *
 * `faskes.tipe` is the five-value ENUM at `:365` and `kode_faskes` is
 * `VARCHAR(20) NULL UNIQUE` (`:362`).
 */
function direktoriFaskes(string $nama): Faskes
{
    $faskes = new Faskes;
    $faskes->kode_faskes = 'FASKES-TEST-'.Str::upper(Str::random(6));
    $faskes->nama = $nama;
    $faskes->tipe = 'klinik';
    $faskes->alamat = 'Jl. Uji Coba No. 1, Jakarta';
    $faskes->akreditasi = 'utama';
    $faskes->status_aktif = true;
    $faskes->save();

    return $faskes;
}

/**
 * Today, as the **clinic** sees it.
 *
 * `dokter.str_berlaku_sampai` is a `DATE` and the rule compares it with a day, so
 * the day has to be the one on the clinic's calendar. It was `SELECT CURDATE()`,
 * which reads the *session's* wall clock: right only by accident while the MySQL
 * session inherited this host's WIB, and the UTC day the moment todo 51 pinned
 * the connection to `+00:00` - which put every fixture here a day behind the rule
 * for seven hours every day.
 *
 * The same question is therefore asked the same way the service asks it, through
 * {@see WaktuIndonesia}, so a fixture and the predicate it is testing cannot be
 * built from two different calendars.
 */
function direktoriHariIni(): string
{
    return WaktuIndonesia::tanggal();
}

/**
 * The `dokter` ids in a list response, in the order the endpoint returned them.
 *
 * @return list<int>
 */
function direktoriIds(array $json): array
{
    return array_map('intval', array_column($json['data']['dokter'] ?? [], 'id'));
}

/*
|--------------------------------------------------------------------------
| Rule 1: the verification state, which the view owns
|--------------------------------------------------------------------------
*/

test('a verified doctor with a live STR is listed and is fetchable by id', function (): void {
    $dokter = direktoriDokter(direktoriUser('Budi Santoso'), ['status_verifikasi' => 'terverifikasi']);

    $daftar = $this->getJson('/api/v1/dokter')->assertOk();

    expect(direktoriIds($daftar->json()))->toBe([(int) $dokter->getKey()])
        ->and($daftar->json('data.dokter.0.nama_lengkap'))->toBe('Budi Santoso')
        ->and($daftar->json('data.dokter.0.status_verifikasi'))->toBe('terverifikasi')
        ->and($daftar->json('meta.total'))->toBe(1)
        ->and($daftar->json('success'))->toBeTrue();

    $detail = $this->getJson('/api/v1/dokter/'.$dokter->getKey())->assertOk();

    expect($detail->json('data.dokter.id'))->toBe((int) $dokter->getKey())
        ->and($detail->json('data.dokter.nama_lengkap'))->toBe('Budi Santoso');
});

test('an UNVERIFIED doctor is excluded from the list and 404s on detail', function (): void {
    $pending = direktoriDokter(direktoriUser('Menunggu Verifikasi'), ['status_verifikasi' => 'pending']);
    $ditolak = direktoriDokter(direktoriUser('Ditolak Verifikasi'), ['status_verifikasi' => 'ditolak']);
    $layak = direktoriDokter(direktoriUser('Layak Verifikasi'), ['status_verifikasi' => 'terverifikasi']);

    // `pending` is the column's own DEFAULT (`:427`), so that row is exactly what
    // a freshly inserted doctor looks like and it must be invisible.
    $daftar = $this->getJson('/api/v1/dokter')->assertOk();

    expect(direktoriIds($daftar->json()))->toBe([(int) $layak->getKey()]);

    $this->getJson('/api/v1/dokter/'.$pending->getKey())->assertNotFound();
    $this->getJson('/api/v1/dokter/'.$ditolak->getKey())->assertNotFound();
});

test('a doctor whose STR has EXPIRED is excluded from the list and 404s on detail', function (): void {
    $kemarin = Carbon::parse(direktoriHariIni())->subDay()->toDateString();

    $kedaluwarsa = direktoriDokter(direktoriUser('STR Kedaluwarsa'), ['str_berlaku_sampai' => $kemarin]);
    $layak = direktoriDokter(direktoriUser('STR Layak'));

    $daftar = $this->getJson('/api/v1/dokter')->assertOk();

    expect(direktoriIds($daftar->json()))->toBe([(int) $layak->getKey()])
        ->and(direktoriIds($daftar->json()))->not->toContain((int) $kedaluwarsa->getKey());

    $this->getJson('/api/v1/dokter/'.$kedaluwarsa->getKey())->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The exact STR boundary, on both sides of the line
|--------------------------------------------------------------------------
*/

test('the STR boundary is inclusive: expiring TODAY is listed, expiring YESTERDAY is not', function (): void {
    $hariIni = Carbon::parse(direktoriHariIni());

    $kemarin = direktoriDokter(direktoriUser('Batas Kemarin'), [
        'str_berlaku_sampai' => $hariIni->copy()->subDay()->toDateString(),
    ]);
    $tepatHariIni = direktoriDokter(direktoriUser('Batas Tepat Hari Ini'), [
        'str_berlaku_sampai' => $hariIni->copy()->toDateString(),
    ]);
    $besok = direktoriDokter(direktoriUser('Batas Besok'), [
        'str_berlaku_sampai' => $hariIni->copy()->addDay()->toDateString(),
    ]);

    $ids = direktoriIds($this->getJson('/api/v1/dokter')->assertOk()->json());

    // `str_berlaku_sampai` is a DATE, so there is no instant during the day on
    // which it lapses: the last moment of validity is the END of the date it
    // names. Expiring today is therefore still licensed today, and this is the
    // assertion that fails if the predicate is `>` instead of `>=`.
    expect($ids)->toContain((int) $tepatHariIni->getKey())
        ->and($ids)->toContain((int) $besok->getKey())
        ->and($ids)->not->toContain((int) $kemarin->getKey());

    $this->getJson('/api/v1/dokter/'.$tepatHariIni->getKey())->assertOk();
    $this->getJson('/api/v1/dokter/'.$kemarin->getKey())->assertNotFound();
});

test('the boundary is exactly the asOf date, one day either side', function (): void {
    $asOf = Carbon::parse('2026-09-27');

    $kemarin = (int) direktoriDokter(direktoriUser('AsOf Kemarin'), ['str_berlaku_sampai' => '2026-09-26'])->getKey();
    $tepat = (int) direktoriDokter(direktoriUser('AsOf Tepat'), ['str_berlaku_sampai' => '2026-09-27'])->getKey();
    $besok = (int) direktoriDokter(direktoriUser('AsOf Besok'), ['str_berlaku_sampai' => '2026-09-28'])->getKey();

    $directory = app(DokterDirectoryService::class);

    // Four evaluations of the same three rows against stated days, which is what
    // makes the boundary exact rather than approximate:
    //
    //   2026-09-26  all three - nothing has lapsed yet
    //   2026-09-27  the 27th and the 28th - the 27th is STILL listed on the day
    //               it expires, which is exactly what `>=` means and what `>` breaks
    //   2026-09-28  the 28th only - the 27th is gone the day AFTER
    //   2026-09-29  nothing - the 28th is gone the day after ITS expiry, which
    //               proves expiry removes rows over time and does not merely
    //               reorder them
    //
    // An off-by-one in either direction changes at least two of the four.
    expect($directory->list([], $asOf)->pluck('dokter_id')->map('intval')->all())
        ->toEqualCanonicalizing([$tepat, $besok])
        ->and($directory->list([], $asOf->copy()->subDay())->pluck('dokter_id')->map('intval')->all())
        ->toEqualCanonicalizing([$kemarin, $tepat, $besok])
        ->and($directory->list([], $asOf->copy()->addDay())->pluck('dokter_id')->map('intval')->all())
        ->toBe([$besok])
        ->and($directory->list([], $asOf->copy()->addDays(2))->pluck('dokter_id')->map('intval')->all())
        ->toBe([])
        ->and($directory->find($tepat, $asOf))->not->toBeNull()
        ->and($directory->find($kemarin, $asOf))->toBeNull()
        ->and($directory->find($besok, $asOf))->not->toBeNull()
        // The 27th is fetchable on the 27th and 404s on the 28th.
        ->and($directory->find($tepat, $asOf->copy()->addDay()))->toBeNull();
});

test('the DDL forbids a NULL STR expiry and the emitted predicate is still fail-closed', function (): void {
    $kolom = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'))
        ->table('dokter')?->columns['str_berlaku_sampai'] ?? null;

    expect($kolom)->not->toBeNull()
        ->and($kolom->type)->toBe('date')
        // `str_berlaku_sampai DATE NOT NULL` (`:414`). MySQL rejects a NULL with
        // 1048 regardless of sql_mode, so "unknown expiry" cannot occur here.
        ->and($kolom->nullable)->toBeFalse();

    // One eligible row so the paged SELECT is issued at all: `paginate()` returns
    // `newCollection()` without querying when the count is zero, and the predicate
    // would then only ever be observed on the count query.
    direktoriDokter(direktoriUser('Predikat Sql'));

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(DokterDirectoryService::class)->list([]);

    $catatan = DB::getQueryLog();
    DB::disableQueryLog();

    $sql = implode(' | ', array_column($catatan, 'query'));

    // The generated SQL, not the prose: the NOT NULL guard and the inclusive
    // comparison, with the boundary value bound from the database's own clock.
    expect($sql)->toContain('`d`.`str_berlaku_sampai` is not null')
        ->and($sql)->toContain('>=');

    $bindings = [];

    foreach ($catatan as $baris) {
        $bindings = array_merge($bindings, array_map('strval', $baris['bindings']));
    }

    expect($bindings)->toContain(direktoriHariIni());
});

/*
|--------------------------------------------------------------------------
| The other three ways a doctor can be ineligible
|--------------------------------------------------------------------------
*/

test('an inactive doctor, a telemedicine opt-out and a soft-deleted account are all excluded', function (): void {
    $nonaktif = direktoriDokter(direktoriUser('Nonaktif Satu'), ['status_aktif' => false]);
    $tanpaTelemedisin = direktoriDokter(direktoriUser('Tanpa Telemedisin Satu'), ['tersedia_telemedisin' => false]);
    $layak = direktoriDokter(direktoriUser('Layak Tiga'));

    $dihapus = direktoriDokter(direktoriUser('Akun Dihapus Satu'));
    $dihapus->user->forceFill(['dihapus_at' => now()])->save();

    $ids = direktoriIds($this->getJson('/api/v1/dokter')->assertOk()->json());

    expect($ids)->toBe([(int) $layak->getKey()])
        ->and($ids)->not->toContain((int) $nonaktif->getKey())
        ->and($ids)->not->toContain((int) $tanpaTelemedisin->getKey())
        ->and($ids)->not->toContain((int) $dihapus->getKey());

    $this->getJson('/api/v1/dokter/'.$dihapus->getKey())->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| 404 shapes
|--------------------------------------------------------------------------
*/

test('absent, unverified, expired, zero and non-numeric ids all answer one 404 envelope', function (): void {
    $pending = (int) direktoriDokter(direktoriUser('Bentuk Pending'), ['status_verifikasi' => 'pending'])->getKey();
    $kedaluwarsa = (int) direktoriDokter(direktoriUser('Bentuk Kedaluwarsa'), [
        'str_berlaku_sampai' => Carbon::parse(direktoriHariIni())->subYear()->toDateString(),
    ])->getKey();

    $envelopes = [];

    foreach ([
        'absent' => '/api/v1/dokter/'.(max($pending, $kedaluwarsa) + 1000),
        'unverified' => '/api/v1/dokter/'.$pending,
        'expired' => '/api/v1/dokter/'.$kedaluwarsa,
        'zero' => '/api/v1/dokter/0',
        'non_numeric' => '/api/v1/dokter/bukan-angka',
    ] as $label => $url) {
        $response = $this->getJson($url)->assertNotFound();

        expect($response->json('success'))->toBeFalse()
            ->and($response->json('message'))->toBe('Resource not found.')
            ->and($response->json('errors'))->toBe([]);

        $envelopes[$label] = $response->json();
    }

    // One body for all five reasons. A caller that could tell "unverified" from
    // "absent" could enumerate the verification state of every doctor account
    // from an unauthenticated endpoint, which is what rule 1 protects.
    expect(array_unique(array_map('json_encode', $envelopes)))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

test('?tipe= filters on the seven-value dokter.tipe ENUM', function (): void {
    $umum = direktoriDokter(direktoriUser('Filter Umum'), ['tipe' => 'dokter_umum']);
    $gigi = direktoriDokter(direktoriUser('Filter Gigi'), ['tipe' => 'dokter_gigi']);

    expect(direktoriIds($this->getJson('/api/v1/dokter?tipe=dokter_gigi')->assertOk()->json()))
        ->toBe([(int) $gigi->getKey()])
        ->and(direktoriIds($this->getJson('/api/v1/dokter?tipe=dokter_umum')->assertOk()->json()))
        ->toBe([(int) $umum->getKey()]);
});

test('?spesialisasi= filters by master_spesialisasi kode and by id alike', function (): void {
    $idPenyakitDalam = direktoriSpesialisasi('SP.PD', 'Spesialis Penyakit Dalam');
    $idGigi = direktoriSpesialisasi('GIGI', 'Dokter Gigi');

    $dalam = direktoriDokter(direktoriUser('Filter Penyakit Dalam'));
    direktoriSambungSpesialisasi($dalam, $idPenyakitDalam, true);

    $gigi = direktoriDokter(direktoriUser('Filter Dokter Gigi Spesialis'));
    direktoriSambungSpesialisasi($gigi, $idGigi, true);

    $tanpaSpesialisasi = direktoriDokter(direktoriUser('Filter Tanpa Spesialisasi'));

    // By code - the plan's own example, `?spesialisasi=SP.PD`.
    expect(direktoriIds($this->getJson('/api/v1/dokter?spesialisasi=SP.PD')->assertOk()->json()))
        ->toBe([(int) $dalam->getKey()]);

    // By id - the plan says "kode or id", so both must work.
    expect(direktoriIds($this->getJson('/api/v1/dokter?spesialisasi='.$idGigi)->assertOk()->json()))
        ->toBe([(int) $gigi->getKey()]);

    // A doctor with no `dokter_spesialisasi` row at all is still listed
    // unfiltered - the view's LEFT JOIN (`:1181`-`:1182`) is what keeps them
    // visible - and a specialisation filter excludes them.
    $semua = direktoriIds($this->getJson('/api/v1/dokter')->assertOk()->json());

    expect($semua)->toHaveCount(3)
        ->and($semua)->toContain((int) $tanpaSpesialisasi->getKey())
        ->and(direktoriIds($this->getJson('/api/v1/dokter?spesialisasi=SP.PD')->assertOk()->json()))
        ->not->toContain((int) $tanpaSpesialisasi->getKey());
});

test('a doctor with three specialisations is listed once, not three times', function (): void {
    $dokter = direktoriDokter(direktoriUser('Tiga Spesialisasi'));

    foreach ([
        direktoriSpesialisasi('SP.A', 'Spesialis Anak'),
        direktoriSpesialisasi('SP.M', 'Spesialis Mata'),
        direktoriSpesialisasi('SP.B', 'Spesialis Bedah'),
    ] as $spesialisasiId) {
        direktoriSambungSpesialisasi($dokter, $spesialisasiId);
    }

    $response = $this->getJson('/api/v1/dokter')->assertOk();

    // A JOIN instead of EXISTS returns three rows for one doctor, inflates
    // `total` to 3 and lets one doctor occupy a whole page.
    expect($response->json('meta.total'))->toBe(1)
        ->and(direktoriIds($response->json()))->toHaveCount(1)
        ->and($response->json('data.dokter.0.spesialisasi'))->toBeString()
        ->and($response->json('data.dokter.0.spesialisasi'))->toContain('Spesialis Anak');
});

test('?search= matches users.nama_lengkap and treats % and _ literally', function (): void {
    $cocok = direktoriDokter(direktoriUser('Dokter Katarina Wijaya'));
    $tidakCocok = direktoriDokter(direktoriUser('Dokter Bambang Suryanto'));

    expect(direktoriIds($this->getJson('/api/v1/dokter?search='.urlencode('Katarina'))->assertOk()->json()))
        ->toBe([(int) $cocok->getKey()]);

    // Case-insensitive: the schema's collation is utf8mb4_unicode_ci.
    expect(direktoriIds($this->getJson('/api/v1/dokter?search='.urlencode('katarina'))->assertOk()->json()))
        ->toBe([(int) $cocok->getKey()]);

    // LIKE metacharacters are neutralised, so `%` matches a literal percent sign
    // instead of every row. An unescaped wildcard is a wrong answer rather than
    // an exploit, but it is still the wrong answer.
    $persen = direktoriDokter(direktoriUser('Dokter diskon 50 persen'));

    $response = $this->getJson('/api/v1/dokter?search='.urlencode('%'))->assertOk();

    expect(direktoriIds($response->json()))->toBe([])
        ->and($response->json('meta.total'))->toBe(0);

    $this->getJson('/api/v1/dokter?search='.urlencode('_'))->assertOk()
        ->assertJsonPath('meta.total', 0);

    expect(direktoriIds($this->getJson('/api/v1/dokter?search='.urlencode('persen'))->assertOk()->json()))
        ->toBe([(int) $persen->getKey()])
        ->and(direktoriIds($this->getJson('/api/v1/dokter?search='.urlencode('Suryanto'))->assertOk()->json()))
        ->toBe([(int) $tidakCocok->getKey()]);
});

test('?tersedia_telemedisin=1 restates the view predicate and =0 is unsatisfiable', function (): void {
    $layak = direktoriDokter(direktoriUser('Telemedisin Ya'));

    expect(direktoriIds($this->getJson('/api/v1/dokter?tersedia_telemedisin=1')->assertOk()->json()))
        ->toBe([(int) $layak->getKey()]);

    // The view already requires `d.tersedia_telemedisin = 1` (`:1185`), so no
    // such row exists to return. The filter is accepted and narrow-only; this
    // records that rather than hiding it.
    $this->getJson('/api/v1/dokter?tersedia_telemedisin=0')->assertOk()
        ->assertJsonPath('meta.total', 0);
});

test('every closed-vocabulary filter is a 422 with its own errors key', function (): void {
    // `dokter.tipe` has seven values and `spesialis` is not one of them: that
    // string belongs to `master_spesialisasi.tipe` (`:406`) and the two ENUMs
    // share only `dokter_umum`.
    $this->getJson('/api/v1/dokter?tipe=spesialis')->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['message', 'errors' => ['tipe']]);

    $this->getJson('/api/v1/dokter?tipe=dokter')->assertStatus(422)
        ->assertJsonStructure(['errors' => ['tipe']]);

    $this->getJson('/api/v1/dokter?spesialisasi=SP.TIDAK.ADA')->assertStatus(422)
        ->assertJsonStructure(['errors' => ['spesialisasi']]);

    $this->getJson('/api/v1/dokter?spesialisasi=9999')->assertStatus(422)
        ->assertJsonStructure(['errors' => ['spesialisasi']]);

    $this->getJson('/api/v1/dokter?tersedia_telemedisin=maybe')->assertStatus(422)
        ->assertJsonStructure(['errors' => ['tersedia_telemedisin']]);

    $this->getJson('/api/v1/dokter?per_page=0')->assertStatus(422)
        ->assertJsonStructure(['errors' => ['per_page']]);

    // The cap matters most here: this route has no `auth:sanctum` and no rate
    // limiter, so an uncapped `per_page` would be anonymous amplification.
    $this->getJson('/api/v1/dokter?per_page=101')->assertStatus(422)
        ->assertJsonStructure(['errors' => ['per_page']]);

    $this->getJson('/api/v1/dokter?per_page=banyak')->assertStatus(422)
        ->assertJsonStructure(['errors' => ['per_page']]);

    $this->getJson('/api/v1/dokter?page=0')->assertStatus(422)
        ->assertJsonStructure(['errors' => ['page']]);

    $this->getJson('/api/v1/dokter?search='.str_repeat('a', 151))->assertStatus(422)
        ->assertJsonStructure(['errors' => ['search']]);
});

/*
|--------------------------------------------------------------------------
| Ordering and pagination
|--------------------------------------------------------------------------
*/

test('the order is rating DESC, then consultations DESC, then dokter_id ASC', function (): void {
    $a = direktoriDokter(direktoriUser('Urutan A'), ['rating_rata_rata' => '4.50', 'jumlah_konsultasi' => 10]);
    $b = direktoriDokter(direktoriUser('Urutan B'), ['rating_rata_rata' => '4.50', 'jumlah_konsultasi' => 90]);
    $c = direktoriDokter(direktoriUser('Urutan C'), ['rating_rata_rata' => '4.90', 'jumlah_konsultasi' => 5]);
    $d = direktoriDokter(direktoriUser('Urutan D'), ['rating_rata_rata' => '4.50', 'jumlah_konsultasi' => 90]);

    // c wins on rating. b and d tie on BOTH of the plan's keys, so only the
    // `dokter_id ASC` tiebreaker separates them.
    expect(direktoriIds($this->getJson('/api/v1/dokter')->assertOk()->json()))->toBe([
        (int) $c->getKey(),
        (int) $b->getKey(),
        (int) $d->getKey(),
        (int) $a->getKey(),
    ]);

    expect($b->getKey())->toBeLessThan($d->getKey());
});

test('the emitted ORDER BY really carries the unique tiebreaker, in the plan order', function (): void {
    // **This is the assertion that pins the tiebreaker, and the behavioural tests
    // above and below do not.** Both were measured: deleting
    // `->orderBy('v_dokter_katalog.dokter_id')` leaves all 27 tests GREEN.
    //
    // The reason is a physical-order coincidence, not a weak test. `v_dokter_katalog`
    // groups on `d.id` and InnoDB answers that with the clustered-index order, so
    // the untied rows come back in ascending `d.id` anyway - exactly the order the
    // tiebreaker would have imposed. A behavioural assertion therefore cannot
    // distinguish "the ORDER BY says `dokter_id`" from "MySQL happened to agree",
    // and a future index change or a different optimiser would flip the pages
    // without turning anything red.
    //
    // So the contract is asserted on the SQL the service emits, which is the only
    // place the guarantee actually lives. The behavioural tests still earn their
    // keep: they prove the pages partition the eligible set, which is the
    // property a client depends on.
    // One eligible row, and it is load-bearing rather than incidental:
    // `Builder::paginate()` short-circuits to `newCollection()` when the count is
    // zero, so with no rows the page SELECT is **never issued** and there is no
    // ORDER BY in the log to assert on.
    direktoriDokter(direktoriUser('Urutan Sql'));

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(DokterDirectoryService::class)->list([]);

    $catatan = DB::getQueryLog();
    DB::disableQueryLog();

    $sql = mb_strtolower(implode(' ', array_column($catatan, 'query')));

    expect($sql)->toContain('order by')
        ->and($sql)->toContain('`v_dokter_katalog`.`rating_rata_rata` desc')
        ->and($sql)->toContain('`v_dokter_katalog`.`jumlah_konsultasi` desc')
        ->and($sql)->toContain('`v_dokter_katalog`.`dokter_id` asc');

    // The plan's two keys come first and the tiebreaker LAST, which is what makes
    // it a tiebreaker rather than a primary sort.
    expect(strpos($sql, 'rating_rata_rata` desc'))
        ->toBeLessThan(strpos($sql, 'jumlah_konsultasi` desc'))
        ->and(strpos($sql, 'jumlah_konsultasi` desc'))
        ->toBeLessThan(strpos($sql, 'dokter_id` asc'));
});

test('pagination partitions the eligible set: no overlap, nothing lost, nothing repeated', function (): void {
    $semua = [];

    // Five doctors with identical rating and consultation counts, so the ONLY
    // thing ordering the pages is the tiebreaker. This proves the pages partition
    // the eligible set.
    //
    // It does NOT prove the tiebreaker is present - see "the emitted ORDER BY
    // really carries the unique tiebreaker" for the measurement of why, and for the
    // assertion that does pin it.
    for ($i = 1; $i <= 5; $i++) {
        $semua[] = (int) direktoriDokter(direktoriUser('Halaman '.$i), [
            'rating_rata_rata' => '4.00',
            'jumlah_konsultasi' => 7,
        ])->getKey();
    }

    // Two ineligible rows, to prove the page math counts eligible rows only.
    direktoriDokter(direktoriUser('Tidak Layak P'), ['status_verifikasi' => 'pending']);
    direktoriDokter(direktoriUser('Tidak Layak S'), [
        'str_berlaku_sampai' => Carbon::parse(direktoriHariIni())->subDay()->toDateString(),
    ]);

    $pertama = $this->getJson('/api/v1/dokter?per_page=2&page=1')->assertOk();
    $kedua = $this->getJson('/api/v1/dokter?per_page=2&page=2')->assertOk();
    $ketiga = $this->getJson('/api/v1/dokter?per_page=2&page=3')->assertOk();

    expect($pertama->json('meta.total'))->toBe(5)
        ->and($pertama->json('meta.per_page'))->toBe(2)
        ->and($pertama->json('meta.last_page'))->toBe(3)
        ->and($pertama->json('meta.current_page'))->toBe(1)
        ->and($pertama->json('meta.from'))->toBe(1)
        ->and($pertama->json('meta.to'))->toBe(2)
        ->and($kedua->json('meta.current_page'))->toBe(2)
        ->and($kedua->json('meta.from'))->toBe(3)
        ->and($kedua->json('meta.to'))->toBe(4)
        ->and($ketiga->json('meta.current_page'))->toBe(3)
        ->and($ketiga->json('meta.from'))->toBe(5)
        ->and($ketiga->json('meta.to'))->toBe(5);

    $halaman1 = direktoriIds($pertama->json());
    $halaman2 = direktoriIds($kedua->json());
    $halaman3 = direktoriIds($ketiga->json());

    expect(array_intersect($halaman1, $halaman2))->toBe([])
        ->and(array_intersect($halaman2, $halaman3))->toBe([])
        ->and(array_intersect($halaman1, $halaman3))->toBe([]);

    $gabungan = array_merge($halaman1, $halaman2, $halaman3);

    expect($gabungan)->toHaveCount(5)
        ->and(array_unique($gabungan))->toHaveCount(5)
        ->and($gabungan)->toEqualCanonicalizing($semua);
});

test('a page past the end is an empty page, not an error and not a repeat', function (): void {
    direktoriDokter(direktoriUser('Hanya Satu'));

    $response = $this->getJson('/api/v1/dokter?per_page=15&page=9')->assertOk();

    expect($response->json('data.dokter'))->toBe([])
        ->and($response->json('meta.total'))->toBe(1)
        ->and($response->json('meta.from'))->toBeNull()
        ->and($response->json('meta.to'))->toBeNull();
});

test('per_page defaults to 15 and 100 is accepted', function (): void {
    for ($i = 1; $i <= 12; $i++) {
        direktoriDokter(direktoriUser('Bawaan '.$i));
    }

    $response = $this->getJson('/api/v1/dokter')->assertOk();

    expect(DokterDirectoryService::PER_PAGE_DEFAULT)->toBe(15)
        ->and(DokterDirectoryService::PER_PAGE_MAX)->toBe(100)
        ->and($response->json('meta.per_page'))->toBe(15)
        ->and($response->json('data.dokter'))->toHaveCount(12)
        ->and($this->getJson('/api/v1/dokter?per_page=100')->assertOk()->json('meta.per_page'))->toBe(100);
});

/*
|--------------------------------------------------------------------------
| The detail payload
|--------------------------------------------------------------------------
*/

test('the detail carries the child tables, ordered as the plan asks', function (): void {
    $idUtama = direktoriSpesialisasi('SP.PD', 'Spesialis Penyakit Dalam');
    $idLain = direktoriSpesialisasi('SP.A', 'Spesialis Anak');

    $faskes = direktoriFaskes('Klinik Uji Coba');

    $dokter = direktoriDokter(direktoriUser('Detail Lengkap'), [
        'rating_rata_rata' => '4.75',
        'jumlah_ulasan' => 42,
        'jumlah_konsultasi' => 120,
        'durasi_default_menit' => 30,
        'bio' => 'Dokter dengan pengalaman panjang.',
    ]);

    // Written in the WRONG order on purpose, so the ordering is proven rather
    // than assumed: the non-primary specialisation is inserted first.
    direktoriSambungSpesialisasi($dokter, $idLain, false);
    direktoriSambungSpesialisasi($dokter, $idUtama, true);

    // Also out of order, and the third has no year at all.
    direktoriTambahPendidikan($dokter, 's1_kedokteran', 'Universitas Indonesia', 2010);
    direktoriTambahPendidikan($dokter, 'profesi', 'Universitas Diponegoro', 2016);
    direktoriTambahPendidikan($dokter, 'sp2', 'Universitas Indonesia', null);

    DB::table('dokter_faskes')->insert([
        'dokter_id' => $dokter->getKey(),
        'faskes_id' => $faskes->getKey(),
        'is_utama' => true,
        'status_aktif' => true,
    ]);

    $body = $this->getJson('/api/v1/dokter/'.$dokter->getKey())
        ->assertOk()
        ->json('data.dokter');

    expect($body['nama_lengkap'])->toBe('Detail Lengkap')
        ->and($body['rating_rata_rata'])->toBe('4.75')
        ->and($body['jumlah_ulasan'])->toBe(42)
        ->and($body['jumlah_konsultasi'])->toBe(120)
        ->and($body['durasi_default_menit'])->toBe(30)
        ->and($body['biaya_luar_jam'])->toBe('75000.00')
        ->and($body['bio'])->toBe('Dokter dengan pengalaman panjang.')
        ->and($body['tersedia_telemedisin'])->toBeTrue()
        ->and($body['status_verifikasi'])->toBe('terverifikasi');

    // `is_utama` first, and the flagged row really is the primary one.
    expect(array_column($body['spesialisasi'], 'kode'))->toBe(['SP.PD', 'SP.A'])
        ->and($body['spesialisasi'][0]['is_utama'])->toBeTrue()
        ->and($body['spesialisasi'][0]['nama'])->toBe('Spesialis Penyakit Dalam')
        ->and($body['spesialisasi'][1]['is_utama'])->toBeFalse();

    // `tahun_lulus DESC`, and MySQL sorts the NULL last under DESC.
    expect(array_column($body['pendidikan'], 'tahun_lulus'))->toBe([2016, 2010, null])
        ->and(array_column($body['pendidikan'], 'institusi'))
        ->toBe(['Universitas Diponegoro', 'Universitas Indonesia', 'Universitas Indonesia']);

    expect($body['faskes'])->toHaveCount(1)
        ->and($body['faskes'][0]['faskes_id'])->toBe((int) $faskes->getKey())
        ->and($body['faskes'][0]['nama'])->toBe('Klinik Uji Coba')
        ->and($body['faskes'][0]['tipe'])->toBe('klinik')
        ->and($body['faskes'][0]['is_utama'])->toBeTrue()
        ->and($body['faskes'][0]['status_aktif'])->toBeTrue()
        ->and($body['faskes'][0]['alamat'])->toBe('Jl. Uji Coba No. 1, Jakarta');
});

test('the detail publishes no licence number, document URL, contact detail or STR date', function (): void {
    $user = direktoriUser('Nama rahasia');
    $user->no_telepon = '08111222333';
    $user->email = 'kontak.rahasia@example.test';
    $user->save();

    $dokter = direktoriDokter($user, [
        'nomor_str' => 'STR-RAHASIA-123',
        'nomor_sip' => 'SIP-RAHASIA-123',
        'nomor_ihs_satusehat' => 'IHS-RAHASIA-123',
        'file_str_url' => 'https://rahasia.example.test/str.pdf',
        'file_sip_url' => 'https://rahasia.example.test/sip.pdf',
    ]);

    $body = (string) $this->getJson('/api/v1/dokter/'.$dokter->getKey())->assertOk()->getContent();

    foreach ([
        'nomor_str',
        'nomor_sip',
        'nomor_ihs_satusehat',
        'file_str_url',
        'file_sip_url',
        'str_berlaku_sampai',
        'sip_berlaku_sampai',
        'STR-RAHASIA-123',
        'SIP-RAHASIA-123',
        'IHS-RAHASIA-123',
        '08111222333',
        'kontak.rahasia@example.test',
        'kata_sandi_hash',
    ] as $terlarang) {
        expect($body)->not->toContain($terlarang);
    }

    // The list reads a different source entirely and is just as clean.
    $listed = (string) $this->getJson('/api/v1/dokter')->assertOk()->getContent();

    foreach ([
        'nomor_str',
        'file_str_url',
        'file_sip_url',
        '08111222333',
        'kontak.rahasia@example.test',
    ] as $terlarang) {
        expect($listed)->not->toContain($terlarang);
    }
});

/*
|--------------------------------------------------------------------------
| The specialisation lookup endpoint
|--------------------------------------------------------------------------
*/

test('GET /master-spesialisasi lists the master rows by kode with a real count', function (): void {
    direktoriSpesialisasi('SP.PD', 'Spesialis Penyakit Dalam');
    direktoriSpesialisasi('GIGI', 'Dokter Gigi');
    direktoriSpesialisasi('UMUM', 'Dokter Umum', 'dokter_umum');

    $response = $this->getJson('/api/v1/master-spesialisasi')->assertOk();

    expect($response->json('success'))->toBeTrue()
        ->and($response->json('meta.total'))->toBe(3)
        // Ordered by `kode`, not by the per-database `id`: a code is what the
        // DDL seeds (`:1237`-`:1252`) and what a client persists.
        ->and(array_column($response->json('data.spesialisasi'), 'kode'))->toBe(['GIGI', 'SP.PD', 'UMUM'])
        ->and($response->json('data.spesialisasi.0.tipe'))->toBe('spesialis')
        ->and($response->json('data.spesialisasi.0.nama'))->toBe('Dokter Gigi');
});

/*
|--------------------------------------------------------------------------
| DDL-derived invariants, checked against the reference SQL
|--------------------------------------------------------------------------
*/

test('TIPE_DOKTER is byte-identical to the DDL seven-value ENUM, in the DDL order', function (): void {
    $tipe = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'))
        ->table('dokter')?->columns['tipe'] ?? null;

    expect($tipe)->not->toBeNull()
        ->and($tipe->type)->toBe("enum('dokter_umum','dokter_spesialis','dokter_gigi','psikolog','bidan','perawat','apoteker')")
        ->and(DokterDirectoryService::TIPE_DOKTER)->toHaveCount(7);

    $dariDdl = array_map(
        static fn (string $nilai): string => trim($nilai, "'"),
        explode(',', (string) substr((string) $tipe->type, strlen('enum('), -1)),
    );

    expect(DokterDirectoryService::TIPE_DOKTER)->toBe($dariDdl);
});

test('the DDL names every column the two eligibility rules and the projection read', function (): void {
    $spesifikasi = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
    $dokter = $spesifikasi->table('dokter');

    expect($dokter)->not->toBeNull();

    // Rule 1's column: the verification state, three values, defaulting to
    // `pending` - so a freshly inserted doctor is invisible.
    expect($dokter->columns)->toHaveKey('status_verifikasi')
        ->and($dokter->columns['status_verifikasi']->type)
        ->toBe("enum('pending','terverifikasi','ditolak')")
        ->and($dokter->columns['status_verifikasi']->default)->toBe("'pending'");

    // Rule 2's column: the STR expiry, a DATE and NOT NULL.
    expect($dokter->columns)->toHaveKey('str_berlaku_sampai')
        ->and($dokter->columns['str_berlaku_sampai']->type)->toBe('date')
        ->and($dokter->columns['str_berlaku_sampai']->nullable)->toBeFalse();

    // The two other columns the view filters on, so the "delegated, not
    // restated" claim is anchored to the DDL rather than to this file.
    expect($dokter->columns)->toHaveKey('status_aktif')
        ->and($dokter->columns)->toHaveKey('tersedia_telemedisin');

    // The columns the projection deliberately refuses to publish.
    foreach ([
        'nomor_str',
        'nomor_sip',
        'nomor_ihs_satusehat',
        'file_str_url',
        'file_sip_url',
        'jumlah_ulasan',
        'durasi_default_menit',
        'bio',
    ] as $kolom) {
        expect($dokter->columns)->toHaveKey($kolom);
    }

    // `users.dihapus_at` is the soft-delete marker the third predicate reads.
    $users = $spesifikasi->table('users');

    expect($users?->columns)->toHaveKey('dihapus_at')
        ->and($users->columns['dihapus_at']->nullable)->toBeTrue();
});

test('the live view really carries the three predicates and exactly the seven columns', function (): void {
    $baris = DB::selectOne(
        "SELECT VIEW_DEFINITION AS definisi FROM information_schema.VIEWS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'v_dokter_katalog'"
    );

    $definis = (string) $baris?->definisi;

    // Rule 1 is the VIEW's, and `str_berlaku_sampai` is nowhere in it. That
    // asymmetry is the entire reason the service adds one predicate and restates
    // none of the other three.
    //
    // `VIEW_DEFINITION` is MySQL's own re-rendering, so function names and keywords
    // come back lower-cased (`group_concat`, not `GROUP_CONCAT`) even though the DDL
    // wrote them upper-case. Comparing case-sensitively against the DDL's casing
    // would be comparing against the server's formatter, not the contract.
    expect($definis)->toContain("`status_verifikasi` = 'terverifikasi'")
        ->and($definis)->toContain('`status_aktif` = 1')
        ->and($definis)->toContain('`tersedia_telemedisin` = 1')
        ->and($definis)->not->toContain('str_berlaku_sampai')
        // The literal `SEPARATOR ', '` is load-bearing: the default separator is a
        // bare comma, so dropping the space would change every multi-specialisation
        // doctor's rendered value from `A, B` to `A,B`.
        ->and($definis)->toContain('group_concat(')
        ->and($definis)->toContain("separator ', ')")
        ->and($definis)->not->toContain("separator ',')");

    $kolom = array_map(
        static fn (object $b): string => (string) $b->COLUMN_NAME,
        DB::select(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'v_dokter_katalog'
             ORDER BY ORDINAL_POSITION"
        ),
    );

    expect($kolom)->toBe(DokterKatalog::KOLOM);
});

test('DokterKatalog reads the view through dokter_id, not a non-existent id column', function (): void {
    $dokter = direktoriDokter(direktoriUser('Kunci Asli'));

    // The plan's Oracle finding: the view aliases `d.id` to `dokter_id` (`:1172`),
    // so Eloquent's default `id` finds nothing and returns null WITHOUT error.
    //
    // `getKeyName()`, `getTable()`, `getIncrementing()`, `getKeyType()` and
    // `usesTimestamps()` are all INSTANCE methods on `Model` in this framework
    // version, so a static call is a fatal "cannot be called statically" rather
    // than a forwarded call. The assertion is on an instance for that reason.
    $proyeksi = new DokterKatalog;

    expect(DokterKatalog::query()->find($dokter->getKey()))->not->toBeNull()
        ->and($proyeksi->getKeyName())->toBe('dokter_id')
        ->and($proyeksi->getTable())->toBe('v_dokter_katalog')
        ->and($proyeksi->getIncrementing())->toBeFalse()
        ->and($proyeksi->getKeyType())->toBe('int')
        ->and($proyeksi->usesTimestamps())->toBeFalse()
        ->and(DokterKatalog::kolomTerpilih())->toBe([
            'v_dokter_katalog.dokter_id',
            'v_dokter_katalog.nama_lengkap',
            'v_dokter_katalog.tipe',
            'v_dokter_katalog.biaya_konsultasi_online',
            'v_dokter_katalog.rating_rata_rata',
            'v_dokter_katalog.jumlah_konsultasi',
            'v_dokter_katalog.spesialisasi',
        ]);

    // An unverified doctor is absent from the projection entirely, which is
    // exactly where rule 1 lives.
    $pending = direktoriDokter(direktoriUser('Kunci Asli Pending'), ['status_verifikasi' => 'pending']);

    expect(DokterKatalog::query()->find($pending->getKey()))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The route surface, and why it is ungated
|--------------------------------------------------------------------------
*/

test('the three directory routes exist and carry no auth, permission or tipe gate', function (): void {
    // `Illuminate\Routing\Route` has no `method()`; it has `methods()`, which
    // returns every verb the route answers - and a `Route::get()` registers only
    // one, so `in_array('GET', $route->methods(), true)` is the right test.
    $routes = collect(Route::getRoutes()->getRoutes())->keyBy(
        fn ($route): string => implode('|', $route->methods()).' '.$route->uri(),
    );

    foreach ([
        'GET|HEAD api/v1/dokter',
        'GET|HEAD api/v1/dokter/{dokter}',
        'GET|HEAD api/v1/master-spesialisasi',
    ] as $signature) {
        expect($routes)->toHaveKey($signature);

        foreach ($routes[$signature]->gatherMiddleware() as $middleware) {
            expect($middleware)->not->toStartWith('auth')
                ->and($middleware)->not->toStartWith('permission')
                ->and($middleware)->not->toStartWith('tipe');
        }
    }

    // A POST to a GET-only route is 405, which proves the verb is real rather
    // than a test artefact.
    $this->postJson('/api/v1/dokter')->assertStatus(405);
});

test('RbacCatalog is why a permission gate here would be wrong', function (): void {
    // The code exists...
    expect(RbacCatalog::isPermission('dokter.lihat'))->toBeTrue()
        // ...and `pasien`, the account type that actually needs a directory,
        // holds it.
        ->and(RbacCatalog::permissionsFor('pasien'))->toContain('dokter.lihat')
        ->and(RbacCatalog::isRole('pasien'))->toBeTrue();

    // Two real `users.tipe` values (`:139`) hold NO role, therefore no grant, so a
    // `permission:dokter.lihat` gate would 403 them on a public page and no data
    // change in RbacCatalog could fix that while the gate is on the route.
    foreach (['perawat', 'kurir'] as $tipe) {
        expect(RbacCatalog::isUserType($tipe))->toBeTrue()
            ->and(RbacCatalog::isRole($tipe))->toBeFalse()
            ->and(RbacCatalog::USER_TYPES)->toContain($tipe);
    }
});
