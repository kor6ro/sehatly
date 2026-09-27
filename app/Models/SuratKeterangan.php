<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `surat_keterangan` table.
 *
 * Source: telemedicine_test.sql:581.
 *
 * @property int|null $id
 * @property string|null $nomor_surat
 * @property int|null $konsultasi_id
 * @property string|null $tipe
 * @property int|null $pasien_id
 * @property int|null $dokter_id
 * @property Carbon|null $tanggal_mulai
 * @property Carbon|null $tanggal_selesai
 * @property int|null $jumlah_hari
 * @property string|null $isi
 * @property string|null $qr_token
 * @property string|null $file_url
 * @property Carbon|null $dibuat_at
 * @property-read Collection<int, Rujukan> $rujukan
 * @property-read Pasien $pasien
 * @property-read Dokter $dokter
 */
class SuratKeterangan extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'surat_keterangan';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `surat_keterangan` declares no `diubah_at`, and Eloquent writes this constant on every
     * save, so it is nulled rather than left as the inherited `updated_at`.
     */
    public const UPDATED_AT = null;

    /**
     * @return HasMany<Rujukan, $this>
     */
    public function rujukan(): HasMany
    {
        return $this->hasMany(Rujukan::class, 'surat_keterangan_id');
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipe' => 'string',
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'isi' => 'string',
        ];
    }
}
