<?php

declare(strict_types=1);

use App\Models\Konsultasi;
use App\Models\User;
use App\Support\NamaMasker;
use App\Support\Rbac\RoleAssigner;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Contract\Support\ContractSpec;
use Tests\Contract\Support\LiveRequest;

/*
|--------------------------------------------------------------------------
| F04 -- the doctor review surface: list, write, reply, invitation
|--------------------------------------------------------------------------
|
| The binding contract is `web/ux/patterns/F04.md`. Four decisions shape every
| assertion here:
|
| 1. The aggregate is RECOMPUTED from `ulasan_dokter` on read. `dokter.
|    rating_rata_rata`/`jumlah_ulasan` have no writer and are never read, and
|    one test asserts the stored columns stay at their DDL defaults after a
|    review is written.
| 2. Reviews publish immediately (post-moderation); there is no status column.
| 3. One review per consultation is the `konsultasi_id UNIQUE` index, so the
|    second attempt is a 422 translated from MySQL's 1062.
| 4. A consultation reaching `selesai` sends the patient ONE generic review
|    invitation: no clinical content, no repeat on a second completion.
|
| **Pest closure tests, not a PHPUnit class**, for the reason
| `PasienProfileTest` records: `tests/Pest.php` binds `RefreshDatabase` to the
| Feature directory. **Real Sanctum bearer tokens**, never `Sanctum::actingAs()`,
| because several tests authenticate more than one account in one method.
|
| The fixtures are local (`ulv*`): a top-level function in one Pest file exists
| only when that file is included, so a cross-file call would make this file's
| behaviour depend on Pest's include order.
|
*/

// ------------------------------------------------------------------ helpers

/**
 * A `users` row carrying `$role` (or no role at all).
 *
 * `uuid`, `nama_lengkap`, `no_telepon` and `kata_sandi_hash` are the four NOT
 * NULL columns with no default (`telemedicine_test.sql:134-138`).
 */
function ulvUser(string $nama, string $tipe = 'pasien', ?string $role = 'pasien'): User
{
    $id = (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => $nama,
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => $tipe,
        'status' => 'aktif',
    ]);

    if ($role !== null) {
        app(RoleAssigner::class)->assign($id, $role);
    }

    return User::query()->findOrFail($id);
}

/**
 * A `pasien` row for `$userId`. `jenis_kelamin`, `tanggal_lahir` and
 * `alamat_lengkap` are NOT NULL with no default (`:225`, `:226`, `:234`); `nik`
 * is left NULL deliberately - a written one would put a fake identity number in
 * the repository.
 *
 * @param  array<string, mixed>  $ubah
 */
function ulvPasien(int $userId, array $ubah = []): int
{
    return (int) DB::table('pasien')->insertGetId(array_merge([
        'user_id' => $userId,
        'nomor_rm' => 'RM-ULV-'.Str::upper(Str::random(8)),
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Ulasan No. 1, Jakarta',
    ], $ubah));
}

/**
 * An eligible `dokter` row for `$userId`: verified, active and
 * telemedicine-available, which is exactly the membership test
 * `DokterDirectoryService` applies.
 *
 * @param  array<string, mixed>  $ubah
 */
function ulvDokter(int $userId, array $ubah = []): int
{
    return (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-ULV-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'biaya_konsultasi_online' => '150000.00',
        'tersedia_telemedisin' => 1,
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
        'rating_rata_rata' => '0.00',
        'jumlah_ulasan' => 0,
    ], $ubah));
}

/**
 * A doctor account owning an eligible `dokter` row, with the `dokter` role -
 * which is what holds `ulasan.balas`, so the reply tests measure the route and
 * the service rather than a missing seed grant.
 *
 * @param  array<string, mixed>  $dokterUbah
 * @return array{user: User, dokter: int}
 */
function ulvAkunDokter(array $dokterUbah = []): array
{
    $user = ulvUser('dr. Uji '.Str::upper(Str::random(4)), 'dokter', 'dokter');

    return ['user' => $user, 'dokter' => ulvDokter((int) $user->getKey(), $dokterUbah)];
}

/**
 * A patient account plus its `pasien` row.
 *
 * @return array{user: User, pasien: int}
 */
function ulvAkunPasien(string $nama = 'Pasien Uji'): array
{
    $user = ulvUser($nama, 'pasien', 'pasien');

    return ['user' => $user, 'pasien' => ulvPasien((int) $user->getKey())];
}

/**
 * Act as `$user` for one request. `forgetGuards()` first, because the auth
 * manager is a singleton whose guard caches the resolved user.
 */
function ulvAs(User $user): mixed
{
    app('auth')->forgetGuards();

    return test()->withToken($user->createToken('ulv-test', ['*'], now()->addHour())->plainTextToken);
}

/**
 * A `konsultasi` row written through the model, so the lifecycle timestamps
 * behave. The instant shape (`booking_id` NULL) needs no schedule and no
 * payment. `selesai` rows carry `mulai_at`/`selesai_at` so they are realistic.
 *
 * @param  array<string, mixed>  $ubah
 */
function ulvSesi(int $pasienId, int $dokterId, string $status = 'selesai', array $ubah = []): Konsultasi
{
    $row = new Konsultasi;
    $row->pasien_id = $pasienId;
    $row->dokter_id = $dokterId;
    $row->tipe = 'chat';
    $row->status = $status;
    $row->room_id = (string) Str::uuid();

    if ($status === 'selesai') {
        $row->mulai_at = '2026-10-01 09:00:00';
        $row->selesai_at = '2026-10-01 09:15:00';
        $row->total_durasi_detik = 900;
    }

    foreach ($ubah as $kolom => $nilai) {
        $row->{$kolom} = $nilai;
    }

    $row->save();

    return $row;
}

/**
 * A `ulasan_dokter` row written through the query builder.
 *
 * The fixture does not go through the model on purpose: the tests that count
 * what an HTTP write produced must not have their own setup audited or cast,
 * and `dibuat_at` needs an explicit value for the ordering tests.
 *
 * @param  array<string, mixed>  $ubah
 */
function ulvUlasan(int $konsultasiId, int $pasienId, int $dokterId, array $ubah = []): int
{
    return (int) DB::table('ulasan_dokter')->insertGetId(array_merge([
        'konsultasi_id' => $konsultasiId,
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'rating' => 5,
        'is_anonim' => 1,
        'dibuat_at' => '2026-10-01 10:00:00',
    ], $ubah));
}

/** The `notifikasi` rows written for one account, oldest first. @return list<object> */
function ulvNotifikasi(int $userId): array
{
    return DB::table('notifikasi')->where('user_id', $userId)->orderBy('id')->get()->all();
}

beforeEach(function (): void {
    // `RbacSeeder` writes the roles and grants the route middleware resolves;
    // without it the doctor's two lifecycle writes answer 500 rather than the
    // behaviour under test.
    $this->seed(RbacSeeder::class);
});

// =====================================================================
// A. GET /api/v1/dokter/{dokter}/ulasan -- the public read
// =====================================================================

test('the review list is public, and ineligibility is the directory 404 body for body', function (): void {
    $dokter = ulvAkunDokter();
    $pasien = ulvAkunPasien();

    ulvUlasan((int) ulvSesi($pasien['pasien'], $dokter['dokter'])->getKey(), $pasien['pasien'], $dokter['dokter']);

    // No bearer token at all: the list is pre-authentication content.
    $this->getJson('/api/v1/dokter/'.$dokter['dokter'].'/ulasan')->assertOk();

    // An unverified doctor is hidden EXACTLY as `GET /dokter/{dokter}` hides it.
    $pending = ulvAkunDokter(['status_verifikasi' => 'pending']);

    $this->getJson('/api/v1/dokter/'.$pending['dokter'].'/ulasan')
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');

    // A non-numeric segment is a router 404, not a TypeError.
    $this->getJson('/api/v1/dokter/abc/ulasan')->assertNotFound();
});

test('an empty review list is honest: zero total, no average, a zeroed distribution', function (): void {
    $dokter = ulvAkunDokter();

    $response = $this->getJson('/api/v1/dokter/'.$dokter['dokter'].'/ulasan')->assertOk();

    $response->assertJsonCount(0, 'data.ulasan')
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.rata_rata', null)
        ->assertJsonPath('meta.rata_rata_komunikasi', null)
        ->assertJsonPath('meta.rata_rata_akurasi', null)
        ->assertJsonPath('meta.distribusi.1', 0)
        ->assertJsonPath('meta.distribusi.5', 0);
});

test('the list item is an allow-list: no reviewer id, no consultation id, no clinical join', function (): void {
    $dokter = ulvAkunDokter();
    $pasien = ulvAkunPasien('Rahasia Sekali');
    $konsultasi = ulvSesi($pasien['pasien'], $dokter['dokter']);

    ulvUlasan((int) $konsultasi->getKey(), $pasien['pasien'], $dokter['dokter'], [
        'rating' => 4,
        'rating_komunikasi' => 5,
        'rating_akurasi' => 3,
        'isi' => 'Penjelasan dokter sangat jelas.',
        'is_anonim' => 1,
    ]);

    $response = $this->getJson('/api/v1/dokter/'.$dokter['dokter'].'/ulasan')->assertOk();

    $item = $response->json('data.ulasan.0');

    expect(array_keys($item))->toBe([
        'id',
        'rating',
        'rating_komunikasi',
        'rating_akurasi',
        'isi',
        'is_anonim',
        'penulis',
        'balasan_dokter',
        'dibalas_at',
        'dibuat_at',
    ]);

    expect($item['rating'])->toBe(4)
        ->and($item['rating_komunikasi'])->toBe(5)
        ->and($item['rating_akurasi'])->toBe(3)
        ->and($item['isi'])->toBe('Penjelasan dokter sangat jelas.')
        ->and($item['is_anonim'])->toBeTrue()
        ->and($item['penulis'])->toBeNull()
        ->and($item['balasan_dokter'])->toBeNull()
        ->and($item['dibalas_at'])->toBeNull();
});

test('the list never publishes pasien_id or konsultasi_id anywhere in the body', function (): void {
    $dokter = ulvAkunDokter();
    $pasien = ulvAkunPasien();
    $konsultasi = ulvSesi($pasien['pasien'], $dokter['dokter']);

    ulvUlasan((int) $konsultasi->getKey(), $pasien['pasien'], $dokter['dokter'], ['is_anonim' => 0]);

    $raw = (string) $this->getJson('/api/v1/dokter/'.$dokter['dokter'].'/ulasan')->assertOk()->getContent();

    expect($raw)->not->toContain('pasien_id')
        ->and($raw)->not->toContain('konsultasi_id')
        ->and($raw)->not->toContain('"dokter_id"');
});

test('an anonymous review hides the author and a named one publishes only a masked name', function (): void {
    $dokter = ulvAkunDokter();
    $pasien = ulvAkunPasien('Dewi Lestari');
    $session = fn (): int => (int) ulvSesi($pasien['pasien'], $dokter['dokter'])->getKey();

    ulvUlasan($session(), $pasien['pasien'], $dokter['dokter'], [
        'rating' => 5,
        'is_anonim' => 1,
        'dibuat_at' => '2026-10-02 10:00:00',
    ]);

    ulvUlasan($session(), $pasien['pasien'], $dokter['dokter'], [
        'rating' => 4,
        'is_anonim' => 0,
        'dibuat_at' => '2026-10-03 10:00:00',
    ]);

    $response = $this->getJson('/api/v1/dokter/'.$dokter['dokter'].'/ulasan')->assertOk();
    $raw = (string) $response->getContent();

    // Newest first, so slot 0 is the NAMED review and slot 1 the anonymous one.
    expect($response->json('data.ulasan.0.is_anonim'))->toBeFalse()
        ->and($response->json('data.ulasan.0.penulis'))->toBe(NamaMasker::mask('Dewi Lestari'))
        ->and($response->json('data.ulasan.1.is_anonim'))->toBeTrue()
        ->and($response->json('data.ulasan.1.penulis'))->toBeNull();

    // The full name is nowhere on the wire, masked form included.
    expect($raw)->not->toContain('Dewi Lestari')
        ->and($raw)->not->toContain('Lestari');
});

test('the default order is newest first and the two score orders are total', function (): void {
    $dokter = ulvAkunDokter();
    $pasien = ulvAkunPasien();

    foreach ([[4, '2026-10-01 10:00:00'], [5, '2026-10-02 10:00:00'], [3, '2026-10-03 10:00:00']] as [$rating, $dibuat]) {
        ulvUlasan(
            (int) ulvSesi($pasien['pasien'], $dokter['dokter'])->getKey(),
            $pasien['pasien'],
            $dokter['dokter'],
            ['rating' => $rating, 'dibuat_at' => $dibuat],
        );
    }

    $base = '/api/v1/dokter/'.$dokter['dokter'].'/ulasan';

    $terbaru = $this->getJson($base)->assertOk();
    expect(array_column($terbaru->json('data.ulasan'), 'rating'))->toBe([3, 5, 4]);

    $tertinggi = $this->getJson($base.'?sort=tertinggi')->assertOk();
    expect(array_column($tertinggi->json('data.ulasan'), 'rating'))->toBe([5, 4, 3]);

    $terendah = $this->getJson($base.'?sort=terendah')->assertOk();
    expect(array_column($terendah->json('data.ulasan'), 'rating'))->toBe([3, 4, 5]);

    // A closed vocabulary: an unknown sort is a 422 naming the field, not a
    // silent fallback to the default.
    $this->getJson($base.'?sort=membantu')->assertStatus(422)->assertJsonStructure(['errors' => ['sort']]);
});

test('the rating filter narrows the page but never the aggregate', function (): void {
    $dokter = ulvAkunDokter();
    $pasien = ulvAkunPasien();

    foreach ([5, 3] as $rating) {
        ulvUlasan(
            (int) ulvSesi($pasien['pasien'], $dokter['dokter'])->getKey(),
            $pasien['pasien'],
            $dokter['dokter'],
            ['rating' => $rating],
        );
    }

    $base = '/api/v1/dokter/'.$dokter['dokter'].'/ulasan';

    $filtered = $this->getJson($base.'?rating=5')->assertOk();

    expect($filtered->json('data.ulasan'))->toHaveCount(1)
        ->and($filtered->json('meta.total'))->toBe(1)
        // The distribution is the doctor's WHOLE review history: clicking a bar
        // must not change the bar that was clicked.
        ->and($filtered->json('meta.distribusi.5'))->toBe(1)
        ->and($filtered->json('meta.distribusi.3'))->toBe(1)
        // JSON numbers do not preserve a trailing `.0`: `json_encode(4.0)` is
        // `4`, so the comparison is numeric rather than type-identical.
        ->and((float) $filtered->json('meta.rata_rata'))->toBe(4.0);

    $this->getJson($base.'?rating=6')->assertStatus(422);
    $this->getJson($base.'?rating=abc')->assertStatus(422);
    $this->getJson($base.'?per_page=51')->assertStatus(422);
    $this->getJson($base.'?per_page=50')->assertOk();
});

test('averages ignore an absent sub-rating and the distribution counts every star', function (): void {
    $dokter = ulvAkunDokter();
    $pasien = ulvAkunPasien();

    // Three reviews: 5 with both sub-ratings, 4 with communication only, 4 with
    // neither. The sub-averages must divide by the reviews that SCORED them.
    $rows = [
        ['rating' => 5, 'rating_komunikasi' => 5, 'rating_akurasi' => 4],
        ['rating' => 4, 'rating_komunikasi' => 5, 'rating_akurasi' => null],
        ['rating' => 4, 'rating_komunikasi' => null, 'rating_akurasi' => null],
    ];

    foreach ($rows as $row) {
        ulvUlasan(
            (int) ulvSesi($pasien['pasien'], $dokter['dokter'])->getKey(),
            $pasien['pasien'],
            $dokter['dokter'],
            $row,
        );
    }

    $response = $this->getJson('/api/v1/dokter/'.$dokter['dokter'].'/ulasan')->assertOk();

    // (5 + 4 + 4) / 3 = 4.33; communication (5 + 5) / 2 = 5.0; accuracy 4 / 1 = 4.0.
    expect((float) $response->json('meta.rata_rata'))->toBe(4.33)
        ->and((float) $response->json('meta.rata_rata_komunikasi'))->toBe(5.0)
        ->and((float) $response->json('meta.rata_rata_akurasi'))->toBe(4.0)
        ->and($response->json('meta.distribusi.5'))->toBe(1)
        ->and($response->json('meta.distribusi.4'))->toBe(2)
        ->and($response->json('meta.distribusi.1'))->toBe(0);
});

test('the published meta schema accepts the recomputed review aggregate', function (): void {
    $dokter = ulvAkunDokter();
    $pasien = ulvAkunPasien();

    ulvUlasan(
        (int) ulvSesi($pasien['pasien'], $dokter['dokter'])->getKey(),
        $pasien['pasien'],
        $dokter['dokter'],
        ['rating' => 4, 'rating_komunikasi' => 4, 'rating_akurasi' => 5],
    );

    $response = $this->getJson('/api/v1/dokter/'.$dokter['dokter'].'/ulasan')->assertOk();

    $violations = LiveRequest::validateAgainstDocument(
        $response,
        ContractSpec::specOperations()['get /api/v1/dokter/{dokter}/ulasan'],
    );

    expect($violations)->toBe([], implode("\n", $violations));
});

// =====================================================================
// B. POST /api/v1/konsultasi/{id}/ulasan -- the patient write
// =====================================================================

test('the consultation own patient writes one review, and the aggregate is only recomputed', function (): void {
    $dokter = ulvAkunDokter();
    $pasien = ulvAkunPasien();
    $konsultasi = ulvSesi($pasien['pasien'], $dokter['dokter']);

    $response = ulvAs($pasien['user'])
        ->postJson('/api/v1/konsultasi/'.$konsultasi->getKey().'/ulasan', [
            'rating' => 4,
            'rating_komunikasi' => 5,
            'rating_akurasi' => 4,
            'isi' => 'Penjelasan jelas dan sabar.',
            'is_anonim' => false,
        ])
        ->assertCreated();

    expect($response->json('data.ulasan.rating'))->toBe(4)
        ->and($response->json('data.ulasan.is_anonim'))->toBeFalse()
        ->and($response->json('data.ulasan.penulis'))->toBe(NamaMasker::mask($pasien['user']->nama_lengkap));

    $row = DB::table('ulasan_dokter')->where('konsultasi_id', $konsultasi->getKey())->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->pasien_id)->toBe($pasien['pasien'])
        ->and((int) $row->dokter_id)->toBe($dokter['dokter'])
        ->and((int) $row->rating)->toBe(4)
        ->and((bool) $row->is_anonim)->toBeFalse();

    // DECISION: the aggregate columns on `dokter` are never maintained. They stay
    // at the DDL defaults, and the list recomputes from `ulasan_dokter`.
    $stored = DB::table('dokter')->where('id', $dokter['dokter'])->first();

    expect((string) $stored->rating_rata_rata)->toBe('0.00')
        ->and((int) $stored->jumlah_ulasan)->toBe(0);

    $list = $this->getJson('/api/v1/dokter/'.$dokter['dokter'].'/ulasan')->assertOk();

    expect($list->json('meta.total'))->toBe(1)
        ->and((float) $list->json('meta.rata_rata'))->toBe(4.0)
        ->and($list->json('meta.distribusi.4'))->toBe(1);
});

test('is_anonim defaults to true and a second review for the same consultation is 422', function (): void {
    $dokter = ulvAkunDokter();
    $pasien = ulvAkunPasien();
    $konsultasi = ulvSesi($pasien['pasien'], $dokter['dokter']);
    $url = '/api/v1/konsultasi/'.$konsultasi->getKey().'/ulasan';

    ulvAs($pasien['user'])->postJson($url, ['rating' => 5])->assertCreated();

    $row = DB::table('ulasan_dokter')->where('konsultasi_id', $konsultasi->getKey())->first();

    expect((int) $row->is_anonim)->toBe(1)
        ->and($row->isi)->toBeNull()
        ->and($row->rating_komunikasi)->toBeNull();

    // `konsultasi_id` is `NOT NULL UNIQUE`: the database, not a pre-check, is
    // what makes this impossible, and the 1062 is translated into a 422.
    ulvAs($pasien['user'])
        ->postJson($url, ['rating' => 1])
        ->assertStatus(422)
        ->assertJsonPath('errors.konsultasi_id.0', 'Konsultasi ini sudah memiliki ulasan.');

    expect(DB::table('ulasan_dokter')->count())->toBe(1);
});

test('a consultation that has not reached selesai refuses the write with errors.status', function (): void {
    $dokter = ulvAkunDokter();
    $pasien = ulvAkunPasien();

    foreach (['menunggu_dokter', 'berlangsung', 'menunggu_resep'] as $status) {
        $konsultasi = ulvSesi($pasien['pasien'], $dokter['dokter'], $status, [
            'mulai_at' => $status === 'menunggu_dokter' ? null : '2026-10-01 09:00:00',
        ]);

        ulvAs($pasien['user'])
            ->postJson('/api/v1/konsultasi/'.$konsultasi->getKey().'/ulasan', ['rating' => 5])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Ulasan hanya dapat ditulis setelah konsultasi selesai.');
    }

    expect(DB::table('ulasan_dokter')->count())->toBe(0);
});

test('another patient consultation and an absent one answer the same 404, and a doctor is refused 403', function (): void {
    $dokter = ulvAkunDokter();
    $pemilik = ulvAkunPasien('Pemilik Sesi');
    $asing = ulvAkunPasien('Pasien Asing');
    $konsultasi = ulvSesi($pemilik['pasien'], $dokter['dokter']);

    $milikOrangLain = ulvAs($asing['user'])
        ->postJson('/api/v1/konsultasi/'.$konsultasi->getKey().'/ulasan', ['rating' => 5]);
    $tidakAda = ulvAs($asing['user'])
        ->postJson('/api/v1/konsultasi/999999999/ulasan', ['rating' => 5]);

    $milikOrangLain->assertNotFound();
    $tidakAda->assertNotFound();

    expect((string) $milikOrangLain->getContent())->toBe((string) $tidakAda->getContent());

    // A doctor owns no `pasien` row; the refusal is about the caller, so it is
    // a 403 and not a row disclosure.
    ulvAs($dokter['user'])
        ->postJson('/api/v1/konsultasi/'.$konsultasi->getKey().'/ulasan', ['rating' => 5])
        ->assertForbidden();

    // No bearer token: the guard's 401. The guard caches its principal and
    // `withToken()` writes the Authorization header as a DEFAULT, so both the
    // guard and the header have to be cleared before this request.
    app('auth')->forgetGuards();
    $this->withoutHeader('Authorization');

    $this->postJson('/api/v1/konsultasi/'.$konsultasi->getKey().'/ulasan', ['rating' => 5])->assertUnauthorized();

    expect(DB::table('ulasan_dokter')->count())->toBe(0);
});

test('the review body is validated and the machine-owned columns are prohibited', function (): void {
    $dokter = ulvAkunDokter();
    $pasien = ulvAkunPasien();
    $konsultasi = ulvSesi($pasien['pasien'], $dokter['dokter']);
    $url = '/api/v1/konsultasi/'.$konsultasi->getKey().'/ulasan';

    ulvAs($pasien['user'])->postJson($url, [])->assertStatus(422)->assertJsonStructure(['errors' => ['rating']]);
    ulvAs($pasien['user'])->postJson($url, ['rating' => 6])->assertStatus(422);
    ulvAs($pasien['user'])->postJson($url, ['rating' => 0])->assertStatus(422);
    ulvAs($pasien['user'])->postJson($url, ['rating' => 'lima'])->assertStatus(422);
    ulvAs($pasien['user'])->postJson($url, ['rating' => 5, 'rating_komunikasi' => 6])->assertStatus(422);
    ulvAs($pasien['user'])->postJson($url, ['rating' => 5, 'isi' => str_repeat('a', 1001)])->assertStatus(422);
    ulvAs($pasien['user'])->postJson($url, ['rating' => 5, 'is_anonim' => 'mungkin'])->assertStatus(422);

    // A body field that would let the caller choose the row it belongs to.
    ulvAs($pasien['user'])
        ->postJson($url, ['rating' => 5, 'pasien_id' => 1, 'konsultasi_id' => 1])
        ->assertStatus(422);

    expect(DB::table('ulasan_dokter')->count())->toBe(0);
});

// =====================================================================
// C. PUT /api/v1/dokter/ulasan/{id}/balas -- the doctor reply
// =====================================================================

test('the owning doctor replies, the reply is public, and a second put replaces it', function (): void {
    $dokter = ulvAkunDokter();
    $pasien = ulvAkunPasien();
    $ulasanId = ulvUlasan(
        (int) ulvSesi($pasien['pasien'], $dokter['dokter'])->getKey(),
        $pasien['pasien'],
        $dokter['dokter'],
        ['rating' => 2],
    );

    $url = '/api/v1/dokter/ulasan/'.$ulasanId.'/balas';

    $response = ulvAs($dokter['user'])
        ->putJson($url, ['balasan_dokter' => 'Terima kasih atas masukannya.'])
        ->assertOk();

    expect($response->json('data.ulasan.balasan_dokter'))->toBe('Terima kasih atas masukannya.')
        ->and($response->json('data.ulasan.dibalas_at'))->toBeString();

    $row = DB::table('ulasan_dokter')->where('id', $ulasanId)->first();

    expect($row->balasan_dokter)->toBe('Terima kasih atas masukannya.')
        ->and($row->dibalas_at)->not->toBeNull();

    // The reply is public: the list publishes it beside the review.
    $list = $this->getJson('/api/v1/dokter/'.$dokter['dokter'].'/ulasan')->assertOk();

    expect($list->json('data.ulasan.0.balasan_dokter'))->toBe('Terima kasih atas masukannya.');

    // A second PUT replaces the answer; there is no reply history in the schema.
    ulvAs($dokter['user'])->putJson($url, ['balasan_dokter' => 'Kami perbaiki layanan.'])->assertOk();

    expect(DB::table('ulasan_dokter')->where('id', $ulasanId)->value('balasan_dokter'))
        ->toBe('Kami perbaiki layanan.');
});

test('another doctor review is 404, a patient is 403, and the reply body is required', function (): void {
    $dokter = ulvAkunDokter();
    $lain = ulvAkunDokter();
    $pasien = ulvAkunPasien();
    $ulasanId = ulvUlasan(
        (int) ulvSesi($pasien['pasien'], $dokter['dokter'])->getKey(),
        $pasien['pasien'],
        $dokter['dokter'],
    );

    $url = '/api/v1/dokter/ulasan/'.$ulasanId.'/balas';

    // Another doctor holds `ulasan.balas` and is refused by OWNERSHIP: a 404,
    // because a 403 would confirm the review exists.
    ulvAs($lain['user'])->putJson($url, ['balasan_dokter' => 'Bukan milik saya.'])->assertNotFound();

    // A patient is refused at `tipe:dokter`, before any row is read.
    ulvAs($pasien['user'])->putJson($url, ['balasan_dokter' => 'Saya pasien.'])->assertForbidden();

    // The guard caches its principal and `withToken()` writes the Authorization
    // header as a DEFAULT, so both are cleared before the anonymous request.
    app('auth')->forgetGuards();
    $this->withoutHeader('Authorization');

    $this->putJson($url, ['balasan_dokter' => 'Tanpa token.'])->assertUnauthorized();

    // The body: required, capped at 1000, and no caller-chosen row columns.
    ulvAs($dokter['user'])->putJson($url, [])->assertStatus(422)
        ->assertJsonStructure(['errors' => ['balasan_dokter']]);
    ulvAs($dokter['user'])->putJson($url, ['balasan_dokter' => str_repeat('a', 1001)])->assertStatus(422);
    ulvAs($dokter['user'])
        ->putJson($url, ['balasan_dokter' => 'Baik.', 'rating' => 5, 'pasien_id' => 1])
        ->assertStatus(422);

    expect(DB::table('ulasan_dokter')->where('id', $ulasanId)->value('balasan_dokter'))->toBeNull();
});

// =====================================================================
// D. The invitation notification, fired by the real completion
// =====================================================================

test('completing a consultation invites the patient once, generically, and carries no clinical content', function (): void {
    $dokter = ulvAkunDokter();
    $pasien = ulvAkunPasien('Pasien Undangan');

    $konsultasiId = (int) ulvAs($pasien['user'])
        ->postJson('/api/v1/konsultasi/mulai', ['dokter_id' => $dokter['dokter'], 'tipe' => 'chat'])
        ->assertCreated()
        ->json('data.konsultasi.id');

    // No invitation on `mulai`, and none on `terima` either: `selesai` is the
    // only transition that fires it.
    expect(ulvNotifikasi((int) $pasien['user']->getKey()))->toBe([]);

    ulvAs($dokter['user'])->putJson('/api/v1/konsultasi/'.$konsultasiId.'/terima')->assertOk();

    expect(ulvNotifikasi((int) $pasien['user']->getKey()))->toBe([]);

    // The marker is deliberately clinical-looking: it must not travel.
    $marker = 'Amoxicillin 500 mg tiga kali sehari';

    ulvAs($dokter['user'])->putJson('/api/v1/konsultasi/'.$konsultasiId.'/selesai', [
        'catatan_subjektif' => $marker,
        'diagnosis_kerja' => $marker,
    ])->assertOk();

    $rows = ulvNotifikasi((int) $pasien['user']->getKey());

    expect($rows)->toHaveCount(1);

    $row = $rows[0];

    expect((string) $row->tipe)->toBe('sistem')
        ->and((string) $row->judul)->toBe('Konsultasi selesai.')
        ->and((string) $row->isi)->toBe('Anda dapat menulis ulasan untuk dokter ini.')
        ->and((string) $row->tautan)->toBe('/api/v1/konsultasi/'.$konsultasiId.'/ulasan')
        ->and(json_decode((string) $row->payload, true))->toBe(['konsultasi_id' => $konsultasiId])
        ->and($row->dibaca_at)->toBeNull();

    // NO clinical content, in the notification OR its deep link.
    $wire = (string) $row->judul.' '.(string) $row->isi.' '.(string) $row->tautan.' '.(string) $row->payload;
    expect($wire)->not->toContain($marker)
        ->and(strtolower($wire))->not->toContain('amoxicillin')
        ->and($wire)->not->toContain('diagnosis');

    // The DOCTOR who completed the session is not notified about their own act.
    expect(ulvNotifikasi((int) $dokter['user']->getKey()))->toBe([]);

    // A second completion is a 422 (terminal state), so the invitation cannot
    // repeat: the count is still exactly one.
    ulvAs($dokter['user'])
        ->putJson('/api/v1/konsultasi/'.$konsultasiId.'/selesai', ['catatan_subjektif' => 'ulang'])
        ->assertStatus(422);

    expect(ulvNotifikasi((int) $pasien['user']->getKey()))->toHaveCount(1);
});

test('the invitation deep link points at a writable surface the patient can actually use', function (): void {
    $dokter = ulvAkunDokter();
    $pasien = ulvAkunPasien();

    $konsultasiId = (int) ulvAs($pasien['user'])
        ->postJson('/api/v1/konsultasi/mulai', ['dokter_id' => $dokter['dokter'], 'tipe' => 'chat'])
        ->assertCreated()
        ->json('data.konsultasi.id');

    ulvAs($dokter['user'])->putJson('/api/v1/konsultasi/'.$konsultasiId.'/terima')->assertOk();
    ulvAs($dokter['user'])->putJson('/api/v1/konsultasi/'.$konsultasiId.'/selesai')->assertOk();

    $tautan = (string) DB::table('notifikasi')
        ->where('user_id', $pasien['user']->getKey())
        ->value('tautan');

    // The link is the write surface: posting the review the notification invites
    // succeeds on exactly that path.
    expect($tautan)->toBe('/api/v1/konsultasi/'.$konsultasiId.'/ulasan');

    ulvAs($pasien['user'])->postJson($tautan, ['rating' => 5])->assertCreated();
});
