<?php

declare(strict_types=1);

namespace App\Http\Requests\Dokter;

use App\Services\Dokter\UlasanDokterService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-string validation for `GET /api/v1/dokter/{dokter}/ulasan`.
 *
 * ## A closed vocabulary, so a typo is a 422 rather than an empty page
 *
 * `sort` is the three values `membantu` is deliberately absent from (it needs a
 * vote table the schema does not have), and `rating` is the DDL's own checked
 * range. `?sort=helpful` or `?rating=6` answers `errors.sort` / `errors.rating`
 * instead of quietly returning the default order and every review, which is the
 * distinction a client needs to notice its own bug.
 *
 * ## `per_page` is capped at 50, not 100
 *
 * The F04 contract names 50 for this endpoint. It matters more here than
 * elsewhere: the route is public and unauthenticated, so an uncapped `per_page`
 * would let one anonymous request make the database materialise an unbounded
 * joined list.
 *
 * ## `authorize()` is true because the directory is public
 *
 * There is no principal to authorise on a route the plan makes pre-authentication,
 * and `RbacCatalog` holds no review code that could gate a read -
 * `DokterController`'s docblock carries the full argument.
 */
class IndexUlasanDokterRequest extends FormRequest
{
    /**
     * Nobody is authorised by a filter.
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
            'rating' => [
                'nullable',
                'integer',
                'between:'.UlasanDokterService::RATING_MIN.','.UlasanDokterService::RATING_MAKS,
            ],
            'sort' => [
                'nullable',
                'string',
                Rule::in(UlasanDokterService::SORT_VALUES),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.UlasanDokterService::PER_PAGE_MAX],
        ];
    }

    /**
     * Indonesian labels, per the project convention (`attributes()`, never
     * `messages()`).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rating' => 'rating',
            'sort' => 'urutan',
            'page' => 'halaman',
            'per_page' => 'jumlah per halaman',
        ];
    }
}
