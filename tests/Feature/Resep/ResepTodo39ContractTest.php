<?php

declare(strict_types=1);

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureUserType;
use App\Services\Obat\ObatSearchService;
use App\Support\Rbac\RbacCatalog;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Todo 39 part 4 - the contracts the other three files leave implicit
|--------------------------------------------------------------------------
|
| Every test here pins a decision the acceptance criteria state and that the
| first three files exercise only by accident of their fixtures.
|
| The CONTROL shape matters in this file: a test that asserts "no warning"
| proves nothing unless a paired test in the same run proves the warning
| machinery was live. Two of the tests below run that pairing deliberately.
*/

require_once __DIR__.'/resep-helpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    Carbon::setTestNow(rx39Jam());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// =====================================================================
// 1. `requires_resep` is a BOOLEAN filter, and "false" is not true
// =====================================================================

test('requires_resep reads the five truthy spellings as true and the falsey as false', function (): void {
    // Two rows differing ONLY in `requires_resep` (`:720`), so a filter that
    // inverts returns the wrong row rather than merely the wrong count.
    $denganResep = rx39Obat('Amoxicillin', ['requires_resep' => 1]);
    $tanpaResep = rx39Obat('Metformin', ['requires_resep' => 0]);

    $dokter = rx39As(rx39DoctorAccount()['user']);

    // `1` and `true` are the ask. `0` and `false` are the refusal, and
    // `false` is the one a `(bool)` cast gets wrong: the STRING "false" is a
    // non-empty string, so `(bool) "false"` is TRUE and the filter would
    // return the prescription-only drug to a caller who asked to exclude it.
    foreach (['1', 'true'] as $benar) {
        $this->withHeaders($dokter)
            ->getJson('/api/v1/obat?requires_resep='.$benar)
            ->assertOk()
            ->assertJsonCount(1, 'data.obat')
            ->assertJsonPath('data.obat.0.id', $denganResep)
            ->assertJsonPath('data.obat.0.requires_resep', true);
    }

    foreach (['0', 'false'] as $salah) {
        $this->withHeaders($dokter)
            ->getJson('/api/v1/obat?requires_resep='.$salah)
            ->assertOk()
            ->assertJsonCount(1, 'data.obat')
            ->assertJsonPath('data.obat.0.id', $tanpaResep)
            ->assertJsonPath('data.obat.0.requires_resep', false);
    }

    // An ABSENT filter is not `false`: it must not narrow the catalogue.
    $this->withHeaders($dokter)
        ->getJson('/api/v1/obat')
        ->assertOk()
        ->assertJsonCount(2, 'data.obat')
        ->assertJsonPath('meta.total', 2);
});

test('the search service reads a boolean and an int, and never a raw string', function (): void {
    $denganResep = rx39Obat('Amoxicillin', ['requires_resep' => 1]);
    rx39Obat('Metformin', ['requires_resep' => 0]);

    $tanpaResep = (int) DB::table('master_obat')->where('requires_resep', 0)->value('id');

    $svc = app(ObatSearchService::class);
    $pakai = static fn (mixed $v): array => [
        'search' => null, 'kelas_obat' => null, 'requires_resep' => $v,
        'page' => 1, 'per_page' => 15,
    ];

    // The service is public API, so it defends itself rather than trusting its
    // caller to have normalised. `false` and `0` must agree, and the string
    // "false" must not be read as true.
    foreach ([false, 0, '0', 'false'] as $salah) {
        expect($svc->cari($pakai($salah))->getCollection()->pluck('id')->all())
            ->toBe([$tanpaResep]);
    }

    foreach ([true, 1, '1', 'true'] as $benar) {
        expect($svc->cari($pakai($benar))->getCollection()->pluck('id')->all())
            ->toBe([$denganResep]);
    }

    expect($svc->cari($pakai(null))->total())->toBe(2);
});

// =====================================================================
// 2. `is_racikan` is a DECLARATION, and a declaration that is ignored is a lie
// =====================================================================

test('is_racikan is checked against the item shape instead of being silently discarded', function (): void {
    $amox = rx39Obat('Amoxicillin');
    $akun = rx39DoctorAccount();
    $path = '/api/v1/konsultasi/'.$akun['sesi']->getKey().'/resep';

    // A catalogue drug cannot also be a racikan. The two shapes are mutually
    // exclusive by construction - `obat_id` NULL *means* racikan (`:770`) - so
    // accepting both claims at once would store `obat_id = <id>` with
    // `is_racikan = 0` and hand the caller a 201 that quietly disagreed with
    // what it asked for.
    $this->withHeaders(rx39As($akun['user']))
        ->postJson($path, ['items' => [[
            'obat_id' => $amox,
            'aturan_pakai' => '3 x 1 tablet',
            'jumlah' => 10,
            'satuan' => 'tablet',
            'is_racikan' => true,
        ]]])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath(
            'errors.items.0.is_racikan.0',
            'Obat katalog bukan racikan; racikan memakai nama_obat tanpa obat_id.',
        );

    // And a racikan that denies being one is refused the same way.
    $this->withHeaders(rx39As($akun['user']))
        ->postJson($path, ['items' => [[
            'nama_obat' => 'Racikan Demam Herbal',
            'aturan_pakai' => '1 x 1 sachet',
            'jumlah' => 1,
            'is_racikan' => false,
        ]]])
        ->assertStatus(422)
        ->assertJsonPath(
            'errors.items.0.is_racikan.0',
            'Racikan wajib dinyatakan is_racikan: true.',
        );

    // Both refusals are collected, not raised one at a time: the second item
    // declares neither shape, and a doctor fixing a screen fixes both in one
    // pass. Every shape complaint is filed on the field that failed to
    // express the shape, so the envelope's `errors` map is directly fillable.
    $respons = $this->withHeaders(rx39As($akun['user']))
        ->postJson($path, ['items' => [[
            'obat_id' => $amox, 'aturan_pakai' => '3 x 1', 'jumlah' => 10, 'satuan' => 'tablet',
        ], [
            'nama_obat' => 'Racikan Demam Herbal', 'aturan_pakai' => '1 x 1', 'jumlah' => 1,
        ]]])
        ->assertStatus(422);

    expect($respons->json('errors.items.1.is_racikan.0'))
        ->toBe('Item resep wajib menunjuk obat katalog atau berisi racikan.')
        ->and(DB::table('resep')->count())->toBe(0)
        ->and(DB::table('resep_item')->count())->toBe(0);

    // The declared shape still works in both directions, so the rule is a
    // consistency check and not a new restriction.
    $this->withHeaders(rx39As($akun['user']))
        ->postJson($path, ['items' => [[
            'obat_id' => $amox, 'aturan_pakai' => '3 x 1', 'jumlah' => 10, 'satuan' => 'tablet',
            'is_racikan' => false,
        ], [
            'nama_obat' => 'Racikan Demam Herbal', 'aturan_pakai' => '1 x 1 sachet', 'jumlah' => 1,
            'is_racikan' => true, 'racikan_nama' => 'Racikan Demam Herbal', 'satuan' => 'sachet',
        ]]])
        ->assertCreated();
});

// =====================================================================
// 3. A racikan is not paired for interactions - proven against a live pairing
// =====================================================================

test('CONTROL then racikan: a live pair warns, and the same drug as a racikan does not', function (): void {
    [$amox, $metformin] = rx39PasanganKontraindikasi();

    // CONTROL, on its own patient: two CATALOGUED items of a stored
    // `kontraindikasi` pair. This is the proof that the pairing machinery is
    // live in this run - without it, the racikan half below would assert
    // nothing at all, because an empty warning set is what a broken engine
    // also returns.
    $kontrol = rx39DoctorAccount();

    $responsKontrol = $this->withHeaders(rx39As($kontrol['user']))
        ->postJson('/api/v1/konsultasi/'.$kontrol['sesi']->getKey().'/resep', [
            'catatan_dodio' => 'Dipertimbangkan atas permintaan pasien.',
            'items' => [
                ['obat_id' => $amox, 'aturan_pakai' => '3 x 1', 'jumlah' => 10, 'satuan' => 'tablet'],
                ['obat_id' => $metformin, 'aturan_pakai' => '1 x 1', 'jumlah' => 20, 'satuan' => 'tablet'],
            ],
        ])
        ->assertCreated();

    expect($responsKontrol->json('data.warning'))->toHaveCount(1)
        ->and($responsKontrol->json('data.warning.0.tingkat'))->toBe('kontraindikasi')
        ->and($responsKontrol->json('data.warning.0.sumber'))->toBe('antar_item');

    // The racikan half, on a FRESH patient so the control prescription above
    // is not itself a `riwayat_resep` clash. The racikan is NAMED after the
    // very drug the pair names, and still contributes no warning: `obat_id` is
    // NULL (`:770`), so it has no id for `obat_interaksi` (`:733`-`:734`) to
    // be keyed on and never reaches pair construction.
    $racikan = rx39DoctorAccount();

    $respons = $this->withHeaders(rx39As($racikan['user']))
        ->postJson('/api/v1/konsultasi/'.$racikan['sesi']->getKey().'/resep', [
            'items' => [[
                'obat_id' => $amox, 'aturan_pakai' => '3 x 1', 'jumlah' => 10, 'satuan' => 'tablet',
            ], [
                'nama_obat' => 'Metformin',
                'kekuatan' => '500 mg',
                'aturan_pakai' => '1 x 1 tablet',
                'jumlah' => 20,
                'is_racikan' => true,
                'racikan_nama' => 'Campuranhipoglikemik',
                'satuan' => 'tablet',
            ]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.warning', [])
        ->assertJsonPath('data.acknowledgement.jumlah_peringatan', 0)
        ->assertJsonPath('data.acknowledgement.diminta', false);

    $items = collect($respons->json('data.resep.items'));
    $baris = $items->firstWhere('is_racikan', true);

    expect($baris['obat_id'])->toBeNull()
        ->and($baris['nama_obat'])->toBe('Metformin')
        ->and($baris['racikan_nama'])->toBe('Campuranhipoglikemik')
        ->and($baris['harga_satuan'])->toBe('0.00');
});

// =====================================================================
// 4. The catalogue resource publishes the DDL's columns, allow-listed
// =====================================================================

test('MasterObatResource publishes the DDL columns less the two timestamps, and nothing else', function (): void {
    rx39Obat('Amoxicillin', ['nama_brand' => 'Amoxsan']);

    $respons = $this->withHeaders(rx39As(rx39DoctorAccount()['user']))
        ->getJson('/api/v1/obat?search=amoxicillin')
        ->assertOk();

    $terbit = array_keys((array) $respons->json('data.obat.0'));
    $dariDdl = array_keys(rx39Spec()->table('master_obat')->columns);
    $harus = array_values(array_diff($dariDdl, ['dibuat_at', 'diubah_at']));

    // Allow-list, so a column added to `master_obat` later does not reach the
    // wire by accident - and the omission of the two TIMESTAMP columns is a
    // decision, asserted as one rather than left to a comment that can drift.
    expect($terbit)->toEqualCanonicalizing($harus)
        ->and($terbit)->toHaveCount(16)
        ->and($terbit)->not->toContain('dibuat_at')
        ->and($terbit)->not->toContain('diubah_at');

    // And the plan names six of them by hand for this response; each is here.
    foreach (['kelas_terapi', 'kelas_obat', 'requires_resep', 'aturan_pakai_umum', 'kontraindikasi', 'harga_jual'] as $kolom) {
        expect($terbit)->toContain($kolom);
    }
});

// =====================================================================
// 5. Every guard, justified by the answer it gives
// =====================================================================

test('every account type but dokter is refused both routes with the 403 envelope', function (): void {
    $obat = rx39Obat('Amoxicillin');
    $sahabat = rx39DoctorAccount();

    // `apoteker`, `admin` and `superadmin` get their real role, so a refusal
    // cannot be explained by a missing grant: `superadmin` holds BOTH codes
    // (`RbacCatalog::ROLE_PERMISSIONS`) and is still refused, which is what
    // makes `tipe:dokter` load-bearing rather than a restatement of the
    // permission gate. `pasien`, `perawat` and `kurir` hold no role at all.
    foreach (['pasien' => null, 'perawat' => null, 'apoteker' => 'apoteker', 'kurir' => null, 'admin' => 'admin', 'superadmin' => 'superadmin'] as $tipe => $peran) {
        $orang = rx39Pengguna($tipe, $peran);

        foreach (['GET|obat' => '/api/v1/obat', 'POST|resep' => '/api/v1/konsultasi/'.$sahabat['sesi']->getKey().'/resep'] as $label => $url) {
            $panggil = str_starts_with($label, 'GET')
                ? $this->withHeaders(rx39As($orang))->getJson($url)
                : $this->withHeaders(rx39As($orang))->postJson($url, rx39Body($obat));

            $panggil->assertStatus(403)
                ->assertExactJson([
                    'success' => false,
                    'message' => 'This action is unauthorized.',
                    'errors' => [],
                ]);
        }
    }

    expect(DB::table('resep')->count())->toBe(0);
});

test('the permission guard is not a restatement of the type guard', function (): void {
    $obat = rx39Obat('Amoxicillin');

    // A `dokter`-typed account with NO role clears `tipe:dokter` and is
    // stopped by `permission:resep.buat`. If the permission guard were
    // redundant this would be a 201, and dropping it would change nothing.
    $tanpaPeran = rx39Pengguna('dokter');

    $this->withHeaders(rx39As($tanpaPeran))
        ->postJson('/api/v1/konsultasi/1/resep', rx39Body($obat))
        ->assertStatus(403);

    $this->withHeaders(rx39As($tanpaPeran))
        ->getJson('/api/v1/obat?search=amoxicillin')
        ->assertStatus(403);

    expect(RbacCatalog::permissionsFor('dokter'))->toContain('obat.cari', 'resep.buat')
        ->and(RbacCatalog::permissionsFor('apoteker'))->not->toContain('obat.cari')
        ->and(RbacCatalog::permissionsFor('apoteker'))->not->toContain('resep.buat')
        ->and(RbacCatalog::permissionsFor('superadmin'))->toContain('obat.cari', 'resep.buat')
        ->and(DB::table('resep')->count())->toBe(0);
});

test('an unknown code is a LogicException from both guards, never a 403', function (): void {
    $request = Request::create('/api/v1/obat');
    $req = $request;
    $req->setUserResolver(static fn () => rx39Pengguna('dokter', 'dokter'));

    $lewati = static fn (Request $r) => new Response;

    // `obat.create` is the English-verb mistake the catalogue's own docblock
    // warns about; `resep.mulai` is a plausible typo of a real code. Both
    // must THROW, because a 403 here would deny every caller forever with a
    // body blaming their token.
    foreach (['obat.create', 'resep.mulai'] as $kode) {
        expect(RbacCatalog::isPermission($kode))->toBeFalse();

        try {
            (new EnsurePermission)->handle($req, $lewati, $kode);
            expect(false)->toBeTrue("permission:{$kode} must not be answered with a response");
        } catch (LogicException $e) {
            expect($e->getMessage())->toContain($kode);
        }
    }

    try {
        (new EnsurePermission)->handle($req, $lewati);
        expect(false)->toBeTrue('a code-less permission: must not be answered with a response');
    } catch (LogicException) {
        expect(true)->toBeTrue();
    }

    try {
        (new EnsureUserType)->handle($req, $lewati, 'doktor');
        expect(false)->toBeTrue('tipe:doktor must not be answered with a response');
    } catch (LogicException $e) {
        expect($e->getMessage())->toContain('doktor');
    }

    // The two codes todo 39 wires both resolve, so neither route can 500.
    expect(RbacCatalog::isPermission('obat.cari'))->toBeTrue()
        ->and(RbacCatalog::isPermission('resep.buat'))->toBeTrue();
});
