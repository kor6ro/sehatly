<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ResepVerifikasiStatus;
use App\Models\ResepVerifikasi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `resep_verifikasi` row, as the next reader of this prescription sees it.
 *
 * `telemedicine_test.sql:786`-`:795`. Six columns, all of them published:
 *
 * | published | column | why |
 * | --- | --- | --- |
 * | `apoteker` | `apoteker_user_id` (`:789`) | WHO signed. A patient disputing a rejection needs a name to dispute it with, and a pharmacy auditing its own queue needs the same. Published as a name only - no `no_telepon`, no `nik`, no `email`. |
 * | `status` | `status` (`:790`) | one of `sesuai`, `ada_koreksi`, `ditolak` |
 * | `catatan` | `catatan` (`:791`) | what the pharmacist said, or null |
 * | `diverifikasi_at` | `diverifikasi_at` (`:792`) | WHEN. There is no `dibuat_at` and no `diubah_at` on this table, so this is the only stamp the row will ever carry. Published as an ISO-8601 instant, like `resep.tanggal_resep`. |
 *
 * ## `terminal` is a DERIVED key, and it is the important one
 *
 * `resep_verifikasi.resep_id` is `UNIQUE` (`:788`), so a row's existence at all
 * means the prescription is closed, and a `ditolak` row means it is closed
 * FOREVER: no corrected prescription, no re-submission, and no `resep` status
 * meaning "returned for correction" among the eight at `:751`-`:752`.
 *
 * Publishing that as an explicit `terminal` boolean rather than making a client
 * infer it from `status` is what stops todo 41's queue from rendering a
 * "resubmit" affordance on a rejection - an affordance the schema cannot
 * honour, and the most likely way this fact turns into a support ticket.
 *
 * Every verification row is terminal in the narrower sense that the ONE
 * verification is spent; `terminal` is true for all three, and the DISTINCTION
 * a client needs is `status`, which says whether the prescription went out.
 *
 * @property-read ResepVerifikasi $resource
 */
class ResepVerifikasiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'resep_id' => $this->resource->resep_id === null ? null : (int) $this->resource->resep_id,
            'apoteker_user_id' => $this->resource->apoteker_user_id === null
                ? null
                : (int) $this->resource->apoteker_user_id,
            'apoteker' => [
                'id' => $this->resource->apoteker_user_id === null
                    ? null
                    : (int) $this->resource->apoteker_user_id,
                'nama_lengkap' => $this->resource->apotekerUser?->nama_lengkap,
            ],
            'status' => (string) $this->resource->status,
            'catatan' => $this->resource->catatan,
            'diverifikasi_at' => $this->resource->diverifikasi_at?->toISOString(),
            'terminal' => true,
            'ditolak' => (string) $this->resource->status === ResepVerifikasiStatus::Ditolak->value,
        ];
    }
}
