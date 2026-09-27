<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `rekam_medis_tindakan` table.
 *
 * Source: telemedicine_test.sql:669.
 *
 * @property int|null $id
 * @property int|null $rekam_medis_id
 * @property string|null $icd9cm_kode
 * @property string|null $nama_tindakan
 * @property string|null $keterangan
 * @property Carbon|null $tanggal_tindakan
 * @property int|null $dokter_pelaksana_id
 * @property-read RekamMedis $rekamMedis
 * @property-read Dokter $dokterPelaksana
 */
class RekamMedisTindakan extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'rekam_medis_tindakan';

    /**
     * Indicates if the model should be timestamped.
     *
     * `rekam_medis_tindakan` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
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
     * @return BelongsTo<Dokter, $this>
     */
    public function dokterPelaksana(): BelongsTo
    {
        return $this->belongsTo(Dokter::class, 'dokter_pelaksana_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'keterangan' => 'string',
            'tanggal_tindakan' => 'datetime',
        ];
    }
}
