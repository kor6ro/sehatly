<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Admin\AdminPenggunaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/admin/pengguna` - the account directory's filters.
 *
 * ## Every non-text filter draws a closed vocabulary, and `staf` is an alias
 *
 * | filter | vocabulary | source |
 * | --- | --- | --- |
 * | `tipe` | `staf` (an alias) + the DDL's eight `users.tipe` values | `telemedicine_test.sql:139`, {@see AdminPenggunaService::TIPE_FILTER} |
 * | `status` | the four `users.status` values | `telemedicine_test.sql:140`, {@see AdminPenggunaService::STATUS_DIRECTORY} |
 * | `dibuat_dari` | one `Y-m-d` date | the dashboard's growth cell counts from it |
 *
 * A value outside the vocabulary is a **422 naming the field**, the same
 * reasoning `IndexAdminDokterRequest` records: "no accounts match" and "your
 * filter is misspelled" are different facts, and the wrong one sends an operator
 * looking for a data problem that does not exist. `staf` is the one value that
 * is NOT a column value - it expands in the service to the five staff types -
 * and it lives in the service's constant so the filter, the dashboard count and
 * the sidebar link cannot drift apart.
 *
 * `q` is length-bounded to `users.nama_lengkap`'s own width and accepted
 * literally; `%` and `_` are neutralised as LIKE metacharacters in
 * {@see AdminPenggunaService::applySearch()} rather than rejected, because a
 * person may legitimately be named with a percent sign.
 *
 * `authorize()` is always true: the route carries `auth:sanctum`,
 * `tipe:admin,superadmin` AND `permission:pengguna.lihat`, and the request has
 * no per-row ownership question to answer.
 */
class IndexPenggunaRequest extends FormRequest
{
    /**
     * Nobody is authorised by the request itself - see the class docblock.
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
            'tipe' => ['nullable', 'string', Rule::in(AdminPenggunaService::TIPE_FILTER)],
            'status' => ['nullable', 'string', Rule::in(AdminPenggunaService::STATUS_DIRECTORY)],
            'q' => ['nullable', 'string', 'max:'.AdminPenggunaService::SEARCH_MAX],
            'dibuat_dari' => ['nullable', 'string', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.AdminPenggunaService::PER_PAGE_MAX,
            ],
        ];
    }

    /**
     * Field labels, so a 422 the client renders does not have to ship a second
     * copy of this project's column names.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipe' => 'tipe akun',
            'status' => 'status',
            'q' => 'kata kunci',
            'dibuat_dari' => 'dibuat sejak',
            'per_page' => 'jumlah per halaman',
        ];
    }
}
