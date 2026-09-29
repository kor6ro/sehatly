<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Rujukan;
use App\Models\SuratKeterangan;
use App\Support\NikCipher;
use App\Support\NikMasker;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One `surat_keterangan` row, as the AUTHENTICATED endpoints publish it.
 *
 * ## This is NOT what the public verifier publishes
 *
 * `GET /api/v1/surat-keterangan/{nomor_surat}/verify` is a different capability with a
 * different audience and it publishes six fields from a hand-built array in
 * {@see \App\Services\SuratKeterangan\SuratKeteranganService::verifikasi()}, not this
 * resource. The resource exists for the patient's own list and for the issuing doctor's
 * response, where the caller is already entitled to the letter. Reusing it on the
 * public route would publish the NIK, the letter body and the clinical period to any
 * scanner, and the two shapes are kept apart deliberately rather than by a flag.
 *
 * ## Allow-list, never `toArray()`
 *
 * A medical letter is a signed clinical document, so the allow-list is the security
 * control rather than a convenience. Nothing is published because it exists on the
 * model: a column has to be named here to reach the wire.
 *
 * ## The NIK is masked here, and published NOT AT ALL on the public route
 *
 * Two different answers to two different audiences, and both are deliberate. The
 * patient is a legitimate holder of their own NIK, but this one response shape is also
 * what a doctor's list view and a support export would carry, so the identifier goes
 * out MASKED through the project masker and never in the clear. The public verifier
 * publishes no NIK at all, not even masked, because a masked 16-digit national
 * identifier tells a stranger eight digits and that endpoint's caller may be anybody.
 *
 * ## `qr_token` IS published, and that is the point
 *
 * The token is the payload of the QR code, and the patient is the person who has to
 * render it and show it to a clinic. The bearer-secret argument that would justify
 * hiding it applies to an UNAUTHENTICATED reader, not to the account that owns the
 * document.
 *
 * ## `jumlah_hari` is an INT
 *
 * `jumlah_hari` is `TINYINT UNSIGNED` (`:590`) and MySQL hands a TINYINT back over the
 * wire protocol as a string, so an un-cast value publishes as `"3"` and a client
 * comparing it with a number has to know that. The cast is here rather than in the
 * model's `casts()` because the model was written by todo 19 to mirror the DDL and this
 * todo does not own it - the same trade `RekamMedisResource` makes for `versi`.
 *
 * ## Instants versus wall clock, and the two are not interchangeable
 *
 * `tanggal_mulai`, `tanggal_selesai` and `rujukan.berlaku_sampai` are `DATE` columns
 * (`:588`, `:589`, `:608`) and are Asia/Jakarta wall-clock days - `toDateString()` and
 * never an instant, because turning a day a patient is to be somewhere else into an
 * instant moves it to the previous evening in any client that renders local time.
 * `dibuat_at` is a `TIMESTAMP` (`:594`) and is a rule-(1) instant, so `toISOString()`.
 * `docs/mobile-integration.md` section 6 is the client-side counterpart, and
 * `SuratKeteranganTest` asserts which shape each of the four takes.
 *
 * @property-read SuratKeterangan $resource
 */
class SuratKeteranganResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'nomor_surat' => $this->resource->nomor_surat,
            'konsultasi_id' => $this->resource->konsultasi_id,
            'tipe' => $this->resource->tipe,
            'pasien_id' => $this->resource->pasien_id,
            'dokter_id' => $this->resource->dokter_id,
            'tanggal_mulai' => $this->tanggal($this->resource->tanggal_mulai),
            'tanggal_selesai' => $this->tanggal($this->resource->tanggal_selesai),
            'jumlah_hari' => $this->resource->jumlah_hari === null ? null : (int) $this->resource->jumlah_hari,
            'isi' => $this->resource->isi,
            'qr_token' => $this->resource->qr_token,
            'file_url' => $this->resource->file_url,
            'dibuat_at' => $this->instans($this->resource->dibuat_at),
            'pasien' => $this->whenLoaded('pasien', fn (): ?array => $this->resource->pasien === null ? null : [
                'id' => (int) $this->resource->pasien->getKey(),
                'nik' => NikCipher::mask($this->resource->pasien->nik_cipher, $this->resource->pasien->nik),
                'nama_lengkap' => $this->resource->pasien->user?->nama_lengkap,
            ]),
            'dokter' => $this->whenLoaded('dokter', fn (): ?array => $this->resource->dokter === null ? null : [
                'id' => (int) $this->resource->dokter->getKey(),
                'nama_lengkap' => $this->resource->dokter->user?->nama_lengkap,
            ]),
            'rujukan' => $this->whenLoaded('rujukan', fn (): array => $this->rujukan()),
        ];
    }

    /**
     * The referral behind this letter, as a list of zero or one.
     *
     * A LIST rather than a single nested object because the relation is a `HasMany` and
     * a client that has to branch on "one or none" against two different shapes is a
     * client with two code paths. The one-to-zero-or-one cardinality is a consequence
     * of `tipe = 'surat_rujukan'` rather than a schema constraint - `rujukan` has no
     * unique on `surat_keterangan_id` - so a letter of any other type legitimately
     * publishes an empty list.
     *
     * @return list<array<string, mixed>>
     */
    private function rujukan(): array
    {
        /** @var Collection<int, Rujukan> $baris */
        $baris = $this->resource->getRelation('rujukan');

        return $baris
            ->map(fn (Rujukan $row): array => (new RujukanResource($row))->resolve())
            ->values()
            ->all();
    }

    /**
     * A `DATETIME` or `TIMESTAMP` as an ISO-8601 UTC instant, or null.
     *
     * `DateTimeInterface` and NOT `Illuminate\Support\Carbon`: on laravel/framework
     * 13.33 an Eloquent `datetime` cast returns a `Carbon\CarbonImmutable`, a SIBLING
     * of `Carbon\Carbon` and therefore not an `Illuminate\Support\Carbon` either, so an
     * `instanceof Carbon` check would return null and the response would quietly
     * publish no timestamp. `RekamMedisResource` documents the same trap.
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
     * NEVER offset-converted and never an instant - see the class docblock.
     */
    private function tanggal(mixed $nilai): ?string
    {
        if (! $nilai instanceof DateTimeInterface) {
            return null;
        }

        return Carbon::instance($nilai)->toDateString();
    }
}
