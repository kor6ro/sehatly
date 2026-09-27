<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\Auth\TokenService;

/**
 * Validates `POST /api/v1/auth/refresh` and the `refresh_token` field of
 * `POST /api/v1/auth/logout`.
 *
 * ## The length rule is exact, and that is deliberate
 *
 * `size:` rather than `min:`/`max:`, so a malformed secret is a 422 with
 * `errors.refresh_token` instead of a 401 from the lookup. A 401 means "your session is
 * over, sign in again", and sending a client there because it truncated a token is a
 * worse failure than a validation error it can act on. The length is read from
 * {@see TokenService::REFRESH_TOKEN_PANJANG} so the rule and the generator cannot
 * disagree.
 *
 * It is a size rule and not a `string` + `min:1` pair for the same reason: a token of
 * the wrong length was never issued by this server, so there is nothing to look up and
 * nothing to disclose.
 *
 * ## The value is a secret and is never echoed
 *
 * Laravel's validation failure path does not echo a submitted value, and the controller
 * reads it once and hands it straight to `TokenService`, which hashes it before any
 * query. It never reaches a response body, a log line, or a resource.
 */
class RefreshTokenRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'refresh_token' => [
                'required', 'string',
                'size:'.TokenService::REFRESH_TOKEN_PANJANG,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'refresh_token' => 'refresh token',
        ];
    }
}
