<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MasterSpesialisasi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `master_spesialisasi` row, for the `?spesialisasi=` filter's reference list.
 *
 * ## The whole table, all three columns
 *
 * `master_spesialisasi` is `(id, kode, nama, tipe)` and nothing else
 * (`telemedicine_test.sql:402`-`:407`). There is no timestamp, no soft delete and no
 * relation to hide behind, so this resource is the complete row and the endpoint it
 * serves is a plain listing.
 *
 * ## `tipe` here is the THREE-value ENUM and it is not `dokter.tipe`
 *
 * `:406` is `ENUM('dokter_umum','spesialis','subspesialis')`, while `dokter.tipe`
 * (`:412`) is `ENUM('dokter_umum','dokter_spesialis','dokter_gigi','psikolog',
 * 'bidan','perawat','apoteker')`. **They share exactly one member, `dokter_umum`.**
 * `'spesialis'` here is not `'dokter_spesialis'` there, and no other string
 * comparison between the two columns matches anything. `SpesialisasiSeeder`'s
 * docblock and `docs/schema-notes.md` both carry the same warning.
 *
 * A client populating two dropdowns from one endpoint therefore has to keep the two
 * vocabularies apart, and publishing both from one response is the safest way to
 * make that visible rather than to let a client guess.
 *
 * ## No timestamps to emit
 *
 * The table declares neither `dibuat_at` nor `diubah_at` nor `terkirim_at`, so
 * `MasterSpesialisasi::$timestamps` is `false` and there is nothing to serialise.
 *
 * @property-read MasterSpesialisasi $resource
 */
class MasterSpesialisasiResource extends JsonResource
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
            'tipe' => $this->resource->tipe,
        ];
    }
}
