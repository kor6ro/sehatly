<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the `user_otp` table.
 *
 * Source: telemedicine_test.sql:179.
 *
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $kode_hash
 * @property string|null $tujuan
 * @property Carbon|null $kedaluwarsa_at
 * @property bool|null $sudah_dipakai
 * @property Carbon|null $dibuat_at
 * @property-read User $user
 */
class UserOtp extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_otp';

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'dibuat_at';

    /**
     * The name of the "updated at" column.
     *
     * `user_otp` declares no `diubah_at`, and Eloquent writes this constant on every
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
            'tujuan' => 'string',
            'kedaluwarsa_at' => 'datetime',
            'sudah_dipakai' => 'boolean',
        ];
    }
}
