<?php

declare(strict_types=1);

use App\Support\Security\PenjagaRahasiaWebhook;
use App\Support\Security\RahasiaWebhookDefaultException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| F-006 - a production boot refuses a shipped-default webhook secret
|--------------------------------------------------------------------------
|
| `config/services.php` ships a literal `UBAH-SEKRET-WEBHOOK-*` per gateway so
| the application runs locally without a `.env` edit. Those literals are in the
| repository, and the webhook they key is UNAUTHENTICATED - the HMAC is the only
| thing between the internet and "mark any invoice paid". A deployment that
| forgot to override one would verify a forged delivery and look healthy.
|
| The guard is called first in `AppServiceProvider::boot()`, so the refusal lands
| on the first console command or request rather than on the first forged
| delivery. This file proves it three ways: the `testing` exemption, the refusal
| with a real environment switch, and - the one that matters - a genuine
| subprocess boot with `APP_ENV=production` and the defaults in place, which is
| the only measurement that shows the call site is wired and not merely correct in
| isolation.
|
| The refusal message is asserted for the ENV VAR NAMES and never for a secret
| value: a guard that echoed the key would turn the failure into the leak.
|
*/

/** One shipped default, built from the guard's own prefix so it cannot drift. */
function rahasiaBawaanWebhook(string $gateway = 'MIDTRANS'): string
{
    return PenjagaRahasiaWebhook::AWALAN_BAWAAN.$gateway.'-0000000000000001';
}

test('lingkungan testing dikecualikan dari penjagaan', function (): void {
    config(['services.payment.gateways' => [
        'midtrans' => ['webhook_secret' => rahasiaBawaanWebhook()],
    ]]);

    expect(app()->environment())->toBe('testing');

    expect(fn () => PenjagaRahasiaWebhook::pastikan(app()))
        ->not->toThrow(RahasiaWebhookDefaultException::class);
});

test('produksi dengan rahasia bawaan gagal keras dan menyebut variabel environment', function (): void {
    config(['services.payment.gateways' => [
        'midtrans' => ['webhook_secret' => rahasiaBawaanWebhook('MIDTRANS')],
        'xendit' => ['webhook_secret' => rahasiaBawaanWebhook('XENDIT')],
        // Two gateways ARE configured, so the refusal must name only the two that
        // are not - and must not echo either real value.
        'doku' => ['webhook_secret' => 'RAHASIA-DOKU-YANG-ASLI'],
        'flip' => ['webhook_secret' => 'RAHASIA-FLIP-YANG-ASLI'],
    ]]);

    app()['env'] = 'production';

    expect(app()->environment())->toBe('production');

    try {
        PenjagaRahasiaWebhook::pastikan(app());

        $this->fail('A production boot must be refused while a shipped default secret is in place');
    } catch (RahasiaWebhookDefaultException $e) {
        expect($e->getMessage())
            ->toContain('midtrans')
            ->toContain('xendit')
            ->toContain('PAYMENT_WEBHOOK_SECRET_MIDTRANS')
            ->toContain('PAYMENT_WEBHOOK_SECRET_XENDIT')
            ->not->toContain('doku')
            ->not->toContain('RAHASIA-DOKU-YANG-ASLI')
            ->not->toContain('RAHASIA-FLIP-YANG-ASLI');
    }
});

test('rahasia kosong juga ditolak, karena HMAC ber-kunci kosong adalah kunci publik', function (): void {
    config(['services.payment.gateways' => [
        'midtrans' => ['webhook_secret' => ''],
    ]]);

    app()['env'] = 'production';

    expect(fn () => PenjagaRahasiaWebhook::pastikan(app()))
        ->toThrow(RahasiaWebhookDefaultException::class);
});

test('produksi dengan semua rahasia di-override lolos', function (): void {
    config(['services.payment.gateways' => [
        'midtrans' => ['webhook_secret' => 'RAHASIA-1'],
        'xendit' => ['webhook_secret' => 'RAHASIA-2'],
        'doku' => ['webhook_secret' => 'RAHASIA-3'],
        'flip' => ['webhook_secret' => 'RAHASIA-4'],
    ]]);

    app()['env'] = 'production';

    expect(fn () => PenjagaRahasiaWebhook::pastikan(app()))
        ->not->toThrow(RahasiaWebhookDefaultException::class);
});

test('boot nyata dengan APP_ENV=production dan rahasia bawaan gagal', function (): void {
    // A real subprocess, not a second in-process call: it is the only measurement
    // that proves `boot()` calls the guard and that the failure reaches a console
    // command's exit code. `.env` says `APP_ENV=local`, and a real environment
    // variable wins over `.env`, which is exactly the production shape.
    $process = new Process(
        [PHP_BINARY, 'artisan', 'about'],
        base_path(),
        ['APP_ENV' => 'production'],
    );

    $process->setTimeout(120);
    $process->run();

    expect($process->isSuccessful())->toBeFalse('artisan about must not exit 0 with default webhook secrets in production')
        ->and($process->getOutput().$process->getErrorOutput())
        ->toContain('PAYMENT_WEBHOOK_SECRET_MIDTRANS');
});
