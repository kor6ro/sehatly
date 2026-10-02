<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Admin\AdminDokterService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /admin/dokter/{id}/status` - the activation switch.
 *
 * ## Two independent columns, and only one of them is required
 *
 * The DDL keeps three facts apart on `dokter`, and the F14 pattern's second
 * design rule is that they must never be collapsed into one lamp:
 *
 * | column | DDL | this request |
 * | --- | --- | --- |
 * | `status_aktif` | `TINYINT(1) NOT NULL DEFAULT 1` (`:430`) | required - it is the switch this endpoint exists for |
 * | `tersedia_telemedisin` | `TINYINT(1) NOT NULL DEFAULT 1` (`:426`) | optional - absent means "leave it alone" |
 * | `status_verifikasi` | ENUM (`:427`) | NOT accepted here; the verify endpoint owns it |
 *
 * Omitting `tersedia_telemedisin` leaves the stored value untouched, which is
 * different from sending `false`: an operator suspending a doctor is not making
 * a statement about their telemedicine availability, and the service writes only
 * what the request names.
 *
 * `status_verifikasi` is deliberately absent from the rules. A request that
 * carries it does not silently change verification state, because
 * `validated()` never yields the key and the service has no parameter for it.
 *
 * ## `authorize()` is always true
 *
 * `tipe:admin,superadmin` runs before this class. The suspension itself is not
 * blocked by anything else on purpose: the F14 owner decision is that a doctor
 * with a problem credential must be suspendable immediately, and that existing
 * bookings are NOT auto-cancelled - {@see AdminDokterService::dampak()}
 * publishes the blast radius for the UI to show first.
 */
class StatusDokterRequest extends FormRequest
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
            'status_aktif' => ['required', 'boolean'],
            'tersedia_telemedisin' => ['sometimes', 'boolean'],
        ];
    }
}
