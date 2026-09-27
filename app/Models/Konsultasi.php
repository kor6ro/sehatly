<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `konsultasi` table.
 *
 * Source: telemedicine_test.sql:536.
 *
 * @property int|null $id
 * @property int|null $booking_id
 * @property int|null $pasien_id
 * @property int|null $dokter_id
 * @property string|null $tipe
 * @property string|null $status
 * @property string|null $room_id
 * @property Carbon|null $mulai_at
 * @property Carbon|null $selesai_at
 * @property int|null $total_durasi_detik
 * @property string|null $catatan_subjektif
 * @property string|null $catatan_objektif
 * @property string|null $catatan_asessment
 * @property string|null $catatan_plan
 * @property string|null $diagnosis_kerja
 * @property string|null $saran_tindak_lanjut
 * @property string|null $biaya_konsultasi
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read Booking $booking
 * @property-read Pasien $pasien
 * @property-read Dokter $dokter
 * @property-read Collection<int, KonsultasiChat> $konsultasiChat
 * @property-read Collection<int, RekamMedis> $rekamMedis
 */
class Konsultasi extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'konsultasi';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
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
     * @return HasMany<KonsultasiChat, $this>
     */
    public function konsultasiChat(): HasMany
    {
        return $this->hasMany(KonsultasiChat::class, 'konsultasi_id');
    }

    /**
     * @return HasMany<RekamMedis, $this>
     */
    public function rekamMedis(): HasMany
    {
        return $this->hasMany(RekamMedis::class, 'konsultasi_id');
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
            'mulai_at' => 'datetime',
            'selesai_at' => 'datetime',
            'catatan_subjektif' => 'string',
            'catatan_objektif' => 'string',
            'catatan_asessment' => 'string',
            'catatan_plan' => 'string',
            'saran_tindak_lanjut' => 'string',
            'biaya_konsultasi' => 'decimal:2',
        ];
    }
}
