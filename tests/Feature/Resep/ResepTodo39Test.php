<?php

declare(strict_types=1);

use App\Enums\MasterObatKelas;
use App\Enums\ResepStatus;
use App\Services\Resep\ResepService;
use App\Support\Rbac\RbacCatalog;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Todo 39 part 1 - DDL citations, closed sets, route table
|--------------------------------------------------------------------------
|
| Closed sets (resep.status, kelas_obat, routes, guards) are generated from
| the DDL and the live route table, never hand-typed. The fixtures live in
| `resep-helpers.php`, required below so this file runs on its own.
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
// 1. The DDL, read and not recalled
// =====================================================================

test('resep and resep_item are the DDL tables this todo writes', function (): void {
    $resep = rx39Spec()->table('resep');
    $item = rx39Spec()->table('resep_item');

    expect($resep)->not->toBeNull()->and($item)->not->toBeNull();

    expect($resep->columns)->toHaveKeys(['dibuat_at', 'diubah_at'])
        ->and($resep->columns)->not->toHaveKeys(['dihapus_at'])
        ->and($item->columns)->not->toHaveKeys(['dibuat_at', 'diubah_at', 'terkirim_at']);

    $fk = array_map(
        static fn ($spek): string => $spek->columns[0],
        $resep->foreignKeys,
    );
    sort($fk);

    expect($fk)->toBe(['apotek_id', 'dokter_id', 'pasien_id'])
        ->and($resep->columns['rekam_medis_id']->nullable)->toBeTrue()
        ->and($resep->columns['konsultasi_id']->nullable)->toBeTrue();

    expect((int) rx39Spec()->table('master_obat')->columns['requires_resep']->default)->toBe(1)
        ->and((string) rx39Spec()->table('master_obat')->columns['harga_jual']->default)->toBe('0');
});

test('the DDL lines this todo cites are the lines the file actually has', function (): void {
    rx39AssertLine(708, 'CREATE TABLE master_obat (');
    rx39AssertLine(711, 'nama_generik VARCHAR(255) NOT NULL');
    rx39AssertLine(712, 'nama_brand VARCHAR(255) NULL');
    rx39AssertLine(715, 'kekuatan VARCHAR(50) NULL');
    rx39AssertLine(718, 'kelas_terapi VARCHAR(100) NULL');
    rx39AssertLine(719, 'kelas_obat ENUM(');
    rx39AssertLine(720, 'requires_resep TINYINT(1) NOT NULL DEFAULT 1');
    rx39AssertLine(724, 'harga_jual DECIMAL(12,2) NOT NULL DEFAULT 0');
    rx39AssertLine(725, 'status_aktif TINYINT(1) NOT NULL DEFAULT 1');
    rx39AssertLine(728, 'INDEX idx_obat_nama (nama_generik)');
    rx39AssertLine(742, 'CREATE TABLE resep (');
    rx39AssertLine(744, 'nomor_resep VARCHAR(30) NOT NULL UNIQUE');
    rx39AssertLine(749, 'apotek_id');
    rx39AssertLine(750, "tipe ENUM('digital','manual') NOT NULL DEFAULT 'digital'");
    rx39AssertLine(751, "status ENUM('aktif','diproses','diverifikasi','dipenuhi','dikirim','selesai',");
    rx39AssertLine(752, "'kedaluwarsa','dibatalkan') NOT NULL DEFAULT 'aktif',");
    rx39AssertLine(753, 'catatan_dokter TEXT NULL');
    rx39AssertLine(754, 'tanggal_resep DATETIME NOT NULL');
    rx39AssertLine(755, "berlaku_sampai DATE NOT NULL COMMENT 'E-resep berlaku 7 hari'");
    rx39AssertLine(758, 'qr_token VARCHAR(100) NOT NULL');
    rx39AssertLine(764, 'INDEX idx_resep_pasien (pasien_id, status)');
    rx39AssertLine(767, 'CREATE TABLE resep_item (');
    rx39AssertLine(770, 'obat_id BIGINT UNSIGNED NULL');
    rx39AssertLine(771, 'nama_obat VARCHAR(255) NOT NULL');
    rx39AssertLine(773, 'aturan_pakai VARCHAR(255) NOT NULL');
    rx39AssertLine(774, 'jumlah SMALLINT UNSIGNED NOT NULL');
    rx39AssertLine(776, 'is_racikan TINYINT(1) NOT NULL DEFAULT 0');
    rx39AssertLine(778, 'harga_satuan DECIMAL(12,2) NOT NULL DEFAULT 0');
    rx39AssertLine(779, 'subtotal DECIMAL(12,2) NOT NULL DEFAULT 0');
    rx39AssertLine(782, 'FOREIGN KEY (obat_id) REFERENCES master_obat(id)');

    expect(rx39DdlLine(727))->toContain('diubah_at')
        ->and(rx39DdlLine(728))->toContain('INDEX idx_obat_nama')
        ->and(rx39DdlLine(761))->toContain('FOREIGN KEY (pasien_id)')
        ->and(rx39DdlLine(755))->toContain('berlaku 7 hari');
});

test('resep.status is the eight-member DDL enum wrapped onto two lines', function (): void {
    $kolom = rx39Spec()->table('resep')->columns['status'];

    expect(rx39Enum('resep', 'status'))->toBe([
        'aktif', 'diproses', 'diverifikasi', 'dipenuhi',
        'dikirim', 'selesai', 'kedaluwarsa', 'dibatalkan',
    ])
        ->and($kolom->line)->toBe(751)
        ->and($kolom->endLine)->toBe(752)
        ->and($kolom->wrapped())->toBeTrue()
        ->and(trim((string) $kolom->default, "'"))->toBe('aktif');

    expect(ResepStatus::nilai())->toBe(rx39Enum('resep', 'status'))
        ->and(ResepStatus::cases())->toHaveCount(8)
        ->and(ResepStatus::default())->toBe('aktif');
});

test('master_obat.kelas_obat is the six-member DDL enum and the class matches it', function (): void {
    $dariDdl = rx39Enum('master_obat', 'kelas_obat');

    expect($dariDdl)->toBe(['bebas', 'bebas_terbatas', 'keras', 'fitofarmaka', 'narkotika', 'psikotropika'])
        ->and($dariDdl)->toHaveCount(6)
        ->and(MasterObatKelas::nilai())->toBe($dariDdl)
        ->and(MasterObatKelas::cases())->toHaveCount(6);
});

test('qr_token has no unique and no index, nomor_resep is the only unique', function (): void {
    $resep = rx39Spec()->table('resep');
    $tokenIndex = [];
    $uniqueKolom = [];

    foreach ($resep->indexes as $index) {
        if (in_array('qr_token', $index->columns, true)) {
            $tokenIndex[] = $index->name;
        }

        if ($index->type === 'UNIQUE') {
            $uniqueKolom[] = implode(',', $index->columns);
        }
    }

    expect($tokenIndex)->toBe([])
        ->and($uniqueKolom)->toBe(['nomor_resep'])
        ->and($resep->columns['qr_token']->nullable)->toBeFalse();
});

test('berlaku_sampai is a date with a 7-day comment and no trigger reacts to it', function (): void {
    $kolom = rx39Spec()->table('resep')->columns['berlaku_sampai'];

    expect($kolom->type)->toBe('date')
        ->and($kolom->nullable)->toBeFalse()
        ->and($kolom->line)->toBe(755);

    $sql = (string) file_get_contents(base_path('telemedicine_test.sql'));

    expect(substr_count($sql, "COMMENT 'E-resep berlaku 7 hari'"))->toBe(1)
        ->and(str_contains($sql, 'TRIGGER'))->toBeFalse()
        ->and(str_contains($sql, 'GENERATED'))->toBeFalse()
        ->and(str_contains($sql, 'CREATE EVENT'))->toBeFalse()
        ->and(ResepService::BERLAKU_SAMPAI_HARI)->toBe(7);
});

// =====================================================================
// 2. The route table
// =====================================================================

test('the two routes exist with exactly the guards the plan names', function (): void {
    $routes = rx39Routes();

    expect(array_keys($routes))->toEqualCanonicalizing([
        'GET api/v1/obat',
        'POST api/v1/konsultasi/{id}/resep',
    ]);

    foreach (array_keys($routes) as $key) {
        expect(RbacCatalog::isPermission('obat.cari'))->toBeTrue()
            ->and(RbacCatalog::isPermission('resep.buat'))->toBeTrue()
            ->and(rx39Guards($routes, $key))->toContain('auth:sanctum')
            ->and(rx39Guards($routes, $key))->toContain('tipe:dokter')
            ->and(rx39Guards($routes, $key))->toContain(
                $key === 'GET api/v1/obat' ? 'permission:obat.cari' : 'permission:resep.buat',
            );
    }

    foreach (['perawat', 'kurir'] as $tanpaPeran) {
        expect(RbacCatalog::isUserType($tanpaPeran))->toBeTrue()
            ->and(RbacCatalog::isRole($tanpaPeran))->toBeFalse()
            ->and(RbacCatalog::permissionsFor('dokter'))->toContain('obat.cari', 'resep.buat');
    }
});

test('the write route is the only post that writes a prescription', function (): void {
    $semua = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('POST', $route->methods(), true)
            && str_contains($route->uri(), 'resep'))
        ->map(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->values()
        ->all();

    expect($semua)->toBe(['POST api/v1/konsultasi/{id}/resep']);
});

test('both routes answer 401 to a caller with no token', function (): void {
    $this->getJson('/api/v1/obat?search=amox')
        ->assertStatus(401)
        ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.', 'errors' => []]);

    $this->postJson('/api/v1/konsultasi/1/resep', rx39Body(1))
        ->assertStatus(401)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Unauthenticated.');
});
