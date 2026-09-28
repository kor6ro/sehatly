<?php

declare(strict_types=1);

namespace App\Http\Requests\RekamMedis;

use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/konsultasi/{id}/rekam-medis` - create a draft.
 *
 * ## The body is the record's own content, and nothing else
 *
 * The consultation id is a PATH segment, so the patient and the doctor are read off
 * the consultation itself and `prohibited` in {@see RekamMedisRequest::KOLOM_MILIK_SISTEM}
 * - there is no body field for either, and a caller who sent one is told so by name
 * rather than having it dropped.
 *
 * `tanggal_periksa` is the ONE identity column this request may set, and it is
 * `date_format:Y-m-d H:i:s` rather than `date` for the reason
 * `IndexSlotDokterRequest` gives: `date` accepts `now`, `+1 week` and `2026-12-7`,
 * and `tanggal_periksa` is a `DATETIME` that is also the chain group key, so a loose
 * rule would put two records in different chains for the same visit. `date_format`
 * round-trips through `format()` before comparing, which is what rejects the
 * overflowing forms `DateTime::createFromFormat` would otherwise accept.
 *
 * It is `nullable` because the service defaults it to now when absent, and the column
 * is `NOT NULL` (:630) - so "not supplied" and "supplied as null" are the same request
 * here, and neither produces a 1364.
 */
class SimpanRekamMedisRequest extends RekamMedisRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(
            self::kolomIsi(),
            [
                'tanggal_periksa' => ['nullable', 'date_format:Y-m-d H:i:s'],
            ],
            self::kolomMilikSistem(),
        );
    }
}
