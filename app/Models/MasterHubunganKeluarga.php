<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the `master_hubungan_keluarga` table.
 *
 * Source: telemedicine_test.sql:109.
 *
 * @property int|null $id
 * @property string|null $nama
 * @property-read Collection<int, PasienAnggotaKeluarga> $pasienAnggotaKeluarga
 */
class MasterHubunganKeluarga extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_hubungan_keluarga';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * The DDL declares `id id PRIMARY KEY` with no AUTO_INCREMENT, so the
     * seeder supplies the id.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Indicates if the model should be timestamped.
     *
     * `master_hubungan_keluarga` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return HasMany<PasienAnggotaKeluarga, $this>
     */
    public function pasienAnggotaKeluarga(): HasMany
    {
        return $this->hasMany(PasienAnggotaKeluarga::class, 'hubungan_id');
    }
}
