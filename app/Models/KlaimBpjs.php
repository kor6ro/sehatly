<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `klaim_bpjs` table.
 *
 * Source: telemedicine_test.sql:1012.
 *
 * @property int|null $id
 * @property int|null $booking_id
 * @property int|null $rekam_medis_id
 * @property string|null $nomor_sep
 * @property string|null $nomor_kartu
 * @property string|null $tipe_layanan
 * @property string|null $diagnosa_icd10
 * @property string|null $tindakan_icd9cm
 * @property string|null $biaya_klaim
 * @property string|null $status
 * @property Carbon|null $tanggal_sep
 * @property Carbon|null $tanggal_pulang
 * @property string|null $berkas_url
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 */
class KlaimBpjs extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'klaim_bpjs';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipe_layanan' => 'string',
            'biaya_klaim' => 'decimal:2',
            'status' => 'string',
            'tanggal_sep' => 'date',
            'tanggal_pulang' => 'date',
        ];
    }
}
