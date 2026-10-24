<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\Artikel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Layanan bisnis untuk CRUD artikel portal marketing.
 *
 * Tabel `artikel` (telemedicine_test.sql:1074) tidak punya `deleted_at`,
 * oleh sebab itu "hapus" di sini berarti mengatur `status = 'arsip'`,
 * bukan barisan benar-benar dihapus dari basis data.
 */
class ArtikelService
{
    /**
     * Daftar artikel, paling baru dulu, dengan kategori eager-loaded.
     */
    public function index(): Builder
    {
        return Artikel::query()
            ->with('kategori')
            ->orderBy('dibuat_at', 'desc');
    }

    /**
     * Satu artikel menurut id, atau 404.
     */
    public function cari(int $id): Artikel
    {
        $artikel = Artikel::query()->with('kategori')->whereKey($id)->first();

        if ($artikel === null) {
            throw (new ModelNotFoundException)->setModel(Artikel::class, [$id]);
        }

        return $artikel;
    }

    /**
     * Buat artikel baru. `penulis_user_id` diisi dari akun yang terautentikasi
     * dan tidak boleh dikirim oleh klien.
     */
    public function buat(array $data, int $penulisUserId): Artikel
    {
        $artikel = new Artikel;
        $artikel->kategori_id = (int) $data['kategori_id'];
        $artikel->penulis_user_id = $penulisUserId;
        $artikel->judul = $data['judul'];
        $artikel->slug = $data['slug'];
        $artikel->ringkasan = $data['ringkasan'] ?? null;
        $artikel->konten = $data['konten'];
        $artikel->cover_url = $data['cover_url'] ?? null;
        $artikel->status = $data['status'] ?? 'draft';
        $artikel->save();

        return $artikel;
    }

    /**
     * Perbarui artikel yang sudah ada.
     */
    public function ubah(Artikel $artikel, array $data): Artikel
    {
        if (isset($data['kategori_id'])) {
            $artikel->kategori_id = (int) $data['kategori_id'];
        }
        if (isset($data['judul'])) {
            $artikel->judul = $data['judul'];
        }
        if (isset($data['slug'])) {
            $artikel->slug = $data['slug'];
        }
        if (array_key_exists('ringkasan', $data)) {
            $artikel->ringkasan = $data['ringkasan'] ?? null;
        }
        if (isset($data['konten'])) {
            $artikel->konten = $data['konten'];
        }
        if (array_key_exists('cover_url', $data)) {
            $artikel->cover_url = $data['cover_url'] ?? null;
        }
        if (isset($data['status'])) {
            $artikel->status = $data['status'];
        }
        $artikel->save();

        return $artikel;
    }

    /**
     * "Hapus" artikel dengan mengarsipkannya: `status = 'arsip'`.
     * Tidak ada hard delete dan tidak ada `deleted_at` pada tabel ini.
     */
    public function arsipkan(Artikel $artikel): void
    {
        $artikel->status = 'arsip';
        $artikel->save();
    }
}
