<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the `master_spesialisasi` table.
 *
 * Source: telemedicine_test.sql:402.
 *
 * @property int|null $id
 * @property string|null $kode
 * @property string|null $nama
 * @property string|null $tipe
 * @property-read Collection<int, DokterSpesialisasi> $dokterSpesialisasi
 */
class MasterSpesialisasi extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_spesialisasi';

    /**
     * Indicates if the model should be timestamped.
     *
     * `master_spesialisasi` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return HasMany<DokterSpesialisasi, $this>
     */
    public function dokterSpesialisasi(): HasMany
    {
        return $this->hasMany(DokterSpesialisasi::class, 'spesialisasi_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipe' => 'string',
        ];
    }
}
