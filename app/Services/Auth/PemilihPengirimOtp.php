<?php

declare(strict_types=1);

namespace App\Services\Auth;

use LogicException;

/**
 * F-005: which {@see OtpSender} a configured driver name resolves to.
 *
 * A named method rather than a `match` inside `AppServiceProvider::configureOtpDelivery()`
 * so the mapping is checkable without booting a second application, and so an
 * unknown driver fails loudly instead of falling back to the log sender - a silent
 * fallback is exactly the failure this whole change exists to remove.
 */
final class PemilihPengirimOtp
{
    /** @var list<string> */
    public const DRIVER = ['log', 'fonnte'];

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
}
