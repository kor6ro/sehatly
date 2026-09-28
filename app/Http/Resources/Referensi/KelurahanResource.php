<?php

declare(strict_types=1);

namespace App\Http\Resources\Referensi;

use App\Models\MasterKelurahan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `master_kelurahan` row, for `GET /api/v1/referencia/kelurahan`.
 *
 * ## The whole table, all four columns
 *
 * `master_kelurahan` is `(id, kecamatan_id, kode, nama)` and nothing else. The table
 * declares no timestamp, so `MasterKelurahan::$timestamps` is `false`.
 *
 * ## This is the biggest table in the schema, and the deepest level of the hierarchy
 *
 * `master_kelurahan` holds more rows than any other reference table, which is why it
 * paginates while its three ancestors do not. It is the last level of the walk a
 * patient makes before they have an account, so it is public and ungated for the same
 * reason `provinsi` is.
 *
 * @property-read MasterKelurahan $resource
 */
class KelurahanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'kecamatan_id' => $this->resource->kecamatan_id,
            'kode' => $this->resource->kode,
            'nama' => $this->resource->nama,
        ];
    }
}
