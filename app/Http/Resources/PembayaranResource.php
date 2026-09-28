<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\PembayaranStatus;
use App\Models\Pembayaran;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `pembayaran` row, as the payer sees it.
 *
 * `telemedicine_test.sql:958-973`. Eight columns, all of them published:
 *
 * | published | column | why |
 * | --- | --- | --- |
 * | `jumlah` | `jumlah` (:962) | what the patient owes, as a JSON **string** |
 * | `nomor_referensi` | `nomor_referensi` (:963) | the provider's transaction id; the thing a support ticket quotes |
 * | `gateway` | `gateway` (:965) | which provider holds the money |
 * | `status` | `status` (:966) | one of the five members |
 * | `dibayar_at` | `dibayar_at` (:967) | WHEN it settled, or null |
 * | `kadaluwarsa_at` | derived | WHEN to stop showing the instructions |
 *
 * ## `jumlah` is a JSON STRING, and never a number
 *
 * `jumlah` is `DECIMAL(14,2)` (:962) and every money value on this API is a
 * string - `"150000.00"`, not `150000.0`. A JSON number has already lost
 * precision by the time PHP's parser hands it over, and a client rendering
 * `150000.00` as a JS double prints `150000` or, worse, `149999.99999999997`
 * after arithmetic. The cast on the model is `decimal:2`, so the value here is
 * already a two-decimal string and is published unchanged.
 *
 * ## `webhook_payload` is NOT published, and that is the interesting omission
 *
 * The column is `JSON NULL` (:968) and it holds the provider's raw delivery -
 * whatever a third party put in the body, including anything they choose to
 * add. Publishing it to the payer would (a) hand an unauthenticated-adjacent
 * third party's document shape to a client that has no use for it, and (b) make
 * this resource a channel through which a forged body, had one been accepted,
 * could be reflected. It is an operator's forensic record and belongs to the
 * audit surface, not to a patient's receipt.
 *
 * ## `kadaluwarsa_at` is DERIVED, and it is the one key with no column
 *
 * `pembayaran` has no expiry column - the five statuses at (:966) are the whole
 * vocabulary and a stale `pending` row is not one of them - and nothing in the
 * schema reacts to time at all. So "stop showing this virtual account" is
 * computed from `dibuat_at` (:969) plus `config('payment.kedaluwarsa_detik')`.
 * A client rendering an expiry it invented would disagree with this one, so the
 * server publishes it and the client shows what it is given.
 *
 * @property-read Pembayaran $resource
 */
class PembayaranResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Pembayaran $pembayaran */
        $pembayaran = $this->resource;

        $kedaluwarsa = clone ($pembayaran->dibuat_at ?? now());
        $kedaluwarsa = $kedaluwarsa->addSeconds((int) config('payment.kedaluwarsa_detik', 86400));

        return [
            'id' => (int) $pembayaran->getKey(),
            'invoice_id' => $pembayaran->invoice_id === null ? null : (int) $pembayaran->invoice_id,
            'metode_id' => $pembayaran->metode_id === null ? null : (int) $pembayaran->metode_id,
            'jumlah' => (string) $pembayaran->jumlah,
            'nomor_referensi' => $pembayaran->nomor_referensi,
            'gateway' => $pembayaran->gateway,
            'va_number' => $pembayaran->va_number,
            'status' => (string) $pembayaran->status,
            'dibayar_at' => $pembayaran->dibayar_at?->toISOString(),
            'kadaluwarsa_at' => $kedaluwarsa->toISOString(),
            // Derived, so a client never has to learn the rule. A terminal
            // status means no further delivery will change this row - see
            // `PembayaranStatus::adalahAkhir()`.
            'terminal' => PembayaranStatus::adalahAkhir((string) $pembayaran->status),
        ];
    }
}
