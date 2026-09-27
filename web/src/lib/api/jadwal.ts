import { queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import type { Iso, TipeLayananJadwal } from '@/lib/api/types';

/**
 * The two doctor schedule endpoints: `GET /api/v1/dokter/{dokter}/jadwal` and
 * `GET /api/v1/dokter/{dokter}/slot?tanggal=YYYY-MM-DD`.
 *
 * ## These two routes DO NOT EXIST YET, and the client is written as though they will
 *
 * This is the one place in the web client where a brief's claim had to be measured rather
 * than believed. Measured, on a live `php artisan serve` against the dev database:
 *
 * ```
 * GET /api/v1/dokter/1/jadwal            -> 404 {"success":false,"message":"Resource not found.","errors":{}}
 * GET /api/v1/dokter/1/slot?tanggal=...  -> 404 {"success":false,"message":"Resource not found.","errors":{}}
 * php artisan route:list --path=api/v1/dokter
 *   GET|HEAD api/v1/dokter           dokter.index
 *   GET|HEAD api/v1/dokter/booking   dokter.booking.index
 *   GET|HEAD api/v1/dokter/{dokter}  dokter.show
 *   Showing [3] routes
 * ```
 *
 * `DokterController` has exactly three actions - `index`, `show`, `spesialisasiIndex` -
 * and `routes/api.php` registers no `jadwal` or `slot` path. The four rules are fully
 * implemented in `App\Services\Booking\SlotAvailabilityService` and the service is
 * endpoint-agnostic, but nothing publishes it over HTTP. This is recorded as finding F1
 * in `.omo/evidence/task-26-sehatly.md`, which states it as a deliberate scope decision
 * for that todo rather than an oversight.
 *
 * So the paths below are the **contract the service and the plan already name**, not
 * invented ones, and the response types are transcribed from the service's own
 * `@return list<array{...}>` annotations rather than guessed. When the routes land, this
 * module works unchanged.
 *
 * ## Why the 404 is a first-class state rather than an error to swallow
 *
 * A 404 from a route that is not registered and a 404 from a doctor that does not exist are
 * the same status and completely different facts. {@link jadwalEndpointBelumTerdaftar}
 * tells them apart by the envelope's `message` - Laravel's router answers
 * `Resource not found.` for an unmatched path and `DokterController` answers its own
 * Indonesian sentence for a doctor who is absent, unverified, inactive, off telemedicine
 * or STR-expired - and the slot picker turns the first into a named panel and the second
 * into the existing `NotFoundState`. Swallowing either into a generic red box would make
 * the difference between "the feature is not deployed" and "that doctor is not bookable"
 * invisible, and those two need opposite actions.
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

/**
 * Is this 404 the *route* being absent, rather than the row being absent?
 *
 * The discriminator is Laravel's own router message, `"Resource not found."`, against
 * `DokterController`'s Indonesian sentences. `DokterController::show()` answers 404 for
 * six separate situations - never existed, unverified, inactive, not on telemedicine, STR
 * expired, soft-deleted - and deliberately publishes one generic message so an anonymous
 * caller cannot enumerate the verification state of every doctor account. So the message
 * is a reliable signal here even though it is deliberately uninformative to the user.
 */
export function jadwalEndpointBelumTerdaftar(error: unknown): boolean {
    if (
        typeof error !== 'object' ||
        error === null ||
        !('status' in error) ||
        !('message' in error)
    ) {
        return false;
    }

    const candidate = error as { status: unknown; message: unknown };

    return (
        candidate.status === 404 && candidate.message === 'Resource not found.'
    );
}

/** `Iso` re-export so a caller does not reach into `lib/api/types` for a date string. */
export type { Iso };
