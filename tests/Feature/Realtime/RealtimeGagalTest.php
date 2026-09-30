<?php

declare(strict_types=1);

use App\Events\KonsultasiMessageSent;
use App\Models\User;
use App\Support\Rbac\RoleAssigner;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| F-003 - the REST write survives a dead broadcaster
|--------------------------------------------------------------------------
|
| `KonsultasiMessageSent` is `ShouldBroadcastNow`, so every write that produces a
| chat row sends inline on the request thread (see the event's own docblock and
| `KonsultasiController::siarkan()`). When Reverb is unreachable the broadcaster
| throws, and before this file the throwable propagated out of `siarkan()` - the
| client received a 500 for a write whose database rows had ALREADY been
| committed, so a retry read as a 422 business refusal. That failure was found by
| live QA (`.omo/evidence/../docs/verification-report.md` section 6.1) and the
| suite could not see it: `phpunit.xml` pins `BROADCAST_CONNECTION=null`, so no
| test ever reaches a broadcaster that can fail.
|
| This file supplies the missing half deliberately: every test swaps the default
| broadcasting connection for a driver whose `broadcast()` throws, exactly the
| shape a running-but-unreachable Reverb has from the application's side. The
| assertion is not "no exception happened" but the stronger pair: the response is
| 2xx AND the row the write was about is in the database AND the failure is
| logged without the message body.
|
| `Log::spy()` is used rather than `Log::fake()` because the question is what the
| log received, and the PHI test asserts an ABSENCE - the chat body must never
| reach the log line that records the outage.
|
| The healthy path is not lost: `siaran tetap dikirim ketika broadcaster sehat`
| fakes the event and asserts it is still dispatched, so the `try` window cannot
| be widened into "silence every broadcast".
|
| Fixtures are local to this file (prefix `rtg`, for `realtime gagal`) and build
| rows with the NOT NULL columns the DDL requires, for the reason
| `BookingConcurrencyTest` gives: a helper shared with another file is a helper
| whose meaning can move under this one.
|
*/

// ------------------------------------------------------------------ helpers

/**
 * A `users` row of a given `tipe`, optionally carrying `$role`.
 *
 * `uuid`, `nama_lengkap`, `no_telepon` and `kata_sandi_hash` are the four NOT
 * NULL columns with no default (`telemedicine_test.sql:134`-`:138`).
 */
function rtgUser(string $tipe, string $nama, ?string $role = null): User
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
 * A `pasien` row. `jenis_kelamin`, `tanggal_lahir` and `alamat_lengkap` are NOT
 * NULL with no default.
 */
function rtgPasien(int $userId): int
{
    return (int) DB::table('pasien')->insertGetId([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Siaran No. 3, Jakarta',
    ]);
}

/**
 * A `dokter` row eligible for the directory: verified, active and flagged for
 * telemedicine, which is what `DokterDirectoryService::find()` gates on.
 */
function rtgDokter(int $userId): int
{
    return (int) DB::table('dokter')->insertGetId([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-RTG-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'biaya_konsultasi_online' => '175000.00',
        'tersedia_telemedisin' => 1,
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ]);
}

/**
 * A `konsultasi` row with the status each endpoint under test requires.
 * `booking_id` stays NULL - the instant "Tanya Dokter" shape - and `room_id` is a
 * real UUID because the column is NOT NULL.
 */
function rtgSesi(int $pasienId, int $dokterId, string $status, ?string $mulaiAt = null): int
{
    return (int) DB::table('konsultasi')->insertGetId([
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'tipe' => 'chat',
        'status' => $status,
        'room_id' => (string) Str::uuid(),
        'mulai_at' => $mulaiAt,
    ]);
}

/**
 * Act as `$user` with a real Sanctum bearer token.
 *
 * `forgetGuards()` first, for the reason `ConsultationChannelTest` and
 * `KonsultasiTest` both document: `RequestGuard` caches its principal, so the
 * first authenticated request inside a test would otherwise decide the caller
 * for every later one.
 */
function rtgAs(User $user): mixed
{
    app('auth')->forgetGuards();

    return test()->withToken(
        $user->createToken('realtime-gagal-test', ['*'], now()->addHour())->plainTextToken,
    );
}

/**
 * Replace the default broadcast connection with one whose `broadcast()` throws.
 *
 * Registered as a custom driver rather than by mocking the manager: the throw
 * happens at the same place a real Pusher HTTP failure happens - inside the
 * broadcaster's `broadcast()` - so the call stack through
 * `Dispatcher -> BroadcastFactory -> Broadcaster` is the production one.
 */
function rtgBroadcasterMelempar(): void
{
    config([
        'broadcasting.default' => 'rtg-melempar',
        'broadcasting.connections.rtg-melempar' => ['driver' => 'rtg-melempar'],
    ]);

    Broadcast::extend('rtg-melempar', fn ($app, array $config): Broadcaster => new class implements Broadcaster
    {
        public function auth($request): bool
        {
            return true;
        }

        public function validAuthenticationResponse($request, $result): bool
        {
            return true;
        }

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new RuntimeException('reverb tidak dapat dihubungi (uji F-003)');
        }
    });

    Broadcast::forgetDrivers();
}

/**
 * Assert the outage was recorded, naming the consultation, and carrying no chat
 * body. `json_encode` over the whole context is deliberate: it catches the body
 * wherever a future field might put it, not only under an `isi` key.
 */
function rtgWarningTerlempar(int $konsultasiId, string $tidakBoleh = ''): void
{
    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context = []) use ($konsultasiId, $tidakBoleh): bool {
            if ($message !== 'konsultasi.siaran_gagal') {
                return false;
            }

            if (($context['konsultasi_id'] ?? null) !== $konsultasiId) {
                return false;
            }

            return $tidakBoleh === '' || ! str_contains((string) json_encode($context), $tidakBoleh);
        });
}

// ------------------------------------------------------------------- setup

beforeEach(function (): void {
    // `RbacSeeder` writes `roles`, `permissions` and `role_permissions`, which
    // `RoleAssigner` and `EnsurePermission` resolve at request time. Without it
    // the three guarded routes answer 500 and the test would measure the seeder.
    $this->seed(RbacSeeder::class);

    rtgBroadcasterMelempar();
});

// ------------------------------------------------------------------- tests

test('mulai tetap 201 dan menyimpan sesi ketika broadcaster melempar', function (): void {
    Log::spy();

    $pasienUser = rtgUser('pasien', 'Pasien Siaran Gagal', 'pasien');
    $pasien = rtgPasien($pasienUser->getKey());
    $dokter = rtgDokter(rtgUser('dokter', 'Dokter Siaran Gagal', 'dokter')->getKey());

    $response = rtgAs($pasienUser)->postJson('/api/v1/konsultasi/mulai', [
        'dokter_id' => $dokter,
        'tipe' => 'chat',
    ]);

    $response->assertCreated();

    $konsultasiId = (int) $response->json('data.konsultasi.id');

    expect($konsultasiId)->toBeGreaterThan(0)
        ->and(DB::table('konsultasi')->where('id', $konsultasiId)->exists())->toBeTrue()
        ->and(DB::table('konsultasi')->where('id', $konsultasiId)->value('pasien_id'))->toBe($pasien);

    rtgWarningTerlempar($konsultasiId);
});

test('terima tetap 200 dan menstempel mulai_at ketika broadcaster melempar', function (): void {
    Log::spy();

    $pasien = rtgPasien(rtgUser('pasien', 'Pasien Siaran Gagal', 'pasien')->getKey());
    $dokterUser = rtgUser('dokter', 'Dokter Siaran Gagal', 'dokter');
    $dokter = rtgDokter($dokterUser->getKey());
    $sesi = rtgSesi($pasien, $dokter, 'menunggu_dokter');

    $response = rtgAs($dokterUser)->putJson('/api/v1/konsultasi/'.$sesi.'/terima', []);

    $response->assertOk();

    $row = DB::table('konsultasi')->where('id', $sesi)->first();

    expect($row->status)->toBe('berlangsung')
        ->and($row->mulai_at)->not->toBeNull();

    rtgWarningTerlempar($sesi);
});

test('selesai tetap 200 dan mengisi durasi ketika broadcaster melempar', function (): void {
    Log::spy();

    $pasien = rtgPasien(rtgUser('pasien', 'Pasien Siaran Gagal', 'pasien')->getKey());
    $dokterUser = rtgUser('dokter', 'Dokter Siaran Gagal', 'dokter');
    $dokter = rtgDokter($dokterUser->getKey());
    $sesi = rtgSesi($pasien, $dokter, 'berlangsung', now()->subMinutes(30)->format('Y-m-d H:i:s'));

    $response = rtgAs($dokterUser)->putJson('/api/v1/konsultasi/'.$sesi.'/selesai', [
        'catatan_subjektif' => 'Pasien melaporkan demam ringan sejak kemarin.',
    ]);

    $response->assertOk();

    $row = DB::table('konsultasi')->where('id', $sesi)->first();

    expect($row->status)->toBe('selesai')
        ->and($row->selesai_at)->not->toBeNull()
        ->and((int) $row->total_durasi_detik)->toBeGreaterThan(0);

    rtgWarningTerlempar($sesi);
});

test('chatStore tetap 201 dan menyimpan pesan ketika broadcaster melempar', function (): void {
    Log::spy();

    $pasienUser = rtgUser('pasien', 'Pasien Siaran Gagal', 'pasien');
    $pasien = rtgPasien($pasienUser->getKey());
    $dokter = rtgDokter(rtgUser('dokter', 'Dokter Siaran Gagal', 'dokter')->getKey());
    $sesi = rtgSesi($pasien, $dokter, 'berlangsung', now()->subMinutes(5)->format('Y-m-d H:i:s'));

    $response = rtgAs($pasienUser)->postJson('/api/v1/konsultasi/'.$sesi.'/chat', [
        'tipe_pesan' => 'teks',
        'isi' => 'Halo dokter, saya demam.',
    ]);

    $response->assertCreated();

    expect(DB::table('konsultasi_chat')
        ->where('konsultasi_id', $sesi)
        ->where('isi', 'Halo dokter, saya demam.')
        ->exists())->toBeTrue();

    rtgWarningTerlempar($sesi);
});

test('log peringatan siaran tidak memuat isi pesan (tanpa PHI)', function (): void {
    Log::spy();

    $pasienUser = rtgUser('pasien', 'Pasien Siaran Gagal', 'pasien');
    $pasien = rtgPasien($pasienUser->getKey());
    $dokter = rtgDokter(rtgUser('dokter', 'Dokter Siaran Gagal', 'dokter')->getKey());
    $sesi = rtgSesi($pasien, $dokter, 'berlangsung', now()->subMinutes(5)->format('Y-m-d H:i:s'));

    $phie = 'RAHASIA-PHI-9f3a';

    rtgAs($pasienUser)->postJson('/api/v1/konsultasi/'.$sesi.'/chat', [
        'tipe_pesan' => 'teks',
        'isi' => $phie,
    ])->assertCreated();

    // The consultation id is in the line; the body is not, anywhere in it.
    rtgWarningTerlempar($sesi, $phie);
});

test('siaran tetap dikirim ketika broadcaster sehat', function (): void {
    Event::fake([KonsultasiMessageSent::class]);

    $pasienUser = rtgUser('pasien', 'Pasien Siaran Sehat', 'pasien');
    $pasien = rtgPasien($pasienUser->getKey());
    $dokter = rtgDokter(rtgUser('dokter', 'Dokter Siaran Sehat', 'dokter')->getKey());
    $sesi = rtgSesi($pasien, $dokter, 'berlangsung', now()->subMinutes(5)->format('Y-m-d H:i:s'));

    rtgAs($pasienUser)->postJson('/api/v1/konsultasi/'.$sesi.'/chat', [
        'tipe_pesan' => 'teks',
        'isi' => 'Halo dokter.',
    ])->assertCreated();

    // The try/catch must swallow a failure, not the send itself.
    Event::assertDispatched(KonsultasiMessageSent::class);
});
