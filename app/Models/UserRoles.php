<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `user_roles` table.
 *
 * Source: telemedicine_test.sql:171.
 *
 * @property int|null $user_id
 * @property int|null $role_id
 * @property-read User $user
 * @property-read Role $role
 */
class UserRoles extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_roles';

    /**
     * The model's primary key.
     *
     * Eloquent has no composite-key support, so this records the key the DDL
     * declares instead of letting the model assume a single `id`. `find()` and
     * `getKey()` are meaningless on a pivot; read it through the `belongsToMany`
     * on the owning model.
     *
     * @var array<int, string>
     */
    protected $primaryKey = ['user_id', 'role_id'];

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model should be timestamped.
     *
     * `user_roles` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
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
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
