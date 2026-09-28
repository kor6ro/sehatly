<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Resep;
use App\Services\Resep\ResepStateMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `resep` row with its stored items, as any of the three readers sees it.
 *
 * `tanggal_resep` is a `DATETIME` (`:754`) published as an ISO-8601 instant;
 * `berlaku_sampai` is a `DATE` (`:755`) published as a wall-clock day,
 * because turning a validity day into an instant moves it to the previous
 * evening in any client that renders local time.
 *
 * No patient identifier beyond the surrogate `pasien_id`: no NIK, no allergy
 * note, nothing the pharmacist reading this prescription needs a national
 * identifier for.
 *
 * ## `is_kedaluwarsa` and `terminal` are on EVERY surface
 *
 * Both are published here rather than added by the detail and history
 * controllers, and the reason is laravel/framework 13 itself:
 * `JsonResource::additional()` writes to a property that
 * `ConditionallyLoadsAttributes::filter()` no longer merges - in 13 the merge
 * that used to happen in `filter()` is gone, so `additional()` on a NESTED
 * resource is a silent no-op. An earlier draft of this todo used it and every
 * `is_kedaluwarsa` assertion read `null` from a 200 response, which is the
 * worst available failure: a client that believes a lapsed prescription is
 * live.
 *
 * So the flags are computed on the model by
 * {@see ResepStateMachine::kedaluwarsa()} and {@see ResepStateMachine::terminal()}
 * - the same two rules `ResepAccess` publishes beside them - and the create
 * response, the detail, the verification response and the history therefore all
 * carry an identical shape. A client renders one component and a "can I still
 * act on this" affordance cannot depend on which endpoint answered.
 *
 * @property-read Resep $resource
 */
class ResepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'nomor_resep' => (string) $this->resource->nomor_resep,
            'konsultasi_id' => $this->resource->konsultasi_id === null ? null : (int) $this->resource->konsultasi_id,
            'rekam_medis_id' => $this->resource->rekam_medis_id === null ? null : (int) $this->resource->rekam_medis_id,
            'pasien_id' => (int) $this->resource->pasien_id,
            'dokter_id' => (int) $this->resource->dokter_id,
            'apotek_id' => $this->resource->apotek_id === null ? null : (int) $this->resource->apotek_id,
            'tipe' => (string) $this->resource->tipe,
            'status' => (string) $this->resource->status,
            'catatan_dokter' => $this->resource->catatan_dokter,
            'tanggal_resep' => $this->resource->tanggal_resep?->toISOString(),
            'berlaku_sampai' => $this->resource->berlaku_sampai?->toDateString(),
            'is_kedaluwarsa' => ResepStateMachine::kedaluwarsa($this->resource),
            'terminal' => ResepStateMachine::terminal($this->resource),
            'is_iter' => (bool) $this->resource->is_iter,
            'jumlah_iter' => (int) $this->resource->jumlah_iter,
            'qr_token' => (string) $this->resource->qr_token,
            'dibuat_at' => $this->resource->dibuat_at?->toISOString(),
            'items' => ResepItemResource::collection($this->whenLoaded('resepItem')),
        ];
    }
}
