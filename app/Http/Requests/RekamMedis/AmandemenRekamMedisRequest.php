<?php

declare(strict_types=1);

namespace App\Http\Requests\RekamMedis;

/**
 * `POST /api/v1/rekam-medis/{id}/amandemen` - supersede a signed record.
 *
 * ## Why the change set is NESTED under `perubahan` while `PUT` is flat
 *
 * `PUT /api/v1/rekam-medis/{id}` takes the record's columns at the top level, because
 * it is a partial update of a document and a client already holds a representation of
 * one. An amendment is not that: it is a SET OF CHANGES, and the plan's own parameter
 * is named `array $perubahan`. Putting it in a named key means the error envelope can
 * say `errors.perubahan.perubahan` - "the changes you sent are wrong" - rather than
 * `errors.keluhan_utama`, which would blame a clinical field for a fact about the
 * request's shape. It also means a client cannot tell the two calls apart by accident.
 *
 * ## `perubahan` is `required` and `array`, and the value rules are per key
 *
 * The nested `perubahan.*` rules come from {@see RekamMedisRequest::kolomIsi()}, so an
 * amendment cannot carry a value the DDL would reject. `tanggal_periksa` is
 * `prohibited` HERE and offered on `PUT`: it is part of the chain group
 * `(pasien_id, dokter_id, tanggal_periksa)`, and an amendment that moved it would be
 * filed in a different chain from the record it supersedes - two unrelated rows
 * carrying consecutive version numbers, with the history split in half.
 *
 * The machine-owned columns are ALSO `prohibited` inside `perubahan`, and they are
 * declared TWICE: once as `perubahan.<column>` so the message names the nested path,
 * and once as a top-level `<column>` so a client that sent them flat is told too. The
 * service's own unknown-column check would catch either, but a 422 from the request
 * is a better answer than a `LogicException` reaching a client as a sanitised 500.
 */
class AmandemenRekamMedisRequest extends RekamMedisRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $nested = [];

        foreach (self::kolomIsi() as $kolom => $aturan) {
            $nested['perubahan.'.$kolom] = $aturan;
        }

        $nested['perubahan.tanggal_periksa'] = ['prohibited'];

        return array_merge($nested, self::kolomMilikSistem());
    }

    /**
     * The change set AS SENT, not as validated.
     *
     * ## Why the raw input and not `validated('perubahan')`
     *
     * `validated('perubahan')` returns only the keys the `perubahan.*` rules declared.
     * A change set naming a column the schema does not have - `asesment` for `asesmen`,
     * which is the pair this schema most invites a typo on - would therefore be handed
     * to the service as an EMPTY array, and the service would answer "your change set
     * is empty" about a request that plainly carried something.
     *
     * The real check is `RekamMedisService::periksaAmandemen()`, which compares the
     * keys against `RekamMedisService::KOLOM_ISI` - the one allow-list, next to the
     * code that writes them. The `perubahan.*` rules stay for what they are good at:
     * type, length and ENUM validation on the fourteen keys that ARE known.
     *
     * @return array<string, mixed>
     */
    public function perubahan(): array
    {
        $perubahan = $this->input('perubahan', []);

        return is_array($perubahan) ? $perubahan : [];
    }
}
