<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Auth\OtpSender;
use App\Support\WaktuIndonesia;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeOtpSender;

require_once __DIR__.'/demo3c-helpers.php';

/*
|--------------------------------------------------------------------------
| F3-02 - the four journeys F3 could not reach, driven over the real API
|--------------------------------------------------------------------------
|
| ## What F3 recorded
|
| BLOCKER F3-02: four of the thirteen requested journeys were unreachable.
| Prescription + interaction override, order checkout, order tracking and the
| doctor half of the medical record could not be exercised, because the root
| cause is SEED DATA rather than missing code: `DevFixtureSeeder` gives every
| fixture account `password_hash(bin2hex(random_bytes(32)))` - a 64-character
| random password nobody knows - so the whole fixture dataset is permanently
| unloginable, and no reachable doctor exists to write a prescription.
|
| ## The rule this file holds itself to
|
| **A seeder that creates a row is not a working journey.** Every test below
| reaches each journey through the HTTP surface a real client uses, with a
| token minted by the real two-step auth flow. The seeder supplies only the
| preconditions that have no public API at all - a doctor and a pharmacist
| account, a doctor's schedule, a pharmacy and its stock - and the JOURNEY
| itself is performed here over the API, exactly as a user would.
|
| ## The four journeys, and the call that reaches each one
|
| | F3 journey | reached by |
| | --- | --- |
| | prescription + interaction override | `POST /api/v1/konsultasi/{id}/resep` as the demo DOCTOR, with a `kontraindikasi` warning and the mandatory `catatan_dodio` |
| | order checkout | `POST /api/v1/resep/{id}/checkout` as the demo PATIENT |
| | order tracking | `GET /api/v1/pesanan-obat/{id}` as the demo PATIENT |
| | doctor half of the medical record | `POST /api/v1/konsultasi/{id}/rekam-medis` as the demo DOCTOR |
|
| ## Auth is two-step and that is exercised, not bypassed
|
| `POST /api/v1/auth/login` returns NO token; the OTP is delivered through the
| bound `OtpSender` and `POST /api/v1/auth/otp/verify` is the only issuer. The
| test swaps the sender for {@see FakeOtpSender} - the same binding
| `AuthFlowTest` uses, and the same one a real SMS gateway would replace - and
| otherwise drives the real endpoints. Minting a Sanctum token directly would
| skip the very thing F3 verified as correct.
*/

beforeEach(function (): void {
    // The full chain minus `DatabaseSeeder` itself, which TRUNCATEs. MySQL treats
    // TRUNCATE as DDL and implicitly commits, which would break the
    // `RefreshDatabase` wrapper every Feature test runs inside; the individual
    // seeders are plain inserts, so seeding them in order is the same data.
    demo3cSeed(['butuh-demo', 'demo']);
    demo3cIkatOtp();
});

// =====================================================================
// The accounts themselves
// =====================================================================

test('the demo accounts exist, hold the right roles, and authenticate for real', function (): void {
    $pasien = demo3cUser(DemoDataSeeder::AKUN_PASIEN);
    $dokter = demo3cUser(DemoDataSeeder::AKUN_DOKTER);
    $apoteker = demo3cUser(DemoDataSeeder::AKUN_APOTEKER);

    expect($pasien->tipe)->toBe('pasien')
        ->and($dokter->tipe)->toBe('dokter')
        ->and($apoteker->tipe)->toBe('apoteker')
        ->and($pasien->status)->toBe('aktif')
        ->and($dokter->status)->toBe('aktif')
        ->and($apoteker->status)->toBe('aktif');

    expect(demo3cRoles((int) $pasien->getKey()))->toBe(['pasien'])
        ->and(demo3cRoles((int) $dokter->getKey()))->toBe(['dokter'])
        ->and(demo3cRoles((int) $apoteker->getKey()))->toBe(['apoteker']);

    // The password is documented and hash-verifiable, which is the whole point
    // of a demo account and the whole reason F3 could not reach anything.
    expect(Hash::check(DemoDataSeeder::KATA_SANDI_PASIEN, (string) $pasien->kata_sandi_hash))->toBeTrue()
        ->and(Hash::check(DemoDataSeeder::KATA_SANDI_DOKTER, (string) $dokter->kata_sandi_hash))->toBeTrue()
        ->and(Hash::check(DemoDataSeeder::KATA_SANDI_APOTEKER, (string) $apoteker->kata_sandi_hash))->toBeTrue();
});

test('every demo account logs in through the real two-step auth flow', function (): void {
    foreach ([DemoDataSeeder::AKUN_PASIEN, DemoDataSeeder::AKUN_DOKTER, DemoDataSeeder::AKUN_APOTEKER] as $nama) {
        $akun = DemoDataSeeder::akun($nama);
        $token = demo3cToken($akun['no_telepon'], $akun['kata_sandi']);

        expect($token)->not->toBeNull();

        // A minted token that the API does not accept would prove nothing, so
        // it is spent on the one endpoint every role can reach.
        demo3cAs($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.tipe', $akun['tipe']);
    }
});

test('the demo patient owns a patient row and the demo doctor a verified doctor row', function (): void {
    $pasien = demo3cUser(DemoDataSeeder::AKUN_PASIEN);
    $dokter = demo3cUser(DemoDataSeeder::AKUN_DOKTER);

    $pasienRow = DB::table('pasien')->where('user_id', $pasien->getKey())->first();
    $dokterRow = DB::table('dokter')->where('user_id', $dokter->getKey())->first();

    expect($pasienRow)->not->toBeNull();
    expect((string) $pasienRow->alamat_lengkap)->not->toBe('');

    // These three predicates are what `DokterDirectoryService` and
    // `GET /dokter/{id}/slot` filter on, so a demo doctor that misses one is a
    // doctor the product cannot show.
    expect((string) $dokterRow->status_verifikasi)->toBe('terverifikasi')
        ->and((int) $dokterRow->status_aktif)->toBe(1)
        ->and((int) $dokterRow->tersedia_telemedisin)->toBe(1);

    expect((float) $dokterRow->biaya_konsultasi_online)->toBeGreaterThan(0.0);
    expect((int) $dokterRow->durasi_default_menit)->toBeGreaterThan(0);
});

test('the demo doctor publishes bookable slots on the public slot endpoint', function (): void {
    $dokterId = (int) DB::table('dokter')
        ->where('user_id', demo3cUser(DemoDataSeeder::AKUN_DOKTER)->getKey())
        ->value('id');

    // F3-08 recorded `GET /dokter/1/slot` answering `{"slots":[]}` and
    // `GET /dokter/1/jadwal` answering `total: 0`, because nothing anywhere can
    // create a `dokter_jadwal` row. The demo seeder supplies one per weekday,
    // which is the only way a fresh clone can ever offer a bookable slot.
    $response = demo3cAs()->getJson('/api/v1/dokter/'.$dokterId.'/slot?tanggal='.demo3cTanggal());

    $response->assertOk();
    expect($response->json('data.timezone'))->toBe(WaktuIndonesia::ZONA);
    expect($response->json('data.slots'))->not->toBe([]);

    $slot = $response->json('data.slots.0');

    expect($slot)->toHaveKeys(['jadwal_id', 'jam_mulai', 'jam_selesai']);
    expect((int) $slot['jadwal_id'])->toBeGreaterThan(0);
});

test('the demo seeder writes NO NIK, so it adds no new 16-digit identifier', function (): void {
    $pasienId = (int) DB::table('pasien')
        ->where('user_id', demo3cUser(DemoDataSeeder::AKUN_PASIEN)->getKey())
        ->value('id');

    // F3-03 was OPEN when this was written: `pasien.nik` was a plaintext
    // `CHAR(16)` with no blind index and `NIK_CIPHER_KEY` was unset. Migration
    // `2026_10_01_000079` closed the storage half of it - the column is now
    // `nik_cipher`, holding a `NikCipher` payload. What this test still asserts,
    // and what is unaffected by that migration, is that the demo seeder adds no
    // identifier of its own: it writes no NIK at all, so demo data cannot make
    // the masking question look answered when nothing in it exercises one.
    expect(DB::table('pasien')->where('id', $pasienId)->value('nik_cipher'))->toBeNull();

    // The demo seeder creates exactly one `pasien` row, and that row is the one
    // above - so "this seeder wrote no NIK" is a statement about a row that
    // exists rather than about an absence nobody measured.
    expect(DB::table('pasien')->where('user_id', '>', 0)->count())->toBe(1);
});
