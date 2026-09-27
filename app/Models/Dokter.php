<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `dokter` table.
 *
 * Source: telemedicine_test.sql:409.
 *
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $tipe
 * @property string|null $nomor_str
 * @property Carbon|null $str_berlaku_sampai
 * @property string|null $nomor_sip
 * @property Carbon|null $sip_berlaku_sampai
 * @property string|null $nomor_ihs_satusehat
 * @property int|null $pengalaman_tahun
 * @property string|null $bio
 * @property string|null $biaya_konsultasi_online
 * @property string|null $biaya_luar_jam
 * @property int|null $durasi_default_menit
 * @property string|null $rating_rata_rata
 * @property int|null $jumlah_ulasan
 * @property int|null $jumlah_konsultasi
 * @property bool|null $tersedia_telemedisin
 * @property string|null $status_verifikasi
 * @property string|null $file_str_url
 * @property string|null $file_sip_url
 * @property bool|null $status_aktif
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read Collection<int, Booking> $booking
 * @property-read User $user
 * @property-read Collection<int, DokterFaskes> $dokterFaskes
 * @property-read Collection<int, DokterJadwal> $dokterJadwal
 * @property-read Collection<int, DokterLibur> $dokterLibur
 * @property-read Collection<int, DokterPendidikan> $dokterPendidikan
 * @property-read Collection<int, DokterSpesialisasi> $dokterSpesialisasi
 * @property-read Collection<int, HomeCarePesanan> $homeCarePesanan
 * @property-read Collection<int, Konsultasi> $konsultasi
 * @property-read Collection<int, LabPermintaan> $labPermintaan
 * @property-read Collection<int, RekamMedis> $rekamMedis
 * @property-read Collection<int, RekamMedisTindakan> $rekamMedisTindakan
 * @property-read Collection<int, Resep> $resep
 * @property-read Collection<int, Rujukan> $rujukan
 * @property-read Collection<int, SuratKeterangan> $suratKeterangan
 * @property-read Collection<int, UlasanDokter> $ulasanDokter
 */
class Dokter extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dokter';

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
        return $this->hasMany(Booking::class, 'dokter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<DokterFaskes, $this>
     */
    public function dokterFaskes(): HasMany
    {
        return $this->hasMany(DokterFaskes::class, 'dokter_id');
    }

    /**
     * @return HasMany<DokterJadwal, $this>
     */
    public function dokterJadwal(): HasMany
    {
        return $this->hasMany(DokterJadwal::class, 'dokter_id');
    }

    /**
     * @return HasMany<DokterLibur, $this>
     */
    public function dokterLibur(): HasMany
    {
        return $this->hasMany(DokterLibur::class, 'dokter_id');
    }

    /**
     * @return HasMany<DokterPendidikan, $this>
     */
    public function dokterPendidikan(): HasMany
    {
        return $this->hasMany(DokterPendidikan::class, 'dokter_id');
    }

    /**
     * @return HasMany<DokterSpesialisasi, $this>
     */
    public function dokterSpesialisasi(): HasMany
    {
        return $this->hasMany(DokterSpesialisasi::class, 'dokter_id');
    }

    /**
     * @return HasMany<HomeCarePesanan, $this>
     */
    public function homeCarePesanan(): HasMany
    {
        return $this->hasMany(HomeCarePesanan::class, 'tenaga_medis_id');
    }

    /**
     * @return HasMany<Konsultasi, $this>
     */
    public function konsultasi(): HasMany
    {
        return $this->hasMany(Konsultasi::class, 'dokter_id');
    }

    /**
     * @return HasMany<LabPermintaan, $this>
     */
    public function labPermintaan(): HasMany
    {
        return $this->hasMany(LabPermintaan::class, 'dokter_id');
    }

    /**
     * @return HasMany<RekamMedis, $this>
     */
    public function rekamMedis(): HasMany
    {
        return $this->hasMany(RekamMedis::class, 'dokter_id');
    }

    /**
     * @return HasMany<RekamMedisTindakan, $this>
     */
    public function rekamMedisTindakan(): HasMany
    {
        return $this->hasMany(RekamMedisTindakan::class, 'dokter_pelaksana_id');
    }

    /**
     * @return HasMany<Resep, $this>
     */
    public function resep(): HasMany
    {
        return $this->hasMany(Resep::class, 'dokter_id');
    }

    /**
     * @return HasMany<Rujukan, $this>
     */
    public function rujukan(): HasMany
    {
        return $this->hasMany(Rujukan::class, 'dokter_perujuk_id');
    }

    /**
     * @return HasMany<SuratKeterangan, $this>
     */
    public function suratKeterangan(): HasMany
    {
        return $this->hasMany(SuratKeterangan::class, 'dokter_id');
    }

    /**
     * @return HasMany<UlasanDokter, $this>
     */
    public function ulasanDokter(): HasMany
    {
        return $this->hasMany(UlasanDokter::class, 'dokter_id');
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
            'str_berlaku_sampai' => 'date',
            'sip_berlaku_sampai' => 'date',
            'bio' => 'string',
            'biaya_konsultasi_online' => 'decimal:2',
            'biaya_luar_jam' => 'decimal:2',
            'rating_rata_rata' => 'decimal:2',
            'tersedia_telemedisin' => 'boolean',
            'status_verifikasi' => 'string',
            'status_aktif' => 'boolean',
        ];
    }
}
