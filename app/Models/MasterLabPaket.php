<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the `master_lab_paket` table.
 *
 * Source: telemedicine_test.sql:860.
 *
 * @property int|null $id
 * @property string|null $nama
 * @property string|null $deskripsi
 * @property string|null $harga
 * @property bool|null $status_aktif
 * @property-read Collection<int, LabPaketItem> $labPaketItem
 * @property-read Collection<int, LabPermintaanDetail> $labPermintaanDetail
 */
class MasterLabPaket extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_lab_paket';

    /**
     * Indicates if the model should be timestamped.
     *
     * `master_lab_paket` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return HasMany<LabPaketItem, $this>
     */
    public function labPaketItem(): HasMany
    {
        return $this->hasMany(LabPaketItem::class, 'paket_id');
    }

    /**
     * @return HasMany<LabPermintaanDetail, $this>
     */
    public function labPermintaanDetail(): HasMany
    {
        return $this->hasMany(LabPermintaanDetail::class, 'paket_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deskripsi' => 'string',
            'harga' => 'decimal:2',
            'status_aktif' => 'boolean',
        ];
    }
}
