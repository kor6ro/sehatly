<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `pasien_anggota_keluarga` table.
 *
 * Source: telemedicine_test.sql:259.
 *
 * @property int|null $id
 * @property int|null $pasien_id
 * @property int|null $hubungan_id
 * @property string|null $nik
 * @property string|null $nama_lengkap
 * @property string|null $jenis_kelamin
 * @property Carbon|null $tanggal_lahir
 * @property string|null $no_telepon
 * @property string|null $catatan_alergi
 * @property Carbon|null $dibuat_at
 * @property-read Collection<int, Booking> $booking
 * @property-read Collection<int, HomeCarePesanan> $homeCarePesanan
 * @property-read Pasien $pasien
 * @property-read MasterHubunganKeluarga $hubungan
 */
class PasienAnggotaKeluarga extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pasien_anggota_keluarga';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `pasien_anggota_keluarga` declares no `diubah_at`, and Eloquent writes this constant on every
     * save, so it is nulled rather than left as the inherited `updated_at`.
     */
    public const UPDATED_AT = null;

    /**
     * @return HasMany<Booking, $this>
     */
    public function booking(): HasMany
    {
        return $this->hasMany(Booking::class, 'anggota_keluarga_id');
    }

    /**
     * @return HasMany<HomeCarePesanan, $this>
     */
    public function homeCarePesanan(): HasMany
    {
        return $this->hasMany(HomeCarePesanan::class, 'anggota_keluarga_id');
    }

    /**
     * @return BelongsTo<Pasien, $this>
     */
    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    /**
     * @return BelongsTo<MasterHubunganKeluarga, $this>
     */
    public function hubungan(): BelongsTo
    {
        return $this->belongsTo(MasterHubunganKeluarga::class, 'hubungan_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jenis_kelamin' => 'string',
            'tanggal_lahir' => 'date',
            'catatan_alergi' => 'string',
        ];
    }
}
