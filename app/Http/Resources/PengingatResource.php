<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ZonaWaktu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `pengingat` row, as the caller's own.
 *
 * `user_id` is never published - the list is already scoped to the caller, and
 * an id the client cannot use is one more place a cross-account reference could
 * leak into a log or a crash report.
 *
 * `tanggal_mulai` is a calendar DATE and is published as `Y-m-d`, never as a
 * datetime: `docs/timezone-policy.md` rule 2, and a `datetime` cast would shift
 * it a day for any zone east of the stored reading. `dibuat_at`/`diubah_at` are
 * instants and are published as ISO-8601 UTC, rule 1.
 *
 * `zona_label` is the human `WIB`/`WITA`/`WIT` the UI prints beside a reminder
 * time; it is derived from the stored IANA name so a client never has to carry
 * its own mapping.
 */
class PengingatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $zona = ZonaWaktu::tryFrom((string) $this->zona_waktu);

        return [
            'id' => $this->id === null ? null : (int) $this->id,
            'jenis' => $this->jenis,
            'judul' => $this->judul,
            'keterangan' => $this->keterangan,
            'obat_id' => $this->obat_id === null ? null : (int) $this->obat_id,
            'booking_id' => $this->booking_id === null ? null : (int) $this->booking_id,
            'dosis' => $this->dosis,
            'jumlah_per_hari' => $this->jumlah_per_hari === null ? null : (int) $this->jumlah_per_hari,
            'tanggal_mulai' => $this->tanggal_mulai?->format('Y-m-d'),
            'lama_hari' => $this->lama_hari === null ? null : (int) $this->lama_hari,
            'waktu' => array_values($this->waktu ?? []),
            'zona_waktu' => $this->zona_waktu,
            'zona_label' => $zona?->label(),
            'status' => $this->status,
            'dibuat_at' => $this->dibuat_at?->toISOString(),
            'diubah_at' => $this->diubah_at?->toISOString(),
        ];
    }
}
