<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

/**
 * `GET /admin/laporan/booking` - the booking report's range plus one filter.
 *
 * `dokter_id` is validated as a REAL `dokter` row (`Rule::exists`), so a typo'd
 * or deleted id is a 422 naming the field rather than a report of zeros that
 * looks like a data problem.
 */
class LaporanBookingRequest extends LaporanRangeRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'dokter_id' => ['nullable', 'integer', 'exists:dokter,id'],
        ];
    }
}
