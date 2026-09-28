<?php

declare(strict_types=1);

namespace App\Http\Requests\RekamMedis;

/**
 * `PUT /api/v1/rekam-medis/{id}/final` - sign a draft.
 *
 * The endpoint takes NO body, and every column is `prohibited` rather than merely
 * absent so that a client which assembles one generic "record payload" and posts it
 * everywhere is told which field it should not be sending.
 *
 * `ditandatangani_at` is the one that matters: it is `DATETIME NULL` (:647) and
 * `RekamMedisService::finalisasi()` is its ONLY writer, so a value arriving here would
 * be a second writer for a column whose whole meaning is "the instant this document
 * was signed". Allowing it would make the column record a request time rather than a
 * signing time, and the value is compared byte for byte in the test that a second
 * finalisation does not re-stamp it.
 */
class FinalisasiRekamMedisRequest extends RekamMedisRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::kolomMilikSistem();
    }
}
