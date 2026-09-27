<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `dokter_spesialisasi` table.
 *
 * Source: telemedicine_test.sql:437.
 *
 * @property int|null $id
 * @property int|null $dokter_id
 * @property int|null $spesialisasi_id
 * @property bool|null $is_utama
 * @property-read Dokter $dokter
 * @property-read MasterSpesialisasi $spesialisasi
 */
class DokterSpesialisasi extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dokter_spesialisasi';

    /**
     * Indicates if the model should be timestamped.
     *
     * `dokter_spesialisasi` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return BelongsTo<Dokter, $this>
     */
    public function dokter(): BelongsTo
    {
        return $this->belongsTo(Dokter::class, 'dokter_id');
    }

    /**
     * @return BelongsTo<MasterSpesialisasi, $this>
     */
    public function spesialisasi(): BelongsTo
    {
        return $this->belongsTo(MasterSpesialisasi::class, 'spesialisasi_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_utama' => 'boolean',
        ];
    }
}
