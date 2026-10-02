<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\Auth\OtpService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates `POST /api/v1/auth/otp/resend`.
 *
 * ## The body is the F01 §4.4 proposal, field for field
 *
 * `{tujuan, no_telepon?, email?, device_id?}`. It identifies an account exactly
 * as `otp/verify` does - either identifier, at least one, `no_telepon` winning -
 * because the code it re-mints has to belong to the same account the verify
 * call will name.
 *
 * ## `tujuan` is the narrow issue-list, not the DDL enum
 *
 * `Rule::in(OtpService::TUJUAN_DI_TERBITKAN)` accepts `verifikasi_telepon` and
 * `login` only, the same list `VerifyOtpRequest` uses and for the same reason:
 * this endpoint's effect is "mint a code this API can spend", and
 * `reset_kata_sandi` / `verifikasi_email` need their own continuation rather
 * than a resend path. Reading the constant instead of restating the two values
 * keeps the FormRequest and the service from drifting.
 *
 * ## `device_id` is accepted for contract symmetry and is NOT used for control
 *
 * `otp/verify` uses it to label the issued Sanctum token. Resend issues no
 * token, so there is nothing to label, and it is deliberately kept OUT of the
 * rate-limit key (`AppServiceProvider`'s `auth-otp-resend`): a caller controls
 * this value and could rotate it to buy fresh resend attempts against one phone
 * number. It is validated with the same rules as everywhere else so the shape
 * of the field cannot drift between endpoints.
 */
class ResendOtpRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->identifierRules() + [
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
            'tujuan' => 'tujuan OTP',
            'device_id' => 'id perangkat',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->requireAtLeastOneIdentifier($validator);
    }
}
