import { queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import type { Iso } from '@/lib/api/types';

/**
 * `GET /api/v1/pasien/refund` - the caller's own refund rows, newest first.
 *
 * The resource publishes exactly the five facts the F12 card renders: amount,
 * destination, status, requested-at, and the `booking_id` the card is matched on.
 * `refund.jumlah` is a `DECIMAL(14,2)` and therefore a JSON **string**, never a number.
 *
 * `status` is transcribed from `RefundService`'s four constants rather than from a
 * remembered enum, so a fifth server status is a type error at the badge instead of a
 * card that silently falls through to a default colour.
 */
export const STATUS_REFUND = ['diajukan', 'diproses', 'berhasil', 'ditolak'] as const;

export type StatusRefund = (typeof STATUS_REFUND)[number];

const STATUS_REFUND_LABEL: Record<StatusRefund, string> = {
    diajukan: 'Diajukan',
    diproses: 'Diproses',
    berhasil: 'Dikembalikan',
    ditolak: 'Ditolak',
};

export function labelStatusRefund(value: StatusRefund): string {
    return STATUS_REFUND_LABEL[value] ?? value;
}

export type RefundMetode = {
    id: number;
    /** The method's human label, never an account number. */
    label: string;
};

export type Refund = {
    id: number;
    booking_id: number;
    /** A `DECIMAL(14,2)` string. */
    jumlah: string;
    /** `null` when the payment's method row could not be resolved. */
    metode: RefundMetode | null;
    status: StatusRefund;
    /** A UTC instant; rendered in the device zone with its label. */
    dibuat_at: Iso;
};

export type RefundPasienFilters = {
    page: number;
    per_page: number;
};

/** `PasienRecordAccess::PER_PAGE_MAX`, the project ceiling this client requests. */
export const REFUND_PER_PAGE = 100;

export async function fetchRefundPasien(filters: RefundPasienFilters) {
    return request<{ refund: Refund[] }>('pasien/refund', {
        searchParams: {
            page: filters.page,
            per_page: filters.per_page,
        },
    });
}

export const refundPasienQueryKey = ['v1', 'pasien', 'refund'] as const;

/**
 * Read the patient's whole refund history, one page at a time.
 *
 * The booking list the cards are matched against is itself paginated, and a refund for a
 * row on page 3 must not vanish because the list only asked page 1 of refunds. The first
 * response's `meta.last_page` is the server's own count, so the loop is bounded by the
 * server's answer rather than by a client guess; each page is requested at the project
 * ceiling (`PER_PAGE_MAX = 100`), so the ordinary patient pays exactly one request.
 *
 * The walk is sequential on purpose: a burst of parallel page reads against an unstable
 * connection is the case F12 is written for, and one request is the honest cost of the
 * common case.
 */
async function fetchSemuaRefund(): Promise<Refund[]> {
    const pertama = await fetchRefundPasien({
        page: 1,
        per_page: REFUND_PER_PAGE,
    });

    const semua: Refund[] = [...pertama.data.refund];
    const halamanTerakhir = pertama.meta?.last_page ?? 1;

    for (let halaman = 2; halaman <= halamanTerakhir; halaman += 1) {
        const berikutnya = await fetchRefundPasien({
            page: halaman,
            per_page: REFUND_PER_PAGE,
        });

        semua.push(...berikutnya.data.refund);
    }

    return semua;
}

export function refundPasienOptions() {
    return queryOptions({
        queryKey: refundPasienQueryKey,
        queryFn: fetchSemuaRefund,
    });
}
