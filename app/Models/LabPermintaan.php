<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `lab_permintaan` table.
 *
 * Source: telemedicine_test.sql:876.
 *
 * @property int|null $id
 * @property string|null $nomor_permintaan
 * @property int|null $rekam_medis_id
 * @property int|null $konsultasi_id
 * @property int|null $pasien_id
 * @property int|null $dokter_id
 * @property int|null $faskes_lab_id
 * @property string|null $status
 * @property string|null $catatan_klinis
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read Collection<int, LabHasil> $labHasil
 * @property-read Pasien $pasien
 * @property-read Dokter $dokter
 * @property-read Faskes $faskesLab
 * @property-read Collection<int, LabPermintaanDetail> $labPermintaanDetail
 */
class LabPermintaan extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'lab_permintaan';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

    /**
     * @return HasMany<LabHasil, $this>
     */
    public function labHasil(): HasMany
    {
        return $this->hasMany(LabHasil::class, 'lab_permintaan_id');
    }

    /**
     * @return BelongsTo<Pasien, $this>
     */
    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    /**
     * @return BelongsTo<Dokter, $this>
     */
    public function dokter(): BelongsTo
    {
        return $this->belongsTo(Dokter::class, 'dokter_id');
    }

    /**
     * @return BelongsTo<Faskes, $this>
     */
    public function faskesLab(): BelongsTo
    {
        return $this->belongsTo(Faskes::class, 'faskes_lab_id');
    }

    /**
     * @return HasMany<LabPermintaanDetail, $this>
     */
    public function labPermintaanDetail(): HasMany
    {
        return $this->hasMany(LabPermintaanDetail::class, 'lab_permintaan_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'string',
            'catatan_klinis' => 'string',
        ];
    }
}
