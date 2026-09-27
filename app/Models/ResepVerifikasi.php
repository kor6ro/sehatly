<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `resep_verifikasi` table.
 *
 * Source: telemedicine_test.sql:786.
 *
 * @property int|null $id
 * @property int|null $resep_id
 * @property int|null $apoteker_user_id
 * @property string|null $status
 * @property string|null $catatan
 * @property Carbon|null $diverifikasi_at
 * @property-read Resep $resep
 * @property-read User $apotekerUser
 */
class ResepVerifikasi extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'resep_verifikasi';

    /**
     * Indicates if the model should be timestamped.
     *
     * `resep_verifikasi` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return BelongsTo<Resep, $this>
     */
    public function resep(): BelongsTo
    {
        return $this->belongsTo(Resep::class, 'resep_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function apotekerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'apoteker_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'string',
            'catatan' => 'string',
            'diverifikasi_at' => 'datetime',
        ];
    }
}
