<?php

declare(strict_types=1);

namespace App\Http\Resources\Referensi;

use App\Models\MasterIcd10;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `master_icd10` row, for `GET /api/v1/referencia/icd10`.
 *
 * ## The whole table, and the text column is `deskripsi` not `nama`
 *
 * `master_icd10` is `(id, kode, deskripsi)` (`telemedicine_test.sql`). It is the only
 * reference table whose label column is called `deskripsi`, which is why it has its own
 * resource instead of sharing the `(id, kode, nama)` shape of `master_spesialisasi`.
 * A client generated from this endpoint reads `deskripsi`; one written against
 * `master_spesialisasi` reads `nama`.
 *
 * ## This is a SEARCHED table, not a scrolled one
 *
 * A diagnosis code is looked up by a clinician who knows a fragment of the code or of
 * the description, so this endpoint paginates and accepts `?q=`. It is one of only three
 * reference tables that do - the other two are the ICD-9CM sibling and `master_kelurahan`.
 *
 * @property-read MasterIcd10 $resource
 */
class Icd10Resource extends JsonResource
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
