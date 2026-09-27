<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the `master_kecamatan` table.
 *
 * Source: telemedicine_test.sql:72.
 *
 * @property int|null $id
 * @property int|null $kabupaten_kota_id
 * @property string|null $kode
 * @property string|null $nama
 * @property-read Collection<int, Faskes> $faskes
 * @property-read MasterKabupatenKota $kabupatenKota
 * @property-read Collection<int, MasterKelurahan> $masterKelurahan
 */
class MasterKecamatan extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_kecamatan';

    /**
     * Indicates if the model should be timestamped.
     *
     * `master_kecamatan` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
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
        return $this->hasMany(Faskes::class, 'kecamatan_id');
    }

    /**
     * @return BelongsTo<MasterKabupatenKota, $this>
     */
    public function kabupatenKota(): BelongsTo
    {
        return $this->belongsTo(MasterKabupatenKota::class, 'kabupaten_kota_id');
    }

    /**
     * @return HasMany<MasterKelurahan, $this>
     */
    public function masterKelurahan(): HasMany
    {
        return $this->hasMany(MasterKelurahan::class, 'kecamatan_id');
    }
}
