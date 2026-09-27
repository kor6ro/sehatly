<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Dokter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `dokter` row `GET /api/v1/me` publishes for the caller's **own** doctor account.
 *
 * ## Why this class is not called `DokterResource`
 *
 * `App\Http\Resources\DokterResource` already exists in this working tree, authored
 * concurrently by the plan's **todo 22** for the *public* directory projection: it reads
 * the `v_dokter_katalog` view through `App\Services\Dokter\DokterKatalog` and its
 * `spesialisasi` is the view's `GROUP_CONCAT` string rather than a structured array.
 * Those are two different projections of two different tables with two different
 * audiences, and the two are not reconcilable by widening either one.
 *
 * This class is therefore named for what it publishes - the doctor row *the account
 * owns* - so the two files cannot overwrite each other and a reader can tell from the
 * name which projection a route is getting. Consolidating them, if that is ever right, is
 * a decision for whichever of todos 22 or 23 still owns the directory, and it is recorded
 * here rather than made silently.
 *
 * ## `nomor_str`, `nomor_sip` and the two file URLs are NOT published
 *
 * - `nomor_str` (`:413`) is `VARCHAR(30) NOT NULL UNIQUE` - the Surat Tanda Registrasi,
 *   a professional credential.
 * - `nomor_sip` (`:415`) is `VARCHAR(50) NULL` - the Surat Izin Praktik, the second
 *   credential.
 * - `file_str_url` / `file_sip_url` (`:428`, `:429`) are `VARCHAR(500)` locations of
 *   scans of those same two documents.
 *
 * The obvious objection is that the caller is the owner, so publishing them is harmless.
 * It is still refused, for a structural reason rather than a philosophical one: a
 * `JsonResource` is a reusable class, the public directory is the second thing anybody
 * will build on a "doctor profile" projection, and a credential inside a shared
 * projection is one refactor away from a public endpoint. A doctor who needs their own
 * STR reads it from the back office; a caller who can reach this resource at all can
 * already obtain their own credential by other means. Recorded as a deliberate trade and
 * asserted by a test that no `/me` body contains `nomor_str`.
 *
 * `PasienResource` omits `pasien.nomor_ihs_satusehat` for the same class of reason; the
 * doctor equivalent is published here because it is the account holder's own SATUSEHAT
 * practitioner identifier and the caller has no other way to see it.
 *
 * ## `spesialisasi` and `pendidikan` are structured arrays, not strings
 *
 * `dokter_spesialisasi` carries `is_utama TINYINT(1) NOT NULL DEFAULT 0` (`:441`) and
 * `UNIQUE KEY uq_dokter_spes (dokter_id, spesialisasi_id)` (`:444`), so a doctor may
 * hold several and one may be the main one. Ordered `is_utama` first, then by the
 * master's `nama`; `master_spesialisasi.kode` is `VARCHAR(10) NOT NULL UNIQUE` (`:404`),
 * so the name order is stable for rows that share a name and total in every case, and
 * the list is `values()`-ed so the JSON array has no gaps in its keys.
 *
 * `dokter_pendidikan` is ordered by `tahun_lulus` descending with a null year last: a
 * doctor who has not graduated has no year, and MySQL sorts `NULL` first ascending, so
 * leaving the order alone would put the most recent qualification at the bottom.
 *
 * The column is `tahun_lulus SMALLINT UNSIGNED NULL` (`:462`). Todo 22's prose writes
 * `tahun_lullah` in one clause of one sentence and `tahun_lulus` in the next; the DDL
 * decides it, and `tahun_lulus` is what is used.
 *
 * Both arrays are `null` rather than `[]` when the relation was not eager-loaded, so a
 * caller that forgot to load them cannot mistake "not loaded" for "has none".
 *
 * @property-read Dokter $resource
 */
class DokterAkunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'tipe' => $this->resource->tipe,
            'nomor_ihs_satusehat' => $this->resource->nomor_ihs_satusehat,
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
            'status_aktif' => (bool) $this->resource->status_aktif,
            'spesialisasi' => $this->spesialisasi(),
            'pendidikan' => $this->pendidikan(),
            'dibuat_at' => $this->resource->dibuat_at?->toISOString(),
            'diubah_at' => $this->resource->diubah_at?->toISOString(),
        ];
    }

    /**
     * The doctor's specialisations, main one first.
     *
     * @return list<array<string, mixed>>|null
     */
    private function spesialisasi(): ?array
    {
        if (! $this->resource->relationLoaded('dokterSpesialisasi')) {
            return null;
        }

        return $this->resource->dokterSpesialisasi
            ->sortBy([
                fn ($a, $b): int => (int) $b->is_utama <=> (int) $a->is_utama,
                fn ($a, $b): int => strcmp(
                    (string) $a->spesialisasi?->nama,
                    (string) $b->spesialisasi?->nama,
                ),
            ])
            ->map(fn ($row): array => [
                'spesialisasi_id' => $row->spesialisasi_id,
                'kode' => $row->spesialisasi?->kode,
                'nama' => $row->spesialisasi?->nama,
                'tipe' => $row->spesialisasi?->tipe,
                'is_utama' => (bool) $row->is_utama,
            ])
            ->values()
            ->all();
    }

    /**
     * The doctor's education, most recent qualification first.
     *
     * @return list<array<string, mixed>>|null
     */
    private function pendidikan(): ?array
    {
        if (! $this->resource->relationLoaded('dokterPendidikan')) {
            return null;
        }

        return $this->resource->dokterPendidikan
            ->sortBy([
                fn ($a, $b): int => ($b->tahun_lulus ?? 0) <=> ($a->tahun_lulus ?? 0),
                fn ($a, $b): int => strcmp((string) $a->institusi, (string) $b->institusi),
            ])
            ->map(fn ($row): array => [
                'jenjang' => $row->jenjang,
                'institusi' => $row->institusi,
                'tahun_lulus' => $row->tahun_lulus,
            ])
            ->values()
            ->all();
    }
}
