<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardsMedicalRecordRead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `rekam_medis_persetujuan` table.
 *
 * Source: telemedicine_test.sql:692.
 *
 * @property int|null $id
 * @property int|null $rekam_medis_id
 * @property string|null $tipe
 * @property string|null $isi_persetujuan
 * @property string|null $ditandatangani_oleh
 * @property string|null $hubungan_dengan_pasien
 * @property string|null $tanda_tangan_url
 * @property Carbon|null $ditandatangani_at
 * @property-read RekamMedis $rekamMedis
 */
class RekamMedisPersetujuan extends Model
{
    use GuardsMedicalRecordRead;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'rekam_medis_persetujuan';

    /**
     * Indicates if the model should be timestamped.
     *
     * `rekam_medis_persetujuan` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
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
            'tipe' => 'string',
            'isi_persetujuan' => 'string',
            'ditandatangani_at' => 'datetime',
        ];
    }
}
