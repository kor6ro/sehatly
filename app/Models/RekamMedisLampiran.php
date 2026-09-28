<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardsMedicalRecordRead;
use App\Models\Concerns\RefusesHardDelete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `rekam_medis_lampiran` table.
 *
 * Source: telemedicine_test.sql:681.
 *
 * @property int|null $id
 * @property int|null $rekam_medis_id
 * @property string|null $nama_file
 * @property string|null $file_url
 * @property string|null $tipe
 * @property int|null $diunggah_oleh
 * @property Carbon|null $dibuat_at
 * @property-read RekamMedis $rekamMedis
 */
class RekamMedisLampiran extends Model
{
    use GuardsMedicalRecordRead;
    use RefusesHardDelete;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'rekam_medis_lampiran';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `rekam_medis_lampiran` declares no `diubah_at`, and Eloquent writes this constant on every
     * save, so it is nulled rather than left as the inherited `updated_at`.
     */
    public const UPDATED_AT = null;

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
        ];
    }
}
