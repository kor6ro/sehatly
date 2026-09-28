<?php

declare(strict_types=1);

namespace App\Http\Resources\Referensi;

use App\Models\MasterGolonganDarah;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `master_golongan_darah` row, for `GET /api/v1/referencia/golongan-darah`.
 *
 * ## The whole table, and it has NO `nama`
 *
 * `master_golongan_darah` is `(id, kode)` and NOTHING else
 * (`telemedicine_test.sql:96`). There is no `nama` column, which is the one structural
 * difference from every other resource in this namespace and the reason this file
 * exists separately rather than being a shared shape with a null field.
 *
 * It is also the reason `ReferensiEndpoint` orders this table on `kode` rather than
 * `nama`: `kode` is the only text the table has, so it is both the label and the sort
 * key. The four values are `A`, `B`, `AB` and `O`.
 *
 * @property-read MasterGolonganDarah $resource
 */
class GolonganDarahResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'kode' => $this->resource->kode,
        ];
    }
}
