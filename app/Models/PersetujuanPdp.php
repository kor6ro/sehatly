<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `persetujuan_pdp` table.
 *
 * Source: telemedicine_test.sql:1134.
 *
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $jenis
 * @property string|null $versi_dokumen
 * @property bool|null $disetujui
 * @property Carbon|null $disetujui_at
 * @property string|null $ip_address
 * @property-read User $user
 */
class PersetujuanPdp extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'persetujuan_pdp';

    /**
     * Indicates if the model should be timestamped.
     *
     * `persetujuan_pdp` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jenis' => 'string',
            'disetujui' => 'boolean',
            'disetujui_at' => 'datetime',
        ];
    }
}
