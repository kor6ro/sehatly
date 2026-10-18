<?php

declare(strict_types=1);

namespace App\Http\Resources\Kurir;

use App\Http\Resources\PesananObatResource;
use App\Http\Resources\PesananObatTrackingResource;
use App\Models\PesananObat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One order as the COURIER workflow publishes it - deliberately not
 * {@see PesananObatResource}.
 *
 * ## The absence of a field is the module's privacy boundary
 *
 * `PesananObatResource` (F46) carries `pasien_id`, `resep_id` and the three
 * money columns, and its read REFUSES the courier for exactly that reason
 * (`pesanan.lihat` is not in the courier's grant list - see `RbacCatalog`'s
 * kurir block and plan section 5.2's table). This resource is the narrower
 * surface that replaces it for delivery, so the fields that motivated the
 * refusal are the fields it must NOT carry: no patient id, no prescription id
 * (and with it no path to the drug list), no subtotal, no shipping fee, no
 * total. A delivery screen that rendered an invoice would undo the F46 refusal
 * through a side door.
 *
 * What it carries instead is what physically delivering a parcel requires: the
 * parcel's identity, the pharmacy it sits at ({@see apotek()} - the pickup
 * point, eager-loaded), the free-text destination snapshot the patient wrote at
 * checkout (`alamat_kirim`; see `PesananObatService::alamat()` for why it is a
 * snapshot and never re-read), the carrier name and the tracking number.
 *
 * The three money columns are STRINGS for the reason `PesananObatResource`
 * gives - DECIMAL on the wire is a JSON string in this application - though the
 * courier payload publishes none of them today; the note is kept so a future
 * addition does not reach for a float.
 *
 * `tracking` is present only when the trail was eager-loaded: the detail loads
 * it; the queue and the two write responses do not (each write's own response
 * carries the row the service just appended through the refreshed model state).
 *
 * @property-read PesananObat $resource
 */
class KurirPesananResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'nomor_pesanan' => (string) $this->resource->nomor_pesanan,
            'status' => (string) $this->resource->status,
            'tipe' => (string) $this->resource->tipe,
            'alamat_kirim' => (string) $this->resource->alamat_kirim,
            'kurir' => $this->resource->kurir === null ? null : (string) $this->resource->kurir,
            'no_resi' => $this->resource->no_resi === null ? null : (string) $this->resource->no_resi,
            'apotek' => $this->apotek(),
            'dibuat_at' => $this->resource->dibuat_at?->toISOString(),
            'diubah_at' => $this->resource->diubah_at?->toISOString(),
            'tracking' => PesananObatTrackingResource::collection($this->whenLoaded('pesananObatTracking')),
        ];
    }

    /**
     * The pickup point, reduced to what a courier navigates to.
     *
     * `Faskes` carries latitude/longitude (`:372`-`:373`) and the DDL constrains
     * NEITHER `pesanan_obat.apotek_id` NOR this payload to `tipe = 'apotek'`
     * (`:365`), so the columns are passed through as stored rather than
     * asserted: a hospital fulfilling an order is representable and the courier
     * sees the name and address of whatever row the order points at.
     *
     * @return array<string, mixed>|null
     */
    private function apotek(): ?array
    {
        // `relationLoaded()` rather than `whenLoaded()`: the latter answers a
        // `MissingValue` sentinel when the relation is absent, which is neither
        // `null` nor an array and would leak through this guard as an object.
        if (! $this->resource->relationLoaded('apotek') || $this->resource->apotek === null) {
            return null;
        }

        $apotek = $this->resource->apotek;

        return [
            'id' => (int) $apotek->getKey(),
            'nama' => (string) $apotek->nama,
            'alamat' => (string) $apotek->alamat,
        ];
    }
}
