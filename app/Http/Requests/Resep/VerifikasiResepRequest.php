<?php

declare(strict_types=1);

namespace App\Http\Requests\Resep;

use App\Enums\ResepVerifikasiStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/resep/{id}/verifikasi` - one pharmacist's single answer.
 *
 * ## `status` is `Rule::enum`, and the list is the DDL's
 *
 * `resep_verifikasi.status` is `ENUM('sesuai','ada_koreksi','ditolak') NOT NULL`
 * at `telemedicine_test.sql:790`, so an unknown value is a 422 naming the field
 * rather than a MySQL 1265 at insert time - and rather than the far worse
 * outcome of a value the ENUM silently coerced. `Rule::enum()` against the real
 * PHP enum, NOT an `enum:` model cast: on laravel/framework 13.33
 * `HasAttributes::isEnumCastable()` requires `enum_exists($castType)`, so the
 * `'enum:sesuai,ada_koreksi,ditolak'` spelling is a silent no-op that reads like
 * validation and validates nothing.
 *
 * ## The machine-owned columns are `prohibited`
 *
 * `resep_id` is the path segment, `apoteker_user_id` is the authenticated
 * pharmacist, and `diverifikasi_at` is the clock. All three are real columns,
 * and all three would let a caller forge a signature: a different pharmacist's
 * name on the row, or a backdate. `prohibited` answers 422 naming the field
 * rather than dropping it silently - the same discipline
 * `StoreResepRequest` applies to `resep.catatan_dokter`.
 *
 * ## `catatan` is `nullable`, and its REQUIREDNESS is a service rule
 *
 * `resep_verifikasi.catatan` is `TEXT NULL` (`:791`), so NULL is a legal column
 * value and the rule that a `kontraindikasi` re-check demands one is NOT a
 * column constraint. It lives in
 * `ResepVerifikasiService::pastikanCatatan()` because it depends on the
 * re-checked warning set, which this request cannot see. Marking it `required`
 * here would force a note on every clean prescription, which is the opposite of
 * what an acknowledgement is for.
 *
 * `max:20000` is a bound, not the column: `TEXT` holds 65,535 bytes and a note
 * is prose, so the cap is about a client accidentally posting a whole
 * document. It is a byte-safe multiple of the packet limit rather than a
 * derived limit, and it is asserted by a test.
 */
class VerifikasiResepRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::enum(ResepVerifikasiStatus::class)],
            'catatan' => ['nullable', 'string', 'max:20000'],

            'resep_id' => ['prohibited'],
            'apoteker_user_id' => ['prohibited'],
            'diverifikasi_at' => ['prohibited'],
        ];
    }

    /**
     * The three `prohibited` messages, spelled as the COLUMN names.
     *
     * Laravel derives an attribute name from the key by `str_replace('_', ' ')`,
     * so `apoteker_user_id` arrives at a client as "apoteker user id" and
     * `diverifikasi_at` as "diverifikasi at". A client maps the field name in
     * `errors` to a form input, and "apoteker user id" is not a form input
     * anybody wrote. Naming the column exactly is the difference between a
     * message a developer can act on and one they have to reverse-engineer.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resep_id.prohibited' => 'The resep_id field is prohibited.',
            'apoteker_user_id.prohibited' => 'The apoteker_user_id field is prohibited.',
            'diverifikasi_at.prohibited' => 'The diverifikasi_at field is prohibited.',
        ];
    }
}
