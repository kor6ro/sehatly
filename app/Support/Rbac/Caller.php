<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use LogicException;

/**
 * Reads the two facts RBAC needs off the authenticated caller: its `users.id` and
 * its `users.tipe`.
 *
 * ## Why this is a class and not two `if` statements in each middleware
 *
 * Both `EnsurePermission` and `EnsureUserType` need both facts, and both need to
 * handle the three awkward cases identically: no authenticated user, an
 * authenticated user whose `tipe` is not loaded, and an id that is not an integer.
 * Two copies of that logic is how the 401 in one middleware and the 403 in the other
 * drift apart. There is one implementation and both middlewares call it.
 *
 * ## Why the id comes from `getAuthIdentifier()` and not from `->id`
 *
 * `getAuthIdentifier()` is the framework's own contract for "the primary key of the
 * authenticated principal", and it is what every guard and every `Auth::id()` call
 * in the framework already resolves. Reading `->id` instead would add an assumption
 * that the property is named `id` and is not a relation, which is true of the model
 * todo 19 will write and false of any other `Authenticatable`. `user_roles.user_id`
 * is `BIGINT UNSIGNED` (`:172`) and is joined to `users.id` (`:133`), so the value
 * has to be the users-table key and nothing else.
 *
 * ## Why `tipe` is read off the model and not re-queried
 *
 * `tipe:` answers "what kind of account is this", and the authenticated principal
 * already *is* the answer - the guard loaded the row. Re-reading `users.tipe` from
 * the query builder would be a second source of truth for a fact the caller is
 * already carrying, and it would add a query to every `tipe:`-gated request to learn
 * something the request object knows.
 *
 * The cost of that choice is that a principal which does not carry `tipe` fails
 * loudly instead of being denied quietly, which is the intended behaviour: a
 * `users` row always has a `tipe` (`:139` is `NOT NULL DEFAULT 'pasien'`), so an
 * authenticator that produced a principal without one is broken, and a broken
 * authenticator must not be allowed to look like an authorisation decision.
 */
final class Caller
{
    /**
     * The authenticated principal, or `null` when the request is anonymous.
     */
    public static function user(Request $request): ?Authenticatable
    {
        $user = $request->user();

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * The principal's `users.id`, as an integer, or `null` when anonymous.
     *
     * `user_roles.user_id` is `BIGINT UNSIGNED` and the join is to `users.id`, so a
     * non-integral identifier cannot be used and is a defect rather than an
     * authorisation failure.
     */
    public static function id(Request $request): ?int
    {
        $user = self::user($request);

        if ($user === null) {
            return null;
        }

        $id = $user->getAuthIdentifier();

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            throw new LogicException(
                'The authenticated principal returned a non-integral auth identifier of type '
                .get_debug_type($id).'. user_roles.user_id is BIGINT UNSIGNED joined to users.id.'
            );
        }

        return (int) $id;
    }

    /**
     * The principal's `users.tipe`, or `null` when anonymous.
     *
     * @throws LogicException when the principal carries no `tipe` at all
     */
    public static function tipe(Request $request): ?string
    {
        $user = self::user($request);

        if ($user === null) {
            return null;
        }

        // `isset` rather than `property_exists`, because an Eloquent model exposes
        // its columns through `__get`/`__isset` and `property_exists` would report
        // every one of them as missing. `tipe` is NOT NULL in the DDL, so "not set"
        // and "set to null" are the same situation and `isset` distinguishes the
        // real case from neither.
        if (! isset($user->tipe)) {
            throw new LogicException(
                'The authenticated principal of type '.get_class($user).' carries no `tipe`. '
                .'telemedicine_test.sql:139 makes users.tipe NOT NULL, so this authenticator is '
                .'incomplete. This is a wiring fault, not an authorisation decision.'
            );
        }

        return (string) $user->tipe;
    }
}
