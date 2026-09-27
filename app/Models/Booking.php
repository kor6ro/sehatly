<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `booking` table.
 *
 * Source: telemedicine_test.sql:498.
 *
 * @property int|null $id
 * @property string|null $nomor_booking
 * @property int|null $pasien_id
 * @property int|null $anggota_keluarga_id
 * @property int|null $dokter_id
 * @property int|null $jadwal_id
 * @property int|null $faskes_id
 * @property string|null $tipe_layanan
 * @property Carbon|null $tanggal_kunjungan
 * @property string|null $slot_mulai
 * @property string|null $slot_selesai
 * @property int|null $nomor_antrian
 * @property string|null $keluhan
 * @property array|null $lampiran_keluhan
 * @property bool|null $is_rujukan
 * @property bool|null $is_konsultasi_lanjutan
 * @property string|null $status
 * @property string|null $dibatalkan_oleh
 * @property string|null $alasan_pembatalan
 * @property int|null $dibuat_oleh_user_id
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read Pasien $pasien
 * @property-read PasienAnggotaKeluarga $anggotaKeluarga
 * @property-read Dokter $dokter
 * @property-read DokterJadwal $jadwal
 * @property-read Faskes $faskes
 * @property-read User $dibuatOlehUser
 * @property-read Konsultasi $konsultasi
 */
class Booking extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'booking';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

    /**
     * @return BelongsTo<Pasien, $this>
     */
    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    /**
     * @return BelongsTo<PasienAnggotaKeluarga, $this>
     */
    public function anggotaKeluarga(): BelongsTo
    {
        return $this->belongsTo(PasienAnggotaKeluarga::class, 'anggota_keluarga_id');
    }

    /**
     * @return BelongsTo<Dokter, $this>
     */
    public function dokter(): BelongsTo
    {
        return $this->belongsTo(Dokter::class, 'dokter_id');
    }

    /**
     * @return BelongsTo<DokterJadwal, $this>
     */
    public function jadwal(): BelongsTo
    {
        return $this->belongsTo(DokterJadwal::class, 'jadwal_id');
    }

    /**
     * @return BelongsTo<Faskes, $this>
     */
    public function faskes(): BelongsTo
    {
        return $this->belongsTo(Faskes::class, 'faskes_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dibuatOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh_user_id');
    }

    /**
     * @return HasOne<Konsultasi, $this>
     */
    public function konsultasi(): HasOne
    {
        return $this->hasOne(Konsultasi::class, 'booking_id');
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
            'tanggal_kunjungan' => 'date',
            'keluhan' => 'string',
            'lampiran_keluhan' => 'array',
            'is_rujukan' => 'boolean',
            'is_konsultasi_lanjutan' => 'boolean',
            'status' => 'string',
            'dibatalkan_oleh' => 'string',
        ];
    }
}
