<?php

declare(strict_types=1);

namespace App\Http\Requests\Konsultasi;

/**
 * Validates `PUT /api/v1/konsultasi/{id}/terima` - the doctor accepting a session
 * that is waiting for them.
 *
 * **This endpoint is not in the plan; the reason it has to exist is in
 * `KonsultasiService::terima()`.** The plan requires `PUT /selesai` to
 * compute `total_durasi_detik` from `mulai_at` and to answer 422 while it is
 * null, which means some endpoint must stamp `mulai_at` - and none of the plan's
 * six does. `RbacCatalog::ROLE_PERMISSIONS` grants `konsultasi.mulai` to `dokter`
 * and to nobody else, and before this route no endpoint consumed that code, so
 * the catalogue is the evidence that a doctor-side start-of-session step was
 * always intended.
 *
 * ## A `FormRequest` for a body that carries nothing
 *
 * The plan's todo 53 acceptance criterion is "every `api/v1` POST/PUT/PATCH route
 * has a `FormRequest`", so this class exists even though every rule in it is
 * `prohibited`. That is not ceremony: it is the only place the four machine-owned
 * columns are refused on the accept path, and `prohibited` is what makes a client
 * that sends `status: 'berlangsung'` learn that the state machine is not theirs to
 * drive - which is exactly the boundary the transition table exists to draw.
 */
class TerimaKonsultasiRequest extends KonsultasiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['prohibited'],
            'mulai_at' => ['prohibited'],
            'selesai_at' => ['prohibited'],
            'total_durasi_detik' => ['prohibited'],
            'dokter_id' => ['prohibited'],
            'booking_id' => ['prohibited'],
            'room_id' => ['prohibited'],
        ];
    }

    /**
     * Indonesian labels, per the project convention.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => 'status',
            'mulai_at' => 'waktu mulai',
            'selesai_at' => 'waktu selesai',
            'total_durasi_detik' => 'total durasi',
            'dokter_id' => 'dokter',
            'booking_id' => 'booking',
            'room_id' => 'room id',
        ];
    }
}
