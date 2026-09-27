<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

use App\Services\Pasien\PasienRecordAccess;
use Illuminate\Validation\Rule;

/**
 * Validates the query string of `GET /api/v1/pasien/booking`.
 *
 * An out-of-enum `status` is a 422 naming the field, not an empty list: an
 * empty list would tell the caller the filter matched nothing, when the truth
 * is the filter itself is not a status.
 */
class IndexBookingRequest extends BookingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(self::STATUS_SEMUA)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.PasienRecordAccess::PER_PAGE_MAX],
            'page' => ['sometimes', 'integer', 'min:1'],
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
            'status' => 'status',
            'per_page' => 'jumlah per halaman',
            'page' => 'halaman',
        ];
    }
}
