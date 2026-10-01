<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One row of the patient's own medical-record list (`GET /api/v1/rekam-medis`).
 *
 * ## Why this is NOT `RekamMedisResource`
 *
 * `RekamMedisResource` is the DETAIL shape: the SOAP note, the six-column narrative,
 * the consent signature, the four child collections and the whole amendment chain.
 * A list needs none of that. Its job is to answer "which visits do I have, when were
 * they, what was I seen for, and which revision is current" - so this resource is the
 * allow-list of exactly that question and nothing else.
 *
 * What is deliberately absent: `pasien_id`/`dokter_id`/`faskes_id`/`konsultasi_id`
 * identity columns, the `pasien` block (and therefore the masked NIK),
 * `satusehat_encounter_id`, every SOAP and `riwayat_*` column, the four child
 * collections, and the access log. The test asserts the key set by value, so a future
 * widening is a visible change rather than a silent one.
 *
 * ## The row is a query-builder `stdClass`, NOT a `RekamMedis` model
 *
 * This is the load-bearing difference from every other resource in this directory.
 * `RekamMedis` carries `GuardsMedicalRecordRead`, whose `retrieved` listener throws
 * unless a logged `RekamMedisReadScope` is open - and opening one requires writing an
 * `akses_rekam_medis_log` row per record, which is exactly what the owner ruled out
 * for a list. `RekamMedisService::daftar()` therefore selects through `DB::table()`,
 * which does not fire `retrieved`, and hands this resource plain rows.
 *
 * The consequence is documented rather than hidden: this endpoint discloses no
 * narrative content and writes no log row (one row per listed record is
 * unrepresentable anyway - `akses_rekam_medis_log.rekam_medis_id` is `NOT NULL` and
 * names ONE record, not a page).
 *
 * ## There is no `nomor` column, so `uuid` is the stable reference
 *
 * `rekam_medis` has 28 columns (`telemedicine_test.sql:622-654`) and none of them is
 * a document number. `uuid` (`:623`, `CHAR(36) NOT NULL UNIQUE`) is the stable,
 * non-sequential identifier the detail resource already publishes, so it is what a
 * client passes back. `id` is published beside it because the detail route addresses
 * records by id.
 *
 * ## `adalah_versi_terkini` is the SAME definition the detail resource publishes
 *
 * A row is current when its `versi` equals `MAX(versi)` over its chain group
 * `(pasien_id, dokter_id, tanggal_periksa)`. `RekamMedisResource::adalahTerkini()`
 * reads that maximum off the loaded `ran` collection; here it is computed by the
 * service's correlated subquery and travels as `versi_tertinggi`, so list and detail
 * cannot disagree about which revision is current - including the schema's permitted
 * tie, where two rows share the chain group and the highest version.
 *
 * ## `tanggal_periksa` is an instant
 *
 * `rekam_medis.tanggal_periksa` is a `DATETIME` (`:630`) and rule-(1) instant under
 * the plan's todo 51 policy, so it is `toISOString()` in UTC. The row arrives as the
 * driver's `Y-m-d H:i:s` string because no model cast ran, so it is parsed with
 * `Carbon::parse()` rather than narrowed to `DateTimeInterface` the way
 * `RekamMedisResource::instans()` is.
 */
class RekamMedisDaftarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $baris = (array) $this->resource;

        return [
            'id' => (int) $baris['id'],
            'uuid' => (string) $baris['uuid'],
            'tanggal_periksa' => $this->instan($baris['tanggal_periksa']),
            'keluhan_utama' => $baris['keluhan_utama'],
            'diagnosis_kerja' => $baris['diagnosis_kerja'],
            'status_dokumen' => (string) $baris['status_dokumen'],
            'versi' => (int) $baris['versi'],
            'adalah_versi_terkini' => (int) $baris['versi'] === (int) $baris['versi_tertinggi'],
            'dokter' => [
                'id' => (int) $baris['dokter_id'],
                // The detail resource publishes the doctor's name under the same
                // `dokter.nama_lengkap` shape, so the two agree.
                'nama_lengkap' => $baris['dokter_nama'],
            ],
        ];
    }

    /**
     * A raw `DATETIME` string as an ISO-8601 UTC instant.
     *
     * `tanggal_periksa` is `NOT NULL`, so there is no null branch.
     */
    private function instan(mixed $nilai): string
    {
        return Carbon::parse((string) $nilai)->toISOString();
    }
}
