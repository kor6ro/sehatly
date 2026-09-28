<?php

declare(strict_types=1);

namespace App\Http\Requests\Konsultasi;

use App\Enums\KonsultasiTipe;
use Illuminate\Validation\Rule;

/**
 * Validates `POST /api/v1/konsultasi/mulai`, in whichever of its two forms the
 * caller used.
 *
 * ## Exactly one of `booking_id` and `dokter_id`
 *
 * ## The mutual exclusion is `prohibits`, and `prohibited_with` does not exist
 *
 * The intuitive spelling is `prohibited_with:other`, and on laravel/framework 13.33
 * it is a `BadMethodCallException` at request time: `Validator::__call()` looks for
 * `validateProhibitedWith()` and there is no such method. The available family is
 * `prohibited`, `prohibited_if`, `prohibited_unless`, `prohibits` and
 * `prohibits_with`, and `prohibited_unless` cannot do the job either because
 * `Validator::validateProhibitedUnless()` calls `requireParameterCount(2, ...)`,
 * so the one-field form throws as well.
 *
 * `prohibits:<other>` is the correct spelling.
 * `Validator::validateProhibits()` fails when the field is present AND `<other>` is
 * present, so putting it on BOTH fields makes "both were sent" fail on BOTH - which
 * is what the test asserts, because a client that sent both deserves to be told
 * about both rather than fixing one and then being told about the other.
 * * The two forms are exclusive and one of them is mandatory, and that is expressed
 * with four rules rather than a `withValidator()` block, because the rules are
 * what the failure messages are generated from:
 *
 * | body | outcome |
 * | --- | --- |
 * | neither | 422 on BOTH `booking_id` and `dokter_id` (`required_without` each) |
 * | both | 422 on BOTH (`prohibits` each) |
 * | only `booking_id` | accepted; `tipe` is derived from the booking |
 * | only `dokter_id` | accepted; `tipe` is required |
 *
 * `tipe` follows the same shape: `required_without:booking_id` when the instant
 * form is used, `prohibits:booking_id` when the booking form is, so the
 * doctor and the service type of a booking-backed session are never taken from a
 * body that could disagree with the booking row.
 *
 * ## No `exists:` on either foreign key, and that is the non-disclosure rule
 *
 * Both ids are resolved in `konsultasi` under an ownership scope:
 * `booking_id` under the caller's own `pasien_id`, and `dokter_id` through
 * `DokterDirectoryService::find()`. An `exists:booking,id` here would answer 422
 * for a real-but-foreign booking and 404 for one that does not exist, which is a
 * working existence oracle over a sequential `BIGINT UNSIGNED AUTO_INCREMENT`
 * space, and would make the "identical body for both" property the rest of this
 * project holds impossible. `DokterController::slot` pins the same rule for the
 * same reason.
 *
 * `dokter_id` therefore answers 404 for a doctor who does not exist AND for one
 * the public directory would not list, with one body.
 *
 * ## The machine-owned columns are `prohibited`, not merely absent
 *
 * `status`, `mulai_at`, `selesai_at`, `room_id`, `pasien_id` and
 * `biaya_konsultasi` are all written by the service from facts the caller does
 * not own - the state machine, the clock, `Str::uuid()`, the caller's own patient
 * row and the doctor's own fee. `prohibited` tells a client that tried to set one
 * so, where merely omitting them from `rules()` would let the value through to
 * `validated()`'s absence and be silently ignored.
 */
class MulaiKonsultasiRequest extends KonsultasiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'booking_id' => ['required_without:dokter_id', 'prohibits:dokter_id', 'nullable', 'integer'],
            'dokter_id' => ['required_without:booking_id', 'prohibits:booking_id', 'nullable', 'integer'],
            'tipe' => [
                'required_without:booking_id',
                'prohibits:booking_id',
                'nullable',
                'string',
                Rule::enum(KonsultasiTipe::class),
            ],
            'pasien_id' => ['prohibited'],
            'status' => ['prohibited'],
            'mulai_at' => ['prohibited'],
            'selesai_at' => ['prohibited'],
            'room_id' => ['prohibited'],
            'biaya_konsultasi' => ['prohibited'],
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
            'booking_id' => 'booking',
            'dokter_id' => 'dokter',
            'tipe' => 'tipe konsultasi',
            'pasien_id' => 'pasien',
            'status' => 'status',
            'mulai_at' => 'waktu mulai',
            'selesai_at' => 'waktu selesai',
            'room_id' => 'room id',
            'biaya_konsultasi' => 'biaya konsultasi',
        ];
    }
}
