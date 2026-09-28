<?php

declare(strict_types=1);

use App\Services\Obat\NamaObat;
use App\Services\Obat\ObatSearchService;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Todo 39 part 2 - medicine search reuses NamaObat normalisation
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

test('search reuses NamaObat so every spelling of one drug finds the same row', function (): void {
    $amox = rx39Obat('Amoxicillin', [
        'nama_brand' => 'Amoxsan',
        'kekuatan' => '500 mg',
        'kelas_terapi' => 'Antibiotik',
        'requires_resep' => 1,
        'harga_jual' => '7500.00',
        'aturan_pakai_umum' => '3 x 1 tablet sesudah makan',
        'kontraindikasi' => 'Hipersensitif terhadap penisilin.',
    ]);
    $lain = rx39Obat('Metformin', ['nama_brand' => 'Glucophage', 'kelas_terapi' => 'Antidiabetik']);

    $dokter = rx39As(rx39DoctorAccount()['user']);

    foreach (['Amoxicillin', 'amoxicillin', 'AMOXICILLIN', 'Amoxi-cillin', 'Amoxicillin 500 mg'] as $ejaan) {
        expect(NamaObat::inti($ejaan))->toBe(NamaObat::inti('Amoxicillin'));

        $this->withHeaders($dokter)
            ->getJson('/api/v1/obat?search='.urlencode($ejaan))
            ->assertOk()
            ->assertJsonPath('data.obat.0.id', $amox)
            ->assertJsonPath('data.obat.0.nama_generik', 'Amoxicillin')
            ->assertJsonCount(1, 'data.obat');
    }

    $this->withHeaders($dokter)
        ->getJson('/api/v1/obat?search=Amoxsan')
        ->assertOk()
        ->assertJsonPath('data.obat.0.id', $amox);

    expect($lain)->not->toBe($amox);

    $this->withHeaders($dokter)
        ->getJson('/api/v1/obat?search=metformin')
        ->assertOk()
        ->assertJsonPath('data.obat.0.id', $lain);
});

test('search does not fire on a near-miss or on a therapy class', function (): void {
    rx39Obat('Amoxicillin', ['kelas_terapi' => 'Antibiotik']);
    rx39Obat('Amoxicillin-Clavulanate', ['nama_brand' => 'Augmentin', 'kelas_terapi' => 'Antibiotik']);

    $dokter = rx39As(rx39DoctorAccount()['user']);

    foreach (['Amoxicilline', 'Amoxycillin', 'Amoxicilin'] as $hampir) {
        $this->withHeaders($dokter)
            ->getJson('/api/v1/obat?search='.urlencode($hampir))
            ->assertOk()
            ->assertJsonCount(0, 'data.obat');
    }

    $this->withHeaders($dokter)
        ->getJson('/api/v1/obat?search=antibiotik')
        ->assertOk()
        ->assertJsonCount(0, 'data.obat');
});

test('search returns the catalogue fields, active rows only, meta as top-level sibling', function (): void {
    $aktif = rx39Obat('Amoxicillin', [
        'nama_brand' => 'Amoxsan',
        'kelas_terapi' => 'Antibiotik',
        'kelas_obat' => 'keras',
        'harga_jual' => '7500.00',
        'status_aktif' => 1,
    ]);
    rx39Obat('Amoxicillin', ['status_aktif' => 0, 'kelas_obat' => 'keras']);

    $dokter = rx39As(rx39DoctorAccount()['user']);

    $respons = $this->withHeaders($dokter)->getJson('/api/v1/obat?search=amoxicillin')->assertOk();

    $respons->assertJsonPath('data.obat.0.id', $aktif)
        ->assertJsonPath('data.obat.0.nama_brand', 'Amoxsan')
        ->assertJsonPath('data.obat.0.kelas_terapi', 'Antibiotik')
        ->assertJsonPath('data.obat.0.kelas_obat', 'keras')
        ->assertJsonPath('data.obat.0.requires_resep', true)
        ->assertJsonCount(1, 'data.obat')
        ->assertJsonStructure(['success', 'data' => ['obat'], 'message', 'meta' => [
            'current_page', 'last_page', 'per_page', 'total', 'from', 'to',
        ]])
        ->assertJsonPath('meta.total', 1);
});

test('search pages and filters, and rejects an unknown kelas_obat', function (): void {
    for ($i = 0; $i < 7; $i++) {
        rx39Obat('Amoxicillin', ['kelas_obat' => $i % 2 === 0 ? 'keras' : 'bebas']);
    }
    $dokter = rx39As(rx39DoctorAccount()['user']);

    $this->withHeaders($dokter)
        ->getJson('/api/v1/obat?search=amoxicillin&per_page=3')
        ->assertOk()
        ->assertJsonCount(3, 'data.obat')
        ->assertJsonPath('meta.total', 7)
        ->assertJsonPath('meta.last_page', 3);

    $keras = $this->withHeaders($dokter)
        ->getJson('/api/v1/obat?search=amoxicillin&kelas_obat=keras')
        ->assertOk();
    expect($keras->json('data.obat'))->toHaveCount(4);

    $this->withHeaders($dokter)
        ->getJson('/api/v1/obat?kelas_obat=jamu')
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors.kelas_obat.0', 'Kelas obat tidak dikenal.');
});

test('the search service decides with NamaObat, not with a like', function (): void {
    $obat = rx39Obat('Amoxicillin', ['nama_brand' => 'Amoxsan']);
    $svc = app(ObatSearchService::class);

    $hasil = $svc->cari(['search' => 'amoxi-cillin', 'kelas_obat' => null, 'requires_resep' => null, 'page' => 1, 'per_page' => 15]);

    expect($hasil->total())->toBe(1)
        ->and($hasil->getCollection()->first()->getKey())->toBe($obat)
        ->and(DB::table('master_obat')->where('nama_generik', 'like', '%amoxi-cillin%')->pluck('id')->all())
        ->not->toContain($obat);
});
