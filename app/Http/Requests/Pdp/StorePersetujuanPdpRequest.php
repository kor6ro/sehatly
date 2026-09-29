<?php

declare(strict_types=1);

namespace App\Http\Requests\Pdp;

use App\Enums\PersetujuanPdpJenis;
use App\Models\PersetujuanPdp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/pdp/persetujuan` - record one decision about one document version.
 *
 * ## Three fields in, and EVERY other column is `prohibited`
 *
 * `persetujuan_pdp` is seven columns (`telemedicine_test.sql:1134`-`:1145`) and
 * this request accepts three of them. The other four are named and refused rather
 * than ignored, for the reason `CheckoutResepRequest` gives: a caller who sent
 * `ip_address` and got a 201 would believe they had recorded the address they
 * were seen at, and this table is a compliance record.
 *
 * | column | line | who writes it |
 * | --- | --- | --- |
 * | `id` | `:1135` | the database |
 * | `user_id` | `:1136` | the authenticated account - a data subject cannot consent for anybody else |
 * | `jenis` | `:1137`-`:1138` | the client: which of the five documents |
 * | `versi_dokumen` | `:1139` | the client: which version of it |
 * | `disetujui` | `:1140` | the client: the decision |
 * | `disetujui_at` | `:1141` | the application clock - "when did you decide" is not a client-supplied fact |
 * | `ip_address` | `:1142` | the request, or NULL |
 *
 * ## `jenis` is validated against ALL FIVE DDL members
 *
 * The five values come from {@see PersetujuanPdpJenis::nilai()}, which is itself
 * asserted against the parsed DDL on every test run, so a value that drifts from
 * the schema by one letter fails the suite rather than producing a 500 at the
 * INSERT. A `jenis` outside the ENUM is a broken caller, not a refusal: it gets
 * the ordinary invalid-value message, and the response does NOT look like a
 * patient who declined.
 *
 * ## `versi_dokumen` is bounded by the COLUMN, not by taste
 *
 * `VARCHAR(20)` (`:1139`), so `max:20` is the schema's limit and not a product
 * decision. The application-layer rule about what a version string means - that
 * "highest" is a STRING order and therefore wants fixed-width values - belongs to
 * {@see \App\Services\Pdp\PdpConsentService}, not here: a client cannot be told
 * which document is current, so it cannot be asked to produce a well-ordered
 * version.
 */
class StorePersetujuanPdpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The four columns this request refuses, named rather than ignored.
     *
     * Declared as a constant and checked against the PARSED DDL by
     * `TokenAuditTest` - every column of `persetujuan_pdp` is classified as
     * either accepted here or refused here, so a fourth server-owned column
     * added to the contract fails the suite instead of being silently ignored by
     * Laravel. A hand-written list with no such check is exactly how a
     * `prohibited` entry gets forgotten.
     *
     * @var list<string>
     */
    public const KOLOM_MILIK_SISTEM = ['id', 'user_id', 'disetujui_at', 'ip_address'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $prohibited = [];

        foreach (self::KOLOM_MILIK_SISTEM as $kolom) {
            $prohibited[$kolom] = ['prohibited'];
        }

        return array_merge([
            'jenis' => ['required', 'string', Rule::in(PersetujuanPdpJenis::nilai())],
            // The schema's own width, not a product limit: `VARCHAR(20)` (:1139).
            'versi_dokumen' => ['required', 'string', 'max:20'],
            // `disetujui TINYINT(1) NOT NULL` (:1140) has no nullable twin, so
            // the field is REQUIRED rather than `boolean` alone - a request
            // omitting it is a client that did not collect the answer, and
            // `required` says so instead of defaulting it to false.
            //
            // `boolean` and not an integer: the DDL value is a flag, and a
            // client sending `2` has made a different mistake from one sending
            // `1`.
            'disetujui' => ['required', 'boolean'],
        ], $prohibited);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'jenis.required' => 'Jenis persetujuan wajib diisi.',
            'jenis.in' => 'Jenis persetujuan tidak dikenal.',
            'versi_dokumen.required' => 'Versi dokumen wajib diisi.',
            'versi_dokumen.max' => 'Versi dokumen maksimal 20 karakter.',
            'disetujui.required' => 'Jawaban persetujuan wajib diisi.',
            'disetujui.boolean' => 'Jawaban persetujuan harus true atau false.',
            'user_id.prohibited' => 'Persetujuan hanya dapat dicatat atas nama akun yang sedang masuk.',
            'disetujui_at.prohibited' => 'Waktu persetujuan dicatat dari waktu server.',
            'ip_address.prohibited' => 'Alamat IP dicatat dari permintaan, bukan dikirim klien.',
            'id.prohibited' => 'Id persetujuan ditentukan oleh database.',
        ];
    }
}
