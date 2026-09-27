<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `dokter_pendidikan` table.
 *
 * Source: telemedicine_test.sql:457.
 *
 * @property int|null $id
 * @property int|null $dokter_id
 * @property string|null $jenjang
 * @property string|null $institusi
 * @property int|null $tahun_lulus
 * @property-read Dokter $dokter
 */
class DokterPendidikan extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dokter_pendidikan';

    /**
     * Indicates if the model should be timestamped.
     *
     * `dokter_pendidikan` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jenjang' => 'string',
        ];
    }
}
