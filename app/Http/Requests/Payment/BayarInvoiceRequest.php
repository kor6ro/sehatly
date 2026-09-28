<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/invoice/{id}/bayar` - choose a method and open a payment.
 *
 * ## `authorize()` is TRUE, and the route carries both guards instead
 *
 * The route names `auth:sanctum` AND `permission:pembayaran.bayar`, and both
 * are load-bearing rather than decorative:
 *
 * - `pembayaran.bayar` IS a real code in `RbacCatalog::PERMISSIONS`, and
 *   `ROLE_PERMISSIONS` grants it to `pasien` and `superadmin` - to nobody else.
 *   So the gate refuses `dokter`, `apoteker` and `admin` without locking out
 *   the one account type that owns the invoice being paid. A token audit in
 *   `PasienProfileTest` and `AuthFlowTest` re-reads that grant list from
 *   `RbacCatalog` on every run, so a later change to the map fails the suite
 *   rather than 403ing every patient.
 * - `tipe:pasien` is deliberately absent, for the reason
 *   `PasienRecordAccess` gives at length: it answers "which account type is
 *   this" and cannot express "is this invoice yours", which is the question
 *   `PembayaranController` actually asks through `ownPasien()`.
 *
 * ## `metode_id` is `exists` and `active`, and BOTH rules are needed
 *
 * `Rule::exists` alone would accept an inactive method. `status_aktif` is
 * `TINYINT(1) NOT NULL DEFAULT 1` (telemedicine_test.sql:933), so a
 * deactivated method is a real storable state rather than a fiction, and a
 * method nobody may pay with must not produce a virtual account. The
 * `->where('status_aktif', 1)` rides on the same rule so the refusal is a 422
 * naming `metode_id` rather than a 500 from a foreign-key violation on insert.
 *
 * ## The width is the DDL's, asserted rather than remembered
 *
 * `master_metode_pembayaran.id` is `SMALLINT UNSIGNED` (:926) and
 * `pembayaran.metode_id` is `SMALLINT UNSIGNED NOT NULL` with a foreign key to
 * it (:961, :971) - so a `metode_id` above 65535 is a 422 here rather than a
 * 1364 or a 1452 at insert time.
 *
 * ## `gateway` is NOT a field on this request
 *
 * A caller cannot name the provider. `pembayaran.gateway` comes from
 * `config('payment.gateway_pembayaran')` through the bound gateway, so a
 * request that carries a `gateway` key has it ignored and is a caller who
 * expects an API that does not exist. Only `metode_id` is accepted.
 */
class BayarInvoiceRequest extends FormRequest
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
            // `master_metode_pembayaran.id` is `SMALLINT UNSIGNED` (:926) and
            // `pembayaran.metode_id` matches it with a real foreign key
            // (:961, :971). `status_aktif` (:933) rides on the same rule so an
            // inactive method is a 422 on the field rather than an insert-time
            // failure.
            'metode_id' => [
                'required',
                'integer',
                'min:1',
                'max:65535',
                Rule::exists('master_metode_pembayaran', 'id')->where('status_aktif', 1),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'metode_id.required' => 'Metode pembayaran wajib diisi.',
            'metode_id.integer' => 'Metode pembayaran tidak valid.',
            'metode_id.max' => 'Metode pembayaran tidak valid.',
            'metode_id.exists' => 'Metode pembayaran tidak ditemukan atau tidak aktif.',
        ];
    }
}
