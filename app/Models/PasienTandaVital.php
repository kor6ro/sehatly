<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `pasien_tanda_vital` table.
 *
 * Source: telemedicine_test.sql:312.
 *
 * @property int|null $id
 * @property int|null $pasien_id
 * @property int|null $rekam_medis_id
 * @property int|null $sistolik
 * @property int|null $diastolik
 * @property int|null $nadi
 * @property string|null $suhu
 * @property int|null $laju_pernapasan
 * @property int|null $spo2
 * @property string|null $tinggi_cm
 * @property string|null $berat_kg
 * @property string|null $glukosa_darah
 * @property string|null $sumber
 * @property Carbon|null $diukur_at
 * @property Carbon|null $dibuat_at
 * @property-read Pasien $pasien
 * @property-read RekamMedis $rekamMedis
 */
class PasienTandaVital extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pasien_tanda_vital';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `pasien_tanda_vital` declares no `diubah_at`, and Eloquent writes this constant on every
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
     * @return BelongsTo<RekamMedis, $this>
     */
    public function rekamMedis(): BelongsTo
    {
        return $this->belongsTo(RekamMedis::class, 'rekam_medis_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suhu' => 'decimal:1',
            'tinggi_cm' => 'decimal:1',
            'berat_kg' => 'decimal:2',
            'glukosa_darah' => 'decimal:1',
            'sumber' => 'string',
            'diukur_at' => 'datetime',
        ];
    }
}
