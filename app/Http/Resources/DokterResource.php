<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Dokter\DokterKatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of `v_dokter_katalog`: the public directory's list projection.
 *
 * ## This is an allow-list, and that is the whole point
 *
 * The view is DDL-owned and can gain a column without anybody editing this file, so
 * a `return $this->resource->toArray()` would publish `dokter.nomor_str`,
 * `file_str_url` and `file_sip_url` the moment the view were widened - and
 * `v_dokter_katalog` is one `GROUP BY` away from being able to select any
 * `dokter` column. Every key below is named on purpose, so a new column is invisible
 * to the API until somebody adds it here.
 *
 * ## The three columns that are deliberately absent
 *
 * | not published | why |
 * | --- | --- |
 * | `nomor_str`, `nomor_sip`, `file_str_url`, `file_sip_url` | licence documents. The plan's todo 22 forbids them publicly: they are admin-only fields (`:413`, `:415`, `:428`, `:429`). |
 * | `no_telepon`, `email` | `users.no_telepon` (`:137`) and `users.email` (`:136`). The plan forbids publishing a doctor's contact details, and the view exposes neither anyway. |
 * | `str_berlaku_sampai` | the STR expiry is a *rule input*, not something a patient needs. `DokterDirectoryTest` asserts it is applied but not published. |
 *
 * `status_verifikasi` is published because a row in this projection is
 * `terverifikasi` by construction (`:1183`), so the value is a constant here and
 * publishing it tells a client the rule was applied without disclosing anything
 * about a specific doctor that is not already true of every listed row.
 *
 * ## `spesialisasi` is the view's `GROUP_CONCAT` string, not an array
 *
 * `telemedicine_test.sql:1178` builds it as
 * `GROUP_CONCAT(s.nama SEPARATOR ', ')`, so the value is already the joined,
 * display-ready form and is passed through untouched. It is `null` for a doctor with
 * no `dokter_spesialisasi` row, because `GROUP_CONCAT` over nothing is `NULL` and the
 * join is a `LEFT JOIN` (`:1181`-`:1182`).
 *
 * **It is truncated, silently, at `group_concat_max_len`.** `telemedicine_test.sql`
 * sets that variable nowhere, so the cap is the server's session default (1024) and a
 * doctor with enough specialisations loses the tail of the list with no error. That
 * is the DDL's behaviour and this resource does not paper over it; the detail
 * endpoint publishes the structured list instead, which is why a client that needs a
 * complete specialisation set must read the detail rather than the list.
 *
 * ## Timestamps
 *
 * None. The view selects seven columns (`:1172`-`:1178`) and none of them is a
 * timestamp, so there is nothing to emit.
 *
 * @property-read DokterKatalog $resource
 */
class DokterResource extends JsonResource
{
    /**
     * The view's seven published columns, plus the verification constant, plus the
     * two fields F03 §4.4 adds to the list card.
     *
     * | field | source |
     * | --- | --- |
     * | `pengalaman_tahun` | `dokter.pengalaman_tahun` (`:418`), selected from the joined row by {@see DokterDirectoryService::query()} |
     * | `jumlah_ulasan` | **recomputed** from `ulasan_dokter` for the page's ids by {@see DokterDirectoryService::lampirkanJumlahUlasan()} |
     *
     * **`jumlah_ulasan` is not `dokter.jumlah_ulasan`.** That column has no writer
     * (`UlasanDokterService`'s class docblock), so publishing it would print a
     * number no patient ever produced; F03 §4.4 item 3 and the owner decision say to
     * recompute instead. The value is the same `COUNT(*)` F04's `agregat()` reports,
     * batched into one query per page so the resource cannot be N+1.
     *
     * `durasi_default_menit` stays **absent, not null**: it is a real `dokter`
     * column (`:422`) that neither the view nor the list projection reads, and a
     * `null` would say "unset" for a value that was never fetched. The detail
     * endpoint is where it lives.
     *
     * `rating_rata_rata` remains the view's stored column: F03 §4.4 lists it as an
     * already-published scalar, the UI renders it, and this round's approved scope
     * changes the SORT for `?sort=rating` only. Sorting by the recomputed average
     * while the card still prints the stored one is the known asymmetry recorded in
     * the F03 backend report; republishing it belongs with the same decision for the
     * detail resource's counters.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->dokter_id,
            'nama_lengkap' => $this->resource->nama_lengkap,
            'tipe' => $this->resource->tipe,
            'pengalaman_tahun' => (int) $this->resource->pengalaman_tahun,
            'spesialisasi' => $this->resource->spesialisasi,
            'biaya_konsultasi_online' => $this->resource->biaya_konsultasi_online,
            'rating_rata_rata' => $this->resource->rating_rata_rata,
            'jumlah_ulasan' => (int) $this->resource->jumlah_ulasan,
            'jumlah_konsultasi' => $this->resource->jumlah_konsultasi,
            'status_verifikasi' => 'terverifikasi',
        ];
    }
}
