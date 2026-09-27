<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `lab_hasil` table.
 *
 * Source: telemedicine_test.sql:905.
 *
 * @property int|null $id
 * @property int|null $lab_permintaan_id
 * @property int|null $tindakan_id
 * @property string|null $nilai
 * @property string|null $satuan
 * @property string|null $nilai_rujukan
 * @property bool|null $is_abnormal
 * @property string|null $keterangan
 * @property int|null $diperiksa_oleh
 * @property Carbon|null $tanggal_hasil
 * @property string|null $file_pdf_url
 * @property-read LabPermintaan $labPermintaan
 * @property-read MasterLabTindakan $tindakan
 */
class LabHasil extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'lab_hasil';

    /**
     * Indicates if the model should be timestamped.
     *
     * `lab_hasil` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return BelongsTo<LabPermintaan, $this>
     */
    public function labPermintaan(): BelongsTo
    {
        return $this->belongsTo(LabPermintaan::class, 'lab_permintaan_id');
    }

    /**
     * @return BelongsTo<MasterLabTindakan, $this>
     */
    public function tindakan(): BelongsTo
    {
        return $this->belongsTo(MasterLabTindakan::class, 'tindakan_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_abnormal' => 'boolean',
            'keterangan' => 'string',
            'tanggal_hasil' => 'datetime',
        ];
    }
}
