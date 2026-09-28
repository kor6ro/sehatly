<?php

declare(strict_types=1);

namespace App\Http\Resources\Referensi;

use App\Models\MasterAgama;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `master_agama` row, for `GET /api/v1/referencia/agama`.
 *
 * ## The whole table, both columns
 *
 * `master_agama` is `(id, nama)` and nothing else. It carries no `kode` at all, which
 * is the one reason this resource is not shaped like its siblings: there is no second
 * field to publish. The seven rows are the DDL's seed data, and `total` in the `meta`
 * block is the live row count rather than a constant, so a schema-only database
 * honestly answers `0`.
 *
 * @property-read MasterAgama $resource
 */
class AgamaResource extends JsonResource
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
