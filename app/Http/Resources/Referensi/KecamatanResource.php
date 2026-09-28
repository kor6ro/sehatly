<?php

declare(strict_types=1);

namespace App\Http\Resources\Referensi;

use App\Models\MasterKecamatan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `master_kecamatan` row, for `GET /api/v1/referencia/kecamatan`.
 *
 * ## The whole table, all four columns
 *
 * `master_kecamatan` is `(id, kabupaten_kota_id, kode, nama)` and nothing else. The
 * table declares no timestamp, so `MasterKecamatan::$timestamps` is `false` and no
 * stamp is serialised or invented.
 *
 * ## `kabupaten_kota_id` is published so the hierarchy can be walked
 *
 * A client moves province -> kabupaten/kota -> kecamatan -> kelurahan one level at a
 * time, and each request filters on the id of the level above. Emitting the parent key
 * is what removes the second round trip in which a client would otherwise have to
 * resolve an id back from a code. It is a foreign key into a public geography table,
 * not an internal surrogate, so it discloses nothing.
 *
 * @property-read MasterKecamatan $resource
 */
class KecamatanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'kabupaten_kota_id' => $this->resource->kabupaten_kota_id,
            'kode' => $this->resource->kode,
            'nama' => $this->resource->nama,
        ];
    }
}
