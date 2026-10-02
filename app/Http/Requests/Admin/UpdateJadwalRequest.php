<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Admin\AdminJadwalService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /admin/jadwal/{id}` - update one weekly window, wholly or partially.
 *
 * ## Every field is `sometimes`, and that is the publish action
 *
 * The F14 UI publishes and unpublishes a draft by sending `{status_aktif: true}`
 * or `{status_aktif: false}` ALONE (AC-7's "Nonaktifkan saja" and the publish
 * button), so a rule set requiring the whole window would make the flow
 * impossible. Each field is validated only when present; the service merges the
 * submitted keys over the stored row and validates the RESULT, which is where
 * `jam_selesai > jam_mulai` and `berlaku_sampai >= berlaku_mulai` are decided
 * for a partial body.
 *
 * ## `nullable` on the three nullable columns is meaningful
 *
 * `faskes_id`, `kuota_per_sesi` and `berlaku_sampai` are all `NULL`-able, and a
 * partial update must be able to CLEAR them - an online-only window has no
 * facility, "no quota" is different from a quota of zero, and "no end date" is an
 * open-ended window. Each therefore accepts an explicit `null` as well as
 * absence-means-unchanged, and the service distinguishes the two with
 * `array_key_exists`.
 *
 * `dokter_id` is not a field: a window cannot move to another doctor. `faskes_id`
 * is checked against the `faskes` table so a facility that does not exist is a
 * 422 on the field rather than a MySQL 1452 on save.
 */
class UpdateJadwalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'hari' => ['sometimes', 'integer', 'between:0,6'],
            'tipe_layanan' => ['sometimes', 'string', Rule::in(AdminJadwalService::TIPE_LAYANAN)],
            'faskes_id' => ['sometimes', 'nullable', 'integer', 'exists:faskes,id'],
            'jam_mulai' => ['sometimes', 'date_format:H:i'],
            'jam_selesai' => ['sometimes', 'date_format:H:i'],
            'durasi_slot_menit' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'kuota_per_sesi' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'berlaku_mulai' => ['sometimes', 'date_format:Y-m-d'],
            'berlaku_sampai' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status_aktif' => ['sometimes', 'boolean'],
        ];
    }
}
