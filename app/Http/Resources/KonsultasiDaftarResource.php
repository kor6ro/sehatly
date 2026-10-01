<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Konsultasi;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One row of the doctor's own consultation list (`GET /api/v1/konsultasi`).
 *
 * ## Why this is NOT `KonsultasiResource`
 *
 * `KonsultasiResource` is the DETAIL shape. It publishes, by design:
 *
 * - `pasien.nik` through `NikMasker` (a masked identifier, but an identifier),
 * - `room_id` - the room credential a video SDK joins with,
 * - `biaya_konsultasi` - a billing amount,
 * - the six SOAP / diagnosis columns - clinical content for the consultation
 *   screen (`GET /konsultasi/{id}`), and
 * - the `baca` block - two account ids and their read markers.
 *
 * A dashboard list needs none of that. Its job is to answer "which
 * consultations are mine, what state are they in, and which do I pick up" -
 * so this resource is the allow-list of exactly that question and nothing
 * else. Every omission above is deliberate and the test asserts the key set by
 * value, so a future widening is a visible change rather than a silent one.
 *
 * No NIK - masked or otherwise - no contact detail, no fee, no room
 * credential, no SOAP note and no read marker is reachable from this class.
 * `GET /konsultasi/{id}` remains the one place those are read, under
 * `KonsultasiAccess::findForRead()`.
 *
 * ## The `booking` block, and why it can be `null`
 *
 * A consultation started from a booking carries the visit time F13 renders on
 * the queue row; an instant "Tanya Dokter" session has `booking_id = NULL`
 * (`telemedicine_test.sql:538`, `NULL UNIQUE`) and is represented by `null`,
 * never by an omitted key - a client distinguishes "no booking" from "the
 * field was not loaded". `pasien` is likewise `null` when its row could not be
 * resolved (a soft-deleted profile), for the same reason.
 *
 * ## Instants are UTC with a `Z` suffix
 *
 * `mulai_at` and `selesai_at` are `DATETIME` (`:545`-`:546`), and
 * `config('app.timezone')` is UTC, so `toISOString()` is right for both. The
 * narrowing is `DateTimeInterface` rather than `Carbon`, for the reason
 * `KonsultasiResource::mulai()` records: Eloquent's `datetime` cast may hand
 * back a `CarbonImmutable`, which is NOT an `Illuminate\Support\Carbon`, and
 * an `instanceof Carbon` check would publish `null` for a stored instant.
 *
 * @property-read Konsultasi $resource
 */
class KonsultasiDaftarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'tipe' => (string) $this->resource->tipe,
            'status' => (string) $this->resource->status,
            'mulai_at' => $this->instan('mulai_at'),
            'selesai_at' => $this->instan('selesai_at'),
            'pasien' => $this->whenLoaded('pasien', fn (): ?array => $this->resource->pasien === null ? null : [
                'id' => (int) $this->resource->pasien->getKey(),
                'nama_lengkap' => $this->resource->pasien->user?->nama_lengkap,
            ]),
            'booking' => $this->whenLoaded('booking', fn (): ?array => $this->resource->booking === null ? null : [
                'id' => (int) $this->resource->booking->getKey(),
                'nomor_booking' => (string) $this->resource->booking->nomor_booking,
                'tipe_layanan' => (string) $this->resource->booking->tipe_layanan,
                'tanggal_kunjungan' => $this->resource->booking->tanggal_kunjungan?->toDateString(),
                'slot_mulai' => (string) $this->resource->booking->slot_mulai,
                'slot_selesai' => (string) $this->resource->booking->slot_selesai,
                'status' => (string) $this->resource->booking->status,
            ]),
        ];
    }

    /**
     * One datetime column as an ISO-8601 UTC instant, or `null`.
     *
     * @see KonsultasiResource::mulai() for the measured reason the narrowing is
     *      `DateTimeInterface` and not `Carbon`.
     */
    private function instan(string $kolom): ?string
    {
        $nilai = $this->resource->{$kolom};

        if (! $nilai instanceof DateTimeInterface) {
            return null;
        }

        return Carbon::instance($nilai)->toISOString();
    }
}
