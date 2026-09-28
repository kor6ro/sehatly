<?php

declare(strict_types=1);

namespace App\Services\RekamMedis;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The one capability that lets a `rekam_medis` row be hydrated at all.
 *
 * ## Why this class exists, and what it replaces
 *
 * The plan's todo 33 asks for the access log to be enforced "structurally rather
 * than by convention", and names the mechanism it had in mind: an architecture
 * test that greps `app/` for a stray `RekamMedis::find(`. **A grep is a
 * convention.** It catches one spelling of one call, it cannot see a relation
 * traversal or a `chunk()`, and a future author who does not know about it
 * defeats it without doing anything that looks wrong.
 *
 * So the control here is a MODEL EVENT, not a grep. `RekamMedis` and its four
 * children use the `GuardsMedicalRecordRead` trait, whose `retrieved` listener
 * asks this class for permission and throws when there is none. Eloquent fires
 * `retrieved` once for every model it hydrates, whatever produced it, so the
 * answer to "is there an unguarded read path?" is: no. There is exactly one
 * method that opens the gate, it is {@see dalam()}, and its only caller is
 * {@see RekamMedisAccessLogger::baca()}, which has already written the log row
 * inside the same transaction.
 *
 * The grep is still in the suite, as a backstop that names the exact spellings a
 * human is most likely to type. It is a smell detector, not the lock.
 *
 * ## What the scope permits, and why it is a GROUP rather than a row
 *
 * A read of `rekam_medis/{id}` also publishes the amendment chain, which means
 * hydrating every sibling revision. Hydrating them is part of the SAME disclosure
 * - one response, one request, one purpose - and the access log names ONE
 * `rekam_medis_id` (:1149), a single foreign key to a single row.
 *
 * So the permit is the chain GROUP - `(pasien_id, dokter_id, tanggal_periksa)` -
 * rather than the single row. That is a superset of permitting one row, and the
 * only code that ever runs inside the scope is this service's own loader, so the
 * superset grants nothing to a caller: a caller cannot choose which rows to
 * hydrate.
 *
 * Children carry only `rekam_medis_id`, not the group triple, so they are checked
 * against an explicit id SET. For a single-record read that set is one element,
 * and for a chain read it is every id in the group. The set is computed with
 * `DB::table()->pluck('id')`, which does NOT hydrate a model, so listing the
 * group cannot itself trip the guard.
 *
 * ## The state is static and that is deliberate
 *
 * PHP has no thread here, one request is one PHP process, and a leak would be far
 * worse than a non-reentrancy limitation. The state is therefore saved and
 * RESTORED rather than merely nulled: a nested {@see dalam()} puts the previous
 * permit back instead of dropping it, so the outermost reader is the one that
 * decides whether the gate stays open. {@see sedangBerjalan()} answers `false`
 * outside any scope and the suite asserts it, which is what makes "no unguarded
 * read path" a checked property rather than a claim.
 *
 * The one thing this does not survive is a process that keeps serving requests
 * without rebinding - Octane, or a queue worker draining jobs between requests.
 * Neither is configured in this application (there is no `octane` dependency and
 * `QUEUE_CONNECTION` is `sync`), and the alternative - a per-request container
 * singleton - would be indistinguishable from this in a `RefreshDatabase` test
 * while being considerably harder to reason about. It is recorded rather than
 * claimed to be impossible.
 */
final class RekamMedisReadScope
{
    /**
     * The chain group of the record currently being read:
     * `[pasien_id, dokter_id, 'Y-m-d H:i:s']`, or null when no scope is open.
     *
     * @var array{0: int, 1: int, 2: string}|null
     */
    private static ?array $grup = null;

    /**
     * The `rekam_medis.id` values whose child rows may be hydrated.
     *
     * @var array<int, true>
     */
    private static array $ids = [];

    /**
     * Run `$callback` with the gate open for `$grup` and `$ids`, and close it again.
     *
     * The save/restore in the `finally` is the whole reason a leaked permit is not
     * possible: a callback that throws still leaves the previous state in place,
     * and the suite asserts that a read attempted after a throwing read is refused.
     *
     * @param  array{0: int, 1: int, 2: string}  $grup
     * @param  list<int>  $ids
     */
    public static function dalam(array $grup, array $ids, Closure $callback): mixed
    {
        $grupSebelumnya = self::$grup;
        $idsSebelumnya = self::$ids;

        self::$grup = $grup;
        self::$ids = array_fill_keys(array_map(intval(...), $ids), true);

        try {
            return $callback();
        } finally {
            self::$grup = $grupSebelumnya;
            self::$ids = $idsSebelumnya;
        }
    }

    /**
     * Is a read gate open right now?
     */
    public static function sedangBerjalan(): bool
    {
        return self::$grup !== null;
    }

    /**
     * May this `rekam_medis` row be hydrated?
     *
     * @param  int  $pasienId  the row's `pasien_id` (:624)
     * @param  int  $dokterId  the row's `dokter_id` (:626)
     * @param  string  $tanggalPeriksa  the row's `tanggal_periksa` as `Y-m-d H:i:s` (:630)
     * @param  string  $model  the concrete model class, named in the refusal
     */
    public static function izinkanAkar(int $pasienId, int $dokterId, string $tanggalPeriksa, string $model): bool
    {
        if (self::$grup === null) {
            throw new RuntimeException(
                'A '.$model.' row was hydrated outside a RekamMedisReadScope, so no '
                .'akses_rekam_medis_log row was written for it. Reading a medical record is only possible through '
                .'App\\Services\\RekamMedis\\RekamMedisService::findForAccess(), which writes the access log inside the '
                .'same transaction as the read. This is a programming error, not an authorisation decision.'
            );
        }

        return self::$grup === [$pasienId, $dokterId, $tanggalPeriksa];
    }

    /**
     * May this child row be hydrated?
     *
     * @param  int  $rekamMedisId  the child's `rekam_medis_id`
     * @param  string  $model  the concrete model class, named in the refusal
     */
    public static function izinkanAnak(int $rekamMedisId, string $model): bool
    {
        if (self::$grup === null) {
            throw new RuntimeException(
                'A '.$model.' row was hydrated outside a RekamMedisReadScope, so no '
                .'akses_rekam_medis_log row was written for it. Reading a medical record is only possible through '
                .'App\\Services\\RekamMedis\\RekamMedisService::findForAccess(), which writes the access log inside the '
                .'same transaction as the read. This is a programming error, not an authorisation decision.'
            );
        }

        return isset(self::$ids[$rekamMedisId]);
    }

    /**
     * Normalise a `tanggal_periksa` attribute to the exact string the group uses.
     *
     * `rekam_medis.tanggal_periksa` is cast to `datetime` (:630 in the DDL, and
     * `RekamMedis::casts()`), and on laravel/framework 13.33 the value that comes
     * back is a `Carbon\CarbonImmutable`. Formatting rather than string-casting is
     * what makes the group key stable across a cast value, a raw driver string and
     * a `Carbon` someone assigned by hand - all three have to produce the same
     * `Y-m-d H:i:s`, because a group key that changes shape with the access path
     * would silently split one chain into several.
     */
    public static function tanggalAsString(mixed $nilai): string
    {
        if ($nilai instanceof DateTimeInterface) {
            return Carbon::instance($nilai)->format('Y-m-d H:i:s');
        }

        return Carbon::parse((string) $nilai)->format('Y-m-d H:i:s');
    }
}
