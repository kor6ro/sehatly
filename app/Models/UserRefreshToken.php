<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `user_refresh_tokens` table.
 *
 * Source: telemedicine_test.sql:204.
 *
 * `device_id` is the F01 owner-approved addition (migration
 * `2026_10_01_000083`): the client-supplied installation identifier that lets a
 * `user_devices` row revoke exactly its own refresh tokens. It is NULL for rows
 * minted before the mapping existed, and NULL never matches a device revoke.
 *
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $device_id
 * @property string|null $token_hash
 * @property Carbon|null $kedaluwarsa_at
 * @property bool|null $dicabut
 * @property Carbon|null $dibuat_at
 * @property-read User $user
 */
class UserRefreshToken extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_refresh_tokens';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `user_refresh_tokens` declares no `diubah_at`, and Eloquent writes this constant on every
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
            'kedaluwarsa_at' => 'datetime',
            'dicabut' => 'boolean',
        ];
    }
}
