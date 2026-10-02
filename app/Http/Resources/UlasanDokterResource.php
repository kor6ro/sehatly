<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\UlasanDokter;
use App\Support\NamaMasker;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One `ulasan_dokter` row, as published by the public review list, the patient's
 * write response, and the doctor's reply response.
 *
 * ## This is an allow-list, and that is the whole point
 *
 * `ulasan_dokter` has twelve columns (`telemedicine_test.sql:1051`-`:1062`) and
 * exactly the eight the F04 contract names are published. The four that are
 * NEVER published, and why:
 *
 * | not published | DDL | why |
 * | --- | --- | --- |
 * | `konsultasi_id` | `:1052` | it joins the review to a clinical encounter. A public reader could correlate reviews with a bookable session; a stranger has no business deriving "this patient saw this doctor on this date". |
 * | `pasien_id` | `:1053` | the reviewer's surrogate key. Publishing it would de-anonymise every `is_anonim` review by joining it to any other surface that names the patient. |
 * | `dokter_id` | `:1054` | redundant: the list is already scoped to one doctor and the item is nested under that response. |
 * | `diubah_at` | — | does not exist on this table; `dibuat_at` (`:1062`) is the only timestamp. There is deliberately no edit time. |
 *
 * The prohibition is enforced by the test suite scanning the serialised body for
 * the substrings `pasien_id` and `konsultasi_id`, not by this docblock.
 *
 * ## `penulis` follows `is_anonim`, and the named case is still MASKED
 *
 * `is_anonim TINYINT(1) NOT NULL DEFAULT 1` (`:1059`): the default is anonymous, so
 * `penulis` is `null` unless the author positively opted out. When they did,
 * {@see NamaMasker::mask()} publishes `Siti Aminah` as `S••• A•••••` - the shape
 * and initial of a name, never the name - which is the same masking primitive
 * `SuratKeteranganService` uses for its public verifier. Publishing the full name
 * (as some benchmarked competitors do in their JSON-LD) is explicitly what F04
 * section 3 refuses to copy.
 *
 * A patient row that is soft-deleted resolves to `null` through the relation, so a
 * missing name fails CLOSED: the review is published, the identity is not.
 *
 * ## Timestamps are ISO-8601 UTC, consistently with `UserResource`
 *
 * `toISOString()` on a nullable column so an absent stamp is `null`, never `""`.
 * The parameter is `mixed` rather than `Carbon` because a `datetime` cast hands
 * back a `CarbonImmutable` under this project's configuration, which is a sibling
 * of `Illuminate\Support\Carbon` and not an instance of it.
 *
 * @property-read UlasanDokter $resource
 */
class UlasanDokterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'rating' => (int) $this->resource->rating,
            'rating_komunikasi' => $this->bintang($this->resource->rating_komunikasi),
            'rating_akurasi' => $this->bintang($this->resource->rating_akurasi),
            'isi' => $this->resource->isi,
            'is_anonim' => (bool) $this->resource->is_anonim,
            'penulis' => $this->penulis(),
            'balasan_dokter' => $this->resource->balasan_dokter,
            'dibalas_at' => $this->waktu($this->resource->dibalas_at),
            'dibuat_at' => $this->waktu($this->resource->dibuat_at),
        ];
    }

    /**
     * The reviewer's display name, or `null` when the review is anonymous.
     *
     * The patient relation is eager-loaded by every query that builds this
     * resource, so this never issues a query of its own; the null-safe walk is
     * still there because a soft-deleted patient makes the relation null and the
     * answer must be "no name" rather than an exception.
     */
    private function penulis(): ?string
    {
        if ((bool) $this->resource->is_anonim) {
            return null;
        }

        return NamaMasker::mask($this->resource->pasien?->user?->nama_lengkap);
    }

    /**
     * One of the three `TINYINT UNSIGNED NULL` scores, as an int or `null`.
     *
     * A sub-rating is `NULL` when the author scored the doctor overall and not
     * the breakdown (`:1056`, `:1057`), and "not scored" must not encode as 0.
     */
    private function bintang(mixed $nilai): ?int
    {
        return $nilai === null ? null : (int) $nilai;
    }

    /**
     * `toISOString()` on a nullable stamp, so an absent one is `null`.
     */
    private function waktu(mixed $waktu): ?string
    {
        return $waktu instanceof DateTimeInterface ? Carbon::instance($waktu)->toISOString() : null;
    }
}
