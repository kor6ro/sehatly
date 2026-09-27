<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\Rbac\Caller;
use App\Support\Rbac\RbacCatalog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware that admits a caller only if one of its roles grants one of the
 * named `permissions.kode` values.
 *
 * Registered as the `permission` alias, so a route reads:
 *
 * ```php
 * ->middleware(['auth:sanctum', 'permission:booking.buat'])
 * ```
 *
 * Several codes are comma-separated and mean **any of**:
 * `permission:resep.lihat,resep.verifikasi` passes a caller holding either.
 *
 * ## The check is exactly one query, whatever the caller holds
 *
 * {@see RbacCatalog::ROLE_PERMISSIONS} makes a caller with three roles hold up to 24
 * permission codes, so the naive implementation - load roles, loop, load permissions
 * per role - is exactly the N+1 this project forbids. Instead one `EXISTS` walks
 * `user_roles -> role_permissions -> permissions` for the caller's single id:
 *
 * ```sql
 * SELECT EXISTS (
 *   SELECT 1 FROM role_permissions
 *   INNER JOIN user_roles ON user_roles.role_id = role_permissions.role_id
 *   INNER JOIN permissions ON permissions.id = role_permissions.permission_id
 *   WHERE user_roles.user_id = ? AND permissions.kode IN (?, ?)
 * )
 * ```
 *
 * The join order is `role_permissions` first because it is the table every other
 * table is reached through, and MySQL resolves it from `user_roles.role_id` and
 * `permissions.id`, both of which are indexed (the latter is the primary key). One
 * `IN` list, one `EXISTS`, no per-role loop - so the cost is flat in the number of
 * roles and permissions the caller has. `RbacMiddlewareTest` proves the flatness by
 * counting queries with `DB::listen` for a caller holding three roles.
 *
 * No eager loading is used anywhere in this class, because there is no collection
 * to load into: the question is a boolean, and materialising 24 rows to answer it
 * would be strictly more work.
 *
 * ## 401 before 403, and never a redirect
 *
 * An anonymous caller gets the **401** envelope, not 403, even if the route forgot
 * `auth:sanctum`. "You are not logged in" and "you may not do this" are different
 * answers and a client needs to be able to tell them: 401 means "send a token", 403
 * means "stop". The body is byte-identical to the one
 * `bootstrap/app.php`'s exception renderer produces for an `AuthenticationException`,
 * so a client cannot tell whether the 401 came from the guard or from here.
 *
 * Both failure bodies are produced by {@see ApiResponse}, the same class every
 * controller uses. **This middleware never redirects** - not to `/login`, not to an
 * Inertia page - because every route it protects lives under the `api/v1` prefix and
 * an HTML redirect is unparseable to every client in this project.
 *
 * The response is returned directly rather than by throwing, because a thrown
 * `AuthenticationException` is rendered by `shouldRenderJsonWhen()`, which is false
 * for a non-`api/*` path. Returning it makes the body independent of the request
 * path, which is what "one error shape" has to mean.
 *
 * ## An unknown permission code is a 500, not a 403
 *
 * `permission:booking.create` - English action verb, not a code in
 * {@see RbacCatalog::PERMISSIONS} - throws a {@see LogicException}. That is
 * deliberate and it is the opposite of the safe-looking alternative:
 *
 * - Failing closed with 403 would make a typo'd route deny **every** caller, forever,
 *   with a body that says "unauthorised" and points at the caller's token. The bug
 *   would be found by a user, not by a developer, and the error message would name
 *   the wrong thing.
 * - Failing open is not an option at all.
 *
 * A 500 with a message naming the offending code and the catalogue is a build-time
 * mistake instead, and the sanitised 500 envelope keeps the internal detail in the
 * log. Todo 20 owns the route surface, so this is the failure mode it will hit if it
 * invents a code; the alternative is a suite of 403s nobody can explain.
 *
 * `permission:` with no code at all is the same class of mistake and also throws.
 */
class EnsurePermission
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $userId = Caller::id($request);

        if ($userId === null) {
            return ApiResponse::error('Unauthenticated.', [], Response::HTTP_UNAUTHORIZED);
        }

        $this->assertKnown($permissions);

        $granted = DB::table('role_permissions')
            ->join('user_roles', 'user_roles.role_id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('user_roles.user_id', $userId)
            ->whereIn('permissions.kode', $permissions)
            ->exists();

        if (! $granted) {
            return ApiResponse::error('This action is unauthorized.', [], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * Reject an empty or unknown permission code before querying anything.
     *
     * @param  list<string>  $permissions
     *
     * @throws LogicException
     */
    private function assertKnown(array $permissions): void
    {
        if ($permissions === []) {
            throw new LogicException(
                'The `permission:` middleware was used with no permission code. Write '
                .'`permission:kode` or `permission:kodeA,kodeB`; the catalogue is: '
                .RbacCatalog::describeCatalogue().'.'
            );
        }

        $unknown = array_values(array_filter(
            $permissions,
            static fn (string $kode): bool => ! RbacCatalog::isPermission($kode),
        ));

        if ($unknown !== []) {
            throw new LogicException(
                'Unknown permission code(s): '.implode(', ', $unknown).'. Every `permission:` code must be a '
                .'key of RbacCatalog::PERMISSIONS, whose shape is the <resource>.<aksi> convention the contract '
                ."itself documents at telemedicine_test.sql:159 (`COMMENT 'cth: rekam_medis.lihat, resep.buat'`). "
                .'The action verbs are Indonesian - `booking.buat`, not `booking.create`. '
                .'The full catalogue is: '.RbacCatalog::describeCatalogue().'.'
            );
        }
    }
}
