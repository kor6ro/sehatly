<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `pesanan_obat` table.
 *
 * Source: telemedicine_test.sql:797.
 *
 * @property int|null $id
 * @property string|null $nomor_pesanan
 * @property int|null $resep_id
 * @property int|null $pasien_id
 * @property int|null $apotek_id
 * @property string|null $tipe
 * @property string|null $alamat_kirim
 * @property string|null $kurir
 * @property string|null $no_resi
 * @property string|null $subtotal
 * @property string|null $biaya_kirim
 * @property string|null $total
 * @property string|null $status
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read Resep $resep
 * @property-read Pasien $pasien
 * @property-read Faskes $apotek
 * @property-read Collection<int, PesananObatTracking> $pesananObatTracking
 */
class PesananObat extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pesanan_obat';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

    /**
     * @return BelongsTo<Resep, $this>
     */
    public function resep(): BelongsTo
    {
        return $this->belongsTo(Resep::class, 'resep_id');
    }

    /**
     * @return BelongsTo<Pasien, $this>
     */
    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    /**
     * @return BelongsTo<Faskes, $this>
     */
    public function apotek(): BelongsTo
    {
        return $this->belongsTo(Faskes::class, 'apotek_id');
    }

    /**
     * @return HasMany<PesananObatTracking, $this>
     */
    public function pesananObatTracking(): HasMany
    {
        return $this->hasMany(PesananObatTracking::class, 'pesanan_obat_id');
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
            'alamat_kirim' => 'string',
            'kurir' => 'string',
            'subtotal' => 'decimal:2',
            'biaya_kirim' => 'decimal:2',
            'total' => 'decimal:2',
            'status' => 'string',
        ];
    }
}
