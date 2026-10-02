<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * The shared date-range rules of F14's three reports.
 *
 * ## `Y-m-d` exactly, and the calendar rollover is refused
 *
 * `date_format:Y-m-d` uses a strict round trip: `2026-13-45` is rejected rather
 * than silently rolled into 2027-02-14, which is the failure mode
 * `SlotAvailabilityService::tanggalValid()` documents for PHP's own parser. A
 * report about a date that does not exist is a client bug, and the 422 names the
 * field.
 *
 * ## `sampai >= dari`, and a maximum span
 *
 * Both are cross-field, so they run in an `after()` hook rather than as rules:
 *
 * - a reversed range is a 422 on `sampai`, not an empty report, because the
 *   empty report would read as "no activity";
 * - the span is capped at 366 days INCLUSIVE (the F14 contract's ceiling),
 *   because a range query is the one way this admin surface could ask the
 *   database for an unbounded scan. 366 rather than 365 so a leap year's full
 *   span is exactly allowed.
 *
 * ## Why an abstract base and not a trait
 *
 * The three reports must refuse the same ranges for the same reasons, and the
 * subclasses differ only in whether they add `dokter_id`. A base class makes the
 * shared rules and the shared refusal text literally one array; a trait would
 * too, but a base also carries the `FormRequest` contract and lets a test
 * instantiate the base's rules through a subclass. The subclasses are one
 * method each and exist so every endpoint has its OWN FormRequest type - the
 * `sehatly:openapi` DoD check reads the injected parameter's type, and one
 * shared type across three endpoints would make the contract less specific than
 * the routes.
 */
abstract class LaporanRangeRequest extends FormRequest
{
    /**
     * The longest range a report accepts, inclusive: 366 days.
     *
     * F14's contract says "≤366 hari", and inclusive arithmetic is what an
     * operator means by "1 Jan - 31 Dec" (365 days in a common year, 366 in a
     * leap year).
     */
    public const MAX_HARI = 366;

    /**
     * The actions are `GET`s whose authorisation is entirely middleware
     * (`tipe:admin,superadmin` plus `permission:laporan.lihat`). A query string
     * cannot grant anything.
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
            'dari' => ['required', 'date_format:Y-m-d'],
            'sampai' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $dari = $this->input('dari');
                $sampai = $this->input('sampai');

                // Let the format rules speak first: comparing a malformed string
                // would produce a second, less useful message about the same key.
                if (! is_string($dari) || ! is_string($sampai)) {
                    return;
                }

                if ($validator->errors()->has('dari') || $validator->errors()->has('sampai')) {
                    return;
                }

                if ($sampai < $dari) {
                    $validator->errors()->add(
                        'sampai',
                        'Tanggal sampai tidak boleh lebih awal dari tanggal mulai.',
                    );

                    return;
                }

                // Inclusive span: 0 means one day, 365 means 366 days.
                if (Carbon::parse($dari)->diffInDays(Carbon::parse($sampai)) > self::MAX_HARI - 1) {
                    $validator->errors()->add(
                        'sampai',
                        'Rentang laporan maksimal '.self::MAX_HARI.' hari.',
                    );
                }
            },
        ];
    }
}
