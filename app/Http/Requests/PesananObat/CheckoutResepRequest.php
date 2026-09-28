<?php

declare(strict_types=1);

namespace App\Http\Requests\PesananObat;

use App\Services\PesananObat\PesananObatService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/resep/{id}/checkout` - place one prescription order.
 *
 * ## `tipe` is ACCEPTED and then REFUSED, and that is the point
 *
 * `pesanan_obat.tipe` is a three-value ENUM (`:803`) and two of the three are
 * legal values this endpoint cannot honour. Making `tipe` `prohibited` would
 * answer 422 with a field error and hide WHICH values were refused and why;
 * making it a silent `Rule::in(['resep_dokter'])` would answer the same 422 for
 * a typo and for a perfectly valid `obat_bebas`, which are different problems
 * with different fixes.
 *
 * So the field is validated against all THREE DDL members - a value outside the
 * ENUM is a broken caller and gets the ordinary invalid-value message - and the
 * SERVICE refuses the two it cannot implement, with a message that names the
 * missing line-item table. That is the whole of the prescription-only rule, and
 * it is a refusal at CREATION rather than a flag on a row that was written.
 *
 * ## `items` is `prohibited`, and that is the partial-basket answer
 *
 * There is no way to record a selection: `pesanan_obat` has no item table
 * anywhere in the 75 and no column holding a quantity, so a partial fill would
 * leave `subtotal` unrecomputable from anything stored. Naming `items` is
 * therefore answered 422 rather than ignored, because a caller who sent it is a
 * caller who believes the order takes a basket.
 *
 * ## `apotek_id` is optional
 *
 * Omitted means "the pharmacy named by the prescription" (`resep.apotek_id`,
 * `:749`), which is the plan's rule. Sent means the caller has chosen another -
 * which is how the alternatives the stock endpoint offers become usable. When
 * the prescription names none and the caller names none, the service refuses:
 * `pesanan_obat.apotek_id` is `NOT NULL` (`:802`) and guessing a pharmacy would
 * ship a prescription somewhere the caller never agreed to.
 *
 * ## `alamat_kirim` is DERIVED, and it is `prohibited`
 *
 * The order's shipping address is the patient's own `pasien.alamat_lengkap`
 * (`:234`, `TEXT NOT NULL`), stored as a snapshot on the order. The snapshot is
 * why the profile changing after checkout must not move a parcel already placed,
 * and it is why the field is derived rather than chosen: there is no address
 * table behind it, so a caller-supplied address would be a second source with
 * nothing to reconcile against.
 *
 * ## Money is a JSON STRING
 *
 * `biaya_kirim` is validated as a string and re-parsed by `Uang::parse()` in the
 * service, which refuses a float, a boolean, null and an array with a message
 * naming the field. `pesanan_obat.biaya_kirim` is `DECIMAL(12,2) NOT NULL
 * DEFAULT 0` (`:808`), so `0.00` is legal and means nothing to ship for, and a
 * negative amount is refused rather than stored.
 */
class CheckoutResepRequest extends FormRequest
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
        $prohibited = [];

        foreach (PesananObatService::KOLOM_MILIK_SISTEM as $kolom) {
            $prohibited[$kolom] = ['prohibited'];
        }

        return array_merge([
            // All THREE DDL members are accepted here so the SERVICE can refuse
            // the two it cannot implement with a message that says why. See the
            // class docblock.
            'tipe' => ['nullable', 'string', Rule::in(PesananObatService::SEMUA_TIPE)],
            // `faskes.id` is a `BIGINT UNSIGNED AUTO_INCREMENT` primary key
            // (`:361`). Existence and `tipe = 'apotek'` are the service's checks,
            // because `Rule::exists` cannot express the second and the schema
            // constrains neither.
            'apotek_id' => ['nullable', 'integer', 'min:1'],
            // `pasien.alamat_lengkap` is `TEXT NOT NULL` (`:234`), so there is
            // always a fallback and the order's address is DERIVED from it.
            // `alamat_kirim` is in the prohibited map above, not here: the
            // snapshot exists so a profile change cannot move a parcel already
            // placed, and a caller-chosen address would be a second address
            // source with no address table behind it.
            'kurir' => ['nullable', 'string', Rule::in(PesananObatService::SEMUA_KURIR)],
            // A money STRING. Deliberately NOT `numeric`: `numeric` accepts a
            // float and a float has already lost precision by the time PHP's
            // parser hands it over.
            'biaya_kirim' => ['nullable', 'string', 'max:14'],
            // `invoice.metode_id` is chosen here rather than at payment so the
            // admin fee lands on THIS invoice; `InvoiceService` refuses an
            // unknown or inactive method with a 422 on `metode_id` itself.
            'metode_id' => ['nullable', 'integer', 'min:1'],
            'kode_promo' => ['nullable', 'string', 'max:30'],
            // The basket that cannot be recorded. See the class docblock.
            'items' => ['prohibited'],
        ], $prohibited);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipe.in' => 'Tipe pesanan tidak dikenal.',
            'apotek_id.integer' => 'Apotek tidak valid.',
            'kurir.in' => 'Kurir tidak dikenal.',
            'biaya_kirim.string' => 'Biaya kirim harus berupa string desimal, bukan angka.',
            'items.prohibited' => 'Pesanan obat diturunkan dari item resep dan tidak menerima item.',
        ];
    }
}
