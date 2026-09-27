<?php

declare(strict_types=1);

namespace App\Services\Auth;

use RuntimeException;

/**
 * Raised when a presented one-time code cannot be accepted.
 *
 * ## Why an exception and not a `null` return
 *
 * {@see OtpService::consume()} has four outcomes -- accepted, unknown code, already
 * used, expired -- and three of them are failures a caller must be able to tell
 * apart: the client renders a different message and offers a different next action
 * ("resend" for an expired code, "start over" for an unknown one). A `?UserOtp`
 * return would collapse that into one branch, and the distinction would be lost at
 * the first call site that ignored it.
 *
 * The HTTP mapping stays in the controller: this is a domain object and knows nothing
 * about status codes. The controller turns it into the task-3 error envelope's
 * `errors.kode` array, so the 422 body is produced by the same code path as every
 * other field-level validation failure on this API.
 */
final class OtpRejected extends RuntimeException
{
    /** The code does not match any row for this user and purpose. */
    public const TIDAK_DIKETAHUI = 'tidak_diketahui';

    /** A row matches, but `user_otp.sudah_dipakai` is already 1. */
    public const SUDAH_DIPAKAI = 'sudah_dipakai';

    /** A row matches, but `user_otp.kedaluwarsa_at` is at or before now. */
    public const KEDALUWARSA = 'kedaluwarsa';

    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function tidakDiketahui(): self
    {
        return new self(self::TIDAK_DIKETAHUI, 'Kode OTP tidak valid.');
    }

    public static function sudahDipakai(): self
    {
        return new self(self::SUDAH_DIPAKAI, 'Kode OTP sudah pernah dipakai.');
    }

    public static function kedaluwarsa(): self
    {
        return new self(self::KEDALUWARSA, 'Kode OTP sudah kedaluwarsa. Silakan minta kode baru.');
    }
}
