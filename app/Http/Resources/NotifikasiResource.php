<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Notifikasi;
use App\Services\Notifikasi\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One `notifikasi` row, published in the inbox's own vocabulary.
 *
 * ## `payload` is passed through, unfiltered, and that is a decision
 *
 * `payload JSON NULL` (`:1043`) is written by {@see NotificationService}
 * and read by nobody but the client. This resource does not reshape it: a client
 * that knows it will get `{"booking_id": 1001}` for a booking notification should
 * not have to handle a second shape when a different event arrives, and the
 * alternative - a flat `resource_id` per event - would need a new column per
 * event in a JSON slot that exists precisely so it does not.
 *
 * The one thing this class does NOT publish is `user_id`. It is on the row, it is
 * never different from the caller's own id, and a client has no use for it; the
 * eight keys below are the whole contract.
 *
 * ## `dibaca_at` is the FIRST read, not the last
 *
 * `PUT /notifikasi/{id}/baca` is idempotent: an already-read row keeps the
 * instant it was first read, so this value answers "when did they see it" and not
 * "when did they last open the app". The alternative - re-stamping on every call -
 * would make the question unanswerable and would write an `audit_log` row per
 * screen open, so the endpoint would end up logging attention rather than changes.
 *
 * ## `dibuat_at` is published because it is the sort key the client relies on
 *
 * `dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` (`:1045`) is the only
 * other time on the row, and `Notifikasi::UPDATED_AT` is `null` because the table
 * has no `diubah_at`. So the two published times are the two the DDL declares, and
 * neither is a key the column does not exist.
 *
 * @property-read Notifikasi $resource
 */
class NotifikasiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'judul' => (string) $this->resource->judul,
            'isi' => (string) $this->resource->isi,
            'tipe' => (string) $this->resource->tipe,
            'tautan' => $this->resource->tautan === null ? null : (string) $this->resource->tautan,
            'payload' => $this->resource->payload,
            'dibaca_at' => $this->resource->dibaca_at === null
                ? null
                : Carbon::instance($this->resource->dibaca_at)->toISOString(),
            'dibuat_at' => $this->resource->dibuat_at === null
                ? null
                : Carbon::instance($this->resource->dibuat_at)->toISOString(),
        ];
    }
}
