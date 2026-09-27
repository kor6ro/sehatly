<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PasienAlergi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of `pasien_alergi`, always the caller's own.
 *
 * ## `dicatat_oleh_user_id` is published as a bare id because it is a bare column
 *
 * `telemedicine_test.sql:281` is `dicatat_oleh_user_id BIGINT UNSIGNED NULL` with **no
 * `FOREIGN KEY`** - one of the 23 reference-shaped columns the DDL deliberately leaves
 * unconstrained, which is why `PasienAlergi` has no `dicatatOleh()` relation to eager
 * load. So the value is published as the integer the column holds, never as a joined
 * user object, and it is never used as an authorisation input: a row is scoped by
 * `pasien_id` alone.
 *
 * It is still published, and the reason is worth stating: a clinician who later adds an
 * allergy to a patient's record writes their own id here, and a patient looking at their
 * own allergy list has a legitimate reason to want to know whether a row was
 * self-reported. The write path sets it to the caller's own id, so a self-reported row
 * is one whose `dicatat_oleh_user_id` equals the caller's `id`.
 *
 * `pasien_id` is not published, for the reason given in
 * {@see PasienAnggotaKeluargaResource}: the list can only ever hold one account's rows.
 *
 * ## `nama_alergen` is free text and is NOT joined to `master_obat`
 *
 * `pasien_alergi.nama_alergen` (`:277`) is a `VARCHAR(150)` and carries no foreign key,
 * so there is no join to perform even if one were wanted. The schema's recorded
 * limitation is that allergy matching is therefore best-effort name matching against
 * `resep_item.nama_obat` (which is a snapshot), and that `pasien.catatan_alergi` is a
 * second, unsynchronised source. This resource publishes the stored string exactly as
 * written and invents no normalisation, because a normalised spelling that disagreed
 * with the database would be a second thing to keep true.
 *
 * @property-read PasienAlergi $resource
 */
class PasienAlergiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'tipe_alergen' => $this->resource->tipe_alergen,
            'nama_alergen' => $this->resource->nama_alergen,
            'reaksi' => $this->resource->reaksi,
            'keparahan' => $this->resource->keparahan,
            'dicatat_oleh_user_id' => $this->resource->dicatat_oleh_user_id,
            'dibuat_at' => $this->resource->dibuat_at?->toISOString(),
        ];
    }
}
