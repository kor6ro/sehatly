<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Dokter;
use App\Models\DokterFaskes;
use App\Models\DokterPendidikan;
use App\Models\DokterSpesialisasi;
use App\Models\Faskes;
use App\Models\MasterSpesialisasi;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * `GET /api/v1/dokter/{dokter}`: the full public profile of one eligible doctor.
 *
 * ## This is the endpoint that reads the `dokter` table, not the view
 *
 * The plan's todo 22 is explicit that the view "exposes no `foto_profil`, no
 * `jumlah_ulasan`, and no `status_aktif`, so those must come from the `dokter` table
 * on the detail endpoint". {@see DokterDirectoryService::find()} therefore re-reads
 * the `dokter` row with its `user` relation and the three child tables eager-loaded,
 * after the view-backed query has already proved the row is eligible.
 *
 * ## The four fields that are never published, and why
 *
 * | not published | DDL | why |
 * | --- | --- | --- |
 * | `nomor_str`, `nomor_sip`, `nomor_ihs_satusehat` | `:413`, `:415`, `:417` | a licence number is an administrative identifier. The plan's todo 22 forbids it publicly and a public directory is the definition of public. |
 * | `file_str_url`, `file_sip_url` | `:428`, `:429` | `VARCHAR(500)` document URLs. The plan names them admin-only; publishing them would hand an anonymous caller a signed-URL surface if the storage is ever configured for it. |
 * | `no_telepon`, `email` | `users` `:137`, `:136` | direct contact details. The plan forbids them. A doctor's contact is reached through a booking. |
 *
 * The first two rows are additionally checked by a test that scans the serialised
 * body for the substrings `nomor_str`, `file_str_url` and `file_sip_url`, so the
 * prohibition is pinned on the wire and not merely in a docblock.
 *
 * ## What *is* published about the STR
 *
 * Nothing. `str_berlaku_sampai` (`:414`) is a rule input: the service has already
 * decided the doctor is eligible because of it, and a patient does not need the
 * date. A client that wanted it would be building an expiry reminder, which is an
 * admin concern, and `dokter.profil` (an admin-facing permission code in
 * `RbacCatalog`) is where that belongs.
 *
 * ## Ordering, and why it is in the service
 *
 * `spesialisasi` is `is_utama DESC` (the plan's rule) and `pendidikan` is
 * `tahun_lulus DESC` (the plan's rule, spelled with the DDL's own column name
 * `tahun_lulus` at `:462`). Both orders, and both unique tiebreakers, are applied in
 * {@see DokterDirectoryService::find()} rather than here, so a client cannot observe
 * a different order depending on which caller built the collection.
 *
 * MySQL sorts `NULL` last under `DESC`, so a `SMALLINT UNSIGNED NULL` graduation
 * year (`:462`) lists after every dated one without an extra `IS NULL` term.
 *
 * ## Timestamps
 *
 * `dibuat_at` is published; `diubah_at` is not. `diubah_at` is
 * `ON UPDATE CURRENT_TIMESTAMP` (`:432`), so it moves whenever an admin touches the
 * row for any reason and would tell a patient when the doctor's profile was last
 * edited, which is neither useful nor the plan's field list. The project's
 * end-to-end UTC policy is todo 51's; `toISOString()` is used consistently with
 * `UserResource` and `UserDeviceResource` until then.
 *
 * @property-read Dokter $resource
 */
class DokterDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'nama_lengkap' => $this->resource->user->nama_lengkap,
            'foto_profil' => $this->resource->user->foto_profil,
            'tipe' => $this->resource->tipe,
            'pengalaman_tahun' => $this->resource->pengalaman_tahun,
            'bio' => $this->resource->bio,
            'biaya_konsultasi_online' => $this->resource->biaya_konsultasi_online,
            'biaya_luar_jam' => $this->resource->biaya_luar_jam,
            'durasi_default_menit' => $this->resource->durasi_default_menit,
            'rating_rata_rata' => $this->resource->rating_rata_rata,
            'jumlah_ulasan' => $this->resource->jumlah_ulasan,
            'jumlah_konsultasi' => $this->resource->jumlah_konsultasi,
            'tersedia_telemedisin' => (bool) $this->resource->tersedia_telemedisin,
            'status_verifikasi' => $this->resource->status_verifikasi,
            'spesialisasi' => $this->resource->dokterSpesialisasi
                ->map(fn (DokterSpesialisasi $row): array => $this->spesialisasi($row))
                ->values()
                ->all(),
            'pendidikan' => $this->resource->dokterPendidikan
                ->map(fn (DokterPendidikan $row): array => [
                    'id' => $row->id,
                    'jenjang' => $row->jenjang,
                    'institusi' => $row->institusi,
                    'tahun_lulus' => $row->tahun_lulus,
                ])
                ->values()
                ->all(),
            'faskes' => $this->resource->dokterFaskes
                ->map(fn (DokterFaskes $row): array => $this->faskes($row))
                ->values()
                ->all(),
            'dibuat_at' => $this->waktu($this->resource->dibuat_at),
        ];
    }

    /**
     * One `dokter_spesialisasi` row, joined out to the master record it points at.
     *
     * `is_utama` is published because the list ordering is built on it, so a client
     * rendering the profile has to be able to see which specialisation the doctor
     * considers primary.
     *
     * @return array<string, mixed>
     */
    private function spesialisasi(DokterSpesialisasi $row): array
    {
        $master = $row->spesialisasi;

        return [
            'id' => $master instanceof MasterSpesialisasi ? $master->id : null,
            'kode' => $master instanceof MasterSpesialisasi ? $master->kode : null,
            'nama' => $master instanceof MasterSpesialisasi ? $master->nama : null,
            'tipe' => $master instanceof MasterSpesialisasi ? $master->tipe : null,
            'is_utama' => (bool) $row->is_utama,
        ];
    }

    /**
     * One `dokter_faskes` row with the `faskes` it points at.
     *
     * `dokter_faskes` is a composite-PK pivot (`:447`-`:455`, `PRIMARY KEY
     * (dokter_id, faskes_id)`) with no `id` column and no timestamps, so there is no
     * surrogate to publish and `faskes_id` is the handle.
     *
     * `status_aktif` is published and **not** filtered on: `dokter_faskes` has its own
     * `status_aktif TINYINT(1) NOT NULL DEFAULT 1` (`:451`) distinct from
     * `faskes.status_aktif` (`:378`), and hiding an inactive affiliation would leave
     * a client unable to tell "not affiliated" from "affiliation withdrawn".
     *
     * `faskes.telepon` and `faskes.email` (`:374`, `:375`) are a *facility's*
     * published contact details rather than a private person's, but they are still
     * omitted here: nothing in the plan's field list for this endpoint includes them,
     * and a facility's phone number belongs to the facility's own page.
     *
     * @return array<string, mixed>
     */
    private function faskes(DokterFaskes $row): array
    {
        $faskes = $row->faskes;

        return [
            'faskes_id' => $row->faskes_id,
            'is_utama' => (bool) $row->is_utama,
            'status_aktif' => (bool) $row->status_aktif,
            'kode_faskes' => $faskes instanceof Faskes ? $faskes->kode_faskes : null,
            'nama' => $faskes instanceof Faskes ? $faskes->nama : null,
            'tipe' => $faskes instanceof Faskes ? $faskes->tipe : null,
            'kelas_rs' => $faskes instanceof Faskes ? $faskes->kelas_rs : null,
            'alamat' => $faskes instanceof Faskes ? $faskes->alamat : null,
        ];
    }

    /**
     * `toISOString()` on a nullable column, so an absent stamp is `null` not `""`.
     *
     * The parameter is typed `mixed` rather than `Carbon` on purpose: a `date`/
     * `datetime` cast hands back a `Carbon\CarbonImmutable` under this project's
     * configuration, which is **not** an `Illuminate\Support\Carbon`, so a `Carbon`
     * type-hint is a `TypeError` on every single detail response. The `instanceof`
     * check is the honest guard and it also covers the `null` case, so one method
     * answers "no stamp" and "wrong type" without a second branch at the call site.
     */
    private function waktu(mixed $waktu): ?string
    {
        return $waktu instanceof DateTimeInterface ? Carbon::instance($waktu)->toISOString() : null;
    }
}
