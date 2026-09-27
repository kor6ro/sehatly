<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `pasien_penjamin` table.
 *
 * Source: telemedicine_test.sql:340.
 *
 * @property int|null $id
 * @property int|null $pasien_id
 * @property int|null $penjamin_id
 * @property string|null $nomor_peserta
 * @property string|null $kelas_rawat
 * @property int|null $faskes_rujukan_id
 * @property Carbon|null $masa_berlaku_akhir
 * @property bool|null $status_aktif
 * @property string|null $file_kartu
 * @property Carbon|null $dibuat_at
 * @property-read Pasien $pasien
 * @property-read MasterPenjamin $penjamin
 */
class PasienPenjamin extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pasien_penjamin';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `pasien_penjamin` declares no `diubah_at`, and Eloquent writes this constant on every
     * save, so it is nulled rather than left as the inherited `updated_at`.
     */
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Pasien, $this>
     */
    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    /**
     * @return BelongsTo<MasterPenjamin, $this>
     */
    public function penjamin(): BelongsTo
    {
        return $this->belongsTo(MasterPenjamin::class, 'penjamin_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kelas_rawat' => 'string',
            'masa_berlaku_akhir' => 'date',
            'status_aktif' => 'boolean',
        ];
    }
}
