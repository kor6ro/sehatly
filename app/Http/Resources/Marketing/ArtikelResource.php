<?php

declare(strict_types=1);

namespace App\Http\Resources\Marketing;

use App\Models\Artikel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu artikel sebagai yang diterbitkan oleh portal marketing.
 *
 * Sengaja menyertakan `kategori` (nama dan slug) karena halaman daftar
 * artikel perlu menggolongkan konten, dan menyertakan `penulis_user_id`
 * sehingga portal tahu siapa yang menulis. Tidak menyertakan
 * `reviewer_user_id` karena revisi medis adalah urusan internal tim medis,
 * bukan konten yang dibaca klien marketing.
 */
class ArtikelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'kategori_id' => (int) $this->resource->kategori_id,
            'kategori' => $this->kategori(),
            'penulis_user_id' => (int) $this->resource->penulis_user_id,
            'judul' => (string) $this->resource->judul,
            'slug' => (string) $this->resource->slug,
            'ringkasan' => $this->resource->ringkasan === null ? null : (string) $this->resource->ringkasan,
            'konten' => (string) $this->resource->konten,
            'cover_url' => $this->resource->cover_url === null ? null : (string) $this->resource->cover_url,
            'status' => (string) $this->resource->status,
            'jumlah_view' => (int) $this->resource->jumlah_view,
            'published_at' => $this->resource->published_at?->toISOString(),
            'dibuat_at' => $this->resource->dibuat_at?->toISOString(),
            'diubah_at' => $this->resource->diubah_at?->toISOString(),
        ];
    }

    /**
     * Kategori yang artikel ini termasuk, atau null jika relasi tidak dimuat.
     *
     * @return array<string, mixed>|null
     */
    private function kategori(): ?array
    {
        if (! $this->resource->relationLoaded('kategori') || $this->resource->kategori === null) {
            return null;
        }

        $kategori = $this->resource->kategori;

        return [
            'id' => (int) $kategori->getKey(),
            'nama' => (string) $kategori->nama,
            'slug' => (string) $kategori->slug,
        ];
    }
}
