<?php

declare(strict_types=1);

namespace App\Services\Auth;

use LogicException;

/**
 * F-005: which {@see OtpSender} a configured driver name resolves to, and which
 * public channel that driver actually delivers over.
 *
 * A named method rather than a `match` inside `AppServiceProvider::configureOtpDelivery()`
 * so the mapping is checkable without booting a second application, and so an
 * unknown driver fails loudly instead of falling back to the log sender - a silent
 * fallback is exactly the failure this whole change exists to remove.
 *
 * ## The driver and the channel are different questions
 *
 * `driver` is the implementation (`log`, `fonnte`); `channel` is the transport a
 * person receives the message on (`whatsapp`, `sms`, `email`, or the `log`
 * stand-in). `PemilihPengirimOtp::kanal()` answers the second for a given first,
 * and `POST /auth/otp/resend` publishes it, so a client is told the truth about
 * where the code went rather than what the deployment wished for.
 *
 * WhatsApp is one option among four, and `config('otp.channel')` /
 * `config('otp.fallback_channel')` name the preferred and fallback channels.
 * `PenjagaPengirimanProduksi` refuses a non-local boot where the declared channel
 * and the active driver disagree, which is what stops `OTP_CHANNEL=sms` from
 * silently sending WhatsApp messages. See `config/otp.php` for the full argument.
 */
final class PemilihPengirimOtp
{
    /** The `OtpSender` implementations this build ships. */
    public const DRIVER = ['log', 'fonnte'];

    /**
     * The channels a deployment may declare.
     *
     * `log` is the local/testing stand-in and never a production channel; the
     * other three are real transports, of which only `whatsapp` has a shipped
     * implementation today (`fonnte`). `sms` and `email` are listed because the
     * contract and the config must be able to name the fallback before an
     * implementation exists - an operator who selects one gets a loud refusal,
     * not a silent WhatsApp send.
     *
     * @var list<string>
     */
    public const KANAL = ['whatsapp', 'sms', 'email', 'log'];

    /**
     * driver => the channel that driver delivers over.
     *
     * @var array<string, string>
     */
    private const KANAL_PER_DRIVER = [
        'log' => 'log',
        'fonnte' => 'whatsapp',
    ];

    /**
     * @return class-string<OtpSender>
     */
    public static function kelas(string $driver): string
    {
        return match ($driver) {
            'log' => LogOtpSender::class,
            'fonnte' => FonnteOtpSender::class,
            default => throw new LogicException(sprintf(
                'config("otp.driver") is [%s], which is not a known OTP driver. Known: [%s].',
                $driver,
                implode(', ', self::DRIVER),
            )),
        };
    }

    /**
     * The public channel a driver delivers over.
     *
     * An unknown driver throws for the same reason {@see kelas()} does: a
     * response that named a channel for a driver nobody implemented would be a
     * guess.
     */
    public static function kanal(string $driver): string
    {
        if (! isset(self::KANAL_PER_DRIVER[$driver])) {
            throw new LogicException(sprintf(
                'config("otp.driver") is [%s], which has no channel mapping. Known: [%s].',
                $driver,
                implode(', ', array_keys(self::KANAL_PER_DRIVER)),
            ));
        }

        return self::KANAL_PER_DRIVER[$driver];
    }

    /**
     * The channel the ACTIVE driver actually delivers over.
     *
     * This is what `POST /auth/otp/resend` reports as `otp.kanal`: derived from
     * the binding that sent the message, not from the declared preference.
     */
    public static function kanalAktif(): string
    {
        return self::kanal((string) config('otp.driver'));
    }

    /**
     * The declared preferred channel, validated against {@see KANAL}.
     */
    public static function kanalTerpilih(): string
    {
        return self::kanalDikonfigurasi('otp.channel');
    }

    /**
     * The declared fallback channel, validated against {@see KANAL}.
     */
    public static function kanalFallback(): string
    {
        return self::kanalDikonfigurasi('otp.fallback_channel');
    }

    private static function kanalDikonfigurasi(string $key): string
    {
        $kanal = (string) config($key);

        if (! in_array($kanal, self::KANAL, true)) {
            throw new LogicException(sprintf(
                'config("%s") is [%s], which is not a known OTP channel. Known: [%s].',
                $key,
                $kanal,
                implode(', ', self::KANAL),
            ));
        }

        return $kanal;
    }
}
