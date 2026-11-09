<?php

declare(strict_types=1);

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Invitation token issued for a user account.
 *
 * This model lives in `App\Models\Admin` rather than `App\Models` because
 * `user_undangan` is not a contract table in `telemedicine_test.sql` — it was
 * added by migration 000090 — and `ModelFoundationTest` scans `app/Models/*.php`
 * (one level only) to enforce a 1-to-1 mapping with the DDL.
 *
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $token_hash   SHA-256 hex of the plaintext token; never the token itself.
 * @property string|null $kanal        whatsapp | sms | email
 * @property int|null $dibuat_oleh
 * @property Carbon|null $kedaluwarsa_pada
 * @property Carbon|null $dipakai_pada
 * @property Carbon|null $dibatalkan_pada
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read User|null $pembuat
 */
class UserUndangan extends Model
{
    protected $table = 'user_undangan';

    protected $fillable = [
        'user_id',
        'token_hash',
        'kanal',
        'dibuat_oleh',
        'kedaluwarsa_pada',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function masihBerlaku(): bool
    {
        return $this->dipakai_pada === null
            && $this->dibatalkan_pada === null
            && $this->kedaluwarsa_pada !== null
            && $this->kedaluwarsa_pada->isFuture();
    }

    protected function casts(): array
    {
        return [
            'kedaluwarsa_pada' => 'datetime',
            'dipakai_pada' => 'datetime',
            'dibatalkan_pada' => 'datetime',
        ];
    }
}
