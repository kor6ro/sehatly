<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `resep` table.
 *
 * Source: telemedicine_test.sql:742.
 *
 * @property int|null $id
 * @property string|null $nomor_resep
 * @property int|null $konsultasi_id
 * @property int|null $rekam_medis_id
 * @property int|null $pasien_id
 * @property int|null $dokter_id
 * @property int|null $apotek_id
 * @property string|null $tipe
 * @property string|null $status
 * @property string|null $catatan_dokter
 * @property Carbon|null $tanggal_resep
 * @property Carbon|null $berlaku_sampai
 * @property bool|null $is_iter
 * @property int|null $jumlah_iter
 * @property string|null $qr_token
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read Collection<int, PesananObat> $pesananObat
 * @property-read Pasien $pasien
 * @property-read Dokter $dokter
 * @property-read Faskes $apotek
 * @property-read Collection<int, ResepItem> $resepItem
 * @property-read ResepVerifikasi $resepVerifikasi
 */
class Resep extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'resep';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

    /**
     * @return HasMany<PesananObat, $this>
     */
    public function pesananObat(): HasMany
    {
        return $this->hasMany(PesananObat::class, 'resep_id');
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
    public function apotek(): BelongsTo
    {
        return $this->belongsTo(Faskes::class, 'apotek_id');
    }

    /**
     * @return HasMany<ResepItem, $this>
     */
    public function resepItem(): HasMany
    {
        return $this->hasMany(ResepItem::class, 'resep_id');
    }

    /**
     * @return HasOne<ResepVerifikasi, $this>
     */
    public function resepVerifikasi(): HasOne
    {
        return $this->hasOne(ResepVerifikasi::class, 'resep_id');
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
            'catatan_dokter' => 'string',
            'tanggal_resep' => 'datetime',
            'berlaku_sampai' => 'date',
            'is_iter' => 'boolean',
        ];
    }
}
