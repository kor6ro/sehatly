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
 * All four filters draw from a **closed vocabulary the DDL defines**, not from
 * user-authored data:
 *
 * | filter | vocabulary | DDL |
 * | --- | --- | --- |
 * | `tipe` | 7 values | `dokter.tipe` `ENUM(...)` at `:412` |
 * | `spesialisasi` | 16 codes or their ids | `master_spesialisasi.kode` / `.id` at `:404` / `:403` |
 * | `search` | free text over `users.nama_lengkap` | `VARCHAR(150) NOT NULL` at `:135` |
 * | `tersedia_telemedisin` | a boolean | `TINYINT(1) NOT NULL DEFAULT 1` at `:426` |
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
