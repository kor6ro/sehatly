<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Services\Auth\OtpSender;
use App\Services\Auth\PemilihPengirimOtp;
use Illuminate\Contracts\Foundation\Application;
use LogicException;

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

        // The declared channel and the active driver must agree. Without this,
        // `OTP_CHANNEL=sms` with `OTP_DRIVER=fonnte` would tell patients a code
        // was sent by SMS while it actually went over the unofficial WhatsApp
        // gateway - a silent mismatch between the contract and the transport.
        // WhatsApp is an option, so selecting another channel is allowed; what is
        // refused is DECLARING one and delivering over another.
        if (! in_array($otp, PemilihPengirimOtp::DRIVER, true)) {
            $masalah[] = 'OTP_DRIVER is ['.$otp.'], which is not a known driver. Known: ['
                .implode(', ', PemilihPengirimOtp::DRIVER).'].';
        } else {
            $kanalAktif = PemilihPengirimOtp::kanal($otp);

            // The accessors validate the declared names, so a typo is collected
            // here instead of throwing a `LogicException` out of a guard whose
            // job is to report every problem in one message.
            $kanalDipilih = null;
            $kanalFallback = null;

            try {
                $kanalDipilih = PemilihPengirimOtp::kanalTerpilih();
            } catch (LogicException $e) {
                $masalah[] = 'OTP_CHANNEL: '.$e->getMessage();
            }

            try {
                $kanalFallback = PemilihPengirimOtp::kanalFallback();
            } catch (LogicException $e) {
                $masalah[] = 'OTP_FALLBACK_CHANNEL: '.$e->getMessage();
            }

            if ($kanalDipilih !== null && $kanalDipilih !== $kanalAktif) {
                $masalah[] = 'OTP_CHANNEL is ['.$kanalDipilih.'] but OTP_DRIVER is ['.$otp.'], which '
                    .'delivers over ['.$kanalAktif.']. Set OTP_CHANNEL='.$kanalAktif
                    .' or implement and select the ['.$kanalDipilih.'] channel'
                    .' (declared fallback: ['.($kanalFallback ?? 'unknown').']).';
            }
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
