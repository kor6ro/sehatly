<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Validator;

/**
 * Validates `POST /api/v1/auth/login`.
 *
 * ## What this endpoint deliberately does not return
 *
 * A successful login returns **no token**. It mints an OTP and stops. The reason is
 * that a phone-based second factor is only a second factor while the second step is
 * reachable by whoever holds the phone; issuing a token from the password step would
 * make the OTP a confirmation screen rather than a control. The plan's acceptance
 * criterion ("a test asserts login alone returns no `data.token`") is asserted
 * directly in `AuthFlowTest`.
 *
 * ## The password rule is only a length cap
 *
 * `password` is `required` with a `max`, and nothing else. The strength policy belongs
 * to `Password::defaults()`, which `AppServiceProvider` already makes strict in
 * production, and restating it here would be a second policy that could disagree.
 * Enforcing a minimum of 1 rather than 8 at the login endpoint is also correct: a
 * login request for an account whose password predates a policy change must not be
 * rejected as "too short" before the hash is even checked.
 */
class LoginRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->identifierRules() + [
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'no_telepon' => 'nomor telepon',
            'email' => 'email',
            'password' => 'kata sandi',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->requireAtLeastOneIdentifier($validator);
    }
}
