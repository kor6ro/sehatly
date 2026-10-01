<?php

declare(strict_types=1);

use App\Models\Konsultasi;
use App\Models\Pasien;
use App\Models\RekamMedis;
use App\Models\RekamMedisDiagnosa;
use App\Models\RekamMedisLampiran;
use App\Models\RekamMedisPersetujuan;
use App\Models\RekamMedisTindakan;
use App\Models\User;
use App\Services\RekamMedis\RekamMedisReadScope;
use App\Services\RekamMedis\RekamMedisService;
use App\Support\NikMasker;
use App\Support\Rbac\RoleAssigner;
use App\Support\Schema\SqlSchemaParser;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PasienFixture;

/*
|--------------------------------------------------------------------------
| The medical record service, its amendment chain, and the access log
|--------------------------------------------------------------------------
|
| APPENDED by todo 33. The helper prefix is `rmd`, following the `kns` / `bku` /
| `realtime` convention: Pest loads every test file into one process, so a helper
| name already declared at file scope would be a redeclaration.
|
| ## What "a read" means here, and why the count is a strong claim
|
| This file is the executable definition. The rule it pins is:
|
| > Every operation that OPENS an existing `rekam_medis` row, or one of its four
| > child tables, writes EXACTLY ONE `akses_rekam_medis_log` row. An operation
| > that opens nothing writes ZERO.
|
| "Opens" is enforced, not agreed. `RekamMedis` and its four children carry the
| `GuardsMedicalRecordRead` trait, whose `retrieved` listener throws unless a
| `RekamMedisReadScope` is open. Eloquent fires `retrieved` for every hydrated
| model - `find`, `findOrFail`, `first`, `firstOrFail`, `chunk`, `cursor`, a lazy
| relation, a `with()` eager load - so a read that does not hold the scope cannot
| be performed at all, whatever query spelling the caller reaches for. The log row
| and the read share one transaction, so a read that is rolled back leaves no log
| row either.
|
| The per-case counts that are NOT one are: a refusal that opened nothing (0), a
| create (0 - nothing pre-existing is opened, and the log's five ENUM values are
| all read purposes), a read inside a rolled-back transaction (0), and a client
| retry (2 - two accesses, because an access log that collapses a retry into one
| row cannot answer "was this looked at twice?").
|
| @see \App\Services\RekamMedis\RekamMedisAccessLogger for the log writer
| @see \App\Services\RekamMedis\RekamMedisAccess for the 403/404 split
*/

// =====================================================================
// Row builders
// =====================================================================

/**
 * The `tanggal_periksa` every fixture writes.
 *
 * `rekam_medis.tanggal_periksa` is `DATETIME NOT NULL` (:630) and the amendment
 * chain is reconstructed by GROUPING on it, so a fixture that let it default to
 * `now()` would sit in a different group every second and a chain assertion would
 * fail for a reason that has nothing to do with the chain.
 */
const RMD_TANGGAL = '2026-03-11 09:30:00';

/**
 * A `users` row. `uuid` (:134), `nama_lengkap` (:135), `no_telepon` (:137) and
 * `kata_sandi_hash` (:138) are the four NOT NULL columns with no default.
 */
function rmdUser(string $nama, string $tipe = 'pasien'): int
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
 * `perawat` and `kurir` are real `users.tipe` values (`telemedicine_test.sql:139`)
 * that hold no role in `RbacCatalog::ROLES`, so a helper that always assigned one
 * would make the only two account types a `permission:` gate locks out untestable.
 */
function rmdPengguna(string $tipe, ?string $role = null): User
{
    $id = rmdUser('Pengguna '.$tipe.' '.Str::upper(Str::random(4)), $tipe);

    if ($role !== null) {
        app(RoleAssigner::class)->assign($id, $role);
    }

    return User::query()->findOrFail($id);
}

/**
 * A `pasien` row. `jenis_kelamin`, `tanggal_lahir` and `alamat_lengkap` are
 * NOT NULL with no default.
 *
 * @param  array<string, mixed>  $ubah
 */
function rmdPasien(int $userId, array $ubah = []): int
{
    return (int) DB::table('pasien')->insertGetId(PasienFixture::withNik(array_merge([
        'user_id' => $userId,
        // `nik_cipher` replaced `nik CHAR(16) NULL UNIQUE` (:222), so the value is
        // a `NikCipher` payload rather than the 16 characters themselves. It still
        // needs to be there for the mask assertion to have something to mask, and
        // `PasienFixture` encrypts it with the same `NikCipher::encrypt()` the
        // model mutator calls.
        'nik' => '327312345678'.str_pad((string) (1000 + random_int(0, 8999)), 4, '0', STR_PAD_LEFT),
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Rekam Medis No. 12, Jakarta',
    ], $ubah)));
}

/**
 * A `dokter` row. `nomor_str` (:413) is UNIQUE so it is randomised per call.
 *
 * @param  array<string, mixed>  $ubah
 */
function rmdDokter(int $userId, array $ubah = []): int
{
    return (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-RMD-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'biaya_konsultasi_online' => '150000.00',
        'tersedia_telemedisin' => 1,
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ], $ubah));
}

/**
 * A `konsultasi` row, so `POST /konsultasi/{id}/rekam-medis` has something to hang
 * a record off. `booking_id` is left NULL, the instant shape.
 */
function rmdKonsultasi(int $pasienId, int $dokterId): int
{
    $row = new Konsultasi;
    $row->pasien_id = $pasienId;
    $row->dokter_id = $dokterId;
    $row->tipe = 'chat';
    $row->status = 'selesai';
    $row->room_id = (string) Str::uuid();
    $row->save();

    return (int) $row->getKey();
}

/**
 * A patient account, its `pasien` row, and a doctor it consults.
 *
 * @return array{user: User, pasien: int, dokter: int, dokterUser: User}
 */
function rmdPatientAccount(): array
{
    $user = rmdPengguna('pasien', 'pasien');
    $dokterUser = rmdPengguna('dokter', 'dokter');

    return [
        'user' => $user,
        'pasien' => rmdPasien($user->getKey()),
        'dokter' => rmdDokter($dokterUser->getKey()),
        'dokterUser' => $dokterUser,
    ];
}

/**
 * A doctor account owning a `dokter` row, with the `dokter` role.
 *
 * @return array{user: User, dokter: int, pasien: int}
 */
function rmdDoctorAccount(): array
{
    $user = rmdPengguna('dokter', 'dokter');

    return [
        'user' => $user,
        'dokter' => rmdDokter($user->getKey()),
        'pasien' => rmdPasien(rmdPengguna('pasien', 'pasien')->getKey()),
    ];
}

/**
 * Act as `$user` for one request, with a real Sanctum bearer token.
 *
 * `forgetGuards()` first, for the reason `knsAs()` gives: `Sanctum::actingAs()`
 * hands back a transient token and `RequestGuard` caches its principal, so the
 * first authenticated request inside a test would otherwise decide the caller for
 * every later one.
 */
function rmdAs(User $user): mixed
{
    app('auth')->forgetGuards();

    return test()->withToken($user->createToken('rekam-medis-test', ['*'], now()->addHour())->plainTextToken);
}

/**
 * How many `akses_rekam_medis_log` rows name `$rekamMedisId`.
 *
 * Counted through the query builder rather than through the `AksesRekamMedisLog`
 * model on purpose: the count is the thing under test, and counting with the same
 * model the production code writes through would be a weaker statement.
 */
function rmdJumlahLog(int $rekamMedisId): int
{
    return DB::table('akses_rekam_medis_log')->where('rekam_medis_id', $rekamMedisId)->count();
}

/**
 * A `rekam_medis` row written DIRECTLY, bypassing the service.
 *
 * Every fixture that needs a record in a particular `status_dokumen` or `versi`
 * builds it here, because the service is the thing under test and asking it to
 * produce the state would make the assertion circular. `status_dokumen` is written
 * explicitly everywhere rather than left to the DDL default, which is `'final'`
 * (:645) - a fixture relying on the default would silently be testing the
 * final-record path.
 *
 * @param  array<string, mixed>  $ubah
 */
function rmdRecord(int $pasienId, int $dokterId, array $ubah = []): RekamMedis
{
    $row = new RekamMedis;
    $row->pasien_id = $pasienId;
    $row->dokter_id = $dokterId;
    $row->tipe_kunjungan = 'telemedisin';
    $row->tanggal_periksa = RMD_TANGGAL;
    $row->status_dokumen = 'draft';
    $row->versi = 1;

    foreach ($ubah as $column => $value) {
        $row->{$column} = $value;
    }

    $row->save();

    return $row;
}

/**
 * A draft record with one child row of each of the four child tables.
 *
 * @return array{0: RekamMedis, 1: array<string, int>}
 */
function rmdRecordLengkap(int $pasienId, int $dokterId, int $pengakses): array
{
    $row = rmdRecord($pasienId, $dokterId, ['subjektif' => 'Batuk sejak tiga hari.']);

    $diagnosa = (int) DB::table('rekam_medis_diagnosa')->insertGetId([
        'rekam_medis_id' => $row->getKey(),
        'icd10_kode' => 'R05.1',
        'deskripsi' => 'Batuk akut',
        'jenis' => 'utama',
        'tipe_kasus' => 'baru',
    ]);

    $tindakan = (int) DB::table('rekam_medis_tindakan')->insertGetId([
        'rekam_medis_id' => $row->getKey(),
        'nama_tindakan' => 'Pemeriksaan fisik',
        'tanggal_tindakan' => RMD_TANGGAL,
        'dokter_pelaksana_id' => $dokterId,
    ]);

    // `diunggah_oleh` (:687) carries NO foreign key, so the ACCOUNT id is written
    // here on purpose rather than a profile id.
    $lampiran = (int) DB::table('rekam_medis_lampiran')->insertGetId([
        'rekam_medis_id' => $row->getKey(),
        'nama_file' => 'lab.pdf',
        'file_url' => 'https://contoh.test/lab.pdf',
        'tipe' => 'hasil_lab',
        'diunggah_oleh' => $pengakses,
    ]);

    $persetujuan = (int) DB::table('rekam_medis_persetujuan')->insertGetId([
        'rekam_medis_id' => $row->getKey(),
        'tipe' => 'general_consent',
        'isi_persetujuan' => 'Persetujuan perawatan telemedisin.',
        'ditandatangani_oleh' => 'Siti Aminah',
        'ditandatangani_at' => RMD_TANGGAL,
    ]);

    return [$row, compact('diagnosa', 'tindakan', 'lampiran', 'persetujuan')];
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
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// =====================================================================
// The DDL the whole todo rests on
// =====================================================================

test('the DDL facts the service depends on are the file, read and not recalled', function (): void {
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    $rm = $spec->table('rekam_medis');
    $log = $spec->table('akses_rekam_medis_log');

    expect($rm)->not->toBeNull()
        ->and($log)->not->toBeNull();

    // The trap the plan names: `status_dokumen` DEFAULTS TO 'final'. Omitting the
    // column produces a record that is immutable from the moment it is born, and
    // `RekamMedisService::simpan()` writes `'draft'` explicitly for that reason.
    expect(trim((string) $rm->columns['status_dokumen']->default, "'"))->toBe('final')
        ->and($rm->columns['status_dokumen']->nullable)->toBeFalse()
        ->and($rm->columns['status_dokumen']->type)->toBe("enum('draft','final','diamendemen')");

    // The version column is a TINYINT UNSIGNED, so the chain is capped at 255
    // amendments. The service refuses the 256th rather than letting MySQL raise
    // 1264, and `versi` is published as an int because a TINYINT arrives from the
    // driver as a string.
    expect((int) $rm->columns['versi']->default)->toBe(1)
        ->and($rm->columns['versi']->nullable)->toBeFalse()
        ->and($rm->columns['versi']->unsigned)->toBeTrue()
        ->and($rm->columns['versi']->type)->toBe('tinyint');

    // The chain group. `tanggal_periksa` is a DATETIME, not a DATE, so the
    // reconstruction key is a full timestamp and not a calendar day.
    expect($rm->columns['tanggal_periksa']->type)->toBe('datetime')
        ->and($rm->columns['tanggal_periksa']->nullable)->toBeFalse()
        ->and($rm->columns['pasien_id']->type)->toBe('bigint')
        ->and($rm->columns['dokter_id']->type)->toBe('bigint');

    // `uuid` is CHAR(36) NOT NULL UNIQUE and is minted per row, which is what makes
    // an amendment a NEW document rather than a second name for the first.
    expect($rm->columns['uuid']->type)->toBe('char(36)')
        ->and($rm->columns['uuid']->nullable)->toBeFalse();

    // THE FINDING. `rekam_medis` carries NO linkage column: no `parent_id`, no
    // self-reference, no currency flag, no supersession pointer, no amendment reason,
    // no amendment author. Every one of these names is asserted ABSENT, because "the
    // chain is reconstructed by grouping" is only a finding if the columns that would
    // make that unnecessary are provably not there.
    foreach ([
        'parent_id', 'rekam_medis_id', 'id_rekam_medis', 'versi_induk', 'parent_uuid',
        'is_current', 'is_terkini', 'superseded_by', 'digantikan_oleh', 'dimodifikasi_at',
        'alasan_amandemen', 'diamendemen_oleh',
    ] as $tidakAda) {
        expect($rm->columns)->not->toHaveKey($tidakAda);
    }

    // Nothing at the database level forbids two rows sharing a version, so the
    // service has to be the thing that refuses it. The declared indexes are asserted
    // as a closed set - `PRIMARY (id)`, `UNIQUE (uuid)` and
    // `INDEX idx_rm_pasien (pasien_id, tanggal_periksa)` and nothing else - so a
    // future migration adding a unique constraint on
    // (pasien_id, dokter_id, tanggal_periksa, versi) would show up here rather than
    // silently making the service's own check redundant. `semanticKey()` rather than
    // the raw name, because `nameIsAuthoritative` is false for a primary key and the
    // engine mangles unnamed index names.
    $indexes = array_map(static fn ($index): string => $index->semanticKey(), $rm->indexes);
    expect($indexes)->toBe([
        'PRIMARY (id)',
        'UNIQUE (uuid)',
        'INDEX (pasien_id, tanggal_periksa)',
    ]);

    // The five read purposes. `klaim` and `kepentingan_hukum` have no producer in
    // this application; the report says so rather than inventing an endpoint.
    expect($log->columns['tujuan_akses']->type)
        ->toBe("enum('perawatan','klaim','audit','pasien_sendiri','kepentingan_hukum')")
        ->and($log->columns['pengakses_user_id']->nullable)->toBeFalse()
        ->and($log->columns['rekam_medis_id']->nullable)->toBeFalse();

    // `dibuat_at` is a TIMESTAMP: one second of resolution, so two reads in the same
    // second are indistinguishable by time and an auditor must order by id.
    expect($log->columns['dibuat_at']->type)->toBe('timestamp');

    // `rekam_medis_lampiran.diunggah_oleh` (:687) is NOT NULL and has NO foreign key,
    // so it is an unverified `users.id` at best. Reported, not invented around.
    $lampiran = $spec->table('rekam_medis_lampiran');
    expect($lampiran->columns)->toHaveKey('diunggah_oleh')
        ->and(array_map(static fn ($fk): array => $fk->columns, $lampiran->foreignKeys))
        ->toBe([['rekam_medis_id']]);
});

test('the plan citations for this todo, checked against the file', function (): void {
    $lines = file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);

    // The plan's todo 33 references `telemedicine_test.sql:621-706` for "all 5
    // tables". They span 621-702; 704 opens the `[9] RESEP & FARMASI` banner. A
    // citation that overruns its own object deserves a test, because the next reader
    // has no other way to learn that it was checked.
    expect($lines[620])->toBe('CREATE TABLE rekam_medis (')
        ->and($lines[654])->toBe(') ENGINE=InnoDB;')
        ->and($lines[656])->toBe('CREATE TABLE rekam_medis_diagnosa (')
        ->and($lines[666])->toBe(') ENGINE=InnoDB;')
        ->and($lines[668])->toBe('CREATE TABLE rekam_medis_tindakan (')
        ->and($lines[678])->toBe(') ENGINE=InnoDB;')
        ->and($lines[680])->toBe('CREATE TABLE rekam_medis_lampiran (')
        ->and($lines[689])->toBe(') ENGINE=InnoDB;')
        ->and($lines[691])->toBe('CREATE TABLE rekam_medis_persetujuan (')
        ->and($lines[701])->toBe(') ENGINE=InnoDB;')
        ->and($lines[1146])->toBe('CREATE TABLE akses_rekam_medis_log (')
        ->and($lines[1154])->toBe(') ENGINE=InnoDB;');

    // The plan's `:645`, `:646` and `:1147-1155` are all CORRECT. Recording the right
    // ones matters as much as recording the wrong one: a report that only lists
    // errors is as misleading as the plan it corrects.
    expect($lines[644])->toBe("  status_dokumen ENUM('draft','final','diamendemen') NOT NULL DEFAULT 'final',")
        ->and($lines[645])->toBe('  versi TINYINT UNSIGNED NOT NULL DEFAULT 1,')
        ->and($lines[1150])->toBe("  tujuan_akses ENUM('perawatan','klaim','audit','pasien_sendiri','kepentingan_hukum') NOT NULL,");
});

// =====================================================================
// The route table
// =====================================================================

test('seven routes are registered under api/v1 with the guards this todo chose', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/rekam-medis')
            || $route->uri() === 'api/v1/konsultasi/{id}/rekam-medis')
        ->keyBy(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->all();

    // Todo 33 shipped five routes and F10 appended the two reads: the patient's own
    // list (`GET api/v1/rekam-medis`, a one-segment literal registered BEFORE the
    // `{id}` wildcard) and the access history for one record
    // (`GET api/v1/rekam-medis/{id}/akses`). The closed set is asserted by URI rather
    // than by prefix, because `route:list --path=api/v1/rekam-medis` cannot see the
    // create route that lives under `konsultasi`.
    expect(array_keys($routes))->toEqualCanonicalizing([
        'POST api/v1/konsultasi/{id}/rekam-medis',
        'GET api/v1/rekam-medis',
        'GET api/v1/rekam-medis/{id}',
        'GET api/v1/rekam-medis/{id}/akses',
        'PUT api/v1/rekam-medis/{id}',
        'PUT api/v1/rekam-medis/{id}/final',
        'POST api/v1/rekam-medis/{id}/amandemen',
    ]);

    $middlewareFor = static function (string $key) use ($routes): array {
        return array_values(array_filter(
            $routes[$key]->gatherMiddleware(),
            static fn ($middleware): bool => is_string($middleware),
        ));
    };

    // Every route names `auth:sanctum` explicitly, so "unauthenticated" is answered
    // by the guard (401) and a route added later cannot be unprotected by omission.
    foreach (array_keys($routes) as $key) {
        expect($middlewareFor($key))->toContain('auth:sanctum');
    }

    // `GET /rekam-medis/{id}` carries NO `permission:` and NO `tipe:`, and that is a
    // decision rather than an omission. The plan's read audience is a DISJUNCTION -
    // the patient themselves OR their doctor OR an oversight account - and a route
    // gate can only express a conjunction. `rekam_medis.lihat` IS a real code, but it
    // is granted to `pasien`, `dokter` and `superadmin` and NOT to `admin`
    // (RbacCatalog::ROLE_PERMISSIONS), so the one code the catalogue has for this
    // action would 403 the `admin` the plan names as the `audit` reader.
    // `KonsultasiController` makes exactly this argument for `GET /konsultasi/{id}`.
    expect($middlewareFor('GET api/v1/rekam-medis/{id}'))->not->toContain('permission:rekam_medis.lihat')
        ->and($middlewareFor('GET api/v1/rekam-medis/{id}'))->not->toContain('tipe:dokter');

    // F10's two reads carry the SAME absence for the same reason: the list's
    // audience is the patient whose `pasien_id` scopes the query, and the
    // access-log read resolves through `RekamMedisAccess::sisiUntukBaca()`, the
    // detail route's own resolver. A route gate cannot express either question.
    expect($middlewareFor('GET api/v1/rekam-medis'))->not->toContain('permission:rekam_medis.lihat')
        ->and($middlewareFor('GET api/v1/rekam-medis'))->not->toContain('tipe:dokter')
        ->and($middlewareFor('GET api/v1/rekam-medis/{id}/akses'))->not->toContain('permission:rekam_medis.lihat')
        ->and($middlewareFor('GET api/v1/rekam-medis/{id}/akses'))->not->toContain('tipe:dokter');

    // The three record writes are `tipe:dokter` plus the code that names the action.
    // An amendment is gated on `rekam_medis.final` and not on `rekam_medis.simpan`,
    // because an amendment carries the same clinical authority as the signed record it
    // supersedes; both are held by the same two roles, so the choice is semantic.
    foreach ([
        'POST api/v1/konsultasi/{id}/rekam-medis' => 'permission:rekam_medis.simpan',
        'PUT api/v1/rekam-medis/{id}' => 'permission:rekam_medis.simpan',
        'PUT api/v1/rekam-medis/{id}/final' => 'permission:rekam_medis.final',
        'POST api/v1/rekam-medis/{id}/amandemen' => 'permission:rekam_medis.final',
    ] as $key => $permission) {
        expect($middlewareFor($key))->toContain('tipe:dokter')->toContain($permission);
    }
});

// =====================================================================
// The structural guarantee
// =====================================================================

test('no rekam_medis row can be hydrated outside a logged read', function (): void {
    $akun = rmdPatientAccount();
    [$row] = rmdRecordLengkap($akun['pasien'], $akun['dokter'], (int) $akun['user']->getKey());
    $id = (int) $row->getKey();

    // Eloquent fires `retrieved` for every hydrated model, so these four are four
    // spellings of the same forbidden act and all four must throw. A grep for
    // `RekamMedis::find(` would only ever catch the first of them.
    foreach ([
        'first' => static fn (): mixed => RekamMedis::query()->whereKey($id)->first(),
        'find' => static fn (): mixed => RekamMedis::query()->find($id),
        'findOrFail' => static fn (): mixed => RekamMedis::query()->findOrFail($id),
        'firstOrFail' => static fn (): mixed => RekamMedis::query()->whereKey($id)->firstOrFail(),
    ] as $bentuk => $jalur) {
        expect(fn (): mixed => $jalur())->toThrow(RuntimeException::class, 'RekamMedisReadScope');
    }

    // The four child tables are guarded the same way, because each of them is
    // clinical content: an `icd10_kode` or a `nama_tindakan` discloses the same fact
    // a SOAP note does.
    foreach ([
        RekamMedisDiagnosa::class,
        RekamMedisTindakan::class,
        RekamMedisLampiran::class,
        RekamMedisPersetujuan::class,
    ] as $model) {
        expect(fn (): mixed => $model::query()->where('rekam_medis_id', $id)->get())
            ->toThrow(RuntimeException::class, 'RekamMedisReadScope');
    }

    expect(RekamMedisReadScope::sedangBerjalan())->toBeFalse();
});

test('the scope is cleared even when the read throws, so a later read is still refused', function (): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter']);
    $id = (int) $row->getKey();

    // `RekamMedisReadScope::dalam()` is the only thing that opens the gate and it
    // clears in a `finally`. If it did not, the first read below would leave the gate
    // open and the SECOND would succeed - which is exactly the leak a leaked scope
    // is. Asserting the second read is refused is what proves the first did not open
    // a lasting hole.
    expect(fn (): mixed => RekamMedisReadScope::dalam(
        grup: [(int) $row->pasien_id, (int) $row->dokter_id, (string) $row->tanggal_periksa->format('Y-m-d H:i:s')],
        ids: [$id],
        callback: static function (): string {
            throw new RuntimeException('tidak sampai');
        },
    ))->toThrow(RuntimeException::class, 'tidak sampai');

    expect(RekamMedisReadScope::sedangBerjalan())->toBeFalse()
        ->and(fn (): mixed => RekamMedis::query()->whereKey($id)->first())
        ->toThrow(RuntimeException::class, 'RekamMedisReadScope');
});

test('no code outside app/Services/RekamMedis reaches the rekam_medis tables', function (): void {
    // The structural guard above is the real control: it is a model event, so it
    // cannot be routed around by any query spelling. This is the conventional
    // backstop and it is the plan's own acceptance criterion - it names the exact
    // spellings a future author is most likely to type, which a runtime guard cannot
    // report as a code smell.
    $allowed = str_replace('\\', '/', base_path('app/Services/RekamMedis')).'/';
    $pola = [
        'RekamMedis::find(',
        'RekamMedis::findOrFail(',
        'RekamMedis::query(',
        'RekamMedis::hydrate(',
        'RekamMedisDiagnosa::query(',
        'RekamMedisTindakan::query(',
        'RekamMedisLampiran::query(',
        'RekamMedisPersetujuan::query(',
    ];

    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        if (str_starts_with($path, $allowed)) {
            continue;
        }

        // Scan EXECUTABLE CODE ONLY.
        //
        // A docblock that NAMES a query is documentation, not code reaching the
        // table. `RefusesHardDelete` writes `RekamMedis::query()->where(...)->delete()`
        // in its own trait note, to record the query-builder delete it deliberately
        // does NOT perform. Matching raw source made this guard fire on that prose,
        // which is the A.15 defect class: a comment failing an assertion about code.
        // Tokenising keeps the guard real - executable calls are still caught.
        $source = (string) file_get_contents($path);
        $source = implode('', array_map(
            static fn (array|string $token): string => is_array($token)
                ? ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT ? '' : $token[1])
                : $token,
            token_get_all($source),
        ));

        foreach ($pola as $satu) {
            if (str_contains($source, $satu)) {
                $offenders[] = str_replace(str_replace('\\', '/', base_path()).'/', '', $path).' uses '.$satu;
            }
        }
    }

    expect($offenders)->toBe([]);
});

// =====================================================================
// Reads write exactly one log row
// =====================================================================

test('reading one record as its own patient writes exactly one log row', function (): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter']);
    $id = (int) $row->getKey();

    expect(rmdJumlahLog($id))->toBe(0);

    $response = rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$id);

    $response->assertOk();
    $response->assertJsonPath('data.rekam_medis.id', $id);
    $response->assertJsonPath('data.rekam_medis.versi', 1);
    $response->assertJsonPath('data.rekam_medis.status_dokumen', 'draft');

    expect(rmdJumlahLog($id))->toBe(1)
        ->and(DB::table('akses_rekam_medis_log')->where('rekam_medis_id', $id)->value('tujuan_akses'))
        ->toBe('pasien_sendiri')
        ->and((int) DB::table('akses_rekam_medis_log')->where('rekam_medis_id', $id)->value('pengakses_user_id'))
        ->toBe((int) $akun['user']->getKey());
});

test('the purpose is derived from the accessor and each class maps to one value', function (string $tipe, ?string $role, string $tujuan): void {
    $pasienUser = rmdPengguna('pasien', 'pasien');
    $pasien = rmdPasien($pasienUser->getKey());
    $dokterUser = rmdPengguna('dokter', 'dokter');
    $dokter = rmdDokter($dokterUser->getKey());
    $row = rmdRecord($pasien, $dokter);
    $id = (int) $row->getKey();

    $caller = match ($tipe) {
        'pasien' => $pasienUser,
        'dokter' => $dokterUser,
        default => rmdPengguna($tipe, $role),
    };

    rmdAs($caller)->getJson('/api/v1/rekam-medis/'.$id)->assertOk();

    expect(rmdJumlahLog($id))->toBe(1)
        ->and(DB::table('akses_rekam_medis_log')->where('rekam_medis_id', $id)->value('tujuan_akses'))
        ->toBe($tujuan);
})->with([
    // `pasien_sendiri` (:1151) is the patient's own record, `perawatan` is the doctor
    // treating, and `audit` is oversight - and BOTH `admin` and `superadmin` reach it,
    // which is only possible because the route carries no `permission:`.
    ['pasien', 'pasien', 'pasien_sendiri'],
    ['dokter', 'dokter', 'perawatan'],
    ['admin', 'admin', 'audit'],
    ['superadmin', 'superadmin', 'audit'],
]);

test('a refusal that opened no record writes no log row', function (string $sisi, int $harapan): void {
    $milik = rmdPatientAccount();
    $row = rmdRecord($milik['pasien'], $milik['dokter']);
    $id = (int) $row->getKey();

    $caller = match ($sisi) {
        'pasien lain' => rmdPatientAccount()['user'],
        'dokter lain' => rmdDoctorAccount()['user'],
        'apoteker' => rmdPengguna('apoteker', 'apoteker'),
        'perawat' => rmdPengguna('perawat', null),
        'kurir' => rmdPengguna('kurir', null),
    };

    rmdAs($caller)->getJson('/api/v1/rekam-medis/'.$id)->assertStatus($harapan);

    expect(rmdJumlahLog($id))->toBe(0);
})->with([
    // Another PATIENT, and a DOCTOR with no consultation relationship: 404, because a
    // 403 would confirm the record exists.
    ['pasien lain', 404],
    ['dokter lain', 404],
    // An account owning NO profile row at all: the refusal is about the caller.
    ['apoteker', 403],
    ['perawat', 403],
    ['kurir', 403],
]);

test('an id that was never there writes no log row', function (string $segment, int $harapan): void {
    rmdAs(rmdPengguna('pasien', 'pasien'))->getJson('/api/v1/rekam-medis/'.$segment)->assertStatus($harapan);

    // Nothing was opened, so nothing is logged - which is the plan's own QA scenario
    // (c), and the reason the ownership probe must not hydrate a model.
    expect(DB::table('akses_rekam_medis_log')->count())->toBe(0);
})->with([
    ['999999999', 404],
    ['0', 404],
    ['abc', 404],
]);

test('the same request twice is two accesses and therefore two log rows', function (): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter']);
    $id = (int) $row->getKey();

    rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$id)->assertOk();
    rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$id)->assertOk();

    // THIS is the one case where "more than one" is correct, and it is stated rather
    // than engineered away. A client that retries a read after a dropped response has
    // performed a second access, and an access log that collapses a retry into one row
    // cannot answer "was this record looked at twice?". Collapsing would need
    // deduplication, and the schema offers none: `dibuat_at` is a TIMESTAMP (:1152) at
    // one second of resolution, so the two rows are not even distinguishable by time -
    // only by the auto-increment `id`.
    expect(rmdJumlahLog($id))->toBe(2)
        ->and(DB::table('akses_rekam_medis_log')->where('rekam_medis_id', $id)->orderBy('id')->pluck('pengakses_user_id')->unique()->all())
        ->toBe([(int) $akun['user']->getKey()]);
});

test('a list of N records is N accesses and therefore N log rows', function (): void {
    $akun = rmdPatientAccount();
    $ids = [];

    for ($i = 0; $i < 3; $i++) {
        $ids[] = (int) rmdRecord($akun['pasien'], $akun['dokter'], [
            'tanggal_periksa' => Carbon::parse(RMD_TANGGAL)->addDays($i)->format('Y-m-d H:i:s'),
        ])->getKey();
    }

    // The plan registers NO list endpoint, so there is no paginated list to drive over
    // HTTP and the count is proven the only honest way available: three successive
    // reads are three accesses, one log row each. Reported as a plan gap rather than
    // papered over by adding a sixth route.
    foreach ($ids as $id) {
        rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$id)->assertOk();
    }

    expect(DB::table('akses_rekam_medis_log')->count())->toBe(3);

    foreach ($ids as $id) {
        expect(rmdJumlahLog($id))->toBe(1);
    }
});

test('eager-loading the four child tables does not multiply the log rows', function (): void {
    $akun = rmdPatientAccount();
    [$row] = rmdRecordLengkap($akun['pasien'], $akun['dokter'], (int) $akun['user']->getKey());
    $id = (int) $row->getKey();

    $response = rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$id);

    $response->assertOk();
    $response->assertJsonCount(1, 'data.rekam_medis.diagnosa');
    $response->assertJsonCount(1, 'data.rekam_medis.tindakan');
    $response->assertJsonCount(1, 'data.rekam_medis.lampiran');
    $response->assertJsonCount(1, 'data.rekam_medis.persetujuan');

    // FIVE hydrations - one parent, four children, five separate queries - inside one
    // scope, and still exactly one log row. If the log were written per hydration this
    // would be 5; if the scope were not shared across the eager loads this would throw
    // instead.
    expect(rmdJumlahLog($id))->toBe(1);
});

test('a read whose transaction is rolled back leaves no log row', function (): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter']);
    $id = (int) $row->getKey();

    // The proof that the log write is INSIDE the read's transaction rather than beside
    // it. If the log were written outside - or "best effort" after the read returned -
    // this rollback would leave the row behind and the two would no longer describe the
    // same event.
    try {
        DB::transaction(function () use ($akun, $id): void {
            app(RekamMedisService::class)->findForAccess($id, $akun['user']);

            throw new RuntimeException('batalkan transaksi');
        });

        expect(false)->toBeTrue('transaksi seharusnya dibatalkan.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('batalkan transaksi');
    }

    expect(rmdJumlahLog($id))->toBe(0);
});

// =====================================================================
// Authorisation detail
// =====================================================================

test('another patients record is 404 with the routers own body, not a 403', function (): void {
    $milik = rmdPatientAccount();
    $row = rmdRecord($milik['pasien'], $milik['dokter']);
    $asing = rmdPatientAccount();

    $response = rmdAs($asing['user'])->getJson('/api/v1/rekam-medis/'.$row->getKey());

    // The BYTE body is asserted, because a 404 that differed from the router's own
    // would be a second existence oracle: a caller could tell "no such record" from
    // "not your record" by comparing the message.
    $response->assertStatus(404);
    expect($response->getContent())
        ->toBe('{"success":false,"message":"Resource not found.","errors":{}}')
        ->and(rmdJumlahLog((int) $row->getKey()))->toBe(0);
});

test('a caller with no profile row is 403, about the caller and not the record', function (): void {
    $milik = rmdPatientAccount();
    $row = rmdRecord($milik['pasien'], $milik['dokter']);

    $response = rmdAs(rmdPengguna('kurir', null))->getJson('/api/v1/rekam-medis/'.$row->getKey());

    $response->assertStatus(403);
    expect($response->getContent())
        ->toBe('{"success":false,"message":"This action is unauthorized.","errors":{}}')
        ->and(rmdJumlahLog((int) $row->getKey()))->toBe(0);
});

test('every write route refuses a patient at the middleware before the service runs', function (string $method, string $suffix): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter']);
    $id = (int) $row->getKey();

    rmdAs($akun['user'])->json($method, '/api/v1/rekam-medis/'.$id.$suffix, [
        'keluhan_utama' => 'Apa saja',
        'perubahan' => ['keluhan_utama' => 'Apa saja'],
    ])->assertStatus(403);

    // `tipe:dokter` answered at the middleware, so the service never opened the row
    // and there is nothing to log.
    expect(rmdJumlahLog($id))->toBe(0);
})->with([
    ['PUT', ''],
    ['PUT', '/final'],
    ['POST', '/amandemen'],
]);

// =====================================================================
// Creating a draft
// =====================================================================

test('a created record is a draft at version 1, never the DDL default final', function (): void {
    $akun = rmdPatientAccount();
    $konsultasiId = rmdKonsultasi($akun['pasien'], $akun['dokter']);

    $response = rmdAs($akun['dokterUser'])->postJson('/api/v1/konsultasi/'.$konsultasiId.'/rekam-medis', [
        'keluhan_utama' => 'Demam tiga hari',
        'subjektif' => 'Demam sejak Senin.',
        'objektif' => 'Suhu 38,5 derajat.',
        'asesmen' => 'Infeksi saluran napas atas.',
        'plan' => 'Analis dan terapi simptomatik.',
        'diagnosis_kerja' => 'Demam tanpa lokalisasi',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.rekam_medis.status_dokumen', 'draft');
    $response->assertJsonPath('data.rekam_medis.versi', 1);
    $response->assertJsonPath('data.rekam_medis.tipe_kunjungan', 'telemedisin');
    $response->assertJsonPath('data.rekam_medis.konsultasi_id', $konsultasiId);

    $id = (int) $response->json('data.rekam_medis.id');
    $stored = DB::table('rekam_medis')->where('id', $id)->first();

    expect($stored->status_dokumen)->toBe('draft')
        ->and((int) $stored->versi)->toBe(1)
        // `tanggal_periksa` is NOT NULL (:630) and the service defaults it to now.
        ->and($stored->tanggal_periksa)->not->toBeNull()
        ->and($stored->uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');

    // A CREATE opens nothing that already existed, and the log's five ENUM values
    // (:1151) are all read purposes - there is no `create` among them, so a creation
    // has no correct row here. Zero is the truthful count, not a missing one.
    expect(rmdJumlahLog($id))->toBe(0);
});

test('a record may only be created for the consultations own doctor', function (): void {
    $akun = rmdPatientAccount();
    $konsultasiId = rmdKonsultasi($akun['pasien'], $akun['dokter']);
    $asing = rmdDoctorAccount();

    rmdAs($asing['user'])->postJson('/api/v1/konsultasi/'.$konsultasiId.'/rekam-medis', [
        'keluhan_utama' => 'Milik dokter lain',
    ])->assertStatus(404);

    expect(DB::table('rekam_medis')->count())->toBe(0);
});

test('the created patient and doctor are read off the consultation, not the body', function (): void {
    $akun = rmdPatientAccount();
    $konsultasiId = rmdKonsultasi($akun['pasien'], $akun['dokter']);

    // `pasien_id` and `dokter_id` are `prohibited` on the request, so a body naming
    // another tenant is REFUSED BY NAME rather than quietly dropped. A doctor who
    // could set `pasien_id` would be writing into another patient's record, and a
    // silently-ignored field is how that gets shipped.
    $ditolak = rmdAs($akun['dokterUser'])->postJson('/api/v1/konsultasi/'.$konsultasiId.'/rekam-medis', [
        'keluhan_utama' => 'Demam',
        'pasien_id' => 999999,
        'dokter_id' => 999999,
    ]);

    $ditolak->assertStatus(422);
    $ditolak->assertJsonPath('errors.pasien_id.0', 'The pasien id field is prohibited.');
    $ditolak->assertJsonPath('errors.dokter_id.0', 'The dokter id field is prohibited.');

    expect(DB::table('rekam_medis')->count())->toBe(0);

    $response = rmdAs($akun['dokterUser'])->postJson('/api/v1/konsultasi/'.$konsultasiId.'/rekam-medis', [
        'keluhan_utama' => 'Demam',
    ]);

    $response->assertCreated();

    $stored = DB::table('rekam_medis')->where('id', (int) $response->json('data.rekam_medis.id'))->first();

    expect((int) $stored->pasien_id)->toBe($akun['pasien'])
        ->and((int) $stored->dokter_id)->toBe($akun['dokter'])
        ->and((int) $stored->konsultasi_id)->toBe($konsultasiId);
});

// =====================================================================
// Draft edits in place, final edits refused
// =====================================================================

test('a draft record can be edited in place', function (): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter'], ['keluhan_utama' => 'Demam']);
    $id = (int) $row->getKey();

    $response = rmdAs($akun['dokterUser'])->putJson('/api/v1/rekam-medis/'.$id, [
        'keluhan_utama' => 'Demam sejak empat hari',
        'asesmen' => 'Demam tanpa lokalisasi',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.rekam_medis.versi', 1);
    $response->assertJsonPath('data.rekam_medis.status_dokumen', 'draft');

    $stored = DB::table('rekam_medis')->where('id', $id)->first();

    expect($stored->keluhan_utama)->toBe('Demam sejak empat hari')
        ->and((int) $stored->versi)->toBe(1)
        ->and($stored->status_dokumen)->toBe('draft');

    // The write OPENED the row, so it is a read for logging purposes and logs once.
    expect(rmdJumlahLog($id))->toBe(1);
});

test('editing a final record is 422 and leaves the row byte identical', function (): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter'], [
        'status_dokumen' => 'final',
        'keluhan_utama' => 'Demam',
        'subjektif' => 'Demam sejak Senin.',
        'versi' => 2,
        'ditandatangani_at' => '2026-03-11 10:00:00',
    ]);
    $id = (int) $row->getKey();

    $sebelum = (array) DB::table('rekam_medis')->where('id', $id)->first();
    $logSebelum = rmdJumlahLog($id);

    $response = rmdAs($akun['dokterUser'])->putJson('/api/v1/rekam-medis/'.$id, [
        'keluhan_utama' => 'Berubah',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('success', false);
    $response->assertJsonPath(
        'errors.status_dokumen.0',
        'Rekam medis yang sudah final atau diamendemen tidak dapat diubah langsung. Gunakan endpoint amandemen.'
    );

    // BYTE identical, `diubah_at` included. `diubah_at` is
    // `ON UPDATE CURRENT_TIMESTAMP` (:649), so a mutation would move it even if every
    // clinical column matched - which is exactly why it is in the comparison.
    //
    // ZERO log rows, and that is the ATOMICITY rule rather than a gap: the log write
    // and the edit share one transaction and the 422 rolled both back. A log row for
    // an edit that did not happen would be a false record, which is worse than a
    // missing one. It is safe for this particular refusal to be unlogged because only
    // the record's OWN doctor can reach it, and a doctor who wrote the record already
    // knows whether it is a draft.
    expect((array) DB::table('rekam_medis')->where('id', $id)->first())->toBe($sebelum)
        ->and(DB::table('rekam_medis')->where('id', $id)->count())->toBe(1)
        ->and(rmdJumlahLog($id))->toBe($logSebelum);
});

test('a finalisation stamps final and ditandatangani_at exactly once', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-03-11T10:00:00Z'));

    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter'], ['keluhan_utama' => 'Demam']);
    $id = (int) $row->getKey();

    $response = rmdAs($akun['dokterUser'])->putJson('/api/v1/rekam-medis/'.$id.'/final');
    $response->assertOk();
    $response->assertJsonPath('data.rekam_medis.status_dokumen', 'final');
    $response->assertJsonPath('data.rekam_medis.versi', 1);
    // `toISOString()` on Carbon emits microseconds, which is what
    // `KonsultasiResource` publishes for `mulai_at` too - asserted here as the exact
    // string so a future change to the format cannot pass unnoticed.
    $response->assertJsonPath('data.rekam_medis.ditandatangani_at', '2026-03-11T10:00:00.000000Z');

    // A SECOND finalisation is 422, moves nothing, and logs NOTHING - the log row
    // and the operation share one transaction, so a refused operation leaves no trace
    // in either table.
    $kedua = rmdAs($akun['dokterUser'])->putJson('/api/v1/rekam-medis/'.$id.'/final');
    $kedua->assertStatus(422);

    expect((string) DB::table('rekam_medis')->where('id', $id)->value('ditandatangani_at'))
        ->toBe('2026-03-11 10:00:00')
        ->and(rmdJumlahLog($id))->toBe(1);
});

test('a finalisation of an already final record refuses and does not re-sign', function (): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter'], [
        'status_dokumen' => 'final',
        'ditandatangani_at' => '2026-03-11 10:00:00',
    ]);

    rmdAs($akun['dokterUser'])->putJson('/api/v1/rekam-medis/'.$row->getKey().'/final')->assertStatus(422);

    expect(DB::table('rekam_medis')->where('id', $row->getKey())->value('ditandatangani_at'))
        ->toBe('2026-03-11 10:00:00');
});

// =====================================================================
// The amendment chain
// =====================================================================

test('an amendment supersedes rather than mutates, and the chain is not truncated', function (): void {
    $akun = rmdPatientAccount();
    $asli = rmdRecord($akun['pasien'], $akun['dokter'], [
        'status_dokumen' => 'final',
        'keluhan_utama' => 'Demam',
        'subjektif' => 'Demam sejak Senin.',
        'diagnosis_kerja' => 'Demam tanpa lokalisasi',
        'ditandatangani_at' => '2026-03-11 10:00:00',
    ]);
    $asliId = (int) $asli->getKey();
    $sebelum = (array) DB::table('rekam_medis')->where('id', $asliId)->first();

    $response = rmdAs($akun['dokterUser'])->postJson('/api/v1/rekam-medis/'.$asliId.'/amandemen', [
        'perubahan' => [
            'keluhan_utama' => 'Demam dan productiveBatuk',
            'diagnosis_kerja' => 'Infeksi saluran napas atas',
        ],
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.rekam_medis.versi', 2);
    $response->assertJsonPath('data.rekam_medis.status_dokumen', 'diamendemen');

    $baruId = (int) $response->json('data.rekam_medis.id');

    // A NEW row, with a new id and a new uuid. `uuid` is `CHAR(36) NOT NULL UNIQUE`
    // (:623), so a reused uuid would be a 1062 rather than a second document.
    expect($baruId)->not->toBe($asliId)
        ->and(DB::table('rekam_medis')->where('id', $baruId)->value('uuid'))->not->toBe($sebelum['uuid']);

    // The ORIGINAL is byte identical, `diubah_at` included. A supersede that mutated the
    // old row would be indistinguishable from an in-place edit in the audit trail,
    // which is the whole thing this feature exists to prevent.
    expect((array) DB::table('rekam_medis')->where('id', $asliId)->first())->toBe($sebelum);

    // The amendment is a MERGE: the fields the caller did not mention are carried over
    // from the record it supersedes, so the chain reads as a document rather than as a
    // diff.
    $baru = (array) DB::table('rekam_medis')->where('id', $baruId)->first();
    expect($baru['keluhan_utama'])->toBe('Demam dan productiveBatuk')
        ->and($baru['diagnosis_kerja'])->toBe('Infeksi saluran napas atas')
        ->and($baru['subjektif'])->toBe('Demam sejak Senin.')
        ->and((int) $baru['versi'])->toBe(2)
        ->and($baru['status_dokumen'])->toBe('diamendemen')
        ->and((int) $baru['pasien_id'])->toBe($akun['pasien'])
        ->and((int) $baru['dokter_id'])->toBe($akun['dokter'])
        ->and($baru['tanggal_periksa'])->toBe($sebelum['tanggal_periksa']);

    // The chain is walked by the caller and is not truncated: the detail response
    // publishes EVERY version, ascending, and names the current one.
    $detail = rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$baruId);
    $detail->assertOk();
    $detail->assertJsonCount(2, 'data.rekam_medis.ran');
    $detail->assertJsonPath('data.rekam_medis.ran.0.versi', 1);
    $detail->assertJsonPath('data.rekam_medis.ran.0.id', $asliId);
    $detail->assertJsonPath('data.rekam_medis.ran.0.status_dokumen', 'final');
    $detail->assertJsonPath('data.rekam_medis.ran.1.versi', 2);
    $detail->assertJsonPath('data.rekam_medis.ran.1.id', $baruId);
    $detail->assertJsonPath('data.rekam_medis.ran.1.status_dokumen', 'diamendemen');
    $detail->assertJsonPath('data.rekam_medis.versi', 2);
    $detail->assertJsonPath('data.rekam_medis.adalah_versi_terkini', true);
});

test('a three version chain keeps all three rows and names the highest as current', function (): void {
    $akun = rmdPatientAccount();
    $v1 = rmdRecord($akun['pasien'], $akun['dokter'], [
        'status_dokumen' => 'final',
        'keluhan_utama' => 'Satu',
        'ditandatangani_at' => '2026-03-11 10:00:00',
    ]);
    $v2 = (int) rmdAs($akun['dokterUser'])
        ->postJson('/api/v1/rekam-medis/'.$v1->getKey().'/amandemen', ['perubahan' => ['keluhan_utama' => 'Dua']])
        ->assertCreated()->json('data.rekam_medis.id');
    $v3 = (int) rmdAs($akun['dokterUser'])
        ->postJson('/api/v1/rekam-medis/'.$v2.'/amandemen', ['perubahan' => ['keluhan_utama' => 'Tiga']])
        ->assertCreated()->json('data.rekam_medis.id');

    // Three rows in ONE group. Nothing was deleted or overwritten, and the two
    // superseded rows are still individually addressable.
    expect(DB::table('rekam_medis')->where('pasien_id', $akun['pasien'])
        ->where('dokter_id', $akun['dokter'])
        ->where('tanggal_periksa', RMD_TANGGAL)
        ->count())->toBe(3);

    // Reading the CURRENT row publishes the whole chain and logs ONE row, naming the
    // addressed record. The chain inclusion does not multiply the count: the log's
    // `rekam_medis_id` is a single foreign key to a single row (:1149), the two
    // superseded rows are frozen and are not the document the caller addressed, and
    // their own ids are published inside the `ran` block so the trail is complete
    // without three inserts.
    $detail = rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$v3);
    $detail->assertOk();
    $detail->assertJsonCount(3, 'data.rekam_medis.ran');
    $detail->assertJsonPath('data.rekam_medis.ran.0.versi', 1);
    $detail->assertJsonPath('data.rekam_medis.ran.1.versi', 2);
    $detail->assertJsonPath('data.rekam_medis.ran.2.versi', 3);
    $detail->assertJsonPath('data.rekam_medis.adalah_versi_terkini', true);

    // The exact per-record counts, which are NOT all one and are the point of the
    // test. v1 was the parent of the first amendment and so was opened once; v2 was
    // the parent of the second and was opened once; v3 has been read once and never
    // written through. One row per COMPLETED operation that opened a record, never
    // more - three operations, three rows, on three different records.
    expect(rmdJumlahLog((int) $v1->getKey()))->toBe(1)
        ->and(rmdJumlahLog($v2))->toBe(1)
        ->and(rmdJumlahLog($v3))->toBe(1)
        ->and(DB::table('akses_rekam_medis_log')->count())->toBe(3);
});

test('reading a superseded revision names that revision in the log', function (): void {
    $akun = rmdPatientAccount();
    $v1 = rmdRecord($akun['pasien'], $akun['dokter'], [
        'status_dokumen' => 'final',
        'ditandatangani_at' => '2026-03-11 10:00:00',
    ]);
    $v2 = (int) rmdAs($akun['dokterUser'])
        ->postJson('/api/v1/rekam-medis/'.$v1->getKey().'/amandemen', ['perubahan' => ['keluhan_utama' => 'Dua']])
        ->assertCreated()->json('data.rekam_medis.id');

    // A superseded row is not hidden - the patient is entitled to their own history -
    // and reading it directly is one access of ONE record, so it adds ONE row naming
    // that record, and it reports that it is NOT the current version. v1 now carries
    // TWO rows: one for the amendment that superseded it and one for this read.
    $response = rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$v1->getKey());

    $response->assertOk();
    $response->assertJsonPath('data.rekam_medis.versi', 1);
    $response->assertJsonPath('data.rekam_medis.adalah_versi_terkini', false);
    $response->assertJsonCount(2, 'data.rekam_medis.ran');

    expect(rmdJumlahLog((int) $v1->getKey()))->toBe(2)
        ->and(rmdJumlahLog($v2))->toBe(0);
});

test('amending a superseded revision takes the next free version, never a duplicate', function (): void {
    $akun = rmdPatientAccount();
    $v1 = rmdRecord($akun['pasien'], $akun['dokter'], [
        'status_dokumen' => 'final',
        'ditandatangani_at' => '2026-03-11 10:00:00',
    ]);
    $v2 = (int) rmdAs($akun['dokterUser'])
        ->postJson('/api/v1/rekam-medis/'.$v1->getKey().'/amandemen', ['perubahan' => ['keluhan_utama' => 'Dua']])
        ->assertCreated()->json('data.rekam_medis.id');

    // Amending the OLDEST row is legal, and it must NOT produce another `versi = 1` or
    // another `versi = 2`. The plan says "old.versi + 1", which for a superseded row is
    // 2 - a duplicate - and a group holding two rows at one version cannot be ordered
    // at all, so "current = highest version" would become ambiguous. This is the one
    // place the service is deliberately stricter than the plan's sentence and the
    // deviation is reported.
    $v3 = rmdAs($akun['dokterUser'])
        ->postJson('/api/v1/rekam-medis/'.$v1->getKey().'/amandemen', ['perubahan' => ['keluhan_utama' => 'Tiga']]);

    $v3->assertCreated();
    $v3->assertJsonPath('data.rekam_medis.versi', 3);

    $versi = DB::table('rekam_medis')->where('pasien_id', $akun['pasien'])
        ->where('dokter_id', $akun['dokter'])
        ->where('tanggal_periksa', RMD_TANGGAL)
        ->orderBy('versi')
        ->pluck('versi')
        ->map(static fn ($v): int => (int) $v)
        ->all();

    expect($versi)->toBe([1, 2, 3]);
});

test('two visits on different dates are two independent chains', function (): void {
    $akun = rmdPatientAccount();
    $hariA = rmdRecord($akun['pasien'], $akun['dokter'], [
        'status_dokumen' => 'final',
        'tanggal_periksa' => '2026-03-11 09:30:00',
        'ditandatangani_at' => '2026-03-11 10:00:00',
    ]);
    $hariB = rmdRecord($akun['pasien'], $akun['dokter'], [
        'status_dokumen' => 'final',
        'tanggal_periksa' => '2026-03-18 09:30:00',
        'ditandatangani_at' => '2026-03-18 10:00:00',
    ]);

    $a2 = (int) rmdAs($akun['dokterUser'])
        ->postJson('/api/v1/rekam-medis/'.$hariA->getKey().'/amandemen', ['perubahan' => ['keluhan_utama' => 'A2']])
        ->assertCreated()->json('data.rekam_medis.id');
    $b2 = (int) rmdAs($akun['dokterUser'])
        ->postJson('/api/v1/rekam-medis/'.$hariB->getKey().'/amandemen', ['perubahan' => ['keluhan_utama' => 'B2']])
        ->assertCreated()->json('data.rekam_medis.id');

    // `tanggal_periksa` is part of the group key (:630), so two visits on different days
    // are two chains and each amendment is `versi = 2`, not 3. If the key were
    // `(pasien_id, dokter_id)` alone - which is what dropping the date would give - the
    // second amendment would be 4 and the two visits' history would be fused into one
    // unreadable document.
    expect((int) DB::table('rekam_medis')->where('id', $a2)->value('versi'))->toBe(2)
        ->and((int) DB::table('rekam_medis')->where('id', $b2)->value('versi'))->toBe(2);

    rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$a2)->assertJsonCount(2, 'data.rekam_medis.ran');
});

test('amending a draft is 422 and points the caller at the in-place path', function (): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter'], ['keluhan_utama' => 'Masih draft']);

    $response = rmdAs($akun['dokterUser'])->postJson('/api/v1/rekam-medis/'.$row->getKey().'/amandemen', [
        'perubahan' => ['keluhan_utama' => 'Berubah'],
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('errors.status_dokumen.0', 'Hanya rekam medis yang sudah final atau diamendemen dapat diamendemen.');

    // The refusal rolls the log row back with the operation, so a draft that was never
    // amended has no access row naming it either.
    expect(DB::table('rekam_medis')->where('id', $row->getKey())->value('keluhan_utama'))->toBe('Masih draft')
        ->and(DB::table('rekam_medis')->where('pasien_id', $akun['pasien'])->count())->toBe(1)
        ->and(rmdJumlahLog((int) $row->getKey()))->toBe(0);
});

test('another doctors record is never reached by an amendment', function (): void {
    $milik = rmdPatientAccount();
    $row = rmdRecord($milik['pasien'], $milik['dokter'], [
        'status_dokumen' => 'final',
        'ditandatangani_at' => '2026-03-11 10:00:00',
    ]);
    $asing = rmdDoctorAccount();

    rmdAs($asing['user'])->postJson('/api/v1/rekam-medis/'.$row->getKey().'/amandemen', [
        'perubahan' => ['keluhan_utama' => 'Milik orang lain'],
    ])->assertStatus(404);

    expect(DB::table('rekam_medis')->where('pasien_id', $milik['pasien'])->count())->toBe(1)
        ->and(rmdJumlahLog((int) $row->getKey()))->toBe(0);
});

test('the 256th amendment is refused with 422 rather than a MySQL 1264', function (): void {
    $akun = rmdPatientAccount();
    $v1 = rmdRecord($akun['pasien'], $akun['dokter'], [
        'status_dokumen' => 'final',
        'ditandatangani_at' => '2026-03-11 10:00:00',
    ]);

    // `versi` is TINYINT UNSIGNED (:646), so 255 is the ceiling and 256 is a MySQL 1264
    // - an opaque driver error, which `bootstrap/app.php` renders as a sanitised 500.
    // The 254 remaining rows are written directly, because the point is the boundary
    // and not 255 HTTP round trips.
    $rows = [];

    for ($versi = 2; $versi <= 255; $versi++) {
        $rows[] = [
            'uuid' => (string) Str::uuid(),
            'pasien_id' => $akun['pasien'],
            'dokter_id' => $akun['dokter'],
            'tipe_kunjungan' => 'telemedisin',
            'tanggal_periksa' => RMD_TANGGAL,
            'status_dokumen' => 'diamendemen',
            'versi' => $versi,
        ];
    }

    DB::table('rekam_medis')->insert($rows);

    $response = rmdAs($akun['dokterUser'])->postJson('/api/v1/rekam-medis/'.$v1->getKey().'/amandemen', [
        'perubahan' => ['keluhan_utama' => 'Terlalu banyak'],
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('errors.versi.0', 'Rekam medis ini sudah mencapai versi maksimum yang diizinkan skema (255).');

    expect(DB::table('rekam_medis')->where('pasien_id', $akun['pasien'])->count())->toBe(255);
});

// =====================================================================
// The sub-entities
// =====================================================================

test('a sub entity is added to a draft and refused on a final record', function (): void {
    $akun = rmdPatientAccount();
    [$draft, $anak] = rmdRecordLengkap($akun['pasien'], $akun['dokter'], (int) $akun['user']->getKey());
    $final = rmdRecord($akun['pasien'], $akun['dokter'], [
        'status_dokumen' => 'final',
        'tanggal_periksa' => '2026-03-12 09:30:00',
        'ditandatangani_at' => '2026-03-12 10:00:00',
    ]);

    $service = app(RekamMedisService::class);
    $dokterUser = $akun['dokterUser'];

    $baru = $service->tambahDiagnosa((int) $draft->getKey(), [
        'icd10_kode' => 'J06.9',
        'jenis' => 'sekunder',
        'tipe_kasus' => 'lama',
    ], $dokterUser);

    expect((int) $baru->rekam_medis_id)->toBe((int) $draft->getKey())
        ->and($baru->jenis)->toBe('sekunder')
        ->and($anak)->toHaveKeys(['diagnosa', 'tindakan', 'lampiran', 'persetujuan']);

    // The gate is INHERITED rather than restated, and the parent is OPENED to apply
    // it - which is a read, so it logs.
    expect(rmdJumlahLog((int) $draft->getKey()))->toBe(1);

    try {
        $service->tambahDiagnosa((int) $final->getKey(), ['icd10_kode' => 'R50.9', 'jenis' => 'utama'], $dokterUser);
        expect(false)->toBeTrue('tambahDiagnosa() pada rekam medis final seharusnya ditolak.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('status_dokumen');
    }

    expect(DB::table('rekam_medis_diagnosa')->where('rekam_medis_id', $final->getKey())->count())->toBe(0);
});

test('a sub entity cannot be written by an account that is not the records doctor', function (): void {
    $akun = rmdPatientAccount();
    [$draft] = rmdRecordLengkap($akun['pasien'], $akun['dokter'], (int) $akun['user']->getKey());
    $asing = rmdDoctorAccount();

    try {
        app(RekamMedisService::class)->tambahTindakan(
            (int) $draft->getKey(),
            ['nama_tindakan' => 'Dianam oleh dokter lain'],
            $asing['user'],
        );
        expect(false)->toBeTrue('tambahTindakan() oleh dokter lain seharusnya ditolak.');
    } catch (ModelNotFoundException) {
        expect(rmdJumlahLog((int) $draft->getKey()))->toBe(0);
    }

    expect(DB::table('rekam_medis_tindakan')->where('rekam_medis_id', $draft->getKey())->count())->toBe(1);
});

test('an unknown column name is refused rather than silently ignored', function (): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter']);

    // The same defect todo 32 found in `KonsultasiService::tulisSoap()`: a misspelled
    // field name would otherwise validate, be accepted, and write nowhere - with no
    // error at all. `FormRequest::validated()` strips undeclared keys, so the request
    // is where this has to be caught; the service's own check covers the NESTED
    // `perubahan` set, which the next test proves.
    $response = rmdAs($akun['dokterUser'])->putJson('/api/v1/rekam-medis/'.$row->getKey(), [
        'keluhan_utma' => 'Salah ketik',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('success', false);
    expect($response->json('errors'))->toHaveKey('keluhan_utma');

    expect(DB::table('rekam_medis')->where('id', $row->getKey())->value('keluhan_utama'))->toBeNull()
        ->and(rmdJumlahLog((int) $row->getKey()))->toBe(0);
});

// =====================================================================
// The envelope
// =====================================================================

test('the detail response publishes the whole record and no more', function (): void {
    $akun = rmdPatientAccount();
    [$row] = rmdRecordLengkap($akun['pasien'], $akun['dokter'], (int) $akun['user']->getKey());

    $response = rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$row->getKey());

    $response->assertOk();
    $response->assertJsonStructure([
        'success',
        'data' => ['rekam_medis' => [
            'id', 'uuid', 'pasien_id', 'faskes_id', 'dokter_id', 'konsultasi_id',
            'tipe_kunjungan', 'tanggal_periksa', 'keluhan_utama',
            'riwayat_penyakit_sekarang', 'riwayat_penyakit_dahulu', 'riwayat_keluarga',
            'riwayat_psikososial', 'hasil_pemeriksaan_fisik', 'subjektif', 'objektif',
            'asesmen', 'plan', 'diagnosis_kerja', 'instruksi_tindak_lanjut',
            'status_tindak_lanjut', 'jadwal_kontrol', 'status_dokumen', 'versi',
            'ditandatangani_at', 'dibuat_at', 'diubah_at', 'adalah_versi_terkini',
            'pasien' => ['id', 'nik', 'nama_lengkap'],
            'dokter' => ['id', 'nama_lengkap'],
            'diagnosa', 'tindakan', 'lampiran', 'persetujuan', 'ran',
        ]],
        'message',
    ]);

    // `meta` is a TOP-LEVEL SIBLING of `data` and `message`, and this is not a list, so
    // the key is ABSENT rather than null - `ApiResponse`'s own documented rule.
    $raw = (string) $response->getContent();
    $body = json_decode($raw, true);

    expect($body)->toHaveKeys(['success', 'data', 'message'])
        ->and($body)->not->toHaveKey('meta')
        ->and($body['success'])->toBeTrue()
        ->and($body['data']['rekam_medis']['versi'])->toBeInt()
        ->and($body['data']['rekam_medis']['ran'])->toBeArray();

    // A NIK is never published raw, and the access log is written FOR the audit trail
    // rather than handed back to the caller who caused it. The mask is asserted
    // through the PROJECT's own masker rather than through a hand-typed pattern - a
    // hand-typed regex is exactly the kind of literal that silently becomes a
    // different string, and the masker is the authority anyway.
    //
    // Read through the MODEL, not through `DB::table()->value('nik')`: the column
    // is `nik_cipher` and holds a payload, so the plaintext exists only after
    // `Pasien`'s accessor decrypts it. Reading it that way also means this line
    // asserts the real read path works, which is the thing the migration changed.
    $nik = (string) Pasien::query()->findOrFail($akun['pasien'])->nik;
    $diterbitkan = (string) $body['data']['rekam_medis']['pasien']['nik'];

    expect($diterbitkan)->not->toBe($nik)
        ->and($diterbitkan)->toBe(NikMasker::mask($nik))
        ->and(mb_strlen($diterbitkan))->toBe(16)
        // `mb_substr`, NOT `substr`: the mask character is U+2022, which is THREE
        // BYTES, so the byte length of a 16-character identifier is 32 and
        // `substr($s, 4, 8)` would slice into the middle of a bullet run. Asserting the
        // byte slice would be asserting a bug.
        ->and(mb_substr($diterbitkan, 4, 8))->toBe(str_repeat("\u{2022}", 8))
        ->and($raw)->not->toContain('kata_sandi_hash')
        ->and($raw)->not->toContain('akses_rekam_medis_log')
        ->and($raw)->not->toContain('pengakses_user_id');
});

test('a 422 can carry more than one message for one field and keeps both', function (): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter'], ['keluhan_utama' => 'Masih draft']);

    // An empty `perubahan` on a DRAFT record violates two independent rules about the
    // change set: it is empty, and it changes nothing. Neither hides the other.
    $response = rmdAs($akun['dokterUser'])->postJson('/api/v1/rekam-medis/'.$row->getKey().'/amandemen', [
        'perubahan' => [],
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('success', false);

    $errors = $response->json('errors');

    // TWO messages on ONE key. `ValidationException::withMessages()` appends when the
    // same key is given twice, and that is the only reason a caller learns both facts.
    // An array assignment would publish one and lose the other.
    expect($errors)->toHaveKey('perubahan')
        ->and($errors['perubahan'])->toHaveCount(2)
        ->and($errors['perubahan'][0])->toBe('Perubahan tidak boleh kosong.')
        ->and($errors['perubahan'][1])->toBe('Perubahan tidak mengubah nilai kolom mana pun.')
        ->and($errors)->toHaveKey('status_dokumen')
        ->and($response->json('message'))->toBe('The given data was invalid.');
});

test('a change set that names no real column is refused with 422 and writes nothing', function (): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter'], [
        'status_dokumen' => 'final',
        'keluhan_utama' => 'Demam',
        'ditandatangani_at' => '2026-03-11 10:00:00',
    ]);
    $sebelum = (array) DB::table('rekam_medis')->where('id', $row->getKey())->first();

    // The DDL has `subjektif`/`objektif`/`asesmen`/`plan` (:637-640). `asesment` is the
    // spelling a reader expects and it is NOT a column, so it must be refused rather
    // than skipped.
    $response = rmdAs($akun['dokterUser'])->postJson('/api/v1/rekam-medis/'.$row->getKey().'/amandemen', [
        'perubahan' => ['asesment' => 'Salah ketik'],
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('errors.perubahan.0', 'Kolom rekam medis tidak dikenal: asesment.');

    expect((array) DB::table('rekam_medis')->where('id', $row->getKey())->first())->toBe($sebelum)
        ->and(DB::table('rekam_medis')->where('pasien_id', $akun['pasien'])->count())->toBe(1);
});

// =====================================================================
// F10: the patient's own list, and the access log of one record
// =====================================================================

/**
 * The fields `RekamMedisDaftarResource` publishes, in order.
 *
 * Asserted by VALUE so a future widening of the list resource is visible instead of
 * silent. No NIK, no contact detail, no SOAP note, no child collection and no
 * `pasien_id`/`faskes_id`/`konsultasi_id` identity column is in this set.
 *
 * @var list<string>
 */
const RMD_KUNCI_DAFTAR = [
    'id',
    'uuid',
    'tanggal_periksa',
    'keluhan_utama',
    'diagnosis_kerja',
    'status_dokumen',
    'versi',
    'adalah_versi_terkini',
    'dokter',
];

test('the patient list returns only the caller own records and no clinical detail', function (): void {
    $akun = rmdPatientAccount();
    $lain = rmdPatientAccount();

    $milik = rmdRecord($akun['pasien'], $akun['dokter'], [
        'keluhan_utama' => 'Batuk tiga hari',
        'diagnosis_kerja' => 'ISPA',
        'status_dokumen' => 'final',
    ]);
    rmdRecord($lain['pasien'], $lain['dokter'], ['keluhan_utama' => 'Batuk tiga hari']);

    $response = rmdAs($akun['user'])->getJson('/api/v1/rekam-medis');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.total', 1);

    $rows = $response->json('data.rekam_medis');

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]['id'])->toBe((int) $milik->getKey())
        ->and($rows[0]['uuid'])->toBe((string) $milik->uuid)
        ->and($rows[0]['keluhan_utama'])->toBe('Batuk tiga hari')
        ->and($rows[0]['diagnosis_kerja'])->toBe('ISPA')
        ->and($rows[0]['status_dokumen'])->toBe('final')
        ->and($rows[0]['versi'])->toBe(1)
        ->and($rows[0]['adalah_versi_terkini'])->toBeTrue()
        ->and($rows[0]['dokter']['nama_lengkap'])->toBe($akun['dokterUser']->nama_lengkap)
        ->and(array_keys($rows[0]))->toBe(RMD_KUNCI_DAFTAR)
        ->and($rows[0])->not->toHaveKey('pasien_id')
        ->and($rows[0])->not->toHaveKey('pasien')
        ->and($rows[0])->not->toHaveKey('subjektif')
        ->and($rows[0])->not->toHaveKey('ran');

    // The raw body names no NIK column and no contact column, and the foreign
    // patient's record is simply not in it.
    $raw = (string) $response->getContent();

    expect($raw)->not->toContain('nik')
        ->and($raw)->not->toContain('no_telepon')
        ->and($raw)->not->toContain('kata_sandi_hash')
        ->and($raw)->not->toContain('akses_rekam_medis_log')
        ->and($raw)->not->toContain('pengakses_user_id');

    // `meta` is a top-level sibling, never nested inside `data`.
    $body = json_decode($raw, true);

    expect($body)->toHaveKeys(['success', 'data', 'message', 'meta'])
        ->and($body['data'])->not->toHaveKey('meta');
});

test('a patient account with no pasien row is refused 403 on the list', function (): void {
    // A `pasien`-typed account with NO `pasien` row: the refusal is about the
    // caller, which is why it is a 403 and not an empty list.
    $user = rmdPengguna('pasien', 'pasien');

    $response = rmdAs($user)->getJson('/api/v1/rekam-medis');

    $response->assertStatus(403)->assertJsonPath('success', false);
});

test('the q filter searches keluhan_utama and diagnosis_kerja, only within the caller records', function (): void {
    $akun = rmdPatientAccount();
    $lain = rmdPatientAccount();

    $batuk = rmdRecord($akun['pasien'], $akun['dokter'], [
        'keluhan_utama' => 'Batuk berdahak',
        'tanggal_periksa' => '2026-03-10 09:00:00',
    ]);
    $hipertensi = rmdRecord($akun['pasien'], $akun['dokter'], [
        'keluhan_utama' => 'Kontrol rutin',
        'diagnosis_kerja' => 'Hipertensi',
        'tanggal_periksa' => '2026-03-12 09:00:00',
    ]);
    // Another patient's record holds the same term. The tenant filter is the
    // query, so it cannot surface here.
    rmdRecord($lain['pasien'], $lain['dokter'], [
        'keluhan_utama' => 'Batuk berdahak',
        'tanggal_periksa' => '2026-03-11 09:00:00',
    ]);

    $cari = function (string $q) use ($akun): array {
        $response = rmdAs($akun['user'])->getJson('/api/v1/rekam-medis?'.http_build_query(['q' => $q]));
        $response->assertOk();

        return array_column($response->json('data.rekam_medis'), 'id');
    };

    // Search on the complaint, scoped to the caller.
    expect($cari('Batuk'))->toBe([(int) $batuk->getKey()]);

    // Search on the working diagnosis - the column's value is not in `keluhan_utama`.
    expect($cari('Hipertensi'))->toBe([(int) $hipertensi->getKey()]);

    // The collation is `utf8mb4_unicode_ci`, so the search is case-insensitive.
    expect($cari('hipertensi'))->toBe([(int) $hipertensi->getKey()]);

    // A term no own record holds answers an empty page, not another patient's row.
    expect($cari('Tidak Ada'))->toBe([]);
});

test('the q filter treats LIKE wildcards as literal characters', function (): void {
    $akun = rmdPatientAccount();

    $persen = rmdRecord($akun['pasien'], $akun['dokter'], [
        'keluhan_utama' => 'Reaksi obat 50% dosis',
        'tanggal_periksa' => '2026-03-10 09:00:00',
    ]);
    // If `%` were left unescaped, this row would match `50%` too.
    rmdRecord($akun['pasien'], $akun['dokter'], [
        'keluhan_utama' => 'Reaksi obat 500 dosis',
        'tanggal_periksa' => '2026-03-11 09:00:00',
    ]);

    $response = rmdAs($akun['user'])
        ->getJson('/api/v1/rekam-medis?'.http_build_query(['q' => '50%']));

    $response->assertOk();

    expect(array_column($response->json('data.rekam_medis'), 'id'))
        ->toBe([(int) $persen->getKey()]);
});

test('the date filters bound tanggal_periksa inclusively and refuse an inverted range', function (): void {
    $akun = rmdPatientAccount();

    $awal = rmdRecord($akun['pasien'], $akun['dokter'], [
        'keluhan_utama' => 'Hari pertama',
        'tanggal_periksa' => '2026-03-10 09:00:00',
    ]);
    $tengah = rmdRecord($akun['pasien'], $akun['dokter'], [
        'keluhan_utama' => 'Hari kedua',
        'tanggal_periksa' => '2026-03-11 09:30:00',
    ]);
    $akhir = rmdRecord($akun['pasien'], $akun['dokter'], [
        'keluhan_utama' => 'Hari ketiga',
        'tanggal_periksa' => '2026-03-12 09:00:00',
    ]);

    $cari = function (array $filter) use ($akun): array {
        $response = rmdAs($akun['user'])
            ->getJson('/api/v1/rekam-medis?'.http_build_query($filter));
        $response->assertOk();

        return array_column($response->json('data.rekam_medis'), 'id');
    };

    // Whole-day bounds: a record at 09:30:00 inside the day is included.
    expect($cari(['tanggal_dari' => '2026-03-11']))->toBe([
        (int) $akhir->getKey(),
        (int) $tengah->getKey(),
    ]);

    expect($cari(['tanggal_sampai' => '2026-03-11']))->toBe([
        (int) $tengah->getKey(),
        (int) $awal->getKey(),
    ]);

    expect($cari(['tanggal_dari' => '2026-03-11', 'tanggal_sampai' => '2026-03-11']))
        ->toBe([(int) $tengah->getKey()]);

    expect($cari(['tanggal_dari' => '2026-03-13']))->toBe([]);

    // An inverted range is a 422 naming the field, not an empty page.
    $response = rmdAs($akun['user'])->getJson(
        '/api/v1/rekam-medis?'.http_build_query([
            'tanggal_dari' => '2026-03-11',
            'tanggal_sampai' => '2026-03-01',
        ])
    );

    $response->assertStatus(422)->assertJsonPath('errors.tanggal_sampai.0', 'Tanggal akhir tidak boleh mendahului tanggal awal.');
});

test('the list paginates with the project meta block, newest first, and caps per_page at 100', function (): void {
    $akun = rmdPatientAccount();

    rmdRecord($akun['pasien'], $akun['dokter'], [
        'keluhan_utama' => 'Tertua',
        'tanggal_periksa' => '2026-03-10 09:00:00',
    ]);
    rmdRecord($akun['pasien'], $akun['dokter'], [
        'keluhan_utama' => 'Tengah',
        'tanggal_periksa' => '2026-03-11 09:00:00',
    ]);
    $terbaru = rmdRecord($akun['pasien'], $akun['dokter'], [
        'keluhan_utama' => 'Terbaru',
        'tanggal_periksa' => '2026-03-12 09:00:00',
    ]);

    $response = rmdAs($akun['user'])->getJson('/api/v1/rekam-medis?per_page=2');

    $response->assertOk()
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.from', 1)
        ->assertJsonPath('meta.to', 2)
        ->assertJsonPath('data.rekam_medis.0.keluhan_utama', 'Terbaru')
        ->assertJsonPath('data.rekam_medis.0.id', (int) $terbaru->getKey());

    $halamanDua = rmdAs($akun['user'])->getJson('/api/v1/rekam-medis?per_page=2&page=2');

    $halamanDua->assertOk()
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.from', 3)
        ->assertJsonPath('meta.to', 3)
        ->assertJsonPath('data.rekam_medis.0.keluhan_utama', 'Tertua');

    // The cap is enforced twice: `IndexRekamMedisRequest` refuses a value over 100
    // with a 422 naming the field, and the service clamps whatever else arrives.
    rmdAs($akun['user'])->getJson('/api/v1/rekam-medis?per_page=101')
        ->assertStatus(422)
        ->assertJsonPath('errors.per_page.0', 'Per halaman maksimal 100.');

    rmdAs($akun['user'])->getJson('/api/v1/rekam-medis?per_page=100')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

test('records sharing an examination instant are ordered by id descending', function (): void {
    $akun = rmdPatientAccount();

    $lama = rmdRecord($akun['pasien'], $akun['dokter'], [
        'keluhan_utama' => 'Sesi lama',
        'tanggal_periksa' => '2026-03-09 09:00:00',
    ]);
    // The chain group is (pasien_id, dokter_id, tanggal_periksa), so these two rows
    // share a group AND a `tanggal_periksa` second: only the id tie-breaker can
    // order them, and insertion order says the later id is newer.
    $pertama = rmdRecord($akun['pasien'], $akun['dokter'], [
        'keluhan_utama' => 'Sesi pertama',
        'tanggal_periksa' => RMD_TANGGAL,
    ]);
    $kedua = rmdRecord($akun['pasien'], $akun['dokter'], [
        'keluhan_utama' => 'Sesi kedua',
        'tanggal_periksa' => RMD_TANGGAL,
    ]);

    $response = rmdAs($akun['user'])->getJson('/api/v1/rekam-medis');

    $response->assertOk();

    expect(array_column($response->json('data.rekam_medis'), 'id'))->toBe([
        (int) $kedua->getKey(),
        (int) $pertama->getKey(),
        (int) $lama->getKey(),
    ]);
});

test('the list writes no access-log row, and the count before and after is the same', function (): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter']);

    // One logged DETAIL read first, so the count under observation is not trivially
    // zero: if the list did write a row per record, this would move to 2 and the
    // assertion below would catch it.
    rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$row->getKey())->assertOk();

    expect(DB::table('akses_rekam_medis_log')->count())->toBe(1);

    rmdAs($akun['user'])->getJson('/api/v1/rekam-medis')->assertOk();

    expect(DB::table('akses_rekam_medis_log')->count())->toBe(1)
        ->and(rmdJumlahLog((int) $row->getKey()))->toBe(1);
});

test('the access log endpoint returns the record history role-only and newest first', function (): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter'], ['keluhan_utama' => 'Demam']);
    $id = (int) $row->getKey();

    // Two distinct accesses. The doctor's is first, the patient's own is second, and
    // they are typically in the same `TIMESTAMP` second - so the `id DESC`
    // tie-breaker is what puts the patient's row first.
    rmdAs($akun['dokterUser'])->getJson('/api/v1/rekam-medis/'.$id)->assertOk();
    rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$id)->assertOk();

    $sebelum = DB::table('akses_rekam_medis_log')->count();

    $response = rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$id.'/akses');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.total', 2);

    $rows = $response->json('data.akses');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['tujuan_akses'])->toBe('pasien_sendiri')
        ->and($rows[0]['peran'])->toBe('pasien')
        ->and($rows[1]['tujuan_akses'])->toBe('perawatan')
        ->and($rows[1]['peran'])->toBe('dokter')
        ->and(array_keys($rows[0]))->toBe(['waktu', 'peran', 'tujuan_akses'])
        ->and($rows[0]['waktu'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');

    // The actor's NAME is not published. `users.tipe` (the `peran` above) is the
    // coarse answer; `pengakses_user_id` and `nama_lengkap` never reach the wire.
    $raw = (string) $response->getContent();

    expect($raw)->not->toContain((string) $akun['dokterUser']->nama_lengkap)
        ->and($raw)->not->toContain('pengakses_user_id')
        ->and($raw)->not->toContain('rekam_medis_id')
        ->and($raw)->not->toContain('nik');

    // Fetching the log is not reading the record, so it writes no log row either.
    expect(DB::table('akses_rekam_medis_log')->count())->toBe($sebelum);
});

test('the access log endpoint 404s another patient record and 403s an account with no profile', function (): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter']);

    // Another patient owns a `pasien` row, so the record is simply not found - 404,
    // never 403, so the endpoint is not an existence oracle.
    $lain = rmdPatientAccount();
    rmdAs($lain['user'])->getJson('/api/v1/rekam-medis/'.$row->getKey().'/akses')->assertNotFound();
    rmdAs($lain['user'])->getJson('/api/v1/rekam-medis/'.$row->getKey())->assertNotFound();

    // An account owning neither profile row and not an oversight type gets 403.
    $tanpa = rmdPengguna('pasien', 'pasien');
    rmdAs($tanpa)->getJson('/api/v1/rekam-medis/'.$row->getKey().'/akses')->assertForbidden();

    // A missing id is the same 404 as "not yours".
    rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/999999/akses')->assertNotFound();
});

test('the access log endpoint paginates and caps per_page at 100', function (): void {
    $akun = rmdPatientAccount();
    $row = rmdRecord($akun['pasien'], $akun['dokter']);
    $id = (int) $row->getKey();

    for ($i = 0; $i < 3; $i++) {
        rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$id)->assertOk();
    }

    $response = rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$id.'/akses?per_page=2');

    $response->assertOk()
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3);

    expect($response->json('data.akses'))->toHaveCount(2);

    rmdAs($akun['user'])->getJson('/api/v1/rekam-medis/'.$id.'/akses?per_page=101')
        ->assertStatus(422)
        ->assertJsonPath('errors.per_page.0', 'Per halaman maksimal 100.');
});
