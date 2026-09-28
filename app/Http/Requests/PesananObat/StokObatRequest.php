<?php

declare(strict_types=1);

namespace App\Http\Requests\PesananObat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/obat/{id}/stok` - how much of one drug one pharmacy has, and
 * which other pharmacies could supply it instead.
 *
 * ## `apotek_id` is OPTIONAL, and that is not a convenience
 *
 * Without it the endpoint answers "where can this drug be had at all", which is
 * the question a patient asks when a pharmacy tells them it has none. With it,
 * the answer is that pharmacy's own shelf plus the alternatives. Both shapes are
 * real: a caller who already knows their pharmacy wants the first row, and a
 * caller who does not wants the list.
 *
 * ## `jumlah` bounds what "sufficient" means
 *
 * The spec's alternative rule is "pharmacies with sufficient `jumlah_stok`", and
 * sufficient FOR WHAT is the caller's demand: a prescription for 3 tablets is
 * not served by a pharmacy holding 2. So the quantity is an input rather than an
 * assumption, and it is bounded by `resep_item.jumlah`'s own ceiling -
 * `SMALLINT UNSIGNED` (`telemedicine_test.sql:774`) tops out at 65535, and a
 * larger demand could not be written on any prescription line anyway.
 */
class StokObatRequest extends FormRequest
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
            // `master_obat.id` is a `BIGINT UNSIGNED AUTO_INCREMENT` primary key
            // (`:709`). The drug's existence is NOT this request's rule: the
            // controller answers 404 for one that does not exist, which is a
            // different failure from a malformed query string.
            'apotek_id' => ['nullable', 'integer', 'min:1'],
            'jumlah' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'apotek_id.integer' => 'Apotek tidak valid.',
            'jumlah.integer' => 'Jumlah tidak valid.',
            'jumlah.max' => 'Jumlah melebihi kapasitas resep_item.jumlah (SMALLINT UNSIGNED).',
        ];
    }
}
