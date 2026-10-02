<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Admin\AdminDokterService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /admin/dokter/{id}/verifikasi` - one verification decision.
 *
 * ## `pending` is NOT an accepted value, and that is the contract
 *
 * The DDL's ENUM has three members (`:427`) and only the two DECISIONS are
 * accepted here:
 *
 * | body value | meaning |
 * | --- | --- |
 * | `terverifikasi` | the credentials are accepted; the doctor may appear in the directory |
 * | `ditolak` | the credentials are refused |
 *
 * A request naming `pending` would be a request to un-decide, which no flow
 * offers; the row's start state is the DDL's default, not something an operator
 * assigns. The refusal is a 422 on `status_verifikasi` from the `Rule::in()`
 * below.
 *
 * ## There is deliberately no `alasan` field
 *
 * `dokter` has no rejection-reason column, and the owner's F14 decision forbids
 * inventing one. The only other place a reason could be stored is `audit_log`,
 * and its redaction policy exists to keep exactly this kind of free text OUT of
 * the log (`dokter`'s allow-list is computed from the DDL and contains no
 * narrative column). So no field is offered, and the automatic audit row records
 * the changed `status_verifikasi` value and nothing else. Recorded in
 * `web/ux/patterns/F14.md` section 12 item 3.
 *
 * ## `authorize()` is always true
 *
 * The route carries `tipe:admin,superadmin`; the verify write has no
 * `permission:` code because the owner approved none for doctor mutation. See
 * the F14 block in `routes/api.php` for that decision.
 */
class VerifikasiDokterRequest extends FormRequest
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
            'status_verifikasi' => [
                'required',
                'string',
                Rule::in(AdminDokterService::KEPUTUSAN_VERIFIKASI),
            ],
        ];
    }
}
