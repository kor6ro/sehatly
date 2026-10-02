<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DokterJadwal;
use App\Services\Admin\AdminJadwalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `dokter_jadwal` row, as the admin schedule tab publishes it.
 *
 * ## Every column is published, because this is a write surface's own shape
 *
 * The admin client edits these rows, so it has to read back exactly what it can
 * send: `faskes_id`, `tipe_layanan`, `hari`, both times, `durasi_slot_menit`,
 * `kuota_per_sesi`, the validity window and `status_aktif`. Hiding one would
 * make a round-trip lose data. The only derived keys are `hari_label` (a display
 * label for the DDL's `0=Minggu .. 6=Sabtu` numbering) and `booking_aktif`
 * (the occupancy count the UI uses to choose between "Hapus" and
 * "Nonaktifkan").
 *
 * ## `booking_aktif` is the SAME count the delete guard uses
 *
 * The controller's list action attaches it with one grouped query through
 * {@see AdminJadwalService}; a row that did not go through the list action
 * (a create or an update response) has no such attribute and publishes `0`,
 * which is truthful for a freshly written row and is what the F14 UI shows until
 * it refetches the list.
 *
 * ## Times are published as STORED, `H:i:s`
 *
 * `dokter_jadwal.jam_mulai`/`jam_selesai` are `TIME` columns, and
 * `docs/timezone-policy.md` says a `TIME` is a wall clock that is never shifted.
 * Trimming the seconds here would be a second spelling of the same value
 * (`08:00` vs `08:00:00`); the client renders `08.00 WIB` either way, and the
 * admin FormRequest accepts `H:i` on the way in. One spelling on the wire, one
 * in the column.
 *
 * **The service therefore re-reads every row it writes** (`$row->refresh()` on
 * create and on update), because the request's `H:i`/`Y-m-d` strings and the
 * column's `H:i:s`/`Y-m-d` spellings differ. A create that answered from the
 * in-memory model would publish `08:00` while an update of the same row published
 * `08:00:00`, and a client diffing the two would be reading a change that is not
 * one.
 *
 * @property-read DokterJadwal $resource
 */
class AdminJadwalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $jadwal = $this->resource;

        return [
            'id' => $jadwal->getKey(),
            'dokter_id' => $jadwal->dokter_id,
            'faskes_id' => $jadwal->faskes_id,
            'tipe_layanan' => $jadwal->tipe_layanan,
            'hari' => (int) $jadwal->hari,
            'hari_label' => AdminJadwalService::HARI_LABEL[(int) $jadwal->hari] ?? null,
            'jam_mulai' => (string) $jadwal->jam_mulai,
            'jam_selesai' => (string) $jadwal->jam_selesai,
            'durasi_slot_menit' => (int) $jadwal->durasi_slot_menit,
            // The DDL's own NULL, never a substituted 1: NULL means "no quota
            // set" and 0 means "no slots", and the admin edits the distinction.
            'kuota_per_sesi' => $jadwal->kuota_per_sesi === null ? null : (int) $jadwal->kuota_per_sesi,
            'berlaku_mulai' => $jadwal->berlaku_mulai?->format('Y-m-d'),
            'berlaku_sampai' => $jadwal->berlaku_sampai?->format('Y-m-d'),
            'status_aktif' => (bool) $jadwal->status_aktif,
            // Attached by the list action; `0` for a row that did not pass
            // through it, which is honest for a just-created row.
            'booking_aktif' => (int) ($jadwal->getAttribute('booking_aktif') ?? 0),
            'dibuat_at' => $jadwal->dibuat_at?->toISOString(),
            'diubah_at' => $jadwal->diubah_at?->toISOString(),
        ];
    }
}
