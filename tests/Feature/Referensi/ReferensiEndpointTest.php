<?php

declare(strict_types=1);

use App\Models\MasterAgama;
use App\Models\MasterGolonganDarah;
use App\Models\MasterHubunganKeluarga;
use App\Models\MasterIcd10;
use App\Models\MasterIcd9cm;
use App\Models\MasterKabupatenKota;
use App\Models\MasterKecamatan;
use App\Models\MasterKelurahan;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterPendidikan;
use App\Models\MasterProvinsi;
use App\Models\MasterSpesialisasi;
use App\Models\MasterStatusPernikahan;
use App\Support\Reference\ReferensiEndpoint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The 14 public reference endpoints
|--------------------------------------------------------------------------
|
| `GET /api/v1/referensi/{slug}` for the 13 master tables the plan's todo 42
| names, plus `GET /api/v1/referensi/enums`, which serves the generated
| `docs/enums.json`.
|
| **These are Pest closure tests, not a PHPUnit class, and that is
| load-bearing.** `tests/Pest.php` binds `RefreshDatabase` with `->in('Feature')`,
| which covers closure tests and not a plain `class FooTest` in the same
| directory. Without the trait the rows written below would survive into the
| next test. See `DokterDirectoryTest` for the same note.
|
| **No seeder runs.** `RefreshDatabase` performs one `migrate:fresh` per process
| and does not pass `--seed`, so every reference table is EMPTY and every row
| below is written by the test. That is what makes the counts exact: an endpoint
| that returned 38 provinces could not be satisfied by a leftover fixture, and
| `meta.total` is a real count rather than the DDL's seed data.
|
| Nothing here is a probe. Every test goes through real HTTP, the real
| middleware stack, the real `FormRequest`, the real controller and the real
| resources. The routes are the ones in `routes/api.php` - registered at
| bootstrap, in the same order as every other feature test in this project.
|
| ## The 13 fixture rows are written in a DELIBERATELY unsorted order
|
| `beforeEach` inserts the provinces as Aceh, Bali, ..., York in an order that is
| not alphabetical, and the assertions then require the response to be sorted.
| Inserting them pre-sorted would make the ordering assertions vacuous: a
| controller that forgot its `ORDER BY` entirely would still pass against rows
| that arrived sorted. The ordering test is only worth having if the fixture
| could catch its absence.
*/

beforeEach(function (): void {
    /*
    | Every model in this project is mass-assignment GUARDED - none of the 13
    | declares a `$fillable`, which is the right default for a schema this
    | fixed. So a fixture is written with `forceFill(...)->save()` rather than
    | `::create([...])`, which would throw "Add [kode] to fillable property".
    | `forceFill` is the right tool HERE and the wrong tool in a controller: a
    | test is writing rows the server would only ever write from a seeder, and it
    | must not be blocked by a guard that exists to stop a request from doing it.
    */
    $make = function (string $class, array $attributes) {
        $model = new $class;
        $model->forceFill($attributes);
        $model->save();

        return $model;
    };

    /*
    | The four provinces are inserted in a deliberately NON-alphabetical order:
    | Aceh, Yogyakarta, Bali, Banten. A controller with no `ORDER BY` returns
    | insertion order, so this fixture is what makes the ordering assertion in
    | `sorts on the human label` capable of failing - insert them pre-sorted and
    | that test would pass against a broken query.
    */
    foreach (['Aceh' => '11', 'Yogyakarta' => '34', 'Bali' => '51', 'Banten' => '33'] as $nama => $kode) {
        $make(MasterProvinsi::class, ['kode' => $kode, 'nama' => $nama]);
    }

    $bali = MasterProvinsi::where('kode', '51')->firstOrFail();

    // Two kabupaten/kota under Bali so the parent filter has something to choose
    // between - a filter that matched one row would pass with a broken `where`.
    $make(MasterKabupatenKota::class, ['provinsi_id' => $bali->id, 'kode' => '5101', 'nama' => 'Denpasar']);
    $make(MasterKabupatenKota::class, ['provinsi_id' => $bali->id, 'kode' => '5102', 'nama' => 'Badung']);

    $badung = MasterKabupatenKota::where('kode', '5102')->firstOrFail();

    // Kuta before Abel, so the kecamatan ordering assertion has something to fail on.
    $make(MasterKecamatan::class, ['kabupaten_kota_id' => $badung->id, 'kode' => '510201', 'nama' => 'Kuta']);
    $make(MasterKecamatan::class, ['kabupaten_kota_id' => $badung->id, 'kode' => '510202', 'nama' => 'Abel']);

    $kuta = MasterKecamatan::where('kode', '510201')->firstOrFail();

    $make(MasterKelurahan::class, ['kecamatan_id' => $kuta->id, 'kode' => '51020101', 'nama' => 'Seniren']);

    /*
    | The five `master_*` tables in 1.2 (agama, golongan_darah, pendidikan,
    | status_pernikahan, hubungan_keluarga) declare `id TINYINT UNSIGNED PRIMARY
    | KEY` with NO `AUTO_INCREMENT` - they are a CLOSED, hand-numbered set that
    | `telemedicine_test.sql` seeds explicitly at 1217-1233. So a fixture insert
    | that omits `id` dies with SQLSTATE 1364 "Field 'id' doesn't have a default
    | value". Every other master_* table the fixture touches DOES carry
    | `AUTO_INCREMENT` and is written without an id on purpose. The ids below are
    | the ones the seeder uses for these tables, so a fixture row is
    | indistinguishable from a seeded row.
    */
    $make(MasterAgama::class, ['id' => 1, 'nama' => 'Islam']);
    $make(MasterAgama::class, ['id' => 2, 'nama' => 'Kristen Protestan']);
    $make(MasterPendidikan::class, ['id' => 1, 'nama' => 'Tidak Sekolah']);
    $make(MasterPendidikan::class, ['id' => 6, 'nama' => 'Sarjana (S1)']);
    // `nama` is an ENUM, not free text: (belum_menikah, menikah, cerai_hidup, cerai_mati).
    $make(MasterStatusPernikahan::class, ['id' => 1, 'nama' => 'belum_menikah']);
    $make(MasterHubunganKeluarga::class, ['id' => 1, 'nama' => 'Pasangan']);

    // "Penyakit Dalam" before "Anak" - the reverse of alphabetical - so the
    // spesialisasi `?q=` test cannot pass on an unfiltered full list.
    $make(MasterSpesialisasi::class, ['kode' => 'S01', 'nama' => 'Penyakit Dalam', 'tipe' => 'spesialis']);
    $make(MasterSpesialisasi::class, ['kode' => 'S02', 'nama' => 'Anak', 'tipe' => 'spesialis']);

    // `master_golongan_darah` has NO `nama` column - the schema is `(id, kode)`.
    // That is the one resource shaped differently, and this row proves the endpoint
    // can serve it at all.
    // `master_golongan_darah` is in the same hand-numbered 1.2 block as
    // `master_agama` - `id TINYINT UNSIGNED PRIMARY KEY`, no AUTO_INCREMENT -
    // so the explicit ids here are required, not decorative.
    $make(MasterGolonganDarah::class, ['id' => 1, 'kode' => 'A']);
    $make(MasterGolonganDarah::class, ['id' => 4, 'kode' => 'O']);

    // `tipe` is an ENUM - (va_bank, e_wallet, qris, kartu_kredit, gerai_retail,
    // cod, tunai, bpjs, asuransi) - so a virtual account row is 'va_bank'. The
    // inactive row is what the default `status_aktif` filter has to hide.
    // `biaya_admin_persen` is `NOT NULL DEFAULT 0`, so an explicit 0, never null.
    $make(MasterMetodePembayaran::class, [
        'kode' => 'bca', 'nama' => 'BCA Virtual Account', 'tipe' => 'va_bank',
        'penyedia' => 'BCA', 'biaya_admin_flat' => 2500, 'biaya_admin_persen' => 0,
        'status_aktif' => 1,
    ]);
    $make(MasterMetodePembayaran::class, [
        'kode' => 'mandiri', 'nama' => 'Mandiri VA', 'tipe' => 'va_bank',
        'penyedia' => 'Mandiri', 'biaya_admin_flat' => 2500, 'biaya_admin_persen' => 0,
        'status_aktif' => 1,
    ]);
    $make(MasterMetodePembayaran::class, [
        'kode' => 'lama', 'nama' => 'Retired Method', 'tipe' => 'va_bank',
        'penyedia' => 'Old Bank', 'biaya_admin_flat' => 1000, 'biaya_admin_persen' => 0,
        'status_aktif' => 0,
    ]);

    $make(MasterIcd10::class, ['kode' => 'E11', 'deskripsi' => 'Diabetes mellitus tipe 2']);
    // Indonesian wording, like the seeded ICD-10 vocabulary at line 1286. The needle
    // this row has to answer is `?q=tuberkulosis`, so an English "Tuberculosis"
    // would leave the deskripsi search in `searches the ICD tables` unfalsifiable.
    $make(MasterIcd10::class, ['kode' => 'A15', 'deskripsi' => 'Tuberkulosis paru']);
    $make(MasterIcd9cm::class, ['kode' => '9300', 'deskripsi' => 'Electroencephalogram']);
    $make(MasterIcd9cm::class, ['kode' => '36400', 'deskripsi' => 'Transfusi darah']);
});

/*
|--------------------------------------------------------------------------
| The closed set of 14 routes
|--------------------------------------------------------------------------
*/

it('registers exactly the 14 reference routes the plan names', function (): void {
    $registered = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->uri(), 'api/v1/referensi'))
        ->map(fn ($route): string => 'GET '.$route->uri())
        ->sort()
        ->values()
        ->all();

    expect($registered)->toBe([
        'GET api/v1/referensi/agama',
        'GET api/v1/referensi/enums',
        'GET api/v1/referensi/golongan-darah',
        'GET api/v1/referensi/hubungan-keluarga',
        'GET api/v1/referensi/icd10',
        'GET api/v1/referensi/icd9cm',
        'GET api/v1/referensi/kabupaten-kota',
        'GET api/v1/referensi/kecamatan',
        'GET api/v1/referensi/kelurahan',
        'GET api/v1/referensi/metode-pembayaran',
        'GET api/v1/referensi/pendidikan',
        'GET api/v1/referensi/provinsi',
        'GET api/v1/referensi/spesialisasi',
        'GET api/v1/referensi/status-pernikahan',
    ]);
});

it('answers all 14 routes to an unauthenticated caller', function (string $slug): void {
    $this->getJson('/api/v1/referensi/'.$slug)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['success', 'data', 'message', 'meta']);
})->with([
    'enums', 'provinsi', 'kabupaten-kota', 'kecamatan', 'kelurahan', 'agama',
    'golongan-darah', 'pendidikan', 'status-pernikahan', 'hubungan-keluarga',
    'spesialisasi', 'metode-pembayaran', 'icd10', 'icd9cm',
]);

/*
|--------------------------------------------------------------------------
| The guards: none of the 14 is gated
|--------------------------------------------------------------------------
*/

it('puts no auth, permission or tipe middleware on any of the 14 routes', function (): void {
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with((string) $route->uri(), 'api/v1/referensi')) {
            continue;
        }

        // Laravel attaches the `api` group to every route under `apiPrefix` itself, so
        // this list is never empty - `toBe([])` would be asserting a framework detail.
        // What must be absent is a GUARD: no `auth*`, no `permission:*`, no `tipe:*`.
        // Same shape as the public `/dokter` assertion in DokterDirectoryTest.
        foreach ($route->gatherMiddleware() as $middleware) {
            expect($middleware)->not->toStartWith('auth')
                ->and($middleware)->not->toStartWith('permission')
                ->and($middleware)->not->toStartWith('tipe');
        }
    }
});

it('registers all 14 as GET, so a write is a 405 rather than a 403', function (string $slug): void {
    $this->postJson('/api/v1/referensi/'.$slug)->assertStatus(405);
    $this->putJson('/api/v1/referensi/'.$slug)->assertStatus(405);
    $this->deleteJson('/api/v1/referensi/'.$slug)->assertStatus(405);
})->with([
    'provinsi', 'kabupaten-kota', 'kecamatan', 'kelurahan', 'agama',
    'golongan-darah', 'pendidikan', 'status-pernikahan', 'hubungan-keluarga',
    'spesialisasi', 'metode-pembayaran', 'icd10', 'icd9cm',
]);

/*
|--------------------------------------------------------------------------
| The envelope and its meta block
|--------------------------------------------------------------------------
*/

it('puts meta at the top level, not inside data', function (): void {
    $body = $this->getJson('/api/v1/referensi/provinsi')->assertOk()->json();

    // Key ORDER too, because `ApiResponse` documents it as load-bearing.
    expect(array_keys($body))->toBe(['success', 'data', 'message', 'meta'])
        ->and(array_keys($body['data']))->toBe(['provinsi'])
        ->and(array_keys($body['meta']))->toBe(['current_page', 'last_page', 'per_page', 'total', 'from', 'to']);
});

it('answers a single-page list with the degenerate page block', function (): void {
    $body = $this->getJson('/api/v1/referensi/provinsi')->assertOk()->json();

    expect($body['meta'])->toBe([
        'current_page' => 1,
        'last_page' => 1,
        'per_page' => 4,
        'total' => 4,
        'from' => 1,
        'to' => 4,
    ]);
});

it('answers an empty table with null from and to rather than 0', function (): void {
    // No fixture rows for `master_agama` in this test, so the list is genuinely empty.
    MasterAgama::query()->delete();

    $body = $this->getJson('/api/v1/referensi/agama')->assertOk()->json();

    expect($body['data']['agama'])->toBe([])
        ->and($body['meta']['total'])->toBe(0)
        ->and($body['meta']['from'])->toBeNull()
        ->and($body['meta']['to'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Ordering: the property that makes pagination safe
|--------------------------------------------------------------------------
*/

it('sorts on the human label, so an absent ORDER BY is caught', function (): void {
    $body = $this->getJson('/api/v1/referensi/provinsi')->assertOk()->json();

    // `beforeEach` inserted Aceh, Yogyakarta, Bali, Banten. The response must be
    // the ALPHABETICAL order, which is a different sequence from the insertion
    // order - so a controller that dropped its `ORDER BY nama` would fail here.
    expect(array_column($body['data']['provinsi'], 'nama'))
        ->toBe(['Aceh', 'Bali', 'Banten', 'Yogyakarta']);
});

it('orders the hierarchy levels independently of insertion', function (): void {
    // Kecamatan were inserted Kuta, then Abel - deliberately the wrong order.
    $body = $this->getJson('/api/v1/referensi/kecamatan')->assertOk()->json();

    expect(array_column($body['data']['kecamatan'], 'nama'))->toBe(['Abel', 'Kuta']);
});

it('orders master_golongan_darah on kode, because it has no nama column', function (): void {
    // Inserted A, O - which is already alphabetical, so insert order cannot be
    // mistaken for a correct ORDER BY. The assertion is that BOTH appear and that
    // the endpoint works against a table with no `nama` at all.
    $body = $this->getJson('/api/v1/referensi/golongan-darah')->assertOk()->json();

    expect($body['data']['golongan_darah'])->toHaveCount(2)
        ->and(array_column($body['data']['golongan_darah'], 'kode'))->toBe(['A', 'O'])
        ->and($body['data']['golongan_darah'][0])->not->toHaveKey('nama');
});

/*
|--------------------------------------------------------------------------
| The parent filters: three levels of the administrative hierarchy
|--------------------------------------------------------------------------
*/

it('filters kabupaten/kota by its parent province', function (): void {
    $aceh = MasterProvinsi::where('kode', '11')->firstOrFail();
    $bali = MasterProvinsi::where('kode', '51')->firstOrFail();

    $this->getJson('/api/v1/referensi/kabupaten-kota?provinsi_id='.$aceh->id)
        ->assertOk()
        ->assertJsonPath('data.kabupaten_kota', []);

    $this->getJson('/api/v1/referensi/kabupaten-kota?provinsi_id='.$bali->id)
        ->assertOk()
        ->assertJsonCount(2, 'data.kabupaten_kota')
        ->assertJsonPath('meta.total', 2);
});

it('walks the whole hierarchy one level at a time', function (): void {
    $bali = MasterProvinsi::where('kode', '51')->firstOrFail();
    $badung = MasterKabupatenKota::where('kode', '5102')->firstOrFail();
    $kuta = MasterKecamatan::where('kode', '510201')->firstOrFail();

    $this->getJson('/api/v1/referensi/kecamatan?kabupaten_kota_id='.$badung->id)
        ->assertOk()
        ->assertJsonCount(2, 'data.kecamatan');

    $this->getJson('/api/v1/referensi/kelurahan?kecamatan_id='.$kuta->id)
        ->assertOk()
        ->assertJsonCount(1, 'data.kelurahan')
        ->assertJsonPath('data.kelurahan.0.nama', 'Seniren');
});

it('publishes the parent key on every hierarchy row', function (): void {
    $bali = MasterProvinsi::where('kode', '51')->firstOrFail();
    $badung = MasterKabupatenKota::where('kode', '5102')->firstOrFail();
    $kuta = MasterKecamatan::where('kode', '510201')->firstOrFail();

    $this->getJson('/api/v1/referensi/kabupaten-kota')
        ->assertJsonPath('data.kabupaten_kota.0.provinsi_id', $bali->id);

    $this->getJson('/api/v1/referensi/kecamatan')
        ->assertJsonPath('data.kecamatan.0.kabupaten_kota_id', $badung->id);

    $this->getJson('/api/v1/referensi/kelurahan')
        ->assertJsonPath('data.kelurahan.0.kecamatan_id', $kuta->id);
});

it('answers an empty list for a parent id that has no children', function (): void {
    // A 422 would be wrong: a client walking the hierarchy should be able to ask
    // for the children of a region that simply has none.
    $this->getJson('/api/v1/referensi/kabupaten-kota?provinsi_id=999999')
        ->assertOk()
        ->assertJsonPath('data.kabupaten_kota', [])
        ->assertJsonPath('meta.total', 0);
});

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

it('searches the ICD tables on both kode and deskripsi', function (): void {
    $this->getJson('/api/v1/referensi/icd10?q=tuberkulosis')
        ->assertOk()
        ->assertJsonCount(1, 'data.icd10')
        ->assertJsonPath('data.icd10.0.kode', 'A15');

    $this->getJson('/api/v1/referensi/icd10?q=E11')
        ->assertOk()
        ->assertJsonCount(1, 'data.icd10')
        ->assertJsonPath('data.icd10.0.kode', 'E11');
});

it('searches spesialisasi and metode pembayaran, and returns nothing for a miss', function (): void {
    $this->getJson('/api/v1/referensi/spesialisasi?q=anak')
        ->assertOk()
        ->assertJsonCount(1, 'data.spesialisasi')
        ->assertJsonPath('data.spesialisasi.0.kode', 'S02');

    $this->getJson('/api/v1/referensi/metode-pembayaran?q=mandiri')
        ->assertOk()
        ->assertJsonCount(1, 'data.metode_pembayaran')
        ->assertJsonPath('data.metode_pembayaran.0.kode', 'mandiri');

    $this->getJson('/api/v1/referensi/icd10?q=zzzznotacode')
        ->assertOk()
        ->assertJsonPath('data.icd10', [])
        ->assertJsonPath('meta.total', 0);
});

it('does not offer ?q= on a table with nothing to search', function (): void {
    // A silently ignored `?q=` is worse than a 422: a client that filtered and got
    // the full list back has no way to tell its filter did nothing.
    $this->getJson('/api/v1/referensi/provinsi?q=aceh')
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['success', 'message', 'errors' => ['q']]);
});

/*
|--------------------------------------------------------------------------
| The status_aktif filter: the one endpoint with a soft-off switch
|--------------------------------------------------------------------------
*/

it('hides an inactive payment method by default and shows it on request', function (): void {
    $this->getJson('/api/v1/referensi/metode-pembayaran')
        ->assertOk()
        ->assertJsonCount(2, 'data.metode_pembayaran')
        ->assertJsonPath('meta.total', 2);

    $codes = array_column($this->getJson('/api/v1/referensi/metode-pembayaran')->json('data.metode_pembayaran'), 'kode');
    expect($codes)->not->toContain('lama');

    $this->getJson('/api/v1/referensi/metode-pembayaran?status_aktif=0')
        ->assertOk()
        ->assertJsonCount(1, 'data.metode_pembayaran')
        ->assertJsonPath('data.metode_pembayaran.0.kode', 'lama')
        ->assertJsonPath('data.metode_pembayaran.0.status_aktif', false);
});

it('publishes the fee columns a booking screen needs', function (): void {
    $this->getJson('/api/v1/referensi/metode-pembayaran')
        ->assertOk()
        // A NUMBER, not the `DECIMAL(12,2)` string the driver hands back - the
        // resource casts it, so a client can add it to a total without parsing.
        // Asserted as int because `json_encode(2500.0)` emits `2500`: a whole
        // float has no fractional part to print, and it decodes back as int.
        ->assertJsonPath('data.metode_pembayaran.0.biaya_admin_flat', 2500)
        // `va_bank` is the ENUM member a virtual account row carries; there is no
        // `transfer` member in `master_metode_pembayaran.tipe`.
        ->assertJsonPath('data.metode_pembayaran.0.tipe', 'va_bank');
});

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

it('pages the endpoints that declare it, with a real page meta block', function (): void {
    $body = $this->getJson('/api/v1/referensi/icd9cm?per_page=1&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data.icd9cm')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.total', 2)
        ->json();

    // Page 2 of 2 with per_page 1 over codes 9300 and 36400 sorted ascending.
    expect($body['data']['icd9cm'][0]['kode'])->toBe('9300');
});

it('rejects a per_page above the cap with a 422 naming the field', function (): void {
    $this->getJson('/api/v1/referensi/icd10?per_page=101')
        ->assertStatus(422)
        ->assertJsonStructure(['success', 'message', 'errors' => ['per_page']]);

    $this->getJson('/api/v1/referensi/icd10?per_page=0')
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['per_page']]);

    $this->getJson('/api/v1/referensi/icd10?page=abc')
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['page']]);
});

it('does not page the endpoints that do not declare it', function (): void {
    $body = $this->getJson('/api/v1/referensi/provinsi?page=2&per_page=1')
        ->assertOk()
        ->assertJsonCount(4, 'data.provinsi')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.total', 4)
        ->json();

    // A client with a generic list component sends `page` to every list it
    // renders; ignoring it is deliberate, and the single-page block makes the
    // ignoring visible rather than a silent empty page.
    expect($body['data']['provinsi'])->toHaveCount(4);
});

/*
|--------------------------------------------------------------------------
| Rejecting unknown parameters
|--------------------------------------------------------------------------
*/

it('rejects a parameter the endpoint does not accept', function (): void {
    $this->getJson('/api/v1/referensi/provinsi?kode=31')
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['errors' => ['kode']]);

    // And a misspelled filter on a paginated endpoint, which is the case a client
    // would otherwise render as "filtered" when it was not.
    $this->getJson('/api/v1/referensi/icd10?search=diabetes')
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['search']]);
});

/*
|--------------------------------------------------------------------------
| /referensi/enums serves the committed artefact
|--------------------------------------------------------------------------
*/

it('serves the generated catalogue, and it is the committed file', function (): void {
    $body = $this->getJson('/api/v1/referensi/enums')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json();

    $onDisk = json_decode((string) file_get_contents(base_path('docs/enums.json')), true);

    expect($body['data']['enums'])->toBe($onDisk)
        ->and($body['meta']['total'])->toBe(count($onDisk))
        ->and($body['meta'])->toHaveKey('current_page');
});

it('serves a catalogue whose keys are table.column pairs', function (): void {
    $enums = $this->getJson('/api/v1/referensi/enums')->assertOk()->json('data.enums');

    expect($enums)->toBeArray()->not->toBeEmpty()
        ->and(array_keys($enums))->toContain('dokter.tipe', 'users.tipe', 'artikel.status');

    foreach ($enums as $column => $values) {
        expect($column)->toMatch('/^[a-z0-9_]+\.[a-z0-9_]+$/')
            ->and($values)->toBeArray()->not->toBeEmpty();
    }
});

it('excludes the one VIEW-derived ENUM column and says so', function (): void {
    $enums = $this->getJson('/api/v1/referensi/enums')->assertOk()->json('data.enums');

    // `v_dokter_katalog.tipe` is a projection of `dokter.tipe`, so the catalogue
    // holds the base column and not the view - a generated client must not emit a
    // `VDokterKatalogTipe` class duplicating `DokterTipe`.
    expect(array_keys($enums))->not->toContain('v_dokter_katalog.tipe')
        ->and(array_keys($enums))->toContain('dokter.tipe');
});

it('agrees with the live schema, so a client is never handed a stale catalogue', function (): void {
    $this->artisan('sehatly:enums', ['--check' => true])->assertExitCode(0);
});

/*
|--------------------------------------------------------------------------
| The definition and the route table cannot drift
|--------------------------------------------------------------------------
*/

it('keeps the route set and the definition the same set', function (): void {
    $defined = collect(ReferensiEndpoint::all())->map(fn ($e): string => 'api/v1/referensi/'.$e->slug)->sort()->values();
    $routed = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => (string) $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'api/v1/referensi'))
        ->reject(fn (string $uri): bool => str_ends_with($uri, '/enums'))
        ->sort()
        ->values();

    expect($routed->all())->toBe($defined->all());
});

it('gives every endpoint a unique data key derived from its slug', function (): void {
    $keys = collect(ReferensiEndpoint::all())->map(fn ($e): string => $e->dataKey());

    expect($keys->count())->toBe($keys->unique()->count())
        ->and($keys->all())->toContain('kabupaten_kota', 'golongan_darah', 'status_pernikahan', 'icd9cm');
});

it('declares a total order on every endpoint, which is what makes paging safe', function (): void {
    foreach (ReferensiEndpoint::all() as $endpoint) {
        expect($endpoint->orders)->not->toBeEmpty();
    }
});

it('gives every endpoint a model whose table really has its order columns', function (): void {
    foreach (ReferensiEndpoint::all() as $endpoint) {
        $table = (new $endpoint->model)->getTable();

        foreach ($endpoint->orders as $column) {
            expect(Schema::hasColumn($table, $column))
                ->toBeTrue("{$endpoint->slug} orders on {$table}.{$column}, which does not exist");
        }
    }
});
