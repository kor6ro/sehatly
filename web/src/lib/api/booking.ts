import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import { slotQueryKey } from '@/lib/api/jadwal';
import type {
    Booking,
    DibatalkanOleh,
    StatusBooking,
    TipeLayanan,
} from '@/lib/api/types';

/**
 * Module 2's four booking endpoints, all behind `auth:sanctum`.
 *
 * | method | path | guard | success |
 * | --- | --- | --- | --- |
 * | `POST` | `/api/v1/booking` | `permission:booking.buat` | 201 |
 * | `GET` | `/api/v1/pasien/booking` | `permission:booking.lihat` | 200 + `meta` |
 * | `GET` | `/api/v1/dokter/booking` | `permission:booking.lihat` + `tipe:dokter` | 200 + `meta` |
 * | `PUT` | `/api/v1/booking/{id}/batalkan` | `permission:booking.batal` | 200 |
 *
 * ## Why a create is never retried, stated once and enforced in two places
 *
 * The shared `QueryClient` already sets `mutations: { retry: 0 }`, and every write here
 * also passes `retry: 0` to `request()`. The duplication is deliberate and is not
 * belt-and-braces: a replayed `POST /booking` is not a duplicate read, it is a **second
 * booking**. The server would answer the first with 201 and the second with 422
 * `slot penuh`, so a retry after a response the client never saw leaves the user with a
 * booking they did not intend and an error they cannot explain.
 *
 * ## The two refusals that are not failures
 *
 * - **403** is a capability refusal, about the caller. `GET /dokter/booking` carries
 *   `tipe:dokter`, and a patient account calling it gets 403 with Laravel's own
 *   `This action is unauthorized.` - measured, not assumed. No retry can change it, so
 *   `ErrorState` withholds its retry button for a 403 and the doctor page renders
 *   `ForbiddenState` instead.
 * - **404** is about the row. `BookingService::batalkan()` scopes the lookup to the
 *   caller's own `pasien` row first and then to their own `dokter` row, so another
 *   tenant's booking is a 404 and existence is not leaked. Measured: `PUT
 *   /booking/999999/batalkan` answered 404 `Resource not found.`
 */

/**
 * `booking.tipe_layanan`, in the DDL's order, with the wording the UI shows.
 *
 * A **DDL transcription, not an API response**, and labelled as such at every use - the
 * same distinction `HUBUNGAN_KELUARGA` in `lib/api/anggota-keluarga.ts` draws. There is no
 * reference endpoint for this ENUM (`route:list --path=api/v1` carries exactly one,
 * `master-spesialisasi`), and `StoreBookingRequest` applies `Rule::in(TIPE_LAYANAN)` to it,
 * so the client can only ever usefully offer these four and the server would 422 anything
 * else. The server's spelling is read straight off the wire in every failure it produces:
 * `{"errors":{"tipe_layanan":["The selected tipe layanan is invalid."]}}`.
 */
export const TIPE_LAYANAN: ReadonlyArray<TipeLayanan> = [
    'chat',
    'video_call',
    'kunjungan_klinik',
    'home_visit',
];

const TIPE_LAYANAN_LABEL: Record<TipeLayanan, string> = {
    chat: 'Chat',
    video_call: 'Video call',
    kunjungan_klinik: 'Kunjungan klinik',
    home_visit: 'Kunjungan rumah',
};

export function labelTipeLayanan(value: TipeLayanan): string {
    return TIPE_LAYANAN_LABEL[value] ?? value;
}

/**
 * `booking.status`, all eight values in the DDL's order, with their labels.
 *
 * Also a DDL transcription, for the same reason as {@link TIPE_LAYANAN}: the server's
 * `IndexBookingRequest` applies `Rule::in(STATUS_SEMUA)` to the `status` filter, so an
 * out-of-enum value is a 422 naming the field (measured:
 * `{"errors":{"status":["The selected status is invalid."]}}`) rather than a silently
 * empty list. The dropdown therefore sends only members of this array.
 */
export const STATUS_BOOKING: ReadonlyArray<StatusBooking> = [
    'menunggu_pembayaran',
    'terjadwal',
    'check_in',
    'berlangsung',
    'selesai',
    'dibatalkan',
    'no_show',
    'kadaluarsa',
];

const STATUS_BOOKING_LABEL: Record<StatusBooking, string> = {
    menunggu_pembayaran: 'Menunggu pembayaran',
    terjadwal: 'Terjadwal',
    check_in: 'Sudah check in',
    berlangsung: 'Berlangsung',
    selesai: 'Selesai',
    dibatalkan: 'Dibatalkan',
    no_show: 'Tidak hadir',
    kadaluarsa: 'Kadaluarsa',
};

export function labelStatusBooking(value: StatusBooking): string {
    return STATUS_BOOKING_LABEL[value] ?? value;
}

/**
 * `BookingRequest::STATUS_TIDAK_BISA_DIBATALKAN`, verbatim: the four a cancel refuses.
 *
 * Two are already over (`berlangsung`, `selesai`) and two are terminal states that have
 * already released the slot (`dibatalkan`, `kadaluarsa`) - cancelling either of the latter
 * would overwrite the fact of *how* the booking ended. The server refuses with
 * `{"errors":{"status":["Booking dengan status tersebut tidak dapat dibatalkan."]}}`,
 * measured.
 *
 * This is the **server's** guard and is kept as the mirror of
 * `BookingRequest::STATUS_TIDAK_BISA_DIBATALKAN`, but it is deliberately **not** what
 * decides whether the UI renders a cancel control. See {@link STATUS_BISA_DIBATALKAN}.
 */
export const STATUS_TIDAK_BISA_DIBATALKAN: ReadonlyArray<StatusBooking> = [
    'berlangsung',
    'selesai',
    'dibatalkan',
    'kadaluarsa',
];

/**
 * The two statuses the F12 pattern allows a patient to cancel from, and the only two the
 * UI renders a cancel control for (F12 §10 AC-2).
 *
 * ## Why this is narrower than the server's guard, and why that is not a lie
 *
 * `BookingService::batalkan()` currently accepts `check_in` and `no_show` too, and F12
 * §7 #9-#10 records both as defects: a `check_in` booking can already start a consultation
 * and a `no_show` cancel overwrites the fact that the patient did not attend, without
 * releasing the slot. The pattern's answer is to stop OFFERING those two while the backend
 * guard is corrected (Pertanyaan #4), not to keep a button whose outcome is a corrupted
 * record.
 *
 * The server still re-checks its own, wider guard inside the transaction, so a stale
 * client cannot force anything; this list only decides what is drawn.
 */
export const STATUS_BISA_DIBATALKAN: ReadonlyArray<StatusBooking> = [
    'menunggu_pembayaran',
    'terjadwal',
];

/**
 * `SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI` - the two that RELEASE a slot.
 *
 * The other six consume it. Not used to gate a button, but it is the pair the status badge
 * treats as "the slot is free again", which is a different and more useful thing to tell a
 * patient than a colour.
 */
export const STATUS_MELEPAS_SLOT: ReadonlyArray<StatusBooking> = [
    'dibatalkan',
    'kadaluarsa',
];

export function labelDibatalkanOleh(value: DibatalkanOleh | null): string {
    switch (value) {
        case 'pasien':
            return 'Dibatalkan oleh pasien';
        case 'dokter':
            return 'Dibatalkan oleh dokter';
        case 'sistem':
            return 'Dibatalkan oleh sistem';
        default:
            return '-';
    }
}

/**
 * Whether the UI may offer a cancel (and a reschedule) for this status.
 *
 * Reads {@link STATUS_BISA_DIBATALKAN}, not the server's wider guard - see that constant
 * for why `check_in` and `no_show` are hidden. The same predicate gates both row actions,
 * because the reschedule endpoint does not exist yet and a reschedule is not a thing to
 * offer for a booking that cannot be cancelled.
 */
export function bisaDibatalkan(status: StatusBooking): boolean {
    return STATUS_BISA_DIBATALKAN.includes(status);
}

// ============================================================================
// The request body
// ============================================================================

/**
 * `POST /api/v1/booking`'s writable subset, which is `StoreBookingRequest::rules()` exactly.
 *
 * Two absences are load-bearing and are not oversights:
 *
 * - **`pasien_id` is not here at all.** The server marks it `prohibited`, not merely
 *   absent from the rules, so a client that sent it would be told so rather than having it
 *   silently dropped. The tenant key is written from the caller's own `pasien` row.
 * - **`nomor_antrian` is not here either.** Nothing enforces the column, so there is no
 *   value for a client to send; the create leaves it `null` and the server publishes
 *   `null`.
 */
export type CreateBookingInput = {
    dokter_id: number;
    /**
     * Optional. When present it must name a `dokter_jadwal` row **of this doctor** - a
     * mismatch is a 422 on `jadwal_id` with the message "Jadwal yang dipilih bukan milik
     * dokter tersebut." (measured). The client never sends it: there is no endpoint that
     * publishes which schedule row a slot came from, so there is no honest value to put
     * here, and the server resolves the geometry from the published slot instead.
     */
    jadwal_id?: number;
    tipe_layanan: TipeLayanan;
    /** `Y-m-d`. `date_format:Y-m-d`, so a rollover like `2026-13-45` is a 422, not a date. */
    tanggal_kunjungan: string;
    /**
     * `H:i:s`, Asia/Jakarta wall clock, unconverted. The server derives `slot_selesai`
     * itself - from the published slot when one exists, and otherwise from
     * `dokter.durasi_default_menit` for a doctor with no `dokter_jadwal` row. Measured:
     * a `09:00:00` on a schedule-less doctor came back with `slot_selesai: "09:15:00"`.
     */
    slot_mulai: string;
    keluhan?: string | null;
    /** Each element needs a non-empty `nama` (max 150) and a valid `url` (max 2048). */
    lampiran_keluhan?: Array<{ nama: string; url: string }>;
    /** Scoped to the caller's own family by a closure rule, so a foreign id is a 422. */
    anggota_keluarga_id?: number | null;
    is_rujukan?: boolean;
    is_konsultasi_lanjutan?: boolean;
};

/** `PUT /api/v1/booking/{id}/batalkan`. The reason is the only input and it is optional. */
export type BatalkanBookingInput = {
    alasan_pembatalan?: string | null;
};

/**
 * `IndexBookingRequest`'s query string: `status`, `per_page`, `page`.
 *
 * `per_page` is capped at `PasienRecordAccess::PER_PAGE_MAX` server-side, and the returned
 * `meta.per_page` is the size that was **actually applied**, so the client reads its
 * pagination from `meta` and never from what it sent.
 */
export type BookingPasienFilters = {
    page: number;
    per_page: number;
    status?: StatusBooking;
};

/** `IndexDokterBookingRequest` adds a consultation-date filter the patient list lacks. */
export type BookingDokterFilters = BookingPasienFilters & {
    /** `date_format:Y-m-d`; an overflowing date is a 422 on the field, not an empty list. */
    tanggal?: string;
};

// ============================================================================
// The four calls
// ============================================================================

export async function createBooking(input: CreateBookingInput) {
    return request<{ booking: Booking }>('booking', {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

export async function fetchBookingPasien(filters: BookingPasienFilters) {
    return request<{ booking: Booking[] }>('pasien/booking', {
        searchParams: {
            page: filters.page,
            per_page: filters.per_page,
            ...(filters.status === undefined ? {} : { status: filters.status }),
        },
    });
}

export async function fetchBookingDokter(filters: BookingDokterFilters) {
    return request<{ booking: Booking[] }>('dokter/booking', {
        searchParams: {
            page: filters.page,
            per_page: filters.per_page,
            ...(filters.status === undefined ? {} : { status: filters.status }),
            ...(filters.tanggal === undefined || filters.tanggal === ''
                ? {}
                : { tanggal: filters.tanggal }),
        },
    });
}

export async function batalkanBooking(
    id: number,
    input: BatalkanBookingInput = {},
) {
    return request<{ booking: Booking }>(`booking/${id}/batalkan`, {
        method: 'PUT',
        json: input,
        retry: 0,
    });
}

// ============================================================================
// Cache keys and the invalidation graph
// ============================================================================

export const bookingQueryKey = ['v1', 'booking'] as const;

export const bookingPasienQueryKey = ['v1', 'booking', 'pasien'] as const;

export const bookingDokterQueryKey = ['v1', 'booking', 'dokter'] as const;

export function bookingPasienOptions(filters: BookingPasienFilters) {
    return queryOptions({
        queryKey: [...bookingPasienQueryKey, filters],
        queryFn: () => fetchBookingPasien(filters),
    });
}

export function bookingDokterOptions(filters: BookingDokterFilters) {
    return queryOptions({
        queryKey: [...bookingDokterQueryKey, filters],
        queryFn: () => fetchBookingDokter(filters),
    });
}

/**
 * A create invalidates the slot query as well as the two lists.
 *
 * The slot availability is a function of how many bookings exist for that doctor, date
 * and window, so a create that succeeded has just made one of the cached slots stale. The
 * doctor's own list is invalidated too, because the row is now visible from both sides.
 */
export function createBookingMutation() {
    return mutationOptions({
        mutationFn: createBooking,
        onSuccess: (_result, input) => {
            void queryClient.invalidateQueries({
                queryKey: bookingQueryKey,
            });

            void queryClient.invalidateQueries({
                queryKey: [...slotQueryKey, input.dokter_id],
            });
        },
    });
}

/**
 * A cancel invalidates the same three prefixes, plus that doctor's slots.
 *
 * The invalid slot is the reason this is not a list-only refresh: a cancelled booking
 * stops consuming its window, so the slot it held is genuinely available again. The
 * doctor's id is not on the row this client fetched for a patient-side cancel - the cancel
 * mutation is shared with the doctor-side list, where it is - so the slot prefix is
 * invalidated for whichever ids the result carries.
 */
export function batalkanBookingMutation() {
    return mutationOptions({
        mutationFn: ({ id, input }: { id: number; input?: BatalkanBookingInput }) =>
            batalkanBooking(id, input ?? {}),
        onSuccess: (result) => {
            void queryClient.invalidateQueries({
                queryKey: bookingQueryKey,
            });

            void queryClient.invalidateQueries({
                queryKey: [...slotQueryKey, result.data.booking.dokter_id],
            });
        },
    });
}
