<?php

declare(strict_types=1);

namespace App\Http\Resources\Referensi;

use App\Models\MasterMetodePembayaran;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `master_metode_pembayaran` row, for `GET /api/v1/referencia/metode-pembayaran`.
 *
 * ## The whole table, and here publishing every column is the point
 *
 * `master_metode_pembayaran` is `(id, kode, nama, tipe, penyedia, biaya_admin_flat,
 * biaya_admin_persen, status_aktif)` (`telemedicine_test.sql`). It is the only reference
 * table whose columns a client cannot do without: a booking screen has to show the fee
 * before the patient commits, and `biaya_admin_flat` and `biaya_admin_persen` are the
 * two halves of that number. A narrowed resource that published only `kode` and `nama`
 * would force a second request for the fees, on a screen the patient is looking at.
 *
 * `tipe` is the discriminator that says which of the two fee columns applies, and it is
 * an ENUM in its own right - it is in `docs/enums.json` under
 * `master_metode_pembayaran.tipe` - so a generated client already knows its members.
 *
 * ## `status_aktif` is published, and filtered by default
 *
 * The endpoint returns active methods unless a client asks for `?status_aktif=0`. The
 * flag is in the response either way, so a client that did ask for the retired ones can
 * tell them apart.
 *
 * @property-read MasterMetodePembayaran $resource
 */
class MetodePembayaranResource extends JsonResource
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
            'penyedia' => $this->resource->penyedia,
            'biaya_admin_flat' => (float) $this->resource->biaya_admin_flat,
            'biaya_admin_persen' => (float) $this->resource->biaya_admin_persen,
            'status_aktif' => (bool) $this->resource->status_aktif,
        ];
    }
}
