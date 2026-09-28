<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ResepItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `resep_item` row, publishing the STORED snapshot.
 *
 * `nama_obat` is `VARCHAR(255) NOT NULL COMMENT 'Snapshot nama saat
 * diresepkan'` (`telemedicine_test.sql:771`): written explicitly from the
 * catalogue at creation and published from the row, never through a live
 * join to `master_obat`, because a later catalogue rename would otherwise
 * silently rewrite history. `harga_satuan` and `subtotal` are the same
 * discipline applied to money.
 *
 * @property-read ResepItem $resource
 */
class ResepItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'obat_id' => $this->resource->obat_id === null ? null : (int) $this->resource->obat_id,
            'nama_obat' => (string) $this->resource->nama_obat,
            'kekuatan' => $this->resource->kekuatan,
            'aturan_pakai' => (string) $this->resource->aturan_pakai,
            'jumlah' => (int) $this->resource->jumlah,
            'satuan' => $this->resource->satuan,
            'is_racikan' => (bool) $this->resource->is_racikan,
            'racikan_nama' => $this->resource->racikan_nama,
            'harga_satuan' => (string) $this->resource->harga_satuan,
            'subtotal' => (string) $this->resource->subtotal,
            'catatan_apoteker' => $this->resource->catatan_apoteker,
        ];
    }
}
