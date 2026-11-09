<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\Telepon;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/auth/daftar/marketing` - self-registration for the marketing portal.
 *
 * Simpler than {@see RegisterRequest}: no patient demographics, no PDP consents
 * (those are collected after approval). The account is created with status
 * `menunggu_persetujuan` and must be approved by an admin before it can log in.
 *
 * `tipe` is NOT accepted from the client - it is hardcoded to `marketing` in the
 * controller, so no caller can self-register as admin.
 */
class DaftarMarketingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $nomor = $this->input('no_telepon');
        if (is_string($nomor)) {
            $this->merge(['no_telepon' => Telepon::normalisasi($nomor)]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nama_lengkap' => ['required', 'string', 'min:3', 'max:150'],
            'no_telepon' => [
                'required', 'string', 'max:20',
                'regex:/^\+?[0-9]{8,20}$/',
                'unique:users,no_telepon',
            ],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'nama_lengkap' => 'nama lengkap',
            'no_telepon' => 'nomor telepon',
            'password' => 'kata sandi',
        ];
    }
}
