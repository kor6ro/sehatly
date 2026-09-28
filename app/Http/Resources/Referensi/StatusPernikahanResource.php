<?php

declare(strict_types=1);

namespace App\Http\Resources\Referensi;

use App\Models\MasterStatusPernikahan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `master_status_pernikahan` row, for `GET /api/v1/referencia/status-pernikahan`.
 *
 * ## The whole table, both columns
 *
 * `master_status_pernikahan` is `(id, nama)` and nothing else - no `kode`, no
 * timestamp. The four rows are the DDL's seed data, and `total` in the `meta` block is
 * the live row count rather than a constant.
 *
 * @property-read MasterStatusPernikahan $resource
 */
class StatusPernikahanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'nama' => $this->resource->nama,
        ];
    }
}
