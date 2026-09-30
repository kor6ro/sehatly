<?php

declare(strict_types=1);

use App\Models\Resep;
use App\Services\Obat\ObatInteraksiService;
use App\Services\Resep\ResepService;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Todo 39 part 3 - warn-but-create, acknowledgement, items, expiry, control
|--------------------------------------------------------------------------
*/

require_once __DIR__.'/resep-helpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    Carbon::setTestNow(rx39Jam());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('a kontraindikasi pair still produces a 201 with a populated warning payload', function (): void {
    [$amox, $metformin] = rx39PasanganKontraindikasi();
    $akun = rx39DoctorAccount();

    $respons = $this->withHeaders(rx39As($akun['user']))->postJson('/api/v1/konsultasi/'.$akun['sesi']->getKey().'/resep', [
        'catatan_dodio' => 'Pasien memerlukan kedua obat; dosis metformin diturunkan.',
        'items' => [
            ['obat_id' => $amox, 'aturan_pakai' => '3 x 1 tablet', 'jumlah' => 10, 'satuan' => 'tablet'],
            ['obat_id' => $metformin, 'aturan_pakai' => '1 x 1 tablet', 'jumlah' => 20, 'satuan' => 'tablet'],
        ],
    ]);

    $respons->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.resep.status', 'aktif');

    $respons->assertJsonCount(1, 'data.warning')
        ->assertJsonPath('data.warning.0.sumber', 'antar_item')
        ->assertJsonPath('data.warning.0.tingkat', 'kontraindikasi')
        ->assertJsonPath('data.warning.0.wajib_catatan_dokter', true)
        ->assertJsonPath('data.warning.0.deskripsi', 'Kombinasi tidak direkomendasikan.')
        ->assertJsonPath('data.warning.0.obat_a.id', min($amox, $metformin))
        ->assertJsonPath('data.warning.0.obat_b.id', max($amox, $metformin));

    $respons->assertJsonPath('data.warning_grup.antar_item', fn ($v): bool => count($v) === 1)
        ->assertJsonPath('data.warning_grup.riwayat_resep', [])
        ->assertJsonPath('data.warning_grup.alergi', []);
    expect(array_keys($respons->json('data.warning_grup')))->toBe(ObatInteraksiService::SUMBER);

    expect(DB::table('resep_item')->where('resep_id', $respons->json('data.resep.id'))->count())->toBe(2)
        ->and($respons->json('data.resep.items'))->toHaveCount(2);
});

test('a berat pair without a note is a 201 and still carries the warning', function (): void {
    [$amox, $metformin] = rx39PasanganKontraindikasi();
    DB::table('obat_interaksi')->update(['tingkat' => 'berat']);

    $akun = rx39DoctorAccount();

    $this->withHeaders(rx39As($akun['user']))->postJson('/api/v1/konsultasi/'.$akun['sesi']->getKey().'/resep', [
        'items' => [
            ['obat_id' => $amox, 'aturan_pakai' => '3 x 1 tablet', 'jumlah' => 10, 'satuan' => 'tablet'],
            ['obat_id' => $metformin, 'aturan_pakai' => '1 x 1 tablet', 'jumlah' => 20, 'satuan' => 'tablet'],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.warning.0.tingkat', 'berat')
        ->assertJsonPath('data.warning.0.wajib_catatan_dokter', false)
        ->assertJsonPath('data.acknowledgement.diminta', false)
        ->assertJsonPath('data.acknowledgement.catatan_dodio', null)
        ->assertJsonCount(1, 'data.warning');
});

test('acknowledged with a note: the pair is written, the note recorded, the warning stays', function (): void {
    [$amox, $metformin] = rx39PasanganKontraindikasi();
    $akun = rx39DoctorAccount();
    $catatan = 'Manfaat lebih besar daripada risiko; pasien diawasi ketat.';

    $respons = $this->withHeaders(rx39As($akun['user']))->postJson('/api/v1/konsultasi/'.$akun['sesi']->getKey().'/resep', [
        'catatan_dodio' => $catatan,
        'items' => [
            ['obat_id' => $amox, 'aturan_pakai' => '3 x 1 tablet', 'jumlah' => 10, 'satuan' => 'tablet'],
            ['obat_id' => $metformin, 'aturan_pakai' => '1 x 1 tablet', 'jumlah' => 20, 'satuan' => 'tablet'],
        ],
    ]);

    $respons->assertCreated()
        ->assertJsonPath('data.acknowledgement.diminta', true)
        ->assertJsonPath('data.acknowledgement.catatan_dodio', $catatan)
        ->assertJsonPath('data.acknowledgement.jumlah_peringatan', 1);

    $tersimpan = Resep::query()->findOrFail($respons->json('data.resep.id'));
    expect((string) $tersimpan->catatan_dokter)->toBe($catatan)
        ->and($respons->json('data.resep.catatan_dokter'))->toBe($catatan);

    $respons->assertJsonCount(1, 'data.warning')
        ->assertJsonPath('data.warning.0.tingkat', 'kontraindikasi');

    $this->withHeaders(rx39As($akun['user']))->postJson('/api/v1/konsultasi/'.$akun['sesi']->getKey().'/resep', [
        'catatan_dokter' => 'Dilewati tanpa pengakuan.',
        'items' => [['obat_id' => $amox, 'aturan_pakai' => '3 x 1 tablet', 'jumlah' => 10, 'satuan' => 'tablet']],
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.catatan_dokter.0', 'The catatan dokter field is prohibited.');
});

test('refused without: a kontraindikasi pair with no note is a 422 and writes nothing', function (): void {
    [$amox, $metformin] = rx39PasanganKontraindikasi();
    $akun = rx39DoctorAccount();
    $path = '/api/v1/konsultasi/'.$akun['sesi']->getKey().'/resep';
    $items = [
        ['obat_id' => $amox, 'aturan_pakai' => '3 x 1 tablet', 'jumlah' => 10, 'satuan' => 'tablet'],
        ['obat_id' => $metformin, 'aturan_pakai' => '1 x 1 tablet', 'jumlah' => 20, 'satuan' => 'tablet'],
    ];

    foreach ([[], ['catatan_dodio' => null], ['catatan_dodio' => ''], ['catatan_dodio' => '   ']] as $tambahan) {
        $this->withHeaders(rx39As($akun['user']))->postJson($path, array_merge(['items' => $items], $tambahan))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors' => ['catatan_dodio']]);
    }

    expect(DB::table('resep')->count())->toBe(0)
        ->and(DB::table('resep_item')->count())->toBe(0);

    DB::table('obat_interaksi')->update(['tingkat' => 'berat']);

    $this->withHeaders(rx39As($akun['user']))->postJson($path, ['items' => $items])
        ->assertCreated()
        ->assertJsonPath('data.acknowledgement.diminta', false);
});

test('one blank note carries two messages on the same field', function (): void {
    [$amox, $metformin] = rx39PasanganKontraindikasi();
    $akun = rx39DoctorAccount();

    $respons = $this->withHeaders(rx39As($akun['user']))->postJson('/api/v1/konsultasi/'.$akun['sesi']->getKey().'/resep', [
        'catatan_dodio' => '   ',
        'items' => [
            ['obat_id' => $amox, 'aturan_pakai' => '3 x 1 tablet', 'jumlah' => 10, 'satuan' => 'tablet'],
            ['obat_id' => $metformin, 'aturan_pakai' => '1 x 1 tablet', 'jumlah' => 20, 'satuan' => 'tablet'],
        ],
    ]);

    $respons->assertStatus(422);

    $pesan = $respons->json('errors.catatan_dodio');

    expect($pesan)->toBeArray()
        ->and($pesan)->toHaveCount(2)
        ->and($respons->json('message'))->toBe('The given data was invalid.')
        ->and(array_keys($respons->json()))->toBe(['success', 'message', 'errors']);
});

test('an anafilaksis allergy also demands the note', function (): void {
    $amox = rx39Obat('Amoxicillin', ['kelas_terapi' => 'Antibiotik']);
    $akun = rx39DoctorAccount();
    rx39Alergi($akun['pasien'], 'Amoxicillin 500mg', 'anafilaksis');

    $items = [['obat_id' => $amox, 'aturan_pakai' => '3 x 1 tablet', 'jumlah' => 10, 'satuan' => 'tablet']];

    $this->withHeaders(rx39As($akun['user']))->postJson('/api/v1/konsultasi/'.$akun['sesi']->getKey().'/resep', ['items' => $items])
        ->assertStatus(422)
        ->assertJsonPath('errors.catatan_dodio.0', 'Catatan pengakuan wajib diisi.')
        ->assertJsonPath('errors.catatan_dodio.1', 'Peringatan kontraindikasi memerlukan catatan dokter.');

    $this->withHeaders(rx39As($akun['user']))->postJson('/api/v1/konsultasi/'.$akun['sesi']->getKey().'/resep', [
        'catatan_dodio' => 'Alergi tercatat; manfaat dinilai lebih besar.',
        'items' => $items,
    ])
        ->assertCreated()
        ->assertJsonPath('data.warning.0.sumber', 'alergi')
        ->assertJsonPath('data.warning.0.tingkat', 'kontraindikasi')
        ->assertJsonPath('data.acknowledgement.diminta', true);
});

test('a racikan item is stored with obat_id null and yields no interaction warning', function (): void {
    $amox = rx39Obat('Amoxicillin', ['kelas_terapi' => 'Antibiotik']);
    $akun = rx39DoctorAccount();

    $respons = $this->withHeaders(rx39As($akun['user']))->postJson('/api/v1/konsultasi/'.$akun['sesi']->getKey().'/resep', [
        'items' => [[
            'nama_obat' => 'Racikan Demam Herbal',
            'kekuatan' => 'No. 10',
            'aturan_pakai' => '1 x 1 sachet',
            'jumlah' => 1,
            'is_racikan' => true,
            'racikan_nama' => 'Racikan Demam Herbal',
            'satuan' => 'sachet',
        ], [
            'obat_id' => $amox,
            'aturan_pakai' => '3 x 1 tablet',
            'jumlah' => 10,
            'satuan' => 'tablet',
        ]],
    ]);

    $respons->assertCreated()
        ->assertJsonPath('data.warning', [])
        ->assertJsonPath('data.acknowledgement.jumlah_peringatan', 0)
        ->assertJsonPath('data.acknowledgement.diminta', false);

    $items = collect($respons->json('data.resep.items'));
    $racikan = $items->firstWhere('is_racikan', true);

    expect($racikan)->not->toBeNull()
        ->and($racikan['obat_id'])->toBeNull()
        ->and($racikan['nama_obat'])->toBe('Racikan Demam Herbal')
        ->and($racikan['harga_satuan'])->toBe('0.00')
        ->and($racikan['subtotal'])->toBe('0.00');

    $tersimpan = DB::table('resep_item')->where('id', $racikan['id'])->first();
    expect($tersimpan->obat_id)->toBeNull()
        ->and((int) $tersimpan->is_racikan)->toBe(1);
});

test('nama_obat is a snapshot that survives a catalogue rename', function (): void {
    $obat = rx39Obat('Amoxicillin', ['nama_brand' => 'Amoxsan', 'harga_jual' => '7500.00']);
    $akun = rx39DoctorAccount();

    $respons = $this->withHeaders(rx39As($akun['user']))->postJson('/api/v1/konsultasi/'.$akun['sesi']->getKey().'/resep', rx39Body($obat, 12))
        ->assertCreated();

    expect($respons->json('data.resep.items.0.nama_obat'))->toBe('Amoxicillin')
        ->and($respons->json('data.resep.items.0.harga_satuan'))->toBe('7500.00')
        ->and($respons->json('data.resep.items.0.subtotal'))->toBe('90000.00');

    DB::table('master_obat')->where('id', $obat)->update(['nama_generik' => 'Amoksisilin Generik']);

    $tersimpan = DB::table('resep_item')->where('resep_id', $respons->json('data.resep.id'))->first();
    expect($tersimpan->nama_obat)->toBe('Amoxicillin')
        ->and($tersimpan->harga_satuan)->toBe('7500.00');
});

test('berlaku_sampai is tanggal_resep plus 7 days, and neither is settable', function (): void {
    $obat = rx39Obat('Amoxicillin');
    $akun = rx39DoctorAccount();
    $path = '/api/v1/konsultasi/'.$akun['sesi']->getKey().'/resep';

    $respons = $this->withHeaders(rx39As($akun['user']))->postJson($path, rx39Body($obat))
        ->assertCreated();

    expect($respons->json('data.resep.berlaku_sampai'))->toBe('2026-03-18')
        ->and(ResepService::BERLAKU_SAMPAI_HARI)->toBe(7);

    $tersimpan = DB::table('resep')->where('id', $respons->json('data.resep.id'))->first();
    expect(substr((string) $tersimpan->berlaku_sampai, 0, 10))->toBe('2026-03-18');
    foreach (['tanggal_resep' => '2020-01-01 00:00:00', 'berlaku_sampai' => '2030-01-01'] as $kolom => $nilai) {
        $pesan = 'The '.str_replace('_', ' ', $kolom).' field is prohibited.';

        $this->withHeaders(rx39As($akun['user']))
            ->postJson($path, rx39Body($obat, 10, [$kolom => $nilai]))
            ->assertStatus(422)
            ->assertJsonPath('errors.'.$kolom.'.0', $pesan);
    }
});

test('unknown, withdrawn or missing drugs are refused by name and write nothing', function (): void {
    $akun = rx39DoctorAccount();
    $path = '/api/v1/konsultasi/'.$akun['sesi']->getKey().'/resep';

    $this->withHeaders(rx39As($akun['user']))->postJson($path, ['items' => [[
        'obat_id' => 999999, 'aturan_pakai' => '3 x 1 tablet', 'jumlah' => 10, 'satuan' => 'tablet',
    ]]])
        ->assertStatus(422)
        ->assertJsonPath('errors.items.0.obat_id.0', 'Obat tidak ditemukan.');

    $nonaktif = rx39Obat('Obat Lama Sekali', ['status_aktif' => 0]);

    $this->withHeaders(rx39As($akun['user']))->postJson($path, ['items' => [[
        'obat_id' => $nonaktif, 'aturan_pakai' => '3 x 1 tablet', 'jumlah' => 10, 'satuan' => 'tablet',
    ]]])
        ->assertStatus(422)
        ->assertJsonPath('errors.items.0.obat_id.0', 'Obat tidak aktif.');

    $this->withHeaders(rx39As($akun['user']))->postJson($path, ['items' => []])
        ->assertStatus(422)
        ->assertJsonPath('errors.items.0', 'Prescription must contain at least one item.');

    expect(DB::table('resep')->count())->toBe(0)
        ->and(DB::table('resep_item')->count())->toBe(0);
});

test('another doctor consultation is a 404 and a profile-less caller is a 403', function (): void {
    $milik = rx39DoctorAccount();
    $asing = rx39DoctorAccount();
    $obat = rx39Obat('Amoxicillin');

    $this->withHeaders(rx39As($asing['user']))->postJson('/api/v1/konsultasi/'.$milik['sesi']->getKey().'/resep', rx39Body($obat))
        ->assertStatus(404)
        ->assertExactJson(['success' => false, 'message' => 'Resource not found.', 'errors' => []]);

    expect(DB::table('resep')->count())->toBe(0);

    $this->withHeaders(rx39As($milik['user']))->postJson('/api/v1/konsultasi/'.$milik['sesi']->getKey().'/resep', rx39Body($obat))
        ->assertCreated();

    $tanpaProfil = rx39Pengguna('dokter', 'dokter');

    $this->withHeaders(rx39As($tanpaProfil))->postJson('/api/v1/konsultasi/1/resep', rx39Body($obat))
        ->assertStatus(403);

    $this->withHeaders(rx39As($milik['pasienUser']))->postJson('/api/v1/konsultasi/'.$milik['sesi']->getKey().'/resep', rx39Body($obat))
        ->assertStatus(403);
});

test('nomor_resep fits the column and qr_token is a uuid', function (): void {
    $obat = rx39Obat('Amoxicillin');
    $akun = rx39DoctorAccount();

    $respons = $this->withHeaders(rx39As($akun['user']))->postJson('/api/v1/konsultasi/'.$akun['sesi']->getKey().'/resep', rx39Body($obat))
        ->assertCreated();

    $nomor = (string) $respons->json('data.resep.nomor_resep');

    expect($nomor)->toStartWith('RX'.str_replace('-', '', RX39_HARI))
        ->and($nomor)->toMatch('/^RX\d{8}[A-Z0-9]{6}$/')
        ->and(strlen($nomor))->toBeLessThanOrEqual(30);

    $token = (string) $respons->json('data.resep.qr_token');
    expect($token)->toHaveLength(36)->and($token[14])->toBe('4');
});

test('CONTROL: a pair warns and a single drug does not', function (): void {
    [$amox, $metformin] = rx39PasanganKontraindikasi();
    $akun = rx39DoctorAccount();
    $path = '/api/v1/konsultasi/'.$akun['sesi']->getKey().'/resep';

    $dengan = $this->withHeaders(rx39As($akun['user']))->postJson($path, [
        'catatan_dodio' => 'Dipertimbangkan.',
        'items' => [
            ['obat_id' => $amox, 'aturan_pakai' => '3 x 1', 'jumlah' => 10, 'satuan' => 'tablet'],
            ['obat_id' => $metformin, 'aturan_pakai' => '1 x 1', 'jumlah' => 20, 'satuan' => 'tablet'],
        ],
    ]);

    // The control runs on a FRESH account: the pair above is a live
    // `kontraindikasi` prescription, so the same drug prescribed again to the
    // same patient would warn via `riwayat_resep` - the engine's second
    // requirement, not silence.
    $segar = rx39DoctorAccount();

    $satu = $this->withHeaders(rx39As($segar['user']))->postJson(
        '/api/v1/konsultasi/'.$segar['sesi']->getKey().'/resep',
        ['items' => [['obat_id' => $amox, 'aturan_pakai' => '3 x 1', 'jumlah' => 10, 'satuan' => 'tablet']]],
    );

    expect($dengan->json('data.warning'))->toHaveCount(1)
        ->and($dengan->json('data.warning.0.tingkat'))->toBe('kontraindikasi')
        ->and($satu->json('data.warning'))->toHaveCount(0)
        ->and($dengan->json('data.warning.0.tingkat'))->not->toBe('ringan');
});
