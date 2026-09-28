<?php

declare(strict_types=1);

use App\Models\Konsultasi;
use App\Models\User;
use App\Support\Rbac\RoleAssigner;
use App\Support\Schema\SqlSchemaParser;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/*
|--------------------------------------------------------------------------
| Todo 39 - shared fixtures for the four Resep test files
|--------------------------------------------------------------------------
|
| `require_once`d by each of them, so a single file is runnable on its own -
| the alternative is that `php artisan test tests/Feature/Resep/OneFile.php`
| passes nothing and reports eight errors about an undefined constant, which
| reads as a broken test rather than a missing include.
|
| The prefix is `rx39` because Pest loads every test file into ONE process and
| these are global functions.
*/

const RX39_HARI = '2026-03-11';
const RX39_JAM = '2026-03-11 10:00:00';

/**
 * The frozen clock every fixture in this directory is written against.
 *
 * `RX39_JAM` is `2026-03-11 10:00:00` in UTC, so `berlaku_sampai` for a
 * prescription written in these tests is `2026-03-18` - seven days, the
 * constant the DDL's own comment at `telemedicine_test.sql:755` names.
 */
function rx39Jam(): Carbon
{
    return Carbon::parse(RX39_JAM, 'UTC');
}

function rx39User(string $nama, string $tipe = 'pasien'): int
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

function rx39Pengguna(string $tipe, ?string $role = null): User
{
    $id = rx39User('Pengguna '.$tipe.' '.Str::upper(Str::random(4)), $tipe);

    if ($role !== null) {
        app(RoleAssigner::class)->assign($id, $role);
    }

    return User::query()->findOrFail($id);
}

function rx39Pasien(int $userId, array $ubah = []): int
{
    return (int) DB::table('pasien')->insertGetId(array_merge([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Resep No. 1, Jakarta',
    ], $ubah));
}

function rx39Dokter(int $userId, array $ubah = []): int
{
    return (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-RX39-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ], $ubah));
}

function rx39Konsultasi(int $pasienId, int $dokterId, string $status = 'selesai'): Konsultasi
{
    $row = new Konsultasi;
    $row->pasien_id = $pasienId;
    $row->dokter_id = $dokterId;
    $row->tipe = 'chat';
    $row->status = $status;
    $row->room_id = (string) Str::uuid();
    $row->mulai_at = Carbon::parse(RX39_HARI.' 09:00:00');
    $row->save();

    return $row;
}

/**
 * @return array{user: User, dokter: int, pasien: int, pasienUser: User, sesi: Konsultasi}
 */
function rx39DoctorAccount(string $status = 'selesai'): array
{
    $dokterUser = rx39Pengguna('dokter', 'dokter');
    $pasienUser = rx39Pengguna('pasien', 'pasien');
    $dokter = rx39Dokter($dokterUser->getKey());
    $pasien = rx39Pasien($pasienUser->getKey());

    return [
        'user' => $dokterUser,
        'dokter' => $dokter,
        'pasien' => $pasien,
        'pasienUser' => $pasienUser,
        'sesi' => rx39Konsultasi($pasien, $dokter, $status),
    ];
}

function rx39Obat(string $namaGenerik, array $ubah = []): int
{
    return (int) DB::table('master_obat')->insertGetId(array_merge([
        'kode_obat' => 'RX39-'.Str::upper(Str::random(10)),
        'nama_generik' => $namaGenerik,
        'bentuk_sediaan' => 'tablet',
        'satuan' => 'tablet',
        'kelas_obat' => 'keras',
    ], $ubah));
}

/**
 * @param 'ringan'|'sedang'|'berat'|'kontraindikasi' $tingkat
 */
function rx39Interaksi(int $a, int $b, string $tingkat = 'berat', ?string $deskripsi = null): int
{
    return (int) DB::table('obat_interaksi')->insertGetId([
        'obat_a_id' => $a,
        'obat_b_id' => $b,
        'tingkat' => $tingkat,
        'deskripsi' => $deskripsi,
    ]);
}

/**
 * @param 'ringan'|'sedang'|'berat'|'anafilaksis' $keparahan
 */
function rx39Alergi(int $pasienId, string $nama, string $keparahan = 'ringan'): int
{
    return (int) DB::table('pasien_alergi')->insertGetId([
        'pasien_id' => $pasienId,
        'tipe_alergen' => 'obat',
        'nama_alergen' => $nama,
        'keparahan' => $keparahan,
    ]);
}

/**
 * @param  array<string, mixed>  $tambahan
 * @return array<string, mixed>
 */
function rx39Body(int $obatId, int $jumlah = 10, array $tambahan = []): array
{
    return array_merge([
        'items' => [[
            'obat_id' => $obatId,
            'aturan_pakai' => '3 x 1 tablet sesudah makan',
            'jumlah' => $jumlah,
            'satuan' => 'tablet',
        ]],
    ], $tambahan);
}

/**
 * Bearer headers for `$user`, with a real Sanctum token.
 *
 * @return array<string, string>
 */
function rx39As(User $user): array
{
    app('auth')->forgetGuards();

    $token = $user->createToken('resep-test', ['*'], now()->addHour())->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

function rx39Spec(): App\Support\Schema\SchemaSpec
{
    return (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
}

/**
 * @return list<string>
 */
function rx39Enum(string $table, string $column): array
{
    $type = rx39Spec()->table($table)->columns[$column]->type;

    preg_match_all("/'([^']+)'/", (string) $type, $matches);

    return $matches[1];
}

function rx39DdlLine(int $n): ?string
{
    $lines = file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);

    return $lines[$n - 1] ?? null;
}

function rx39AssertLine(int $line, string $token): void
{
    $actual = rx39DdlLine($line);

    Assert::assertNotNull($actual, "telemedicine_test.sql:{$line} does not exist");

    Assert::assertStringContainsString(
        $token,
        (string) $actual,
        "telemedicine_test.sql:{$line} is [".(string) $actual.'] and does not contain ['.$token.']',
    );
}

/**
 * @return array<string, mixed>
 */
function rx39Routes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => $route->uri() === 'api/v1/obat'
            || $route->uri() === 'api/v1/konsultasi/{id}/resep')
        ->keyBy(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->all();
}

/**
 * @param  array<string, mixed>  $routes
 * @return list<string>
 */
function rx39Guards(array $routes, string $key): array
{
    return array_values(array_filter(
        $routes[$key]->gatherMiddleware(),
        static fn ($middleware): bool => is_string($middleware),
    ));
}

/**
 * Two catalogued drugs with a stored `kontraindikasi` interaction between
 * them, in the DDL's own order (`obat_a_id < obat_b_id`, the spelling the
 * `DevFixtureSeeder` adopts).
 *
 * @return array{0: int, 1: int}
 */
function rx39PasanganKontraindikasi(): array
{
    $amox = rx39Obat('Amoxicillin', [
        'nama_brand' => 'Amoxsan',
        'kekuatan' => '500 mg',
        'kelas_terapi' => 'Antibiotik',
        'harga_jual' => '7500.00',
    ]);
    $metformin = rx39Obat('Metformin', [
        'nama_brand' => 'Glucophage',
        'kekuatan' => '500 mg',
        'kelas_terapi' => 'Antidiabetik',
        'harga_jual' => '6300.00',
    ]);
    rx39Interaksi($amox, $metformin, 'kontraindikasi', 'Kombinasi tidak direkomendasikan.');

    return [$amox, $metformin];
}
