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
 * reach the wire by accident. The sixteen keys are every column the DDL
 * declares (`telemedicine_test.sql:708-729`), timestamps included, because
 * the plan names the catalogue fields for this response and the test pins
 * the count.
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
