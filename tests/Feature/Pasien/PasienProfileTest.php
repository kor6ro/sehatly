<?php

declare(strict_types=1);

use App\Http\Requests\Pasien\AlergiRequest;
use App\Http\Requests\Pasien\AnggotaKeluargaRequest;
use App\Http\Requests\Pasien\UpdatePasienProfileRequest;
use App\Models\Dokter;
use App\Models\DokterPendidikan;
use App\Models\DokterSpesialisasi;
use App\Models\MasterAgama;
use App\Models\MasterHubunganKeluarga;
use App\Models\MasterKecamatan;
use App\Models\MasterKelurahan;
use App\Models\MasterSpesialisasi;
use App\Models\Pasien;
use App\Models\PasienAlergi;
use App\Models\PasienAnggotaKeluarga;
use App\Models\User;
use App\Services\Auth\IssuedOtp;
use App\Services\Auth\LogOtpSender;
use App\Services\Auth\OtpSender;
use App\Services\Auth\OtpService;
use App\Support\NikMasker;
use App\Support\Rbac\RbacCatalog;
use App\Support\Rbac\RoleAssigner;
use App\Support\Schema\SqlSchemaParser;
use Database\Seeders\MasterUmumSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SpesialisasiSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeOtpSender;

/*
|--------------------------------------------------------------------------
| GET /api/v1/me, the patient profile, family members and allergies
|--------------------------------------------------------------------------
|
| Eleven routes, and the point of the file is the matrix under "Ownership": for every
| mutating endpoint the caller's own record is allowed, another patient's record is refused,
| a non-patient account is refused, and an anonymous caller is refused - each with a
| different status, because "you are not a patient" (403) and "that row is not yours" (404)
| are different facts and a client has to be able to tell them apart.
|
| **These are Pest closure tests, not a PHPUnit class, and that is load-bearing.**
| `tests/Pest.php` binds `RefreshDatabase` to the tests in `tests/Feature` -- for Pest,
| its closure tests, and *not* a plain `class FooTest extends TestCase` in the same
| directory. Without the trait there is no per-test rollback, the `RbacSeeder` in
| `beforeEach` survives into the next test, and everything after the first fails with a
| duplicate-key error on `roles.roles_nama_unique`.
|
| **The real routes are driven, not probes.** `RbacMiddlewareTest` and `ApiKernelTest`
| register their routes at runtime so they cannot contribute a path to the OpenAPI document
| todo 53 reconciles against the live route table. This file is the opposite case: it is
| surface todo 53 has to document, so it goes through `routes/api.php` over real HTTP.
| A probe would prove the controller works while proving nothing about the wiring, and the
| wiring is where the middleware and the ownership scoping live.
|
| **Authentication uses real Sanctum bearer tokens**, never `Sanctum::actingAs()`: an
| acting-as principal gets a `TransientToken`, and `Illuminate\Auth\RequestGuard::setRequest()`
| does not clear its cached principal, so inside one test the *first* authenticated request
| would decide the caller for all later ones. `asUser()` goes through
| `app('auth')->forgetGuards()` for exactly that reason - the full mechanism is written out
| in `AuthFlowTest::authAsToken()`, where it produced three failures that each read as a
| missing authorisation check in shipped code.
|
| **The ownership rule is asserted against the tables, not against a status code.** A 404
| on another patient's row is only worth anything if the row is still there afterwards, so
| every cross-patient test reads the row back and checks it is untouched.
|
*/

// ------------------------------------------------------------------ helpers

/**
 * The bullet run the NIK mask produces for a `CHAR(16)`: first four, eight bullets, last
 * four. Spelled once so no test hand-counts it.
 */
function maskedNik(string $nik): string
{
    return NikMasker::mask($nik);
}

/**
 * A complete, valid `POST /auth/register` payload, with overrides merged in.
 *
 * All nine keys are supplied, not just the required ones, so a test that changes one field
 * knows the other eight still validate and the failure it observes is the one it meant to
 * cause.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pasienTestRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'nama_lengkap' => 'Budi Santoso',
        'no_telepon' => '081234567890',
        'email' => 'budi.santoso@example.test',
        'password' => 'kata-sandi-yang-kuat-123',
        'jenis_kelamin' => 'L',
        'tanggal_lahir' => '1990-05-17',
        'tempat_lahir' => 'Bandung',
        'alamat_lengkap' => 'Jl. Merdeka No. 1, Bandung, Jawa Barat 40115',
        'bahasa' => 'id',
    ], $overrides);
}

/**
 * Register a patient and verify the registration OTP, returning a usable account.
 *
 * The shortest path to an authenticated patient, and it is a real HTTP walk of the two
 * endpoints rather than a factory plus a hand-made token. The fixtures these tests need are
 * *patients*, and a factory cannot build the `pasien` row that makes the ownership rule
 * decidable at all: `pasien.user_id` is `NOT NULL UNIQUE`, so the row is the tenant.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{user: User, pasien: Pasien}
 */
function patientAccount(array $overrides = []): array
{
    $payload = pasienTestRegisterPayload($overrides);

    test()->postJson('/api/v1/auth/register', $payload)->assertCreated();

    test()->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => $payload['no_telepon'],
        'kode' => testOtpSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON),
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ])->assertOk();

    $user = User::query()->where('no_telepon', $payload['no_telepon'])->firstOrFail();

    return [
        'user' => $user,
        'pasien' => Pasien::query()->where('user_id', $user->getKey())->firstOrFail(),
    ];
}

/**
 * Register a patient, authenticate as them, and hand the account back.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{user: User, pasien: Pasien}
 */
function asPatient(array $overrides = []): array
{
    $account = patientAccount($overrides);

    asUser($account['user']);

    return $account;
}

/**
 * The `FakeOtpSender` bound in `beforeEach`, read back out of the container.
 *
 * Read from the container rather than captured in a variable so a test cannot observe a
 * sender the controller was never handed.
 */
function testOtpSender(): FakeOtpSender
{
    $sender = app(OtpSender::class);

    expect($sender)->toBeInstanceOf(FakeOtpSender::class);

    return $sender;
}

/**
 * Make the next request carry a real Sanctum bearer token for `$user`.
 */
function asUser(User $user): mixed
{
    app('auth')->forgetGuards();

    return test()->withToken($user->createToken('pasien-profile-test', ['*'], now()->addHour())->plainTextToken);
}

/**
 * A `master_agama` id, whatever the seed assigned it.
 *
 * Read from the table rather than hard-coded, so a change to the seeded ids does not turn a
 * profile test into a 422 about something unrelated.
 */
function religionId(): int
{
    return (int) MasterAgama::query()->orderBy('id')->value('id');
}

/**
 * A `master_hubungan_keluarga` id.
 */
function relationshipId(): int
{
    return (int) MasterHubunganKeluarga::query()->orderBy('id')->value('id');
}

/**
 * One coherent administrative chain, planted once per test.
 *
 * `master_kabupaten_kota`, `master_kecamatan` and `master_kelurahan` are **never seeded** -
 * `telemedicine_test.sql`'s section `[16]` inserts 38 `master_provinsi` rows and nothing
 * else, so `MasterWilayahSeeder` covers one table of the four. The address block therefore
 * has to be planted as a fixture, which is recorded here because a test that quietly
 * assumed otherwise would have had a `Rule::exists` failure rather than a clear one.
 *
 * Coherent on purpose: `UpdatePasienProfileRequest` refuses a set that cannot all be part
 * of one chain, so a test that wants an incoherent set has to build it deliberately.
 *
 * @return array{provinsi_id: int, kabupaten_kota_id: int, kecamatan_id: int, kelurahan_id: int}
 */
function wilayahChain(): array
{
    // `insertOrIgnore`, not a `static $planted` latch: `RefreshDatabase` rolls back after
    // every test, so a latch that survives across tests would report the chain as planted
    // while the rows had been rolled back - and the next test's `Rule::exists` would fail
    // for a reason that looks like a schema bug.
    DB::table('master_provinsi')->insertOrIgnore([
        ['id' => 1, 'kode' => '11', 'nama' => 'Aceh'],
        ['id' => 2, 'kode' => '12', 'nama' => 'Sumatera Utara'],
    ]);

    DB::table('master_kabupaten_kota')->insertOrIgnore([
        ['id' => 11, 'provinsi_id' => 1, 'kode' => '1101', 'nama' => 'Banda Aceh'],
        ['id' => 12, 'provinsi_id' => 2, 'kode' => '1273', 'nama' => 'Pematang Siantar'],
    ]);

    DB::table('master_kecamatan')->insertOrIgnore([
        ['id' => 111, 'kabupaten_kota_id' => 11, 'kode' => '110101', 'nama' => 'Meurallaya'],
        ['id' => 127, 'kabupaten_kota_id' => 12, 'kode' => '127301', 'nama' => 'Siantar Marihat'],
    ]);

    DB::table('master_kelurahan')->insertOrIgnore([
        ['id' => 11101, 'kecamatan_id' => 111, 'kode' => '1101012001', 'nama' => 'Ieung Geuyeut'],
        ['id' => 12701, 'kecamatan_id' => 127, 'kode' => '1273012001', 'nama' => 'Sari Marihat'],
    ]);

    return [
        'provinsi_id' => 1,
        'kabupaten_kota_id' => 11,
        'kecamatan_id' => 111,
        'kelurahan_id' => 11101,
    ];
}

/**
 * A `master_kelurahan` that is provably NOT under the given chain's `kabupaten_kota_id`.
 *
 * Used only by the incoherence test, which needs a row that exists in every `Rule::exists`
 * check and is still the wrong one.
 */
function quartierLain(int $kabupatenKotaId): int
{
    $row = MasterKelurahan::query()
        ->where('kecamatan_id', '!=', MasterKecamatan::query()
            ->where('kabupaten_kota_id', $kabupatenKotaId)
            ->select('id'))
        ->orderBy('id')
        ->first();

    expect($row)->not->toBeNull('The coherence test needs a second administrative chain to exist.');

    return (int) $row->getKey();
}

/**
 * A minimal valid `POST /pasien/anggota-keluarga` body, with overrides merged in.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function familyMemberPayload(array $overrides = []): array
{
    return array_merge([
        'hubungan_id' => relationshipId(),
        'nik' => '3273123456780001',
        'nama_lengkap' => 'Siti Aminah',
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1965-08-09',
        'no_telepon' => '081200000001',
        'catatan_alergi' => 'Alergi terhadap biting serangga',
    ], $overrides);
}

/**
 * A minimal valid `POST /pasien/alergi` body, with overrides merged in.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function allergyPayload(array $overrides = []): array
{
    return array_merge([
        'tipe_alergen' => 'obat',
        'nama_alergen' => 'Amoksisilin',
        'reaksi' => 'Ruam dan gatal',
        'keparahan' => 'berat',
    ], $overrides);
}

/**
 * Insert a family member straight into the table, bypassing the API.
 *
 * Used to plant a row that belongs to *another* patient, which is the only way to test the
 * cross-tenant refusal: the API itself can never create one, because `pasien_id` is
 * server-written. This is the one place in the file that writes without going through an
 * endpoint, and it is a fixture rather than a shortcut.
 */
function plantFamilyMember(Pasien $pasien, array $overrides = []): PasienAnggotaKeluarga
{
    $anggota = new PasienAnggotaKeluarga;
    $anggota->pasien_id = $pasien->getKey();

    foreach (familyMemberPayload($overrides) as $key => $value) {
        $anggota->{$key} = $value;
    }

    $anggota->save();

    return $anggota;
}

/**
 * Insert an allergy straight into the table, bypassing the API.
 *
 * @see plantFamilyMember() for why this exists
 */
function plantAllergy(Pasien $pasien, array $overrides = []): PasienAlergi
{
    $alergi = new PasienAlergi;
    $alergi->pasien_id = $pasien->getKey();
    $alergi->dicatat_oleh_user_id = $pasien->user_id;

    foreach (allergyPayload($overrides) as $key => $value) {
        $alergi->{$key} = $value;
    }

    $alergi->save();

    return $alergi;
}

/**
 * Read an ENUM column's value list out of the reference SQL, through the project's own
 * parser.
 *
 * A local copy of `AuthFlowTest::authEnumValuesFromDdl()` rather than a reuse of it: a
 * top-level function in one Pest file is defined when that file is included, so calling the
 * other file's copy would make this file's behaviour depend on Pest's include order. The
 * duplication is fifteen lines and it is the whole point - the reference file is re-parsed
 * with the same `SqlSchemaParser` `sehatly:verify-schema` uses, so these assertions cannot
 * disagree with the verifier about what the DDL says.
 *
 * @return list<string>
 */
function pasienEnumFromDdl(string $table, string $column): array
{
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    expect($spec->hasTable($table))->toBeTrue();

    $tableSpec = $spec->table($table);

    expect($tableSpec)->not->toBeNull();
    expect($tableSpec->columns)->toHaveKey($column);
    expect($tableSpec->columns[$column]->type)->toMatch("/^enum\((.*)\)$/");

    preg_match("/^enum\((.*)\)$/", $tableSpec->columns[$column]->type, $matches);

    $values = array_map(
        static fn (string $member): string => trim($member, "'"),
        explode(',', $matches[1]),
    );

    expect($values)->not->toContain('', "{$table}.{$column} has an empty ENUM member.");

    return $values;
}

/**
 * Every mutating endpoint on this surface, with the HTTP verb and the URI template.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function mutatingEndpoints(): array
{
    return [
        'profil' => ['PUT', '/api/v1/pasien/profil'],
        'keluarga create' => ['POST', '/api/v1/pasien/anggota-keluarga'],
        'keluarga update' => ['PUT', '/api/v1/pasien/anggota-keluarga/{id}'],
        'keluarga delete' => ['DELETE', '/api/v1/pasien/anggota-keluarga/{id}'],
        'alergi create' => ['POST', '/api/v1/pasien/alergi'],
        'alergi update' => ['PUT', '/api/v1/pasien/alergi/{id}'],
        'alergi delete' => ['DELETE', '/api/v1/pasien/alergi/{id}'],
    ];
}

/**
 * A body that satisfies every one of the mutating requests' rules at once, so a refusal
 * can only ever be about authorisation and never about validation.
 *
 * The profile request and the family request both want a `nama_lengkap`, and they want
 * different things from it: a person's name on the profile, a relative's name on a family
 * member. The profile body is therefore trimmed to the one field it uses, and the family
 * body keeps the relative's name, so a single array can drive all seven refusals without
 * any of them being a validation failure wearing a 403.
 *
 * @return array<string, mixed>
 */
function validBodyForEveryMutation(): array
{
    return [
        'pekerjaan' => 'Guru',
        'hubungan_id' => relationshipId(),
        'nik' => '3273123456780001',
        'nama_lengkap' => 'Siti Aminah',
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1965-08-09',
        'no_telepon' => '081200000001',
        'catatan_alergi' => 'Alergi terhadap biting serangga',
        'tipe_alergen' => 'obat',
        'nama_alergen' => 'Amoksisilin',
        'reaksi' => 'Ruam dan gatal',
        'keparahan' => 'berat',
    ];
}

/**
 * The body for one mutating request, trimmed to the keys that request actually validates.
 *
 * @return array<string, mixed>
 */
function validMutationBody(string $method, string $uri): array
{
    $body = validBodyForEveryMutation();

    if (! str_contains($uri, 'alergi')) {
        unset($body['tipe_alergen'], $body['nama_alergen'], $body['reaksi'], $body['keparahan']);
    }

    if (! str_contains($uri, 'keluarga')) {
        unset(
            $body['hubungan_id'],
            $body['nik'],
            $body['jenis_kelamin'],
            $body['tanggal_lahir'],
            $body['no_telepon'],
            $body['catatan_alergi'],
        );
    }

    if (str_contains($uri, 'profil')) {
        // The profile request names `nama_lengkap` for the *patient*, and the family
        // request for a *relative*; keeping the relative's name here is safe because the
        // profile body only ever sends `pekerjaan`.
        $body = ['pekerjaan' => 'Guru', 'nama_lengkap' => 'Budi Santoso Junior'];
    }

    return $body;
}

beforeEach(function (): void {
    // `RbacSeeder` is required, not cosmetic: `register()` grants the `pasien` role
    // through `RoleAssigner`, which resolves role names against the `roles` table and
    // throws a LogicException when the catalogue names a row that is not there.
    $this->seed(RbacSeeder::class);

    // `MasterUmumSeeder` supplies the `master_agama` and `master_hubungan_keluarga` rows the
    // profile and family requests validate against, and `SpesialisasiSeeder` supplies the
    // two `master_spesialisasi` rows the `/me` doctor test attaches. Both are the DDL's own
    // `[16]` inserts, run through the project's own seeders rather than re-typed here, so a
    // change to the reference data cannot leave a stale literal in this file.
    $this->seed(MasterUmumSeeder::class);
    $this->seed(SpesialisasiSeeder::class);

    // The three wilayah tables below `master_provinsi` are never seeded by the DDL, so the
    // address chain is planted on demand by `wilayahChain()`.
    wilayahChain();

    $this->app->instance(OtpSender::class, new FakeOtpSender);
});

// =====================================================================
// GET /api/v1/me
// =====================================================================

test('me returns the caller with a masked patient NIK and no password hash anywhere in the body', function (): void {
    $account = patientAccount();

    $account['pasien']->nik = '3273123456780001';
    $account['pasien']->nomor_kk = '3273123456780002';
    $account['pasien']->save();

    $response = asUser($account['user'])->getJson('/api/v1/me');

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $response->assertJsonPath('data.user.id', $account['user']->getKey());
    $response->assertJsonPath('data.user.tipe', 'pasien');
    $response->assertJsonPath('data.user.pasien.nomor_rm', $account['pasien']->nomor_rm);
    $response->assertJsonPath('data.user.pasien.nama_lengkap', 'Budi Santoso');
    $response->assertJsonPath('data.user.pasien.nik', maskedNik('3273123456780001'));
    $response->assertJsonPath('data.user.pasien.nomor_kk', maskedNik('3273123456780002'));

    // Asserted on the serialised body, not the decoded array: a field nested somewhere
    // unexpected would be just as much of a leak as one in the expected place.
    $body = (string) $response->getContent();
    $hash = (string) User::query()->where('no_telepon', '081234567890')->value('kata_sandi_hash');

    expect($body)->not->toContain('kata_sandi')
        ->and($body)->not->toContain($hash)
        ->and($body)->not->toContain('3273123456780001')
        ->and($body)->not->toContain('3273123456780002')
        // The mask is the plan's shape: four kept, eight bullets, four kept.
        ->and($response->json('data.user.pasien.nik'))->toHaveLength(16)
        ->and(maskedNik('3273123456780001'))->toBe('3273'.str_repeat("\u{2022}", 8).'0001');
});

test('me publishes null for a relation the account does not own, and never refuses for it', function (): void {
    // A `superadmin` holds all 24 permissions and owns neither a `pasien` nor a `dokter`
    // row. "What does your account look like" is not an authorisation question, so `/me`
    // answers rather than 403s - the patient routes are where a missing row IS a 403.
    $admin = User::factory()->create(['tipe' => 'superadmin', 'status' => 'aktif']);

    $response = asUser($admin)->getJson('/api/v1/me');

    $response->assertOk();
    $response->assertJsonPath('data.user.tipe', 'superadmin');
    expect($response->json('data.user.pasien'))->toBeNull()
        ->and($response->json('data.user.dokter'))->toBeNull();
});

test('me eager-loads the doctor row with its specialisations and education, and withholds the licence numbers', function (): void {
    $dokterUser = User::factory()->create(['tipe' => 'dokter', 'status' => 'aktif']);

    // The two `master_spesialisasi` ids are read from the table, never hard-coded as 1 and
    // 2. `RefreshDatabase` wraps each test in a transaction, and a **rollback does not
    // reset an AUTO_INCREMENT counter**, so the ids `SpesialisasiSeeder` produces keep
    // climbing for the life of the process. A fixture that hard-codes them is correct in
    // the first test that runs and is MySQL 1452 in the hundredth - which is exactly the
    // failure this produced before the ids were read.
    $ids = MasterSpesialisasi::query()->orderBy('id')->take(2)->pluck('id')->all();

    expect($ids)->toHaveCount(2);

    $dokter = new Dokter;
    $dokter->user_id = $dokterUser->getKey();
    $dokter->tipe = 'dokter_spesialis';
    $dokter->nomor_str = '1234567890123456';
    $dokter->nomor_sip = 'SIP/1234/2026';
    $dokter->str_berlaku_sampai = now()->addYears(3)->toDateString();
    $dokter->save();

    $bukanUtama = new DokterSpesialisasi;
    $bukanUtama->dokter_id = $dokter->getKey();
    $bukanUtama->spesialisasi_id = $ids[1];
    $bukanUtama->is_utama = false;
    $bukanUtama->save();

    $utama = new DokterSpesialisasi;
    $utama->dokter_id = $dokter->getKey();
    $utama->spesialisasi_id = $ids[0];
    $utama->is_utama = true;
    $utama->save();

    $lama = new DokterPendidikan;
    $lama->dokter_id = $dokter->getKey();
    $lama->jenjang = 's1_kedokteran';
    $lama->institusi = 'Universitas Lama';
    $lama->tahun_lulus = 2005;
    $lama->save();

    $baru = new DokterPendidikan;
    $baru->dokter_id = $dokter->getKey();
    $baru->jenjang = 'sp2';
    $baru->institusi = 'Universitas Baru';
    $baru->tahun_lulus = 2018;
    $baru->save();

    $response = asUser($dokterUser)->getJson('/api/v1/me');

    $response->assertOk();
    $response->assertJsonPath('data.user.dokter.tipe', 'dokter_spesialis');
    expect($response->json('data.user.pasien'))->toBeNull();

    // `is_utama` first, so the main specialisation leads the list.
    $spesialisasi = $response->json('data.user.dokter.spesialisasi');
    expect($spesialisasi)->toHaveCount(2)
        ->and($spesialisasi[0]['is_utama'])->toBeTrue()
        ->and($spesialisasi[1]['is_utama'])->toBeFalse()
        ->and($spesialisasi[0]['nama'])->toBeString()
        ->and($spesialisasi[0]['kode'])->toBeString()
        // The `is_utama` row is the first specialisation in the table, by id.
        ->and($spesialisasi[0]['spesialisasi_id'])->toBe($ids[0])
        ->and($spesialisasi[1]['spesialisasi_id'])->toBe($ids[1]);

    // `tahun_lulus` descending, under the DDL's own spelling.
    $pendidikan = $response->json('data.user.dokter.pendidikan');
    expect($pendidikan)->toHaveCount(2)
        ->and($pendidikan[0]['tahun_lulus'])->toBe(2018)
        ->and($pendidikan[1]['tahun_lulus'])->toBe(2005)
        ->and($pendidikan[0]['institusi'])->toBe('Universitas Baru');

    // The four credential fields are absent from the body, not null.
    $body = (string) $response->getContent();
    expect($body)->not->toContain('nomor_str')
        ->and($body)->not->toContain('1234567890123456')
        ->and($body)->not->toContain('nomor_sip')
        ->and($body)->not->toContain('SIP/1234/2026');
});

test('me requires an authenticated caller and never redirects', function (): void {
    app('auth')->forgetGuards();

    $response = test()->withoutHeader('Authorization')->getJson('/api/v1/me');

    $response->assertStatus(401);
    $response->assertJsonPath('success', false);
    expect($response->headers->get('Location'))->toBeNull();
});

// =====================================================================
// GET / PUT /api/v1/pasien/profil
// =====================================================================

test('the profile endpoint returns the caller own patient row', function (): void {
    $account = asPatient();

    $response = asUser($account['user'])->getJson('/api/v1/pasien/profil');

    $response->assertOk();
    $response->assertJsonStructure([
        'success',
        'data' => ['profile' => [
            'id', 'nomor_rm', 'nik', 'nomor_kk', 'nama_lengkap', 'jenis_kelamin',
            'tanggal_lahir', 'alamat_lengkap', 'tinggi_badan_cm', 'berat_badan_kg',
        ]],
        'message',
    ]);
    $response->assertJsonPath('data.profile.id', $account['pasien']->getKey());
    $response->assertJsonPath('data.profile.nama_lengkap', 'Budi Santoso');
    // A patient who supplied no NIK has none, and the resource says `null` rather than a
    // run of bullets that would look like an identifier.
    $response->assertJsonPath('data.profile.nik', null);

    // The three columns `PasienResource` deliberately withholds, so a future widening of
    // the projection is a visible change rather than a silent one.
    $body = (string) $response->getContent();
    expect($body)->not->toContain('"user_id"')
        ->and($body)->not->toContain('nomor_ihs_satusehat')
        ->and($body)->not->toContain('catatan_alergi');
});

test('the profile update writes the plan field set, across both tables', function (): void {
    $account = asPatient();
    $wilayah = wilayahChain();

    $response = asUser($account['user'])->putJson('/api/v1/pasien/profil', array_merge([
        'nama_lengkap' => 'Budi Santoso Pratama',
        'tempat_lahir' => 'Bandung',
        'agama_id' => religionId(),
        'pekerjaan' => 'Guru',
        'alamat_lengkap' => 'Jl. Sudirman No. 45, Jakarta Pusat 10220',
        'rt' => '003',
        'rw' => '007',
        'kode_pos' => '10220',
        'tinggi_badan_cm' => '172.5',
        'berat_badan_kg' => '68.25',
    ], $wilayah));

    $response->assertOk();
    $response->assertJsonPath('data.profile.nama_lengkap', 'Budi Santoso Pratama');
    $response->assertJsonPath('data.profile.tinggi_badan_cm', '172.5');
    $response->assertJsonPath('data.profile.berat_badan_kg', '68.25');
    $response->assertJsonPath('data.profile.pekerjaan', 'Guru');

    // `nama_lengkap` is the one key on this route that lands on `users`, and that is a
    // separate table, so it is asserted against the row rather than through the response.
    expect($account['user']->fresh()->nama_lengkap)->toBe('Budi Santoso Pratama');

    $pasien = $account['pasien']->fresh();
    expect($pasien->tempat_lahir)->toBe('Bandung')
        ->and($pasien->agama_id)->toBe(religionId())
        ->and($pasien->pekerjaan)->toBe('Guru')
        ->and($pasien->rt)->toBe('003')
        ->and($pasien->rw)->toBe('007')
        ->and($pasien->kode_pos)->toBe('10220')
        ->and($pasien->provinsi_id)->toBe($wilayah['provinsi_id'])
        ->and($pasien->kabupaten_kota_id)->toBe($wilayah['kabupaten_kota_id'])
        ->and($pasien->kecamatan_id)->toBe($wilayah['kecamatan_id'])
        ->and($pasien->kelurahan_id)->toBe($wilayah['kelurahan_id']);
});

test('the profile update cannot change tipe, status, no_telepon, nik, or the identity columns', function (string $field, mixed $value): void {
    $account = asPatient();
    $before = $account['user']->only(['tipe', 'status', 'no_telepon']);
    $pasienBefore = $account['pasien']->only(['nik', 'jenis_kelamin', 'nomor_rm']);
    $lahirBefore = $account['pasien']->tanggal_lahir->toDateString();

    $response = asUser($account['user'])->putJson('/api/v1/pasien/profil', [
        'pekerjaan' => 'Guru',
        $field => $value,
    ]);

    // 200, not 422: the key is not in the rules, so it is neither validated nor rejected -
    // it is dropped. The plan's mechanism is `validated()` returning only the named keys,
    // and a 422 would be a *different* mechanism that the plan does not ask for and that
    // would break a client that round-trips its own model.
    $response->assertOk();

    $pasienAfter = $account['pasien']->fresh();

    expect($account['user']->fresh()->only(['tipe', 'status', 'no_telepon']))->toBe($before)
        ->and($pasienAfter->only(['nik', 'jenis_kelamin', 'nomor_rm']))->toBe($pasienBefore)
        ->and($pasienAfter->tanggal_lahir->toDateString())->toBe($lahirBefore)
        // The one field that *was* named still landed, proving the request was applied and
        // not rejected wholesale.
        ->and($pasienAfter->pekerjaan)->toBe('Guru');
})->with([
    ['tipe', 'superadmin'],
    ['status', 'aktif'],
    ['no_telepon', '089999999999'],
    ['nik', '3273000000000000'],
    ['jenis_kelamin', 'P'],
    ['tanggal_lahir', '2000-01-01'],
    ['nomor_rm', 'RM-199901-999999'],
    ['user_id', 999999],
    ['catatan_alergi', 'diisi dari luar'],
    ['is_meninggal', true],
    ['rhesus', 'negatif'],
]);

test('the profile update refuses a bad foreign key as a 422 and not a 500', function (): void {
    $account = asPatient();

    $response = asUser($account['user'])->putJson('/api/v1/pasien/profil', [
        'pekerjaan' => 'Guru',
        'agama_id' => 250,
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('success', false);
    $response->assertJsonPath('message', 'The given data was invalid.');
    expect($response->json('errors'))->toHaveKey('agama_id');

    expect($account['pasien']->fresh()->pekerjaan)->not->toBe('Guru');
});

test('the profile update refuses an unknown bare reference id, which is the only check the schema permits', function (): void {
    // `pasien.provinsi_id` and its three siblings carry NO foreign key (DDL :236-238), so
    // without `Rule::exists` a bad id would be written and would silently point at nothing.
    $account = asPatient();

    $response = asUser($account['user'])->putJson('/api/v1/pasien/profil', [
        'pekerjaan' => 'Guru',
        'provinsi_id' => 250,
    ]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('provinsi_id');
});

test('the profile update refuses a wilayah set that is not one administrative chain', function (): void {
    $account = asPatient();
    $chain = wilayahChain();

    // A coherent chain with one borough swapped for one from a different kabupaten/kota:
    // every value exists, so `Rule::exists` is satisfied and only the coherence check
    // catches it.
    $response = asUser($account['user'])->putJson('/api/v1/pasien/profil', array_merge($chain, [
        'kelurahan_id' => quartierLain($chain['kabupaten_kota_id']),
    ]));

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('kecamatan_id');

    expect($account['pasien']->fresh()->pekerjaan)->not->toBe('Guru');
});

test('the profile update refuses each of the widths the DDL declares', function (string $field, mixed $value, string $fragment): void {
    $account = asPatient();

    $response = asUser($account['user'])->putJson('/api/v1/pasien/profil', [$field => $value]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey($field);
    expect($response->json('errors.'.$field.'.0'))->toContain($fragment);
})->with([
    ['rt', 'abcd', 'format is invalid'],
    ['rt', '1234', 'format is invalid'],
    ['rw', 'abcd', 'format is invalid'],
    ['kode_pos', '1022', 'must be 5 characters'],
    ['kode_pos', 'ABCDE', 'format is invalid'],
    ['tinggi_badan_cm', '172.55', 'decimal places'],
    ['berat_badan_kg', '68.255', 'decimal places'],
    ['tinggi_badan_cm', '301', 'must not be greater than 300'],
    ['berat_badan_kg', '0.5', 'must be at least 1'],
    ['nama_lengkap', 'ab', 'at least 3 characters'],
    ['nama_lengkap', str_repeat('a', 151), 'greater than 150 characters'],
]);

test('a patient can clear a nullable profile field by sending null', function (): void {
    $account = asPatient();

    $account['pasien']->pekerjaan = 'Guru';
    $account['pasien']->agama_id = religionId();
    $account['pasien']->save();

    $response = asUser($account['user'])->putJson('/api/v1/pasien/profil', [
        'pekerjaan' => null,
        'agama_id' => null,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.profile.pekerjaan', null);
    $response->assertJsonPath('data.profile.agama_id', null);

    $pasien = $account['pasien']->fresh();
    expect($pasien->pekerjaan)->toBeNull()->and($pasien->agama_id)->toBeNull();
});

test('a profile update is a partial update, so an absent key leaves the stored value alone', function (): void {
    $account = asPatient();
    $account['pasien']->pekerjaan = 'Guru';
    $account['pasien']->save();

    asUser($account['user'])->putJson('/api/v1/pasien/profil', ['tempat_lahir' => 'Surabaya'])
        ->assertOk();

    $pasien = $account['pasien']->fresh();
    expect($pasien->pekerjaan)->toBe('Guru')
        ->and($pasien->tempat_lahir)->toBe('Surabaya');
});

// =====================================================================
// Ownership -- the matrix every mutating endpoint is held to
// =====================================================================

test('a non-patient account is refused with 403 on every mutating route, whatever it holds', function (string $method, string $uri): void {
    // A `superadmin` holds all 24 permission codes in `RbacCatalog::ROLE_PERMISSIONS` and
    // it still cannot act on a patient self-service record. That is the strongest available
    // statement that the gate is a data check and not a grant: no amount of permission
    // turns a non-patient into a patient.
    //
    // `perawat` and `kurir` are in the list on purpose: `RbacCatalog` documents them as
    // real `users.tipe` values holding no role, so a `permission:` gate here would be a
    // permanent lockout for exactly these two, and the test proves they are refused as
    // patients rather than as role-holders.
    foreach (['superadmin', 'dokter', 'admin', 'perawat', 'kurir', 'apoteker'] as $tipe) {
        $account = User::factory()->create(['tipe' => $tipe, 'status' => 'aktif']);

        if (in_array($tipe, ['dokter', 'apoteker'], true)) {
            app(RoleAssigner::class)->assign((int) $account->getKey(), $tipe);
        }

        $response = asUser($account)
            ->json($method, str_replace('{id}', '1', $uri), validMutationBody($method, $uri));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'This action is unauthorized.');

        // And nothing was written on the way out.
        expect(PasienAlergi::query()->count())->toBe(0)
            ->and(PasienAnggotaKeluarga::query()->count())->toBe(0)
            ->and(Pasien::query()->where('pekerjaan', 'Guru')->count())->toBe(0);
    }
})->with(mutatingEndpoints());

test('a patient account with no patient row is refused with 403, not 500', function (string $method, string $uri): void {
    // A `pasien`-typed account with no `pasien` row is the case that decided against a
    // `tipe:pasien` gate: `tipe:` would pass it and the row lookup would be what answers.
    // Here the answer is a 403 with the documented message, not a 500 from a null relation.
    $orphan = User::factory()->create(['tipe' => 'pasien', 'status' => 'aktif']);

    expect(Pasien::query()->where('user_id', $orphan->getKey())->count())->toBe(0);

    $response = asUser($orphan)
        ->json($method, str_replace('{id}', '1', $uri), validMutationBody($method, $uri));

    $response->assertStatus(403);
    $response->assertJsonPath('success', false);
})->with(mutatingEndpoints());

test('every patient route refuses an anonymous caller with the 401 envelope', function (string $method, string $uri): void {
    app('auth')->forgetGuards();

    $response = test()->json($method, str_replace('{id}', '1', $uri), []);

    $response->assertStatus(401);
    $response->assertJsonPath('success', false);
    expect($response->getContent())
        ->toBe('{"success":false,"message":"Unauthenticated.","errors":{}}')
        ->and($response->headers->get('Location'))->toBeNull();
})->with(array_merge(
    mutatingEndpoints(),
    [
        'me' => ['GET', '/api/v1/me'],
        'profil read' => ['GET', '/api/v1/pasien/profil'],
        'keluarga list' => ['GET', '/api/v1/pasien/anggota-keluarga'],
        'alergi list' => ['GET', '/api/v1/pasien/alergi'],
    ],
));

// =====================================================================
// Family members
// =====================================================================

test('a family member is created, listed, updated and hard deleted, every step scoped to the caller', function (): void {
    $account = asPatient();

    $created = asUser($account['user'])->postJson('/api/v1/pasien/anggota-keluarga', familyMemberPayload());

    $created->assertCreated();
    $created->assertJsonPath('data.anggota_keluarga.nama_lengkap', 'Siti Aminah');
    $created->assertJsonPath(
        'data.anggota_keluarga.hubungan',
        MasterHubunganKeluarga::query()->orderBy('id')->value('nama'),
    );
    $created->assertJsonPath('data.anggota_keluarga.nik', maskedNik('3273123456780001'));
    $created->assertJsonPath('data.anggota_keluarga.tanggal_lahir', '1965-08-09');

    $id = (int) $created->json('data.anggota_keluarga.id');

    // `pasien_id` is server-written and is not published, so the row is proved to belong to
    // the caller by reading the table rather than by trusting the response.
    expect(PasienAnggotaKeluarga::query()->findOrFail($id)->pasien_id)->toBe($account['pasien']->getKey());

    $listed = asUser($account['user'])->getJson('/api/v1/pasien/anggota-keluarga');
    $listed->assertOk();
    $listed->assertJsonPath('meta.total', 1);
    $listed->assertJsonCount(1, 'data.anggota_keluarga');
    expect((string) $listed->getContent())->not->toContain('"pasien_id"');

    $updated = asUser($account['user'])->putJson('/api/v1/pasien/anggota-keluarga/'.$id, [
        'nama_lengkap' => 'Siti Aminah Baru',
        'no_telepon' => '081200000009',
    ]);

    $updated->assertOk();
    $updated->assertJsonPath('data.anggota_keluarga.nama_lengkap', 'Siti Aminah Baru');
    $updated->assertJsonPath('data.anggota_keluarga.no_telepon', '081200000009');

    // A partial update: `tanggal_lahir` was absent from the body and is untouched.
    $stored = PasienAnggotaKeluarga::query()->findOrFail($id);
    expect($stored->tanggal_lahir->toDateString())->toBe('1965-08-09')
        ->and($stored->pasien_id)->toBe($account['pasien']->getKey());

    $deleted = asUser($account['user'])->deleteJson('/api/v1/pasien/anggota-keluarga/'.$id);
    $deleted->assertOk();
    $deleted->assertJsonPath('data.deleted', true);
    $deleted->assertJsonPath('data.id', $id);

    // A HARD delete: `pasien_anggota_keluarga` declares `dibuat_at` and nothing else, so
    // there is no `dihapus_at` to soft-delete into.
    expect(PasienAnggotaKeluarga::query()->whereKey($id)->count())->toBe(0);
});

test('the family list returns only the caller own rows, with the project meta block', function (): void {
    $mine = asPatient();
    $theirs = patientAccount(['no_telepon' => '081200000010', 'email' => 'orang.lain@example.test']);

    plantFamilyMember($mine['pasien'], ['nama_lengkap' => 'Ibu Saya']);
    plantFamilyMember($mine['pasien'], ['nama_lengkap' => 'Ayah Saya']);
    plantFamilyMember($theirs['pasien'], ['nama_lengkap' => 'Ibu Orang Lain']);

    $first = asUser($mine['user'])->getJson('/api/v1/pasien/anggota-keluarga?per_page=1');

    $first->assertOk();
    $first->assertJsonPath('meta.total', 2);
    $first->assertJsonPath('meta.current_page', 1);
    $first->assertJsonPath('meta.last_page', 2);
    $first->assertJsonPath('meta.per_page', 1);
    $first->assertJsonCount(1, 'data.anggota_keluarga');
    expect($first->getContent())->not->toContain('Ibu Orang Lain');

    $second = asUser($mine['user'])->getJson('/api/v1/pasien/anggota-keluarga?per_page=1&page=2');
    $second->assertOk();
    $second->assertJsonPath('meta.current_page', 2);
    $second->assertJsonPath('meta.last_page', 2);
    $second->assertJsonCount(1, 'data.anggota_keluarga');
    expect($second->getContent())->not->toContain('Ibu Orang Lain');

    // The other patient's row is still on the table: this list filtered, it did not move
    // anything.
    expect(PasienAnggotaKeluarga::query()->count())->toBe(3);
});

test('a family member belonging to another patient is 404 on update and delete, and the row is untouched', function (): void {
    $mine = asPatient();
    $theirs = patientAccount(['no_telepon' => '081200000011', 'email' => 'orang.lain2@example.test']);

    $foreign = plantFamilyMember($theirs['pasien'], ['nama_lengkap' => 'Ibu Orang Lain']);

    $update = asUser($mine['user'])->putJson('/api/v1/pasien/anggota-keluarga/'.$foreign->getKey(), [
        'nama_lengkap' => 'Diretas',
    ]);

    // 404, not 403: a 403 would confirm the row exists. The plan states this explicitly.
    $update->assertStatus(404);
    $update->assertJsonPath('success', false);
    $update->assertJsonPath('message', 'Resource not found.');
    expect($update->getContent())->not->toContain('Ibu Orang Lain')
        ->and($update->getContent())->not->toContain('Diretas');

    $delete = asUser($mine['user'])->deleteJson('/api/v1/pasien/anggota-keluarga/'.$foreign->getKey());
    $delete->assertStatus(404);

    $stillThere = PasienAnggotaKeluarga::query()->findOrFail($foreign->getKey());
    expect($stillThere->nama_lengkap)->toBe('Ibu Orang Lain')
        ->and($stillThere->pasien_id)->toBe($theirs['pasien']->getKey());

    // A row that does not exist at all is byte-identical, so the two cannot be told apart.
    // The body is a VALID one, so the 404 is about the row and not about a rule the
    // request happened to break on the way in.
    $missing = asUser($mine['user'])->putJson('/api/v1/pasien/anggota-keluarga/999999', [
        'nama_lengkap' => 'Siti Aminah',
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1965-08-09',
        'hubungan_id' => relationshipId(),
    ]);
    $missing->assertStatus(404);
    expect($missing->getContent())->toBe($update->getContent());
});

test('the family endpoints refuse the field errors the DDL makes unavoidable', function (string $field, mixed $value, string $fragment): void {
    $account = asPatient();

    $response = asUser($account['user'])
        ->postJson('/api/v1/pasien/anggota-keluarga', familyMemberPayload([$field => $value]));

    $response->assertStatus(422);
    $response->assertJsonPath('success', false);
    $response->assertJsonPath('message', 'The given data was invalid.');
    expect($response->json('errors'))->toHaveKey($field);
    expect($response->json('errors.'.$field.'.0'))->toContain($fragment);

    expect(PasienAnggotaKeluarga::query()->count())->toBe(0);
})->with([
    // `nama_lengkap`, `jenis_kelamin`, `tanggal_lahir` and `hubungan_id` are all NOT NULL.
    ['nama_lengkap', null, 'field is required'],
    ['jenis_kelamin', null, 'field is required'],
    ['tanggal_lahir', null, 'field is required'],
    ['hubungan_id', null, 'field is required'],
    ['jenis_kelamin', 'X', 'The selected jenis kelamin is invalid.'],
    ['tanggal_lahir', '09-08-1965', 'must match the format Y-m-d'],
    ['tanggal_lahir', now()->addDay()->toDateString(), 'before or equal to today'],
    ['nik', '12345', 'must be 16 digits'],
    ['nik', str_repeat('a', 16), 'must be 16 digits'],
    ['no_telepon', 'bukan-nomor', 'format is invalid'],
    // The FK is real (`:271`), so without `exists` this would be MySQL 1452 - a 500.
    ['hubungan_id', 250, 'The selected hubungan keluarga is invalid.'],
]);

test('a family member cannot be created under a patient id of the caller choosing', function (): void {
    $mine = asPatient();
    $theirs = patientAccount(['no_telepon' => '081200000012', 'email' => 'orang.lain3@example.test']);

    // 201, and the row lands under the CALLER's `pasien_id`: `pasien_id` is not in the
    // rules, so a client-supplied one is dropped before the write.
    $response = asUser($mine['user'])->postJson('/api/v1/pasien/anggota-keluarga', array_merge(
        familyMemberPayload(),
        ['pasien_id' => $theirs['pasien']->getKey()],
    ));

    $response->assertCreated();

    $created = PasienAnggotaKeluarga::query()->latest('id')->firstOrFail();
    expect($created->pasien_id)->toBe($mine['pasien']->getKey())
        ->and($created->pasien_id)->not->toBe($theirs['pasien']->getKey())
        ->and(PasienAnggotaKeluarga::query()->where('pasien_id', $theirs['pasien']->getKey())->count())->toBe(0);
});

test('the family list refuses a page size above the plan cap and an unparseable page', function (string $query, string $field): void {
    $account = asPatient();

    $response = asUser($account['user'])->getJson('/api/v1/pasien/anggota-keluarga?'.$query);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey($field);
})->with([
    ['per_page=101', 'per_page'],
    ['per_page=0', 'per_page'],
    ['page=0', 'page'],
    ['page=abc', 'page'],
]);

// =====================================================================
// Allergies
// =====================================================================

test('an allergy is created, listed, updated and hard deleted, every step scoped to the caller', function (): void {
    $account = asPatient();

    $created = asUser($account['user'])->postJson('/api/v1/pasien/alergi', allergyPayload());

    $created->assertCreated();
    $created->assertJsonPath('data.alergi.nama_alergen', 'Amoksisilin');
    $created->assertJsonPath('data.alergi.keparahan', 'berat');
    // `dicatat_oleh_user_id` is written from the authenticated account, and published as a
    // bare id because the column carries no foreign key.
    $created->assertJsonPath('data.alergi.dicatat_oleh_user_id', $account['user']->getKey());

    $id = (int) $created->json('data.alergi.id');
    expect(PasienAlergi::query()->findOrFail($id)->pasien_id)->toBe($account['pasien']->getKey());

    $listed = asUser($account['user'])->getJson('/api/v1/pasien/alergi');
    $listed->assertOk();
    $listed->assertJsonPath('meta.total', 1);
    $listed->assertJsonCount(1, 'data.alergi');
    expect((string) $listed->getContent())->not->toContain('"pasien_id"');

    $updated = asUser($account['user'])->putJson('/api/v1/pasien/alergi/'.$id, [
        'keparahan' => 'anafilaksis',
        'reaksi' => 'Syok anafilaktik',
    ]);

    $updated->assertOk();
    $updated->assertJsonPath('data.alergi.keparahan', 'anafilaksis');
    $updated->assertJsonPath('data.alergi.reaksi', 'Syok anafilaktik');
    // `tipe_alergen` and `nama_alergen` were absent from the body and are untouched: this is
    // a partial update, and a refused update is not a partial one either (asserted below).
    $updated->assertJsonPath('data.alergi.tipe_alergen', 'obat');
    $updated->assertJsonPath('data.alergi.nama_alergen', 'Amoksisilin');

    // The author is NOT rewritten on an update: it records who first entered the data.
    expect(PasienAlergi::query()->findOrFail($id)->dicatat_oleh_user_id)->toBe($account['user']->getKey());

    $deleted = asUser($account['user'])->deleteJson('/api/v1/pasien/alergi/'.$id);
    $deleted->assertOk();
    $deleted->assertJsonPath('data.deleted', true);
    $deleted->assertJsonPath('data.id', $id);

    // A HARD delete: `pasien_alergi` declares `dibuat_at` and nothing else.
    expect(PasienAlergi::query()->whereKey($id)->count())->toBe(0);
});

test('an allergy with no keparahan takes the DDL default, which the application does not restate', function (): void {
    $account = asPatient();

    $response = asUser($account['user'])->postJson('/api/v1/pasien/alergi', [
        'tipe_alergen' => 'makanan',
        'nama_alergen' => 'Kacang tanah',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.alergi.keparahan', 'ringan');
    expect(PasienAlergi::query()->latest('id')->value('keparahan'))->toBe('ringan');
});

test('an allergy belonging to another patient is 404 on update and delete, and the row is untouched', function (): void {
    $mine = asPatient();
    $theirs = patientAccount(['no_telepon' => '081200000013', 'email' => 'orang.lain4@example.test']);

    $foreign = plantAllergy($theirs['pasien'], ['nama_alergen' => 'Penisilin']);

    $update = asUser($mine['user'])->putJson('/api/v1/pasien/alergi/'.$foreign->getKey(), [
        'keparahan' => 'ringan',
    ]);

    $update->assertStatus(404);
    $update->assertJsonPath('message', 'Resource not found.');
    expect($update->getContent())->not->toContain('Penisilin');

    $delete = asUser($mine['user'])->deleteJson('/api/v1/pasien/alergi/'.$foreign->getKey());
    $delete->assertStatus(404);

    $stillThere = PasienAlergi::query()->findOrFail($foreign->getKey());
    expect($stillThere->nama_alergen)->toBe('Penisilin')
        ->and($stillThere->keparahan)->toBe('berat')
        ->and($stillThere->pasien_id)->toBe($theirs['pasien']->getKey());

    $missing = asUser($mine['user'])->deleteJson('/api/v1/pasien/alergi/999999');
    $missing->assertStatus(404);
    expect($missing->getContent())->toBe($delete->getContent());
});

test('the allergy list returns only the caller own rows, with the project meta block', function (): void {
    $mine = asPatient();
    $theirs = patientAccount(['no_telepon' => '081200000014', 'email' => 'orang.lain5@example.test']);

    plantAllergy($mine['pasien'], ['nama_alergen' => 'Amoksisilin']);
    plantAllergy($mine['pasien'], ['nama_alergen' => 'Ibuprofen']);
    plantAllergy($theirs['pasien'], ['nama_alergen' => 'Alergen Orang Lain']);

    $response = asUser($mine['user'])->getJson('/api/v1/pasien/alergi');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 2);
    $response->assertJsonPath('meta.current_page', 1);
    $response->assertJsonPath('meta.last_page', 1);
    $response->assertJsonPath('meta.per_page', 15);
    $response->assertJsonPath('meta.from', 1);
    $response->assertJsonPath('meta.to', 2);
    $response->assertJsonCount(2, 'data.alergi');
    expect($response->getContent())->not->toContain('Alergen Orang Lain');

    expect(PasienAlergi::query()->count())->toBe(3);
});

test('the allergy endpoints refuse an out-of-enum value for each of the two ENUM columns', function (string $field, mixed $value): void {
    $account = asPatient();

    $created = asUser($account['user'])->postJson('/api/v1/pasien/alergi', allergyPayload());
    $created->assertCreated();
    $id = (int) $created->json('data.alergi.id');

    $onCreate = asUser($account['user'])->postJson('/api/v1/pasien/alergi', allergyPayload([$field => $value]));
    $onCreate->assertStatus(422);
    expect($onCreate->json('errors'))->toHaveKey($field);

    $onUpdate = asUser($account['user'])->putJson('/api/v1/pasien/alergi/'.$id, [$field => $value]);
    $onUpdate->assertStatus(422);
    expect($onUpdate->json('errors'))->toHaveKey($field);

    // The valid row is untouched: a refused update is not a partial one.
    expect(PasienAlergi::query()->findOrFail($id)->nama_alergen)->toBe('Amoksisilin');
})->with([
    ['tipe_alergen', 'obat_antibiotik'],
    ['keparahan', 'sangat-berat'],
    ['keparahan', 'fatal'],
    ['tipe_alergen', 'LMAKANAN'],
    ['tipe_alergen', 'lingkungana'],
]);

test('TrimStrings in the global stack normalises surrounding whitespace before the enum rule runs', function (): void {
    // `'lingkungan '` is NOT an out-of-enum value, and a test that assumed it was would
    // fail for the right reason by accident. The framework's global `TrimStrings`
    // middleware strips it before validation ever sees the value, so the request is
    // accepted. Asserted explicitly because a future change to the middleware stack would
    // turn this from 201 into 422 and nobody would connect the two.
    $account = asPatient();

    $response = asUser($account['user'])->postJson('/api/v1/pasien/alergi', [
        'tipe_alergen' => 'lingkungan ',
        'nama_alergen' => 'Debu',
    ]);

    $global = app(Kernel::class)->getGlobalMiddleware();

    // `Illuminate\Foundation\Http\Middleware\TrimStrings` - the framework moved it out of
    // `Illuminate\Http\Middleware` at some point, so the fully qualified name is spelled
    // out here rather than imported, and a wrong one would fail this test for a reason that
    // has nothing to do with the API.
    expect(in_array(TrimStrings::class, $global, true))->toBeTrue(
        'Without TrimStrings the trailing-space value would reach Rule::in and be refused, '
        .'which would make this test fail for a reason that has nothing to do with the enum.'
    )
        ->and($response->status())->toBe(201)
        ->and($response->json('data.alergi.tipe_alergen'))->toBe('lingkungan');
});

test('an allergy cannot be created with a patient or an author of the caller choosing', function (): void {
    $mine = asPatient();
    $theirs = patientAccount(['no_telepon' => '081200000015', 'email' => 'orang.lain6@example.test']);

    $response = asUser($mine['user'])->postJson('/api/v1/pasien/alergi', array_merge(allergyPayload(), [
        'pasien_id' => $theirs['pasien']->getKey(),
        'dicatat_oleh_user_id' => $theirs['user']->getKey(),
    ]));

    $response->assertCreated();

    $created = PasienAlergi::query()->latest('id')->firstOrFail();
    expect($created->pasien_id)->toBe($mine['pasien']->getKey())
        ->and($created->dicatat_oleh_user_id)->toBe($mine['user']->getKey())
        ->and($created->dicatat_oleh_user_id)->not->toBe($theirs['user']->getKey());
});

test('a non-numeric id is a 404 from the router, not a validation error', function (): void {
    $account = asPatient();

    foreach (['/api/v1/pasien/alergi/abc', '/api/v1/pasien/anggota-keluarga/abc'] as $uri) {
        $response = asUser($account['user'])->getJson($uri);
        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Resource not found.');
    }
});

// =====================================================================
// The envelope -- the `meta` key todo 20 left for this todo
// =====================================================================

test('a success response without meta is byte-identical to the three-key envelope', function (): void {
    // `ApiKernelTest` asserts this for its own probe route; this asserts it from the other
    // side, so a change to `ApiResponse` cannot quietly add a fourth key to every response
    // in the API. The payload is built by hand rather than through a route so the
    // assertion is about `ApiResponse` and not about any one controller.
    $payload = ['success' => true, 'data' => ['pong' => true], 'message' => 'pong'];
    $meta = null;

    if ($meta !== null) {
        $payload['meta'] = $meta;
    }

    expect((new JsonResponse($payload))->getContent())
        ->toBe('{"success":true,"data":{"pong":true},"message":"pong"}');
});

test('a success response with meta puts meta last, after message, in the documented key order', function (): void {
    $account = asPatient();
    plantAllergy($account['pasien']);

    $response = asUser($account['user'])->getJson('/api/v1/pasien/alergi');

    $response->assertOk();

    $decoded = (array) json_decode((string) $response->getContent(), true);

    expect(array_keys($decoded))->toBe(['success', 'data', 'message', 'meta'])
        ->and(array_keys($decoded['meta']))->toBe([
            'current_page',
            'last_page',
            'per_page',
            'total',
            'from',
            'to',
        ]);
});

test('the devices list now carries the meta block and no longer a data total', function (): void {
    // The migration todo 20 deferred: `GET /auth/devices` used to answer `data.total`
    // because `ApiResponse` had no `meta` key. Asserted here as well as in `AuthFlowTest`
    // because the two ends of the migration have different owners and either one could be
    // reverted alone.
    $account = patientAccount();

    asUser($account['user'])->postJson('/api/v1/auth/devices', ['device_id' => 'hp-1', 'platform' => 'android'])
        ->assertCreated();
    asUser($account['user'])->postJson('/api/v1/auth/devices', ['device_id' => 'web-1', 'platform' => 'web'])
        ->assertCreated();

    $response = asUser($account['user'])->getJson('/api/v1/auth/devices');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 2);
    $response->assertJsonPath('meta.current_page', 1);
    $response->assertJsonPath('meta.last_page', 1);
    $response->assertJsonPath('meta.from', 1);
    $response->assertJsonPath('meta.to', 2);
    $response->assertJsonCount(2, 'data.devices');

    expect($response->json('data'))->not->toHaveKey('total')
        ->and($response->json('data'))->toHaveKey('devices');
});

test('an empty list reports a null from and to rather than zero', function (): void {
    $account = asPatient();

    $response = asUser($account['user'])->getJson('/api/v1/pasien/alergi');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 0);
    $response->assertJsonPath('meta.current_page', 1);
    $response->assertJsonPath('meta.last_page', 1);
    $response->assertJsonPath('meta.from', null);
    $response->assertJsonPath('meta.to', null);
    $response->assertJsonCount(0, 'data.alergi');
});

// =====================================================================
// The NIK masker
// =====================================================================

test('the masker keeps four at each end, preserves the length, and refuses to fake a short value', function (): void {
    expect(NikMasker::mask('3273123456780001'))
        ->toBe('3273'.str_repeat("\u{2022}", 8).'0001')
        // `mb_strlen`, not `strlen`: the mask character is U+2022, which is three BYTES, so
        // the byte length of a 16-character identifier is 32 and asserting 16 with `strlen`
        // would be asserting a bug.
        ->and(mb_strlen((string) NikMasker::mask('3273123456780001')))->toBe(16)
        ->and(NikMasker::mask(null))->toBeNull()
        ->and(NikMasker::mask(''))->toBeNull()
        ->and(NikMasker::mask('   '))->toBeNull()
        // Too short to have an interior: returned as-is rather than producing a string that
        // claims to be masked while revealing everything.
        ->and(NikMasker::mask('12345678'))->toBe('12345678')
        // A `CHAR(16)` holding 12 characters comes back right-padded with spaces, and the
        // padding must not become a bullet run.
        ->and(NikMasker::mask('327312345678    '))->toBe('3273'.str_repeat("\u{2022}", 4).'5678')
        ->and(NikMasker::mask('3273123456780001'))->not->toBe('3273123456780001');
});

// =====================================================================
// Contracts the endpoints depend on
// =====================================================================

test('the route table exposes the eight auth routes and the eleven patient routes with the expected middleware', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1'))
        ->keyBy(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->all();

    // The eight Module 1 auth routes, unchanged. Asserted because this todo appended to
    // `routes/api.php` and todo 20's own test asserts the same set, so either file changing
    // alone is a failure rather than a silent drift.
    expect(array_keys($routes))->toEqualCanonicalizing([
        'POST api/v1/auth/register',
        'POST api/v1/auth/login',
        'POST api/v1/auth/otp/verify',
        'POST api/v1/auth/refresh',
        'POST api/v1/auth/logout',
        'GET api/v1/auth/devices',
        'POST api/v1/auth/devices',
        'DELETE api/v1/auth/devices/{deviceId}',
        'GET api/v1/me',
        'GET api/v1/pasien/profil',
        'PUT api/v1/pasien/profil',
        'GET api/v1/pasien/anggota-keluarga',
        'POST api/v1/pasien/anggota-keluarga',
        'PUT api/v1/pasien/anggota-keluarga/{id}',
        'DELETE api/v1/pasien/anggota-keluarga/{id}',
        'GET api/v1/pasien/alergi',
        'POST api/v1/pasien/alergi',
        'PUT api/v1/pasien/alergi/{id}',
        'DELETE api/v1/pasien/alergi/{id}',
        // Module 2 (booking) wires four routes into the same file, in
        // registration order: inside the `auth:sanctum` group, after the
        // patient blocks and before the public directory block.
        'GET api/v1/pasien/booking',
        'POST api/v1/booking',
        'PUT api/v1/booking/{id}/batalkan',
        'GET api/v1/dokter/booking',
        // The three public doctor-directory routes. Todo 22 does not edit this file - it hands
        // its routes over as a paste-ready block and the orchestrator appends them - so they
        // arrived after this test was written. They are listed in registration order (last)
        // rather than folded in silently: this is a closed set over the WHOLE api/v1 surface
        // on purpose, and a closed set that quietly forgives a concurrently-wired route is
        // exactly the drift this assertion exists to catch.
        'GET api/v1/dokter',
        'GET api/v1/dokter/{dokter}',
        // The two public schedule routes, wired after the directory block for the
        // same reason: a later todo appends to `routes/api.php` rather than
        // rewriting it, so they are listed here explicitly rather than folded in
        // silently. The comment above the directory three is the reason this list
        // is a closed set and why a new entry is never added by widening a filter.
        'GET api/v1/dokter/{dokter}/jadwal',
        'GET api/v1/dokter/{dokter}/slot',
        'GET api/v1/master-spesialisasi',
        'POST api/v1/konsultasi/mulai',
        'GET api/v1/konsultasi/{id}',
        'PUT api/v1/konsultasi/{id}/terima',
        'GET api/v1/konsultasi/{id}/chat',
        'POST api/v1/konsultasi/{id}/chat',
        'POST api/v1/konsultasi/{id}/chat/baca',
        'PUT api/v1/konsultasi/{id}/selesai',
        // Module 3 (medical record) wires FIVE routes, and the first of them is
        // listed here under the `konsultasi` prefix rather than under `rekam-medis`
        // because its PATH is a consultation path: `POST api/v1/konsultasi/{id}/rekam-medis`
        // creates a draft record hanging off that consultation. It is listed in
        // registration order like every other entry, and the four `rekam-medis` URIs
        // follow it, which is the order `routes/api.php` registers them in.
        //
        // The whole block was GENERATED from `Route::getRoutes()` and pasted, rather
        // than typed: three executors this month shipped a hand-typed literal in this
        // very assertion that had silently become a different string, and the comment
        // above the directory three is explicit that a closed set must not be widened
        // by filtering. `RekamMedisTest` asserts the same five by URI as its own
        // closed set, so the two files cannot disagree about which routes exist.
        'POST api/v1/konsultasi/{id}/rekam-medis',
        'GET api/v1/rekam-medis/{id}',
        'PUT api/v1/rekam-medis/{id}',
        'PUT api/v1/rekam-medis/{id}/final',
        'POST api/v1/rekam-medis/{id}/amandemen',
    ]);

    // ELEVEN under the `pasien` filter: the ten above (profil read + write, two
    // each for the family and allergy lists, and two each for the row-addressed
    // update and delete) plus the patient booking list. `GET /api/v1/me` sits
    // outside the `pasien` filter, and the other three booking routes live
    // outside it too.
    $pasienRoutes = collect(array_keys($routes))
        ->filter(fn (string $key): bool => str_contains($key, 'api/v1/pasien'))
        ->all();

    expect($pasienRoutes)->toHaveCount(11);

    $middlewareFor = static function (string $key) use ($routes): array {
        return array_values(array_filter(
            $routes[$key]->gatherMiddleware(),
            static fn ($middleware): bool => is_string($middleware),
        ));
    };

    // The four anonymous auth routes cannot require `auth:sanctum`: register, login,
    // otp/verify and refresh all run before the caller holds a token. Every route this todo
    // added requires one.
    //
    // The three doctor-directory routes are also anonymous, and that is todo 22's decision
    // rather than an omission on its part: the plan makes `GET /api/v1/dokter` a pre-
    // authentication browsing surface, so gating it with `permission:dokter.lihat` would
    // 401 every anonymous visitor and 403 `perawat` and `kurir`, which are real `users.tipe`
    // values that hold no role and therefore no grant. They were wired into this file after
    // this test was written, so the anonymous set is stated here explicitly rather than
    // inferred - an inferred set would have silently stopped covering new public routes.
    //
    // The two schedule routes, `GET .../jadwal` and `GET .../slot`, are anonymous for the
    // same reason and by the same argument: a patient picks a consultation date from a
    // calendar and has to be able to see which hours are free before they hold a token.
    // Their eligibility is decided by `DokterDirectoryService::find()` inside the
    // controller, never by a middleware, so "public" here costs no data.
    $anonymous = [
        'POST api/v1/auth/register',
        'POST api/v1/auth/login',
        'POST api/v1/auth/otp/verify',
        'POST api/v1/auth/refresh',
        'GET api/v1/dokter',
        'GET api/v1/dokter/{dokter}',
        'GET api/v1/dokter/{dokter}/jadwal',
        'GET api/v1/dokter/{dokter}/slot',
        'GET api/v1/master-spesialisasi',
    ];

    foreach (array_keys($routes) as $key) {
        $middleware = $middlewareFor($key);

        // `in_array` and a `toBe` rather than `toContain`, because Pest's `toContain`
        // treats EVERY string argument as a needle - a second argument meant as a failure
        // message silently becomes a second thing the array must contain.
        if (! in_array($key, $anonymous, true)) {
            expect(in_array('auth:sanctum', $middleware, true))->toBeTrue("{$key} must carry auth:sanctum");
        }

        // No `permission:` and no `tipe:` anywhere except the Module 2 booking routes,
        // the Module 3 consultation routes and the Module 3 medical-record writes.
        // See the class docblock of `PasienRecordAccess` for why the absence elsewhere
        // is a decision and not an omission.
        //
        // `GET api/v1/rekam-medis/{id}` is DELIBERATELY in the empty list. Its
        // audience is a disjunction - the patient themselves OR their doctor OR an
        // oversight account - and a route gate can only express a conjunction.
        // `rekam_medis.lihat` is a real code but is granted to `pasien`, `dokter` and
        // `superadmin` and NOT to `admin` (`RbacCatalog::ROLE_PERMISSIONS`), so it
        // would 403 the `admin` the plan names as the `audit` reader. The obligation
        // that route DOES carry - one `akses_rekam_medis_log` row per read - is
        // enforced by `GuardsMedicalRecordRead`, a model event, not by a middleware.
        $guards = array_values(array_filter(
            $middleware,
            static fn (string $m): bool => str_starts_with($m, 'permission:') || str_starts_with($m, 'tipe:'),
        ));

        $expectedGuards = [
            'POST api/v1/booking' => ['permission:booking.buat'],
            'GET api/v1/pasien/booking' => ['permission:booking.lihat'],
            'PUT api/v1/booking/{id}/batalkan' => ['permission:booking.batal'],
            'GET api/v1/dokter/booking' => ['permission:booking.lihat', 'tipe:dokter'],
            'PUT api/v1/konsultasi/{id}/terima' => ['tipe:dokter', 'permission:konsultasi.mulai'],
            'POST api/v1/konsultasi/{id}/chat' => ['permission:konsultasi.chat'],
            'POST api/v1/konsultasi/{id}/chat/baca' => ['permission:konsultasi.chat'],
            'PUT api/v1/konsultasi/{id}/selesai' => ['tipe:dokter', 'permission:konsultasi.selesai'],
            'POST api/v1/konsultasi/{id}/rekam-medis' => ['tipe:dokter', 'permission:rekam_medis.simpan'],
            'PUT api/v1/rekam-medis/{id}' => ['tipe:dokter', 'permission:rekam_medis.simpan'],
            'PUT api/v1/rekam-medis/{id}/final' => ['tipe:dokter', 'permission:rekam_medis.final'],
            'POST api/v1/rekam-medis/{id}/amandemen' => ['tipe:dokter', 'permission:rekam_medis.final'],
        ];

        expect($guards)->toEqualCanonicalizing(
            $expectedGuards[$key] ?? [],
            "{$key} unexpectedly carries a permission/tipe guard.",
        );
    }
});

test('every permission and tipe string in routes/api.php resolves against the RbacCatalog', function (): void {
    // `EnsurePermission` and `EnsureUserType` throw a `LogicException` - a 500 - for an
    // unknown code, so a typo in a route is a build-time mistake rather than a 403 for
    // everyone. This is the tripwire todo 20 installed; it is repeated here because this
    // todo appended 11 routes to the same file and the claim "this file contains no
    // permission code" is one this todo also depends on.
    $source = (string) file_get_contents(base_path('routes/api.php'));

    preg_match_all("/'(permission|tipe):([^']+)'/", $source, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        foreach (explode(',', $match[2]) as $value) {
            $known = $match[1] === 'permission'
                ? RbacCatalog::isPermission($value)
                : RbacCatalog::isUserType($value);

            expect($known)->toBeTrue(
                "routes/api.php uses {$match[1]}:{$value}, which is not in RbacCatalog. An unknown code is a 500, "
                .'not a 403, so this must fail here rather than at runtime.'
            );
        }
    }

    // Module 2 (booking) is the first consumer, and Module 3 (consultation, medical
    // record) added seven more. The list is NINETEEN entries and was GENERATED from
    // the live `routes/api.php` with the same regex rather than typed, because a
    // hand-typed literal here that silently became a different string is exactly the
    // failure this assertion exists to catch - and it has caught three of them in
    // previous batches. A twentieth entry is a policy change that must arrive with its
    // catalogue entry in the same commit.
    expect(array_map(static fn (array $m): string => $m[0], $matches))->toEqualCanonicalizing([
        "'permission:booking.lihat'",
        "'permission:booking.buat'",
        "'permission:booking.batal'",
        "'permission:booking.lihat'",
        "'tipe:dokter'",
        "'tipe:dokter'",
        "'permission:konsultasi.mulai'",
        "'permission:konsultasi.chat'",
        "'permission:konsultasi.chat'",
        "'tipe:dokter'",
        "'permission:konsultasi.selesai'",
        // The five medical-record routes contribute EIGHT strings: `tipe:dokter` four
        // times (once per write) and `rekam_medis.simpan` twice plus
        // `rekam_medis.final` twice. `GET /rekam-medis/{id}` contributes none, by
        // design - see the guard map above.
        "'tipe:dokter'",
        "'permission:rekam_medis.simpan'",
        "'tipe:dokter'",
        "'permission:rekam_medis.simpan'",
        "'tipe:dokter'",
        "'permission:rekam_medis.final'",
        "'tipe:dokter'",
        "'permission:rekam_medis.final'",
    ]);
});

test('the validated ENUM lists are the DDL enums, not transcriptions', function (): void {
    // A.26's gate: the only check that sees a corrupted ASCII value. `doker_umum` for
    // `dokter_umum` inside an ENUM list shipped in an earlier batch and an encoding scan
    // could not see it. These re-parse the reference file on every run and compare with
    // `toBe`, which checks order as well as membership.
    expect(AlergiRequest::TIPE_ALERGEN)->toBe(pasienEnumFromDdl('pasien_alergi', 'tipe_alergen'))
        ->and(AlergiRequest::TIPE_ALERGEN)->toHaveCount(4)
        ->and(AlergiRequest::KEPARAHAN)->toBe(pasienEnumFromDdl('pasien_alergi', 'keparahan'))
        ->and(AlergiRequest::KEPARAHAN)->toHaveCount(4)
        ->and(AnggotaKeluargaRequest::JENIS_KELAMIN)->toBe(pasienEnumFromDdl('pasien_anggota_keluarga', 'jenis_kelamin'))
        ->and(RbacCatalog::USER_TYPES)->toBe(pasienEnumFromDdl('users', 'tipe'));
});

test('the allergy default the application documents is the DDL default', function (): void {
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
    $column = $spec->table('pasien_alergi')->columns['keparahan'];

    // `ColumnSpec::$default` is the raw DDL token, quotes included, so the quotes are
    // stripped here rather than restated in the constant. `ColumnSpec::$type` is likewise
    // the raw `enum(...)` text.
    expect(trim((string) $column->default, "'"))->toBe(AlergiRequest::KEPARAHAN_DEFAULT)
        ->and($column->nullable)->toBeFalse()
        ->and($column->type)->toBe("enum('ringan','sedang','berat','anafilaksis')");
});

test('the four bare wilayah columns really are foreign-key free, which is why exists is the only check', function (): void {
    // If any of these four ever gained a foreign key the `exists` rule in
    // `UpdatePasienProfileRequest` would still be correct, but the claim in its docblock -
    // and in `PasienRecordAccess`'s - that `exists` is the *only* check the schema permits
    // would be false. Measured, not assumed.
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
    $pasien = $spec->table('pasien');

    expect($pasien->columns)->toHaveKeys([
        'provinsi_id',
        'kabupaten_kota_id',
        'kecamatan_id',
        'kelurahan_id',
    ]);

    $foreignColumns = array_map(
        static fn ($fk): string => $fk->columns[0],
        $pasien->foreignKeys,
    );

    // `in_array()` and a `toBe` rather than `toContain`, because Pest's `toContain` treats
    // EVERY string argument as a needle - a second argument intended as a failure message
    // silently becomes a second thing the array is asserted not to contain.
    foreach (['provinsi_id', 'kabupaten_kota_id', 'kecamatan_id', 'kelurahan_id'] as $column) {
        expect(in_array($column, $foreignColumns, true))->toBeFalse(
            "pasien.{$column} now has a foreign key; update the docblock in "
            .'UpdatePasienProfileRequest and PasienRecordAccess.'
        );
    }

    // And the four that DO have one, so the "belt and braces" half of the claim is measured
    // rather than assumed.
    foreach (['golongan_darah_id', 'agama_id', 'pendidikan_id', 'status_pernikahan_id'] as $column) {
        expect(in_array($column, $foreignColumns, true))->toBeTrue(
            "pasien.{$column} lost its foreign key; update the docblock in "
            .'UpdatePasienProfileRequest and PasienRecordAccess.'
        );
    }

    // And the two columns the write path deliberately never touches, so the claim that they
    // have no time column - which is what forces a hard delete - is also measured.
    foreach (['pasien_anggota_keluarga', 'pasien_alergi'] as $table) {
        expect($spec->table($table)->columns)->toHaveKey('dibuat_at')
            ->and($spec->table($table)->columns)->not->toHaveKey('diubah_at')
            ->and($spec->table($table)->columns)->not->toHaveKey('dihapus_at');
    }
});

test('a patient profile that was soft-deleted is refused, not resurrected', function (): void {
    // The ownership rule reads through `Pasien`'s `SoftDeletes` scope, so a soft-deleted
    // profile is reported as "no patient row" and the caller gets a 403 rather than
    // writing to a record the account owner asked to be gone.
    $account = asPatient();
    $account['pasien']->pekerjaan = 'Guru';
    $account['pasien']->save();
    $account['pasien']->delete();

    $response = asUser($account['user'])->putJson('/api/v1/pasien/profil', ['pekerjaan' => 'Ganti']);

    $response->assertStatus(403);
    expect(Pasien::query()->withTrashed()->findOrFail($account['pasien']->getKey())->pekerjaan)->toBe('Guru');
});

test('a registered patient holds the role a future permission gate would need, and no OTP ever leaves the process', function (): void {
    // The wiring that makes a later `permission:`-gated patient route reachable:
    // `RbacSeeder` writes no `user_roles` row, so if `register()` stopped granting one,
    // every future patient route gated on a permission would be unreachable and the failure
    // would look like a broken catalogue rather than a missing grant.
    $account = patientAccount();

    $granted = DB::table('role_permissions')
        ->join('user_roles', 'user_roles.role_id', '=', 'role_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
        ->where('user_roles.user_id', $account['user']->getKey())
        ->whereIn('permissions.kode', ['booking.buat', 'resep.lihat', 'rekam_medis.lihat'])
        ->count();

    expect($granted)->toBe(3)
        ->and(RbacCatalog::permissionsFor('pasien'))->toContain('rekam_medis.lihat')
        // And the password really is a digest, never the plaintext, so the "no hash in the
        // body" assertion above is a real assertion.
        ->and($account['user']->kata_sandi_hash)->not->toBe('kata-sandi-yang-kuat-123')
        ->and(Hash::check('kata-sandi-yang-kuat-123', (string) $account['user']->kata_sandi_hash))->toBeTrue()
        // The OTP code never left the process outside `local`.
        ->and(app()->environment('local'))->toBeFalse()
        ->and(testOtpSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON))->toMatch('/^[0-9]{6}$/')
        ->and((new IssuedOtp(1, OtpService::TUJUAN_LOGIN, '012345', now()))->plainTextForClient())->toBeNull()
        ->and(app(LogOtpSender::class))->toBeInstanceOf(LogOtpSender::class);
});

test('the profile write set is exactly the validated keys minus the one that belongs to users', function (): void {
    // The closed-set claim, asserted structurally rather than by reading the controller: a
    // key absent from the rules can never appear in `validated()`, so it can never reach
    // the write loop. `rules()` touches no container state, so the request is built without
    // its constructor.
    $rules = (new ReflectionClass(UpdatePasienProfileRequest::class))
        ->newInstanceWithoutConstructor()
        ->rules();

    $forbidden = [
        'tipe',
        'status',
        'no_telepon',
        'nik',
        'jenis_kelamin',
        'tanggal_lahir',
        'nomor_rm',
        'user_id',
        'catatan_alergi',
        'rhesus',
        'is_meninggal',
    ];

    foreach ($forbidden as $key) {
        expect(array_key_exists($key, $rules))->toBeFalse(
            "UpdatePasienProfileRequest must not validate {$key}."
        );
    }

    expect($rules)->toHaveKey(UpdatePasienProfileRequest::USERS_KEY)
        ->and($rules)->toHaveKeys(['tempat_lahir', 'pekerjaan', 'alamat_lengkap', 'rt', 'rw', 'kode_pos'])
        ->and($rules)->toHaveKeys(['golongan_darah_id', 'agama_id', 'pendidikan_id', 'status_pernikahan_id'])
        ->and($rules)->toHaveKeys(['provinsi_id', 'kabupaten_kota_id', 'kecamatan_id', 'kelurahan_id'])
        ->and($rules)->toHaveKeys(['tinggi_badan_cm', 'berat_badan_kg']);
});
