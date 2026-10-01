import { queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import type { Iso, TipeLayananJadwal } from '@/lib/api/types';

/**
 * The two doctor schedule endpoints: `GET /api/v1/dokter/{dokter}/jadwal` and
 * `GET /api/v1/dokter/{dokter}/slot?tanggal=YYYY-MM-DD`.
 *
 * ## Both routes exist, measured live on 2026-10-01
 *
 * ```
 * GET /api/v1/dokter/{dokter}/jadwal            -> 200
 * GET /api/v1/dokter/{dokter}/slot?tanggal=...  -> 200
 *   {"data":{"tanggal":"...","timezone":"Asia/Jakarta","slots":[...16 rows...]}}
 * ```
 *
 * An earlier revision of this module claimed the routes were unregistered and carried a
 * `jadwalEndpointBelumTerdaftar()` discriminator plus a free-text time fallback in the
 * slot picker. That measurement was stale: `DokterController::jadwal()` and `::slot()`
 * are registered in `routes/api.php` and answer 200. The discriminator is deleted rather
 * than kept as dead code, and `web/ux/patterns/F05.md` §12 records the correction.
 *
 * ## The 404 that remains is the doctor's, and it is not distinguishable from a route 404
 *
 * `DokterController::slot()` answers `ApiResponse::error('Resource not found.', ...)` for
 * all six ineligibility reasons, and the kernel publishes the same body for an unmatched
 * path. The two are therefore the same status **and the same message**, so no client-side
 * discriminator can tell them apart - which is the second reason the old one was wrong.
 * The slot picker renders `NotFoundState` for any 404 and lets the page's doctor-detail
 * gate own the primary "not bookable" answer.
 *
 * ## Why the response types are transcribed from the service
 *
 * The response types below are transcribed from `SlotAvailabilityService`'s own
 * `@return list<array{...}>` annotations rather than guessed, so a field the service adds
 * is a compile error at the consumer rather than an `undefined` at runtime.
 */

/**
 * The three `alasan` values `SlotAvailabilityService` publishes, in its own precedence
 * order: `libur` beats `lewat_waktu` beats `penuh`.
 *
 * `null` means the slot is available, and it is the only value that is not a string - so
 * `alasan === null` is the availability test and the union is closed. A fourth reason would
 * be a compile error at the label map rather than a slot silently rendering as "available".
 */
export type AlasanSlot = 'libur' | 'lewat_waktu' | 'penuh';

/**
 * One row of `GET /dokter/{dokter}/slot`, transcribed from
 * `SlotAvailabilityService::getSlotTerbuka()`'s `@return` annotation at
 * `app/Services/Booking/SlotAvailabilityService.php:399`.
 *
 * `jadwal_id` is not in the plan's published shape; the service carries it deliberately
 * (its finding F8) because two windows on the same weekday are both honoured and a client
 * otherwise cannot tell which row a 09:00 slot belongs to.
 *
 * `tipe_layanan` is the schedule row's **own** three-value enum,
 * `('online','klinik','home_visit')` at `:473` - not {@link TipeLayanan}, the four-value
 * booking enum. The service publishes it without narrowing, so it is typed as that union
 * or the raw string.
 */
export type Slot = {
    jadwal_id: number;
    /** `H:i:s`, Asia/Jakarta wall clock, uncast and never zone-converted. */
    jam_mulai: string;
    /** `H:i:s`. A partial trailing slot is never published, so this is always later. */
    jam_selesai: string;
    tipe_layanan: TipeLayananJadwal | string;
    /** `NULL` is meaningful: the column comment at `:473` reads "NULL = layanan online murni". */
    faskes_id: number | null;
    tersedia: boolean;
    /** `null` on an available slot. See {@link AlasanSlot}. */
    alasan: AlasanSlot | null;
};

export type SlotHari = {
    tanggal: TanggalSlot;
    timezone: 'Asia/Jakarta';
    slots: Slot[];
};

/**
 * `Y-m-d`. The CONSULTATION date, which is the day the doctor's `str_berlaku_sampai` is
 * compared against - never today. A slot availability answer for a date is only valid for
 * that date.
 */
export type TanggalSlot = string;

/**
 * One day of `GET /dokter/{dokter}/jadwal`, transcribed from
 * `getJadwal()`'s emitted array at `SlotAvailabilityService.php:360`.
 *
 * `getJadwal()` always returns all seven keys, empty arrays included, so "no rows" and
 * "no such key" are the same shape and the client cannot tell a day with no window from a
 * day that was never published. That is a deliberate contract of the service, recorded as
 * its finding F6.
 */
export type JadwalHari = {
    jadwal_id: number;
    /** `0` = Minggu .. `6` = Saturday, which is PHP's own `date('w')` numbering. */
    hari: number;
    tipe_layanan: TipeLayananJadwal | string;
    faskes_id: number | null;
    jam_mulai: string;
    jam_selesai: string;
    durasi_slot_menit: number;
    /**
     * The DDL's own `null`, **not** a substituted 1, so a client can tell a genuinely
     * uncapped window from one it must assume holds a single seat.
     */
    kuota_per_sesi: number | null;
};

export type JadwalMinggu = Record<string, JadwalHari[]>;

/**
 * The seven weekday keys `getJadwal()` publishes, in its own order. Typed as a record so a
 * lookup of a day the server omitted reads as `undefined` rather than as an empty array,
 * which are different facts.
 */
export const HARI_NAMES: ReadonlyArray<string> = [
    'Minggu',
    'Senin',
    'Selasa',
    'Rabu',
    'Kamis',
    'Jumat',
    'Sabtu',
];

/** Why a slot is not bookable, in the UI's words. `null` availability has no reason. */
export function labelAlasanSlot(value: AlasanSlot): string {
    switch (value) {
        case 'libur':
            return 'Dokter libur pada tanggal ini.';
        case 'lewat_waktu':
            return 'Jam ini sudah lewat.';
        case 'penuh':
            return 'Slot sudah penuh.';
        default:
            return value;
    }
}

export async function fetchSlotDokter(
    dokterId: string,
    tanggal: TanggalSlot,
) {
    return request<SlotHari>(`dokter/${dokterId}/slot`, {
        searchParams: { tanggal },
    });
}

export async function fetchJadwalDokter(dokterId: string) {
    return request<{ jadwal: JadwalMinggu }>(`dokter/${dokterId}/jadwal`);
}

export const slotQueryKey = ['v1', 'dokter', 'slot'] as const;

export const jadwalQueryKey = ['v1', 'dokter', 'jadwal'] as const;

/**
 * The slot query, keyed by doctor and date.
 *
 * `retry: 0` because a 404 is the endpoint's measured answer, not a blip, and the shared
 * 4xx rule in `lib/query-client.ts` agrees. Without it the picker would spend two retries
 * re-asking for a route that does not exist before showing anything.
 */
export function slotOptions(dokterId: string, tanggal: TanggalSlot) {
    return queryOptions({
        queryKey: [...slotQueryKey, dokterId, tanggal],
        queryFn: () => fetchSlotDokter(dokterId, tanggal),
        retry: 0,
    });
}

export function jadwalOptions(dokterId: string) {
    return queryOptions({
        queryKey: [...jadwalQueryKey, dokterId],
        queryFn: () => fetchJadwalDokter(dokterId),
        retry: 0,
    });
}

/** `Iso` re-export so a caller does not reach into `lib/api/types` for a date string. */
export type { Iso };
