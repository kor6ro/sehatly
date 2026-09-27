<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Booking;
use App\Support\NikMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `booking` row.
 *
 * Allow-list, never `$this->resource->toArray()`: the allow-list is the
 * security control. This is the one booking endpoint family that eager-loads
 * `pasien` for somebody who is not the patient, so the response carries
 * `NikMasker::mask($nik)` and never the bare identifier, the family card
 * number, or anything that authenticates the account (`kata_sandi_hash`).
 *
 * @property-read Booking $resource
 */
class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'nomor_booking' => $this->resource->nomor_booking,
            'pasien_id' => $this->resource->pasien_id,
            'anggota_keluarga_id' => $this->resource->anggota_keluarga_id,
            'dokter_id' => $this->resource->dokter_id,
            'jadwal_id' => $this->resource->jadwal_id,
            'faskes_id' => $this->resource->faskes_id,
            'tipe_layanan' => $this->resource->tipe_layanan,
            'tanggal_kunjungan' => $this->resource->tanggal_kunjungan?->toDateString(),
            'slot_mulai' => $this->resource->slot_mulai,
            'slot_selesai' => $this->resource->slot_selesai,
            'nomor_antrian' => $this->resource->nomor_antrian,
            'keluhan' => $this->resource->keluhan,
            // MySQL sorts JSON object keys by length, so a stored
            // `{nama, url}` element reads back as `{url, nama}`. The `{nama,
            // url}` contract is the API's, so each element is re-emitted in
            // that order rather than passed through in storage order.
            'lampiran_keluhan' => $this->resource->lampiran_keluhan === null ? null : array_values(array_map(
                static fn (array $satu): array => ['nama' => $satu['nama'] ?? null, 'url' => $satu['url'] ?? null],
                $this->resource->lampiran_keluhan,
            )),
            'is_rujukan' => (bool) $this->resource->is_rujukan,
            'is_konsultasi_lanjutan' => (bool) $this->resource->is_konsultasi_lanjutan,
            'status' => $this->resource->status,
            'dibatalkan_oleh' => $this->resource->dibatalkan_oleh,
            'alasan_pembatalan' => $this->resource->alasan_pembatalan,
            'dibuat_oleh_user_id' => $this->resource->dibuat_oleh_user_id,
            'dibuat_at' => $this->resource->dibuat_at?->toISOString(),
            'pasien' => $this->whenLoaded('pasien', fn (): array => [
                'id' => $this->resource->pasien->getKey(),
                'nik' => NikMasker::mask($this->resource->pasien->nik),
                'nama_lengkap' => $this->resource->pasien->user?->nama_lengkap,
            ]),
        ];
    }
}
