<?php

declare(strict_types=1);

use App\Models\Konsultasi;
use App\Models\Resep;
use App\Models\ResepItem;
use App\Models\ResepVerifikasi;
use App\Models\User;
use App\Support\Rbac\RoleAssigner;
use App\Support\Schema\SchemaSpec;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/*
|--------------------------------------------------------------------------
| Todo 40 - shared fixtures for the prescription-detail / verification file
|--------------------------------------------------------------------------
|
| `require_once`d by the test file, so that file is runnable on its own: the
| alternative is `php artisan test tests/Feature/Resep/OneFile.php` reporting a
| wall of "undefined constant" errors, which reads as a broken test rather
| than a missing include. This is exactly what `resep-helpers.php` does for
| todo 39, and the reason it is `require_once`d there is quoted in that file's
| own header.
|
| The prefix is `rx40` because Pest loads every test file into ONE process and
| these are global functions - the same reason todo 39's are `rx39`. Nothing
| here is re-derived from `resep-helpers.php`: a todo-40 fixture that is
| `rx39` in a file this todo also creates would make the two directories
| interdependent, and deleting either one would then break the other.
|
| ## The CLOCK is the test's, and it is set in the test file's `beforeEach`
|
| `RX40_JAM` is `2026-03-11 10:00:00`, so `berlaku_sampai` for a prescription
| written against it is `2026-03-18` - the seven days
| `telemedicine_test.sql:755` names in its own `COMMENT`. The expiry tests
| move the clock FORWARD past that day rather than writing a past date into
| the column, because nothing in the schema reacts to `berlaku_sampai`: the
| flag under test has to be a PHP comparison, and moving the clock is what
| makes that comparison the only thing under test.
*/

const RX40_JAM = '2026-03-11 10:00:00';

/** The day `berlaku_sampai` lands on for a prescription written at {@see RX40_JAM}. */
const RX40_BERLAKU = '2026-03-18';

function rx40Jam(): Carbon
{
    return Carbon::parse(RX40_JAM);
}

function rx40User(string $nama, string $tipe = 'pasien'): int
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

function rx40Pengguna(string $tipe, ?string $role = null): User
{
    $id = rx40User('Pengguna '.$tipe.' '.Str::upper(Str::random(4)), $tipe);

    if ($role !== null) {
        app(RoleAssigner::class)->assign($id, $role);
    }

    return User::query()->findOrFail($id);
}

function rx40Pasien(int $userId, array $ubah = []): int
{
    return (int) DB::table('pasien')->insertGetId(array_merge([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Verifikasi No. 1, Jakarta',
    ], $ubah));
}

function rx40Dokter(int $userId, array $ubah = []): int
{
    return (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-RX40-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ], $ubah));
}

function rx40Konsultasi(int $pasienId, int $dokterId, string $status = 'selesai'): Konsultasi
{
    $row = new Konsultasi;
    $row->pasien_id = $pasienId;
    $row->dokter_id = $dokterId;
    $row->tipe = 'chat';
    $row->status = $status;
    $row->room_id = (string) Str::uuid();
    $row->mulai_at = Carbon::parse('2026-03-11 09:00:00');
    $row->save();

    return $row;
}

/**
 * A doctor, their patient, and the consultation the two share.
 *
 * @return array{user: User, dokter: int, pasien: int, pasienUser: User, sesi: Konsultasi}
 */
function rx40DoctorAccount(string $status = 'selesai'): array
{
    $dokterUser = rx40Pengguna('dokter', 'dokter');
    $pasienUser = rx40Pengguna('pasien', 'pasien');
    $dokter = rx40Dokter($dokterUser->getKey());
    $pasien = rx40Pasien($pasienUser->getKey());

    return [
        'user' => $dokterUser,
        'dokter' => $dokter,
        'pasien' => $pasien,
        'pasienUser' => $pasienUser,
        'sesi' => rx40Konsultasi($pasien, $dokter, $status),
    ];
}

function rx40Obat(string $namaGenerik, array $ubah = []): int
{
    return (int) DB::table('master_obat')->insertGetId(array_merge([
        'kode_obat' => 'RX40-'.Str::upper(Str::random(10)),
        'nama_generik' => $namaGenerik,
        'bentuk_sediaan' => 'tablet',
        'satuan' => 'tablet',
        'kelas_obat' => 'keras',
    ], $ubah));
}

/**
 * @param  'ringan'|'sedang'|'berat'|'kontraindikasi'  $tingkat
 */
function rx40Interaksi(int $a, int $b, string $tingkat = 'berat', ?string $deskripsi = null): int
{
    return (int) DB::table('obat_interaksi')->insertGetId([
        'obat_a_id' => $a,
        'obat_b_id' => $b,
        'tingkat' => $tingkat,
        'deskripsi' => $deskripsi,
    ]);
}

/**
 * @param  'ringan'|'sedang'|'berat'|'anafilaksis'  $keparahan
 */
function rx40Alergi(int $pasienId, string $nama, string $keparahan = 'ringan'): int
{
    return (int) DB::table('pasien_alergi')->insertGetId([
        'pasien_id' => $pasienId,
        'tipe_alergen' => 'obat',
        'nama_alergen' => $nama,
        'keparahan' => $keparahan,
    ]);
}

/**
 * @return array<string, string>
 */
function rx40As(User $user): array
{
    app('auth')->forgetGuards();

    // The expiry is absolute rather than "one hour from now", because the
    // expiry tests move `Carbon::setTestNow()` FORWARD past `berlaku_sampai`
    // and a relative token would silently 401 on the second request of the
    // same test - a failure that reads as a broken endpoint rather than a
    // fixture that aged out. A year is far past any clock these tests move to
    // and stays inside `personal_access_tokens.expires_at`, which is a
    // `TIMESTAMP` and therefore stops at 2038.
    $token = $user->createToken('resep-todo40', ['*'], rx40Jam()->addYear())->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

/**
 * Write a prescription row and its items directly, so a test can put the
 * database in a state no endpoint reaches yet.
 *
 * The endpoint under test is todo 39's, and reaching `resep.status = 'diproses'`
 * through it is impossible on purpose - no route in the file moves a
 * prescription off `aktif` except todo 40's own verification. A fixture that
 * routes through the API would therefore have to assert the thing it is
 * setting up, and these tests are about what happens AFTER the row exists.
 *
 * @param  list<int>  $obatIds
 */
function rx40Resep(int $pasienId, int $dokterId, array $obatIds, string $status = 'aktif', array $ubah = []): Resep
{
    $sekarang = rx40Jam();

    $resep = new Resep;
    $resep->nomor_resep = 'RX40'.strtoupper(Str::random(10));
    $resep->konsultasi_id = null;
    $resep->rekam_medis_id = null;
    $resep->pasien_id = $pasienId;
    $resep->dokter_id = $dokterId;
    $resep->apotek_id = null;
    $resep->tipe = 'digital';
    $resep->status = $status;
    $resep->catatan_dokter = null;
    $resep->tanggal_resep = $sekarang;
    $resep->berlaku_sampai = $sekarang->copy()->addDays(7)->toDateString();
    $resep->is_iter = false;
    $resep->jumlah_iter = 0;
    $resep->qr_token = (string) Str::uuid();

    foreach ($ubah as $kolom => $nilai) {
        $resep->{$kolom} = $nilai;
    }

    $resep->save();

    // The snapshot is the catalogue name at the time of writing, read from
    // `master_obat` rather than typed in, so a test that asserts the detail
    // publishes the drug it created is not asserting a hardcoded string that
    // happens to match.
    $nama = DB::table('master_obat')
        ->whereIn('id', $obatIds)
        ->pluck('nama_generik', 'id');

    foreach ($obatIds as $obatId) {
        $baris = new ResepItem;
        $baris->resep_id = $resep->getKey();
        $baris->obat_id = $obatId;
        $baris->nama_obat = (string) ($nama[$obatId] ?? 'Uji Rx40');
        $baris->kekuatan = '500 mg';
        $baris->aturan_pakai = '3 x 1 tablet';
        $baris->jumlah = 10;
        $baris->satuan = 'tablet';
        $baris->is_racikan = false;
        $baris->racikan_nama = null;
        $baris->harga_satuan = '7500.00';
        $baris->subtotal = '75000.00';
        $baris->catatan_apoteker = null;
        $baris->save();
    }

    return $resep;
}

function rx40Spec(): SchemaSpec
{
    return (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
}

/**
 * @return list<string>
 */
function rx40Enum(string $table, string $column): array
{
    $type = rx40Spec()->table($table)->columns[$column]->type;

    preg_match_all("/'([^']+)'/", (string) $type, $matches);

    return $matches[1];
}

function rx40DdlLine(int $n): ?string
{
    $lines = file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);

    return $lines[$n - 1] ?? null;
}

/**
 * Assert a DDL line really says `$token`, so a citation in this file cannot
 * drift from the file it cites.
 */
function rx40AssertLine(int $line, string $token): void
{
    $actual = rx40DdlLine($line);

    Assert::assertNotNull($actual, "telemedicine_test.sql:{$line} does not exist");

    Assert::assertStringContainsString(
        $token,
        (string) $actual,
        "telemedicine_test.sql:{$line} is [".(string) $actual.'] and does not contain ['.$token.']',
    );
}

/**
 * The four routes todo 40 registers, keyed by `METHOD uri`.
 *
 * The closed set is keyed by URI rather than collected from a `--path=` filter,
 * because the plan's own acceptance criterion says
 * `route:list --path=api/v1/resep` "lists 4 routes" and the patient-history
 * route is a `pasien` path - the same prefix-filter defect todo 32 found for
 * `konsultasi`, todo 33 for `rekam-medis` and todo 34 for
 * `surat_keterangan`. A filter on `resep` alone answers 3; all four ship.
 *
 * @return array<string, mixed>
 */
function rx40Routes(): array
{
    $uris = [
        'api/v1/resep/{id}',
        'api/v1/resep/{id}/verifikasi',
        'api/v1/resep/{id}/cek-interaksi',
        'api/v1/pasien/resep',
    ];

    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array($route->uri(), $uris, true))
        ->keyBy(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->all();
}

/**
 * @param  array<string, mixed>  $routes
 * @return list<string>
 */
function rx40Guards(array $routes, string $key): array
{
    return array_values(array_filter(
        $routes[$key]->gatherMiddleware(),
        static fn ($middleware): bool => is_string($middleware),
    ));
}

/**
 * A stored verification row, or null.
 */
function rx40Verifikasi(int $resepId): ?ResepVerifikasi
{
    return ResepVerifikasi::query()->where('resep_id', $resepId)->first();
}
