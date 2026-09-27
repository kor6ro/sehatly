<?php

declare(strict_types=1);

namespace App\Http\Requests\Pasien;

use App\Services\Pasien\PasienRecordAccess;

/**
 * The `?page=` / `?per_page=` contract shared by every patient list endpoint.
 *
 * ## The 100 cap is the plan's, and it is applied twice on purpose
 *
 * `max:100` here means a caller who asks for 5000 gets a 422 naming the field, which is
 * the answer a client can act on. {@see PasienRecordAccess::perPage()}
 * clamps the same number again before it reaches the paginator, so a `per_page` arriving
 * from a path that skipped this request - a future internal caller, a second validation
 * route - still cannot ask for an unbounded page. A rule alone is a convention; a rule
 * plus a clamp is a property.
 *
 * `page` is validated as an integer rather than left to the paginator, because
 * `AbstractPaginator::resolveCurrentPage()` silently falls back to page 1 for anything it
 * cannot parse. A client sending `page: "abc"` would then be told "here is page one" and
 * would have no way to tell that its pagination is broken. A 422 says so.
 *
 * Both fields are `sometimes`, so a bare `GET` with no query string is valid and the
 * controller falls back to {@see PasienRecordAccess::PER_PAGE_DEFAULT}.
 */
abstract class IndexPasienRequest extends PasienRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.PasienRecordAccess::PER_PAGE_MAX],
        ];
    }

    /**
     * The page size to paginate with, already clamped to the plan's cap.
     *
     * Reads the *validated* value rather than the raw input, so the clamp can never be
     * skipped by a caller that bypassed the rule.
     */
    public function perPage(): int
    {
        return app(PasienRecordAccess::class)->perPage(
            (int) $this->validated('per_page', PasienRecordAccess::PER_PAGE_DEFAULT),
        );
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'page' => 'halaman',
            'per_page' => 'jumlah per halaman',
        ];
    }
}
