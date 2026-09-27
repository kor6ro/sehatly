<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `pesanan_obat_tracking` table.
 *
 * Source: telemedicine_test.sql:819.
 *
 * @property int|null $id
 * @property int|null $pesanan_obat_id
 * @property string|null $status
 * @property string|null $keterangan
 * @property string|null $lokasi
 * @property Carbon|null $waktu
 * @property-read PesananObat $pesananObat
 */
class PesananObatTracking extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pesanan_obat_tracking';

    /**
     * Indicates if the model should be timestamped.
     *
     * `pesanan_obat_tracking` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return BelongsTo<PesananObat, $this>
     */
    public function pesananObat(): BelongsTo
    {
        return $this->belongsTo(PesananObat::class, 'pesanan_obat_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'waktu' => 'datetime',
        ];
    }
}
