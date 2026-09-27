<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `resep_item` table.
 *
 * Source: telemedicine_test.sql:767.
 *
 * @property int|null $id
 * @property int|null $resep_id
 * @property int|null $obat_id
 * @property string|null $nama_obat
 * @property string|null $kekuatan
 * @property string|null $aturan_pakai
 * @property int|null $jumlah
 * @property string|null $satuan
 * @property bool|null $is_racikan
 * @property string|null $racikan_nama
 * @property string|null $harga_satuan
 * @property string|null $subtotal
 * @property string|null $catatan_apoteker
 * @property-read Resep $resep
 * @property-read MasterObat $obat
 */
class ResepItem extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'resep_item';

    /**
     * Indicates if the model should be timestamped.
     *
     * `resep_item` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return BelongsTo<Resep, $this>
     */
    public function resep(): BelongsTo
    {
        return $this->belongsTo(Resep::class, 'resep_id');
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
            'is_racikan' => 'boolean',
            'harga_satuan' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'catatan_apoteker' => 'string',
        ];
    }
}
