<?php

declare(strict_types=1);

namespace App\Services\Auth;

use RuntimeException;

/**
 * Raised when a presented refresh token cannot be exchanged.
 *
 * ## The three reasons are all 401 to the client, and one of them is a security event
 *
 * `tidak_diketahui` and `kedaluwarsa` are ordinary: a client that never had a token,
 * or one that sat idle past thirty days. `sudah_dicabut` is not. A refresh token is
 * rotated on every use and the old one is marked `dicabut = 1`, so the *only* way to
 * present a revoked token is to present a token that was already spent -- which means
 * either the client replayed it by bug, or somebody else is holding a copy. The two are
 * indistinguishable from here, so the service assumes the worse one:
 * {@see TokenService::rotate()} revokes every live refresh token for that user before
 * raising this.
 *
 * The reason is still carried so the server log and the test suite can tell the three
 * apart; the client is told only that the session is over, because "your token was
 * reused" is a statement about an attacker and helps one.
 */
final class RefreshTokenRejected extends RuntimeException
{
    /** No `user_refresh_tokens` row carries this hash. */
    public const TIDAK_DIKETAHUI = 'tidak_diketahui';

    /** The row exists and `dicabut` is 1: a replay, treated as theft. */
    public const SUDAH_DICABUT = 'sudah_dicabut';

    /** The row exists and `kedaluwarsa_at` is at or before now. */
    public const KEDALUWARSA = 'kedaluwarsa';

    /**
     * The single client-facing message for all three.
     *
     * One string, so the response cannot be used as an oracle for which of the three
     * conditions applied.
     */
    public const PESAN = 'Sesi tidak valid. Silakan masuk kembali.';

    private function __construct(
        public readonly string $reason,
    ) {
        parent::__construct(self::PESAN);
    }

    public static function tidakDiketahui(): self
    {
        return new self(self::TIDAK_DIKETAHUI);
    }

    public static function sudahDicabut(): self
    {
        return new self(self::SUDAH_DICABUT);
    }

    public static function kedaluwarsa(): self
    {
        return new self(self::KEDALUWARSA);
    }
}
