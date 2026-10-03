<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Auth\OtpSender;
use App\Services\Auth\OtpService;
use App\Support\Telepon;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeOtpSender;

/*
|--------------------------------------------------------------------------
| F01 P0 - one phone number, one account, whichever spelling arrives
|--------------------------------------------------------------------------
|
| Before this fix `0812…` and `+62812…` were two accounts: the `unique` rule and
| every lookup matched the submitted string exactly (`AuthRequest.php:63-66`,
| `AuthController.php:533-535`). These tests drive the real endpoints with both
| spellings and assert the canonical local form is what is stored, what is
| looked up, what the rate limiter counts and what the backfill command writes.
|
| The canonical form is the LOCAL `08…` because that is what existing rows and
| fixtures already use; `App\Support\Telepon` carries the full argument.
|
*/

// ------------------------------------------------------------------ helpers

function pntPhone(): string
{
    return '081355554444';
}

function pntPayload(array $overrides = []): array
{
    return array_merge([
        'nama_lengkap' => 'Andi Wijaya',
        'no_telepon' => pntPhone(),
        'email' => 'andi.wijaya@example.test',
        'password' => 'kata-sandi-normalisasi-789',
        'jenis_kelamin' => 'L',
        'tanggal_lahir' => '1988-03-21',
        'tempat_lahir' => 'Medan',
        'alamat_lengkap' => 'Jl. Gatot Subroto No. 5, Medan, Sumatera Utara 20112',
        'bahasa' => 'id',
        'persetujuan_syarat_ketentuan' => true,
        'persetujuan_kebijakan_privasi' => true,
    ], $overrides);
}

function pntSender(): FakeOtpSender
{
    $sender = app(OtpSender::class);

    expect($sender)->toBeInstanceOf(FakeOtpSender::class);

    return $sender;
}

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->app->instance(OtpSender::class, new FakeOtpSender);
});

// =====================================================================
// The pure rule
// =====================================================================

test('the normaliser folds both country-code spellings into the local form and is idempotent', function (): void {
    expect(Telepon::normalisasi('+6281234567890'))->toBe('081234567890')
        ->and(Telepon::normalisasi('6281234567890'))->toBe('081234567890')
        ->and(Telepon::normalisasi('081234567890'))->toBe('081234567890')
        // Idempotent, so a second pass (request then controller) is free.
        ->and(Telepon::normalisasi(Telepon::normalisasi('+6281234567890')))->toBe('081234567890')
        // Not a valid Indonesian form: left alone rather than turned into 008...
        ->and(Telepon::normalisasi('+6208123'))->toBe('+6208123')
        // Null and empty pass through so a nullable field cannot become '0'.
        ->and(Telepon::normalisasi(null))->toBeNull()
        ->and(Telepon::normalisasi(''))->toBe('')
        ->and(Telepon::keInternasional('081234567890'))->toBe('+6281234567890');
});

// =====================================================================
// Register, login and verify
// =====================================================================

test('register stores the canonical local form when the client sends +62', function (): void {
    $response = $this->postJson('/api/v1/auth/sign-up', pntPayload([
        'no_telepon' => '+62'.substr(pntPhone(), 1),
    ]));

    $response->assertCreated();

    // The response and the row agree, and both are canonical.
    $response->assertJsonPath('data.user.no_telepon', pntPhone());

    expect(User::query()->where('no_telepon', pntPhone())->exists())->toBeTrue()
        ->and(User::query()->where('no_telepon', '+62'.substr(pntPhone(), 1))->exists())->toBeFalse();
});

test('login and verify accept either spelling of the same account', function (): void {
    // Registered with the local form...
    $this->postJson('/api/v1/auth/sign-up', pntPayload())->assertCreated();

    // ...logged in with the international form...
    $this->postJson('/api/v1/auth/login', [
        'no_telepon' => '+62'.substr(pntPhone(), 1),
        'password' => 'kata-sandi-normalisasi-789',
    ])->assertOk()->assertJsonPath('data.otp.tujuan', OtpService::TUJUAN_LOGIN);

    // ...and verified with the country code WITHOUT the plus.
    $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => '62'.substr(pntPhone(), 1),
        'kode' => pntSender()->lastKodeFor(OtpService::TUJUAN_LOGIN),
        'tujuan' => OtpService::TUJUAN_LOGIN,
    ])->assertOk();
});

test('a duplicate is refused across spellings, as a field error and not a 500', function (): void {
    $this->postJson('/api/v1/auth/sign-up', pntPayload())->assertCreated();

    $second = $this->postJson('/api/v1/auth/sign-up', pntPayload([
        'no_telepon' => '+62'.substr(pntPhone(), 1),
        'email' => 'orang.lain@example.test',
    ]));

    $second->assertStatus(422);
    expect($second->json('errors'))->toHaveKey('no_telepon');

    expect(User::query()->count())->toBe(1);
});

test('the login limiter counts both spellings against one budget', function (): void {
    // Register and verify so the account exists and the password is known.
    $this->postJson('/api/v1/auth/sign-up', pntPayload())->assertCreated();

    $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => pntPhone(),
        'kode' => pntSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON),
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ])->assertOk();

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'no_telepon' => pntPhone(),
            'password' => 'kata-sandi-yang-salah',
        ])->assertStatus(401);
    }

    // The sixth attempt spells the SAME number the other way. If the middleware
    // did not normalise before keying, this would be a fresh bucket and answer
    // 401 instead of 429.
    $this->postJson('/api/v1/auth/login', [
        'no_telepon' => '+62'.substr(pntPhone(), 1),
        'password' => 'kata-sandi-yang-salah',
    ])->assertStatus(429);
});

// =====================================================================
// The one-shot backfill
// =====================================================================

test('the backfill command folds legacy rows and leaves an idempotent no-op', function (): void {
    $legacyPlus = User::factory()->create(['no_telepon' => '+628111112222']);
    $legacyBare = User::factory()->create(['no_telepon' => '628111113333']);
    $canonical = User::factory()->create(['no_telepon' => '081111114444']);

    $this->artisan('sehatly:normalisasi-telepon')->assertSuccessful();

    expect((string) $legacyPlus->fresh()->no_telepon)->toBe('08111112222')
        ->and((string) $legacyBare->fresh()->no_telepon)->toBe('08111113333')
        ->and((string) $canonical->fresh()->no_telepon)->toBe('081111114444');

    // Second run changes nothing.
    $this->artisan('sehatly:normalisasi-telepon')
        ->expectsOutputToContain('0 diubah')
        ->assertSuccessful();
});

test('the backfill command refuses to create a duplicate and names the collision', function (): void {
    User::factory()->create(['no_telepon' => '081222223333']);
    $duplicate = User::factory()->create(['no_telepon' => '+6281222223333']);

    $this->artisan('sehatly:normalisasi-telepon')
        ->expectsOutputToContain('Tabrakan')
        ->assertSuccessful();

    // The row is left exactly as it was: two accounts for one number is a human
    // decision, not something a sweep may resolve by picking a winner.
    expect((string) $duplicate->fresh()->no_telepon)->toBe('+6281222223333')
        ->and(DB::table('users')->where('no_telepon', '081222223333')->count())->toBe(1);
});

test('the backfill dry-run reports without writing', function (): void {
    $legacy = User::factory()->create(['no_telepon' => '+628133334444']);

    $this->artisan('sehatly:normalisasi-telepon', ['--dry-run' => true])
        ->expectsOutputToContain('Dry-run')
        ->assertSuccessful();

    expect((string) $legacy->fresh()->no_telepon)->toBe('+628133334444');
});
