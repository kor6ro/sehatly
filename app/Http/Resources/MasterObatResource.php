<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MasterObat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `master_obat` row, as the doctor-only search publishes it.
 *
 * Allow-list, never `toArray()`: a column added to `master_obat` must not
 * reach the wire by accident.
 *
 * ## The sixteen keys, and the two columns deliberately left out
 *
 * `master_obat` (`telemedicine_test.sql:708`-`:729`) declares EIGHTEEN columns.
 * This publishes sixteen of them and omits `dibuat_at` (`:726`) and
 * `diubah_at` (`:727`), the pair of `TIMESTAMP` columns Eloquent maintains.
 * The omission is a decision, not an oversight:
 *
 * - a catalogue row is reference data, not a record of an event, and neither
 *   timestamp says anything a prescribing doctor acts on;
 * - `diubah_at` moves on any catalogue edit, so publishing it would make two
 *   prescriptions of the same drug look like they were composed against
 *   different catalogues when they were not;
 * - the plan names the fields this response carries (`:567`) and neither
 *   timestamp is among them.
 *
 * `ResepTodo39ContractTest` asserts the published key set against the PARSED
 * DDL rather than against a transcription of it, so a column added to
 * `master_obat` fails the suite instead of being published by accident, and a
 * timestamp added to this list fails it too.
 *
 * @property-read MasterObat $resource
 */
class MasterObatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'kode_obat' => (string) $this->resource->kode_obat,
            'nama_generik' => (string) $this->resource->nama_generik,
            'nama_brand' => $this->resource->nama_brand,
            'bentuk_sediaan' => (string) $this->resource->bentuk_sediaan,
            'kekuatan' => $this->resource->kekuatan,
            'satuan' => (string) $this->resource->satuan,
            'pabrikan' => $this->resource->pabrikan,
            'kelas_terapi' => $this->resource->kelas_terapi,
            'kelas_obat' => (string) $this->resource->kelas_obat,
            'requires_resep' => (bool) $this->resource->requires_resep,
            'aturan_pakai_umum' => $this->resource->aturan_pakai_umum,
            'indikasi' => $this->resource->indikasi,
            'kontraindikasi' => $this->resource->kontraindikasi,
            'harga_jual' => (string) $this->resource->harga_jual,
            'status_aktif' => (bool) $this->resource->status_aktif,
        ];
    }
}
