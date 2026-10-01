<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One `akses_rekam_medis_log` row (`GET /api/v1/rekam-medis/{id}/akses`).
 *
 * ## Three fields, and the actor's NAME is deliberately not one of them
 *
 * `akses_rekam_medis_log` (`telemedicine_test.sql:1147-1155`) has five columns and
 * this resource publishes the three the owner approved:
 *
 * | published | column | why |
 * | --- | --- | --- |
 * | `waktu` | `dibuat_at` (`:1152`) | when the access happened |
 * | `peran` | `users.tipe` of `pengakses_user_id` (`:1150`) | WHAT KIND of account opened the record |
 * | `tujuan_akses` | `tujuan_akses` (`:1151`) | the stated purpose, one of the five ENUM values |
 *
 * NOT published: `pengakses_user_id`, `rekam_medis_id`, and - the decision this
 * docblock exists for - the actor's name.
 *
 * The table stores no name at all; publishing one would require joining
 * `users.nama_lengkap` in. It is not joined, on purpose. UU PDP No. 27/2022 requires
 * processing to be limited to the purpose it was collected for (Pasal 5, data
 * minimisation) and secured against unnecessary disclosure (Pasal 34), and this
 * endpoint's purpose is transparency - "my record was opened, by what kind of actor,
 * for what purpose, when" - not the identification of the individual clinician or
 * officer. A patient who can read a NAME learns which person touched their file,
 * which exposes that person's involvement in the patient's care to a surface it was
 * not collected for, and invites pressure on staff exercising a legitimate duty. The
 * `peran` value answers the legitimate question without that disclosure; a review
 * that needs the person behind the access belongs to the `audit` process through the
 * `pengakses_user_id` foreign key, under the oversight account the plan names, not
 * to this patient-facing list.
 *
 * ## `peran` is `users.tipe`, not the RBAC role name
 *
 * `users.tipe` is a seven-value ENUM (`:139`, `RbacCatalog::USER_TYPES`) - `pasien`,
 * `dokter`, `perawat`, `apoteker`, `kurir`, `admin`, `superadmin`. It is a single
 * column on a row the query already joins for the FK, so it publishes the coarsest
 * accurate answer with no extra join into the permission tables. It pairs with
 * `tujuan_akses` and never replaces it: `audit` names an oversight purpose, and
 * `admin`/`superadmin` name the two oversight account types; `perawatan` names a
 * clinical purpose, and `dokter` names the account type that reads for it.
 *
 * ## `waktu` is an instant
 *
 * `dibuat_at` is a `TIMESTAMP` (`:1152`), so it is `toISOString()` in UTC - the same
 * treatment `RekamMedisDaftarResource` gives `tanggal_periksa` and the detail
 * resource gives `dibuat_at`.
 */
class AksesRekamMedisResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $baris = (array) $this->resource;

        return [
            'waktu' => Carbon::parse((string) $baris['dibuat_at'])->toISOString(),
            'peran' => (string) $baris['peran'],
            'tujuan_akses' => (string) $baris['tujuan_akses'],
        ];
    }
}
