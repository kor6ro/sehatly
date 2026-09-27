<?php

declare(strict_types=1);

namespace App\Services\Konsultasi;

use App\Models\Konsultasi;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single answer to "may this account subscribe to the private channel of
 * this consultation?".
 *
 * ## Why a service and not a closure
 *
 * The channel callback in `routes/channels.php` is a one-line delegation to
 * this class so that the rule has exactly one implementation. Todo 32 needs the
 * same rule to scope `GET /api/v1/konsacerbasi/{id}` and the chat endpoints, and
 * a second hand-written `pasien.user_id` or `dokter.user_id` comparison there
 * would be a second copy of the authorization decision - which is exactly the
 * kind of copy that drifts and starts letting strangers through.
 *
 * ## The rule, and the columns it is derived from
 *
 * A consultation has no `user_id` of its own. `konsultasi` stores
 * `pasien_id` (`telemedicine_test.sql:539`) and `dokter_id` (`:540`), each a
 * foreign key to a profile table (`:558` and `:559`), and it is the **profile**
 * tables that carry the account: `pasien.user_id` (`:220`) and `dokter.user_id`
 * (`:411`). So membership is a two-hop walk, and it is walked through the
 * Eloquent relations that already encode those two hops
 * (`Konsultasi::pasien()` / `Konsultasi::dokter()`) rather than through raw joins,
 * so the answer cannot disagree with the relations the rest of the app uses.
 *
 * Both hops are required to be *present* as well as matching. A consultation
 * whose `pasien` or `dokter` row has been soft deleted does not grant access:
 * `Pasien` uses `SoftDeletes` and `whereHas()` applies that scope, so a
 * withdrawn patient profile stops being a route to the conversation.
 *
 * ## Why the id is validated before it is used
 *
 * The wildcard value reaching this method is whatever appeared after the final
 * dot on the wire - a **string**, not an integer. Typing the parameter as `int`
 * (as the plan's literal snippet does) makes a non-numeric id a `TypeError`,
 * which the API exception renderer maps to a 500, so a probe for
 * `private-konsultasi.abc` would be answered with a server error rather than a
 * refusal. This method therefore takes `int|string` and normalises explicitly,
 * so every malformed id lands on the `false` path and is answered 403.
 *
 * The accepted form is deliberately narrower than "castable to int": a bare
 * digit run with no leading zero. That rejects `01`, `1.0`, `+1`, ` 1` and
 * `1e3`, all of which `(int)` would happily collapse onto consultation 1. A
 * second channel therefore cannot be addressed by spelling its id differently,
 * which is the property that makes the refusal meaningful.
 */
final class KonsultasiChannelAccess
{
    /**
     * Determine whether the user is a party to the consultation.
     *
     * @param  int|string  $konsultasiId  The raw wildcard segment from the wire.
     */
    public function allows(User $user, int|string $konsultasiId): bool
    {
        $id = $this->normaliseId($konsultasiId);

        if ($id === null) {
            return false;
        }

        // One query, and it asks the only question that matters: is there a row
        // in `konsultasi` whose patient or doctor profile is owned by this user?
        // A consultation that does not exist and a consultation the user is not
        // party to are the same answer, which is the intended one: both are
        // refusals, and neither should reveal whether the id is real.
        return Konsultasi::query()
            ->whereKey($id)
            ->where(function (Builder $query) use ($user): void {
                $query
                    ->whereHas('pasien', fn (Builder $profiles): Builder => $profiles->where('user_id', $user->getKey()))
                    ->orWhereHas('dokter', fn (Builder $profiles): Builder => $profiles->where('user_id', $user->getKey()));
            })
            ->exists();
    }

    /**
     * Reduce a wire-supplied id to a canonical positive integer, or null.
     *
     * Returns null rather than throwing so the caller has a single refusal path
     * to reason about: a malformed id, an out-of-range id and a non-existent id
     * are all just "no".
     */
    private function normaliseId(int|string $konsultasiId): ?int
    {
        $digits = is_int($konsultasiId)
            ? (string) $konsultasiId
            : $konsultasiId;

        if (preg_match('/^[1-9][0-9]{0,17}$/', $digits) !== 1) {
            return null;
        }

        // The column is BIGINT UNSIGNED, so anything past the 64-bit ceiling is
        // not a consultation. `filter_var` is the check rather than a cast
        // comparison because `(int) '99999999999999999999'` saturates to
        // PHP_INT_MAX and would be handed to the driver as a real id.
        $id = filter_var($digits, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
