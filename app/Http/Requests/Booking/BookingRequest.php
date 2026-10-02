<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

use App\Services\Booking\SlotAvailabilityService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the booking endpoints under `/api/v1`.
 *
 * The repository's convention is one sub-namespace per module
 * (`Requests\Auth\`, `Requests\Pasien\`, `Requests\Dokter\`), so the booking
 * inputs live here rather than directly under `Requests\` as the plan
 * sketches. Reported rather than silently deviated from.
 *
 * DDL vocabularies are `public const` arrays on this base so the suite can
 * assert them against `telemedicine_test.sql` instead of trusting a
 * transcription.
 */
abstract class BookingRequest extends FormRequest
{
    /**
     * `booking.tipe_layanan` ENUM('chat','video_call','kunjungan_klinik','home_visit')
     * at `:506`. `Rule::in` and not `Rule::enum`, for the reason `AlergiRequest`
     * gives: on laravel/framework 13.33 both a string `enum:` cast and
     * `Rule::enum` need a real PHP enum class, and this project has none for a
     * MySQL ENUM.
     *
     * @var list<string>
     */
    public const TIPE_LAYANAN = ['chat', 'video_call', 'kunjungan_klinik', 'home_visit'];

    /**
     * `booking.status` is the eight-value ENUM at `:515-516`. Six consume a
     * slot and two release it, and the two are REUSED from
     * `SlotAvailabilityService` rather than restated, so a ninth ENUM value
     * cannot quietly join one service and not the other.
     *
     * @var list<string>
     */
    public const STATUS_TIDAK_MENGKONSUMSI = SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI;

    /**
     * The states a cancellation must refuse: the two that are already over
     * (`berlangsung`, `selesai`) and the two terminal states that already
     * release the slot (`dibatalkan`, `kadaluarsa`). "Cancelling" either of the
     * latter would overwrite the fact of HOW the booking ended.
     *
     * @var list<string>
     */
    public const STATUS_TIDAK_BISA_DIBATALKAN = ['berlangsung', 'selesai', 'dibatalkan', 'kadaluarsa'];

    /**
     * The states a reschedule may move: the two that are not yet under way.
     *
     * Deliberately NOT the complement of
     * {@see STATUS_TIDAK_BISA_DIBATALKAN}, even though the cancel guard is
     * written as a refusal list. The complement also contains `check_in` and
     * `no_show`, and the F12 owner decision is narrower than the cancellation
     * guard: a patient already checked in is in the clinic's hands, and a
     * `no_show` is a recorded fact that moving the slot would overwrite. So
     * the reschedule guard is an ALLOW list of exactly these two, and a test
     * asserts the relationship between the three constants (`check_in` and
     * `no_show` are cancellable per the old guard but not reschedulable).
     *
     * @var list<string>
     */
    public const STATUS_BISA_DIJADWAL_ULANG = ['menunggu_pembayaran', 'terjadwal'];

    /**
     * Every `booking.status` value in the DDL's exact order (`:515-516`), for
     * the list filters. `no_show` is the eighth value an earlier draft of the
     * plan missed.
     *
     * @var list<string>
     */
    public const STATUS_SEMUA = [
        'menunggu_pembayaran',
        'terjadwal',
        'check_in',
        'berlangsung',
        'selesai',
        'dibatalkan',
        'no_show',
        'kadaluarsa',
    ];

    /**
     * `authorize()` is ALWAYS `true`: the tenant question ("whose row is
     * this") is answered by `PasienRecordAccess` in the service, and the
     * permission question by the `permission:` middleware, never here.
     */
    public function authorize(): bool
    {
        return true;
    }
}
