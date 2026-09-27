<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `pasien_imunisasi` table.
 *
 * Source: telemedicine_test.sql:300.
 *
 * @property int|null $id
 * @property int|null $pasien_id
 * @property string|null $nama_vaksin
 * @property Carbon|null $tanggal
 * @property int|null $dosis_ke
 * @property string|null $no_batch
 * @property string|null $pemberi
 * @property Carbon|null $dibuat_at
 * @property-read Pasien $pasien
 */
class PasienImunisasi extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pasien_imunisasi';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `pasien_imunisasi` declares no `diubah_at`, and Eloquent writes this constant on every
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
