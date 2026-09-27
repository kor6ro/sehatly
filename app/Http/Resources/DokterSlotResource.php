<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Booking\SlotAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of `GET /api/v1/dokter/{dokter}/slot` -- one candidate slot, already
 * decided by {@see SlotAvailabilityService::getSlotTerbuka()}.
 *
 * ## This resource decides NOTHING
 *
 * Every field below is a pass-through of a key the service already emitted. The
 * availability rules -- the window subtraction from `dokter_jadwal`, the
 * whole-day `dokter_libur` closure, the quota-aware overlap count, the STR
 * boundary against the consultation date -- all live in the service, and a
 * resource that re-derived any of them would be a second source of truth that
 * could disagree with the one the booking path uses.
 * `DokterJadwalSlotEndpointTest` pins this by asserting the decoded `slots` array
 * equals `getSlotTerbuka()`'s return value element for element.
 *
 * The allow-list is still worth writing down: the service is a plain array
 * builder, so a key added there would otherwise reach the wire silently, and the
 * shape is the contract the plan and both clients transcribe.
 *
 * ## The seven keys, and where each comes from
 *
 * | key | source | DDL |
 * | --- | --- | --- |
 * | `jadwal_id` | the `dokter_jadwal` row the candidate came from | `dokter_jadwal.id` `:471`; `booking.jadwal_id` `:504` is the nullable column it is written to |
 * | `jam_mulai` | candidate start, `H:i:s` wall clock | `dokter_jadwal.jam_mulai` `:476` |
 * | `jam_selesai` | candidate end, `H:i:s` wall clock | `dokter_jadwal.jam_selesai` `:477` |
 * | `tipe_layanan` | the row's own service type | `dokter_jadwal.tipe_layanan` `:474`, a THREE-value ENUM |
 * | `faskes_id` | the row's venue, or `null` for online-only | `dokter_jadwal.faskes_id` `:473`, whose comment reads `NULL = layanan online murni` |
 * | `tersedia` | derived from `alasan`, never decided separately | the service's own `tersedia = $alasan === null` |
 * | `alasan` | `libur` \| `lewat_waktu` \| `penuh` \| `null` | service constants, not DDL |
 *
 * **`tipe_layanan` here is NOT the four-value booking enum.** `dokter_jadwal`
 * carries `ENUM('online','klinik','home_visit')` at `:474` while `booking` carries
 * `ENUM('chat','video_call','kunjungan_klinik','home_visit')` at `:506`. Two
 * same-shaped names for different things is exactly the class of confusion the
 * token audit exists to catch, and the web client hit it and retyped the field as
 * a distinct `TipeLayananJadwal` union.
 *
 * ## `alasan` is `null` exactly when `tersedia` is `true`
 *
 * The service derives one from the other, and this resource publishes both
 * verbatim, so a client never has to infer availability from a reason string.
 * The precedence is `libur` > `lewat_waktu` > `penuh` and is the service's
 * decision, not this file's.
 *
 * ## No timestamps
 *
 * `dibuat_at`/`diubah_at` are `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` on
 * `dokter_jadwal` (`:483`-`:484`) and on `booking` (`:520`-`:521`). Neither is
 * published: a slot is a computed window, not a row, and the booking rows it was
 * computed against are deliberately absent -- publishing them would leak other
 * patients' appointments.
 *
 * @property-read array{
 *     jadwal_id: int,
 *     jam_mulai: string,
 *     jam_selesai: string,
 *     tipe_layanan: string,
 *     faskes_id: int|null,
 *     tersedia: bool,
 *     alasan: string|null
 * } $resource
 */
class DokterSlotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'jadwal_id' => $this->resource['jadwal_id'],
            'jam_mulai' => $this->resource['jam_mulai'],
            'jam_selesai' => $this->resource['jam_selesai'],
            'tipe_layanan' => $this->resource['tipe_layanan'],
            'faskes_id' => $this->resource['faskes_id'],
            'tersedia' => $this->resource['tersedia'],
            'alasan' => $this->resource['alasan'],
        ];
    }
}
