import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request, type ApiMeta } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import type { Notifikasi, TipeNotifikasi } from '@/lib/api/types';

/**
 * The notification centre's three routes.
 *
 * | method | path | guard | success |
 * | --- | --- | --- | --- |
 * | `GET` | `/api/v1/notifikasi` | `permission:notifikasi.lihat` | 200 + `meta` |
 * | `PUT` | `/api/v1/notifikasi/{id}/baca` | same | 200, no body read |
 * | `PUT` | `/api/v1/notifikasi/baca-semua` | same | 200, no body read |
 *
 * ## `meta.unread` IS the badge, and it is not a page total
 *
 * `NotifikasiController::index()` adds `unread` to the standard pagination block from a
 * **separate** count query scoped to the caller's own `dibaca_at IS NULL` rows, so it is
 * invariant across `?unread` and `?page`. A badge built from `meta.total` would show the
 * page size, and a badge built from the rows on screen would show at most `per_page`.
 *
 * ## No `channel`, so no per-channel control
 *
 * `notifikasi` has no channel column and no delivery-state column, so a "delivered" or
 * "queued" badge would be unrepresentable and is not offered.
 *
 * ## PDP consent is deliberately NOT a gate here
 *
 * `PersetujuanPdpConsent::require()` is called from exactly one place in the application,
 * `SuratKeteranganService:255`, and it gates REFERRAL LETTERS. `NotificationService`
 * contains no consent check and its own docblock records the scope limit. Gating the
 * notification centre behind `berbagi_data_medis` would be inventing a rule the API does not
 * have, and would hide a patient's own booking confirmations behind a consent they never
 * gave.
 */

/** The badge cadence. 30 s, and the tab is hidden-proof rather than background-proof. */
export const NOTIFIKASI_REFETCH_MS = 30_000;

export type NotifikasiFilters = {
    page: number;
    per_page: number;
    /**
     * A THREE-state filter, and it is a string on the wire.
     *
     * `IndexNotifikasiRequest` applies `Rule::in(['true','1','false','0'])` to a **string**,
     * deliberately not Laravel's `boolean` rule, so `?unread=true` must be sent as the
     * literal string `"true"`. Omitted means no filter at all, which is not the same as
     * `false` - `false` means read-only.
     */
    unread?: 'true' | 'false';
};

/** `meta` plus the badge. `unread` is the account-wide unread total, not a page count. */
export type NotifikasiMeta = ApiMeta & {
    unread: number;
};

/**
 * Read `meta.unread` off a pagination block that is typed `ApiMeta`.
 *
 * ## Why a reader and not a cast
 *
 * `request()` types every response as `ApiResult<T>`, whose `meta` is the project-wide
 * `ApiMeta`, so the extra key the endpoint adds is invisible to the type system. Casting
 * `ApiMeta` to `NotifikasiMeta` would assert a field that a reader cannot check, and a
 * badge built on a lie shows a number the server never sent. This reads the key, checks it
 * really is a number, and answers `0` when it is absent - which is also the truthful
 * reading of an account with nothing unread.
 */
export function unreadDari(meta: ApiMeta | undefined): number {
    const unread = (meta as Record<string, unknown> | undefined)?.unread;

    return typeof unread === 'number' ? unread : 0;
}

/**
 * One read, one request.
 *
 * ## Why the automatic retries are off for this endpoint
 *
 * The screen exposes an explicit "Coba lagi", and `_global.md` §3 makes `ErrorState` +
 * "Coba lagi" the retry mechanism for a failed read. Leaving ky's one-retry and
 * react-query's two retries in place would turn one "Coba lagi" tap into up to six
 * requests for a 5xx, and would make "the button refetched once" unobservable. For a
 * 403 the retries were already refused by `shouldRetryQuery`; this closes the 5xx half.
 * Retrying a GET is safe by itself - it is the count and the error latency that are
 * wrong here.
 */
export async function fetchNotifikasi(filters: NotifikasiFilters) {
    return request<{ notifikasi: Notifikasi[] }>('notifikasi', {
        retry: 0,
        searchParams: {
            page: filters.page,
            per_page: filters.per_page,
            ...(filters.unread === undefined ? {} : { unread: filters.unread }),
        },
    });
}

export async function tandaiDibaca(id: number) {
    return request<{ notifikasi: Notifikasi }>(`notifikasi/${id}/baca`, { method: 'PUT' });
}

export async function tandaiSemuaDibaca() {
    return request<{ ditandai: number }>('notifikasi/baca-semua', { method: 'PUT' });
}

const TIPE_NOTIFIKASI_LABEL: Record<TipeNotifikasi, string> = {
    booking: 'Booking',
    pembayaran: 'Pembayaran',
    resep: 'Resep',
    chat: 'Konsultasi',
    lab: 'Laboratorium',
    promo: 'Promo',
    sistem: 'Sistem',
};

export function labelTipeNotifikasi(value: TipeNotifikasi): string {
    return TIPE_NOTIFIKASI_LABEL[value] ?? value;
}

export const notifikasiQueryKey = ['v1', 'notifikasi'] as const;

/** The unread badge query: page 1, a fixed small size, no filter. */
export const notifikasiBadgeQueryKey = [...notifikasiQueryKey, 'badge'] as const;

export function notifikasiOptions(filters: NotifikasiFilters) {
    return queryOptions({
        queryKey: [...notifikasiQueryKey, filters],
        queryFn: () => fetchNotifikasi(filters),
        /**
         * No automatic retry: the screen owns a single explicit "Coba lagi", and a 403
         * must not be replayed at all (`shouldRetryQuery` already refuses 4xx; this also
         * pins the 5xx case so one tap is one request).
         */
        retry: false,
    });
}

/**
 * The shell-level badge query.
 *
 * `refetchInterval` is the badge cadence the plan asks for, and `refetchIntervalInBackground`
 * is left at its default of `false` so a backgrounded tab stops asking rather than
 * polling all night. `GET /notifikasi` carries **no** `throttle:` middleware, so 30 s is
 * well inside anything the server would enforce - but the cadence is chosen for the badge,
 * not pushed to the limit.
 */
export function notifikasiBadgeOptions() {
    return queryOptions({
        queryKey: notifikasiBadgeQueryKey,
        queryFn: () => fetchNotifikasi({ page: 1, per_page: 5 }),
        refetchInterval: NOTIFIKASI_REFETCH_MS,
        /** The badge fails quietly and retries on its own 30 s cadence, not on a loop. */
        retry: false,
    });
}

/**
 * Marking one read invalidates the WHOLE notification prefix, badge included.
 *
 * `NotifikasiController::baca()` is idempotent server-side - it keeps the FIRST `dibaca_at`
 * and writes no second `audit_log` row for an already-read notification - so a double click
 * is harmless on the data. It is not harmless in the cache: the badge lives under its own
 * key, and a targeted invalidation of the page query would leave `meta.unread` stale, so
 * the badge would keep showing a count the server no longer reports. Hence the prefix.
 */
export function tandaiDibacaMutation(onSelesai?: () => void) {
    return mutationOptions({
        mutationFn: tandaiDibaca,
        onSettled: () => {
            onSelesai?.();

            void queryClient.invalidateQueries({ queryKey: notifikasiQueryKey });
        },
    });
}

/**
 * Mark-all reads nothing back, so the badge must come from a refetch rather than a guess.
 *
 * `data.ditandai` is the number of rows **CHANGED**, not the inbox size, so a second call
 * answers `0`. A screen that set the badge to `0` locally would be right the first time and
 * wrong if another device had already read them all. `onSettled` rather than `onSuccess`
 * because the badge must also drop when the call fails - a stale count is a smaller lie
 * than none.
 */
export function tandaiSemuaDibacaMutation() {
    return mutationOptions({
        mutationFn: tandaiSemuaDibaca,
        onSettled: () => {
            void queryClient.invalidateQueries({ queryKey: notifikasiQueryKey });
        },
    });
}
