<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `home_care_pesanan` table.
 *
 * Source: telemedicine_test.sql:1093.
 *
 * @property int|null $id
 * @property string|null $nomor_pesanan
 * @property int|null $pasien_id
 * @property int|null $anggota_keluarga_id
 * @property string|null $tipe_layanan
 * @property int|null $tenaga_medis_id
 * @property string|null $alamat_kunjungan
 * @property Carbon|null $jadwal_kunjungan
 * @property int|null $durasi_jam
 * @property string|null $keluhan
 * @property string|null $status
 * @property string|null $biaya
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read Pasien $pasien
 * @property-read PasienAnggotaKeluarga $anggotaKeluarga
 * @property-read Dokter $tenagaMedis
 */
class HomeCarePesanan extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'home_care_pesanan';

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
    public function tenagaMedis(): BelongsTo
    {
        return $this->belongsTo(Dokter::class, 'tenaga_medis_id');
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
            'alamat_kunjungan' => 'string',
            'jadwal_kunjungan' => 'datetime',
            'keluhan' => 'string',
            'status' => 'string',
            'biaya' => 'decimal:2',
        ];
    }
}
