<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Services\Auth\OtpSender;
use Illuminate\Contracts\Foundation\Application;

/**
 * F-005: refuse to boot outside local/testing while a delivery channel is the log.
 *
 * `LogOtpSender` and `LogPushDispatcher` are the correct implementations in
 * `local` and `testing`. Anywhere else they are a silent outage - the OTP never
 * reaches the account holder, the push never reaches a device - and the failure is
 * invisible: registration returns 201, a booking returns 201, and nothing in the
 * response says the message went to a file.
 *
 * The guard also refuses a channel that names a real driver but is missing its
 * credential (`fonnte` with no `FONNTE_TOKEN`, `fcm` with no
 * `FIREBASE_CREDENTIALS`), because that shape fails at request time rather than at
 * boot - and it fails on the FIRST registration, which is the worst moment to
 * discover a deployment mistake.
 *
 * The pairing with the runtime behaviour is deliberate: the senders must not throw
 * for a recoverable delivery failure (see {@see OtpSender}), so
 * boot is the only place a misconfiguration can be loud.
 */
final class PenjagaPengirimanProduksi
{
    /**
     * The environments where a log transport is the intended one.
     *
     * @var list<string>
     */
    public const LINGKUNGAN_DIKECUALIKAN = ['local', 'testing'];

    /**
     * @throws PengirimanLogDiProduksiException when a channel cannot deliver outside local/testing
     */
    public static function pastikan(Application $app): void
    {
        if ($app->environment(self::LINGKUNGAN_DIKECUALIKAN)) {
            return;
        }

        $otp = (string) config('otp.driver');
        $push = (string) config('push.driver');

        $masalah = [];

        if ($otp === 'log') {
            $masalah[] = 'OTP_DRIVER is "log": no account holder can receive a code. '
                .'Set OTP_DRIVER=fonnte (prototype) or an official gateway.';
        }

        if ($push === 'log') {
            $masalah[] = 'PUSH_DRIVER is "log": no device receives a push. '
                .'Set PUSH_DRIVER=fcm with FIREBASE_CREDENTIALS.';
        }

        if ($otp === 'fonnte' && (string) config('otp.fonnte.token') === '') {
            $masalah[] = 'OTP_DRIVER is "fonnte" but FONNTE_TOKEN is empty.';
        }

        if ($push === 'fcm' && (string) config('push.firebase.credentials') === '') {
            $masalah[] = 'PUSH_DRIVER is "fcm" but FIREBASE_CREDENTIALS is empty.';
        }

        if ($masalah !== []) {
            throw new PengirimanLogDiProduksiException(
                'Refusing to boot: '.implode(' ', $masalah)
                .' APP_ENV is not local or testing, so the log transport is no longer acceptable.',
            );
        }
    }
}
