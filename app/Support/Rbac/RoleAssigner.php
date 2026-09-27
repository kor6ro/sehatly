<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Grants, revokes and reads the roles held by one `users` row through
 * `user_roles`.
 *
 * ## Why this exists as a service and not as `User::assignRole()`
 *
 * Plan todo 4 asks for "an `AssignRole` helper on the `User` model". Todo 19 is
 * building all 75 models in parallel and owns `app/Models/**`, so the helper is
 * delivered here instead and todo 19 should call it from the model rather than
 * re-implement it. The behaviour is identical and there is exactly one
 * implementation: a second copy inside a model would be a second place where
 * `user_roles` can be written, and the two would drift.
 *
 * The call site in todo 19 should look like:
 *
 * ```php
 * public function assignRole(string ...$roles): static
 * {
 *     app(RoleAssigner::class)->assign($this->getKey(), ...$roles);
 *
 *     return $this;
 * }
 * ```
 *
 * ## `user_roles` is a composite-PK join table with no `id`
 *
 * `telemedicine_test.sql:171-177`: `user_id` and `role_id`, both unsigned, with
 * `PRIMARY KEY (user_id, role_id)` and no surrogate key. Two consequences shape
 * this class:
 *
 * - The pair is the identity, so {@see assign()} is idempotent by construction. It
 *   uses `insertOrIgnore()` so that granting a role twice is a no-op rather than a
 *   duplicate-key exception - the one conflict the composite primary key can raise.
 * - There is no `created_at` to set, so nothing here passes timestamps. `users` and
 *   `roles` are also timestamp-free, and a stray `created_at` would be a MySQL 1054.
 *
 * `insertOrIgnore()` is `INSERT IGNORE` on MySQL, and `INSERT IGNORE` downgrades
 * *any* error to a warning - not only the duplicate key. That is acceptable here
 * precisely because every value is resolved first: an unknown role name throws
 * before the write (so no dangling `role_id` can be inserted), and `userId` is the
 * caller's own key. The residual risk is a `users` row that was deleted between
 * the call and the insert, which the `ON DELETE CASCADE` on `user_roles.user_id`
 * makes irrelevant.
 *
 * ## Role names are validated against the catalogue, not the database
 *
 * A role name is accepted only if it is in {@see RbacCatalog::ROLES}, which is a
 * subset of the DDL's `users.tipe` values. Looking the name up in `roles` and
 * throwing when it is absent would also be safe, but it would report "no such role"
 * for a name that was never going to be seeded - a typo and a not-yet-implemented
 * role become indistinguishable. Validating against the catalogue first keeps the
 * error message about the name.
 */
final class RoleAssigner
{
    /**
     * Grant every named role to `$userId`, ignoring roles already held.
     *
     * @throws LogicException on an unknown role name, before any write
     */
    public function assign(int $userId, string ...$roles): void
    {
        $this->assertKnown($roles);

        if ($roles === []) {
            return;
        }

        $ids = $this->roleIds($roles);

        $rows = array_map(
            static fn (int $roleId): array => ['user_id' => $userId, 'role_id' => $roleId],
            $ids,
        );

        DB::table('user_roles')->insertOrIgnore($rows);
    }

    /**
     * Revoke every named role from `$userId`.
     *
     * Revoking a role the user does not hold is a no-op, not an error, so this is
     * safe to call from a logout or deactivation path.
     *
     * @throws LogicException on an unknown role name, before any write
     */
    public function revoke(int $userId, string ...$roles): void
    {
        $this->assertKnown($roles);

        if ($roles === []) {
            return;
        }

        DB::table('user_roles')
            ->where('user_id', $userId)
            ->whereIn('role_id', $this->roleIds($roles))
            ->delete();
    }

    /**
     * The role names `$userId` currently holds, ordered for stable assertions.
     *
     * @return list<string>
     */
    public function rolesFor(int $userId): array
    {
        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $userId)
            ->orderBy('roles.nama')
            ->pluck('roles.nama')
            ->all();
    }

    /**
     * Reject unknown role names before anything is written.
     *
     * @param  list<string>  $roles
     *
     * @throws LogicException
     */
    private function assertKnown(array $roles): void
    {
        $unknown = array_values(array_filter(
            $roles,
            static fn (string $role): bool => ! RbacCatalog::isRole($role),
        ));

        if ($unknown !== []) {
            throw new LogicException(
                'Unknown role name(s): '.implode(', ', $unknown).'. RbacCatalog::ROLES holds only: '
                .implode(', ', RbacCatalog::ROLES).'. Every role name must also be a telemedicine_test.sql:139 '
                .'users.tipe value.'
            );
        }
    }

    /**
     * Resolve role names to ids, throwing rather than inserting a dangling id.
     *
     * @param  list<string>  $roles
     * @return list<int>
     */
    private function roleIds(array $roles): array
    {
        $found = DB::table('roles')
            ->whereIn('nama', $roles)
            ->pluck('id', 'nama');

        $missing = array_values(array_filter(
            $roles,
            static fn (string $role): bool => ! $found->has($role),
        ));

        if ($missing !== []) {
            throw new LogicException(
                'RbacCatalog::ROLES names '.implode(', ', $missing).' but `roles` holds no such row. '
                .'Run `php artisan migrate:fresh --seed` (or db:seed --class=RbacSeeder) first.'
            );
        }

        // Resolved in the caller's order rather than in `roles` order, so the
        // generated INSERT lists pairs deterministically.
        return array_map(static fn (string $role): int => (int) $found[$role], $roles);
    }
}
