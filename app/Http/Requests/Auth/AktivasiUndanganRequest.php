<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/auth/undangan/{token}/aktivasi` - activate an invited account.
 *
 * The invitee proves their phone via OTP (issued by `POST /auth/otp/resend`
 * or the invitation delivery), then sets their own password.
 *
 * `{token}` is the plaintext invitation token from the URL path; it is NOT
 * validated here (the controller resolves it first and answers 404/422 before
 * this request is even constructed).
 */
class AktivasiUndanganRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'otp' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'otp' => 'kode OTP',
            'password' => 'kata sandi baru',
        ];
    }
}
