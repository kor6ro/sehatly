<?php

declare(strict_types=1);

namespace App\Services\Notifikasi;

use App\Services\Auth\PemilihPengirimOtp;
use LogicException;

/**
 * F-005: which {@see PushDispatcher} a configured driver name resolves to.
 *
 * The push counterpart of {@see PemilihPengirimOtp}, and for the
 * same reason: the mapping is checkable without booting a second application, and
 * an unknown driver must fail loudly rather than fall back to the log.
 */
final class PemilihPengirimPush
{
    /** @var list<string> */
    public const DRIVER = ['log', 'fcm'];

    /**
     * @return class-string<PushDispatcher>
     */
    public static function kelas(string $driver): string
    {
        return match ($driver) {
            'log' => LogPushDispatcher::class,
            'fcm' => FcmPushDispatcher::class,
            default => throw new LogicException(sprintf(
                'config("push.driver") is [%s], which is not a known push driver. Known: [%s].',
                $driver,
                implode(', ', self::DRIVER),
            )),
        };
    }
}
