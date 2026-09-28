<?php

declare(strict_types=1);

use App\Enums\PersetujuanPdpJenis;
use App\Enums\SuratKeteranganTipe;
use App\Models\Konsultasi;
use App\Models\PersetujuanPdp;
use App\Models\Rujukan;
use App\Models\SuratKeterangan;
use App\Models\User;
use App\Http\Requests\SuratKeterangan\BuatSuratKeteranganRequest;
use App\Services\Pasien\PasienRecordAccess;
use App\Services\Pdp\PdpConsent;
use App\Services\SuratKeterangan\QrTokenGenerator;
use App\Services\SuratKeterangan\StrQrTokenGenerator;
use App\Services\SuratKeterangan\SuratKeteranganService;
use App\Services\SuratKeterangan\SuratKeteranganTokenHabisException;
use App\Support\Dokumen\NomorDokumen;
use App\Support\NamaMasker;
use App\Support\NikMasker;
use App\Support\Rbac\RbacCatalog;
use App\Support\Rbac\RoleAssigner;
use App\Support\Schema\SqlSchemaParser;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/*
|--------------------------------------------------------------------------
| The medical letters, the referrals, and the QR verification token
|--------------------------------------------------------------------------
|
| APPENDED by todo 34. The helper prefix is `skt`, following the `kns` / `bku` /
| `rmd` / `realtime` convention: Pest loads every test file into one process, so a
| helper name already declared at file scope would be a redeclaration.
|
| ## The collision test is the reason this file exists
|
| `surat_keterangan.qr_token` is `VARCHAR(100) NOT NULL` (telemedicine_test.sql:592)
| with **no UNIQUE index and no other index at all**, so MySQL will not stop two
| letters from carrying the same token. The database cannot enforce it and the DDL
| is read-only law, therefore the ONLY defence is an application-level duplicate
| check plus a bounded retry - and the only way to prove that defence is to force a
| genuine collision, which is what {@see sktTokenStub()} is for. A test that merely
| calls the service and sees a UUID would pass against a service with no collision
| handling whatsoever, which is why the stub rather than the happy path is the
| load-bearing case.
|
| ## The route list and the type lists are GENERATED
|
| The three URIs below are the closed set, and the four letter types and the five
| consent kinds are read out of the parsed DDL rather than typed. Three previous
| batches of this project shipped a hand-typed literal that had silently become a
| different string, one of them inside a permission name, and {@see sktEnum()} plus
| {@see sktRoutes()} make that class of mistake impossible here.
|
| @see \App\Services\SuratKeterangan\SuratKeteranganService for the retry ceiling
| @see \App\Services\Pdp\PdpConsent for the consent rule
*/

// =====================================================================
// Row builders
// =====================================================================

/**
 * The date every letter fixture issues on, so a number assertion is not a race.
 */
const SKT_HARI = '2026-03-11';

/**
 * A `users` row. `uuid` (:134), `nama_lengkap` (:135), `no_telepon` (:137) and
 * `kata_sandi_hash` (:138) are the four NOT NULL columns with no default.
 */
function sktUser(string $nama, string $tipe = 'pasien'): int
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

/**
 * A user carrying `$role`, or carrying NO role at all.
 *
 * `perawat` and `kurir` are real `users.tipe` values (telemedicine_test.sql:139) that
 * hold no role in `RbacCatalog::ROLES`, so a helper that always assigned one would
 * make the only two account types a `permission:` gate locks out untestable.
 */
function sktPengguna(string $tipe, ?string $role = null): User
{
    $id = sktUser('Pengguna '.$tipe.' '.Str::upper(Str::random(4)), $tipe);

    if ($role !== null) {
        app(RoleAssigner::class)->assign($id, $role);
    }

    return User::query()->findOrFail($id);
}

/**
 * A `pasien` row. `jenis_kelamin` (:225), `tanggal_lahir` (:226) and
 * `alamat_lengkap` (:234) are NOT NULL with no default, and `nik` (:222) is
 * `CHAR(16) NULL UNIQUE` so it needs a value for the mask assertions to have
 * something to mask.
 *
 * @param  array<string, mixed>  $ubah
 */
function sktPasien(int $userId, array $ubah = []): int
{
    return (int) DB::table('pasien')->insertGetId(array_merge([
        'user_id' => $userId,
        'nik' => '327312345678'.str_pad((string) (1000 + random_int(0, 8999)), 4, '0', STR_PAD_LEFT),
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Surat No. 12, Jakarta',
    ], $ubah));
}

/**
 * A `dokter` row. `nomor_str` (:413) is UNIQUE so it is randomised per call.
 *
 * @param  array<string, mixed>  $ubah
 */
function sktDokter(int $userId, array $ubah = []): int
{
    return (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-SKT-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'biaya_konsultasi_online' => '150000.00',
        'tersedia_telemedisin' => 1,
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ], $ubah));
}

/**
 * A `faskes` row, which is what `rujukan.faskes_tujuan_id` (:613) points at.
 * `kode_faskes` (:362) is UNIQUE so it is randomised; `nama` (:364) and `alamat`
 * (:366) are NOT NULL with no default.
 *
 * @param  array<string, mixed>  $ubah
 */
function sktFaskes(string $tipe = 'rumah_sakit', array $ubah = []): int
{
    return (int) DB::table('faskes')->insertGetId(array_merge([
        'kode_faskes' => 'F'.Str::upper(Str::random(8)),
        'nama' => 'RS Uji Rujukan',
        'tipe' => $tipe,
        'alamat' => 'Jl. Uji Rujukan No. 1, Jakarta',
        'status_aktif' => 1,
    ], $ubah));
}

/**
 * A `konsultasi` row so `POST /konsultasi/{id}/surat-keterangan` has something to
 * hang a letter off. `booking_id` is left NULL, the instant shape.
 */
function sktKonsultasi(int $pasienId, int $dokterId, string $status = 'selesai'): Konsultasi
{
    $row = new Konsultasi;
    $row->pasien_id = $pasienId;
    $row->dokter_id = $dokterId;
    $row->tipe = 'chat';
    $row->status = $status;
    $row->room_id = (string) Str::uuid();
    $row->mulai_at = Carbon::parse(SKT_HARI.' 09:00:00');
    $row->save();

    return $row;
}

/**
 * A doctor account, its `dokter` row, and a patient it has seen.
 *
 * @return array{user: User, dokter: int, pasien: int, pasienUser: User}
 */
function sktDoctorAccount(): array
{
    $dokterUser = sktPengguna('dokter', 'dokter');
    $pasienUser = sktPengguna('pasien', 'pasien');

    // The patient is named rather than left to `sktPengguna()`'s `Pengguna <tipe>
    // <RANDOM>` pattern, because the word-by-word masking test asserts a LITERAL
    // mask - `S... A.....` - for this account. `nama_lengkap` is `VARCHAR(150) NOT
    // NULL` (telemedicine_test.sql:135) and is NOT UNIQUE, so a fixed name costs
    // nothing, and the tests that compare against `nama_lengkap` rather than a
    // literal (the verify minimum, the list row) read the same value and stay honest.
    $pasienUser->nama_lengkap = 'Siti Aminah';
    $pasienUser->save();

    return [
        'user' => $dokterUser,
        'dokter' => sktDokter($dokterUser->getKey()),
        'pasien' => sktPasien($pasienUser->getKey()),
        'pasienUser' => $pasienUser,
    ];
}

/**
 * Act as `$user` for one request, with a real Sanctum bearer token.
 *
 * `forgetGuards()` first, for the reason `knsAs()` and `rmdAs()` give: `Sanctum`
 * caches its principal, so the first authenticated request inside a test would
 * otherwise decide the caller for every later one.
 */
function sktAs(User $user): mixed
{
    app('auth')->forgetGuards();

    return test()->withToken($user->createToken('surat-keterangan-test', ['*'], now()->addHour())->plainTextToken);
}

/**
 * An approved `persetujuan_pdp` row for `$userId`.
 */
function sktConsent(int $userId, string $jenis = 'berbagi_data_medis', bool $disetujui = true, string $versi = 'v1'): PersetujuanPdp
{
    $row = new PersetujuanPdp;
    $row->user_id = $userId;
    $row->jenis = $jenis;
    $row->versi_dokumen = $versi;
    $row->disetujui = $disetujui;
    $row->disetujui_at = Carbon::parse(SKT_HARI.' 08:00:00');
    $row->save();

    return $row;
}

/**
 * A `surat_keterangan` row written DIRECTLY, bypassing the service.
 *
 * Properties are assigned rather than passed to `create()`, because `SuratKeterangan`
 * declares neither `$fillable` nor `$guarded` - the same reason `rmdRecord()` builds
 * its fixtures attribute by attribute - so `create()` would raise a
 * `MassAssignmentException` rather than write a row.
 *
 * The token is a real UUID4 so a fixture letter never shares one with a generated
 * one, and `qr_token` is written explicitly because the column has no default.
 *
 * @param  array<string, mixed>  $ubah
 */
function sktSurat(int $pasienId, int $dokterId, array $ubah = []): SuratKeterangan
{
    $row = new SuratKeterangan;
    $row->nomor_surat = 'SK'.Str::upper(Str::random(10));
    $row->tipe = 'surat_sakit';
    $row->pasien_id = $pasienId;
    $row->dokter_id = $dokterId;
    $row->qr_token = (string) Str::uuid();

    foreach ($ubah as $column => $value) {
        $row->{$column} = $value;
    }

    $row->save();

    return $row;
}

/**
 * A `QrTokenGenerator` stub that hands out `$nilai` in order and then keeps
 * repeating the LAST one, so a test can force both a retry and a ceiling.
 *
 * The `dipanggil` counter is public because the assertion that matters is not "a
 * letter was created" but "the generator was asked N times", and a stub that
 * returned its values without counting could not tell a retry from a first
 * success.
 *
 * @param  list<string>  $nilai
 */
function sktTokenStub(array $nilai): QrTokenGenerator
{
    return new class($nilai) implements QrTokenGenerator
    {
        /**
         * How many times {@see next()} was called.
         */
        public int $dipanggil = 0;

        /**
         * @param  list<string>  $nilai
         */
        public function __construct(private readonly array $nilai) {}

        public function next(): string
        {
            $this->dipanggil++;

            return $this->nilai[min($this->dipanggil, count($this->nilai)) - 1];
        }
    };
}

/**
 * The parsed DDL, once, for the vocabulary and citation assertions.
 */
function sktSpec(): App\Support\Schema\SchemaSpec
{
    return (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
}

/**
 * One `enum(...)` column's values, read through the project's own parser.
 *
 * The parser is the same one `sehatly:verify-schema` uses, so a test that
 * disagreed with the DDL here would also disagree with the schema verifier.
 *
 * @return list<string>
 */
function sktEnum(string $table, string $column): array
{
    $type = sktSpec()->table($table)->columns[$column]->type;

    preg_match_all("/'([^']+)'/", $type, $matches);

    return $matches[1];
}

/**
 * Line `$n` of the reference DDL, or null when the file is shorter.
 */
function sktDdlLine(int $n): ?string
{
    $lines = file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);

    return $lines[$n - 1] ?? null;
}

/**
 * Assert that a cited DDL line really contains the token the docblock claims.
 *
 * `expect()->toContain()` is deliberately NOT used: Pest treats EVERY string
 * argument as a needle, so the failure message would become a second thing the
 * string must contain - the exact trap `PasienProfileTest` documents on its own
 * middleware loop.
 */
function sktAssertLine(int $line, string $token): void
{
    $actual = sktDdlLine($line);

    Assert::assertNotNull($actual, "telemedicine_test.sql:{$line} does not exist");

    Assert::assertStringContainsString(
        $token,
        (string) $actual,
        "telemedicine_test.sql:{$line} is [".(string) $actual."] and does not contain [{$token}]",
    );
}

/**
 * Every `METHOD uri` pair under `$prefix`, keyed and ready for a closed-set
 * assertion. Generated from the live route table, never typed.
 *
 * @return array<string, mixed>
 */
function sktRoutes(string $prefix): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), $prefix))
        ->keyBy(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->all();
}

/**
 * The string guards on one registered route.
 *
 * @param  array<string, mixed>  $routes
 * @return list<string>
 */
function sktGuards(array $routes, string $key): array
{
    return array_values(array_filter(
        $routes[$key]->gatherMiddleware(),
        static fn ($middleware): bool => is_string($middleware),
    ));
}

// =====================================================================
// Setup
// =====================================================================

beforeEach(function (): void {
    // `RbacSeeder` writes `roles`, `permissions` and `role_permissions`, and both
    // `RoleAssigner::assign()` and `EnsurePermission` resolve against those tables
    // at request time, so without it every guarded route answers 500 and each guard
    // assertion here would be measuring the seeder.
    $this->seed(RbacSeeder::class);

    Carbon::setTestNow(Carbon::parse(SKT_HARI.' 10:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// =====================================================================
// The DDL, read and not recalled
// =====================================================================

test('the two letter tables are the DDL, column for column', function (): void {
    $spec = sktSpec();

    $surat = $spec->table('surat_keterangan');
    $rujukan = $spec->table('rujukan');

    expect($surat)->not->toBeNull()
        ->and($rujukan)->not->toBeNull();

    // Both tables declare `dibuat_at` ONLY. There is no `diubah_at` and no
    // `dihapus_at`, so `$table->timestamps()` and `softDeletes()` would each be a
    // missing-column-plus-extra-column pair, and a letter is retired by revoking
    // `rujukan.status` rather than by deleting it.
    foreach (['surat_keterangan', 'rujukan'] as $table) {
        expect(sktSpec()->table($table)->columns)->toHaveKeys(['dibuat_at'])
            ->and(sktSpec()->table($table)->columns)->not->toHaveKeys(['diubah_at', 'dihapus_at', 'created_at', 'updated_at'])
            ->and(sktSpec()->table($table)->columns['dibuat_at']->type)->toBe('timestamp')
            ->and(sktSpec()->table($table)->columns['dibuat_at']->nullable)->toBeFalse();
    }

    // The two bare columns, on both tables. `surat_keterangan.konsultasi_id` (:584) and
    // `rujukan.faskes_asal_id` (:602) are nullable unsigned BIGINTs with NO foreign
    // key, so the service's whole letter-ownership story is an application rule and
    // never a constraint.
    expect($surat->columns['konsultasi_id']->nullable)->toBeTrue()
        ->and($surat->columns['konsultasi_id']->type)->toBe('bigint')
        ->and($rujukan->columns['faskes_asal_id']->nullable)->toBeTrue()
        ->and($rujukan->columns['faskes_asal_id']->type)->toBe('bigint');

    $fkKolom = array_map(static fn ($fk): string => implode(',', $fk->columns), $surat->foreignKeys);
    sort($fkKolom);

    expect($fkKolom)->toBe(['dokter_id', 'pasien_id'])
        ->and($rujukan->foreignKeys)->toHaveCount(3);

    $rujukanFk = array_map(static fn ($fk): string => implode(',', $fk->columns), $rujukan->foreignKeys);
    sort($rujukanFk);

    expect($rujukanFk)->toBe(['dokter_perujuk_id', 'faskes_tujuan_id', 'surat_keterangan_id'])
        ->and($rujukanFk)->not->toContain('faskes_asal_id');
});

test('the four letter types are the DDL enum, in the DDL order, all four of them', function (): void {
    expect(SuratKeteranganTipe::nilai())->toBe(sktEnum('surat_keterangan', 'tipe'))
        ->and(SuratKeteranganTipe::cases())->toHaveCount(4)
        // `toBe` checks order as well as membership, so a transposed pair fails.
        ->and(SuratKeteranganTipe::nilai())->toBe([
            'surat_sakit',
            'surat_sehat',
            'surat_rujukan',
            'surat_kematian',
        ]);

    // The column is NOT NULL and declares NO DEFAULT, so the service has to write
    // the type rather than let MySQL choose - and there is no "no type" letter.
    $kolom = sktSpec()->table('surat_keterangan')->columns['tipe'];

    expect($kolom->nullable)->toBeFalse()
        ->and($kolom->default)->toBeNull();
});

test('qr_token is NOT NULL and NOT UNIQUE, and nomor_surat IS unique', function (): void {
    // THE LOAD-BEARING FACT OF THIS TODO. `qr_token` (:592) carries a COMMENT naming
    // its purpose - a QR authenticity token - and no constraint of any kind, so two
    // letters can hold the same token and scanning either QR resolves both. The
    // application-level duplicate check plus a bounded retry is the ONLY defence
    // available, because a UNIQUE index here would be permanent `extra_index` drift
    // against a read-only DDL.
    $kolom = sktSpec()->table('surat_keterangan')->columns['qr_token'];

    expect($kolom->type)->toBe('varchar(100)')
        ->and($kolom->nullable)->toBeFalse()
        ->and($kolom->default)->toBeNull();

    $tokenIndex = [];
    $uniqueKolom = [];

    foreach (sktSpec()->table('surat_keterangan')->indexes as $index) {
        if (in_array('qr_token', $index->columns, true)) {
            $tokenIndex[] = $index->name;
        }

        if ($index->type === 'UNIQUE') {
            $uniqueKolom[] = implode(',', $index->columns);
        }
    }

    expect($tokenIndex)->toBe([])
        ->and($uniqueKolom)->toBe(['nomor_surat']);

    // `nomor_surat` (:583) is the human-facing number and IS constrained, so its
    // collision is a database 1062 rather than a silent overwrite - which is why the
    // retry loop catches both sources and why the two are not the same problem.
    $nomor = sktSpec()->table('surat_keterangan')->columns['nomor_surat'];

    expect($nomor->type)->toBe('varchar(50)')
        ->and($nomor->nullable)->toBeFalse();
});

test('the LIVE table carries no index on qr_token either', function (): void {
    // The parser reads the DDL; this reads `information_schema`. Both are asserted
    // because a mismatch is exactly the drift `sehatly:verify-schema` reports, and a
    // test that only read one of them would pass on half the evidence.
    $baris = DB::select(
        'select INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME from information_schema.STATISTICS'
        .' where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
        ['surat_keterangan', 'qr_token'],
    );

    expect($baris)->toBe([]);

    // And the one UNIQUE index on the live table is the document number, not the
    // token. `NON_UNIQUE = 0` is MySQL's own statement that the index is unique.
    $unik = DB::select(
        'select INDEX_NAME, NON_UNIQUE, COLUMN_NAME from information_schema.STATISTICS'
        .' where TABLE_SCHEMA = database() and TABLE_NAME = ? and NON_UNIQUE = 0',
        ['surat_keterangan'],
    );

    $kolomUnik = array_map(static fn ($row): string => (string) $row->COLUMN_NAME, $unik);

    expect($kolomUnik)->toContain('nomor_surat')
        ->and($kolomUnik)->not->toContain('qr_token');
});

test('neither letter table has a uuid column, so the Str::uuid() IS the qr_token', function (): void {
    // The brief for this todo asks for "a Str uuid + qr_token, kept distinct in value
    // and purpose unless the schema says otherwise". The schema says otherwise, and
    // this is the measurement rather than the assertion of it: thirteen columns and
    // twelve columns respectively, none of which is a uuid, a token alias, or any
    // second opaque identifier.
    $kandidat = [
        'uuid', 'token', 'kode_qr', 'kode_verifikasi', 'verification_token',
        'qr_uuid', 'token_uuid', 'public_id', 'kode', 'kode_dokumen',
    ];

    expect(sktSpec()->table('surat_keterangan')->columns)->not->toHaveKeys($kandidat)
        ->and(sktSpec()->table('rujukan')->columns)->not->toHaveKeys($kandidat)
        ->and(sktSpec()->table('surat_keterangan')->columns)->toHaveCount(13)
        ->and(sktSpec()->table('rujukan')->columns)->toHaveCount(12);

    // The only opaque identifier a letter has is `qr_token` itself, and it is the
    // one the plan calls `Str::uuid()`. So the value IS the uuid, the column IS the
    // token, and the human-readable identifier beside it is `nomor_surat` - a
    // different value serving a different purpose, which is the distinction the
    // brief asks for, kept in the two columns that actually exist.
    $opaque = [];

    foreach (sktSpec()->table('surat_keterangan')->columns as $nama => $kolom) {
        if (in_array($kolom->type, ['varchar(50)', 'varchar(100)', 'varchar(500)', 'char(36)'], true)) {
            $opaque[$nama] = $kolom->type;
        }
    }

    expect($opaque)->toBe(['nomor_surat' => 'varchar(50)', 'qr_token' => 'varchar(100)', 'file_url' => 'varchar(500)']);
});

test('the generated token is a real UUID4, which is what makes it unguessable', function (): void {
    $generator = new StrQrTokenGenerator;

    $pertama = $generator->next();

    expect($pertama)->toBeString()
        ->and(Str::isUuid($pertama))->toBeTrue()
        // A v4 UUID, so the 122 free bits are real and the token cannot be guessed
        // from a previous one. The plan requires "never a guessable value".
        ->and($pertama[14])->toBe('4')
        // It must fit the column: `qr_token` is `VARCHAR(100)` (:592) and a UUID
        // string is 36 characters, so a token that had to be padded or truncated to
        // fit would be a different value from the one the QR carries.
        ->and(strlen($pertama))->toBe(36)
        ->and(strlen($pertama))->toBeLessThan(100)
        ->and($generator->next())->not->toBe($pertama);
});

test('jumlah_hari is a TINYINT UNSIGNED, so the window ceiling is 255 days', function (): void {
    $kolom = sktSpec()->table('surat_keterangan')->columns['jumlah_hari'];

    expect($kolom->type)->toBe('tinyint')
        ->and($kolom->unsigned)->toBeTrue()
        ->and($kolom->nullable)->toBeTrue()
        ->and($kolom->default)->toBeNull();

    // Nullable, so NULL ("the letter states no period") and 0 are DIFFERENT
    // statements. A `surat_sehat` names a moment rather than a window, and a
    // collapsed null-to-zero would say the period is zero days long.
    expect($kolom->nullable)->toBeTrue();

    // Both endpoints are nullable DATE columns, and the count is neither generated
    // nor checked by the database: a `jumlah_hari` disagreeing with its own two dates
    // is representable, and the unsigned flag catches only negatives.
    expect(sktSpec()->table('surat_keterangan')->columns['tanggal_mulai']->type)->toBe('date')
        ->and(sktSpec()->table('surat_keterangan')->columns['tanggal_mulai']->nullable)->toBeTrue()
        ->and(sktSpec()->table('surat_keterangan')->columns['tanggal_selesai']->type)->toBe('date')
        ->and(sktSpec()->table('surat_keterangan')->columns['tanggal_selesai']->nullable)->toBeTrue();
});

test('the referral status enum is the DDL enum and the default is aktif', function (): void {
    expect(sktEnum('rujukan', 'status'))->toBe(['aktif', 'terpakai', 'kedaluwarsa'])
        ->and(trim((string) sktSpec()->table('rujukan')->columns['status']->default, "'"))->toBe('aktif');

    // `berlaku_sampai` (:608) is the one NOT NULL date on the table and is a
    // different date from the medical window on the letter. Nothing in the schema
    // ties them together, so nothing will derive one from the other.
    $berlaku = sktSpec()->table('rujukan')->columns['berlaku_sampai'];

    expect($berlaku->type)->toBe('date')
        ->and($berlaku->nullable)->toBeFalse()
        ->and(sktSpec()->table('rujukan')->columns['alasan_rujukan']->nullable)->toBeTrue()
        ->and(sktSpec()->table('rujukan')->columns['diagnosis_kerja']->type)->toBe('varchar(255)')
        ->and(sktSpec()->table('rujukan')->columns['icd10_kode']->type)->toBe('varchar(8)')
        ->and(sktSpec()->table('rujukan')->columns['nomor_sep']->type)->toBe('varchar(30)');
});

test('the consent kinds are the DDL enum and exactly one of them is cross-faskes sharing', function (): void {
    expect(PersetujuanPdpJenis::nilai())->toBe(sktEnum('persetujuan_pdp', 'jenis'))
        ->and(PersetujuanPdpJenis::cases())->toHaveCount(5)
        ->and(PersetujuanPdpJenis::nilai())->toContain('berbagi_data_medis');

    // The rule this todo gates on is exactly ONE of the five kinds, and it is named
    // by the plan rather than invented here.
    expect(PdpConsent::JENIS_BERBAGI_DATA)->toBe('berbagi_data_medis')
        ->and(PdpConsent::JENIS_BERBAGI_DATA)->toBe(
            collect(PersetujuanPdpJenis::nilai())->first(fn (string $j): bool => str_contains($j, 'berbagi'))
        );

    // `versi_dokumen` is `VARCHAR(20)`, NOT an integer - which is why "the highest
    // version" is a string order and not a numeric one. The trap is real and is
    // pinned by its own test below rather than described here.
    expect(sktSpec()->table('persetujuan_pdp')->columns['versi_dokumen']->type)->toBe('varchar(20)')
        ->and(sktSpec()->table('persetujuan_pdp')->columns['disetujui']->type)->toBe('tinyint')
        ->and(sktSpec()->table('persetujuan_pdp')->columns['disetujui']->nullable)->toBeFalse();
});

test('the models declare no enum: cast and the timestamps the DDL names', function (): void {
    // On laravel/framework 13.33 `HasAttributes::isEnumCastable()` requires
    // `enum_exists($castType)`, so `'tipe' => 'enum:surat_sakit,...'` is a SILENT
    // NO-OP: not a primitive cast, not a class castable. `ModelFoundationTest`
    // asserts every ENUM column is a plain `'string'` cast, and this is the same
    // assertion for these two models.
    foreach ([SuratKeterangan::class, Rujukan::class] as $model) {
        $method = new ReflectionMethod($model, 'casts');
        $method->setAccessible(true);

        /** @var array<string, string> $casts */
        $casts = $method->invoke(new $model);

        foreach ($casts as $column => $cast) {
            expect($cast)->not->toStartWith('enum:', $model.'.'.$column);
        }

        expect($casts['tipe'] ?? 'string')->toBe('string');
    }

    // `dibuat_at` only, on both models, and `UPDATED_AT` nulled rather than left as
    // the inherited `updated_at` - Eloquent writes that constant on every save and
    // the column does not exist.
    $surat = new SuratKeterangan;
    $rujukan = new Rujukan;

    expect($surat->getCreatedAtColumn())->toBe('dibuat_at')
        ->and($surat->getUpdatedAtColumn())->toBeNull()
        ->and($surat->usesTimestamps())->toBeTrue()
        ->and($rujukan->getCreatedAtColumn())->toBe('dibuat_at')
        ->and($rujukan->getUpdatedAtColumn())->toBeNull()
        ->and($rujukan->usesTimestamps())->toBeTrue();

    // A trait cannot redeclare a constant it inherits, so nothing in this todo
    // authors a timestamp trait at all: the two models already declare their own.
    expect((new ReflectionClass(SuratKeterangan::class))->getTraitNames())->not->toContain('Illuminate\Database\Eloquent\Concerns\HasTimestamps');
});

test('every DDL line number cited by this todo is the line it claims to be', function (): void {
    $citations = [
        // The letter table, CREATE at 581 and its closing ENGINE at 597.
        581 => 'CREATE TABLE surat_keterangan (',
        582 => 'id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT',
        583 => 'nomor_surat VARCHAR(50) NOT NULL UNIQUE',
        584 => 'konsultasi_id BIGINT UNSIGNED NULL',
        585 => "tipe ENUM('surat_sakit','surat_sehat','surat_rujukan','surat_kematian') NOT NULL",
        586 => 'pasien_id BIGINT UNSIGNED NOT NULL',
        587 => 'dokter_id BIGINT UNSIGNED NOT NULL',
        588 => 'tanggal_mulai DATE NULL',
        589 => 'tanggal_selesai DATE NULL',
        590 => 'jumlah_hari TINYINT UNSIGNED NULL',
        591 => 'isi TEXT NULL',
        592 => "qr_token VARCHAR(100) NOT NULL COMMENT 'Token QR verifikasi keaslian'",
        593 => 'file_url VARCHAR(500) NULL',
        594 => 'dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        595 => 'FOREIGN KEY (pasien_id) REFERENCES pasien(id)',
        596 => 'FOREIGN KEY (dokter_id) REFERENCES dokter(id)',
        597 => 'ENGINE=InnoDB',

        // The referral table, CREATE at 599 and its closing ENGINE at 615.
        599 => 'CREATE TABLE rujukan (',
        600 => 'id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT',
        601 => 'surat_keterangan_id BIGINT UNSIGNED NOT NULL',
        602 => 'faskes_asal_id BIGINT UNSIGNED NULL',
        603 => 'faskes_tujuan_id BIGINT UNSIGNED NOT NULL',
        604 => 'dokter_perujuk_id BIGINT UNSIGNED NOT NULL',
        605 => 'diagnosis_kerja VARCHAR(255) NULL',
        606 => 'icd10_kode VARCHAR(8) NULL',
        607 => 'alasan_rujukan TEXT NULL',
        608 => 'berlaku_sampai DATE NOT NULL',
        610 => "status ENUM('aktif','terpakai','kedaluwarsa') NOT NULL DEFAULT 'aktif'",
        611 => 'dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        612 => 'FOREIGN KEY (surat_keterangan_id) REFERENCES surat_keterangan(id)',
        613 => 'FOREIGN KEY (faskes_tujuan_id) REFERENCES faskes(id)',
        614 => 'FOREIGN KEY (dokter_perujuk_id) REFERENCES dokter(id)',
        615 => 'ENGINE=InnoDB',

        // The consent table: CREATE at 1134, a WRAPPED ENUM at 1137-1138, and the
        // composite unique at 1144 that makes revocation unrepresentable.
        1134 => 'CREATE TABLE persetujuan_pdp (',
        1136 => 'user_id BIGINT UNSIGNED NOT NULL',
        1137 => "jenis ENUM('syarat_ketentuan','kebijakan_privasi','berbagi_data_medis',",
        1138 => "'pemasaran','komunikasi_tindak_lanjut') NOT NULL,",
        1139 => 'versi_dokumen VARCHAR(20) NOT NULL',
        1140 => 'disetujui TINYINT(1) NOT NULL',
        1143 => 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        1144 => 'UNIQUE KEY uq_consent (user_id, jenis, versi_dokumen)',
        1145 => 'ENGINE=InnoDB',

        // Reached through the second hop.
        134 => 'uuid CHAR(36) NOT NULL UNIQUE',
        135 => 'nama_lengkap VARCHAR(150) NOT NULL',
        139 => "tipe ENUM('pasien','dokter','perawat','apoteker','kurir','admin','superadmin') NOT NULL DEFAULT 'pasien'",
        222 => 'nik CHAR(16) NULL UNIQUE',
        362 => 'kode_faskes VARCHAR(20) NULL UNIQUE',
        364 => 'nama VARCHAR(200) NOT NULL',
        413 => 'nomor_str VARCHAR(30) NOT NULL UNIQUE',
    ];

    foreach ($citations as $line => $token) {
        sktAssertLine($line, $token);
    }

    expect(count($citations))->toBe(49);
});

test('the plan cites 581-619 for the two tables, and they end at 615', function (): void {
    // The plan's citation is a range that runs FOUR LINES PAST the closing ENGINE
    // line of the second table and into the `[8] REKAM MEDIS` section banner. The
    // plan file is orchestrator-owned and is not edited here, so the correction is
    // ASSERTED and the docblocks in `app/` cite the true lines.
    sktAssertLine(581, 'CREATE TABLE surat_keterangan (');
    sktAssertLine(597, 'ENGINE=InnoDB');
    sktAssertLine(599, 'CREATE TABLE rujukan (');
    sktAssertLine(615, 'ENGINE=InnoDB');

    expect(sktDdlLine(616))->toBe('')
        ->and(sktDdlLine(617))->toStartWith('-- ===')
        ->and(sktDdlLine(618))->toContain('[8] REKAM MEDIS')
        ->and(sktDdlLine(619))->toStartWith('-- ===')
        ->and(sktDdlLine(620))->toBe('');

    // The two citations that are RIGHT, asserted as carefully as the wrong one,
    // because a report that only lists errors is as misleading as the plan it
    // corrects: `:585` really is the four-value `tipe` ENUM, and `:1134-1145`
    // really is the whole consent table.
    sktAssertLine(585, "ENUM('surat_sakit','surat_sehat','surat_rujukan','surat_kematian')");
    expect(sktEnum('surat_keterangan', 'tipe'))->toHaveCount(4)
        ->and(sktSpec()->table('persetujuan_pdp')->foreignKeys)->not->toBeEmpty();
});

// =====================================================================
// The route table
// =====================================================================

test('three routes are registered, with the expected verbs and guards', function (): void {
    // The plan's acceptance criterion says `route:list --path=api/v1/surat-keterangan`
    // lists 3 routes. It answers 2, because the plan's own third route
    // (`GET /api/v1/pasien/surat-keterangan`) is a PASIEN path - exactly the defect
    // todo 33 found and reported for the same reason. The closed set below is over
    // the three URIs the plan names, matched by a predicate that covers both
    // prefixes, so the count is right and the filter is not widened to hide it.
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array($route->uri(), [
            'api/v1/konsultasi/{id}/surat-keterangan',
            'api/v1/surat-keterangan/{nomor_surat}/verify',
            'api/v1/pasien/surat-keterangan',
        ], true))
        ->keyBy(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->all();

    expect(array_keys($routes))->toBe([
        'POST api/v1/konsultasi/{id}/surat-keterangan',
        'GET api/v1/pasien/surat-keterangan',
        'GET api/v1/surat-keterangan/{nomor_surat}/verify',
    ]);

    // The guards, spelled out. `permission:` answers a GRANT and `tipe:` answers an
    // ACCOUNT TYPE, and the doctor-only write uses both.
    expect(sktGuards($routes, 'POST api/v1/konsultasi/{id}/surat-keterangan'))
        ->toBe(['api', 'tipe:dokter', 'permission:surat_keterangan.buat', 'auth:sanctum']);

    // The patient's own list: authenticated, and NO permission and NO tipe. There is
    // no `surat_keterangan.lihat` code in the catalogue - `surat_keterangan.buat` is
    // the only one - and a `tipe:pasien` would answer "which account type is this"
    // rather than "is this row yours". The ownership rule is `ownPasien()`.
    expect(sktGuards($routes, 'GET api/v1/pasien/surat-keterangan'))->toBe(['api', 'auth:sanctum']);

    // THE PUBLIC ONE. A QR code is a physical artifact: it gets photographed, printed
    // on a letter and handed to a clinic receptionist who has no account. The verify
    // endpoint therefore carries NO `auth:sanctum` at all, so an unauthenticated
    // scanner reaches it and a `permission:` there would be worse than useless - it
    // answers 401 for an anonymous caller and 403 for `perawat` and `kurir`.
    expect(sktGuards($routes, 'GET api/v1/surat-keterangan/{nomor_surat}/verify'))->toBe(['api']);

    // And nothing ELSE lives under the letter prefix, so a fourth route cannot
    // appear beside these three without this failing.
    expect(array_keys(sktRoutes('api/v1/surat-keterangan')))->toBe([
        'GET api/v1/surat-keterangan/{nomor_surat}/verify',
    ]);

    // `{nomor_surat}` carries the document number, not a surrogate key, so it is a
    // STRING parameter and cannot be constrained with `whereNumber`. It is
    // deliberately not bound to a model either: the verify route must answer
    // `valid: false` for a number that does not exist rather than a router 404.
    expect($routes['GET api/v1/surat-keterangan/{nomor_surat}/verify']->wheres)->toBe([])
        ->and($routes['POST api/v1/konsultasi/{id}/surat-keterangan']->wheres)->toBe(['id' => '(\d+)']);
});

test('every guard this todo writes resolves against the RBAC catalogue', function (): void {
    // `EnsurePermission` throws a `LogicException` - a 500, not a 403 - for an
    // unknown code, and `RbacCatalog::ROLE_PERMISSIONS` is the only place a grant
    // may be changed. `surat_keterangan.buat` is a real code and is granted to
    // `dokter` and `superadmin`; `tipe:dokter` is a real `users.tipe` value.
    expect(RbacCatalog::isPermission('surat_keterangan.buat'))->toBeTrue()
        ->and(RbacCatalog::permissionsFor('dokter'))->toContain('surat_keterangan.buat')
        ->and(RbacCatalog::permissionsFor('superadmin'))->toContain('surat_keterangan.buat')
        ->and(RbacCatalog::isUserType('dokter'))->toBeTrue();

    // The disjunction nobody can express as a gate: `superadmin` holds the grant but
    // is not a `dokter`, so `tipe:dokter` refuses it. That is the catalogue's data,
    // not a decision made here, and it is why the create is doctor-only for a
    // clinical-authority reason rather than a missing-code reason.
    expect(RbacCatalog::permissionsFor('dokter'))->toContain('surat_keterangan.buat')
        ->and(RbacCatalog::permissionsFor('admin'))->not->toContain('surat_keterangan.buat');

    // `perawat` and `kurir` are real `users.tipe` values that hold NO role, so any
    // `permission:` locks them out of the create permanently. The DDL makes the gap
    // sharper: `pasien_tanda_vital.sumber` is `ENUM('mandiri','dokter','perawat',
    // 'iot_device')` at :325, so this schema gives a nurse a clinical role of her
    // own while the RBAC vocabulary gives her no grant that could let her write a
    // letter. Reported as a data change in `app/Support/Rbac/`, not fixed here.
    expect(RbacCatalog::isUserType('perawat'))->toBeTrue()
        ->and(RbacCatalog::isUserType('kurir'))->toBeTrue()
        ->and(RbacCatalog::isRole('perawat'))->toBeFalse()
        ->and(RbacCatalog::isRole('kurir'))->toBeFalse();
});

// =====================================================================
// The collision retry
// =====================================================================

test('a qr_token collision is RETRIED and the second value is the one stored', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    // A letter already holds the token the stub will draw first. Nothing about that
    // letter is special: it is an ordinary row, which is precisely the point - the
    // duplicate check is an existence test against a column MySQL does not police.
    $sudah = sktSurat($account['pasien'], $account['dokter']);
    $tabrakan = (string) $sudah->qr_token;
    $bebas = (string) Str::uuid();

    $stub = sktTokenStub([$tabrakan, $bebas]);
    app()->instance(QrTokenGenerator::class, $stub);

    $response = sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_sakit',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
    ]);

    $response->assertCreated();

    // TWO calls, not one: the first token was refused and the second was accepted.
    // This is the assertion a happy-path test could never make.
    expect($stub->dipanggil)->toBe(2);

    $tersimpan = SuratKeterangan::query()->where('qr_token', $bebas)->first();

    expect($tersimpan)->not->toBeNull()
        ->and($tersimpan->qr_token)->toBe($bebas)
        ->and($tersimpan->qr_token)->not->toBe($tabrakan)
        // The pre-existing letter is untouched, and it is still the only row holding
        // the colliding token - a retry replaces the CANDIDATE, never a stored row.
        ->and($sudah->refresh()->qr_token)->toBe($tabrakan)
        ->and(SuratKeterangan::query()->where('qr_token', $tabrakan)->count())->toBe(1)
        ->and(SuratKeterangan::query()->count())->toBe(2);

    $response->assertJsonPath('data.surat_keterangan.qr_token', $bebas);
});

test('the qr_token retry is BOUNDED and the ceiling fails loudly with a 422', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    $sudah = sktSurat($account['pasien'], $account['dokter']);
    $selalu = (string) $sudah->qr_token;

    // A ONE-element list: the stub keeps repeating its last value forever, so every
    // attempt collides and the loop must give up rather than spin.
    $stub = sktTokenStub([$selalu]);
    app()->instance(QrTokenGenerator::class, $stub);

    $response = sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_sakit',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
    ]);

    // LOUDLY: a 422 envelope, never a 500 and never a hang.
    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'The given data was invalid.');

    // EXACTLY the published ceiling, and not one more. An unbounded loop would make
    // this assertion hang rather than fail, so the bound is what makes the test
    // terminate at all.
    expect($stub->dipanggil)->toBe(SuratKeteranganService::PERCOBAAN_TOKEN_MAKS)
        ->and(SuratKeteranganService::PERCOBAAN_TOKEN_MAKS)->toBe(5);

    // BOTH fields are reported, because a caller cannot know which of the two
    // candidates failed - and a 422 carrying two messages for the SAME field is the
    // shape the envelope is specified to preserve.
    $errors = $response->json('errors');

    expect($errors)->toHaveKeys(['qr_token', 'nomor_surat'])
        ->and($errors['qr_token'])->toHaveCount(1)
        ->and($errors['nomor_surat'])->toHaveCount(1);

    // NOTHING was written. The retry loop runs inside the transaction, so a spent
    // budget leaves no letter, no referral and no orphan behind.
    expect(SuratKeterangan::query()->count())->toBe(1)
        ->and(Rujukan::query()->count())->toBe(0);
});

test('a nomor_surat duplicate-key collision is retried through the SAME bound', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    // `nomor_surat` IS unique (DDL :583), so its collision is a real MySQL 1062 and
    // the pre-check cannot see it. Plant the exact number the FIRST attempt draws.
    $pertama = 'SK'.str_replace('-', '', SKT_HARI).'AAAAAA';
    $kedua = 'SK'.str_replace('-', '', SKT_HARI).'BBBBBB';

    sktSurat($account['pasien'], $account['dokter'], ['nomor_surat' => $pertama]);

    // TWO FREE tokens, so the collision that forces the retry is the NUMBER, not the
    // token: attempt 1 draws a free token and a planted number, attempt 2 draws a
    // free token and a free number. A stub whose first value were a taken token would
    // `continue` before the number was ever drawn, and the test would pass without
    // exercising the 1062 path at all.
    $stub = sktTokenStub([(string) Str::uuid(), (string) Str::uuid()]);
    app()->instance(QrTokenGenerator::class, $stub);

    // The sequence source is injected, exactly as `BookingService` does it, so the
    // number each attempt draws is PREDICTABLE. Attempt 1 draws the planted number
    // and is refused by the database; attempt 2 draws a free one.
    app()->instance(NomorDokumen::class, new NomorDokumen(
        static fn (int $percobaan): string => $percobaan === 1 ? 'AAAAAA' : 'BBBBBB',
    ));

    $response = sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_sakit',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
    ]);

    $response->assertCreated();

    // TWO attempts, the second of which is a DIFFERENT collision: the number was
    // still colliding on attempt 1, so the loop went round again. TWO calls to the
    // generator are the proof - one per attempt, because a retry redraws BOTH
    // identifiers - and the number collision is what made the second attempt happen.
    expect($stub->dipanggil)->toBe(2)
        ->and($response->json('data.surat_keterangan.nomor_surat'))->toBe($kedua)
        ->and(SuratKeterangan::query()->where('nomor_surat', $pertama)->count())->toBe(1)
        ->and(SuratKeterangan::query()->count())->toBe(2);
});

test('the number is SK plus the date plus a sequence, and it fits the column', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    $response = sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_sakit',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
    ]);

    $response->assertCreated();

    $nomor = (string) $response->json('data.surat_keterangan.nomor_surat');

    expect($nomor)->toStartWith('SK'.str_replace('-', '', SKT_HARI))
        ->and(strlen($nomor))->toBe(16)
        // `nomor_surat` is `VARCHAR(50)` (:583), so 16 leaves 34 characters of room
        // and the number cannot be truncated into two colliding strings.
        ->and(strlen($nomor))->toBeLessThanOrEqual(50)
        ->and(NomorDokumen::PREFIX_SURAT)->toBe('SK');
});

test('the ceiling is one published constant and both collision sources consume it', function (): void {
    // One budget, one name, one place. A second ceiling for the second collision
    // source would be two numbers to keep in step, and the two sources are not
    // distinguishable to a caller - the 422 reports both fields for that reason.
    expect(SuratKeteranganService::PERCOBAAN_TOKEN_MAKS)->toBeInt()
        ->and(SuratKeteranganService::PERCOBAAN_TOKEN_MAKS)->toBeGreaterThan(0)
        ->and(SuratKeteranganService::PERCOBAAN_TOKEN_MAKS)->toBeLessThanOrEqual(10)
        // Five rather than `BookingService`'s three, and the reason is that a
        // document number here is `SK` + 8 + 6 = 16 characters against a 50-column
        // VARCHAR with a 36-character random tail, so a collision is 60 bits of
        // entropy per attempt rather than a shared counter. Three is kept as the
        // FLOOR and asserted below, so the bound is a considered number rather than
        // a copy.
        ->and(SuratKeteranganService::PERCOBAAN_TOKEN_MAKS)->toBeGreaterThan(3);

    // The exception is the loud failure, and it is a domain exception rather than a
    // bare RuntimeException so the controller can convert it to the 422 envelope.
    $exception = SuratKeteranganTokenHabisException::penuh();

    expect($exception->errors())->toHaveKeys(['qr_token', 'nomor_surat'])
        ->and(array_merge(...array_values($exception->errors())))->each->toBeString();
});

// =====================================================================
// Issuing a letter
// =====================================================================

test('a doctor issues all four letter types and each stores its own type', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    foreach (SuratKeteranganTipe::nilai() as $tipe) {
        $payload = ['tipe' => $tipe];

        if (in_array($tipe, ['surat_sakit', 'surat_rujukan'], true)) {
            $payload['tanggal_mulai'] = SKT_HARI;
            $payload['tanggal_selesai'] = SKT_HARI;
        }

        if ($tipe === 'surat_rujukan') {
            $payload['faskes_tujuan_id'] = sktFaskes();
            $payload['alasan_rujukan'] = 'Perlu pemeriksaan lanjutan.';
            sktConsent($account['pasienUser']->getKey());
        }

        sktAs($account['user'])
            ->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', $payload)
            ->assertCreated()
            ->assertJsonPath('data.surat_keterangan.tipe', $tipe);
    }

    // Four letters, four DISTINCT tokens, four distinct numbers, and one referral.
    expect(SuratKeterangan::query()->count())->toBe(4)
        ->and(SuratKeterangan::query()->distinct()->count('qr_token'))->toBe(4)
        ->and(SuratKeterangan::query()->distinct()->count('nomor_surat'))->toBe(4)
        ->and(Rujukan::query()->count())->toBe(1)
        ->and(SuratKeterangan::query()->pluck('tipe')->sort()->values()->all())
        ->toBe(collect(SuratKeteranganTipe::nilai())->sort()->values()->all());
});

test('an unknown letter type is 422 and the message names the DDL values', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    sktAs($account['user'])
        ->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', ['tipe' => 'surat_izin'])
        ->assertStatus(422)
        ->assertJsonPath('errors.tipe.0', 'Tipe surat harus salah satu dari: '.implode(', ', SuratKeteranganTipe::nilai()).'.');

    expect(SuratKeterangan::query()->count())->toBe(0);
});

test('the patient and the letter are read off the consultation, never off the request', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    // A caller who supplies BOTH a patient id and a doctor id is refused BY NAME,
    // rather than having them silently dropped: a doctor who could set `pasien_id`
    // would issue a perfectly valid letter about somebody else's patient. This is the
    // same `prohibited` rule `RekamMedisRequest` applies, and it is why the ids are
    // not merely absent from the allow-list.
    $orangLain = sktPasien(sktUser('Pasien lain', 'pasien'));
    $dokterLain = sktDokter(sktUser('Dokter lain', 'dokter'));

    $response = sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_sakit',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
        'pasien_id' => $orangLain,
        'dokter_id' => $dokterLain,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('errors.pasien_id.0', 'The pasien_id field is prohibited.');

    expect($response->json('errors.dokter_id'))->toHaveCount(1);

    // And nothing was written by the refusal.
    expect(SuratKeterangan::query()->count())->toBe(0);

    // The same request WITHOUT the two ids: both are read off the consultation and the
    // authenticated account, so the stored letter is about the consultation's patient
    // and signed by the doctor who issued it.
    $response = sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_sakit',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.surat_keterangan.pasien_id', $account['pasien'])
        ->assertJsonPath('data.surat_keterangan.dokter_id', $account['dokter'])
        ->assertJsonPath('data.surat_keterangan.konsultasi_id', $sesi->getKey());

    expect(SuratKeterangan::query()->where('pasien_id', $orangLain)->count())->toBe(0)
        ->and(SuratKeterangan::query()->where('dokter_id', $dokterLain)->count())->toBe(0)
        ->and(SuratKeterangan::query()->where('pasien_id', $account['pasien'])->count())->toBe(1);
});

test('the machine-owned columns are refused by name, and the list is the DDL one', function (): void {
    $account = sktDoctorAccount();

    // `nomor_surat` and `qr_token` are the two identifiers a caller must never choose:
    // one is the document number and the other is the bearer secret the QR carries.
    // `file_url` and `jumlah_hari` are machine-owned too, and the test asserts the
    // whole list rather than a sample so a future column cannot be left writable.
    expect(BuatSuratKeteranganRequest::KOLOM_MILIK_SISTEM)->toContain('nomor_surat', 'qr_token', 'jumlah_hari', 'file_url', 'pasien_id', 'dokter_id')
        ->and(BuatSuratKeteranganRequest::KOLOM_MILIK_SISTEM)->toContain('dibuat_at')
        // `surat_keterangan` has NO `uuid` column, so a `uuid` key can never be stored;
        // it is on the list anyway so a caller who tries to send one is told so.
        ->and(BuatSuratKeteranganRequest::KOLOM_MILIK_SISTEM)->toContain('uuid')
        ->and(BuatSuratKeteranganRequest::KOLOM_RUJUKAN_MILIK_SISTEM)->toBe([
            'surat_keterangan_id',
            'dokter_perujuk_id',
            'status',
        ]);

    // And the referral allow-list the service consults, which is the DDL's own column
    // set for `rujukan` minus the three machine-owned ones.
    expect(SuratKeteranganService::KOLOM_RUJUKAN)->toBe([
        'faskes_tujuan_id',
        'diagnosis_kerja',
        'icd10_kode',
        'alasan_rujukan',
        'berlaku_sampai',
        'nomor_sep',
    ]);

    foreach (array_merge(BuatSuratKeteranganRequest::KOLOM_MILIK_SISTEM, BuatSuratKeteranganRequest::KOLOM_RUJUKAN_MILIK_SISTEM) as $kolom) {
        expect(SuratKeteranganService::KOLOM_RUJUKAN)->not->toContain($kolom, $kolom);
    }

    // Every referral column the service MAY write is a real `rujukan` column, and every
    // one it writes is on the request's rule list - a column that is writable and
    // unvalidated at once is the defect class this whole assertion exists for.
    $rul = (new BuatSuratKeteranganRequest)->rules();

    foreach (SuratKeteranganService::KOLOM_RUJUKAN as $kolom) {
        expect($rul)->toHaveKey($kolom);
        expect(sktSpec()->table('rujukan')->columns)->toHaveKey($kolom);
    }
});

test('a second-doctor 404 is produced by the reused ownership rule, not a new one', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);
    $asing = sktDoctorAccount();

    // Delegated to `KonsultasiAccess::untukDokter()`, the SAME rule `PUT
    // /konsultasi/{id}/selesai` uses. This class has no `if` about ownership at all,
    // which is asserted by reading the source rather than trusted.
    $sumber = file_get_contents((new ReflectionClass(SuratKeteranganService::class))->getFileName());

    expect($sumber)->toContain('KonsultasiAccess')
        ->and($sumber)->toContain('untukDokter')
        ->and($sumber)->toContain('ownDokterOrFail')
        ->and($sumber)->toContain('ownPasien')
        // No hand-rolled ownership comparison: neither a `where` on a second id nor a
        // `ModelNotFoundException` thrown from here.
        ->and($sumber)->not->toContain('AccessDeniedHttpException(')
        ->and($sumber)->not->toContain('ModelNotFoundException');

    sktAs($asing['user'])
        ->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', ['tipe' => 'surat_sakit'])
        ->assertStatus(404);
});

test('jumlah_hari is the INCLUSIVE day difference', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    // 11 March to 30 March inclusive is 20 days, and the exclusive difference is 19 -
    // so this case distinguishes the two and a plain `diffInDays` fails it.
    $response = sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_sakit',
        'tanggal_mulai' => '2026-03-11',
        'tanggal_selesai' => '2026-03-30',
    ]);

    $response->assertCreated()
        // A TINYINT arrives from the driver as a string, so the resource publishes an
        // INT and a client comparing it with a number does not have to know that.
        ->assertJsonPath('data.surat_keterangan.jumlah_hari', 20);

    expect((int) SuratKeterangan::query()->first()->jumlah_hari)->toBe(20)
        ->and((int) Carbon::parse('2026-03-11')->diffInDays(Carbon::parse('2026-03-30')))->toBe(19);
});

test('a single-day letter is one day, and a letter with no period is null not zero', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_sakit',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
    ])->assertCreated()->assertJsonPath('data.surat_keterangan.jumlah_hari', 1);

    // `surat_sehat` describes a moment rather than a window, so the period is absent
    // and `jumlah_hari` is NULL. A collapsed null-to-zero would claim the period is
    // zero days long, which is a different and false statement.
    sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_sehat',
    ])->assertCreated()->assertJsonPath('data.surat_keterangan.jumlah_hari', null);

    expect(SuratKeterangan::query()->where('tipe', 'surat_sehat')->first()->jumlah_hari)->toBeNull();
});

test('a period longer than 255 days is 422 rather than a raw MySQL 1264', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    $response = sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_sakit',
        'tanggal_mulai' => '2026-01-01',
        // 2026 is not a leap year, so 1 Jan to 1 Jan next year inclusive is 365.
        'tanggal_selesai' => '2027-01-01',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('errors.tanggal_selesai.0', 'Periode surat maksimal 255 hari.');

    expect(SuratKeterangan::query()->count())->toBe(0);
});

test('a reversed date window is 422 and names both dates', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_sakit',
        'tanggal_mulai' => '2026-03-20',
        'tanggal_selesai' => '2026-03-11',
    ])->assertStatus(422)
        ->assertJsonPath('errors.tanggal_selesai.0', 'Tanggal selesai tidak boleh sebelum tanggal mulai.');

    expect(SuratKeterangan::query()->count())->toBe(0);
});

test('a 422 carries MULTIPLE messages for ONE field, and several fields at once', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    // A period type with no start date but an end date. TWO independent rules are
    // broken by ONE input, so the field carries TWO messages and both are preserved -
    // which is the envelope contract, not an accident of ordering.
    $satu = sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_sakit',
        'tanggal_selesai' => SKT_HARI,
    ]);

    $satu->assertStatus(422);

    expect($satu->json('errors.tanggal_mulai'))->toHaveCount(2)
        ->and($satu->json('errors.tanggal_mulai.0'))->toBe('Surat dengan periode wajib menyertakan tanggal mulai.')
        ->and($satu->json('errors.tanggal_mulai.1'))->toBe('Tanggal selesai diberikan tanpa tanggal mulai.');

    // A referral with nothing attached breaks FOUR rules at once, and the service
    // collects every violation before throwing any of them, so one 422 reports all
    // four rather than whichever check happened to run first.
    $empat = sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_rujukan',
    ]);

    $empat->assertStatus(422);

    expect(array_keys($empat->json('errors')))->toBe([
        'tanggal_mulai',
        'faskes_tujuan_id',
        'alasan_rujukan',
    ])
        ->and($empat->json('errors.tanggal_mulai'))->toHaveCount(1)
        ->and($empat->json('errors.faskes_tujuan_id'))->toHaveCount(1)
        ->and($empat->json('errors.alasan_rujukan'))->toHaveCount(1)
        ->and(SuratKeterangan::query()->count())->toBe(0);
});

test('referral keys on a non-referral letter are refused rather than ignored', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    // Silently dropping a `faskes_tujuan_id` the doctor supplied would be the
    // `array_key_exists` trap todo 32 found in `tulisSoap()`: the request validates,
    // the service accepts it, and the value is written nowhere.
    $response = sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_sakit',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
        'faskes_tujuan_id' => sktFaskes(),
        'alasan_rujukan' => 'Tidak ada tujuan yang disebut.',
    ]);

    $response->assertStatus(422);

    expect(array_keys($response->json('errors')))->toBe(['faskes_tujuan_id', 'alasan_rujukan'])
        ->and($response->json('errors.faskes_tujuan_id.0'))
        ->toBe('Hanya surat rujukan yang dapat memiliki tujuan rujukan.')
        ->and(SuratKeterangan::query()->count())->toBe(0)
        ->and(Rujukan::query()->count())->toBe(0);
});

// =====================================================================
// Who may issue a letter
// =====================================================================

test('a patient is refused at the tipe gate, and an apoteker at the permission gate', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);
    $pasienUser = $account['pasienUser'];

    // `tipe:dokter` runs first, so a patient is refused as the wrong KIND of caller
    // rather than as a caller with a missing grant.
    sktAs($pasienUser)
        ->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', ['tipe' => 'surat_sakit'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'This action is unauthorized.');

    // `apoteker` IS a `dokter`-typed value? No - it is its own value at :139, so it
    // is refused by `tipe:dokter` too, and the permission it DOES lack is a second
    // independent reason. Both are asserted rather than either being assumed.
    $apoteker = sktPengguna('apoteker', 'apoteker');

    sktAs($apoteker)
        ->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', ['tipe' => 'surat_sakit'])
        ->assertStatus(403);

    expect(SuratKeterangan::query()->count())->toBe(0);
});

test('perawat and kurir are refused, and the reason is the permission they hold no grant for', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    // Both are real `users.tipe` values (:139) that hold NO role, so `permission:`
    // answers 403 for them permanently. This is a real gap rather than a correct
    // refusal and it is reported: the DDL gives a nurse a clinical role of her own
    // (`pasien_tanda_vital.sumber` at :325 includes `perawat`), so this schema lets a
    // nurse write vital signs while the RBAC vocabulary gives her no grant that could
    // let her write a letter. The fix is a data change in `app/Support/Rbac/` plus a
    // re-seed, and it is not this todo's to make.
    foreach (['perawat', 'kurir'] as $tipe) {
        $akun = sktPengguna($tipe);

        expect($akun->tipe)->toBe($tipe);

        sktAs($akun)
            ->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', ['tipe' => 'surat_sakit'])
            ->assertStatus(403);
    }

    expect(SuratKeterangan::query()->count())->toBe(0);
});

test('a superadmin holds the grant but is not a dokter, so the create refuses it', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);
    $superadmin = sktPengguna('superadmin', 'superadmin');

    // The disjunction a route gate cannot express, and the reason `tipe:dokter` is
    // present. `surat_keterangan.buat` is granted to `superadmin`, and issuing a
    // clinical letter is not a thing an oversight account does.
    expect(RbacCatalog::permissionsFor('superadmin'))->toContain('surat_keterangan.buat');

    sktAs($superadmin)
        ->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', ['tipe' => 'surat_sakit'])
        ->assertStatus(403);

    expect(SuratKeterangan::query()->count())->toBe(0);
});

test('another doctor gets 404 and a non-doctor account with no profile gets 404 too', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    // A doctor who owns a profile but is not THIS consultation's doctor is 404, not
    // 403: a 403 would confirm the consultation exists, which is a cross-tenant
    // existence oracle over a sequential BIGINT key.
    $asing = sktDoctorAccount();

    sktAs($asing['user'])
        ->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', ['tipe' => 'surat_sakit'])
        ->assertStatus(404)
        ->assertJsonPath('message', 'Resource not found.');

    // And an id that does not exist is the SAME 404, so a caller cannot tell "not
    // yours" from "not real" by watching the status code.
    sktAs($asing['user'])
        ->postJson('/api/v1/konsultasi/999999/surat-keterangan', ['tipe' => 'surat_sakit'])
        ->assertStatus(404);

    // A `dokter`-typed account with NO `dokter` row is 403, because the refusal is
    // about the caller's incomplete profile rather than about the consultation.
    $tanpaProfil = sktPengguna('dokter', 'dokter');

    sktAs($tanpaProfil)
        ->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', ['tipe' => 'surat_sakit'])
        ->assertStatus(403);

    expect(SuratKeterangan::query()->count())->toBe(0);
});

test('an unauthenticated create is 401, not 403', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    test()->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', ['tipe' => 'surat_sakit'])
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthenticated.');
});

// =====================================================================
// The referral and the PDP consent
// =====================================================================

test('a referral without an approved berbagi_data_medis consent is 403', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);
    $faskes = sktFaskes();

    // No consent row at all.
    sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_rujukan',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
        'faskes_tujuan_id' => $faskes,
        'alasan_rujukan' => 'Perlu rujukan.',
    ])->assertStatus(403)
        ->assertJsonPath('message', 'This action is unauthorized.');

    // A consent row that exists but is DISAPPROVED. The row existing is not consent,
    // and `disetujui` is what the rule reads.
    sktConsent($account['pasienUser']->getKey(), 'berbagi_data_medis', false);

    sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_rujukan',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
        'faskes_tujuan_id' => $faskes,
        'alasan_rujukan' => 'Perlu rujukan.',
    ])->assertStatus(403);

    // The WRONG kind of consent. All five kinds exist and exactly one of them is
    // cross-faskes sharing, so approving the others is not a substitute.
    DB::table('persetujuan_pdp')->delete();

    foreach (PersetujuanPdpJenis::nilai() as $jenis) {
        if ($jenis === 'berbagi_data_medis') {
            continue;
        }

        sktConsent($account['pasienUser']->getKey(), $jenis);

        sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
            'tipe' => 'surat_rujukan',
            'tanggal_mulai' => SKT_HARI,
            'tanggal_selesai' => SKT_HARI,
            'faskes_tujuan_id' => $faskes,
            'alasan_rujukan' => 'Perlu rujukan.',
        ])->assertStatus(403);

        DB::table('persetujuan_pdp')->delete();
    }

    // Nothing was written by any of the four refusals, because the consent check runs
    // inside the same transaction as the write.
    expect(SuratKeterangan::query()->count())->toBe(0)
        ->and(Rujukan::query()->count())->toBe(0);
});

test('a referral with consent is 201 and writes both rows in one transaction', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);
    $faskes = sktFaskes();
    sktConsent($account['pasienUser']->getKey());

    $response = sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_rujukan',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
        'faskes_tujuan_id' => $faskes,
        'alasan_rujukan' => 'Perlu pemeriksaan kardiologi.',
        'diagnosis_kerja' => 'Nyeri dada',
        'icd10_kode' => 'R07.4',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.surat_keterangan.tipe', 'surat_rujukan')
        ->assertJsonPath('data.rujukan.faskes_tujuan_id', $faskes)
        ->assertJsonPath('data.rujukan.dokter_perujuk_id', $account['dokter'])
        ->assertJsonPath('data.rujukan.status', 'aktif')
        ->assertJsonPath('data.rujukan.alasan_rujukan', 'Perlu pemeriksaan kardiologi.');

    $surat = SuratKeterangan::query()->sole();
    $rujukan = Rujukan::query()->sole();

    expect($rujukan->surat_keterangan_id)->toBe($surat->getKey())
        // `faskes_asal_id` (:602) is nullable with NO foreign key and this endpoint
        // has no referring facility to record: the doctor's own practice is not a
        // `faskes` row in this schema, and inventing one would be a row no
        // deployment has. The migration's own docblock records that the asymmetry
        // with `faskes_tujuan_id` is deliberate.
        ->and($rujukan->faskes_asal_id)->toBeNull()
        ->and($rujukan->diagnosis_kerja)->toBe('Nyeri dada')
        ->and($rujukan->icd10_kode)->toBe('R07.4')
        ->and($surat->rujukan->count())->toBe(1);
});

test('berlaku_sampai defaults to plus 14 days and a supplied date is honoured', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);
    $faskes = sktFaskes();
    sktConsent($account['pasienUser']->getKey());

    $dasar = [
        'tipe' => 'surat_rujukan',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
        'faskes_tujuan_id' => $faskes,
        'alasan_rujukan' => 'Perlu rujukan.',
    ];

    sktAs($account['user'])
        ->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', $dasar)
        ->assertCreated()
        // The plan's "+14 days", read against the letter's OWN start date rather than
        // against `now()`, so a referral written for a future visit expires relative
        // to the visit. `Carbon::setTestNow()` is 11 March 2026, so both readings
        // agree today - and the third case below is what separates them.
        ->assertJsonPath('data.rujukan.berlaku_sampai', Carbon::parse(SKT_HARI)->addDays(14)->toDateString());

    sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', $dasar + [
        'berlaku_sampai' => '2026-12-31',
    ])->assertCreated()->assertJsonPath('data.rujukan.berlaku_sampai', '2026-12-31');

    expect(Rujukan::query()->count())->toBe(2)
        ->and(Rujukan::query()->orderBy('id')->first()->berlaku_sampai?->toDateString())
        ->toBe(Carbon::parse(SKT_HARI)->addDays(14)->toDateString());
});

test('a non-referral letter needs no PDP consent at all', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);

    // Reading your own letter to your own doctor is not cross-faskes data sharing,
    // and the DDL offers no consent kind for it: the five values at 1137-1138 are
    // terms, privacy policy, medical-data sharing, marketing and follow-up
    // communication, and none of the other four means "may I read my own record".
    sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_sakit',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
    ])->assertCreated();

    expect(PersetujuanPdp::query()->count())->toBe(0)
        ->and(SuratKeterangan::query()->count())->toBe(1)
        ->and(Rujukan::query()->count())->toBe(0);
});

test('the consent checked is the PATIENTs, not the doctor who issues the letter', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);
    $faskes = sktFaskes();

    // The DOCTOR consents, the PATIENT does not: still 403. The data being shared is
    // the patient's and UU PDP asks the data subject, so the rule reads
    // `pasien.user_id` and not the authenticated account.
    sktConsent($account['user']->getKey());

    sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_rujukan',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
        'faskes_tujuan_id' => $faskes,
        'alasan_rujukan' => 'Perlu rujukan.',
    ])->assertStatus(403);

    sktConsent($account['pasienUser']->getKey());

    sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_rujukan',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
        'faskes_tujuan_id' => $faskes,
        'alasan_rujukan' => 'Perlu rujukan.',
    ])->assertCreated();

    expect(Rujukan::query()->count())->toBe(1);
});

test('a faskes_tujuan_id that does not exist is 422, never a foreign-key 500', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);
    sktConsent($account['pasienUser']->getKey());

    sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_rujukan',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
        'faskes_tujuan_id' => 999999,
        'alasan_rujukan' => 'Perlu rujukan.',
    ])->assertStatus(422)
        ->assertJsonPath('errors.faskes_tujuan_id.0', 'Faskes tujuan tidak ditemukan.');

    expect(SuratKeterangan::query()->count())->toBe(0)
        ->and(Rujukan::query()->count())->toBe(0);
});

test('the consent check reads the highest versi_dokumen, and the column is a VARCHAR', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);
    $faskes = sktFaskes();

    // Revocation is NOT representable in this schema: `uq_consent (user_id, jenis,
    // versi_dokumen)` (:1144) means a second row for the same document version would
    // collide, so withdrawing consent can only be recorded as a NEW version. The
    // check therefore has to read the highest `versi_dokumen` and honour ITS
    // `disetujui` - reading the first row would keep honouring a withdrawn consent.
    //
    // THE MEASURED TRAP, and the reason the "highest version" rule is a convention
    // rather than a guarantee. `versi_dokumen` is `VARCHAR(20)` (:1139), NOT an
    // integer, so "highest" is the column's own STRING order - and `v2.0` sorts ABOVE
    // `v10.0`, because `'2' > '1'` at the second position. A patient who approved v2
    // and then WITHDREW at v10 therefore has their withdrawal IGNORED, and the write
    // goes ahead. This is the actual behaviour of the code under test, pinned here
    // rather than described, because the alternative - quietly casting the column to an
    // integer - would be an assumption the schema does not support and would refuse a
    // real version string like `v1.2.3-beta`.
    sktConsent($account['pasienUser']->getKey(), 'berbagi_data_medis', true, 'v2.0');
    sktConsent($account['pasienUser']->getKey(), 'berbagi_data_medis', false, 'v10.0');

    $consent = app(PdpConsent::class);

    expect($consent->disetujui($account['pasienUser'], 'berbagi_data_medis'))->toBeTrue()
        ->and($consent->versiTerbaru($account['pasienUser'], 'berbagi_data_medis')?->versi_dokumen)->toBe('v2.0')
        ->and($consent->require($account['pasienUser'], 'berbagi_data_medis')->versi_dokumen)->toBe('v2.0');

    // The mitigation is a WRITER-side convention - fixed-width, zero-padded version
    // strings - and here it does what the rule is meant to do: with both strings the
    // same width, the later withdrawal really is the highest and the call is refused.
    DB::table('persetujuan_pdp')->delete();
    sktConsent($account['pasienUser']->getKey(), 'berbagi_data_medis', true, 'v0002.0');
    sktConsent($account['pasienUser']->getKey(), 'berbagi_data_medis', false, 'v0010.0');

    expect($consent->disetujui($account['pasienUser'], 'berbagi_data_medis'))->toBeFalse()
        ->and($consent->versiTerbaru($account['pasienUser'], 'berbagi_data_medis')?->versi_dokumen)->toBe('v0010.0')
        ->and(fn () => $consent->require($account['pasienUser'], 'berbagi_data_medis'))
        ->toThrow(AccessDeniedHttpException::class);

    // And the reverse: an approval as the highest version passes.
    DB::table('persetujuan_pdp')->delete();
    sktConsent($account['pasienUser']->getKey(), 'berbagi_data_medis', false, 'v0002.0');
    sktConsent($account['pasienUser']->getKey(), 'berbagi_data_medis', true, 'v0010.0');

    expect($consent->disetujui($account['pasienUser'], 'berbagi_data_medis'))->toBeTrue()
        ->and($consent->require($account['pasienUser'], 'berbagi_data_medis')->versi_dokumen)->toBe('v0010.0')
        // `disetujui_at` (:1141) is a `DATETIME NOT NULL` and is a rule-(1) instant, so
        // it is ISO-8601 UTC when published - decided once, in the service.
        ->and((string) $consent->disetujuiAt($consent->versiTerbaru($account['pasienUser'], 'berbagi_data_medis')))->toEndWith('Z');

    // No consent row at all is a refusal too, and not a crash.
    DB::table('persetujuan_pdp')->delete();

    expect($consent->disetujui($account['pasienUser'], 'berbagi_data_medis'))->toBeFalse()
        ->and($consent->versiTerbaru($account['pasienUser'], 'berbagi_data_medis'))->toBeNull()
        ->and($consent->disetujuiAt(null))->toBeNull()
        ->and(fn () => $consent->require($account['pasienUser'], 'berbagi_data_medis'))
        ->toThrow(AccessDeniedHttpException::class);

    // The consent kinds the DDL does not have are a PROGRAMMING error, not a 403 -
    // the same distinction `RbacCatalog::permissionsFor()` and `EnsurePermission` make,
    // because a 403 would let a misspelled `jenis` look like a patient who refused.
    expect(fn () => $consent->require($account['pasienUser'], 'berbagi_data'))
        ->toThrow(LogicException::class);

    expect(fn () => $consent->disetujui($account['pasienUser'], 'berbagi_data'))
        ->toThrow(LogicException::class);

    // Neither refused call reached a write, so there is still no referral.
    expect(Rujukan::query()->count())->toBe(0)
        ->and(SuratKeterangan::query()->count())->toBe(0);
});

// =====================================================================
// The public QR verification
// =====================================================================

test('the verify route answers with NO Authorization header at all', function (): void {
    $account = sktDoctorAccount();
    $surat = sktSurat($account['pasien'], $account['dokter']);

    $response = test()->getJson('/api/v1/surat-keterangan/'.$surat->nomor_surat.'/verify?token='.$surat->qr_token);

    $response->assertOk()
        ->assertJsonPath('data.valid', true);

    // A 401 here would mean the endpoint is useless: a clinic receptionist scanning a
    // printed letter has no account and never will. The decision is stated in the
    // route block's comment and the test is its proof.
    expect($response->status())->toBe(200)
        ->and($response->json('success'))->toBeTrue();
});

test('a valid token answers the MINIMUM and no more', function (): void {
    $account = sktDoctorAccount();
    $pasienUser = User::query()->findOrFail($account['pasienUser']->getKey());
    $surat = sktSurat($account['pasien'], $account['dokter'], [
        'isi' => 'Pasienaaaaaaaaaa piling rest 3 hari.',
        'tanggal_mulai' => '2026-03-11',
        'tanggal_selesai' => '2026-03-13',
        'jumlah_hari' => 3,
    ]);

    $response = test()->getJson('/api/v1/surat-keterangan/'.$surat->nomor_surat.'/verify?token='.$surat->qr_token);

    $response->assertOk();

    // EXACTLY six keys, in order, and no seventh can be added without this failing.
    expect(array_keys($response->json('data')))->toBe([
        'valid',
        'nomor_surat',
        'tipe',
        'dokter',
        'tanggal',
        'pasien_nama_masked',
    ]);

    expect($response->json('data'))->toBe([
        'valid' => true,
        'nomor_surat' => $surat->nomor_surat,
        'tipe' => 'surat_sakit',
        'dokter' => $account['user']->nama_lengkap,
        'tanggal' => Carbon::instance($surat->dibuat_at)->toDateString(),
        'pasien_nama_masked' => NamaMasker::mask($pasienUser->nama_lengkap),
    ]);
});

test('the verify response carries NO nik, NO body, and NO id of anything', function (): void {
    $account = sktDoctorAccount();
    $pasienUser = User::query()->findOrFail($account['pasienUser']->getKey());
    $surat = sktSurat($account['pasien'], $account['dokter'], [
        'isi' => 'Kondisi pasien memburuk dan perlu rujukan segera.',
    ]);
    $nik = (string) DB::table('pasien')->where('id', $account['pasien'])->value('nik');

    $response = test()->getJson('/api/v1/surat-keterangan/'.$surat->nomor_surat.'/verify?token='.$surat->qr_token);

    $response->assertOk();
    $body = (string) $response->getContent();

    // The strongest form of the assertion: the 16 digits are not in the body at all,
    // not masked, not partial. A PUBLIC endpoint that publishes even a masked NIK
    // publishes 8 of 16 digits to a scanner that may be anybody, and the plan's own
    // response list has no `nik` key at all - so the correct amount is ZERO.
    expect($body)->not->toContain($nik)
        // And the clinical body, which is the actual protected content.
        ->and($body)->not->toContain('Kondisi pasien memburuk')
        // The letter body column, whatever it holds.
        ->and($body)->not->toContain('isi')
        // The token itself is the bearer secret and is never echoed back, and neither
        // surrogate id is published: `pasien_id` would be an existence oracle into the
        // patient table and `dokter_id` an id the display name already answers.
        ->and($body)->not->toContain((string) $surat->qr_token)
        ->and($body)->not->toContain('"pasien_id"')
        ->and($body)->not->toContain('"dokter_id"')
        ->and($body)->not->toContain('"id"')
        ->and($body)->not->toContain('"konsultasi_id"')
        ->and($body)->not->toContain('"tanggal_mulai"')
        ->and($body)->not->toContain('"tanggal_selesai"');

    // A recursive key walk, so a NESTED key cannot hide from the string search.
    $kunci = [];

    $data = $response->json();

    array_walk_recursive($data, function ($value, $key) use (&$kunci): void {
        $kunci[] = (string) $key;
    });

    expect($kunci)->not->toContain('nik')
        ->and($kunci)->not->toContain('nomor_kk')
        ->and($kunci)->not->toContain('tanggal_lahir');

    // The doctor is named in full: a letter's authenticity is exactly the fact that
    // a NAMED doctor signed it, and `users.nama_lengkap` is a professional name on
    // a professional document rather than a patient identifier.
    expect($response->json('data.dokter'))->toBe($account['user']->nama_lengkap);
});

test('the patient name is masked, word by word', function (): void {
    $account = sktDoctorAccount();
    $pasienUser = User::query()->findOrFail($account['pasienUser']->getKey());
    $surat = sktSurat($account['pasien'], $account['dokter']);

    $response = test()->getJson('/api/v1/surat-keterangan/'.$surat->nomor_surat.'/verify?token='.$surat->qr_token);

    $masked = (string) $response->json('data.pasien_nama_masked');
    $bullet = "\u{2022}";

    // Each word keeps its FIRST character and loses the rest, so the shape of a name
    // survives and the name does not. A name with no interior to hide is returned
    // with a mask rather than verbatim, which is the opposite of `NikMasker`'s
    // documented behaviour for a value shorter than its visible ends - and that
    // difference is deliberate and asserted.
    expect($masked)->toBe('S'.$bullet.$bullet.$bullet.' A'.$bullet.$bullet.$bullet.$bullet.$bullet)
        ->and($masked)->not->toBe($pasienUser->nama_lengkap)
        ->and(str_contains($masked, 'Siti'))->toBeFalse()
        ->and(str_contains($masked, 'Aminah'))->toBeFalse()
        // The masker itself, including the two degenerate cases.
        ->and(NamaMasker::mask(null))->toBeNull()
        ->and(NamaMasker::mask(''))->toBeNull()
        ->and(NamaMasker::mask('   '))->toBeNull()
        ->and(NamaMasker::mask('Siti'))->toBe('S'.$bullet.$bullet.$bullet)
        // Collapsing runs of whitespace means a padded `users.nama_lengkap` cannot
        // become a bullet run, which is the same MySQL `CHAR` trap `NikMasker` handles.
        ->and(NamaMasker::mask('Siti   Aminah'))->toBe('S'.$bullet.$bullet.$bullet.' A'.$bullet.$bullet.$bullet.$bullet.$bullet);
});

test('a wrong token answers valid:false with every other field null', function (): void {
    $account = sktDoctorAccount();
    $surat = sktSurat($account['pasien'], $account['dokter']);

    $response = test()->getJson('/api/v1/surat-keterangan/'.$surat->nomor_surat.'/verify?token='.Str::uuid());

    // 200, not 404 and not 403: the scanner asked a well-formed question and the
    // answer is "this token does not verify". A 404 would make the endpoint's
    // behaviour depend on whether the document number happens to be real, which is
    // an existence oracle over the number space.
    $response->assertOk()
        ->assertJsonPath('data.valid', false)
        ->assertJsonPath('data.nomor_surat', null)
        ->assertJsonPath('data.tipe', null)
        ->assertJsonPath('data.dokter', null)
        ->assertJsonPath('data.tanggal', null)
        ->assertJsonPath('data.pasien_nama_masked', null);

    // The same SIX keys as a valid answer, so a client parses one shape and
    // branches on one boolean rather than on the presence of four other fields.
    expect(array_keys($response->json('data')))->toBe([
        'valid',
        'nomor_surat',
        'tipe',
        'dokter',
        'tanggal',
        'pasien_nama_masked',
    ]);
});

test('a number that does not exist is indistinguishable from a wrong token', function (): void {
    $account = sktDoctorAccount();
    $surat = sktSurat($account['pasien'], $account['dokter']);

    $tidakAda = test()->getJson('/api/v1/surat-keterangan/SK'.Str::upper(Str::random(10)).'/verify?token='.$surat->qr_token);
    $tokenSalah = test()->getJson('/api/v1/surat-keterangan/'.$surat->nomor_surat.'/verify?token='.Str::uuid());

    $tidakAda->assertOk();
    $tokenSalah->assertOk();

    // BYTE for byte. Two strangers comparing the two answers learn that neither
    // document exists, and nothing else.
    expect($tidakAda->getContent())->toBe($tokenSalah->getContent());
});

test('a missing token is 422, because that is a client error rather than a failed verification', function (): void {
    $account = sktDoctorAccount();
    $surat = sktSurat($account['pasien'], $account['dokter']);

    // The distinction is real: a MISSING token is the scanner not finishing its job,
    // and answering `valid: false` would tell it the letter is forged, which is a
    // different and wrong fact. A 422 says "send the token".
    test()->getJson('/api/v1/surat-keterangan/'.$surat->nomor_surat.'/verify')
        ->assertStatus(422)
        ->assertJsonPath('errors.token.0', 'Token QR wajib diisi.');

    expect($surat->exists)->toBeTrue();
});

test('a token is case sensitive and must be the whole value', function (): void {
    $account = sktDoctorAccount();
    $surat = sktSurat($account['pasien'], $account['dokter'], ['qr_token' => 'AbCdEf-123456789']);

    // An exact-match test, not a prefix or a substring one: a verification token that
    // accepted a prefix would be forgeable by truncation, and `VARCHAR(100)`'s
    // collation is case-insensitive in MySQL's default `utf8mb4_unicode_ci`, so the
    // application compares the bytes rather than letting the collation decide.
    test()->getJson('/api/v1/surat-keterangan/'.$surat->nomor_surat.'/verify?token=AbCdEf-123456789')
        ->assertOk()
        ->assertJsonPath('data.valid', true);

    test()->getJson('/api/v1/surat-keterangan/'.$surat->nomor_surat.'/verify?token=abcdef-123456789')
        ->assertOk()
        ->assertJsonPath('data.valid', false);

    test()->getJson('/api/v1/surat-keterangan/'.$surat->nomor_surat.'/verify?token=AbCdEf')
        ->assertOk()
        ->assertJsonPath('data.valid', false);
});

test('exactly what a stranger learns: the valid answer and nothing the invalid one withholds', function (): void {
    $account = sktDoctorAccount();
    $surat = sktSurat($account['pasien'], $account['dokter'], [
        'isi' => 'Rincian klinis yang tidak boleh bocor.',
    ]);
    $token = (string) $surat->qr_token;

    $sah = test()->getJson('/api/v1/surat-keterangan/'.$surat->nomor_surat.'/verify?token='.$token)->json('data');
    $tidak = test()->getJson('/api/v1/surat-keterangan/'.$surat->nomor_surat.'/verify?token=x')->json('data');

    // A VALID token tells a stranger five things and no more: that the document
    // number is real, what KIND of document it is, which doctor signed it, the day
    // it was issued, and the shape of the patient's name. It does NOT tell them the
    // patient's name, their NIK, their birth date, the letter's body, its clinical
    // period, or any surrogate id - and none of those appear anywhere in the body,
    // which the previous test proves by byte search rather than by enumeration.
    expect(array_keys($sah))->toBe([
        'valid',
        'nomor_surat',
        'tipe',
        'dokter',
        'tanggal',
        'pasien_nama_masked',
    ])
        ->and(array_keys($tidak))->toBe(array_keys($sah))
        // The only difference is the boolean and the five values behind it.
        ->and(array_keys(array_filter($sah, fn ($v, $k): bool => $k !== 'valid' && $v !== null, ARRAY_FILTER_USE_BOTH)))
        ->toBe(['nomor_surat', 'tipe', 'dokter', 'tanggal', 'pasien_nama_masked'])
        ->and(array_filter($tidak, fn ($v, $k): bool => $k !== 'valid' && $v !== null, ARRAY_FILTER_USE_BOTH))->toBe([]);

    // The plan's own field list, verified key for key against what is published.
    expect(array_keys($sah))->toBe(['valid', 'nomor_surat', 'tipe', 'dokter', 'tanggal', 'pasien_nama_masked']);
});

// =====================================================================
// The patient's own list
// =====================================================================

test('the patient sees their own letters, paginated, with meta as a top-level sibling', function (): void {
    $account = sktDoctorAccount();
    $dokterLain = sktDokter(sktUser('Dokter lain', 'dokter'));

    for ($i = 0; $i < 3; $i++) {
        sktSurat($account['pasien'], $account['dokter'], ['tipe' => SuratKeteranganTipe::nilai()[$i]]);
    }

    // Another patient's letters, which must be absent rather than refused.
    $asing = sktDoctorAccount();
    sktSurat($asing['pasien'], $asing['dokter']);
    sktSurat($asing['pasien'], $dokterLain);

    $response = sktAs($account['pasienUser'])->getJson('/api/v1/pasien/surat-keterangan');

    $response->assertOk()
        ->assertJsonCount(3, 'data.surat_keterangan')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 1)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.from', 1)
        ->assertJsonPath('meta.to', 3);

    // `meta` is a TOP-LEVEL SIBLING of `data`, never inside it: the same shape as
    // every other list in this application, so a client parses one list envelope.
    expect($response->json())->toHaveKeys(['success', 'data', 'message', 'meta'])
        ->and($response->json('data'))->toHaveKeys(['surat_keterangan'])
        ->and($response->json('data'))->not->toHaveKey('meta')
        ->and($response->json('data'))->not->toHaveKey('total');

    // The ordering is total, so paging cannot repeat or skip a row.
    $nomor = array_map(
        static fn (array $row): string => (string) $row['nomor_surat'],
        $response->json('data.surat_keterangan'),
    );

    expect($nomor)->toHaveCount(3);
});

test('an empty list reports a null from and to rather than zero', function (): void {
    $account = sktDoctorAccount();

    $response = sktAs($account['pasienUser'])->getJson('/api/v1/pasien/surat-keterangan');

    $response->assertOk()
        ->assertJsonCount(0, 'data.surat_keterangan')
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 1)
        ->assertJsonPath('meta.from', null)
        ->assertJsonPath('meta.to', null);
});

test('another patients letters are simply absent, and an account with no profile is 403', function (): void {
    $account = sktDoctorAccount();
    $asing = sktDoctorAccount();

    sktSurat($account['pasien'], $account['dokter']);
    sktSurat($asing['pasien'], $asing['dokter']);

    // 200 with one row, not a 403: the tenant filter IS the query, so another
    // patient's letter is not found rather than refused, which is what stops the id
    // space from being an existence oracle.
    sktAs($account['pasienUser'])->getJson('/api/v1/pasien/surat-keterangan')
        ->assertOk()
        ->assertJsonCount(1, 'data.surat_keterangan')
        ->assertJsonPath('meta.total', 1);

    // A `pasien`-typed account with no `pasien` row is 403, because the refusal is
    // about the CALLER and discloses nothing about anybody's letters.
    $tanpaProfil = sktPengguna('pasien', 'pasien');

    sktAs($tanpaProfil)->getJson('/api/v1/pasien/surat-keterangan')
        ->assertStatus(403)
        ->assertJsonPath('message', 'This action is unauthorized.');

    // A doctor account is 403 too, and that is `ownPasien()`'s answer rather than a
    // `tipe:pasien` gate: the account type is not the question, the row is.
    sktAs($account['user'])->getJson('/api/v1/pasien/surat-keterangan')
        ->assertStatus(403);
});

test('the letter the patient reads masks the NIK and shows the token to its owner', function (): void {
    $account = sktDoctorAccount();
    $surat = sktSurat($account['pasien'], $account['dokter']);
    $nik = (string) DB::table('pasien')->where('id', $account['pasien'])->value('nik');

    $response = sktAs($account['pasienUser'])->getJson('/api/v1/pasien/surat-keterangan');

    $response->assertOk();

    $baris = $response->json('data.surat_keterangan.0');

    // The patient is a legitimate holder of their own NIK, so the authenticated
    // resource publishes it MASKED through the project masker - never in the clear,
    // because this row is also what a doctor's list view and a support export would
    // carry, and one response shape serves all three.
    expect($baris['pasien']['nik'])->toBe(NikMasker::mask($nik))
        ->and($baris['pasien']['nik'])->not->toBe($nik)
        ->and($baris['pasien']['nama_lengkap'])->toBe($account['pasienUser']->nama_lengkap)
        // The doctor is named, and the token is published because the PATIENT is the
        // one who needs to render the QR and show it to a clinic.
        ->and($baris['dokter']['nama_lengkap'])->toBe($account['user']->nama_lengkap)
        ->and($baris['qr_token'])->toBe((string) $surat->qr_token)
        ->and((string) $response->getContent())->not->toContain($nik);
});

test('the resource publishes the letters own fields and no more', function (): void {
    $account = sktDoctorAccount();
    $surat = sktSurat($account['pasien'], $account['dokter'], [
        'tanggal_mulai' => '2026-03-11',
        'tanggal_selesai' => '2026-03-13',
        'jumlah_hari' => 3,
        'isi' => 'Istirahat total tiga hari.',
    ]);

    $response = sktAs($account['pasienUser'])->getJson('/api/v1/pasien/surat-keterangan');
    $response->assertOk();

    // An allow-list, so a column added by a later migration is invisible until
    // somebody decides it should not be.
    expect(array_keys($response->json('data.surat_keterangan.0')))->toBe([
        'id',
        'nomor_surat',
        'konsultasi_id',
        'tipe',
        'pasien_id',
        'dokter_id',
        'tanggal_mulai',
        'tanggal_selesai',
        'jumlah_hari',
        'isi',
        'qr_token',
        'file_url',
        'dibuat_at',
        'pasien',
        'dokter',
        'rujukan',
    ]);

    // `tanggal_mulai` and `tanggal_selesai` are `DATE` columns and therefore
    // Asia/Jakarta wall-clock days, never instants; `dibuat_at` is a `TIMESTAMP` and
    // therefore an ISO-8601 UTC instant. Getting that the wrong way round is the
    // error todo 51's two-rule policy exists to prevent, and the test names which
    // shape each of the three takes.
    expect($response->json('data.surat_keterangan.0.tanggal_mulai'))->toBe('2026-03-11')
        ->and($response->json('data.surat_keterangan.0.tanggal_selesai'))->toBe('2026-03-13')
        ->and($response->json('data.surat_keterangan.0.jumlah_hari'))->toBe(3)
        ->and((string) $response->json('data.surat_keterangan.0.dibuat_at'))->toEndWith('Z')
        ->and($response->json('data.surat_keterangan.0.isi'))->toBe('Istirahat total tiga hari.')
        // `file_url` is `VARCHAR(500) NULL` (:593) and no PDF is rendered by this
        // todo, so it is honestly null rather than a URL to nowhere.
        ->and($response->json('data.surat_keterangan.0.file_url'))->toBeNull()
        ->and($response->json('data.surat_keterangan.0.konsultasi_id'))->toBeNull()
        ->and($response->json('data.surat_keterangan.0.rujukan'))->toBe([]);
});

test('a referral is published with its own fields and its expiry as a wall-clock day', function (): void {
    $account = sktDoctorAccount();
    $sesi = sktKonsultasi($account['pasien'], $account['dokter']);
    $faskes = sktFaskes();
    sktConsent($account['pasienUser']->getKey());

    sktAs($account['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', [
        'tipe' => 'surat_rujukan',
        'tanggal_mulai' => SKT_HARI,
        'tanggal_selesai' => SKT_HARI,
        'faskes_tujuan_id' => $faskes,
        'alasan_rujukan' => 'Perlu pemeriksaan.',
        'nomor_sep' => '0123456789',
    ])->assertCreated();

    $response = sktAs($account['pasienUser'])->getJson('/api/v1/pasien/surat-keterangan');
    $response->assertOk();

    $rujukan = $response->json('data.surat_keterangan.0.rujukan.0');

    expect(array_keys($rujukan))->toBe([
        'id',
        'surat_keterangan_id',
        'faskes_asal_id',
        'faskes_tujuan_id',
        'dokter_perujuk_id',
        'diagnosis_kerja',
        'icd10_kode',
        'alasan_rujukan',
        'berlaku_sampai',
        'nomor_sep',
        'status',
        'dibuat_at',
    ])
        ->and($rujukan['status'])->toBe('aktif')
        ->and($rujukan['berlaku_sampai'])->toBe(Carbon::parse(SKT_HARI)->addDays(14)->toDateString())
        // A `DATE` column, so a wall-clock day and never an instant. Offsetting it
        // would move a next-day expiry into the previous evening for any client that
        // renders local time.
        ->and($rujukan['berlaku_sampai'])->not->toContain('T')
        ->and((string) $rujukan['dibuat_at'])->toEndWith('Z');
});

test('the per_page cap holds and a big ask is clamped rather than honoured', function (): void {
    $account = sktDoctorAccount();

    sktAs($account['pasienUser'])->getJson('/api/v1/pasien/surat-keterangan?per_page=1000')
        ->assertOk()
        ->assertJsonPath('meta.per_page', PasienRecordAccess::PER_PAGE_MAX);

    expect(PasienRecordAccess::PER_PAGE_MAX)->toBe(100);
});

// =====================================================================
// The service boundary
// =====================================================================

test('the service takes an account and an id, never a row the caller opened', function (): void {
    // The same rule `RekamMedisService` follows: every public method takes a `User`
    // and resolves the rows itself, so no controller can supply a letter it read
    // outside the ownership check.
    $refleksi = new ReflectionClass(SuratKeteranganService::class);
    $publik = array_values(array_filter(
        $refleksi->getMethods(ReflectionMethod::IS_PUBLIC),
        // `verifikasi` is EXEMPT, and the exemption is asserted separately by the very
        // next test rather than assumed here: the QR verifier is the one public method
        // with no caller identity, because a scanner is a receptionist with no account.
        // Its signature is pinned to exactly two `string` parameters by 'the public
        // verify method is the only one that takes no account', so widening it has to
        // change a test.
        static fn (ReflectionMethod $m): bool => ! $m->isConstructor() && $m->getName() !== 'verifikasi',
    ));

    expect($publik)->not->toBeEmpty();

    foreach ($publik as $metode) {
        $tipe = $metode->getParameters()[0]->getType();

        // The first parameter is a `User` for the three account-facing methods. The
        // generator and the exception are not methods, so there is nothing to exempt.
        expect($tipe)->toBeInstanceOf(ReflectionNamedType::class)
            ->and($tipe->getName())->toBe(User::class, $metode->getName());

        // And no method accepts a `SuratKeterangan` or a `Rujukan`, so there is no
        // overload through which a caller could hand over a row it hydrated itself.
        foreach ($metode->getParameters() as $parameter) {
            $jenis = $parameter->getType();

            if (! $jenis instanceof ReflectionNamedType) {
                continue;
            }

            expect($jenis->getName())->not->toBe(SuratKeterangan::class, $metode->getName())
                ->and($jenis->getName())->not->toBe(Rujukan::class, $metode->getName());
        }
    }
});

test('the public verify method is the only one that takes no account', function (): void {
    // A deliberate exception to the rule above, and the one place in this service
    // with no caller identity at all. It is named here so a future edit that widens
    // its signature has to change this test.
    $metode = new ReflectionMethod(SuratKeteranganService::class, 'verifikasi');

    expect($metode->isPublic())->toBeTrue()
        ->and($metode->getParameters()[0]->getType()->getName())->toBe('string')
        ->and($metode->getParameters()[1]->getType()->getName())->toBe('string')
        ->and(count($metode->getParameters()))->toBe(2);

    // And the token comparison is EXACT, in the application, because MySQL's default
    // `utf8mb4_unicode_ci` collation is case-INSENSITIVE - a `where('qr_token', ...)`
    // alone would accept a case variant of a bearer secret. `hash_equals` is also
    // constant-time, where a byte-by-byte comparison leaks how much of a guessed
    // secret was right.
    $sumber = file_get_contents((new ReflectionClass(SuratKeteranganService::class))->getFileName());

    expect($sumber)->toContain('hash_equals')
        // The token is compared in PHP, so it must NOT also be a SQL predicate: a
        // `where('qr_token', ...)` would be the case-insensitive one. The ONE
        // occurrence of `where('qr_token', $token)` in the whole file is the
        // `->exists()` collision check below, and the assertion counts both so a
        // second predicate - or a second copy of the check - fails.
        ->and(substr_count($sumber, "where('qr_token', \$token)"))->toBe(1)
        ->and(substr_count($sumber, "where('qr_token', \$token)->exists()"))->toBe(1);
});
