import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import type { Iso } from '@/lib/api/types';

/**
 * `GET|POST /api/v1/pengingat` and `PUT|DELETE /api/v1/pengingat/{id}`.
 *
 * ## One resource, four routes, and no "taken" endpoint
 *
 * There is no `PUT /pengingat/{id}/sudah-diminum`, no snooze route and no skip route:
 * `PengingatController` publishes exactly list, create, update and delete. A row action
 * therefore consists of an edit, a `status` transition (`aktif` / `nonaktif`, and
 * `selesai` is the same PUT) and a hard delete. Nothing else is offered, because nothing
 * else exists.
 *
 * ## `waktu` is a list of `HH:MM` wall clocks in `zona_waktu`, never an instant
 *
 * The scheduler compares the literal `HH:MM` string against the reminder's own zone
 * (`PengingatRequest::FORMAT_WAKTU`), so the client sends what a human reads and never
 * converts it. `zona_label` is the API's own `WIB`/`WITA`/`WIT`, derived server-side
 * from the stored IANA name, so the UI does not carry a second mapping.
 *
 * ## `status` is system-owned on create
 *
 * `StorePengingatRequest` declares `status` `prohibited` - a new reminder is always
 * `aktif`. Only the update route accepts it, which is why {@link PengingatInput} has no
 * `status` member at all: pausing is a separate call with its own payload type.
 */

/** `pengingat.jenis`, the two DDL values. */
export type JenisPengingat = 'obat' | 'janji_temu';

/** `pengingat.status`, the three DDL values. */
export type StatusPengingat = 'aktif' | 'nonaktif' | 'selesai';

/** `ZonaWaktu`, the three IANA zones the surface accepts. */
export type ZonaPengingat = 'Asia/Jakarta' | 'Asia/Makassar' | 'Asia/Jayapura';

/** One `pengingat` row, transcribed from `PengingatResource`. */
export type Pengingat = {
    id: number;
    jenis: JenisPengingat;
    judul: string;
    keterangan: string | null;
    obat_id: number | null;
    booking_id: number | null;
    dosis: string | null;
    jumlah_per_hari: number | null;
    /** `Y-m-d`, a calendar date and never zone-shifted. */
    tanggal_mulai: string | null;
    lama_hari: number | null;
    /** Non-empty `HH:MM` list; the service sorts and de-duplicates it. */
    waktu: string[];
    zona_waktu: string;
    /** The `WIB`/`WITA`/`WIT` label, derived server-side; `null` on an unknown value. */
    zona_label: string | null;
    status: StatusPengingat;
    dibuat_at: Iso;
    diubah_at: Iso;
};

/**
 * The writable half of a create or update.
 *
 * `status` is absent on purpose: create refuses it, and pausing is
 * {@link updatePengingatStatus}. `booking_id`/`obat_id` are omitted by this client - it
 * does not link a manual reminder to a catalogue row, and an omitted key is left alone
 * by the update merge.
 */
export type PengingatInput = {
    jenis: JenisPengingat;
    judul: string;
    dosis?: string | null;
    jumlah_per_hari?: number | null;
    tanggal_mulai: string;
    lama_hari?: number | null;
    waktu: string[];
    zona_waktu: ZonaPengingat;
};

/**
 * A partial update, plus the one field create forbids.
 *
 * `PUT` accepts `status` (`UpdatePengingatRequest`) while `POST` refuses it, so the
 * status transition gets its own optional member here rather than a cast at the call
 * site.
 */
export type PengingatUpdateInput = Partial<PengingatInput> & {
    status?: StatusPengingat;
};

export const LABEL_JENIS_PENGINGAT: Record<JenisPengingat, string> = {
    obat: 'Obat',
    janji_temu: 'Janji temu',
};

export const LABEL_STATUS_PENGINGAT: Record<StatusPengingat, string> = {
    aktif: 'Aktif',
    nonaktif: 'Nonaktif',
    selesai: 'Selesai',
};

export const ZONA_PENGINGAT: ReadonlyArray<{ nilai: ZonaPengingat; label: string }> = [
    { nilai: 'Asia/Jakarta', label: 'WIB' },
    { nilai: 'Asia/Makassar', label: 'WITA' },
    { nilai: 'Asia/Jayapura', label: 'WIT' },
];

export type PengingatFilters = {
    page?: number;
    per_page?: number;
    status?: StatusPengingat;
};

export const pengingatQueryKey = ['v1', 'pengingat'] as const;

export async function fetchPengingat(filters: PengingatFilters = {}) {
    return request<{ pengingat: Pengingat[] }>('pengingat', {
        retry: 0,
        searchParams: {
            ...(filters.page === undefined ? {} : { page: filters.page }),
            ...(filters.per_page === undefined ? {} : { per_page: filters.per_page }),
            ...(filters.status === undefined ? {} : { status: filters.status }),
        },
    });
}

export async function createPengingat(input: PengingatInput) {
    return request<{ pengingat: Pengingat }>('pengingat', {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

export async function updatePengingat(id: number, input: PengingatUpdateInput) {
    return request<{ pengingat: Pengingat }>(`pengingat/${id}`, {
        method: 'PUT',
        json: input,
        retry: 0,
    });
}

/** The pause/resume call: the only field the endpoint needs for a status transition. */
export async function updatePengingatStatus(id: number, status: StatusPengingat) {
    return updatePengingat(id, { status });
}

export async function deletePengingat(id: number) {
    return request<{ dihapus: boolean }>(`pengingat/${id}`, {
        method: 'DELETE',
        retry: 0,
    });
}

export function pengingatOptions(filters: PengingatFilters = {}) {
    return queryOptions({
        queryKey: [...pengingatQueryKey, filters],
        queryFn: () => fetchPengingat(filters),
        retry: false,
    });
}

/**
 * Every write invalidates the whole reminder prefix.
 *
 * A create can change the grouping of a day and an update can move a row between days,
 * so a targeted single-row cache patch would be wrong the moment the list is grouped by
 * date. The refetch is the honest answer; `meta` on the list is small.
 */
function invalidatePengingat(): void {
    void queryClient.invalidateQueries({ queryKey: pengingatQueryKey });
}

export function createPengingatMutation() {
    return mutationOptions({
        mutationFn: createPengingat,
        onSuccess: invalidatePengingat,
    });
}

export function updatePengingatMutation() {
    return mutationOptions({
        mutationFn: ({ id, input }: { id: number; input: PengingatUpdateInput }) =>
            updatePengingat(id, input),
        onSuccess: invalidatePengingat,
    });
}

export function updatePengingatStatusMutation() {
    return mutationOptions({
        mutationFn: ({ id, status }: { id: number; status: StatusPengingat }) =>
            updatePengingatStatus(id, status),
        onSuccess: invalidatePengingat,
    });
}

export function deletePengingatMutation() {
    return mutationOptions({
        mutationFn: deletePengingat,
        onSuccess: invalidatePengingat,
    });
}
