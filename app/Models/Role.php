<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the `roles` table.
 *
 * Source: telemedicine_test.sql:151.
 *
 * @property int|null $id
 * @property string|null $nama
 * @property string|null $deskripsi
 * @property-read Collection<int, RolePermissions> $rolePermissions
 * @property-read Collection<int, UserRoles> $userRoles
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, Permission> $permissions
 */
class Role extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'roles';

    /**
     * Indicates if the model should be timestamped.
     *
     * `roles` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
     * for Eloquent to write as a creation stamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return HasMany<RolePermissions, $this>
     */
    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermissions::class, 'role_id');
    }

    /**
     * @return HasMany<UserRoles, $this>
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRoles::class, 'role_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'user_id');
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id');
    }
}
