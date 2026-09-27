<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Carbon\CarbonInterface;

/**
 * An issued access + refresh token pair, in plaintext, for exactly one response.
 *
 * ## Why the refresh token is a string and not a Sanctum token
 *
 * Sanctum's `personal_access_tokens` stores a *hashed* access token and hands the
 * plaintext back once, at creation. A refresh token has the opposite requirement: the
 * client must present it repeatedly until it expires or is rotated, so the server has
 * to recognise it on every use. Sanctum's guard cannot do that for a second token, so
 * the refresh token is an application-level secret over the contract's own
 * `user_refresh_tokens` table, and this object is the transport.
 *
 * ## Nothing here is ever logged or persisted in this form
 *
 * {@see TokenService} writes only `hash('sha256', ...)` of the refresh token to the
 * database. The plaintext exists in this object, in the JSON response, and in the
 * client's secure storage -- nowhere else. `__debugInfo()` redacts both halves for the
 * same reason {@see IssuedOtp} does: a `dd()` must not print a credential.
 */
final class AuthTokenPair
{
    public const TOKEN_TYPE = 'Bearer';

    public function __construct(
        public readonly string $accessToken,
        public readonly CarbonInterface $accessTokenExpiresAt,
        public readonly string $refreshToken,
        public readonly CarbonInterface $refreshTokenExpiresAt,
    ) {}

    /**
     * How long the access token remains valid, in whole seconds.
     *
     * Computed from the same clock the token was minted with, so a client that honours
     * this never has to probe the server to discover the expiry. Carbon 3 returns a
     * float from `diffInSeconds()`, so the cast is load-bearing: an `int` return type
     * with a float value is a TypeError, and it is raised while the response is being
     * serialised, which turns every token response into a 500.
     */
    public function accessTokenTtlSeconds(): int
    {
        return max(0, (int) now()->diffInSeconds($this->accessTokenExpiresAt, false));
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'tokenType' => self::TOKEN_TYPE,
            'accessTokenExpiresAt' => $this->accessTokenExpiresAt,
            'refreshTokenExpiresAt' => $this->refreshTokenExpiresAt,
        ];
    }
}
