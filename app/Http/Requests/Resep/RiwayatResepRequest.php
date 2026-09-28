<?php

declare(strict_types=1);

namespace App\Http\Requests\Resep;

use App\Enums\ResepStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/pasien/resep` - the caller's own prescription history.
 *
 * ## `status` is a filter over the EIGHT members, and a typo is a 422
 *
 * `resep.status` is `ENUM('aktif','diproses','diverifikasi','dipulumi',
 * 'dikirim','selesai','kedaluwarsa','dibatalkan')` at
 * `telemedicine_test.sql:751`-`:752` - eight, in an ENUM that WRAPS onto a
 * second line, which is why the plan's `IN ('aktif','diproses')` for "currently
 * on" reads as complete and is not. Every member is accepted here, including
 * the three terminal ones, because a patient asking "what happened to that
 * prescription" is asking about the ones that did NOT work.
 *
 * A value outside the ENUM is a 422 naming the field rather than a silently
 * ignored filter. The alternative - dropping it and returning the whole list -
 * reads to a client as "no such state has any prescriptions", which is a
 * different and wrong claim.
 *
 * `per_page` and `page` are the project-wide pair that
 * `ApiResponse::pageMeta()` reports on; the 100 cap is
 * `PasienRecordAccess::PER_PAGE_MAX` and is applied again in the service, so a
 * value arriving from anywhere else cannot bypass it.
 */
class RiwayatResepRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::enum(ResepStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
