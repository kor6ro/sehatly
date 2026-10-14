import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import type {
    KurirPesanan,
    PesananObat,
    StatusPesananObat,
    StokObat,
    TipePesananObat,
} from '@/lib/api/types';

/**
 * Module 5's order surface: the three `pesanan_obat` routes.
 *
 * | method | path | guard | success |
 * | --- | --- | --- | --- |
 * | `GET` | `/api/v1/obat/{id}/stok` | `auth:sanctum` only | 200 |
 * | `POST` | `/api/v1/resep/{id}/checkout` | `permission:pesanan.buat` | 201 |
 * | `GET` | `/api/v1/pesanan-obat/{id}` | `permission:pesanan.lihat` | 200 |
 *
 * ## The two gaps this client is built around
 *
 * **No list endpoint.** There is no `GET /pasien/pesanan-obat` and no
 * `GET /pesanan-obat`, so "my orders" cannot be rendered. Every order screen here is
 * addressed by id, the same way todo 41's pharmacist queue is. **No invoice id on the
 * order.** The checkout 201 carries no `invoice_id` and `PesananObatResource` has no
 * invoice field, so the id that `POST /invoice/{id}/bayar` needs cannot be discovered from
 * any response the order flow produces. It CAN be read once typed: `GET /invoice/{id}`
 * (see `lib/api/pembayaran.ts`) resolves the bill and its payment history, while this
 * module remains the settlement source.
 *
 * ## `recorded` is the field a naive stock panel drops
 *
 * `PesananObatService::cekStok()` distinguishes a pharmacy that has never stocked a drug
 * (`recorded: false`, `jumlah_stok: 0`) from one whose shelf is empty
 * (`recorded: true`, `jumlah_stok: 0`). The first is a permanent "this pharmacy does not
 * carry it" and the second is "ask again tomorrow", and a screen that renders both as
 * "stok 0" sends a patient to a shop that was never going to help. See {@link StokObat}.
 *
 * ## The response is `data.stok`, NOT `data`
 *
 * `PesananObatController::stok()` wraps the pass-through resource under a `stok` key, and
 * the shape is a read that is easy to get one level wrong. Measured on the live API:
 * `{"data":{"stok":{"obat_id":2,"jumlah_diminta":1,"apotek":null,"alternatif":[...]}}}`.
 * A reader written against `data.alternatif` reads `undefined` and concludes - wrongly -
 * that no pharmacy stocks the drug, which is exactly the failure this screen exists to
 * prevent.
 */

/**
 * `GET /obat/{id}/stok`'s query string, `StokObatRequest::rules()` exactly.
 *
 * `jumlah` is capped at 65535 because `resep_item.jumlah` is `SMALLINT UNSIGNED`, and
 * `apotek_id` is `min:1` with a 422 naming the field for a facility that is missing,
 * inactive, or not an `apotek` - the schema does not constrain the column to one.
 */
export type CekStokParams = {
    apotekId?: number | null;
    jumlah?: number | null;
};

/**
 * `POST /resep/{id}/checkout`'s writable subset, `CheckoutResepRequest::rules()` exactly.
 *
 * ## `items` is `prohibited`, and that is the design
 *
 * `pesanan_obat` has no line-item table, so the order's items are read back through
 * `resep_item` and a body that carried its own items would be inventing rows that cannot
 * exist. The server marks the key `prohibited` rather than merely absent, so a client that
 * sent one is told so instead of having it silently dropped.
 *
 * ## `tipe` accepts only `resep_dokter` in practice
 *
 * `obat_bebas` and `produk_kesehatan` are legal DDL members and the service refuses both
 * with a 422 on `tipe`, because there is nowhere to record what was bought. The field is
 * typed as the three-value union and the client sends nothing, which the server reads as
 * the default.
 */
export type CheckoutResepInput = {
    apotek_id: number;
    /** `DECIMAL` STRING, max 14 characters. The server rejects a JSON number. */
    biaya_kirim?: string;
    metode_id?: number;
    /** `VARCHAR(30)`. Applied by `InvoiceService::buat()`, never computed here. */
    kode_promo?: string;
    kurir?: KurirPesanan;
    tipe?: TipePesananObat;
};

const STATUS_PESANAN_LABEL: Record<StatusPesananObat, string> = {
    menunggu_pembayaran: 'Menunggu pembayaran',
    diproses: 'Diproses apotek',
    siap: 'Siap dikirim',
    sedang_dikirim: 'Sedang dikirim',
    selesai: 'Selesai',
    dibatalkan: 'Dibatalkan',
};

export function labelStatusPesanan(value: StatusPesananObat): string {
    return STATUS_PESANAN_LABEL[value] ?? value;
}

/** The tracking statuses worth rendering as a distinct colour, in trail order. */
const STATUS_PESANAN_URUT: ReadonlyArray<StatusPesananObat> = [
    'menunggu_pembayaran',
    'diproses',
    'siap',
    'sedang_dikirim',
    'selesai',
    'dibatalkan',
];

/**
 * Where a status sits on the timeline, or `null` for a value outside the six.
 *
 * A DDL transcription rather than a response field, and labelled as such at the one place
 * it is used. `pesanan_obat_tracking.status` is `VARCHAR(100)` in the DDL, so the server
 * could publish a seventh value; a timeline that rendered an unknown status as step 0
 * would silently rewrite history, so it renders it with no marker instead.
 */
export function urutanStatusPesanan(value: StatusPesananObat): number | null {
    const index = STATUS_PESANAN_URUT.indexOf(value);

    return index === -1 ? null : index;
}

export async function cekStokObat(obatId: number, params: CekStokParams = {}) {
    return request<{ stok: StokObat }>(`obat/${obatId}/stok`, {
        searchParams: {
            ...(params.apotekId == null ? {} : { apotek_id: params.apotekId }),
            ...(params.jumlah == null ? {} : { jumlah: params.jumlah }),
        },
    });
}

export async function checkoutResep(resepId: number, input: CheckoutResepInput) {
    return request<{ pesanan: PesananObat }>(`resep/${resepId}/checkout`, {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

export async function fetchPesananObat(id: number) {
    return request<{ pesanan: PesananObat }>(`pesanan-obat/${id}`);
}

export const pesananObatQueryKey = ['v1', 'pesanan-obat'] as const;

export const stokObatQueryKey = ['v1', 'obat', 'stok'] as const;

export function pesananObatOptions(id: number) {
    return queryOptions({
        queryKey: [...pesananObatQueryKey, id],
        queryFn: () => fetchPesananObat(id),
    });
}

/**
 * One shelf read, keyed by BOTH parameters.
 *
 * `apotekId` is in the key because the same drug answers a different question once a
 * pharmacy is chosen: without it `apotek` is `null` and `alternatif` is the candidate
 * list, and with it `apotek` is that pharmacy's shelf. Sharing a key would let the
 * candidate list render as if it were a chosen shelf.
 *
 * `staleTime: 15_000` because stock is a moving number and `refetchIntervalInBackground`
 * is left off, so a hidden tab stops asking.
 */
export function stokObatOptions(obatId: number, params: CekStokParams = {}) {
    return queryOptions({
        queryKey: [...stokObatQueryKey, obatId, params.apotekId ?? null, params.jumlah ?? null],
        queryFn: () => cekStokObat(obatId, params),
        staleTime: 15_000,
    });
}

/**
 * A checkout invalidates the shelves it just consumed.
 *
 * `ApotekStokService::kurangi()` decrements `apotek_stok` **inside the checkout
 * transaction**, so a successful order makes every shelf this screen just read stale -
 * the numbers on screen are the numbers the server has already changed.
 */
export function checkoutResepMutation(resepId: number) {
    return mutationOptions({
        mutationFn: (input: CheckoutResepInput) => checkoutResep(resepId, input),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: stokObatQueryKey });
        },
    });
}
