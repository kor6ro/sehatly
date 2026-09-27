<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the `master_agama` table.
 *
 * Source: telemedicine_test.sql:89.
 *
 * @property int|null $id
 * @property string|null $nama
 * @property-read Collection<int, Pasien> $pasien
 */
class MasterAgama extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_agama';

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
     * `master_agama` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return HasMany<Pasien, $this>
     */
    public function pasien(): HasMany
    {
        return $this->hasMany(Pasien::class, 'agama_id');
    }
}
