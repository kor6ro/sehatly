<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PesananObat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `pesanan_obat` row.
 *
 * ## The three money columns are STRINGS, and that is not decoration
 *
 * `subtotal`, `biaya_kirim` and `total` are `DECIMAL(12,2) NOT NULL DEFAULT 0`
 * (`telemedicine_test.sql:807`-`:809`) and every amount on the wire in this
 * application is a JSON string - `"150000.00"`, not `150000.0`. A JSON number
 * has already lost precision by the time PHP's parser hands it over, so the
 * resource publishes the model's own `decimal:2` cast unchanged rather than
 * re-formatting through a float.
 *
 * Note the shape of the money: the order's `total` is goods plus shipping and
 * NOTHING else, because `pesanan_obat` has no admin-fee column and no discount
 * column. `invoice` has five money columns and is where a promo or a payment
 * method changes the figure, so a client renders the invoice. The two are equal
 * when no promo and no method is chosen.
 *
 * ## `tipe` is published, and only one value is ever stored by this application
 *
 * `tipe` is a three-value ENUM (`:803`) and two of the three are unrepresentable
 * for an order this API creates. Publishing the column rather than hiding it
 * means a client that receives a row from any other writer can still see what it
 * is, and a client reading a row from this API knows the value is `resep_dokter`
 * because nothing else can be written.
 *
 * @property-read PesananObat $resource
 */
class PesananObatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'nomor_pesanan' => (string) $this->resource->nomor_pesanan,
            'resep_id' => $this->resource->resep_id === null ? null : (int) $this->resource->resep_id,
            'pasien_id' => (int) $this->resource->pasien_id,
            'apotek_id' => (int) $this->resource->apotek_id,
            'tipe' => (string) $this->resource->tipe,
            'alamat_kirim' => (string) $this->resource->alamat_kirim,
            'kurir' => $this->resource->kurir === null ? null : (string) $this->resource->kurir,
            'no_resi' => $this->resource->no_resi === null ? null : (string) $this->resource->no_resi,
            'subtotal' => (string) $this->resource->subtotal,
            'biaya_kirim' => (string) $this->resource->biaya_kirim,
            'total' => (string) $this->resource->total,
            'status' => (string) $this->resource->status,
            'dibuat_at' => $this->resource->dibuat_at?->toISOString(),
            'diubah_at' => $this->resource->diubah_at?->toISOString(),
            // Present only when the relation was eager-loaded. The order detail
            // loads it; a create response does not, because the trail is one row
            // at that point and it is the initial row the service already knows.
            //
            // NOT through `additional()`: in laravel/framework 13 the merge that
            // used to happen in `ConditionallyLoadsAttributes::filter()` is gone,
            // so `additional()` on a nested resource is a SILENT no-op. See
            // `ResepResource`'s docblock, which found that the hard way.
            'tracking' => PesananObatTrackingResource::collection($this->whenLoaded('pesananObatTracking')),
        ];
    }
}
