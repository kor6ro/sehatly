<?php

declare(strict_types=1);

namespace App\Http\Requests\RekamMedis;

use App\Services\Pasien\PasienRecordAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/rekam-medis/{id}/akses` - the access history of ONE record.
 *
 * ## Only pagination, and that is the whole rule set
 *
 * The list is already narrowed to one `rekam_medis_id` by the path and to the
 * caller's entitlement by `RekamMedisAccess::sisiUntukBaca()` inside the service, so
 * there is nothing left to filter on: the log's five columns are `waktu`, `peran`,
 * `tujuan_akses` and the two identifiers the response does not publish. Adding a
 * `tujuan_akses` filter would be a new disclosure surface with no owner decision
 * behind it, and adding a date filter would let a caller silently hide rows from the
 * very history the endpoint exists to show.
 *
 * ## `per_page` is capped at 100, and the default is the project's 15
 *
 * The same `PasienRecordAccess` ceiling every list in this project uses. The cap is
 * applied again in `RekamMedisService::daftarAkses()`, so it cannot be bypassed.
 *
 * ## `authorize()` is ALWAYS `true`
 *
 * The question "may this caller read this record's access log?" is answered by
 * `RekamMedisAccess::sisiUntukBaca()`, the same resolver `GET /rekam-medis/{id}` uses,
 * inside the service. Answering it here would be a second copy of the ownership rule.
 */
class IndexAksesRekamMedisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.PasienRecordAccess::PER_PAGE_MAX],
        ];
    }

    /**
     * The page size, clamped to the project's ceiling. The default is the
     * project's default, not Laravel's.
     */
    public function perPage(): int
    {
        return max(1, min(
            (int) $this->input('per_page', PasienRecordAccess::PER_PAGE_DEFAULT),
            PasienRecordAccess::PER_PAGE_MAX,
        ));
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'per_page.max' => 'Per halaman maksimal 100.',
        ];
    }
}
