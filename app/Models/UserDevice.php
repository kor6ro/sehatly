<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `user_devices` table.
 *
 * Source: telemedicine_test.sql:190.
 *
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $device_id
 * @property string|null $platform
 * @property string|null $fcm_token
 * @property string|null $app_versi
 * @property bool|null $aktif
 * @property Carbon|null $last_active_at
 * @property Carbon|null $dibuat_at
 * @property-read User $user
 */
class UserDevice extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_devices';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `user_devices` declares no `diubah_at`, and Eloquent writes this constant on every
     * save, so it is nulled rather than left as the inherited `updated_at`.
     */
    public const UPDATED_AT = null;

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
            'platform' => 'string',
            'aktif' => 'boolean',
            'last_active_at' => 'datetime',
        ];
    }
}
