<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `role_permissions` table.
 *
 * Source: telemedicine_test.sql:163.
 *
 * @property int|null $role_id
 * @property int|null $permission_id
 * @property-read Role $role
 * @property-read Permission $permission
 */
class RolePermissions extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'role_permissions';

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
    protected $primaryKey = ['role_id', 'permission_id'];

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
     * `role_permissions` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * @return BelongsTo<Permission, $this>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id');
    }
}
