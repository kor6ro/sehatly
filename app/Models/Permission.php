<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the `permissions` table.
 *
 * Source: telemedicine_test.sql:157.
 *
 * @property int|null $id
 * @property string|null $kode
 * @property string|null $nama
 * @property-read Collection<int, RolePermissions> $rolePermissions
 * @property-read Collection<int, Role> $roles
 */
class Permission extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'permissions';

    /**
     * Indicates if the model should be timestamped.
     *
     * `permissions` declares no `dibuat_at` and no `terkirim_at`, so there is nothing
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
        return $this->hasMany(RolePermissions::class, 'permission_id');
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions', 'permission_id', 'role_id');
    }
}
