<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `pasien_riwayat_penyakit` table.
 *
 * Source: telemedicine_test.sql:286.
 *
 * @property int|null $id
 * @property int|null $pasien_id
 * @property string|null $tipe
 * @property string|null $nama_penyakit
 * @property string|null $icd10_kode
 * @property int|null $tahun_terdiagnosis
 * @property string|null $status
 * @property string|null $keterangan
 * @property Carbon|null $dibuat_at
 * @property-read Pasien $pasien
 */
class PasienRiwayatPenyakit extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pasien_riwayat_penyakit';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `pasien_riwayat_penyakit` declares no `diubah_at`, and Eloquent writes this constant on every
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
            'tipe' => 'string',
            'status' => 'string',
            'keterangan' => 'string',
        ];
    }
}
