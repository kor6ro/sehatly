<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

/**
 * Validates `POST /api/v1/auth/logout`.
 *
 * ## Why this is a subclass of {@see RefreshTokenRequest} and not a copy of it
 *
 * Logout takes exactly the same one field with exactly the same rules, and the rule is
 * `size:`-exact against a generated secret. Two copies of that rule would be two
 * places for the length to drift, and a logout that 422s because its length check
 * disagreed with the generator is a user who cannot sign out.
 *
 * ## Why `refresh_token` is required here
 *
 * Logout is the only endpoint that can revoke a refresh token, and it revokes the one
 * the client presents. Requiring the field means "log out" has exactly one meaning --
 * end this session's refresh chain -- and a client that has genuinely lost the secret
 * still has a working access token and can call
 * `DELETE /api/v1/auth/devices/{deviceId}` instead. It is required rather than optional
 * so that "revoke every live refresh token for this account" stays a distinct,
 * deliberate action instead of a silent consequence of omitting a field.
 */
class LogoutRequest extends RefreshTokenRequest
{
    //
}
