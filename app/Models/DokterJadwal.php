<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `dokter_jadwal` table.
 *
 * Source: telemedicine_test.sql:470.
 *
 * @property int|null $id
 * @property int|null $dokter_id
 * @property int|null $faskes_id
 * @property string|null $tipe_layanan
 * @property int|null $hari
 * @property string|null $jam_mulai
 * @property string|null $jam_selesai
 * @property int|null $durasi_slot_menit
 * @property int|null $kuota_per_sesi
 * @property Carbon|null $berlaku_mulai
 * @property Carbon|null $berlaku_sampai
 * @property bool|null $status_aktif
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read Collection<int, Booking> $booking
 * @property-read Dokter $dokter
 * @property-read Faskes $faskes
 */
class DokterJadwal extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dokter_jadwal';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

    /**
     * @return HasMany<Booking, $this>
     */
    public function booking(): HasMany
    {
        return $this->hasMany(Booking::class, 'jadwal_id');
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
    public function faskes(): BelongsTo
    {
        return $this->belongsTo(Faskes::class, 'faskes_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipe_layanan' => 'string',
            'berlaku_mulai' => 'date',
            'berlaku_sampai' => 'date',
            'status_aktif' => 'boolean',
        ];
    }
}
