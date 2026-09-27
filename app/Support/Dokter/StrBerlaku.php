<?php

declare(strict_types=1);

namespace App\Support\Dokter;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The one place the STR licence boundary is decided.
 *
 * ## Why this class exists at all
 *
 * `dokter.str_berlaku_sampai DATE NOT NULL` is `telemedicine_test.sql:414`.
 * Two features need to know whether a doctor's licence covers a given day, and
 * they need to agree:
 *
 * - `App\Services\Dokter\DokterDirectoryService` asks "may this doctor be
 *   *listed*?", against today.
 * - `App\Services\Booking\SlotAvailabilityService` asks "may a consultation
 *   *happen* on date D?", against D.
 *
 * Those are different questions with different reference days, and that is
 * deliberate -- see the reference-day section below. What must NOT differ is
 * the **boundary itself**: which side of the date the licence is valid on, and
 * how an unknown expiry is read. Two spellings of that would be two sources of
 * truth, and the one that is looser is the one that would let an unlicensed
 * doctor take a consultation. So both callers come here.
 *
 * ## The boundary is INCLUSIVE, and that is a decision rather than a default
 *
 * `str_berlaku_sampai` is a `DATE`, not a `DATETIME`. It has no time-of-day
 * component, so it cannot lapse at some instant during the day: the last moment
 * of validity is the **end** of the date it names. `berlaku sampai 2026-09-27`
 * therefore reads as "valid through 2026-09-27", and the operator is `>=`.
 *
 * This is not a new decision. It was reached and pinned in todo 22 and is
 * restated here because the constant has to be stated once. The off-by-one in
 * the other direction is not a safety win either: it would hide a
 * currently-licensed doctor for a whole day, which is a bookable-consultation
 * denial rather than a patient-safety protection.
 *
 * {@see \Tests\Feature\Dokter\DokterDirectoryTest} pins it on the exact
 * boundary date on both sides through the directory, and
 * {@see \Tests\Feature\Dokter\SlotAvailabilityTest} pins it through this class
 * on the exact consultation date.
 *
 * ## What a NULL expiry means: EXCLUDED
 *
 * `str_berlaku_sampai` is `DATE NOT NULL` (`:414`), so the DDL makes a NULL
 * impossible -- MySQL rejects one with 1048 regardless of `sql_mode`, because
 * `NOT NULL` is a hard constraint and not a strict-mode warning. Both test files
 * re-parse `:414` with the project's own `SqlSchemaParser` and assert the
 * column is non-nullable, so the claim rests on the DDL rather than on this
 * paragraph.
 *
 * The predicate still carries `whereNotNull`, and {@see berlakuPada()} still
 * answers `false` for `null`, because the predicate has to be **well-defined**
 * rather than merely **currently unreachable**. "We do not know when this
 * licence ends" is not evidence that the licence is valid, so the reading is
 * fail-closed. If the column were ever relaxed to nullable, the code already
 * excludes the unknown rather than admitting it.
 *
 * ## The reference day, and why the two callers use different ones
 *
 * - The **directory** compares against today, because the question is whether a
 *   patient may see the doctor in the directory *now*.
 * - The **slot service** compares against the requested consultation date,
 *   because the question is whether a specific visit may proceed on that day.
 *
 * Answering the second with the first would be a patient-safety hole, not a
 * convenience: a doctor's licence could run out next week, and nothing in the
 * schema invalidates them the day it passes (the plan's own constraint list
 * names that gap at `:207`), so today-based filtering would let a patient book
 * a consultation months after the licence lapsed. It is still the *same* rule --
 * same operator, same inclusivity, same fail-closed NULL handling -- applied to
 * the day the answer is actually about.
 *
 * ## Why `whereDate()` and not a bare comparison
 *
 * `whereDate()` renders `date(column) >= ?` and, importantly, formats a
 * `DateTimeInterface` argument to `Y-m-d` before binding it. On a `DATE` column
 * the `date()` wrapper is a semantic no-op, and it is kept because it makes the
 * calendar-day comparison explicit at the call site rather than leaving a bare
 * `>=` to be read as an instant comparison. `DokterDirectoryTest` asserts the
 * bound value is exactly `Y-m-d`, and that assertion is what would catch the
 * binding being widened to a datetime.
 */
final class StrBerlaku
{
    /**
     * The boundary operator, inclusive.
     *
     * Public so a test can assert the codebase has exactly one spelling of it
     * rather than two that happen to agree today.
     *
     * @var string
     */
    public const OPERATOR_BATAS = '>=';

    /**
     * The wall-clock reference the `DATE` is read in.
     *
     * The column has no zone and the rest of the schema stores naive wall clock;
     * the service layer never converts a slot time, so this is the zone the
     * values are authored in. It is published so a response can carry it, and it
     * is deliberately NOT `config('app.timezone')`, which is UTC.
     *
     * @var string
     */
    public const REFERENSI = 'Asia/Jakarta';

    /**
     * No instances: every method is a pure decision.
     */
    private function __construct()
    {
    }

    /**
     * Is the licence still valid on `$hari`?
     *
     * The comparison is `str_berlaku_sampai >= $hari`, spelled in that order so
     * it reads exactly like the SQL this class emits. The operands are NOT
     * interchangeable: `Carbon::gte` is the expiry on the left, and writing it
     * the other way round answers "has this day passed since the licence
     * expired", which is true for almost every historical date and therefore
     * admits every lapsed doctor ever recorded. That inversion was written,
     * caught by the boundary tests and is why they are written on the exact
     * date rather than on "some old date".
     *
     * `$strBerlakuSampai` is accepted as a `DateTimeInterface` because
     * `Dokter::$casts` maps the column to `date`, and as a `Y-m-d` string
     * because a raw `DB::table()` read hands back the raw string. Both are
     * normalised to a calendar day before the comparison, so a timestamp
     * carrying a time of day can never shift the boundary.
     *
     * @param  DateTimeInterface|string|null  $strBerlakuSampai
     */
    public static function berlakuPada(DateTimeInterface|string|null $strBerlakuSampai, Carbon $hari): bool
    {
        if ($strBerlakuSampai === null) {
            return false;
        }

        $kedaluwarsa = $strBerlakuSampai instanceof DateTimeInterface
            ? Carbon::instance($strBerlakuSampai)
            : Carbon::parse($strBerlakuSampai);

        return $kedaluwarsa->startOfDay()->gte($hari->copy()->startOfDay());
    }

    /**
     * Add the boundary to a query, grouped.
     *
     * Nested inside a `where(function ...)` rather than applied as two sibling
     * `where` calls because a caller may itself be inside a nested closure; the
     * grouping keeps the `NOT NULL` and the comparison from ever being separated
     * by an `or` that a future filter introduces. The `or` case is the one that
     * would matter: `A OR B` with `A` being a two-clause rule splits into
     * `A1 OR A2`, and `A2` alone admits the NULL.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function terapkan(Builder $query, string $kolom, Carbon $hari): void
    {
        $query->where(function ($inner) use ($kolom, $hari): void {
            $inner
                ->whereNotNull($kolom)
                ->whereDate($kolom, self::OPERATOR_BATAS, $hari->copy()->startOfDay());
        });
    }
}
