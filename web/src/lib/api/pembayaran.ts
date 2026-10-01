import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import type {
    Invoice,
    MetodePembayaran,
    MulaiPembayaranData,
    PromoValidasi,
    StatusInvoice,
    StatusPembayaran,
} from '@/lib/api/types';

/**
 * Module 5's money surface: the payment methods, the invoice read, the promo calculation
 * and the payment initiation.
 *
 * | method | path | guard | success |
 * | --- | --- | --- | --- |
 * | `GET` | `/api/v1/referensi/metode-pembayaran` | none - PUBLIC | 200 + `meta` |
 * | `GET` | `/api/v1/invoice/{id}` | `permission:pembayaran.bayar` | 200 |
 * | `POST` | `/api/v1/promo/validasi` | `auth:sanctum` | 200 always |
 * | `POST` | `/api/v1/invoice/{id}/bayar` | `permission:pembayaran.bayar` | 201 |
 *
 * ## The invoice read exists, and it is owner-scoped
 *
 * `GET /invoice/{id}` publishes the whole row through `App\Http\Resources\InvoiceResource`,
 * including its newest-first `pembayaran` history, so a typed invoice id can be verified -
 * and its real status and amount shown - before any payment is started. Another patient's
 * invoice is a 404, not a 403, exactly as the order read behaves.
 *
 * ## The settlement signal is still `pesanan_obat.status`, and that has not changed
 *
 * The invoice read shows what the server thinks of the BILL. What moves the ORDER is
 * `PaymentService::LANJUT` inside the settlement transaction, and the webhook - not the
 * browser - is the only thing that triggers it. So the payment screen polls
 * `GET /pesanan-obat/{id}` for settlement and uses this read for the summary, never the
 * other way round.
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

const STATUS_INVOICE_LABEL: Record<StatusInvoice, string> = {
    draft: 'Draf',
    menunggu_pembayaran: 'Menunggu pembayaran',
    lunas: 'Lunas',
    kadaluarsa: 'Kedaluwarsa',
    dibatalkan: 'Dibatalkan',
    refund_sebagian: 'Dana dikembalikan sebagian',
    refund_penuh: 'Dana dikembalikan penuh',
};

/**
 * The seven `invoice.status` members, spelled for the payer.
 *
 * A DDL transcription like {@link TIPE_METODE_LABEL}: the resource publishes the raw
 * column value and no reference endpoint lists this ENUM, so the wording has to live
 * somewhere and one place is the right number of places.
 */
export function labelStatusInvoice(value: StatusInvoice): string {
    return STATUS_INVOICE_LABEL[value] ?? value;
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

export async function fetchInvoice(invoiceId: number) {
    return request<{ invoice: Invoice }>(`invoice/${invoiceId}`);
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

export const invoiceQueryKey = ['v1', 'invoice'] as const;

/**
 * One invoice read, keyed by id.
 *
 * `retry: false` because the two interesting failures are decisions, not blips: a 404
 * ("that id is not yours, or does not exist") and a 403 ("this account owns no patient
 * row") are both fixed by typing a different id or signing in as the right account, not by
 * asking again. A network failure still surfaces through `ErrorState`'s retry.
 */
export function invoiceOptions(invoiceId: number) {
    return queryOptions({
        queryKey: [...invoiceQueryKey, invoiceId],
        queryFn: () => fetchInvoice(invoiceId),
        retry: false,
    });
}

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
            void queryClient.invalidateQueries({ queryKey: invoiceQueryKey });
        },
    });
}
