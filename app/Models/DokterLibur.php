<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `dokter_libur` table.
 *
 * Source: telemedicine_test.sql:490.
 *
 * @property int|null $id
 * @property int|null $dokter_id
 * @property Carbon|null $tanggal
 * @property string|null $alasan
 * @property-read Dokter $dokter
 */
class DokterLibur extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dokter_libur';

    /**
     * Indicates if the model should be timestamped.
     *
     * `dokter_libur` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

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
            'tanggal' => 'date',
        ];
    }
}
