<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\Rbac\Caller;
use App\Support\Rbac\RbacCatalog;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware that admits a caller only if `users.tipe` is one of the named
 * `tipe:` values.
 *
 * Registered as the `tipe` alias, so a route reads:
 *
 * ```php
 * ->middleware(['auth:sanctum', 'tipe:dokter'])
 * ->middleware(['auth:sanctum', 'tipe:admin,superadmin'])
 * ```
 *
 * Comma-separated values mean **any of**: `tipe:admin,superadmin` passes either.
 *
 * ## `tipe:` and `permission:` answer different questions, and the plan uses both
 *
 * `tipe` is the DDL's own account-type column: a hard fact about the row, with
 * seven possible values at `telemedicine_test.sql:139`, and it is what
 * `booking.dibatalkan_oleh` is populated from. `permission` is a grant that an
 * administrator can change without touching the account row. A route that needs an
 * *account type* should say `tipe:`; a route that needs a *grant* should say
 * `permission:`. The plan uses both in the same breath - todo 32 writes
 * "`tipe:dokter` + an ownership check", todo 39 writes the medicine search is
 * "doctor-only (`tipe:dokter`)" - so both middlewares are genuinely load-bearing and
 * neither is redundant.
 *
 * ## Only the DDL's seven values are accepted
 *
 * Every value passed to `tipe:` is checked against {@see RbacCatalog::USER_TYPES}
 * before the caller's row is read, and an unrecognised value throws a
 * {@see LogicException} - a 500 - rather than a 403, for the same reason
 * {@see EnsurePermission} does: `tipe:doktor` is a typo, and a 403 would present it
 * as an authorisation decision about a real user. `RbacCatalog::USER_TYPES` is
 * asserted equal to the DDL's ENUM by `RbacCatalogTest`, so the accepted set cannot
 * drift from the schema.
 *
 * A value that *is* in the ENUM but is not a seeded role - `perawat` and `kurir` -
 * works here, and holds no `permission:` grant at all. That asymmetry is recorded in
 * {@see RbacCatalog}'s docblock and in `.omo/evidence/task-4-sehatly.md`; it is a
 * finding for todos 20/21/22, not something this middleware papers over.
 *
 * ## 401 before 403, and never a redirect
 *
 * Identical in shape and reasoning to {@see EnsurePermission}: an anonymous caller
 * gets the 401 envelope, both failure bodies come from {@see ApiResponse}, the
 * strings match the exception renderer in `bootstrap/app.php` byte for byte, and no
 * path through this class can produce a redirect or an HTML page.
 */
class EnsureUserType
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $tipe = Caller::tipe($request);

        if ($tipe === null) {
            return ApiResponse::error('Unauthenticated.', [], Response::HTTP_UNAUTHORIZED);
        }

        $this->assertKnown($types);

        if (! in_array($tipe, $types, true)) {
            return ApiResponse::error('This action is unauthorized.', [], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * Reject an empty or unknown `tipe` value before the caller's row is read.
     *
     * @param  list<string>  $types
     *
     * @throws LogicException
     */
    private function assertKnown(array $types): void
    {
        if ($types === []) {
            throw new LogicException(
                'The `tipe:` middleware was used with no value. Write `tipe:dokter` or '
                .'`tipe:admin,superadmin`; the DDL allows exactly: '
                .implode(', ', RbacCatalog::USER_TYPES).'.'
            );
        }

        $unknown = array_values(array_filter(
            $types,
            static fn (string $type): bool => ! RbacCatalog::isUserType($type),
        ));

        if ($unknown !== []) {
            throw new LogicException(
                'Unknown user type(s): '.implode(', ', $unknown).'. `tipe:` accepts only the values of the '
                .'users.tipe ENUM at '.RbacCatalog::DDL_USER_TYPES_LINE
                .', which are exactly: '.implode(', ', RbacCatalog::USER_TYPES).'.'
            );
        }
    }
}
