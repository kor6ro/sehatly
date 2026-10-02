import type { TipeNotifikasi, UserTipe } from '@/lib/api/types';

/**
 * F11's deep-link whitelist: `notifikasi.tautan` is an API path, and exactly four of
 * its shapes have an SPA destination.
 *
 * ## Why a whitelist and not a `startsWith`
 *
 * `NotificationService` publishes `/api/v1/booking/1001` and friends, which are backend
 * paths. `navigate(row.tautan)` would leave the SPA for the API origin and land on a
 * JSON body or a 404. Worse, `tautan` is a server string that a compromised or buggy
 * producer could set to `https://jahat.example`, `javascript:alert(1)` or
 * `//jahat.example` - all of which a naive router would treat as a destination. The
 * patterns below are **anchored and digit-only**, so nothing except a path that exactly
 * matches the §4.4 table ever becomes a link. Protocol-relative paths, absolute URLs,
 * query strings and unknown API paths all fail to match and the row is rendered without
 * a link.
 *
 * ## The id comes from the path, and only after the path matched
 *
 * The table's "payload id hanya dipakai setelah path cocok" rule is implemented by
 * `exec` on an already-anchored pattern: the capture group is digits, and it cannot be
 * reached at all unless the whole string matched. Nothing here reads `payload`, so a
 * producer that puts a wrong id in the payload cannot steer the route.
 */

const POLA_BOOKING = /^\/api\/v1\/booking\/([0-9]+)$/;
const POLA_INVOICE = /^\/api\/v1\/invoice\/([0-9]+)$/;
const POLA_RESEP = /^\/api\/v1\/resep\/([0-9]+)$/;
const POLA_KONSULTASI = /^\/api\/v1\/konsultasi\/([0-9]+)\/chat$/;

/**
 * The two booking producers reach different audiences: a patient's own booking lives at
 * `/booking`, while a doctor's incoming booking lives at `/dokter/booking`. The API
 * cannot tell the client which one a row is for, so the current account decides. The
 * account type is read from `GET /me`; while it is unknown this answers `null` and the
 * row stays non-clickable rather than guessing a route the account may not own.
 */
export function ruteDariTautan(
    tautan: string | null,
    tipePengguna: UserTipe | undefined,
): string | null {
    if (tautan === null) {
        return null;
    }

    if (POLA_BOOKING.test(tautan)) {
        if (tipePengguna === 'dokter') {
            return '/dokter/booking';
        }

        if (tipePengguna === 'pasien') {
            return '/booking';
        }

        return null;
    }

    if (POLA_INVOICE.test(tautan)) {
        return '/pembayaran';
    }

    const resep = POLA_RESEP.exec(tautan);

    if (resep !== null) {
        return `/resep/${resep[1]}`;
    }

    const konsultasi = POLA_KONSULTASI.exec(tautan);

    if (konsultasi !== null) {
        return `/konsultasi/${konsultasi[1]}`;
    }

    return null;
}

/**
 * The index page named by the §4.3 fallback sentence, per row type.
 *
 * A row whose `tautan` is a real string but matches none of the four patterns gets
 * "Tidak dapat dibuka langsung. Buka dari halaman {X}." - and the page name comes from
 * the row's own `tipe` rather than from a guess about the path. Types with no producer
 * (`lab`, `promo`, `sistem`) and no index route answer `null`, and the sentence stops
 * after "Tidak dapat dibuka langsung."
 */
const HALAMAN_INDEKS: Partial<Record<TipeNotifikasi, string>> = {
    booking: 'Booking',
    pembayaran: 'Pembayaran',
    resep: 'Resep',
    chat: 'Konsultasi',
};

export function halamanIndeksTipe(tipe: TipeNotifikasi): string | null {
    return HALAMAN_INDEKS[tipe] ?? null;
}
