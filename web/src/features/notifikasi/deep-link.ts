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
 *
 * One case sits beside the table: a reminder notification (`payload.pengingat_id` from
 * `PengingatPengirim`) opens `/pengingat`. See {@link punyaPengingatId}.
 */

const POLA_BOOKING = /^\/api\/v1\/booking\/([0-9]+)$/;
const POLA_INVOICE = /^\/api\/v1\/invoice\/([0-9]+)$/;
const POLA_RESEP = /^\/api\/v1\/resep\/([0-9]+)$/;
const POLA_KONSULTASI = /^\/api\/v1\/konsultasi\/([0-9]+)\/chat$/;
/**
 * F04's review invitation, `NotificationService::ulasanDiminta()`'s `tautan`. The SPA
 * destination is the write surface at `/konsultasi/:id/ulasan`, not the consultation
 * itself: the whole point of the notification is that one tap starts the review.
 */
const POLA_ULASAN = /^\/api\/v1\/konsultasi\/([0-9]+)\/ulasan$/;

/**
 * The four whitelisted shapes, as one anchored alternation. Used only to decide whether a
 * reminder notification's `tautan` is a path this client already trusts; it never
 * produces a destination by itself.
 */
const POLA_TAUTAN_DIKENAL =
    /^\/api\/v1\/(?:booking|invoice|resep)\/[0-9]+$|^\/api\/v1\/konsultasi\/[0-9]+\/(?:chat|ulasan)$/;

/**
 * A reminder notification carries `payload.pengingat_id` from `PengingatPengirim`.
 *
 * The payload is used as a DISCRIMINATOR, never as a destination: the value must be a
 * positive integer, and the row still becomes a link only when its `tautan` is either
 * `null` (a drug reminder; the backend publishes no page path for one) or one of the four
 * whitelisted API paths (an appointment reminder, which carries the booking path). The id
 * itself is discarded - the destination is the constant `/pengingat` - so a payload that
 * lies about the id cannot steer the router.
 */
function punyaPengingatId(payload: unknown): boolean {
    if (typeof payload !== 'object' || payload === null) {
        return false;
    }

    const id = (payload as Record<string, unknown>).pengingat_id;

    return typeof id === 'number' && Number.isInteger(id) && id > 0;
}

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
    payload?: unknown,
): string | null {
    /**
     * A reminder notification opens the reminders screen. It is checked FIRST because an
     * appointment reminder also carries `/api/v1/booking/{id}` as its `tautan`, and the
     * row is a reminder to manage rather than the booking itself.
     */
    if (punyaPengingatId(payload) && (tautan === null || POLA_TAUTAN_DIKENAL.test(tautan))) {
        return '/pengingat';
    }

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

    const ulasan = POLA_ULASAN.exec(tautan);

    if (ulasan !== null) {
        return `/konsultasi/${ulasan[1]}/ulasan`;
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
