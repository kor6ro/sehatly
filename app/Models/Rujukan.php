<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `rujukan` table.
 *
 * Source: telemedicine_test.sql:599.
 *
 * @property int|null $id
 * @property int|null $surat_keterangan_id
 * @property int|null $faskes_asal_id
 * @property int|null $faskes_tujuan_id
 * @property int|null $dokter_perujuk_id
 * @property string|null $diagnosis_kerja
 * @property string|null $icd10_kode
 * @property string|null $alasan_rujukan
 * @property Carbon|null $berlaku_sampai
 * @property string|null $nomor_sep
 * @property string|null $status
 * @property Carbon|null $dibuat_at
 * @property-read SuratKeterangan $suratKeterangan
 * @property-read Faskes $faskesTujuan
 * @property-read Dokter $dokterPerujuk
 */
class Rujukan extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'rujukan';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `rujukan` declares no `diubah_at`, and Eloquent writes this constant on every
     * save, so it is nulled rather than left as the inherited `updated_at`.
     */
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<SuratKeterangan, $this>
     */
    public function suratKeterangan(): BelongsTo
    {
        return $this->belongsTo(SuratKeterangan::class, 'surat_keterangan_id');
    }

    /**
     * @return BelongsTo<Faskes, $this>
     */
    public function faskesTujuan(): BelongsTo
    {
        return $this->belongsTo(Faskes::class, 'faskes_tujuan_id');
    }

    /**
     * @return BelongsTo<Dokter, $this>
     */
    public function dokterPerujuk(): BelongsTo
    {
        return $this->belongsTo(Dokter::class, 'dokter_perujuk_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alasan_rujukan' => 'string',
            'berlaku_sampai' => 'date',
            'status' => 'string',
        ];
    }
}
