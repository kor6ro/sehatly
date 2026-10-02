<?php

declare(strict_types=1);

namespace App\Http\Requests\Dokter;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body validation for `PUT /api/v1/dokter/ulasan/{id}/balas` - the doctor's public
 * reply to one of their own reviews.
 *
 * ## `balasan_dokter` is required, and the column can hold more than 1000
 *
 * `balasan_dokter` is `TEXT NULL` (`telemedicine_test.sql:1060`), so the schema
 * would accept 64 KiB. The 1000-character cap is a product limit, not a storage
 * one: a reply is rendered under a review in a public list, and the F04 contract
 * names ~1000 as the ceiling both the review body and the reply share. An empty
 * string fails `required` rather than blanking an answer that already exists -
 * the route REPLACES a reply, and "replace it with nothing" is not an edit the
 * endpoint publishes.
 *
 * ## The machine-owned columns are prohibited
 *
 * `balasan_dokter` and `dibalas_at` are the two writable columns; `rating`,
 * `rating_komunikasi`, `rating_akurasi`, `isi`, `is_anonim`, `pasien_id`,
 * `dokter_id` and `konsultasi_id` are all `prohibited`, because a doctor answers
 * a review - they do not get to rewrite it or to move it to another patient.
 * `dibalas_at` in particular is stamped by the service from the clock, so a
 * caller cannot backdate their own reply.
 *
 * ## `authorize()` is true: the gates are the route's
 *
 * `tipe:dokter` answers "which account type is this" and
 * `permission:ulasan.balas` answers "does this account hold the grant"; the
 * per-row question ("is this review yours") is `UlasanDokterService::balas()`,
 * which answers 404 for another doctor's review. A validation rule cannot express
 * any of the three.
 */
class BalasUlasanRequest extends FormRequest
{
    /**
     * The product ceiling on a reply, shared with the review body.
     */
    public const BALASAN_MAKS = 1000;

    /**
     * No validation rule can answer "is this review yours".
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'balasan_dokter' => ['required', 'string', 'max:'.self::BALASAN_MAKS],
            'rating' => ['prohibited'],
            'rating_komunikasi' => ['prohibited'],
            'rating_akurasi' => ['prohibited'],
            'isi' => ['prohibited'],
            'is_anonim' => ['prohibited'],
            'pasien_id' => ['prohibited'],
            'dokter_id' => ['prohibited'],
            'konsultasi_id' => ['prohibited'],
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
            'balasan_dokter' => 'balasan dokter',
            'rating' => 'rating',
            'rating_komunikasi' => 'rating komunikasi',
            'rating_akurasi' => 'rating akurasi',
            'isi' => 'isi ulasan',
            'is_anonim' => 'anonim',
            'pasien_id' => 'pasien',
            'dokter_id' => 'dokter',
            'konsultasi_id' => 'konsultasi',
            'dibalas_at' => 'waktu balasan',
        ];
    }
}
