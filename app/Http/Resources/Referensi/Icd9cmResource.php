<?php

declare(strict_types=1);

namespace App\Http\Resources\Referensi;

use App\Models\MasterIcd9cm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `master_icd9cm` row, for `GET /api/v1/referencia/icd9cm`.
 *
 * ## The whole table, with the same `deskripsi` label as its ICD-10 sibling
 *
 * `master_icd9cm` is `(id, kode, deskripsi)` (`telemedicine_test.sql`) - the same three
 * columns as `master_icd10`, which is why it has its own resource rather than reusing
 * one: the two resources document two different tables, and a shared base would hide the
 * fact that the model behind each is distinct. Sharing the ICD-10 *shape* would be right;
 * sharing one resource across two models is not, and it would make `?q=` on one table
 * silently read the other.
 *
 * ## The slug is `icd9cm`, and it is not `icd-9cm`
 *
 * The path segment is the plan's spelling, so the route and the DDL both stay greppable
 * from the same token. The table is `master_icd9cm`; the plan writes the endpoint
 * `/referensi/icd9cm`. Renaming either to the other's convention would make one of them
 * harder to find from the other, and the plan's spelling is the one clients are written
 * against.
 *
 * ## Paginated and searchable
 *
 * A procedure code is looked up by a clinician who knows a fragment, so this endpoint
 * paginates and accepts `?q=` against `kode` and `deskripsi`, exactly as its ICD-10
 * sibling does.
 *
 * @property-read MasterIcd9cm $resource
 */
class Icd9cmResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'kode' => $this->resource->kode,
            'deskripsi' => $this->resource->deskripsi,
        ];
    }
}
