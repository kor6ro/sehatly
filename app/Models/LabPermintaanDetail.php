<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `lab_permintaan_detail` table.
 *
 * Source: telemedicine_test.sql:894.
 *
 * @property int|null $id
 * @property int|null $lab_permintaan_id
 * @property int|null $tindakan_id
 * @property int|null $paket_id
 * @property string|null $prioritas
 * @property-read LabPermintaan $labPermintaan
 * @property-read MasterLabTindakan $tindakan
 * @property-read MasterLabPaket $paket
 */
class LabPermintaanDetail extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'lab_permintaan_detail';

    /**
     * Indicates if the model should be timestamped.
     *
     * `lab_permintaan_detail` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
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
     * @return BelongsTo<MasterLabPaket, $this>
     */
    public function paket(): BelongsTo
    {
        return $this->belongsTo(MasterLabPaket::class, 'paket_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prioritas' => 'string',
        ];
    }
}
