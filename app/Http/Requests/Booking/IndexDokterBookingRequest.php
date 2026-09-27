<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

/**
 * Validates the query string of `GET /api/v1/dokter/booking`.
 *
 * Adds the consultation-date filter to the patient-list contract. The date is
 * a `date_format` check for the same reason the create's is: an overflowing
 * `tanggal` must be a 422 on the field, not a silently empty list.
 */
class IndexDokterBookingRequest extends IndexBookingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'tanggal' => 'tanggal kunjungan',
        ]);
    }
}
