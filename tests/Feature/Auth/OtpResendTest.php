<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserOtp;
use App\Services\Auth\OtpSender;
use App\Services\Auth\OtpService;
use Database\Seeders\RbacSeeder;
use Tests\Support\FakeOtpSender;

/*
|--------------------------------------------------------------------------
| F01 - POST /api/v1/auth/otp/resend, and the attempt counter
|--------------------------------------------------------------------------
|
| Two owner decisions meet in this file:
|
| - §4.4 #1: a password-free resend endpoint that closes the previous code and
|   answers generically, so it cannot enumerate accounts.
| - §12 #6: `meta.sisa_percobaan` on an OTP-verify failure and `retry_after` on
|   the 429, and NOWHERE else - login and account lookup stay generic.
|
| The real routes are driven, the OTP is observed through the bound
| `FakeOtpSender`, and `freezeTime()` is used for the enumeration assertion so
| the two timestamps are byte-identical rather than merely close.
|
*/

// ------------------------------------------------------------------ helpers

/**
 * A complete register payload, consent included (F01 decision #1).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ortRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'nama_lengkap' => 'Siti Rahayu',
        'no_telepon' => ortPhone(),
        'email' => 'siti.rahayu@example.test',
        'password' => ortPassword(),
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1992-08-09',
        'tempat_lahir' => 'Surabaya',
        'alamat_lengkap' => 'Jl. Tunjungan No. 10, Surabaya, Jawa Timur 60275',
        'bahasa' => 'id',
        'persetujuan_syarat_ketentuan' => true,
        'persetujuan_kebijakan_privasi' => true,
    ], $overrides);
}

function ortPhone(): string
{
    return '081377776666';
}

function ortPassword(): string
{
    return 'kata-sandi-resend-456';
}

function ortSender(): FakeOtpSender
{
    $sender = app(OtpSender::class);

    expect($sender)->toBeInstanceOf(FakeOtpSender::class);

    return $sender;
}

/**
 * Register `ortPhone()` through the real endpoint and return the `users` row.
 */
function ortRegisteredUser(): User
{
    test()->postJson('/api/v1/auth/register', ortRegisterPayload())->assertCreated();

    return User::query()->where('no_telepon', ortPhone())->firstOrFail();
}

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->app->instance(OtpSender::class, new FakeOtpSender);
});

// =====================================================================
// Resend
// =====================================================================

test('resend issues a new code, closes the previous one, and reports the channel', function (): void {
    $user = ortRegisteredUser();

    $oldKode = (string) ortSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON);

    $response = $this->postJson('/api/v1/auth/otp/resend', [
        'no_telepon' => ortPhone(),
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ]);

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $response->assertJsonStructure([
        'success',
        'data' => ['otp' => ['kedaluwarsa_at', 'ttl_detik', 'kanal']],
        'message',
    ]);
    $response->assertJsonPath('data.otp.ttl_detik', OtpService::TTL_MENIT * 60);
    // `phpunit.xml` leaves OTP_DRIVER at its `log` default, so the reported
    // channel is the one the bound sender actually delivers over. The WhatsApp
    // mapping is asserted in `FonnteOtpSenderTest`.
    $response->assertJsonPath('data.otp.kanal', 'log');

    $newKode = (string) ortSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON);

    expect($newKode)->toMatch('/^[0-9]{6}$/')
        ->and($newKode)->not->toBe($oldKode)
        // Supersession, not accumulation: exactly one live code for the purpose.
        ->and(app(OtpService::class)->liveCodeCount($user, OtpService::TUJUAN_VERIFIKASI_TELEPON))->toBe(1);

    // The old code is refused through the ordinary expiry branch, and the new
    // one verifies. This is the property a "resend" exists to provide.
    $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => ortPhone(),
        'kode' => $oldKode,
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ])->assertStatus(422)->assertJsonPath('errors.kode.0', 'Kode OTP sudah kedaluwarsa. Silakan minta kode baru.');

    $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => ortPhone(),
        'kode' => $newKode,
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ])->assertOk();
});

test('the channel read reports the same channel resend reports, and needs no credential', function (): void {
    // A sign-in dialog labels its send button from this BEFORE it has asked for a
    // number, so it must answer unauthenticated and with no body - and it must never
    // disagree with what `otp/resend` reports afterwards, or the button would have
    // promised one transport while the code went over another. Both sides read
    // `PemilihPengirimOtp::kanalAktif()`, which is what makes that structural rather
    // than a coincidence this test merely observes.
    $response = $this->getJson('/api/v1/auth/otp/kanal');

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $response->assertJsonStructure(['success', 'data' => ['kanal'], 'message']);

    // `phpunit.xml` leaves OTP_DRIVER at its `log` default, so this is `log`; the
    // `fonnte` => `whatsapp` mapping is asserted in `FonnteOtpSenderTest`.
    $response->assertJsonPath('data.kanal', 'log');

    // Agreement must hold for an account nobody has ever registered, because resend
    // answers unknown identifiers with the same envelope and the same channel.
    $this->postJson('/api/v1/auth/otp/resend', [
        'no_telepon' => ortPhone(),
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ])->assertOk()->assertJsonPath('data.otp.kanal', $response->json('data.kanal'));
});

test('the channel read follows the configured driver, naming whatsapp once fonnte is selected', function (): void {
    // The half the test above cannot cover: that the answer MOVES when the deployment
    // does, which is the entire reason the read exists rather than a constant in the
    // client. `PenjagaPengirimanProduksi` returns early in `local`, so the driver can
    // point at `fonnte` without a token - and `PemilihPengirimOtp::kanal()` maps it by
    // driver alone, so the response has to say `whatsapp` here.
    //
    // Without this, a client asserting "show the WhatsApp button" would have no test
    // proving anything ever sets that value.
    config(['otp.driver' => 'fonnte']);

    $this->getJson('/api/v1/auth/otp/kanal')
        ->assertOk()
        ->assertJsonPath('data.kanal', 'whatsapp');
});

test('resend answers the same body for a registered and an unknown account', function (): void {
    // Frozen so `kedaluwarsa_at` is byte-identical, not merely close. Without
    // this the assertion would compare two different instants and fail for a
    // reason that has nothing to do with account enumeration.
    $this->freezeTime();

    ortRegisteredUser();

    $known = $this->postJson('/api/v1/auth/otp/resend', [
        'no_telepon' => ortPhone(),
        'tujuan' => OtpService::TUJUAN_LOGIN,
    ]);

    $unknown = $this->postJson('/api/v1/auth/otp/resend', [
        'no_telepon' => '089999999999',
        'tujuan' => OtpService::TUJUAN_LOGIN,
    ]);

    $known->assertOk();
    $unknown->assertOk();

    // Byte-identical: no status, no message and no timestamp distinguishes them.
    expect($unknown->getContent())->toBe($known->getContent());

    // Only the registered account actually received a code. The unknown branch
    // writes no `user_otp` row and sends nothing.
    expect(ortSender()->countFor(OtpService::TUJUAN_LOGIN))->toBe(1)
        ->and(UserOtp::query()->where('tujuan', OtpService::TUJUAN_LOGIN)->count())->toBe(1);
});

test('resend refuses a suspended account with the same generic body and sends nothing', function (): void {
    $this->freezeTime();

    $user = ortRegisteredUser();
    $user->status = 'ditangguhkan';
    $user->save();

    $refused = $this->postJson('/api/v1/auth/otp/resend', [
        'no_telepon' => ortPhone(),
        'tujuan' => OtpService::TUJUAN_LOGIN,
    ]);

    $refused->assertOk();

    expect(ortSender()->countFor(OtpService::TUJUAN_LOGIN))->toBe(0);
});

test('resend is rate limited to three per five minutes, with retry_after in the envelope', function (): void {
    ortRegisteredUser();

    $body = ['no_telepon' => ortPhone(), 'tujuan' => OtpService::TUJUAN_LOGIN];

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->postJson('/api/v1/auth/otp/resend', $body)->assertOk();
    }

    $refused = $this->postJson('/api/v1/auth/otp/resend', $body);

    $refused->assertStatus(429);
    $refused->assertJsonPath('success', false);
    expect($refused->json('errors'))->toBe([]);

    // Both channels carry the wait: the header a browser can read without CORS
    // exposure, and the envelope key a non-browser client reads.
    $header = $refused->headers->get('Retry-After');

    expect($header)->not->toBeNull()
        ->and((int) $header)->toBeGreaterThan(0)
        ->and((int) $header)->toBeLessThanOrEqual(300);

    $meta = $refused->json('meta');

    expect($meta)->toBeArray()
        ->and($meta['retry_after'] ?? null)->toBeInt()
        ->and($meta['retry_after'])->toBeGreaterThan(0);

    // A resend 429 carries no attempt counter: `sisa_percobaan` is an OTP-verify
    // concept, and publishing it here would be a second shape for the same key.
    expect($meta)->not->toHaveKey('sisa_percobaan');

    // And the budget is per account + address: a different account is a fresh
    // bucket, so one caller cannot lock every patient out of resend.
    $this->postJson('/api/v1/auth/otp/resend', [
        'no_telepon' => '081300000001',
        'tujuan' => OtpService::TUJUAN_LOGIN,
    ])->assertOk();
});

test('resend requires a tujuan and at least one identifier', function (): void {
    $neither = $this->postJson('/api/v1/auth/otp/resend', []);

    $neither->assertStatus(422);
    expect($neither->json('errors'))->toHaveKeys(['tujuan', 'no_telepon', 'email'])
        ->and($neither->json('errors.no_telepon.0'))->toBe('Isi no_telepon atau email.');

    $this->postJson('/api/v1/auth/otp/resend', ['no_telepon' => ortPhone()])
        ->assertStatus(422)
        ->assertJsonPath('errors.tujuan.0', 'The tujuan OTP field is required.');

    // `reset_kata_sandi` is in the DDL enum but this endpoint does not mint it.
    $this->postJson('/api/v1/auth/otp/resend', [
        'no_telepon' => ortPhone(),
        'tujuan' => OtpService::TUJUAN_RESET_KATA_SANDI,
    ])->assertStatus(422)->assertJsonPath('errors.tujuan.0', 'The selected tujuan OTP is invalid.');
});

// =====================================================================
// Attempts and retry_after on verify
// =====================================================================

test('each verify failure exposes the remaining attempts, and the lockout exposes retry_after', function (): void {
    ortRegisteredUser();

    $attempt = fn (): mixed => $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => ortPhone(),
        'kode' => '999999',
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ]);

    // Attempts 1..4 answer 422 with 4, 3, 2, 1 guesses left.
    for ($n = 1; $n <= 4; $n++) {
        $response = $attempt();

        $response->assertStatus(422);
        $response->assertJsonPath('errors.kode.0', 'Kode OTP tidak valid.');
        $response->assertJsonPath('meta.sisa_percobaan', 5 - $n);
    }

    // The fifth is the last one the limiter allows: 0 left.
    $attempt()->assertStatus(422)->assertJsonPath('meta.sisa_percobaan', 0);

    // The sixth never reaches the controller.
    $refused = $attempt();

    $refused->assertStatus(429);
    $refused->assertJsonPath('meta.sisa_percobaan', 0);

    $header = $refused->headers->get('Retry-After');

    expect($header)->not->toBeNull()
        ->and((int) $header)->toBeGreaterThan(0)
        ->and((int) $header)->toBeLessThanOrEqual(OtpService::TTL_MENIT * 60);

    $meta = $refused->json('meta');

    expect($meta)->toBeArray()
        ->and($meta['retry_after'] ?? null)->toBeInt()
        ->and($meta['retry_after'])->toBeGreaterThan(0);
});

test('login and account lookup never expose the attempt counter', function (): void {
    ortRegisteredUser();

    // A correct-password login: 200, and no `meta` key at all.
    $login = $this->postJson('/api/v1/auth/login', [
        'no_telepon' => ortPhone(),
        'password' => ortPassword(),
    ]);

    $login->assertOk();
    expect($login->json('meta'))->toBeNull()
        ->and($login->getContent())->not->toContain('sisa_percobaan');

    // A wrong password: the generic 401, no counter.
    $wrong = $this->postJson('/api/v1/auth/login', [
        'no_telepon' => ortPhone(),
        'password' => 'kata-sandi-yang-salah',
    ]);

    $wrong->assertStatus(401);
    expect($wrong->json('meta'))->toBeNull()
        ->and($wrong->getContent())->not->toContain('sisa_percobaan');

    // A duplicate registration: the 422 names the field and nothing else.
    $duplicate = $this->postJson('/api/v1/auth/register', ortRegisterPayload([
        'email' => 'orang.lain@example.test',
    ]));

    $duplicate->assertStatus(422);
    expect($duplicate->json('meta'))->toBeNull()
        ->and($duplicate->getContent())->not->toContain('sisa_percobaan');
});

test('a new code resets the attempt budget', function (): void {
    $user = ortRegisteredUser();

    $wrong = fn (): mixed => $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => ortPhone(),
        'kode' => '999999',
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ]);

    // Spend four of the five attempts.
    for ($n = 1; $n <= 4; $n++) {
        $wrong()->assertStatus(422);
    }

    // Resend mints a new row, so the limiter's key (the code's own id) moves and
    // the budget starts over. The patient is never permanently locked out.
    $this->postJson('/api/v1/auth/otp/resend', [
        'no_telepon' => ortPhone(),
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ])->assertOk();

    $kode = (string) ortSender()->lastKodeFor(OtpService::TUJUAN_VERIFIKASI_TELEPON);

    $this->postJson('/api/v1/auth/otp/verify', [
        'no_telepon' => ortPhone(),
        'kode' => $kode,
        'tujuan' => OtpService::TUJUAN_VERIFIKASI_TELEPON,
    ])->assertOk();

    expect(app(OtpService::class)->liveCodeCount($user, OtpService::TUJUAN_VERIFIKASI_TELEPON))->toBe(0);
});
