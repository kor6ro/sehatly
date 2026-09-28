<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PesananObatTracking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `pesanan_obat_tracking` row.
 *
 * ## There are NO TIMESTAMPS on this table, and that is not an omission here
 *
 * `pesanan_obat_tracking` is five columns (`:820`-`:825`) and none of them is
 * `dibuat_at` or `diubah_at`:
 *
 * ```
 * pesanan_obat_id BIGINT UNSIGNED NOT NULL,
 * status           VARCHAR(100) NOT NULL,
 * keterangan       VARCHAR(255) NULL,
 * lokasi           VARCHAR(255) NULL,
 * waktu            DATETIME NOT NULL,
 * ```
 *
 * The model is therefore `$timestamps = false` and the ONLY time on the row is
 * `waktu` (`:825`), which the service writes from the application clock. This
 * resource publishes `waktu` and **no** `created_at`/`updated_at`, because
 * publishing a key the column does not exist would make a client read `null` and
 * believe the parcel has no time on it.
 *
 * ## `status` is free text in the DDL and an enum in the application
 *
 * `pesanan_obat_tracking.status` is `VARCHAR(100)` (`:822`) while sharing a NAME
 * with `pesanan_obat.status`'s six-value ENUM (`:810`-`:811`). The DDL permits any
 * string; this application writes only the six, which is the narrowing
 * `PesananObatStateMachine` exists to enforce. The value published here is
 * therefore always a member of `PesananObatStatus::nilai()` - and a reader must
 * still take the ORDER's state from `pesanan_obat.status` rather than from the
 * last row of this trail, which is an append-only history rather than the
 * authority.
 *
 * @property-read PesananObatTracking $resource
 */
class PesananObatTrackingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'pesanan_obat_id' => (int) $this->resource->pesanan_obat_id,
            'status' => (string) $this->resource->status,
            'keterangan' => $this->resource->keterangan === null ? null : (string) $this->resource->keterangan,
            'lokasi' => $this->resource->lokasi === null ? null : (string) $this->resource->lokasi,
            // `waktu` is `DATETIME NOT NULL` and the table's de-facto created-at.
            // Published as an ISO-8601 instant like every other time on this API.
            'waktu' => $this->resource->waktu?->toISOString(),
        ];
    }
}
