<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `preferensi_notifikasi_tipe` table.
 *
 * Source: `telemedicine_test.sql:1384` (F11 section `[18]`, appended 2026-10-03).
 *
 * One row per `(user, tipe)` for the four produced notification types. **No row
 * means `push_aktif = 1`** - the schema's own default - so the write path
 * lazy-upserts only the rows a user actually changes and the read path treats
 * an absent row as "on" without inventing one.
 *
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $tipe
 * @property bool|null $push_aktif
 * @property Carbon|null $dibuat_at
 * @property Carbon|null $diubah_at
 * @property-read User $user
 */
class PreferensiNotifikasiTipe extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'preferensi_notifikasi_tipe';

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
            'tipe' => 'string',
            'push_aktif' => 'boolean',
        ];
    }
}
