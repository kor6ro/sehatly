<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `obat_interaksi` table.
 *
 * Source: telemedicine_test.sql:731.
 *
 * @property int|null $id
 * @property int|null $obat_a_id
 * @property int|null $obat_b_id
 * @property string|null $tingkat
 * @property string|null $deskripsi
 * @property-read MasterObat $obatA
 * @property-read MasterObat $obatB
 */
class ObatInteraksi extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'obat_interaksi';

    /**
     * Indicates if the model should be timestamped.
     *
     * `obat_interaksi` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return BelongsTo<MasterObat, $this>
     */
    public function obatA(): BelongsTo
    {
        return $this->belongsTo(MasterObat::class, 'obat_a_id');
    }

    /**
     * @return BelongsTo<MasterObat, $this>
     */
    public function obatB(): BelongsTo
    {
        return $this->belongsTo(MasterObat::class, 'obat_b_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tingkat' => 'string',
            'deskripsi' => 'string',
        ];
    }
}
