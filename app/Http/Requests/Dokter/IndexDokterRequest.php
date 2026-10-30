<?php

declare(strict_types=1);

namespace App\Http\Requests\Dokter;

use App\Services\Dokter\DokterDirectoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-string validation for `GET /api/v1/dokter`.
 *
 * ## Why every unknown filter is a 422 here rather than an empty list
 *
 * Every filter that is not free text draws from a **closed vocabulary**, not from
 * user-authored data:
 *
 * | filter | vocabulary | source |
 * | --- | --- | --- |
 * | `tipe` | 7 values | `dokter.tipe` `ENUM(...)` at `:412` |
 * | `spesialisasi` | 16 codes or their ids | `master_spesialisasi.kode` / `.id` at `:404` / `:403` |
 * | `search` | free text over `users.nama_lengkap` | `VARCHAR(150) NOT NULL` at `:135` |
 * | `tersedia_telemedisin` | a boolean | `TINYINT(1) NOT NULL DEFAULT 1` at `:426` |
 * | `sort` | 7 values | F03 §4.4's approved six plus `terdekat`, {@see DokterDirectoryService::SORT_VALUES} - the seventh is a §12 butir 8(i) deviation |
 * | `lat`, `lng` | a WGS-84 pair, each bounded by its own axis | `faskes.latitude` `DECIMAL(10,8)` / `.longitude` `DECIMAL(11,8)` at `:393`/`:394` - the column's own precision is the reason a coordinate out of that range cannot correspond to any row |
 *
 * **`lat`/`lng` exist only to be ordered by, and they are required exactly when
 * they are.** `required_if:sort,terdekat` refuses `?sort=terdekat` without a
 * position rather than falling back to `relevan`: a caller asking for "nearest"
 * and silently receiving "most reviewed" has been told a lie it cannot detect,
 * and this endpoint is unauthenticated, so there is no session whose saved
 * location could stand in for the missing pair. The reverse is not an error -
 * a coordinate with no `sort=terdekat` is simply unused, and it is left in the
 * query string so a client that holds a position can keep holding it while it
 * sorts some other way.
 *
 * `required_with` closes the half-pair: a latitude and a longitude are one
 * position, and measuring a distance from an axis alone is not an approximation,
 * it is a different question.
 *
 * **A coordinate never reaches the URL of the answer the patient sees** - that
 * is F03 §9's rule for free text, and it applies to a position for the same
 * reason. It travels on this endpoint's query string only, which is a request
 * the client makes, never an address it shares.
 *
 * `sort` is the one row with no DDL behind it, so the contract is F03 §4.4
 * itself. Its vocabulary lives on the service as a constant that this rule and
 * {@see DokterDirectoryService::applySort()} both consume, so the value the
 * generated contract publishes and the value the query actually applies cannot
 * drift; `relevan` is the default and is the pre-F03 order unchanged.
 *
 * `Rule::in(DokterDirectoryService::TIPE_DOKTER)` therefore rejects
 * `?tipe=dokter` and `?tipe=spesialis` with `errors.tipe` rather than quietly
 * returning nothing. That matters more here than on a private endpoint: this one is
 * unauthenticated, so a client that cannot tell "I typed the wrong value" from "no
 * doctor matches" will ship the typo.
 *
 * **`spesialisasi` is validated as *some* `master_spesialisasi` row, by code or by
 * id.** `Rule::exists('master_spesialisasi', 'kode')` and
 * `Rule::exists('master_spesialisasi', 'id')` cannot be OR-ed together, and the
 * endpoint genuinely accepts both (the plan's todo 22 says "kode or id"), so the
 * two are combined into one `Rule::exists` whose column list is built from the
 * value's own shape. The bound is the DDL's own `kode` width (`:404`), which is
 * also wide enough for a `SMALLINT UNSIGNED` id.
 *
 * ## `per_page` is capped, on an unauthenticated endpoint
 *
 * `max:100` is the project-wide cap the plan's todo 21 sets for every list
 * endpoint. It matters most here: this route has no `auth:sanctum` and no rate
 * limiter, so an uncapped `per_page=1000000` would let one anonymous request make
 * the database materialise a million joined rows.
 *
 * ## `search` is length-bounded but not pattern-validated
 *
 * `%` and `_` are *accepted*, not rejected - a doctor can legitimately be called
 * "dr. 100%" - and are neutralised as LIKE metacharacters in
 * {@see DokterDirectoryService}. Rejecting them would be a worse answer than
 * treating them literally.
 */
class IndexDokterRequest extends FormRequest
{
    /**
     * Nobody is authorised by a filter.
     *
     * The directory is public by the plan's explicit instruction (its todo 22: "GET
     * /api/v1/dokter is **public** (no auth)"), so there is no principal to
     * authorise. See `DokterController`'s docblock for the `RbacCatalog` reasoning
     * behind not adding `permission:dokter.lihat`.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the `tersedia_telemedisin` query flag before the `boolean`
     * rule sees it.
     *
     * A query string carries only text, so `?tersedia_telemedisin=true`
     * arrives as the STRING `"true"` - and Laravel's `boolean` rule accepts
     * only `true, false, 1, 0, "1", "0"`, answering 422 on `"true"`/`"false"`.
     * `filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)`
     * folds every recognised spelling onto a real boolean, while an
     * unrecognised string is left untouched so the `boolean` rule still
     * refuses it by name (`?tersedia_telemedisin=maybe` was, and stays, a
     * 422). Absent stays absent: no filter must narrow the directory. The
     * same pattern, and the same reasoning, lives in {@see SearchObatRequest}.
     *
     * The 422 is the LUCKY half of the problem. The dangerous half is
     * downstream: `(bool) "false"` is TRUE, because the string is not empty,
     * so a filter built on that cast hands a caller who asked for
     * non-telemedicine doctors exactly the telemedicine ones, with no error
     * anywhere to notice it by. Normalising here means the value that leaves
     * `validated()` is a real `bool`, and
     * {@see DokterDirectoryService::applyTersediaTelemedisin()} reads it with
     * the same `filter_var` either way.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('tersedia_telemedisin')) {
            return;
        }

        $mentah = $this->input('tersedia_telemedisin');

        if (is_bool($mentah) || $mentah === null || $mentah === '') {
            return;
        }

        $baca = filter_var($mentah, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($baca !== null) {
            $this->merge(['tersedia_telemedisin' => $baca]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'spesialisasi' => [
                'nullable',
                'string',
                'max:'.DokterDirectoryService::SPESIALISASI_MAX,
                Rule::exists('master_spesialisasi', $this->spesialisasiLookupColumn()),
            ],
            'tipe' => [
                'nullable',
                'string',
                Rule::in(DokterDirectoryService::TIPE_DOKTER),
            ],
            'search' => [
                'nullable',
                'string',
                'max:'.DokterDirectoryService::SEARCH_MAX,
            ],
            'tersedia_telemedisin' => ['nullable', 'boolean'],
            'sort' => [
                'nullable',
                'string',
                Rule::in(DokterDirectoryService::SORT_VALUES),
            ],
            // Ordered BEFORE `nullable`: `nullable` short-circuits every later rule
            // on a null value, so placing it first would let `?sort=terdekat&lat=`
            // through as "present but null" - which is the exact request this pair
            // exists to refuse.
            'lat' => [
                'required_if:sort,terdekat',
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.DokterDirectoryService::PER_PAGE_MAX],
        ];
    }

    /**
     * Which `master_spesialisasi` column the `spesialisasi` value is looked up by.
     *
     * A non-numeric value is a `kode` (`SP.PD`, `UMUM`, `GIGI` - all 16 of the DDL's
     * seed codes are non-numeric) and a numeric one is an `id`. Returning the column
     * name rather than a `Rule::exists` keeps the whole decision in one expression,
     * so the validator and {@see DokterDirectoryService}'s query cannot drift about
     * which vocabulary a given value belongs to.
     */
    private function spesialisasiLookupColumn(): string
    {
        $nilai = $this->input('spesialisasi');

        return is_string($nilai) && ctype_digit($nilai) ? 'id' : 'kode';
    }
}
