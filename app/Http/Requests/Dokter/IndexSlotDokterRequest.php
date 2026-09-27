<?php

declare(strict_types=1);

namespace App\Http\Requests\Dokter;

use App\Http\Controllers\Api\V1\DokterController;
use App\Services\Booking\SlotAvailabilityService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string validation for `GET /api/v1/dokter/{dokter}/slot`.
 *
 * ## `tanggal` is `required`, and that is a decision rather than a default
 *
 * The plan's todo 26 names exactly one query parameter and gives it one rule:
 * "`tanggal` must be validated with `date_format:Y-m-d` and rejected with 422 if
 * malformed". The service agrees on the shape -- its `@param` says `Y-m-d`, Asia/
 * Jakarta wall clock -- and its {@see SlotAvailabilityService}
 * class docblock says a `date_format:Y-m-d` rule "is the same rule at the edge, and
 * both may coexist". So the two are the same rule stated twice, not two rules: the
 * FormRequest answers at the edge, the service still throws if it is ever called
 * with a raw string.
 *
 * `required` rather than `nullable` because the endpoint has no sensible default.
 * `getSlotTerbuka()` takes one date and answers about that date, and answering
 * about today when the caller did not say would be a silent, and wrong,
 * substitution -- a patient looking at Tuesday would see Monday's availability.
 *
 * ## Why `date_format:Y-m-d` and not `date`
 *
 * `date` accepts a dozen spellings, including relative ones PHP invents
 * ("now", "+1 week") and `2026-12-7` with a single-digit day. A schedule
 * question has exactly one addressable form, and a permissive rule would let a
 * client send `tanggal=now` and get a confidently wrong answer.
 *
 * **The rule also catches the calendar rollover, which is the whole trap.** PHP's
 * `DateTime::createFromFormat('Y-m-d', '2026-13-45')` does not fail: it overflows
 * month 13 and day 45 into 2027-02-14. Laravel's `date_format` rule round-trips
 * the parsed value back through `format()` and compares it to the input, so the
 * overflowed date does not match and the rule fails. The same round trip is what
 * the service's own `tanggalValid()` does, and
 * `DokterJadwalSlotEndpointTest` asserts both agree by driving each rollover
 * spelling through HTTP and getting 422.
 *
 * ## No `page` or `per_page` is accepted, and that is the whole pagination story
 *
 * There is nothing to page. `slots` is the candidate set of ONE date, bounded by
 * the `dokter_jadwal` rows for that weekday, and the weekly read is structurally
 * seven keys that must all be present or a client cannot render a week. A
 * `per_page` here could only ever truncate a set that is already bounded, and
 * truncating it would publish a *lie*: a day with 40 candidates would look like a
 * day with 15, and the untruncated ones would read as nonexistent rather than
 * unavailable. Both routes therefore answer `ApiResponse::singlePageMeta()` and
 * ignore a `per_page` sent anyway, which the test file pins.
 *
 * ## `authorize()` is true for the same reason the directory's is
 *
 * The route is public by the plan's explicit instruction, there is no principal
 * to authorise, and `RbacCatalog` holds no code that fits -- see
 * `DokterController`'s docblock for the full reasoning.
 */
class IndexSlotDokterRequest extends FormRequest
{
    /**
     * The one addressable form of a `DATE` on this endpoint.
     *
     * Spelled as a constant so the rule and the docblock cannot drift, and
     * re-asserted against the DDL in the test file rather than trusted here.
     *
     * @var string
     */
    public const FORMAT_TANGGAL = 'Y-m-d';

    /**
     * Nobody is authorised by a date.
     *
     * @see DokterController for why no `permission:`
     *      or `tipe:` gate appears on this public route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'string', 'date_format:'.self::FORMAT_TANGGAL],
        ];
    }
}
