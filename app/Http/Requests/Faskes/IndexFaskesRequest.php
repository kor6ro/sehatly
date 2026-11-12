<?php

declare(strict_types=1);

namespace App\Http\Requests\Faskes;

use App\Services\Faskes\FaskesDirectoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-string validation for `GET /api/v1/faskes`.
 *
 * ## Four parameters, and what each one is for
 *
 * | parameter | rule | source |
 * | --- | --- | --- |
 * | `tipe` | 5 values, default `apotek` | `faskes.tipe` `ENUM('rumah_sakit','klinik','puskesmas','apotek','laboratorium')` at `2026_10_01_000028_faskes_table.php:78` |
 * | `lat`, `lng` | a WGS-84 pair, each bounded by its own axis | `faskes.latitude` `DECIMAL(10,8)` / `.longitude` `DECIMAL(11,8)` |
 * | `page`, `per_page` | the project-wide list contract, `per_page` capped at 100 | plan todo 21 |
 *
 * **`tipe` defaults rather than requiring itself.** The route exists so the landing
 * page's pharmacy menu has something to open, so "no `tipe`" is answered with the
 * pharmacy list instead of a 422 that would make the most common call the one that
 * fails. The default is applied in {@see prepareForValidation()} rather than in the
 * service, so `validated()` always carries the value the query actually used and a
 * caller cannot receive rows of one type under the impression they asked for another.
 *
 * `Rule::in(FaskesDirectoryService::TIPE_FASKES)` rejects `?tipe=apotekx` with
 * `errors.tipe` rather than returning an empty list. This route is unauthenticated, so
 * a client that cannot tell "I mistyped the filter" from "no venue matches" will ship
 * the typo - which is the same reasoning `IndexDokterRequest` gives for its own `tipe`.
 *
 * **`lat`/`lng` are a pair, or they are nothing.** `required_with` refuses the half
 * pair: measuring a distance from one axis alone is not an approximation, it is a
 * different question. Neither is required, because a visitor who has not granted
 * location permission must still see the panel - the list then falls back to ordering
 * by name and every `jarak_km` in the answer is `null`, which the client renders as
 * "jarak belum tersedia" rather than as a missing row.
 *
 * `nullable` is placed AFTER the `required_with` rules, mirroring
 * `IndexDokterRequest`: `nullable` short-circuits every later rule on a null value,
 * so placing it first would let `?lat=` through as "present but null".
 *
 * ## `per_page` is capped, on an endpoint with no `auth:sanctum`
 *
 * `max:100` is the project-wide cap. It matters most on the routes that gate nothing:
 * this one has neither `auth:sanctum` nor a rate limiter, so an uncapped
 * `?per_page=1000000` would let one anonymous request make the database materialise a
 * million rows to answer a marketing page's menu.
 */
class IndexFaskesRequest extends FormRequest
{
    /**
     * Nobody is authorised by a filter.
     *
     * The list is public for the reason `GET /dokter`, `GET /master-spesialisasi` and
     * `GET /hero` are: it is content the landing page needs before anybody has an
     * account, and `permission:` on a read resolves through `EnsurePermission`, which
     * answers 401 to exactly the signed-out visitor the menu is for.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Put the default `tipe` on the input before the rules see it.
     *
     * Merging rather than falling back downstream, so the value that leaves
     * `validated()` is the value the query used: a service that defaulted on its own
     * would answer rows of one type while the caller's `tipe` sat in the query string
     * looking honoured.
     *
     * An empty `?tipe=` counts as absent (`has()` is true for an empty string) and
     * takes the default too - an empty filter is a filter nobody wrote, not a request
     * for venues whose `tipe` is the empty string, which the ENUM could not hold
     * anyway.
     */
    protected function prepareForValidation(): void
    {
        $tipe = $this->input('tipe');

        if ($tipe === null || $tipe === '') {
            $this->merge(['tipe' => FaskesDirectoryService::TIPE_DEFAULT]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'tipe' => [
                'nullable',
                'string',
                Rule::in(FaskesDirectoryService::TIPE_FASKES),
            ],
            // Ordered BEFORE `nullable`: see the class docblock. `required_with` is
            // what refuses a half pair, and `nullable` would disable it.
            'lat' => [
                'required_with:lng',
                'nullable',
                'numeric',
                'min:-90',
                'max:90',
            ],
            'lng' => [
                'required_with:lat',
                'nullable',
                'numeric',
                'min:-180',
                'max:180',
            ],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.FaskesDirectoryService::PER_PAGE_MAX],
        ];
    }
}
