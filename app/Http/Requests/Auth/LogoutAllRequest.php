<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

/**
 * Validates `POST /api/v1/auth/logout-all`.
 *
 * ## Why this is a `FormRequest` with an empty `rules()`
 *
 * The Definition of Done requires every `POST` to validate through a
 * `FormRequest`, read from the controller method's signature by
 * `sehatly:openapi`. This endpoint genuinely has no body: the account to sign
 * out is the authenticated one, and "all devices" is the absence of a device
 * selector rather than a field. An empty rule set is therefore the honest
 * declaration - it says "no caller-supplied field is trusted here" - and it
 * keeps the route inside the DoD instead of becoming a fourth exemption in
 * `RouteInventory`.
 *
 * The alternative shapes were considered and rejected:
 *
 * - **No body at all with a bare `Request`**: would need a written exemption in
 *   `RouteInventory::FORM_REQUEST_EXEMPTIONS`, whose list is asserted to stay at
 *   three. A bulk logout is not a webhook HMAC or a path-only transition; it is
 *   an ordinary authenticated write.
 * - **A `refresh_token` field**: would make "all devices" mean "all devices
 *   except the one I forgot to list", and the access token is already the proof
 *   of the current session.
 */
class LogoutAllRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
