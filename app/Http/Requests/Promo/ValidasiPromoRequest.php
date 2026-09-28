<?php

declare(strict_types=1);

namespace App\Http\Requests\Promo;

use App\Services\Invoice\PromoHitungan;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/promo/validasi` - ask whether a promo code works for a
 * purchase, without applying it.
 *
 * ## `authorize()` is TRUE, and the route carries `auth:sanctum` instead
 *
 * The refusal that matters here is not a 403 about roles. `promo.validasi` IS a
 * real code in `RbacCatalog::PERMISSIONS`, but `RbacCatalog::ROLE_PERMISSIONS`
 * grants it to `admin` and `superadmin` and to **nobody else** - the `pasien`
 * role's list (:221-235) does not contain it. A `permission:promo.validasi` gate
 * would therefore 403 the ONE account type that has a `pasien` row to validate
 * against, and the endpoint would be reachable by exactly the callers who cannot
 * use it. That is stated rather than assumed: the token audit reads
 * `ROLE_PERMISSIONS` directly and prints the grant list.
 *
 * `tipe:pasien` is not used either, for the reason `PasienRecordAccess` gives at
 * length: it answers "which account type is this", which cannot express "is
 * this invoice yours", and a `pasien`-typed account with no `pasien` row passes
 * it and is refused by the service anyway. The controller resolves the caller's
 * own `pasien` row through `PasienRecordAccess::ownPasien()`, which is what
 * actually bounds the read.
 *
 * The route DOES name `auth:sanctum`, so an anonymous caller gets the guard's 401
 * envelope rather than this request's 422 on `kode`.
 *
 * ## `kode` is required and NOT `exists`
 *
 * `Rule::exists('master_promo', 'kode')` would answer a missing code with
 * "validation failed" on the field, and it would do so in a rule that has to be
 * kept in step with the service's own catalogue lookup. A promo that EXISTS but
 * fails its window, its quota or its minimum is a perfectly valid row that the
 * caller must be told about with the specific reason, so the lookup is the
 * service's and the field rule is only "a non-empty string no longer than
 * `master_promo.kode`'s VARCHAR(30)".
 *
 * That 30 is asserted against the DDL rather than written from memory - a
 * client sending a 40-character code must be refused on length, not by a
 * silently-truncated lookup.
 */
class ValidasiPromoRequest extends FormRequest
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
            // `master_promo.kode` is `VARCHAR(30) NOT NULL UNIQUE` (:987).
            'kode' => ['required', 'string', 'max:30'],
            // `invoice.id` is a `BIGINT UNSIGNED AUTO_INCREMENT` primary key
            // (:937), and the endpoint is a calculation AGAINST one invoice -
            // it is what the discount is computed from, so it is not optional.
            'invoice_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kode.required' => 'Kode promo wajib diisi.',
            'kode.max' => 'Kode promo maksimal 30 karakter.',
            'invoice_id.required' => 'Invoice wajib diisi.',
            'invoice_id.integer' => 'Invoice tidak valid.',
        ];
    }

    /**
     * The reason codes this endpoint can report, for a client that would rather
     * key off a stable token than parse Indonesian prose.
     *
     * Derived from the same constant the service fills in, so the HTTP surface
     * and the rule set cannot drift apart.
     *
     * @return list<string>
     */
    public static function kodeAlasan(): array
    {
        return array_keys(PromoHitungan::KOLOM);
    }
}
