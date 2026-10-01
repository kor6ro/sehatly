<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Models\Pembayaran;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `invoice` row, as the payer sees it.
 *
 * `telemedicine_test.sql:936-956`. Every column the bill itself is made of is
 * published:
 *
 * | published | column | why |
 * | --- | --- | --- |
 * | `id` | `id` (:937) | the key the SPA no longer has to type by hand (F06) |
 * | `nomor_invoice` | `nomor_invoice` (:938) | the number a support ticket quotes |
 * | `referensi_tipe` / `referensi_id` | :940, :941 | which row this bill is FOR - a booking, a prescription, an order |
 * | `subtotal`, `diskon`, `biaya_admin`, `biaya_pengiriman`, `total` | :942-:946 | the arithmetic, unchanged |
 * | `status` | `status` (:947) | one of the seven members |
 * | `jatuh_tempo`, `lunas_at`, `dibuat_at` | :949-:951 | WHEN it is due, WHEN it settled, WHEN it was raised |
 *
 * ## Every money value is a JSON STRING, and never a number
 *
 * `subtotal`, `diskon`, `biaya_admin`, `biaya_pengiriman` and `total` are all
 * `DECIMAL(14,2)` (:942-:946) and each carries the `decimal:2` cast on the model,
 * so every one of them is already a two-decimal string - `"150000.00"`, not
 * `150000.0`. {@see PembayaranResource} states the argument in full; the rule is
 * repeated here because `invoice.total` is the exact figure
 * `PaymentService::mulai()` mints a `pembayaran.jumlah` from, and a numeric total
 * here beside a string total there would be one contract with two number types.
 *
 * ## `pembayaran` is the payment history, NEWEST FIRST
 *
 * `invoice.pembayaran` is a `hasMany` (`:970`), because an invoice may be
 * initiated more than once - the first attempt can fail or expire and the payer
 * may start another. The one a client renders as "current status" is the latest,
 * so the ordering is imposed HERE rather than trusted from the caller: sorting by
 * `id` descending is total (the primary key is `AUTO_INCREMENT`, :959), so two
 * payments created in the same second still have a stable answer. Each row is
 * mapped through {@see PembayaranResource}, which is also why no
 * `webhook_payload` is reachable from this file.
 *
 * ## `webhook_payload` is NOT published, and this resource cannot leak it
 *
 * The column is `JSON NULL` on `pembayaran` (:968), not on `invoice`, and the
 * only path from here to a payment row is `PembayaranResource`, which omits it.
 * There is deliberately no `toArray()` branch that reads the raw relation and no
 * `$invoice->toArray()` passthrough that could reintroduce it.
 *
 * ## Dates are ISO-8601 strings, or null
 *
 * `jatuh_tempo`, `lunas_at` and `dibuat_at` are `DATETIME`/`TIMESTAMP` (:949-:951)
 * and `dibuat_at` is the model's `CREATED_AT`, so each is cast to a `Carbon` and
 * published through `toISOString()`. An absent value stays `null` rather than
 * becoming an epoch or an empty string.
 *
 * @property-read Invoice $resource
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->resource;

        return [
            'id' => (int) $invoice->getKey(),
            'nomor_invoice' => $invoice->nomor_invoice,
            'referensi_tipe' => $invoice->referensi_tipe,
            'referensi_id' => $invoice->referensi_id === null ? null : (int) $invoice->referensi_id,
            // The five DECIMAL(14,2) columns, each already a two-decimal string
            // through its `decimal:2` cast. Published unchanged.
            'subtotal' => (string) $invoice->subtotal,
            'diskon' => (string) $invoice->diskon,
            'biaya_admin' => (string) $invoice->biaya_admin,
            'biaya_pengiriman' => (string) $invoice->biaya_pengiriman,
            'total' => (string) $invoice->total,
            'status' => (string) $invoice->status,
            'jatuh_tempo' => $invoice->jatuh_tempo?->toISOString(),
            'lunas_at' => $invoice->lunas_at?->toISOString(),
            'dibuat_at' => $invoice->dibuat_at?->toISOString(),
            // Newest first, sorted here so the order does not depend on which
            // controller loaded the relation. `PembayaranResource` is the only
            // thing that shapes a payment row, and it never publishes
            // `webhook_payload`.
            'pembayaran' => PembayaranResource::collection(
                $invoice->pembayaran
                    ->sortByDesc(static fn (Pembayaran $pembayaran): int => (int) $pembayaran->getKey())
                    ->values(),
            ),
        ];
    }
}
