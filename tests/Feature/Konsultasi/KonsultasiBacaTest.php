<?php

declare(strict_types=1);

use App\Events\KonsultasiChatDibaca;
use App\Models\Konsultasi;
use App\Models\KonsultasiBaca;
use App\Models\KonsultasiChat;
use App\Models\Pasien;
use App\Models\User;
use App\Support\Rbac\RoleAssigner;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\PasienFixture;

/*
|--------------------------------------------------------------------------
| F08: the per-participant read marker and the `chat.dibaca` broadcast
|--------------------------------------------------------------------------
|
| `KonsultasiTest` covers the per-message `dibaca_at` stamp; this file is the
| F08 half - `konsultasi_baca`, the `chat.dibaca` event, the `baca` block of
| `GET /konsultasi/{id}`, and the absence of a delete endpoint.
|
| ## The helper prefix is `kbt`
|
| Pest loads every test file into one process, so every helper other files
| declare at file scope is already taken. This file deliberately does NOT reuse
| `kns*` from `KonsultasiTest`: running this file alone must work, and binding
| its fixtures to another file is the cross-dependency the prefix convention
| prevents.
|
| ## Authentication uses real Sanctum bearer tokens
|
| `kbtAs()` calls `forgetGuards()` first for the reason `KonsultasiTest` and
| `PasienProfileTest` both give: `RequestGuard` caches its principal, so without
| it the first authenticated request of a test would decide the caller for every
| later one.
*/

// =====================================================================
// Row builders
// =====================================================================

/**
 * A `users` row. `uuid`, `nama_lengkap`, `no_telepon` and `kata_sandi_hash` are
 * the four NOT NULL columns with no default (`telemedicine_test.sql:134-138`).
 */
function kbtUser(string $nama, string $tipe = 'pasien'): int
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
 * An account carrying `$role`, or no role at all.
 *
 * `perawat` and `kurir` are real `users.tipe` values that hold no role, so the
 * role is nullable and defaults to none.
 */
function kbtPengguna(string $tipe, ?string $role = null): User
{
    $id = kbtUser('Pengguna '.$tipe.' '.Str::upper(Str::random(4)), $tipe);

    if ($role !== null) {
        app(RoleAssigner::class)->assign($id, $role);
    }

    return User::query()->findOrFail($id);
}

/**
 * A `pasien` row for `$userId`. `jenis_kelamin`, `tanggal_lahir` and
 * `alamat_lengkap` are NOT NULL with no default (`:225`, `:226`, `:234`).
 *
 * @param  array<string, mixed>  $ubah
 */
function kbtPasien(int $userId, array $ubah = []): int
{
    return (int) DB::table('pasien')->insertGetId(PasienFixture::withNik(array_merge([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Baca No. 9, Jakarta',
    ], $ubah)));
}

/**
 * A `dokter` row for `$userId`, verified and telemedicine-available by default.
 *
 * @param  array<string, mixed>  $ubah
 */
function kbtDokter(int $userId, array $ubah = []): int
{
    return (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-KBT-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'biaya_konsultasi_online' => '150000.00',
        'tersedia_telemedisin' => 1,
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ], $ubah));
}

/**
 * A `konsultasi` row (instant shape: `booking_id` NULL), written through the
 * model so the lifecycle stamps behave.
 */
function kbtSesi(int $pasienId, int $dokterId): Konsultasi
{
    $row = new Konsultasi;
    $row->pasien_id = $pasienId;
    $row->dokter_id = $dokterId;
    $row->tipe = 'chat';
    $row->status = 'berlangsung';
    $row->room_id = (string) Str::uuid();
    $row->save();

    return $row;
}

/**
 * A `konsultasi_chat` row in the transcript.
 */
function kbtPesan(int $sesiId, int $pengirimUserId, string $pengirimTipe, string $isi = 'Halo'): int
{
    return (int) DB::table('konsultasi_chat')->insertGetId([
        'konsultasi_id' => $sesiId,
        'pengirim_user_id' => $pengirimUserId,
        'pengirim_tipe' => $pengirimTipe,
        'tipe_pesan' => 'teks',
        'isi' => $isi,
    ]);
}

/**
 * Act as `$user` for one request.
 */
function kbtAs(User $user): mixed
{
    app('auth')->forgetGuards();

    return test()->withToken($user->createToken('konsultasi-baca-test', ['*'], now()->addHour())->plainTextToken);
}

/**
 * A running consultation between a fresh patient account and a fresh doctor
 * account, both holding the roles their guards require.
 *
 * @return array{user_pasien: User, pasien: int, user_dokter: User, dokter: int, konsultasi: Konsultasi}
 */
function kbtSesiLengkap(): array
{
    $userPasien = kbtPengguna('pasien', 'pasien');
    $pasienId = kbtPasien($userPasien->getKey());

    $userDokter = kbtPengguna('dokter', 'dokter');
    $dokterId = kbtDokter($userDokter->getKey());

    return [
        'user_pasien' => $userPasien,
        'pasien' => $pasienId,
        'user_dokter' => $userDokter,
        'dokter' => $dokterId,
        'konsultasi' => kbtSesi($pasienId, $dokterId),
    ];
}

beforeEach(function (): void {
    // `EnsurePermission` resolves `konsultasi.chat` against the `permissions`
    // table, and `RoleAssigner` looks the role up in `roles`; without the seeder
    // every guarded call answers 500 and the assertions would measure that.
    $this->seed(RbacSeeder::class);
});

// =====================================================================
// The per-participant marker
// =====================================================================

test('the callers last_read_at is upserted once, and the per-message stamp still works', function (): void {
    $s = kbtSesiLengkap();
    $id = (int) $s['konsultasi']->getKey();

    $dariDokter = kbtPesan($id, $s['user_dokter']->getKey(), 'dokter', 'Pagi, ada keluhan?');

    $first = kbtAs($s['user_pasien'])->postJson('/api/v1/konsultasi/'.$id.'/chat/baca');

    $first->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.konsultasi_id', $id)
        ->assertJsonPath('data.jumlah_ditandai_baca', 1);

    $baca = KonsultasiBaca::query()
        ->where('konsultasi_id', $id)
        ->where('user_id', $s['user_pasien']->getKey())
        ->firstOrFail();

    expect($baca->last_read_at)->not->toBeNull();

    $pertama = $baca->last_read_at->copy();

    // The per-message stamp F08 does NOT replace: the doctor's line is read.
    expect(KonsultasiChat::query()->findOrFail($dariDokter)->dibaca_at)->not->toBeNull();

    // A second receipt is idempotent: one row for the pair, and the marker moves
    // forward rather than a duplicate row appearing (the `uq_baca` guarantee).
    $second = kbtAs($s['user_pasien'])->postJson('/api/v1/konsultasi/'.$id.'/chat/baca');

    $second->assertOk()->assertJsonPath('data.jumlah_ditandai_baca', 0);

    expect(KonsultasiBaca::query()
        ->where('konsultasi_id', $id)
        ->where('user_id', $s['user_pasien']->getKey())
        ->count())->toBe(1)
        ->and(KonsultasiBaca::query()
            ->where('konsultasi_id', $id)
            ->where('user_id', $s['user_pasien']->getKey())
            ->firstOrFail()
            ->last_read_at
            ->greaterThanOrEqualTo($pertama))->toBeTrue();

    // The other party has no marker until they read.
    expect(KonsultasiBaca::query()
        ->where('konsultasi_id', $id)
        ->where('user_id', $s['user_dokter']->getKey())
        ->exists())->toBeFalse();
});

// =====================================================================
// The `chat.dibaca` broadcast
// =====================================================================

test('the receipt dispatches chat.dibaca on the consultation channel with the stored payload', function (): void {
    Event::fake([KonsultasiChatDibaca::class]);

    $s = kbtSesiLengkap();
    $id = (int) $s['konsultasi']->getKey();

    kbtAs($s['user_pasien'])->postJson('/api/v1/konsultasi/'.$id.'/chat/baca')->assertOk();

    $stored = KonsultasiBaca::query()
        ->where('konsultasi_id', $id)
        ->where('user_id', $s['user_pasien']->getKey())
        ->firstOrFail();

    $iso = $stored->last_read_at->toISOString();

    // The payload is the STORED instant, so the socket and a following GET cannot
    // disagree by the microseconds `Carbon::now()` carries and MySQL rounds away.
    expect($iso)->toEndWith('Z');

    Event::assertDispatched(
        KonsultasiChatDibaca::class,
        fn (KonsultasiChatDibaca $event): bool => $event->konsultasiId === $id
            && $event->userId === $s['user_pasien']->getKey()
            && $event->lastReadAt === $iso
            && (string) $event->broadcastOn() === 'private-konsultasi.'.$id
            && $event->broadcastAs() === 'chat.dibaca'
            && $event->broadcastWith() === [
                'user_id' => $s['user_pasien']->getKey(),
                'last_read_at' => $iso,
            ],
    );
});

// =====================================================================
// Ownership
// =====================================================================

test('a non-party cannot stamp or read the marker, and the refusal writes nothing', function (): void {
    $s = kbtSesiLengkap();
    $id = (int) $s['konsultasi']->getKey();

    // A third patient and a third doctor: both hold `konsultasi.chat` and both
    // are strangers to this row, so both reach the ownership rule and get 404.
    $pasienLain = kbtPengguna('pasien', 'pasien');
    kbtPasien($pasienLain->getKey());

    $dokterLain = kbtPengguna('dokter', 'dokter');
    kbtDokter($dokterLain->getKey());

    foreach ([$pasienLain, $dokterLain] as $penyusup) {
        kbtAs($penyusup)->postJson('/api/v1/konsultasi/'.$id.'/chat/baca')->assertNotFound();
        kbtAs($penyusup)->getJson('/api/v1/konsultasi/'.$id)->assertNotFound();
    }

    expect(KonsultasiBaca::query()->where('konsultasi_id', $id)->count())->toBe(0);
});

// =====================================================================
// GET /konsultasi/{id} publishes both markers
// =====================================================================

test('the session response exposes both participants read state so the other party maps without a second call', function (): void {
    $s = kbtSesiLengkap();
    $id = (int) $s['konsultasi']->getKey();

    // Before any receipt: both ids resolve and both markers are null.
    kbtAs($s['user_dokter'])->getJson('/api/v1/konsultasi/'.$id)
        ->assertOk()
        ->assertJsonPath('data.konsultasi.baca.pasien_user_id', $s['user_pasien']->getKey())
        ->assertJsonPath('data.konsultasi.baca.dokter_user_id', $s['user_dokter']->getKey())
        ->assertJsonPath('data.konsultasi.baca.pasien_last_read_at', null)
        ->assertJsonPath('data.konsultasi.baca.dokter_last_read_at', null);

    kbtAs($s['user_pasien'])->postJson('/api/v1/konsultasi/'.$id.'/chat/baca')->assertOk();

    // The DOCTOR reads the session: the patient's marker is populated and the
    // doctor's is still null, which is the "other party" mapping the shape exists
    // for. The value is the STORED one, the same instant the event carried.
    $dokterView = kbtAs($s['user_dokter'])->getJson('/api/v1/konsultasi/'.$id);

    $dokterView->assertOk()
        ->assertJsonPath('data.konsultasi.baca.pasien_user_id', $s['user_pasien']->getKey())
        ->assertJsonPath('data.konsultasi.baca.dokter_user_id', $s['user_dokter']->getKey())
        ->assertJsonPath('data.konsultasi.baca.dokter_last_read_at', null);

    $stored = KonsultasiBaca::query()
        ->where('konsultasi_id', $id)
        ->where('user_id', $s['user_pasien']->getKey())
        ->firstOrFail()
        ->last_read_at
        ->toISOString();

    expect($dokterView->json('data.konsultasi.baca.pasien_last_read_at'))->toBe($stored);

    // And the mirror: the doctor stamps, the patient sees the doctor's marker.
    kbtAs($s['user_dokter'])->postJson('/api/v1/konsultasi/'.$id.'/chat/baca')->assertOk();

    $pasienView = kbtAs($s['user_pasien'])->getJson('/api/v1/konsultasi/'.$id);

    $pasienView->assertOk()
        ->assertJsonPath('data.konsultasi.baca.pasien_last_read_at', $stored);

    expect($pasienView->json('data.konsultasi.baca.dokter_last_read_at'))->toBe(
        KonsultasiBaca::query()
            ->where('konsultasi_id', $id)
            ->where('user_id', $s['user_dokter']->getKey())
            ->firstOrFail()
            ->last_read_at
            ->toISOString(),
    );
});

// =====================================================================
// No delete endpoint
// =====================================================================

test('there is no delete endpoint for a consultation or its messages', function (): void {
    // The owner's decision: medical messages must remain. The route table is the
    // authoritative surface, so the absence is asserted there rather than in a
    // client or a comment.
    $deletes = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/konsultasi')) {
            continue;
        }

        if (in_array('DELETE', $route->methods(), true)) {
            $deletes[] = 'DELETE '.$route->uri();
        }
    }

    expect($deletes)->toBe([]);

    // And no route name under the consultation prefix ends in `.destroy`.
    $destroy = [];

    foreach (Route::getRoutes() as $route) {
        $nama = (string) $route->getName();

        if (str_starts_with($nama, 'konsultasi.') && str_ends_with($nama, '.destroy')) {
            $destroy[] = $nama;
        }
    }

    expect($destroy)->toBe([]);
});
