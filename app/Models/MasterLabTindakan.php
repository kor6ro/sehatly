<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the `master_lab_tindakan` table.
 *
 * Source: telemedicine_test.sql:847.
 *
 * @property int|null $id
 * @property string|null $kode
 * @property string|null $nama
 * @property string|null $kelompok
 * @property string|null $satuan
 * @property string|null $nilai_rujukan_laki
 * @property string|null $nilai_rujukan_perempuan
 * @property string|null $kode_loinc
 * @property string|null $harga
 * @property bool|null $status_aktif
 * @property-read Collection<int, LabHasil> $labHasil
 * @property-read Collection<int, LabPaketItem> $labPaketItem
 * @property-read Collection<int, LabPermintaanDetail> $labPermintaanDetail
 */
class MasterLabTindakan extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_lab_tindakan';

    /**
     * Indicates if the model should be timestamped.
     *
     * `master_lab_tindakan` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return HasMany<LabHasil, $this>
     */
    public function labHasil(): HasMany
    {
        return $this->hasMany(LabHasil::class, 'tindakan_id');
    }

    /**
     * @return HasMany<LabPaketItem, $this>
     */
    public function labPaketItem(): HasMany
    {
        return $this->hasMany(LabPaketItem::class, 'tindakan_id');
    }

    /**
     * @return HasMany<LabPermintaanDetail, $this>
     */
    public function labPermintaanDetail(): HasMany
    {
        return $this->hasMany(LabPermintaanDetail::class, 'tindakan_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kelompok' => 'string',
            'harga' => 'decimal:2',
            'status_aktif' => 'boolean',
        ];
    }
}
