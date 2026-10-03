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
 * ## `password` is optional, and why that is not a hole in this endpoint
 *
 * `password` is `sometimes` with a `max`, and nothing else: a client that sends one has
 * it verified (`AuthController::login` refuses a mismatch), and a client that sends none
 * is admitted on the OTP alone. The web client no longer sends one - the sign-in screen
 * is a phone number and a code, one door for new and returning visitors alike - so the
 * rule stays only for the callers that still present a password.
 *
 * That reads as a bypass, and alone it would be: whoever wants in simply omits the
 * field. What makes the OTP sufficient rather than optional is that the code is minted
 * ONLY for a registered, active account and is consumed over a second round trip
 * (`/auth/otp/verify`) with its own attempt counter. The password was never what
 * identified the account - {@see AuthRequest::identifierRules()} is - it was a second
 * secret checked in the SAME request as the identifier, which is the part this change
 * removes.
 *
 * The strength policy belongs to `Password::defaults()`, which `AppServiceProvider`
 * already makes strict in production; restating it here would be a second policy that
 * could disagree, and a minimum on login would reject an account whose password predates
 * a policy change before the hash is even checked.
 */
class LoginRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->identifierRules() + [
            'password' => ['sometimes', 'string', 'max:255'],
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
