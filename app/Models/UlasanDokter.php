<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `ulasan_dokter` table.
 *
 * Source: telemedicine_test.sql:1050.
 *
 * @property int|null $id
 * @property int|null $konsultasi_id
 * @property int|null $pasien_id
 * @property int|null $dokter_id
 * @property int|null $rating
 * @property int|null $rating_komunikasi
 * @property int|null $rating_akurasi
 * @property string|null $isi
 * @property bool|null $is_anonim
 * @property string|null $balasan_dokter
 * @property Carbon|null $dibalas_at
 * @property Carbon|null $dibuat_at
 * @property-read Pasien $pasien
 * @property-read Dokter $dokter
 */
class UlasanDokter extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ulasan_dokter';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `ulasan_dokter` declares no `diubah_at`, and Eloquent writes this constant on every
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
            'isi' => 'string',
            'is_anonim' => 'boolean',
            'balasan_dokter' => 'string',
            'dibalas_at' => 'datetime',
        ];
    }
}
