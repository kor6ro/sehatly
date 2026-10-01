<?php

declare(strict_types=1);

namespace App\Http\Requests\Konsultasi;

use App\Services\Konsultasi\KonsultasiService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/konsultasi` - the doctor's own consultation list.
 *
 * ## `status` is the DASHBOARD set, not the whole ENUM
 *
 * `KonsultasiService::STATUS_DASBOR` is the closed set this filter accepts:
 * `menunggu_dokter`, `berlangsung`, `menunggu_resep` and `selesai`. It is read
 * from the service rather than typed here because the SAME constant drives the
 * default listing and the ordering rank, so the accepted values and the rows
 * the endpoint can return cannot disagree.
 *
 * The two remaining DDL states, `dibatalkan` and `gagal`, are deliberately
 * outside the set, for two independent reasons:
 *
 * 1. **No endpoint writes them.** `KonsultasiService::ubahStatus()` will apply
 *    both, but no HTTP route does, because `RbacCatalog::PERMISSIONS` holds no
 *    cancel/fail code and inventing one is a catalogue decision (the service
 *    docblock records that finding). A filter value no row can hold would only
 *    ever answer an empty page.
 * 2. **They are not dashboard content.** F13's dashboard shows work to pick up
 *    and history that was completed; a cancelled or failed session is neither.
 *
 * A value outside the set is a 422 naming `status`, not an empty page - the
 * same choice `AntreanResepRequest` makes for the pharmacist queue: `[]` for
 * `?status=dibatalkan` would read as "none of those exist", when the truth is
 * that this endpoint does not speak that value at all.
 *
 * ## `page` / `per_page` are the project-wide pair
 *
 * `per_page` is capped at 100 - the same ceiling
 * `PasienRecordAccess::PER_PAGE_MAX` applies - and the cap is applied again in
 * `KonsultasiService::daftar()`, so a value arriving from anywhere else cannot
 * bypass it. The response's `meta` block is `ApiResponse::pageMeta()`, the one
 * list envelope every other list in this project uses.
 */
class IndexKonsultasiRequest extends FormRequest
{
    /**
     * `authorize()` is ALWAYS `true`: the account-type question is answered by
     * the route's `tipe:dokter` middleware, and the ownership question ("is
     * this row on the caller's own `dokter` row?") by
     * `KonsultasiAccess::ownDokter()` inside the service - never here.
     */
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
            'status' => ['nullable', 'string', Rule::in(KonsultasiService::statusDasbor())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => 'status',
            'page' => 'halaman',
            'per_page' => 'jumlah per halaman',
        ];
    }
}
