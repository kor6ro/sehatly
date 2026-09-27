<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

/**
 * Validates `PUT /api/v1/booking/{id}/batalkan`.
 *
 * The reason is the only input and it is optional: the refusal of a booking
 * that is already over names the STATUS, never the missing reason, and an
 * empty body still reaches the service so the ownership split (404 for the
 * row, 403 for the caller) is measured rather than masked by validation.
 */
class CancelBookingRequest extends BookingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'alasan_pembatalan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'alasan_pembatalan' => 'alasan pembatalan',
        ];
    }
}
