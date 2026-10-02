<?php

declare(strict_types=1);

namespace App\Http\Requests\Konsultasi;

use App\Services\Dokter\UlasanDokterService;

/**
 * Body validation for `POST /api/v1/konsultasi/{id}/ulasan` - the patient's
 * review of one completed consultation.
 *
 * ## The five caller-owned fields, and the one that defaults to anonymous
 *
 * `rating` is required and bounded 1..5; the two sub-ratings are `nullable`
 * because `rating_komunikasi` and `rating_akurasi` are `TINYINT UNSIGNED NULL`
 * (`telemedicine_test.sql:1056`-`:1057`) and a patient may score the doctor
 * overall without breaking it down. `isi` is `nullable` and capped at 1000 for
 * the same product reason `BalasUlasanRequest` records - the column is `TEXT`,
 * but a review is rendered in a public list.
 *
 * `is_anonim` is `nullable` here and the SERVICE defaults it to `true`:
 * `is_anonim TINYINT(1) NOT NULL DEFAULT 1` (`:1059`) makes anonymity the DDL's
 * default, and an absent flag must mean "anonymous", never "named".
 *
 * ## The machine-owned columns are prohibited
 *
 * `pasien_id`, `dokter_id` and `konsultasi_id` are derived from the path and the
 * authenticated caller; `balasan_dokter` and `dibalas_at` belong to the doctor's
 * endpoint. `prohibited` says so out loud, where omitting them from `rules()`
 * would silently ignore whoever tried to set them.
 *
 * ## The range is expressed with `between`, which matches the DDL's CHECK
 *
 * `ulasan_dokter` is the only table in the whole 75-table contract with
 * `CHECK (rating BETWEEN 1 AND 5)` constraints (`:324`-`:326` of the migration).
 * The rule mirrors them at the edge; the database remains the enforcement that
 * cannot be bypassed by a second code path.
 */
class SimpanUlasanRequest extends KonsultasiRequest
{
    /**
     * The product ceiling on a review body, shared with the doctor's reply.
     */
    public const ISI_MAKS = 1000;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'rating' => [
                'required',
                'integer',
                'between:'.UlasanDokterService::RATING_MIN.','.UlasanDokterService::RATING_MAKS,
            ],
            'rating_komunikasi' => [
                'nullable',
                'integer',
                'between:'.UlasanDokterService::RATING_MIN.','.UlasanDokterService::RATING_MAKS,
            ],
            'rating_akurasi' => [
                'nullable',
                'integer',
                'between:'.UlasanDokterService::RATING_MIN.','.UlasanDokterService::RATING_MAKS,
            ],
            'isi' => ['nullable', 'string', 'max:'.self::ISI_MAKS],
            'is_anonim' => ['nullable', 'boolean'],
            'pasien_id' => ['prohibited'],
            'dokter_id' => ['prohibited'],
            'konsultasi_id' => ['prohibited'],
            'balasan_dokter' => ['prohibited'],
            'dibalas_at' => ['prohibited'],
        ];
    }

    /**
     * Indonesian labels, per the project convention.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rating' => 'rating',
            'rating_komunikasi' => 'rating komunikasi',
            'rating_akurasi' => 'rating akurasi',
            'isi' => 'isi ulasan',
            'is_anonim' => 'anonim',
            'pasien_id' => 'pasien',
            'dokter_id' => 'dokter',
            'konsultasi_id' => 'konsultasi',
            'balasan_dokter' => 'balasan dokter',
            'dibalas_at' => 'waktu balasan',
        ];
    }
}
