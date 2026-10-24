<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /marketing/promo` - buat kode promo baru di portal marketing.
 *
 * Semua lebar dan tipe mengikuti DDL `master_promo`
 * (`telemedicine_test.sql:985`-:998). `status_aktif` default `true` saat
 * tidak dikirim; `kuota_total` dan `maks_diskon` nullable berarti
 * "tidak terbatas".
 */
class StorePromoRequest extends FormRequest
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
            'kode' => ['required', 'string', 'max:30', 'unique:master_promo,kode'],
            'nama' => ['required', 'string', 'max:150'],
            'tipe_diskon' => ['required', 'in:persen,nominal,gratis_ongkir'],
            'nilai' => ['required', 'numeric'],
            'min_transaksi' => ['nullable', 'numeric'],
            'maks_diskon' => ['nullable', 'numeric'],
            'kuota_total' => ['nullable', 'integer'],
            'kuota_per_user' => ['nullable', 'integer'],
            'mulai_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'selesai_at' => ['required', 'date_format:Y-m-d H:i:s'],
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
     * @return array{kode: string, nama: string, tipe_diskon: string, nilai: string|int|float, min_transaksi?: string|int|float|null, maks_diskon?: string|int|float|null, kuota_total?: int|null, kuota_per_user?: int|null, mulai_at: string, selesai_at: string, status_aktif?: bool}
     */
    public function isi(): array
    {
        return [
            'kode' => (string) $this->input('kode'),
            'nama' => (string) $this->input('nama'),
            'tipe_diskon' => (string) $this->input('tipe_diskon'),
            'nilai' => $this->input('nilai'),
            'min_transaksi' => $this->input('min_transaksi'),
            'maks_diskon' => $this->input('maks_diskon'),
            'kuota_total' => $this->input('kuota_total'),
            'kuota_per_user' => $this->input('kuota_per_user'),
            'mulai_at' => (string) $this->input('mulai_at'),
            'selesai_at' => (string) $this->input('selesai_at'),
            'status_aktif' => $this->boolean('status_aktif'),
        ];
    }
}
