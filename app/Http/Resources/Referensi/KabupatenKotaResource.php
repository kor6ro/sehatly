<?php

declare(strict_types=1);

namespace App\Http\Resources\Referensi;

use App\Models\MasterKabupatenKota;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `master_kabupaten_kota` row, for `GET /api/v1/referencia/kabupaten-kota`.
 *
 * ## The whole table, all four columns
 *
 * `master_kabupaten_kota` is `(id, provinsi_id, kode, nama)` and nothing else
 * (`telemedicine_test.sql`). `MasterKabupatenKota::$timestamps` is `false` because the
 * table declares no `dibuat_at` and no `diubah_at`, so there is no stamp to serialise
 * and none is invented.
 *
 * ## `provinsi_id` is published, and that is the point of the endpoint
 *
 * The three administrative levels filter on each other, so a client walking
 * province -> kabupaten/kota -> kecamatan has to carry the parent id. Emitting it
 * is what lets a client request `/kecamatan?kabupaten_kota_id=7` without a second
 * round trip to resolve which `kode` that id came from. It is a foreign key to a
 * public geography table, not an internal surrogate, so publishing it discloses
 * nothing.
 *
 * @property-read MasterKabupatenKota $resource
 */
class KabupatenKotaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'provinsi_id' => $this->resource->provinsi_id,
            'kode' => $this->resource->kode,
            'nama' => $this->resource->nama,
        ];
    }
}
