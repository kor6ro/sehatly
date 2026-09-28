<?php

declare(strict_types=1);

namespace App\Http\Requests\Konsultasi;

/**
 * Validates `PUT /api/v1/konsfirmasi/{id}/selesai` - the doctor's completion
 * call, which also carries the four SOAP fields.
 *
 * ## The six writable fields and the four machine-owned ones
 *
 * `catatan_subjektif`, `catatan_objektif`, `catatan_asessment` and
 * `catatan_plan` are the four SOAP columns (`:548`-`:551`), all `TEXT NULL`;
 * `diagnosis_kerja` is `VARCHAR(255) NULL` (`:552`) and so is the only one with a
 * character limit, and `saran_tindak_lanjut` is `TEXT NULL` (`:553`). All six are
 * `nullable` rather than `required`, because the columns are nullable and a
 * doctor who examined a patient verbally should not be forced to invent an
 * objective finding to satisfy a NOT NULL that the schema does not have.
 *
 * `status`, `mulai_at`, `selesai_at` and `total_durasi_detik` are `prohibited`.
 * All four are written by `KonsultasiService::selesai()` from the state
 * machine and the clock - the doctor's own words must not be able to set a
 * duration, and the plan's "must NOT let a patient set `status`, `mulai_at`, or
 * `selesai_at`" is enforced on the DOCTOR too, because the machine-owned
 * distinction does not change with the caller's account type.
 *
 * ## Why `mulai_at` is `prohibited` and not merely absent
 *
 * It is the field the plan's own 422 rule reads ("rejected with 422 if `mulai_at`
 * is still null"). A doctor who tried to supply it would be overwriting the
 * instant `terima()` stamped - the only writer of that column - and a stale value
 * would then be used to compute `total_durasi_detik`. `prohibited` answers 422
 * with a message naming the field rather than silently dropping it.
 */
class SelesaikanKonsultasiRequest extends KonsultasiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'catatan_subjektif' => ['nullable', 'string', 'max:16000'],
            'catatan_objektif' => ['nullable', 'string', 'max:16000'],
            'catatan_asessment' => ['nullable', 'string', 'max:16000'],
            'catatan_plan' => ['nullable', 'string', 'max:16000'],
            'diagnosis_kerja' => ['nullable', 'string', 'max:255'],
            'saran_tindak_lanjut' => ['nullable', 'string', 'max:16000'],
            'status' => ['prohibited'],
            'mulai_at' => ['prohibited'],
            'selesai_at' => ['prohibited'],
            'total_durasi_detik' => ['prohibited'],
            'booking_id' => ['prohibited'],
            'room_id' => ['prohibited'],
            'biaya_konsultasi' => ['prohibited'],
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
            'catatan_subjektif' => 'catatan subjektif',
            'catatan_objektif' => 'catatan objektif',
            'catatan_asessment' => 'catatan assessment',
            'catatan_plan' => 'catatan plan',
            'diagnosis_kerja' => 'diagnosis kerja',
            'saran_tindak_lanjut' => 'saran tindak lanjut',
            'status' => 'status',
            'mulai_at' => 'waktu mulai',
            'selesai_at' => 'waktu selesai',
            'total_durasi_detik' => 'total durasi',
            'booking_id' => 'booking',
            'room_id' => 'room id',
            'biaya_konsultasi' => 'biaya konsultasi',
        ];
    }
}
