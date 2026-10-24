<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\MasterPromo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Layanan bisnis untuk CRUD promo portal marketing.
 *
 * Tabel `master_promo` (telemedicine_test.sql:985) tidak punya `deleted_at`
 * dan tidak punya kolom `status` apa pun, hanya `status_aktif` TINYINT(1).
 * Oleh sebab itu "hapus" di sini berarti `status_aktif = 0` (non-aktif),
 * bukan barisan benar-benar dihapus dari basis data.
 */
class PromoService
{
    /**
     * Daftar promo, paling baru dulu.
     */
    public function index(): Builder
    {
        return MasterPromo::query()->orderBy('mulai_at', 'desc');
    }

    /**
     * Satu promo menurut id, atau 404.
     */
    public function cari(int $id): MasterPromo
    {
        $promo = MasterPromo::query()->whereKey($id)->first();

        if ($promo === null) {
            throw (new ModelNotFoundException)->setModel(MasterPromo::class, [$id]);
        }

        return $promo;
    }

    /**
     * Buat promo baru.
     */
    public function buat(array $data): MasterPromo
    {
        $promo = new MasterPromo;
        $promo->kode = $data['kode'];
        $promo->nama = $data['nama'];
        $promo->tipe_diskon = $data['tipe_diskon'];
        $promo->nilai = $data['nilai'];
        $promo->min_transaksi = $data['min_transaksi'] ?? 0;
        $promo->maks_diskon = $data['maks_diskon'] ?? null;
        $promo->kuota_total = $data['kuota_total'] ?? null;
        $promo->kuota_per_user = $data['kuota_per_user'] ?? 1;
        $promo->mulai_at = $data['mulai_at'];
        $promo->selesai_at = $data['selesai_at'];
        $promo->status_aktif = $data['status_aktif'] ?? true;
        $promo->save();

        return $promo;
    }

    /**
     * Perbarui promo yang sudah ada.
     */
    public function ubah(MasterPromo $promo, array $data): MasterPromo
    {
        if (isset($data['kode'])) {
            $promo->kode = $data['kode'];
        }
        if (isset($data['nama'])) {
            $promo->nama = $data['nama'];
        }
        if (isset($data['tipe_diskon'])) {
            $promo->tipe_diskon = $data['tipe_diskon'];
        }
        if (isset($data['nilai'])) {
            $promo->nilai = $data['nilai'];
        }
        if (array_key_exists('min_transaksi', $data)) {
            $promo->min_transaksi = $data['min_transaksi'] ?? 0;
        }
        if (array_key_exists('maks_diskon', $data)) {
            $promo->maks_diskon = $data['maks_diskon'] ?? null;
        }
        if (array_key_exists('kuota_total', $data)) {
            $promo->kuota_total = $data['kuota_total'] ?? null;
        }
        if (array_key_exists('kuota_per_user', $data)) {
            $promo->kuota_per_user = $data['kuota_per_user'] ?? 1;
        }
        if (isset($data['mulai_at'])) {
            $promo->mulai_at = $data['mulai_at'];
        }
        if (isset($data['selesai_at'])) {
            $promo->selesai_at = $data['selesai_at'];
        }
        if (array_key_exists('status_aktif', $data)) {
            $promo->status_aktif = (bool) $data['status_aktif'];
        }
        $promo->save();

        return $promo;
    }

    /**
     * "Hapus" promo dengan menonaktifkannya: `status_aktif = 0`.
     * Tidak ada hard delete dan tidak ada `deleted_at` pada tabel ini.
     */
    public function nonaktifkan(MasterPromo $promo): void
    {
        $promo->status_aktif = false;
        $promo->save();
    }
}
