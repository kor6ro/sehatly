<?php

declare(strict_types=1);

namespace App\Http\Resources\Marketing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu promo sebagai yang dikelola oleh portal marketing.
 *
 * Semua kolom diunggah karena halaman manajemen promo perlu menampilkan
 * kode, nama, tipe diskon, nilai, kuota, jendela waktu, dan apakah promo
 * masih aktif. Tidak ada relasi eager-load karena `promo_redemption`
 * adalah catatan transaksional yang tidak perlu ditampilkan pada daftar.
 */
class PromoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'kode' => (string) $this->resource->kode,
            'nama' => (string) $this->resource->nama,
            'tipe_diskon' => (string) $this->resource->tipe_diskon,
            'nilai' => (string) $this->resource->nilai,
            'min_transaksi' => (string) $this->resource->min_transaksi,
            'maks_diskon' => $this->resource->maks_diskon === null ? null : (string) $this->resource->maks_diskon,
            'kuota_total' => $this->resource->kuota_total === null ? null : (int) $this->resource->kuota_total,
            'kuota_per_user' => (int) $this->resource->kuota_per_user,
            'mulai_at' => $this->resource->mulai_at?->toISOString(),
            'selesai_at' => $this->resource->selesai_at?->toISOString(),
            'status_aktif' => (bool) $this->resource->status_aktif,
            'dibuat_at' => $this->resource->dibuat_at?->toISOString(),
            'diubah_at' => $this->resource->diubah_at?->toISOString(),
        ];
    }
}
