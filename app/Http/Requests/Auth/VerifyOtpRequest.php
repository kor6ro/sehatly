<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\Auth\OtpService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates `POST /api/v1/auth/otp/verify`.
 *
 * ## `tujuan` is required, and it is the ENUM, not a guess
 *
 * `user_otp.tujuan` is a four-value ENUM (`telemedicine_test.sql:183`) and a code is
 * only valid for the purpose it was minted for, so the caller has to say which one it
 * is verifying. The rule reads
 * {@see OtpService::TUJUAN_DI_TERBITKAN} rather than restating the list, so a value can
 * never be accepted here and rejected by the column.
 *
 * The narrower list -- the two purposes Module 1 actually issues -- is itself read from
 * the service, and `reset_kata_sandi` and `verifikasi_email` are excluded on purpose.
 * This endpoint's *effect* is "issue an access + refresh pair", and that is not a
 * correct outcome for a password-reset code or for an email-verification code: both
 * need their own continuation. Accepting them here would let a caller turn a
 * reset code into a session. The other two are in the column and in
 * {@see OtpService::TUJUAN}; a later todo that implements email verification or a
 * password reset widens one constant.
 *
 * ## `device_id` labels the token AND binds the session to a device
 *
 * It is recorded as `personal_access_tokens.name` so an administrator reading a token
 * list can tell a phone session from a web one, which the plan's
 * `$user->createToken($deviceName, ...)` asks for. It is also persisted on the refresh
 * row (`user_refresh_tokens.device_id`, F01's owner-approved mapping, migration
 * `2026_10_01_000083`) so `DELETE /auth/devices/{deviceId}` can end exactly this
 * device's sessions, and `TokenService::rotate()` carries it forward on every
 * rotation. It is validated exactly as {@see StoreDeviceRequest} validates it and is
 * never used to select whose tokens to touch - the revoke path scopes by the caller's
 * own `user_id` in addition. It is still not written to `user_devices`: registering
 * the installation is the device endpoint's job, and a session may exist for a device
 * the list has never seen.
 *
 * ## `kode` is a six-digit string, not an integer
 *
 * The rule is a regex, not `digits:6`, and the value is never cast to a number. A code
 * may begin with `0` (`OtpService` zero-pads), and a client that parsed the response as
 * an integer would send a five-digit code that can never match. The same reasoning is
 * why the stored hash is of the string form.
 */
class VerifyOtpRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->identifierRules() + [
            'kode' => ['required', 'string', 'regex:/^[0-9]{'.OtpService::KODE_DIGIT.'}$/'],
            'tujuan' => ['required', 'string', Rule::in(OtpService::TUJUAN_DI_TERBITKAN)],
            'device_id' => ['nullable', 'string', 'min:3', 'max:255'],
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
            'kode' => 'kode OTP',
            'tujuan' => 'tujuan OTP',
            'device_id' => 'id perangkat',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->requireAtLeastOneIdentifier($validator);
    }
}
