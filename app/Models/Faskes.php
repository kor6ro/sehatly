<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `faskes` table.
 *
 * Source: telemedicine_test.sql:360.
 *
 * @property int|null $id
 * @property string|null $kode_faskes
 * @property string|null $satusehat_org_id
 * @property string|null $nama
 * @property string|null $tipe
 * @property string|null $kelas_rs
 * @property string|null $alamat
 * @property int|null $provinsi_id
 * @property int|null $kabupaten_kota_id
 * @property int|null $kecamatan_id
 * @property string|null $kode_pos
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $telepon
 * @property string|null $email
 * @property string|null $akreditasi
 * @property array|null $jam_operasional
 * @property bool|null $status_aktif
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read Collection<int, ApotekStok> $apotekStok
 * @property-read Collection<int, Booking> $booking
 * @property-read Collection<int, DokterFaskes> $dokterFaskes
 * @property-read Collection<int, DokterJadwal> $dokterJadwal
 * @property-read MasterProvinsi $provinsi
 * @property-read MasterKabupatenKota $kabupatenKota
 * @property-read MasterKecamatan $kecamatan
 * @property-read Collection<int, FaskesLayanan> $faskesLayanan
 * @property-read Collection<int, LabPermintaan> $labPermintaan
 * @property-read Collection<int, PesananObat> $pesananObat
 * @property-read Collection<int, RekamMedis> $rekamMedis
 * @property-read Collection<int, Resep> $resep
 * @property-read Collection<int, Rujukan> $rujukan
 */
class Faskes extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'faskes';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

    /**
     * @return HasMany<ApotekStok, $this>
     */
    public function apotekStok(): HasMany
    {
        return $this->hasMany(ApotekStok::class, 'apotek_id');
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function booking(): HasMany
    {
        return $this->hasMany(Booking::class, 'faskes_id');
    }

    /**
     * @return HasMany<DokterFaskes, $this>
     */
    public function dokterFaskes(): HasMany
    {
        return $this->hasMany(DokterFaskes::class, 'faskes_id');
    }

    /**
     * @return HasMany<DokterJadwal, $this>
     */
    public function dokterJadwal(): HasMany
    {
        return $this->hasMany(DokterJadwal::class, 'faskes_id');
    }

    /**
     * @return BelongsTo<MasterProvinsi, $this>
     */
    public function provinsi(): BelongsTo
    {
        return $this->belongsTo(MasterProvinsi::class, 'provinsi_id');
    }

    /**
     * @return BelongsTo<MasterKabupatenKota, $this>
     */
    public function kabupatenKota(): BelongsTo
    {
        return $this->belongsTo(MasterKabupatenKota::class, 'kabupaten_kota_id');
    }

    /**
     * @return BelongsTo<MasterKecamatan, $this>
     */
    public function kecamatan(): BelongsTo
    {
        return $this->belongsTo(MasterKecamatan::class, 'kecamatan_id');
    }

    /**
     * @return HasMany<FaskesLayanan, $this>
     */
    public function faskesLayanan(): HasMany
    {
        return $this->hasMany(FaskesLayanan::class, 'faskes_id');
    }

    /**
     * @return HasMany<LabPermintaan, $this>
     */
    public function labPermintaan(): HasMany
    {
        return $this->hasMany(LabPermintaan::class, 'faskes_lab_id');
    }

    /**
     * @return HasMany<PesananObat, $this>
     */
    public function pesananObat(): HasMany
    {
        return $this->hasMany(PesananObat::class, 'apotek_id');
    }

    /**
     * @return HasMany<RekamMedis, $this>
     */
    public function rekamMedis(): HasMany
    {
        return $this->hasMany(RekamMedis::class, 'faskes_id');
    }

    /**
     * @return HasMany<Resep, $this>
     */
    public function resep(): HasMany
    {
        return $this->hasMany(Resep::class, 'apotek_id');
    }

    /**
     * @return HasMany<Rujukan, $this>
     */
    public function rujukan(): HasMany
    {
        return $this->hasMany(Rujukan::class, 'faskes_tujuan_id');
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
            'alamat' => 'string',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'akreditasi' => 'string',
            'jam_operasional' => 'array',
            'status_aktif' => 'boolean',
        ];
    }
}
