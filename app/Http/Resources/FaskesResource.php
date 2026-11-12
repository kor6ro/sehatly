<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of `GET /api/v1/faskes` - the venue shape the landing page's pharmacy
 * panel renders.
 *
 * ## What it publishes, and the one column it does not
 *
 * | field | source |
 * | --- | --- |
 * | `id`, `nama`, `tipe`, `alamat`, `telepon` | the `faskes` row, verbatim |
 * | `jam_operasional` | the row's JSON column, `null` when no schedule was entered |
 * | `latitude`, `longitude` | the row's own pin as floats, `null` when the venue was entered without one - published so a client can draw the map link itself rather than asking the server to compose a URL |
 * | `jarak_km` | the `jarak_km` SELECT alias {@see FaskesDirectoryService::ukur()} adds, **`null` whenever the request carried no position** |
 *
 * `kode_faskes`, `satusehat_org_id`, `akreditasi` and the four `*_id` region columns
 * are deliberately withheld: they are identifiers and internal taxonomy, none of them
 * is something a patient reads on a card, and `satusehat_org_id` in particular is an
 * integration key this endpoint has no reason to publish. The region columns are
 * withheld for a second reason - the panel shows the raw `alamat`, and a `provinsi_id`
 * without its name is a number no client can render.
 *
 * ## `jarak_km` is rounded to whole metres, and only here
 *
 * The Haversine over `DECIMAL(10,8)` inputs produces a double with far more digits
 * than a satellite gives, and the fifteenth of a metre is not information. The
 * rounding happens in this class and never in the service's `ORDER BY`, so two venues
 * a centimetre apart still order correctly.
 */
class FaskesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'nama' => $this->resource->nama,
            'tipe' => $this->resource->tipe,
            'alamat' => $this->resource->alamat,
            'telepon' => $this->resource->telepon,
            'jam_operasional' => $this->resource->jam_operasional,
            'latitude' => $this->resource->latitude === null ? null : (float) $this->resource->latitude,
            'longitude' => $this->resource->longitude === null ? null : (float) $this->resource->longitude,
            'jarak_km' => $this->jarak(),
        ];
    }

    /**
     * `jarak_km` as a number rounded to whole metres, or `null`.
     *
     * `getAttribute()` rather than a property read, because the alias is a SELECT
     * column: on a list that carried no position the attribute is absent entirely,
     * and the explicit call records that `null` means "not measured" rather than
     * "not requested".
     */
    private function jarak(): ?float
    {
        $jarak = $this->resource->getAttribute('jarak_km');

        return $jarak === null ? null : round((float) $jarak, 3);
    }
}
