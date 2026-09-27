<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `pasien_alergi` table.
 *
 * Source: telemedicine_test.sql:274.
 *
 * @property int|null $id
 * @property int|null $pasien_id
 * @property string|null $tipe_alergen
 * @property string|null $nama_alergen
 * @property string|null $reaksi
 * @property string|null $keparahan
 * @property int|null $dicatat_oleh_user_id
 * @property Carbon|null $dibuat_at
 * @property-read Pasien $pasien
 */
class PasienAlergi extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pasien_alergi';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `pasien_alergi` declares no `diubah_at`, and Eloquent writes this constant on every
     * save, so it is nulled rather than left as the inherited `updated_at`.
     */
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Pasien, $this>
     */
    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipe_alergen' => 'string',
            'keparahan' => 'string',
        ];
    }
}
