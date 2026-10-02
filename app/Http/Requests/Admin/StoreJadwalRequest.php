<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Admin\AdminJadwalService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /admin/dokter/{id}/jadwal` - the multi-day weekly-window create.
 *
 * ## `hari` is an ARRAY, and that is the F14 form
 *
 * The pattern's step 5 is one form that creates the same window for several
 * weekdays ("Salin jam ke hari lain" is a UI affordance over the same payload),
 * and the contract's create answers `201 data.jadwal[]` - one row per day. So
 * `hari` is required, non-empty, at most seven entries, each an integer in the
 * DDL's `0..6` range with no duplicates. `distinct` matters: two rows for the
 * same day in one request would be an overlap by construction, and refusing the
 * duplicate as a field error is cheaper and clearer than letting the conflict
 * check report the request against itself.
 *
 * ## Shape rules here, cross-field rules in the service
 *
 * This class validates FORMAT and VOCABULARY: `H:i`, `Y-m-d`, the three
 * `tipe_layanan` values, integer bounds and the `faskes` foreign key. The
 * relationships that need the merged row - `jam_selesai > jam_mulai`,
 * `berlaku_sampai >= berlaku_mulai`, and the per-day overlap check - live in
 * {@see AdminJadwalService} because the update path has no full row to validate
 * statelessly. Keeping all three in one place means the create and the update
 * cannot disagree about what a legal window is.
 *
 * ## `durasi_slot_menit` accepts the schema's range
 *
 * The F14 form's suggested range is 5-480 minutes, but the owner's F14 scope
 * states the server rule as `> 0`, and the column is
 * `SMALLINT UNSIGNED NOT NULL DEFAULT 15`. So the server accepts `1..65535` -
 * the column's real domain - and the client may narrow it for usability. A
 * server that silently refused a schema-legal value would be inventing policy.
 *
 * `kuota_per_sesi` is `NULL`-able and `0` is a real, different value (a quota of
 * zero means "no slots"), so `nullable|integer|min:0` keeps the two apart. The
 * service writes the `null` through rather than substituting 1.
 */
class StoreJadwalRequest extends FormRequest
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
            'hari' => ['required', 'array', 'min:1', 'max:7'],
            'hari.*' => ['required', 'integer', 'between:0,6', 'distinct'],
            'tipe_layanan' => ['required', 'string', Rule::in(AdminJadwalService::TIPE_LAYANAN)],
            'faskes_id' => ['nullable', 'integer', 'exists:faskes,id'],
            'jam_mulai' => ['required', 'date_format:H:i'],
            'jam_selesai' => ['required', 'date_format:H:i'],
            'durasi_slot_menit' => ['required', 'integer', 'min:1', 'max:65535'],
            'kuota_per_sesi' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'berlaku_mulai' => ['required', 'date_format:Y-m-d'],
            'berlaku_sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:berlaku_mulai'],
            'status_aktif' => ['required', 'boolean'],
        ];
    }
}
