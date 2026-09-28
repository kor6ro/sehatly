<?php

declare(strict_types=1);

namespace App\Http\Resources\Referensi;

use App\Models\MasterProvinsi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `master_provinsi` row, for `GET /api/v1/referencia/provinsi`.
 *
 * ## The whole table, all three columns
 *
 * `master_provinsi` is `(id, kode, nama)` and nothing else (`telemedicine_test.sql:58`).
 * `MasterProvinsi::$timestamps` is `false` because the table declares no `dibuat_at` and
 * no `terkirim_at`, so there is no stamp to serialise and none is invented.
 *
 * ## This is the FIRST reference call an unauthenticated client makes
 *
 * A patient has to choose a province before they have an account, which is why this
 * endpoint is public and ungated. It is 38 rows - the whole table, not a page - so the
 * response is a single page with the degenerate `current_page = 1, last_page = 1` block
 * a client parses identically for every reference list.
 *
 * @property-read MasterProvinsi $resource
 */
class ProvinsiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'kode' => $this->resource->kode,
            'nama' => $this->resource->nama,
        ];
    }
}
