<?php

declare(strict_types=1);

namespace App\Http\Resources\Referensi;

use App\Models\MasterHubunganKeluarga;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `master_hubungan_keluarga` row, for `GET /api/v1/referencia/hubungan-keluarga`.
 *
 * ## The whole table, both columns
 *
 * `master_hubungan_keluarga` is `(id, nama)` and nothing else - no `kode`, no
 * timestamp. The seven rows are the DDL's seed data, and `total` in the `meta` block is
 * the live row count rather than a constant.
 *
 * @property-read MasterHubunganKeluarga $resource
 */
class HubunganKeluargaResource extends JsonResource
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
