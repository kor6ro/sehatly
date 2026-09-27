<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `apotek_stok` table.
 *
 * Source: telemedicine_test.sql:829.
 *
 * @property int|null $id
 * @property int|null $apotek_id
 * @property int|null $obat_id
 * @property int|null $jumlah_stok
 * @property int|null $stok_minimum
 * @property string|null $harga_jual
 * @property Carbon|null $kedaluwarsa
 * @property Carbon|null $diubah_at
 * @property-read Faskes $apotek
 * @property-read MasterObat $obat
 */
class ApotekStok extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'apotek_stok';

    /**
     * Indicates if the model should be timestamped.
     *
     * `apotek_stok` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

    /**
     * @return BelongsTo<Faskes, $this>
     */
    public function apotek(): BelongsTo
    {
        return $this->belongsTo(Faskes::class, 'apotek_id');
    }

    /**
     * @return BelongsTo<MasterObat, $this>
     */
    public function obat(): BelongsTo
    {
        return $this->belongsTo(MasterObat::class, 'obat_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'harga_jual' => 'decimal:2',
            'kedaluwarsa' => 'date',
            'diubah_at' => 'datetime',
        ];
    }
}
