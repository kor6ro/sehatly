<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /marketing/promo/{id}` - perbarui promo yang sudah ada.
 *
 * Semua field opsional; `slug`-eksivalen (`kode`) mengecualikan barisan itu
 * sendiri dari pemeriksaan unik.
 */
class UpdatePromoRequest extends FormRequest
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
        $id = $this->route('id');

        return [
            'kode' => ['sometimes', 'string', 'max:30', 'unique:master_promo,kode,'.$id],
            'nama' => ['sometimes', 'string', 'max:150'],
            'tipe_diskon' => ['sometimes', 'in:persen,nominal,gratis_ongkir'],
            'nilai' => ['sometimes', 'numeric'],
            'min_transaksi' => ['nullable', 'numeric'],
            'maks_diskon' => ['nullable', 'numeric'],
            'kuota_total' => ['nullable', 'integer'],
            'kuota_per_user' => ['nullable', 'integer'],
            'mulai_at' => ['sometimes', 'date_format:Y-m-d H:i:s'],
            'selesai_at' => ['sometimes', 'date_format:Y-m-d H:i:s'],
            'status_aktif' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kode' => 'kode promo',
            'nama' => 'nama promo',
            'tipe_diskon' => 'tipe diskon',
            'nilai' => 'nilai diskon',
            'min_transaksi' => 'minimum transaksi',
            'maks_diskon' => 'maksimal diskon',
            'kuota_total' => 'kuota total',
            'kuota_per_user' => 'kuota per pengguna',
            'mulai_at' => 'mulai berlaku',
            'selesai_at' => 'selesai berlaku',
            'status_aktif' => 'status aktif',
        ];
    }

    /**
     * @return array{kode?: string, nama?: string, tipe_diskon?: string, nilai?: string|int|float, min_transaksi?: string|int|float|null, maks_diskon?: string|int|float|null, kuota_total?: int|null, kuota_per_user?: int|null, mulai_at?: string, selesai_at?: string, status_aktif?: bool}
     */
    public function isi(): array
    {
        return [
            'kode' => $this->input('kode'),
            'nama' => $this->input('nama'),
            'tipe_diskon' => $this->input('tipe_diskon'),
            'nilai' => $this->input('nilai'),
            'min_transaksi' => $this->input('min_transaksi'),
            'maks_diskon' => $this->input('maks_diskon'),
            'kuota_total' => $this->input('kuota_total'),
            'kuota_per_user' => $this->input('kuota_per_user'),
            'mulai_at' => $this->input('mulai_at'),
            'selesai_at' => $this->input('selesai_at'),
            'status_aktif' => $this->input('status_aktif'),
        ];
    }
}
