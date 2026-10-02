<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

/**
 * Validates `PUT /api/v1/booking/{id}/jadwal-ulang`.
 *
 * ## The four keys are the real columns, not the sketch
 *
 * F12's pattern sketched `{jadwal_id, slot_mulai, slot_selesai}`. The real
 * `booking` table needs a DATE as well - `tanggal_kunjungan` is
 * `DATE NOT NULL` (`telemedicine_test.sql:507`) and a reschedule is allowed to
 * change it - so this request accepts `jadwal_id`, `tanggal_kunjungan`,
 * `slot_mulai` and (optionally) `slot_selesai`.
 *
 * `slot_selesai` is **accepted and checked, never trusted**. `booking` has the
 * column and the client that picked a slot already holds the published end
 * time, so refusing the key outright would break the documented payload; but
 * the length of a slot is a property of the schedule row
 * (`dokter_jadwal.durasi_slot_menit`, `:478`), so the service derives the end
 * from the published slot and raises a 422 on `slot_selesai` when the two
 * disagree. That is the same rule `StoreBookingRequest` enforces by not
 * accepting the key at all, made compatible with the F12 body instead of
 * silently ignoring a field.
 *
 * ## `authorize()` is TRUE; the route and the service decide
 *
 * The route carries `auth:sanctum` and `permission:booking.batal` (no code in
 * `RbacCatalog` names schedule movement; see the F12 pattern's section 12),
 * and the service runs the tenant rule - another patient's booking is a 404, an
 * account with neither profile row is a 403 - before anything else.
 * `jadwal_id` is only checked for existence and shape here, NOT for ownership
 * or availability: "is this row this booking's doctor's?" and "is the start a
 * published, free slot for that date?" need the booking's `dokter_id` and the
 * row locks, so they are answered inside `BookingService::jadwalUlang()`'s
 * transaction. A closure rule here would either duplicate that logic outside
 * the lock or leak the existence of a booking the caller does not own.
 */
class RescheduleBookingRequest extends BookingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'jadwal_id' => ['required', 'integer', 'exists:dokter_jadwal,id'],
            // `date_format`, not `date`: `Carbon::createFromFormat('Y-m-d',
            // '2026-13-45')` does NOT fail - PHP overflows month 13 into
            // 2027-02-14 - and only the format rule round-trips and refuses.
            'tanggal_kunjungan' => ['required', 'date_format:Y-m-d'],
            'slot_mulai' => ['required', 'date_format:H:i:s'],
            'slot_selesai' => ['nullable', 'date_format:H:i:s'],
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
            'jadwal_id' => 'jadwal',
            'tanggal_kunjungan' => 'tanggal kunjungan',
            'slot_mulai' => 'jam mulai',
            'slot_selesai' => 'jam selesai',
        ];
    }
}
