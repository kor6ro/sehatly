<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\KonsultasiChat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `konsultasi` row.
 *
 * ## This is the ONE definition of "a chat message", and that is load-bearing
 *
 * `KonsultasiMessageSent` deliberately takes an ALREADY-SERIALISED array and forwards it in
 * `broadcastWith()` rather than holding the model and re-deriving a shape, so the
 * socket payload and the REST payload cannot drift. Both are produced here:
 * `GET /api/v1/konsultasi/{id}/chat` publishes `KonsultasiChatResource::collection(...)`, and
 * `KonsultasiController::chatStore()` passes
 * `(new KonsultasiChatResource($pesan))->resolve($request)` into the event. One allow-list,
 * two transports.
 *
 * ## Allow-list, and the field that is deliberately NOT here
 *
 * `pengirim_nama` is not published. The transcript is read by two clients and the
 * row is written on the hot path of a chat send, so resolving the sender's name
 * would mean an eager load of `pengirimUser` on the list endpoint - and
 * `pengirim_tipe` already answers the only question a client asks of it, which is
 * which of the two parties, or the system, this line came from.
 *
 * The attachment columns are descriptors only: `file_url` is a public-disk URL the
 * client could already derive, `file_nama` is the sanitised basename the service
 * truncated to the column's 255 characters, and no file body is ever inlined.
 *
 * @property-read KonsultasiChat $resource
 */
class KonsultasiChatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'konsultasi_id' => $this->resource->konsultasi_id,
            'pengirim_user_id' => $this->resource->pengirim_user_id,
            'pengirim_tipe' => $this->resource->pengirim_tipe,
            'tipe_pesan' => $this->resource->tipe_pesan,
            'isi' => $this->resource->isi,
            'file_url' => $this->resource->file_url,
            'file_nama' => $this->resource->file_nama,
            'file_ukuran_kb' => $this->resource->file_ukuran_kb === null
                ? null
                : (int) $this->resource->file_ukuran_kb,
            'dibaca_at' => $this->resource->dibaca_at?->toISOString(),
            'terkirim_at' => $this->resource->terkirim_at?->toISOString(),
        ];
    }
}
