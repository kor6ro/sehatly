<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RekamMedis;
use App\Support\NikMasker;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One `rekam_medis` row, as all five of this todo's endpoints publish it.
 *
 * ## Allow-list, never `toArray()`
 *
 * `rekam_medis` is the most sensitive table in the schema - it is the SOAP note, the
 * history, the examination findings and the consent signature - so the allow-list is
 * the security control rather than a convenience. Nothing is published because it
 * exists on the model; a column has to be named here to reach the wire, and a column
 * added by a later migration is invisible until somebody decides it should not be.
 *
 * Two things are deliberately NOT here. `aksesRekamMedisLog` is a relation on the
 * model, and the access log is written FOR the audit trail rather than handed back to
 * the caller who caused it - a patient who can read their own access history learns
 * which doctor looked at their file, which is a question the platform should answer
 * in one place, not by omission. And the identity columns a caller cannot influence
 * (`uuid`, `pasien_id`, `dokter_id`, `faskes_id`, `konsultasi_id`,
 * `satusehat_encounter_id`) are published as ids only, with the profiles nested and
 * the patient NIK masked through the project masker.
 *
 * ## `versi` is published as an INT
 *
 * `rekam_medis.versi` is `TINYINT UNSIGNED` (`telemedicine_test.sql:646`) and MySQL
 * hands a TINYINT back over the wire protocol as a string, so an un-cast `versi`
 * publishes as `"1"` and a client comparing it with a number has to know that. The
 * cast is here rather than in the model's `casts()` because the model's cast list was
 * written by todo 19 to mirror the DDL and this todo does not own it; both are
 * defensible and this one is the one that cannot drift silently out of the response.
 *
 * ## The `ran` block is the amendment chain, and it is never truncated
 *
 * `rekam_medis` has no linkage column, so the chain is reconstructed by
 * `(pasien_id, dokter_id, tanggal_periksa)` ordered by `versi` - see
 * `RekamMedisService`'s docblock. Every revision is published, ascending, with
 * `adalah_versi_terkini` naming the one the service considers current. A resource
 * that published only the head of the chain would be a silent truncation of the
 * history, which is the one thing a medical record may not do.
 *
 * A chain entry is a REDUCED projection - id, uuid, `versi`, `status_dokumen`,
 * `ditandatangani_at`, `dibuat_at` and the two most-changed clinical fields - and
 * not a nested full record. Nesting full records would make a three-version chain
 * publish three complete SOAP notes per revision, and the head already carries the
 * complete note. What a client cannot get from the chain it gets by addressing the
 * revision's own id, which is a separate logged read.
 *
 * ## Instants versus wall clock, and the two are not interchangeable
 *
 * `tanggal_periksa` is a `DATETIME` (:630) and is a rule-(1) INSTANT under the
 * plan's todo 51 policy, so it is `toISOString()`. `jadwal_kontrol` is a `DATE`
 * (:644) and is Asia/Jakarta wall clock, so it is `toDateString()` and never an
 * instant - `KonsultasiResource` makes the same split for the same reason, and
 * `docs/mobile-integration.md` section 6 is the client-side counterpart.
 *
 * @property-read RekamMedis $resource
 */
class RekamMedisResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'uuid' => $this->resource->uuid,
            'pasien_id' => $this->resource->pasien_id,
            'faskes_id' => $this->resource->faskes_id,
            'dokter_id' => $this->resource->dokter_id,
            'konsultasi_id' => $this->resource->konsultasi_id,
            'satusehat_encounter_id' => $this->resource->satusehat_encounter_id,
            'tipe_kunjungan' => $this->resource->tipe_kunjungan,
            'tanggal_periksa' => $this->instans($this->resource->tanggal_periksa),
            'keluhan_utama' => $this->resource->keluhan_utama,
            'riwayat_penyakit_sekarang' => $this->resource->riwayat_penyakit_sekarang,
            'riwayat_penyakit_dahulu' => $this->resource->riwayat_penyakit_dahulu,
            'riwayat_keluarga' => $this->resource->riwayat_keluarga,
            'riwayat_psikososial' => $this->resource->riwayat_psikososial,
            'hasil_pemeriksaan_fisik' => $this->resource->hasil_pemeriksaan_fisik,
            'subjektif' => $this->resource->subjektif,
            'objektif' => $this->resource->objektif,
            'asesmen' => $this->resource->asesmen,
            'plan' => $this->resource->plan,
            'diagnosis_kerja' => $this->resource->diagnosis_kerja,
            'instruksi_tindak_lanjut' => $this->resource->instruksi_tindak_lanjut,
            'status_tindak_lanjut' => $this->resource->status_tindak_lanjut,
            'jadwal_kontrol' => $this->tanggal($this->resource->jadwal_kontrol),
            'status_dokumen' => $this->resource->status_dokumen,
            'versi' => (int) $this->resource->versi,
            'ditandatangani_at' => $this->instans($this->resource->ditandatangani_at),
            'dibuat_at' => $this->instans($this->resource->dibuat_at),
            'diubah_at' => $this->instans($this->resource->diubah_at),
            'adalah_versi_terkini' => $this->adalahTerkini(),
            'pasien' => $this->whenLoaded('pasien', fn (): ?array => $this->resource->pasien === null ? null : [
                'id' => (int) $this->resource->pasien->getKey(),
                'nik' => NikMasker::mask($this->resource->pasien->nik),
                'nama_lengkap' => $this->resource->pasien->user?->nama_lengkap,
            ]),
            'dokter' => $this->whenLoaded('dokter', fn (): ?array => $this->resource->dokter === null ? null : [
                'id' => (int) $this->resource->dokter->getKey(),
                'nama_lengkap' => $this->resource->dokter->user?->nama_lengkap,
            ]),
            'diagnosa' => $this->whenLoaded('rekamMedisDiagnosa', fn (): array => $this->anak(
                $this->resource->rekamMedisDiagnosa,
                ['id', 'icd10_kode', 'deskripsi', 'jenis', 'tipe_kasus', 'is_terkonfirmasi'],
            )),
            'tindakan' => $this->whenLoaded('rekamMedisTindakan', fn (): array => $this->anak(
                $this->resource->rekamMedisTindakan,
                ['id', 'icd9cm_kode', 'nama_tindakan', 'keterangan', 'dokter_pelaksana_id'],
                ['tanggal_tindakan' => 'instans'],
            )),
            'lampiran' => $this->whenLoaded('rekamMedisLampiran', fn (): array => $this->anak(
                $this->resource->rekamMedisLampiran,
                ['id', 'nama_file', 'file_url', 'tipe'],
            )),
            'persetujuan' => $this->whenLoaded('rekamMedisPersetujuan', fn (): array => $this->anak(
                $this->resource->rekamMedisPersetujuan,
                ['id', 'tipe', 'isi_persetujuan', 'ditandatangani_oleh', 'hubungan_dengan_pasien', 'tanda_tangan_url'],
                ['ditandatangani_at' => 'instans'],
            )),
            'ran' => $this->whenLoaded('ran', fn (): array => $this->rantai()),
        ];
    }

    /**
     * Is this row the highest `versi` in its chain group?
     *
     * Derived from the loaded `ran` collection rather than re-queried: a resource
     * that answered this with its own query would be a read, and a read that did not
     * log is the one thing this whole design refuses. The collection is built inside
     * the logged read's permit, so the answer is a fact about materialised rows.
     */
    private function adalahTerkini(): bool
    {
        $rantai = $this->resource->getRelation('ran');

        if ($rantai->isEmpty()) {
            return true;
        }

        return (int) $rantai->max('versi') === (int) $this->resource->versi;
    }

    /**
     * The amendment chain, ascending, as a reduced projection.
     *
     * @return list<array<string, mixed>>
     */
    private function rantai(): array
    {
        return $this->resource->getRelation('ran')
            ->map(fn (RekamMedis $row): array => [
                'id' => (int) $row->getKey(),
                'uuid' => $row->uuid,
                'versi' => (int) $row->versi,
                'status_dokumen' => $row->status_dokumen,
                'keluhan_utama' => $row->keluhan_utama,
                'diagnosis_kerja' => $row->diagnosis_kerja,
                'ditandatangani_at' => $this->instans($row->ditandatangani_at),
                'dibuat_at' => $this->instans($row->dibuat_at),
            ])
            ->values()
            ->all();
    }

    /**
     * One child collection, projected onto named keys.
     *
     * A child is a list of small clinical rows and each is published whole, so the
     * projection is a key list rather than a per-column map. The `$tanggal` map names
     * the DATETIME columns that are rule-(1) instants; anything not named is
     * published as stored.
     *
     * @param  Collection<int, Model>  $baris
     * @param  list<string>  $kolom
     * @param  array<string, string>  $tanggal  column name to `instans` or `tanggal`
     * @return list<array<string, mixed>>
     */
    private function anak(Collection $baris, array $kolom, array $tanggal = []): array
    {
        return $baris
            ->map(function ($row) use ($kolom, $tanggal): array {
                $hasil = ['id' => (int) $row->getKey()];

                foreach ($kolom as $satu) {
                    $nilai = $row->{$satu};

                    $hasil[$satu] = match ($tanggal[$satu] ?? null) {
                        'instans' => $this->instans($nilai),
                        'tanggal' => $this->tanggal($nilai),
                        default => $nilai,
                    };
                }

                return $hasil;
            })
            ->values()
            ->all();
    }

    /**
     * A `DATETIME` or `TIMESTAMP` as an ISO-8601 UTC instant, or null.
     *
     * `DateTimeInterface` and NOT `Illuminate\Support\Carbon`: on
     * laravel/framework 13.33 an Eloquent `datetime` cast returns a
     * `Carbon\CarbonImmutable`, a SIBLING of `Carbon\Carbon` and therefore not an
     * `Illuminate\Support\Carbon` either, so a `instanceof Carbon` check would return
     * null and the response would quietly publish no timestamp. `KonsultasiResource`
     * documents the same trap and the same fix.
     */
    private function instans(mixed $nilai): ?string
    {
        if (! $nilai instanceof DateTimeInterface) {
            return null;
        }

        return Carbon::instance($nilai)->toISOString();
    }

    /**
     * A `DATE` as an Asia/Jakarta wall-clock day, or null.
     *
     * NEVER offset-converted and never an instant. `jadwal_kontrol` is a day a patient
     * is to come back on, and turning it into an instant would move a next-day
     * appointment to the previous evening in any client that renders local time.
     */
    private function tanggal(mixed $nilai): ?string
    {
        if (! $nilai instanceof DateTimeInterface) {
            return null;
        }

        return Carbon::instance($nilai)->toDateString();
    }
}
