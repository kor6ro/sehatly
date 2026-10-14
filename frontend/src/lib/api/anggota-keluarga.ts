import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import type { AnggotaKeluarga } from '@/lib/api/types';

/**
 * `GET|POST /api/v1/pasien/anggota-keluarga` and `PUT|DELETE .../{id}`
 *
 * ## The two status codes that are not interchangeable
 *
 * | status | meaning | controller |
 * | --- | --- | --- |
 * | 403 | the caller is not a patient account at all | `PasienRecordAccess::ownPasien()` |
 * | 404 | the row is not the caller's, or does not exist | `anggotaKeluargaOrFail()` |
 *
 * A row belonging to another patient is a **404, never a 403**, so existence is not
 * leaked across tenants. The UI copy has to reflect that: a 403 says "this account cannot
 * act on a patient record", which is about the caller and is worth a different next step
 * than "that row is not there". Collapsing the two would tell a patient their own family
 * member does not exist.
 *
 * ## `pasien_id` is not in {@link AnggotaKeluargaInput}, and never will be
 *
 * It is written from the caller's own `pasien` row and read from nowhere. Its absence
 * from the request is the cross-patient write control, so a payload that tried to send it
 * would be validated and then dropped.
 */

export type AnggotaKeluargaInput = {
    /** `exists:master_hubungan_keluarga,id`; a bad id is a 422 rather than a MySQL 1452. */
    hubungan_id: number;
    nama_lengkap: string;
    jenis_kelamin: 'L' | 'P';
    /** `Y-m-d`, `date_format:Y-m-d`, between 1900-01-01 and today. */
    tanggal_lahir: string;
    /**
     * Exactly sixteen digits, and `nullable`. **Write-only from this client's point of
     * view**: `PasienAnggotaKeluargaResource` masks it on read, so the edit form cannot
     * pre-fill it and a re-submitted masked value would fail `digits:16`. The field is
     * therefore left empty on edit and submitting it empty omits the key entirely, leaving
     * the stored value alone.
     */
    nik?: string | null;
    no_telepon?: string | null;
    catatan_alergi?: string | null;
};

/**
 * `PUT` is a **partial** update: `AnggotaKeluargaRequest` uses `sometimes`, and
 * `anggotaKeys()` writes only the keys present in the body. Sending `{}` is a valid,
 * successful, no-op request - so the form must send only what the user actually changed.
 */
export type UpdateAnggotaKeluargaInput = Partial<AnggotaKeluargaInput>;

export type { AnggotaKeluarga };

export async function fetchAnggotaKeluarga(params: {
    page: number;
    per_page: number;
}) {
    return request<{ anggota_keluarga: AnggotaKeluarga[] }>(
        'pasien/anggota-keluarga',
        { searchParams: { page: params.page, per_page: params.per_page } },
    );
}

export async function createAnggotaKeluarga(input: AnggotaKeluargaInput) {
    return request<{ anggota_keluarga: AnggotaKeluarga }>(
        'pasien/anggota-keluarga',
        { method: 'POST', json: input, retry: 0 },
    );
}

export async function updateAnggotaKeluarga(
    id: number,
    input: UpdateAnggotaKeluargaInput,
) {
    return request<{ anggota_keluarga: AnggotaKeluarga }>(
        `pasien/anggota-keluarga/${id}`,
        { method: 'PUT', json: input, retry: 0 },
    );
}

export async function destroyAnggotaKeluarga(id: number) {
    return request<{ deleted: true; id: number }>(
        `pasien/anggota-keluarga/${id}`,
        { method: 'DELETE', retry: 0 },
    );
}

export const anggotaKeluargaQueryKey = ['v1', 'pasien', 'anggota-keluarga'] as const;

/**
 * The list query, keyed by the request so that changing the page is a different cache
 * entry rather than a refetch of the previous one. `per_page` is part of the key because
 * the server's `meta` describes the page it actually applied, after the 100 cap.
 */
export function anggotaKeluargaOptions(params: {
    page: number;
    per_page: number;
}) {
    return queryOptions({
        queryKey: [...anggotaKeluargaQueryKey, params],
        queryFn: () => fetchAnggotaKeluarga(params),
    });
}

export function createAnggotaKeluargaMutation() {
    return mutationOptions({
        mutationFn: createAnggotaKeluarga,
        onSuccess: () => {
            void queryClient.invalidateQueries({
                queryKey: anggotaKeluargaQueryKey,
            });
        },
    });
}

export function updateAnggotaKeluargaMutation() {
    return mutationOptions({
        mutationFn: ({ id, input }: { id: number; input: UpdateAnggotaKeluargaInput }) =>
            updateAnggotaKeluarga(id, input),
        onSuccess: () => {
            void queryClient.invalidateQueries({
                queryKey: anggotaKeluargaQueryKey,
            });
        },
    });
}

export function destroyAnggotaKeluargaMutation() {
    return mutationOptions({
        mutationFn: destroyAnggotaKeluarga,
        onSuccess: () => {
            void queryClient.invalidateQueries({
                queryKey: anggotaKeluargaQueryKey,
            });
        },
    });
}

/**
 * The relationship labels, for a form to render a name next to an id.
 *
 * `PasienAnggotaKeluargaResource` publishes `hubungan` as a string whenever the relation
 * was loaded, and the list endpoint loads it, so a row renders "Orang Tua/Kandung" rather
 * than a bare `3`. A **form** is the case the resource cannot serve: a `Select` needs the
 * option set, and Module 1 exposes no `master_hubungan_keluarga` endpoint -
 * `route:list --path=api/v1` has exactly one reference endpoint, `master-spesialisasi`.
 *
 * The seven rows below are therefore transcribed from the reference SQL's own seed,
 * `telemedicine_test.sql` `INSERT INTO master_hubungan_keluarga VALUES`, and are labelled
 * as such at every use site. They are a **DDL transcription, not an API response**, which
 * is the one thing this client is otherwise forbidden from having: a hardcoded array
 * standing in for a server result. The distinction is that these rows are reference data
 * fixed by the schema this API serves, and the client can only offer the seven ids the
 * `exists:master_hubungan_keluarga,id` rule will accept anyway.
 */
export const HUBUNGAN_KELUARGA: ReadonlyArray<{ id: number; nama: string }> = [
    { id: 1, nama: 'Pasangan' },
    { id: 2, nama: 'Anak Kandung' },
    { id: 3, nama: 'Orang Tua/Kandung' },
    { id: 4, nama: 'Saudara Kandung' },
    { id: 5, nama: 'Paman/Tante' },
    { id: 6, nama: 'Kakek/Nenek' },
    { id: 7, nama: 'Lainnya' },
];

/**
 * The label for a `hubungan_id`, falling back to the bare id.
 *
 * The fallback matters: the list resource publishes `hubungan` through `whenLoaded()`, so
 * a caller that did not eager-load the relation gets no label, and rendering nothing next
 * to a relationship would be worse than rendering the number.
 */
export function namaHubungan(hubunganId: number, label?: string | null): string {
    if (typeof label === 'string' && label !== '') {
        return label;
    }

    return (
        HUBUNGAN_KELUARGA.find((row) => row.id === hubunganId)?.nama ??
        `Hubungan ${hubunganId}`
    );
}
