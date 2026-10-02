<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Admin\AdminDokterService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /admin/dokter` - the admin directory's filters.
 *
 * ## `q` is length-bounded and accepted literally
 *
 * `q` searches `users.nama_lengkap` OR `dokter.nomor_str`; `%` and `_` are
 * accepted rather than rejected (a doctor may be named "100%") and are
 * neutralised as LIKE metacharacters in {@see AdminDokterService}. The bound is
 * `users.nama_lengkap`'s own width (`VARCHAR(150)`, telemedicine_test.sql:135),
 * which is also wide enough for an STR number (`VARCHAR(30)`, :413).
 *
 * ## Every value filter draws a closed vocabulary
 *
 * | filter | vocabulary | DDL |
 * | --- | --- | --- |
 * | `status_verifikasi` | `pending`, `terverifikasi`, `ditolak` | `dokter.status_verifikasi` `:427` |
 * | `status_aktif` | boolean | `TINYINT(1) NOT NULL DEFAULT 1` `:430` |
 * | `tersedia_telemedisin` | boolean | `TINYINT(1) NOT NULL DEFAULT 1` `:426` |
 * | `urutan` | the three sorts the contract names | not a column; a query vocabulary |
 *
 * A value outside one of those is a **422 naming the field**, not a silently
 * empty page: an operator who typed `?status_verifikasi=terverifikasi ` with a
 * trailing space, or `?urutan=nama_lengkap`, must be told, because "no doctors
 * match" and "your filter is misspelled" are different facts and the wrong one
 * sends them looking for a data problem that does not exist.
 *
 * ## `per_page` is capped at the project ceiling
 *
 * `max:100` is the same ceiling every other list endpoint applies. This route is
 * authenticated and admin-only, so the cap is a consistency rule rather than a
 * public-abuse defence - but one number in one place is what keeps `meta.per_page`
 * meaningful across the API.
 */
class IndexAdminDokterRequest extends FormRequest
{
    /**
     * The middleware is the authorisation. `tipe:admin,superadmin` plus
     * `permission:dokter.lihat` run before this class, so there is no principal
     * question left for a filter to answer.
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
            'q' => ['nullable', 'string', 'max:'.AdminDokterService::SEARCH_MAX],
            'status_verifikasi' => [
                'nullable',
                'string',
                Rule::in(AdminDokterService::STATUS_VERIFIKASI),
            ],
            'status_aktif' => ['nullable', 'boolean'],
            'tersedia_telemedisin' => ['nullable', 'boolean'],
            'urutan' => ['nullable', 'string', Rule::in(['str_berlaku_sampai', 'nama', 'jumlah_konsultasi'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.AdminDokterService::PER_PAGE_MAX],
        ];
    }
}
