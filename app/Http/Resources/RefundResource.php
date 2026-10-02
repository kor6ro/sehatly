<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `refund` row on the PATIENT-facing list (`GET /api/v1/pasien/refund`).
 *
 * ## A dedicated resource, not the shared one
 *
 * The refund surface has exactly this one resource. The doctor-facing booking
 * resource (`BookingResource`) is a different disclosure surface, and hanging
 * a refund block off it would make every booking response carry money data the
 * reader did not ask for. The list's shape is exactly the four facts the F12
 * refund card renders - amount, destination, status, requested-at - plus the
 * two identifiers needed to link back to the booking and forward to the refund
 * row.
 *
 * ## `booking_id` is resolved through the invoice
 *
 * `refund` names only `pembayaran_id` (`telemedicine_test.sql:977`); the
 * booking is `invoice.referensi_id` with `invoice.referensi_tipe = 'booking'`.
 * The service's query filters on that pair, and this resource re-reads it from
 * the eager-loaded rows rather than exposing `pembayaran_id`, which is an
 * internal settlement key with no meaning to a patient.
 *
 * ## The destination is the method LABEL, never an account number
 *
 * `refund` has no beneficiary column, and `master_metode_pembayaran` carries a
 * human label (`nama`, :928) rather than credentials - so nothing here can
 * leak a full account number into a URL, a toast or a log. If a future method
 * gains a masked destination it belongs in `metode` as its own key, not folded
 * into `label`.
 *
 * @property-read Refund $resource
 */
class RefundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pembayaran = $this->resource->pembayaran;
        $invoice = $pembayaran?->invoice;
        $metode = $pembayaran?->metode;

        return [
            'id' => $this->resource->getKey(),
            'booking_id' => $invoice !== null && (string) $invoice->referensi_tipe === 'booking'
                ? (int) $invoice->referensi_id
                : null,
            // `refund.jumlah` is `DECIMAL(14,2)` (:978), so it arrives as a
            // JSON string, never a number - the same convention every money
            // column in this API follows.
            'jumlah' => (string) $this->resource->jumlah,
            'metode' => $metode === null ? null : [
                'id' => (int) $metode->getKey(),
                'label' => (string) $metode->nama,
            ],
            'status' => (string) $this->resource->status,
            'dibuat_at' => $this->resource->dibuat_at?->toISOString(),
        ];
    }
}
