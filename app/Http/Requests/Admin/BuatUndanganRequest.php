<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Actions\UndangPengguna;
use App\Support\Telepon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/admin/undangan` - invite a staff account.
 *
 * `tipe` is restricted to {@see UndangPengguna::ROLE_DIIZINKAN}. Creating an
 * `admin` additionally requires a `superadmin` actor, checked in the controller.
 *
 * `kanal` is the delivery channel for the invitation link.
 */
class BuatUndanganRequest extends FormRequest
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
            'tipe' => ['required', 'string', Rule::in(UndangPengguna::ROLE_DIIZINKAN)],
            'kanal' => ['required', 'string', Rule::in(['whatsapp', 'sms', 'email'])],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'nama_lengkap' => 'nama lengkap',
            'no_telepon' => 'nomor telepon',
            'tipe' => 'tipe akun',
            'kanal' => 'kanal pengiriman',
        ];
    }
}
