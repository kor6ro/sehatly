import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import type {
    MetodePembayaran,
    MulaiPembayaranData,
    PromoValidasi,
    StatusPembayaran,
} from '@/lib/api/types';

/**
 * Module 5's money surface: the payment methods, the promo calculation and the payment
 * initiation.
 *
 * | method | path | guard | success |
 * | --- | --- | --- | --- |
 * | `GET` | `/api/v1/referensi/metode-pembayaran` | none - PUBLIC | 200 + `meta` |
 * | `POST` | `/api/v1/promo/validasi` | `auth:sanctum` | 200 always |
 * | `POST` | `/api/v1/invoice/{id}/bayar` | `permission:pembayaran.bayar` | 201 |
 *
 * ## FINDING: there is no way to read an invoice or a payment
 *
 * The plan's `PaymentWaitingPage` is specified to "poll `GET /invoice/{id}`". That route
 * does not exist - `routes/api.php:1159-1162` registers only the `bayar` POST, and no
 * other route touches `invoice` or `pembayaran` at all. The checkout 201 does not publish
 * `invoice_id` either, so the client cannot even discover which invoice to pay.
 *
 * The screen therefore does NOT poll. It reflects the one published settlement signal that
 * does exist, `pesanan_obat.status` moving off `menunggu_pembayaran`, and it says so in its
 * own copy. A route to read an invoice is a backend change, not this executor's.
 */

/** `POST /invoice/{id}/bayar`. The only input is the method; `gateway` is NOT a field. */
export type BayarInvoiceInput = {
    /**
     * `master_metode_pembayaran.id`, and the server additionally requires
     * `status_aktif = 1`. A caller cannot name the provider: `MockPaymentGatewayService` is
     * selected by `config('payment.gateway_pembayaran')` on the server, so `gateway` is
     * the server's choice to publish rather than the client's to send.
     */
    metode_id: number;
};

/** `POST /promo/validasi`. Both fields are `required`, so neither is optional here. */
export type ValidasiPromoInput = {
    kode: string;
    /**
     * Required, and the reason the promo input cannot live on the checkout screen.
     *
     * `promo_redemption.invoice_id` is `NOT NULL` and foreign-keyed, so a validation call
     * has no invoice to attach to and must name an existing one. The invoice is created BY
     * the checkout, so before checkout there is no id to validate against - the loop cannot
     * be broken from the client. See `.omo/evidence/task-48-sehatly.md`.
     */
    invoice_id: number;
};

const STATUS_PEMBAYARAN_LABEL: Record<StatusPembayaran, string> = {
    pending: 'Menunggu konfirmasi gateway',
    berhasil: 'Berhasil',
    gagal: 'Gagal',
    kedaluwarsa: 'Kedaluwarsa',
    refund: 'Dikembalikan',
};

export function labelStatusPembayaran(value: StatusPembayaran): string {
    return STATUS_PEMBAYARAN_LABEL[value] ?? value;
}

/**
 * The nine `master_metode_pembayaran.tipe` members with the wording the picker groups by.
 *
 * A **DDL transcription**, not a response field: `MetodePembayaranResource` publishes the
 * raw `tipe` string and the table maps a method to a provider only through free-text
 * `penyedia`, with no gateway column at all. So the client can only group, never route.
 *
 * All nine are declared because the column declares nine and `MetodePembayaranSeeder` ships
 * rows across all of them; the plan's own list of five would hide four seeded methods.
 */
export const TIPE_METODE_LABEL: Record<MetodePembayaran['tipe'], string> = {
    va_bank: 'Virtual Account',
    e_wallet: 'E-Wallet',
    qris: 'QRIS',
    kartu_kredit: 'Kartu kredit',
    gerai_retail: 'Gerai ritel',
    cod: 'Bayar di tempat',
    tunai: 'Tunai',
    bpjs: 'BPJS',
    asuransi: 'Asuransi',
};

/** Grouping order, most common first, so the picker opens on what people actually use. */
export const TIPE_METODE_URUT: ReadonlyArray<MetodePembayaran['tipe']> = [
    'va_bank',
    'qris',
    'e_wallet',
    'kartu_kredit',
    'gerai_retail',
    'cod',
    'tunai',
    'bpjs',
    'asuransi',
];

/**
 * The admin fee the server will add, quoted in rupiah.
 *
 * ## It is an ESTIMATE and the screen must say so
 *
 * `InvoiceService::biayaAdmin()` applies `biaya_admin_flat` plus `biaya_admin_persen` to
 * **`subtotal - diskon`**, not to `subtotal`, and a promo applied to the same invoice moves
 * the base. The picker therefore shows the fee on the undiscounted subtotal and labels it
 * as such; the authoritative number is the `total` the server returns on the invoice, and
 * the payment screen renders that rather than this.
 */
export function perkiraanBiayaAdmin(metode: MetodePembayaran, subtotal: number): number {
    return metode.biaya_admin_flat + (subtotal * metode.biaya_admin_persen) / 100;
}

export async function fetchMetodePembayaran() {
    return request<{ metode_pembayaran: MetodePembayaran[] }>('referensi/metode-pembayaran');
}

export async function validasiPromo(input: ValidasiPromoInput) {
    return request<PromoValidasi>('promo/validasi', {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

export async function bayarInvoice(invoiceId: number, input: BayarInvoiceInput) {
    return request<MulaiPembayaranData>(`invoice/${invoiceId}/bayar`, {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

export const metodePembayaranQueryKey = ['v1', 'referensi', 'metode-pembayaran'] as const;

export const promoQueryKey = ['v1', 'promo'] as const;

/**
 * The reference list is PUBLIC and barely changes - it is fourteen `master_*` rows.
 *
 * `staleTime: 300_000` is long on purpose. A payment method is not a live signal, and this
 * query is mounted in the shell-level picker, so a long cache is the difference between one
 * request per session and one per navigation.
 */
export function metodePembayaranOptions() {
    return queryOptions({
        queryKey: metodePembayaranQueryKey,
        queryFn: fetchMetodePembayaran,
        staleTime: 300_000,
    });
}

/**
 * A promo validation is a POST and is therefore `retry: 0` twice over.
 *
 * The shared client already disables mutation retries, and the write is repeated here for
 * the reason `checkoutResep` repeats it: `POST /promo/validasi` is documented as a PURE
 * CALCULATION with **no writes at all** - it creates no `promo_redemption` and no
 * `invoice` - so a replay would be harmless. It is still not retried, because a replayed
 * POST that turned out to be impure would consume a real quota and this client should not
 * depend on that staying true.
 */
export function validasiPromoMutation() {
    return mutationOptions({ mutationFn: validasiPromo });
}

/**
 * A payment initiation is `retry: 0`, and the reasoning is the load-bearing part.
 *
 * The server already refuses a second `pending` payment for the same invoice from inside
 * its own transaction, so a replay cannot create a second payment. This client therefore
 * keeps **no** client-side "already started" flag: a latching local flag would hide the
 * server's own 422 ("Pembayaran untuk invoice ini sudah dibuat dengan nomor referensi X")
 * and leave a patient believing their payment is confirmed when the server is refusing to
 * confirm anything. The idempotency is the server's, and the screen's job is to show the
 * server's answer.
 */
export function bayarInvoiceMutation() {
    return mutationOptions({
        mutationFn: ({ invoiceId, input }: { invoiceId: number; input: BayarInvoiceInput }) =>
            bayarInvoice(invoiceId, input),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: promoQueryKey });
        },
    });
}
