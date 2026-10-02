<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `preferensi_notifikasi` table.
 *
 * Source: `telemedicine_test.sql:1370` (F11 section `[18]`, appended 2026-10-03).
 *
 * One row per user: quiet hours, the mode, and the zone the window is read in.
 * A user with no row is NOT "no preferences" - the effective defaults are
 * `jam_tenang_aktif = false`, `setiap_hari`, `21:00`-`06:00`, `Asia/Jakarta`,
 * and `PreferensiNotifikasiService` answers them without writing anything.
 *
 * There is deliberately **no in-app column**: in-app delivery is unconditional.
 *
 * @property int|null $id
 * @property int|null $user_id
 * @property bool|null $jam_tenang_aktif
 * @property string|null $jam_tenang_mode
 * @property Carbon|null $jam_tenang_mulai
 * @property Carbon|null $jam_tenang_selesai
 * @property string|null $zona_waktu
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read User $user
 */
class PreferensiNotifikasi extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'preferensi_notifikasi';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'diubah_at';

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
            'jam_tenang_aktif' => 'boolean',
            'jam_tenang_mode' => 'string',
        ];
    }
}
