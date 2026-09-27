<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the `master_provinsi` table.
 *
 * Source: telemedicine_test.sql:58.
 *
 * @property int|null $id
 * @property string|null $kode
 * @property string|null $nama
 * @property-read Collection<int, Faskes> $faskes
 * @property-read Collection<int, MasterKabupatenKota> $masterKabupatenKota
 */
class MasterProvinsi extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_provinsi';

    /**
     * Indicates if the model should be timestamped.
     *
     * `master_provinsi` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
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
        return $this->hasMany(Faskes::class, 'provinsi_id');
    }

    /**
     * @return HasMany<MasterKabupatenKota, $this>
     */
    public function masterKabupatenKota(): HasMany
    {
        return $this->hasMany(MasterKabupatenKota::class, 'provinsi_id');
    }
}
