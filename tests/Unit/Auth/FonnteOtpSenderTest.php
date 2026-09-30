<?php

declare(strict_types=1);

use App\Services\Auth\FonnteOtpSender;
use App\Services\Auth\LogOtpSender;
use App\Services\Auth\PemilihPengirimOtp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| F-005 - the Fonnte OTP sender logs failures and never leaks the code
|--------------------------------------------------------------------------
|
| The sender sits behind `OtpSender`, whose contract says a recoverable delivery
| failure is LOGGED, not thrown: an OTP that was minted but not delivered stays a
| live `user_otp` row and the account holder can request a fresh one. These tests
| drive the three failure shapes - a provider 5xx, a transport fault, a missing
| token - and assert both halves of that contract: no throw, and no code in the log.
|
| `Http::fake` is used throughout; no request leaves the process, and there is no
| Fonnte account behind these tests.
|
*/

test('mengirim OTP ke Fonnte dengan token mentah di header dan kode di pesan', function (): void {
    config([
        'otp.fonnte.token' => 'TOKEN-RAHASIA',
        'otp.fonnte.url' => 'https://api.fonnte.test/send',
        'otp.fonnte.timeout' => 5,
    ]);

    Http::fake(['api.fonnte.test/*' => Http::response(['status' => true], 200)]);

    app(FonnteOtpSender::class)->send('081298765432', '123456', 'login');

    Http::assertSent(function ($request): bool {
        // Fonnte expects the token RAW in `Authorization`, not as `Bearer <token>`,
        // which is what `Http::withToken()` would send.
        return $request->url() === 'https://api.fonnte.test/send'
            && $request->hasHeader('Authorization', 'TOKEN-RAHASIA')
            && $request['target'] === '081298765432'
            && str_contains((string) $request['message'], '123456');
    });
});

test('provider 5xx hanya menulis warning, tanpa kode dan tanpa exception', function (): void {
    config(['otp.fonnte.token' => 'TOKEN-RAHASIA']);

    Log::spy();

    // The provider echoes the submitted body, code included - which is exactly why
    // the sender must log the status and not the body.
    Http::fake(['*' => Http::response('gagal: KODE-9f3a tidak terkirim', 500)]);

    app(FonnteOtpSender::class)->send('081298765432', 'KODE-9f3a', 'login');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'otp.fonnte.gagal'
            && ! str_contains((string) json_encode($context), 'KODE-9f3a')
            && ($context['status'] ?? null) === 500);
});

test('timeout transport hanya menulis warning, tanpa kode', function (): void {
    config(['otp.fonnte.token' => 'TOKEN-RAHASIA']);

    Log::spy();

    Http::fake(function (): void {
        throw new ConnectionException('Operation timed out');
    });

    app(FonnteOtpSender::class)->send('081298765432', 'KODE-9f3a', 'login');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'otp.fonnte.gagal'
            && ! str_contains((string) json_encode($context), 'KODE-9f3a')
            && ($context['sebab'] ?? null) === ConnectionException::class);
});

test('token kosong menulis warning khusus dan tidak memanggil provider', function (): void {
    config(['otp.fonnte.token' => '']);

    Log::spy();
    Http::fake();

    app(FonnteOtpSender::class)->send('081298765432', 'KODE-9f3a', 'login');

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => $message === 'otp.fonnte.tanpa_token');
});

test('pemilih driver memetakan log dan fonnte, dan menolak nama tak dikenal', function (): void {
    expect(PemilihPengirimOtp::kelas('log'))->toBe(LogOtpSender::class)
        ->and(PemilihPengirimOtp::kelas('fonnte'))->toBe(FonnteOtpSender::class);

    expect(fn (): string => PemilihPengirimOtp::kelas('sms'))
        ->toThrow(LogicException::class);
});
