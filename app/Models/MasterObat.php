<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `master_obat` table.
 *
 * Source: telemedicine_test.sql:708.
 *
 * @property int|null $id
 * @property string|null $kode_obat
 * @property string|null $nama_generik
 * @property string|null $nama_brand
 * @property string|null $bentuk_sediaan
 * @property string|null $kekuatan
 * @property string|null $satuan
 * @property string|null $pabrikan
 * @property string|null $kelas_terapi
 * @property string|null $kelas_obat
 * @property bool|null $requires_resep
 * @property string|null $aturan_pakai_umum
 * @property string|null $indikasi
 * @property string|null $kontraindikasi
 * @property string|null $harga_jual
 * @property bool|null $status_aktif
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read Collection<int, ApotekStok> $apotekStok
 * @property-read Collection<int, ObatInteraksi> $obatA
 * @property-read Collection<int, ObatInteraksi> $obatB
 * @property-read Collection<int, ResepItem> $resepItem
 */
class MasterObat extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'master_obat';

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
        return $this->hasMany(ApotekStok::class, 'obat_id');
    }

    /**
     * @return HasMany<ObatInteraksi, $this>
     */
    public function obatA(): HasMany
    {
        return $this->hasMany(ObatInteraksi::class, 'obat_a_id');
    }

    /**
     * @return HasMany<ObatInteraksi, $this>
     */
    public function obatB(): HasMany
    {
        return $this->hasMany(ObatInteraksi::class, 'obat_b_id');
    }

    /**
     * @return HasMany<ResepItem, $this>
     */
    public function resepItem(): HasMany
    {
        return $this->hasMany(ResepItem::class, 'obat_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bentuk_sediaan' => 'string',
            'satuan' => 'string',
            'kelas_obat' => 'string',
            'requires_resep' => 'boolean',
            'indikasi' => 'string',
            'kontraindikasi' => 'string',
            'harga_jual' => 'decimal:2',
            'status_aktif' => 'boolean',
        ];
    }
}
