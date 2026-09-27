<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `rekam_medis_diagnosa` table.
 *
 * Source: telemedicine_test.sql:657.
 *
 * @property int|null $id
 * @property int|null $rekam_medis_id
 * @property string|null $icd10_kode
 * @property string|null $deskripsi
 * @property string|null $jenis
 * @property string|null $tipe_kasus
 * @property bool|null $is_terkonfirmasi
 * @property-read RekamMedis $rekamMedis
 */
class RekamMedisDiagnosa extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'rekam_medis_diagnosa';

    /**
     * Indicates if the model should be timestamped.
     *
     * `rekam_medis_diagnosa` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

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
            'jenis' => 'string',
            'tipe_kasus' => 'string',
            'is_terkonfirmasi' => 'boolean',
        ];
    }
}
