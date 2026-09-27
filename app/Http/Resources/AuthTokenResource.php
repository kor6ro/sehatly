<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Auth\AuthTokenPair;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The token pair, in the shape the React and Dart clients both read.
 *
 * ## `expires_in` is a number of seconds, not a timestamp
 *
 * `expires_in` is what a client can act on without a clock comparison: a Kotlin or Dart
 * cache stores "expires in 1439 seconds" and re-authenticates when it reaches zero. The
 * absolute `*_expires_at` values are published alongside it so a client that does have
 * a trustworthy clock can schedule a refresh, and so the values can be asserted in a
 * test without recomputing anything.
 *
 * ## The two secrets appear exactly once, in exactly one response
 *
 * The access token is in the response body rather than an `Authorization` header on the
 * request that issued it, which is the only option: the caller has no token yet. It is
 * never logged here, and {@see AuthTokenPair::__debugInfo()} redacts it so a `dd()` in
 * a future debug statement cannot print it.
 *
 * `refresh_token` is a sibling of `access_token` and not a cookie. `config/cors.php`
 * sets `supports_credentials: true` for the SPA, and a cross-origin cookie the browser
 * would attach automatically is a worse default for a long-lived credential than a value
 * the client has to hold deliberately; todo 24's Dart package documents the
 * `flutter_secure_storage` half of that decision.
 *
 * @property-read AuthTokenPair $resource
 */
class AuthTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token_type' => AuthTokenPair::TOKEN_TYPE,
            'access_token' => $this->resource->accessToken,
            'expires_in' => $this->resource->accessTokenTtlSeconds(),
            'access_token_expires_at' => $this->resource->accessTokenExpiresAt->toISOString(),
            'refresh_token' => $this->resource->refreshToken,
            'refresh_token_expires_at' => $this->resource->refreshTokenExpiresAt->toISOString(),
        ];
    }
}
