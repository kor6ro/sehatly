<?php

declare(strict_types=1);

use App\Enums\KonsultasiStatus;
use App\Models\Konsultasi;
use App\Models\User;
use App\Services\Konsultasi\KonsultasiService;
use App\Support\Rbac\RbacCatalog;
use App\Support\Rbac\RoleAssigner;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\PasienFixture;

/*
|--------------------------------------------------------------------------
| GET /api/v1/konsultasi -- the doctor's own consultation list (F13)
|--------------------------------------------------------------------------
|
| The endpoint F13's dashboard needed: before it, the only way a doctor could
| discover a consultation was the chat notification the patient's first
| message mints, so a consultation created and never messaged was invisible.
|
| What is proved here:
|
| - the route is a ONE-SEGMENT literal registered before the `{id}` wildcard,
|   carrying `auth:sanctum` + `tipe:dokter` and NO `permission:` (the
|   catalogue holds no consultation read code, so inventing one would be a 500
|   rather than a 403);
| - a doctor sees ONLY their own rows - another doctor's are simply absent,
|   because the tenant filter IS the query;
| - a patient, an admin, an overseer and a profile-less `dokter` account are
|   all refused 403;
| - the `status` filter is the closed dashboard set, and anything else is a
|   422 naming the field rather than an empty page;
| - the documented ordering is the one served: most actionable state first,
|   then `dibuat_at DESC, id DESC`;
| - pagination carries the project `meta` block with the 100 ceiling; and
| - the row publishes no NIK, no contact detail, no SOAP note, no fee and no
|   `room_id` - the disclosure surface is `KonsultasiDaftarResource`.
|
| **Pest closure tests, not a PHPUnit class**, for the reason
| `PasienProfileTest` records: `tests/Pest.php` binds `RefreshDatabase` to
| Pest's Feature tests, and a class in the same directory would silently miss
| it. **Real Sanctum bearer tokens**, never `Sanctum::actingAs()`: the
| transient-token principal is cached by the request guard, and this file
| makes several authenticated requests as different accounts in one test.
|
| The fixtures are local (`kdl*`) rather than reused from `KonsultasiTest`
| (`kns*`): a top-level function in one Pest file exists only when that file
| is included, so a cross-file call would make this file's behaviour depend on
| Pest's include order. `KonsultasiBacaTest` keeps its own `kbt*` copy for the
| same reason.
|
*/

// ------------------------------------------------------------------ helpers

/**
 * A `users` row. `uuid`, `nama_lengkap`, `no_telepon` and `kata_sandi_hash`
 * are the four NOT NULL columns with no default (`telemedicine_test.sql:134-138`).
 */
function kdlUser(string $nama, string $tipe = 'pasien'): int
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
 * An account carrying `$role`, or no role at all - `perawat` and `kurir` are
 * real `users.tipe` values that hold none.
 */
function kdlPengguna(string $tipe, ?string $role = null): User
{
    $id = kdlUser('Pengguna '.$tipe.' '.Str::upper(Str::random(4)), $tipe);

    if ($role !== null) {
        app(RoleAssigner::class)->assign($id, $role);
    }

    return User::query()->findOrFail($id);
}

/**
 * A `pasien` row for `$userId`. The three NOT NULL columns with no default are
 * supplied (`:225`, `:226`, `:234`).
 *
 * @param  array<string, mixed>  $ubah
 */
function kdlPasien(int $userId, array $ubah = []): int
{
    // `PasienFixture` renames a `nik` key to `nik_cipher` and encrypts it with
    // the same `NikCipher::encrypt()` the model mutator calls, which is what
    // makes a NIK fixture legal at all.
    return (int) DB::table('pasien')->insertGetId(PasienFixture::withNik(array_merge([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Daftar No. 9, Jakarta',
    ], $ubah)));
}

/**
 * A `dokter` row for `$userId`, verified and telemedicine-available.
 *
 * @param  array<string, mixed>  $ubah
 */
function kdlDokter(int $userId, array $ubah = []): int
{
    return (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-KDL-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'biaya_konsultasi_online' => '150000.00',
        'tersedia_telemedisin' => 1,
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ], $ubah));
}

/**
 * A `konsultasi` row (instant shape: `booking_id` NULL), written through the
 * model so the lifecycle timestamps behave.
 *
 * @param  array<string, mixed>  $ubah
 */
function kdlSesi(int $pasienId, int $dokterId, string $status = 'menunggu_dokter', array $ubah = []): Konsultasi
{
    $row = new Konsultasi;
    $row->pasien_id = $pasienId;
    $row->dokter_id = $dokterId;
    $row->tipe = 'chat';
    $row->status = $status;
    $row->room_id = (string) Str::uuid();

    foreach ($ubah as $kolom => $nilai) {
        $row->{$kolom} = $nilai;
    }

    $row->save();

    return $row;
}

/**
 * A doctor account owning a `dokter` row, with the `dokter` role.
 *
 * @return array{user: User, dokter: int}
 */
function kdlAkunDokter(array $dokterUbah = []): array
{
    $user = kdlPengguna('dokter', 'dokter');

    return ['user' => $user, 'dokter' => kdlDokter($user->getKey(), $dokterUbah)];
}

/**
 * A patient account row, for building consultations.
 */
function kdlPasienBaru(): int
{
    return kdlPasien(kdlPengguna('pasien', 'pasien')->getKey());
}

/**
 * Act as `$user` for one request. `forgetGuards()` is required because a test
 * here authenticates several accounts in one method.
 */
function kdlAs(User $user): mixed
{
    app('auth')->forgetGuards();

    return test()->withToken($user->createToken('konsultasi-daftar-test', ['*'], now()->addHour())->plainTextToken);
}

beforeEach(function (): void {
    // `tipe:dokter` resolves against `users.tipe` with no database read, but
    // the role assignment in `kdlPengguna()` needs the seeded `roles` rows.
    $this->seed(RbacSeeder::class);
});

// =====================================================================
// The route table
// =====================================================================

test('the list is a one-segment literal before the id wildcard, with tipe:dokter and no permission', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->keyBy(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->all();

    expect($routes)->toHaveKey('GET api/v1/konsultasi');

    $route = $routes['GET api/v1/konsultasi'];

    expect($route->getName())->toBe('konsultasi.index');

    $middleware = array_values(array_filter(
        $route->gatherMiddleware(),
        static fn ($m): bool => is_string($m),
    ));

    expect($middleware)->toContain('auth:sanctum', 'tipe:dokter');

    // `tipe:dokter` and NOTHING else. `RbacCatalog::PERMISSIONS` holds the
    // three consultation codes below and none of them names a read, so a
    // `permission:` here would have to be invented - and `EnsurePermission`
    // answers an unknown code with a 500, not a 403.
    $guards = array_values(array_filter(
        $middleware,
        static fn (string $m): bool => str_starts_with($m, 'permission:') || str_starts_with($m, 'tipe:'),
    ));

    expect($guards)->toEqualCanonicalizing(['tipe:dokter']);

    expect(array_values(array_filter(
        RbacCatalog::permissionCodes(),
        static fn (string $kode): bool => str_starts_with($kode, 'konsultasi.'),
    )))->toBe(['konsultasi.mulai', 'konsultasi.chat', 'konsultasi.selesai']);

    // Registration order: the literal is before the two-segment wildcard. The
    // two cannot collide - one pattern is a single segment and the other two -
    // but "literals before wildcards" is the file's convention and this is
    // what makes it checkable rather than asserted in a comment.
    $uris = array_keys($routes);

    expect(array_search('GET api/v1/konsultasi', $uris, true))
        ->toBeLessThan(array_search('GET api/v1/konsultasi/{id}', $uris, true));
});

// =====================================================================
// Ownership
// =====================================================================

test('lists only the caller own consultations and never another doctor rows', function (): void {
    $milik = kdlAkunDokter();
    $asing = kdlAkunDokter();
    $pasienA = kdlPasienBaru();
    $pasienB = kdlPasienBaru();

    $menunggu = kdlSesi($pasienA, $milik['dokter'], 'menunggu_dokter');
    $berlangsung = kdlSesi($pasienB, $milik['dokter'], 'berlangsung', [
        'mulai_at' => Carbon::parse('2026-10-01 09:00:00'),
    ]);
    $orangLain = kdlSesi($pasienA, $asing['dokter'], 'menunggu_dokter');

    $response = kdlAs($milik['user'])->getJson('/api/v1/konsultasi');

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $response->assertJsonPath('message', 'Daftar konsultasi berhasil dimuat.');
    $response->assertJsonPath('meta.total', 2);
    $response->assertJsonCount(2, 'data.konsultasi');

    // `meta` is a TOP-LEVEL sibling of `data`, never wrapped inside it.
    expect(array_keys($response->json()))->toBe(['success', 'data', 'message', 'meta']);

    $ids = array_column($response->json('data.konsultasi'), 'id');

    expect($ids)->toEqualCanonicalizing([$menunggu->getKey(), $berlangsung->getKey()])
        ->and($ids)->not->toContain($orangLain->getKey());

    // The other doctor's own list sees exactly their one row - the same rule
    // from the other side, so this is a scope and not a filter on one account.
    $lain = kdlAs($asing['user'])->getJson('/api/v1/konsultasi');

    $lain->assertOk();
    $lain->assertJsonPath('meta.total', 1);
    $lain->assertJsonPath('data.konsultasi.0.id', $orangLain->getKey());

    // ...and the other doctor's row is still on the table: this list scopes,
    // it does not move anything.
    expect(Konsultasi::query()->count())->toBe(3);
});

test('refuses a patient, an admin, an overseer and every other account type with 403', function (): void {
    $akun = kdlAkunDokter();
    $sesi = kdlSesi(kdlPasienBaru(), $akun['dokter'], 'menunggu_dokter');

    $terlarang = [
        'pasien' => kdlPengguna('pasien', 'pasien'),
        'admin' => kdlPengguna('admin', 'admin'),
        // Holds `konsultasi.*` and is still refused: the list is a doctor's own
        // worklist, and an oversight account owns no `dokter` row.
        'superadmin' => kdlPengguna('superadmin', 'superadmin'),
        'apoteker' => kdlPengguna('apoteker', 'apoteker'),
        'perawat' => kdlPengguna('perawat'),
        'kurir' => kdlPengguna('kurir'),
    ];

    foreach ($terlarang as $nama => $user) {
        $response = kdlAs($user)->getJson('/api/v1/konsultasi');

        $response->assertStatus(403, "{$nama} must be refused the doctor's list");
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'This action is unauthorized.');

        // A refusal discloses nothing: the planted row exists and its id must
        // not appear in the body.
        expect(str_contains((string) $response->getContent(), (string) $sesi->getKey()))->toBeFalse(
            "the 403 for {$nama} leaked a consultation id",
        );
    }

    // A `dokter`-TYPED account with no `dokter` row passes `tipe:dokter` and
    // is refused 403 by `KonsultasiAccess::ownDokter()`: the profile is
    // incomplete, which is a fact about the caller, not about any row.
    $tanpaProfil = kdlPengguna('dokter', 'dokter');

    expect(DB::table('dokter')->where('user_id', $tanpaProfil->getKey())->count())->toBe(0);

    kdlAs($tanpaProfil)
        ->getJson('/api/v1/konsultasi')
        ->assertStatus(403)
        ->assertJsonPath('success', false)
        // The 403 envelope is fixed for every authorization path
        // (`bootstrap/app.php` renders both `AuthorizationException` and
        // `AccessDeniedHttpException` as this one sanitized message), so the
        // profile-less refusal is indistinguishable from the account-type
        // refusal - which is the point: a caller learns nothing about rows.
        ->assertJsonPath('message', 'This action is unauthorized.');
});

test('an anonymous caller is 401 with the project envelope', function (): void {
    app('auth')->forgetGuards();

    $response = test()->getJson('/api/v1/konsultasi');

    $response->assertStatus(401);
    $response->assertExactJson(['success' => false, 'message' => 'Unauthenticated.', 'errors' => []]);
    expect($response->headers->get('Location'))->toBeNull();
});

// =====================================================================
// Filters and ordering
// =====================================================================

test('the status filter is the closed dashboard set and anything else is a 422', function (): void {
    // The chosen set, pinned against the enum here so a change to either is a
    // visible one rather than a silent divergence between filter and rows.
    expect(KonsultasiService::statusDasbor())
        ->toBe(['menunggu_dokter', 'berlangsung', 'menunggu_resep', 'selesai']);

    foreach (KonsultasiService::statusDasbor() as $nilai) {
        expect(KonsultasiStatus::tryFrom($nilai))->not->toBeNull("{$nilai} must be a KonsultasiStatus case");
    }

    $akun = kdlAkunDokter();
    $pasien = kdlPasienBaru();

    $id = [];

    foreach (KonsultasiService::statusDasbor() as $urutan => $status) {
        $id[$status] = kdlSesi($pasien, $akun['dokter'], $status, [
            'dibuat_at' => Carbon::parse('2026-10-01 08:0'.$urutan.':00'),
        ])->getKey();
    }

    // The two states outside the set, planted to prove the default listing
    // excludes them by construction rather than by luck.
    $id['dibatalkan'] = kdlSesi($pasien, $akun['dokter'], 'dibatalkan')->getKey();
    $id['gagal'] = kdlSesi($pasien, $akun['dokter'], 'gagal')->getKey();

    // No filter: the four dashboard states, and NOT the terminal pair.
    $semua = kdlAs($akun['user'])->getJson('/api/v1/konsultasi');

    $semua->assertOk();
    $semua->assertJsonPath('meta.total', 4);

    expect(array_column($semua->json('data.konsultasi'), 'id'))
        ->toEqualCanonicalizing(array_values(array_slice($id, 0, 4)));

    foreach (KonsultasiService::statusDasbor() as $status) {
        $response = kdlAs($akun['user'])->getJson('/api/v1/konsultasi?status='.$status);

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonCount(1, 'data.konsultasi');
        $response->assertJsonPath('data.konsultasi.0.id', $id[$status]);
        $response->assertJsonPath('data.konsultasi.0.status', $status);
    }

    // A value the dashboard does not speak is a named 422, not an empty page:
    // `[]` would read as "none of those exist", which is a different claim.
    foreach (['dibatalkan', 'gagal', 'setuju', 'aktif'] as $tidak) {
        kdlAs($akun['user'])
            ->getJson('/api/v1/konsultasi?status='.$tidak)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.status.0', 'The selected status is invalid.');
    }
});

test('orders most actionable first, then newest, with the id as the tie-breaker', function (): void {
    $akun = kdlAkunDokter();
    $pasien = kdlPasienBaru();

    $tua = kdlSesi($pasien, $akun['dokter'], 'menunggu_dokter', [
        'dibuat_at' => Carbon::parse('2026-10-01 07:00:00'),
    ]);
    $baru = kdlSesi($pasien, $akun['dokter'], 'menunggu_dokter', [
        'dibuat_at' => Carbon::parse('2026-10-01 09:30:00'),
    ]);
    // Same `dibuat_at` as `$baru`: the `id DESC` half is the only thing that
    // can order them, and a page boundary placed between them must be stable.
    $tie = kdlSesi($pasien, $akun['dokter'], 'menunggu_dokter', [
        'dibuat_at' => Carbon::parse('2026-10-01 09:30:00'),
    ]);
    $berlangsung = kdlSesi($pasien, $akun['dokter'], 'berlangsung', [
        'dibuat_at' => Carbon::parse('2026-10-01 10:00:00'),
        'mulai_at' => Carbon::parse('2026-10-01 10:05:00'),
    ]);
    $selesai = kdlSesi($pasien, $akun['dokter'], 'selesai', [
        'dibuat_at' => Carbon::parse('2026-10-01 11:00:00'),
    ]);

    $response = kdlAs($akun['user'])->getJson('/api/v1/konsultasi');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 5);

    // Rank 0 newest-first: tie (higher id) before baru, then tua. Rank 1, then
    // rank 3. A rank-1 row created AFTER a rank-0 row still sorts below every
    // `menunggu_dokter`, which is the "most actionable first" property.
    expect(array_column($response->json('data.konsultasi'), 'id'))->toBe([
        $tie->getKey(),
        $baru->getKey(),
        $tua->getKey(),
        $berlangsung->getKey(),
        $selesai->getKey(),
    ]);
});

// =====================================================================
// Pagination
// =====================================================================

test('paginates with the project meta block and caps per_page at 100', function (): void {
    $akun = kdlAkunDokter();
    $pasien = kdlPasienBaru();

    $ids = [];

    foreach (range(0, 2) as $posisi) {
        $ids[] = kdlSesi($pasien, $akun['dokter'], 'selesai', [
            'dibuat_at' => Carbon::parse('2026-10-01 08:00:00')->addMinutes($posisi),
        ])->getKey();
    }

    $halaman2 = kdlAs($akun['user'])->getJson('/api/v1/konsultasi?per_page=2&page=2');

    $halaman2->assertOk();
    $halaman2->assertJsonCount(1, 'data.konsultasi');
    // Newest first, so page 2 holds the OLDEST of the three.
    $halaman2->assertJsonPath('data.konsultasi.0.id', $ids[0]);
    $halaman2->assertJsonPath('meta', [
        'current_page' => 2,
        'last_page' => 2,
        'per_page' => 2,
        'total' => 3,
        'from' => 3,
        'to' => 3,
    ]);

    // 100 is the ceiling and 101 is refused by name rather than silently clamped.
    kdlAs($akun['user'])
        ->getJson('/api/v1/konsultasi?per_page=101')
        ->assertStatus(422)
        ->assertJsonPath('errors.per_page.0', 'The jumlah per halaman field must not be greater than 100.');

    kdlAs($akun['user'])
        ->getJson('/api/v1/konsultasi?per_page=100')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

// =====================================================================
// Disclosure
// =====================================================================

test('publishes no NIK, no contact detail, no SOAP note, no fee and no room credential', function (): void {
    $akun = kdlAkunDokter();
    $pasienUser = kdlPengguna('pasien', 'pasien');

    // Sentinels that exist only server-side. If any reaches the body, the
    // list published a field it must not.
    $rahasiaNik = '3273123456780001';
    $rahasiaAlamat = 'Jl. RAHASIA-KDL No. 99, Jakarta';
    $rahasiaSubjektif = 'RAHASIA-KDL-SUBJEKTIF';
    $rahasiaRoom = 'RAHASIA-KDL-ROOM';

    $pasien = kdlPasien($pasienUser->getKey(), [
        'nik' => $rahasiaNik,
        'alamat_lengkap' => $rahasiaAlamat,
    ]);

    $sesi = kdlSesi($pasien, $akun['dokter'], 'berlangsung', [
        'mulai_at' => Carbon::parse('2026-10-01 09:00:00'),
        'catatan_subjektif' => $rahasiaSubjektif,
        'room_id' => $rahasiaRoom,
        'biaya_konsultasi' => '123456.00',
    ]);

    $telepon = (string) DB::table('users')->where('id', $pasienUser->getKey())->value('no_telepon');

    $response = kdlAs($akun['user'])->getJson('/api/v1/konsultasi');

    $response->assertOk();

    // 1. The EXACT key set of a list row. Anything added here is a conscious
    //    widening of the surface and fails this assertion by name.
    expect(array_keys($response->json('data.konsultasi.0')))->toBe([
        'id',
        'tipe',
        'status',
        'mulai_at',
        'selesai_at',
        'pasien',
        'booking',
    ]);

    // 2. The `pasien` block is an id and a name - nothing else.
    expect(array_keys($response->json('data.konsultasi.0.pasien')))->toBe(['id', 'nama_lengkap'])
        ->and($response->json('data.konsultasi.0.mulai_at'))->toBe('2026-10-01T09:00:00.000000Z')
        ->and($response->json('data.konsultasi.0.selesai_at'))->toBeNull()
        // An instant session has no booking, and `null` says so rather than
        // the key disappearing.
        ->and($response->json('data.konsultasi.0.booking'))->toBeNull();

    // 3. The raw body, byte-scanned: the NIK, the phone, the address, the
    //    SOAP note, the room id and the fee are all on the server and none
    //    may appear - nor may the key-shaped spellings of them.
    $mentah = (string) $response->getContent();

    $larangan = [
        $rahasiaNik,
        $telepon,
        $rahasiaAlamat,
        $rahasiaSubjektif,
        $rahasiaRoom,
        '123456.00',
        'nik',
        'no_telepon',
        'alamat_lengkap',
        'catatan_subjektif',
        'catatan_objektif',
        'catatan_asessment',
        'diagnosis_kerja',
        'room_id',
        'biaya_konsultasi',
        'total_durasi_detik',
    ];

    foreach ($larangan as $dilarang) {
        expect(str_contains($mentah, $dilarang))->toBeFalse("the list body leaked [{$dilarang}]");
    }

    // 4. And the row is still addressable: the id the list publishes is the id
    //    the detail endpoint takes, where the doctor reads the clinical content
    //    under `KonsultasiAccess::findForRead()`.
    expect($response->json('data.konsultasi.0.id'))->toBe($sesi->getKey());
});
