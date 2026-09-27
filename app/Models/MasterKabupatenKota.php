<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the `master_kabupaten_kota` table.
 *
 * Source: telemedicine_test.sql:64.
 *
 * @property int|null $id
 * @property int|null $provinsi_id
 * @property string|null $kode
 * @property string|null $nama
 * @property-read Collection<int, Faskes> $faskes
 * @property-read MasterProvinsi $provinsi
 * @property-read Collection<int, MasterKecamatan> $masterKecamatan
 */
class MasterKabupatenKota extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_kabupaten_kota';

    /**
     * Indicates if the model should be timestamped.
     *
     * `master_kabupaten_kota` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return HasMany<Faskes, $this>
     */
    public function faskes(): HasMany
    {
        return $this->hasMany(Faskes::class, 'kabupaten_kota_id');
    }

    /**
     * @return BelongsTo<MasterProvinsi, $this>
     */
    public function provinsi(): BelongsTo
    {
        return $this->belongsTo(MasterProvinsi::class, 'provinsi_id');
    }

    /**
     * @return HasMany<MasterKecamatan, $this>
     */
    public function masterKecamatan(): HasMany
    {
        return $this->hasMany(MasterKecamatan::class, 'kabupaten_kota_id');
    }
}
