<?php

declare(strict_types=1);

namespace App\Http\Requests\RekamMedis;

use App\Services\Pasien\PasienRecordAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/rekam-medis` - the caller patient's OWN medical-record list.
 *
 * ## `authorize()` is ALWAYS `true`
 *
 * The ownership question is not answered here. An account with no `pasien` row is
 * refused 403 by `PasienRecordAccess::ownPasien()` inside `RekamMedisService::daftar()`,
 * and "is this row mine" is the `where('pasien_id', ...)` scope of that same query -
 * never a filter applied after a row has been loaded. Putting either answer in
 * `authorize()` would split one rule across two classes, which is the drift
 * `PasienRecordAccess` exists to prevent.
 *
 * ## `q` searches TWO columns, and they are chosen rather than derived
 *
 * `keluhan_utama` (`telemedicine_test.sql:631`) and `diagnosis_kerja` (`:641`) are the
 * columns a patient can MEANINGFULLY search: the complaint they reported and the
 * working diagnosis label they were told. The six narrative/SOAP columns
 * (`:632`-`:640`) are long clinical prose a patient does not have a search term for,
 * and the diagnosis CODE lives in `rekam_medis_diagnosa` - a guarded child table this
 * unlogged endpoint deliberately does not join.
 *
 * The search NEVER crosses patients: `RekamMedisService::daftar()` applies
 * `where('rm.pasien_id', $pasien->id)` to the query BEFORE the `q` clause, so a term
 * that appears in another patient's record cannot reach this response. The tenant
 * filter is the query, not a post-filter, exactly as `PasienRecordAccess` requires.
 *
 * The LIKE pattern is escaped in the service, so `%` and `_` in a patient's term
 * match literally rather than as wildcards a caller could widen the search with.
 *
 * ## Dates are DAYS over the record's examination column
 *
 * `tanggal_dari` and `tanggal_sampai` compare against
 * `rekam_medis.tanggal_periksa` (`:630`, `DATETIME NOT NULL`), which is the record's
 * examination instant - the column the DDL indexes with `pasien_id` in
 * `idx_rm_pasien` (`:654`). They are validated as `Y-m-d` and the service expands them
 * to whole-day `DATETIME` bounds (`00:00:00` / `23:59:59`) so the comparison stays an
 * index range scan instead of a `DATE()` call that would disable the index.
 *
 * `tanggal_sampai` with no `tanggal_dari` is allowed: "everything up to this day" is
 * a real filter, and Laravel's `after_or_equal` passes when the reference field is
 * absent.
 *
 * ## `per_page` is capped at 100, and the default is the project's 15
 *
 * The cap is the project-wide `PasienRecordAccess::PER_PAGE_MAX` and is applied AGAIN
 * in the service, so a value arriving from anywhere else cannot bypass it.
 */
class IndexRekamMedisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'tanggal_dari' => ['nullable', 'date_format:Y-m-d'],
            'tanggal_sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tanggal_dari'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.PasienRecordAccess::PER_PAGE_MAX],
        ];
    }

    /**
     * The page size, clamped to the project's ceiling. The default is the
     * project's default, not Laravel's.
     */
    public function perPage(): int
    {
        return max(1, min(
            (int) $this->input('per_page', PasienRecordAccess::PER_PAGE_DEFAULT),
            PasienRecordAccess::PER_PAGE_MAX,
        ));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'q' => 'kata kunci',
            'tanggal_dari' => 'tanggal awal',
            'tanggal_sampai' => 'tanggal akhir',
            'page' => 'halaman',
            'per_page' => 'jumlah per halaman',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tanggal_sampai.after_or_equal' => 'Tanggal akhir tidak boleh mendahului tanggal awal.',
            'per_page.max' => 'Per halaman maksimal 100.',
        ];
    }
}
