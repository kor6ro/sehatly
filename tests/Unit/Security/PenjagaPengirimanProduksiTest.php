<?php

declare(strict_types=1);

use App\Support\Security\PengirimanLogDiProduksiException;
use App\Support\Security\PenjagaPengirimanProduksi;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| F-005 - a non-local boot refuses a log delivery channel
|--------------------------------------------------------------------------
|
| `LogOtpSender` and `LogPushDispatcher` are correct in `local`/`testing` and a
| silent outage anywhere else: registration returns 201 while the OTP goes to a
| file, and no device is ever pushed to. The guard refuses that boot, and also
| refuses a real driver that is missing its credential - a shape that otherwise
| fails on the first registration.
|
| The tests switch `app()['env']` directly rather than spawning a subprocess: the
| cold-boot path is already proven for the webhook guard by
| `tests/Unit/Security/PenjagaRahasiaWebhookTest.php`, and this class is the same
| kind of call from the same `boot()`.
|
*/

test('lingkungan testing dikecualikan', function (): void {
    config(['otp.driver' => 'log', 'push.driver' => 'log']);

    expect(app()->environment())->toBe('testing');

    expect(fn () => PenjagaPengirimanProduksi::pastikan(app()))
        ->not->toThrow(PengirimanLogDiProduksiException::class);
});

test('produksi dengan OTP_DRIVER=log ditolak', function (): void {
    config([
        'otp.driver' => 'log',
        'push.driver' => 'fcm',
        'push.firebase.credentials' => '/tmp/kredensial.json',
    ]);

    app()['env'] = 'production';

    try {
        PenjagaPengirimanProduksi::pastikan(app());
        $this->fail('A production boot must be refused while OTP_DRIVER is log');
    } catch (PengirimanLogDiProduksiException $e) {
        expect($e->getMessage())->toContain('OTP_DRIVER');
    }
});

test('produksi dengan PUSH_DRIVER=log ditolak', function (): void {
    config([
        'otp.driver' => 'fonnte',
        'otp.fonnte.token' => 'TOKEN-ADA',
        'push.driver' => 'log',
    ]);

    app()['env'] = 'production';

    try {
        PenjagaPengirimanProduksi::pastikan(app());
        $this->fail('A production boot must be refused while PUSH_DRIVER is log');
    } catch (PengirimanLogDiProduksiException $e) {
        expect($e->getMessage())->toContain('PUSH_DRIVER');
    }
});

test('produksi dengan fonnte tanpa token ditolak', function (): void {
    config([
        'otp.driver' => 'fonnte',
        'otp.fonnte.token' => '',
        'push.driver' => 'fcm',
        'push.firebase.credentials' => '/tmp/kredensial.json',
    ]);

    app()['env'] = 'production';

    try {
        PenjagaPengirimanProduksi::pastikan(app());
        $this->fail('A production boot must be refused while FONNTE_TOKEN is empty');
    } catch (PengirimanLogDiProduksiException $e) {
        expect($e->getMessage())->toContain('FONNTE_TOKEN');
    }
});

test('produksi dengan driver nyata dan kredensial lengkap lolos', function (): void {
    config([
        'otp.driver' => 'fonnte',
        'otp.fonnte.token' => 'TOKEN-ADA',
        'push.driver' => 'fcm',
        'push.firebase.credentials' => '/tmp/kredensial.json',
    ]);

    app()['env'] = 'production';

    expect(fn () => PenjagaPengirimanProduksi::pastikan(app()))
        ->not->toThrow(PengirimanLogDiProduksiException::class);
});

test('produksi dengan OTP_CHANNEL yang tidak sesuai driver ditolak', function (): void {
    // Declaring `sms` while the active driver delivers over WhatsApp is exactly
    // the silent mismatch F01 decision #5 forbids: the patient is told one
    // channel and the message travels another.
    config([
        'otp.driver' => 'fonnte',
        'otp.fonnte.token' => 'TOKEN-ADA',
        'otp.channel' => 'sms',
        'push.driver' => 'fcm',
        'push.firebase.credentials' => '/tmp/kredensial.json',
    ]);

    app()['env'] = 'production';

    try {
        PenjagaPengirimanProduksi::pastikan(app());
        $this->fail('A production boot must be refused while OTP_CHANNEL and OTP_DRIVER disagree');
    } catch (PengirimanLogDiProduksiException $e) {
        expect($e->getMessage())->toContain('OTP_CHANNEL');
    }
});

test('produksi dengan OTP_CHANNEL tak dikenal ditolak', function (): void {
    config([
        'otp.driver' => 'fonnte',
        'otp.fonnte.token' => 'TOKEN-ADA',
        'otp.channel' => 'telegram',
        'push.driver' => 'fcm',
        'push.firebase.credentials' => '/tmp/kredensial.json',
    ]);

    app()['env'] = 'production';

    try {
        PenjagaPengirimanProduksi::pastikan(app());
        $this->fail('A production boot must be refused while OTP_CHANNEL is not a known channel');
    } catch (PengirimanLogDiProduksiException $e) {
        expect($e->getMessage())->toContain('OTP_CHANNEL');
    }
});
