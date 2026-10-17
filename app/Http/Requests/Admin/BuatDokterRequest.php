<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /admin/dokter` — create a new doctor account.
 *
 * Creates both the `users` row (`tipe: dokter`, `status: aktif`) and the
 * `dokter` row in one transaction. The caller supplies the minimum required
 * to make the account usable: identity, credentials and the two required
 * `dokter` columns (`nomor_str`, `str_berlaku_sampai`).
 *
 * `kata_sandi` is required: unlike a self-registered patient, a doctor account
 * is created by an admin and must be immediately usable with a known password.
 * The admin communicates it out-of-band; the doctor changes it on first login.
 *
 * `status_verifikasi` is NOT accepted — new accounts start `pending` and the
 * admin verifies through `PUT /admin/dokter/{id}/verifikasi`. Accepting it here
 * would bypass the one-way verification gate.
 */
class BuatDokterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // users fields
            'nama_lengkap'      => ['required', 'string', 'max:150'],
            'no_telepon'        => ['required', 'string', 'max:20', 'unique:users,no_telepon'],
            'email'             => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'kata_sandi'        => ['required', 'string', 'min:8', 'max:255'],

            // dokter fields
            'tipe'              => ['required', 'string', Rule::in([
                'dokter_umum', 'dokter_spesialis', 'dokter_gigi',
                'psikolog', 'bidan', 'perawat', 'apoteker',
            ])],
            'nomor_str'         => ['required', 'string', 'max:30', 'unique:dokter,nomor_str'],
            'str_berlaku_sampai' => ['required', 'date_format:Y-m-d'],
            'nomor_sip'         => ['nullable', 'string', 'max:50'],
            'sip_berlaku_sampai' => ['nullable', 'date_format:Y-m-d'],
            'pengalaman_tahun'  => ['nullable', 'integer', 'min:0', 'max:65535'],
            'biaya_konsultasi_online' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
