<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\NikCipher;
use App\Support\NikMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * One `konsultasi` row, as `GET /api/v1/konsultasi/{id}` and every lifecycle write publish it.
 *
 * ## Allow-list, never `toArray()`
 *
 * The allow-list is the security control, exactly as `BookingResource` states. A
 * consultation response names a patient, a doctor and - through `pasien` - the
 * patient identity columns, so `pasien.nik` is masked through `NikMasker::mask()`
 * and never published raw, and `pasien.user.nama_lengkap` is read from the `users`
 * row while nothing else on the `users` row is read at all.
 *
 * The three nested blocks are all `whenLoaded()`, so this resource is safe on a
 * bare row - the broadcast payload is built from one - and publishes them only when
 * `KonsultasiAccess::muatan()` eager-loaded them.
 *
 * ## `total_durasi_detik` is the STORED column, and that is deliberate
 *
 * The plan asks for "a `total_durasi_detik` computed value" here. The computation
 * is real and happens once, in `KonsultasiService::selesai()`, which derives the number
 * from `mulai_at` to `selesai_at` and stores it in `total_durasi_detik`
 * (`INT UNSIGNED`, `:547`). Publishing the stored value rather than a second live
 * `now() - mulai_at` is what makes the REST answer and the database agree, and it
 * means a client that re-reads the row after a reconnect sees the number it saw on
 * the socket.
 *
 * A live duration was considered and rejected: two reads of the same consultation
 * would disagree, and it would need a companion "duration so far" field to be
 * meaningful beside the total. The stored column is `null` until the doctor
 * completes the session, which is the truthful answer before there is a duration.
 *
 * ## Instants are UTC with a `Z` suffix
 *
 * `mulai_at` and `selesai_at` are `DATETIME` (`:545`-`:546`) and `dibuat_at` /
 * `diubah_at` are `TIMESTAMP` (`:555`-`:556`). All four are rule-(1) instants in
 * the plan's todo 51 policy and `config('app.timezone')` is UTC, so `toISOString()`
 * is exactly right for all of them. `terkirim_at` on the chat resource is the same
 * argument.
 *
 * @property-read \App\Models\Konsultasi $resource
 */
class KonsultasiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'booking_id' => $this->resource->booking_id,
            'pasien_id' => $this->resource->pasien_id,
            'dokter_id' => $this->resource->dokter_id,
            'tipe' => $this->resource->tipe,
            'status' => $this->resource->status,
            'room_id' => $this->resource->room_id,
            'mulai_at' => $this->mulai(),
            'selesai_at' => $this->resource->selesai_at?->toISOString(),
            'total_durasi_detik' => $this->resource->total_durasi_detik === null
                ? null
                : (int) $this->resource->total_durasi_detik,
            'catatan_subjektif' => $this->resource->catatan_subjektif,
            'catatan_objektif' => $this->resource->catatan_objektif,
            'catatan_asessment' => $this->resource->catatan_asessment,
            'catatan_plan' => $this->resource->catatan_plan,
            'diagnosis_kerja' => $this->resource->diagnosis_kerja,
            'saran_tindak_lanjut' => $this->resource->saran_tindak_lanjut,
            'biaya_konsultasi' => $this->resource->biaya_konsultasi,
            'dibuat_at' => $this->resource->dibuat_at?->toISOString(),
            'diubah_at' => $this->resource->diubah_at?->toISOString(),
            'pasien' => $this->whenLoaded('pasien', fn (): array => [
                'id' => $this->resource->pasien->getKey(),
                'nik' => NikCipher::mask($this->resource->pasien->nik_cipher),
                'nama_lengkap' => $this->resource->pasien->user?->nama_lengkap,
            ]),
            'dokter' => $this->whenLoaded('dokter', fn (): array => [
                'id' => $this->resource->dokter->getKey(),
                'nama_lengkap' => $this->resource->dokter->user?->nama_lengkap,
            ]),
            'booking' => $this->whenLoaded('booking', fn (): ?array => $this->resource->booking === null ? null : [
                'id' => $this->resource->booking->getKey(),
                'nomor_booking' => $this->resource->booking->nomor_booking,
                'tipe_layanan' => $this->resource->booking->tipe_layanan,
                'tanggal_kunjungan' => $this->resource->booking->tanggal_kunjungan?->toDateString(),
                'slot_mulai' => $this->resource->booking->slot_mulai,
                'slot_selesai' => $this->resource->booking->slot_selesai,
                'status' => $this->resource->booking->status,
            ]),
        ];
    }

    /**
     * `mulai_at` as an ISO-8601 UTC instant, or `null`.
     *
     * ## The narrowing is `DateTimeInterface`, and NOT `Carbon`, and that is measured
     *
     * `Illuminate\Support\Carbon` extends `Carbon\Carbon`, so a naive
     * `$value instanceof Carbon` looks like the obvious type check. It is wrong:
     * Eloquent's `datetime` cast goes through the `Date` factory, and on
     * laravel/framework 13.33 the value that comes back for a standard
     * `Y-m-d H:i:s` string is a `Carbon\CarbonImmutable` - a SIBLING of
     * `Carbon\Carbon`, not a subclass of it, and therefore not an
     * `Illuminate\Support\Carbon` either.
     *
     * The failure is silent and expensive: the check returned `null`, the key
     * published as `null`, and a completed consultation reported no start time over
     * HTTP while the column held one. It was found by asserting the JSON path
     * against a value the database demonstrably had, not by reading the code.
     *
     * `DateTimeInterface` is what `Carbon`, `CarbonImmutable` and `DateTime` all
     * satisfy, and `Carbon::instance()` re-wraps any of them as the mutable
     * `Illuminate\Support\Carbon` this resource then formats.
     */
    private function mulai(): ?string
    {
        $mulai = $this->resource->mulai_at;

        if (! $mulai instanceof DateTimeInterface) {
            return null;
        }

        return Carbon::instance($mulai)->toISOString();
    }
}
