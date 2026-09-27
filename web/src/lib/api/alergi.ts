import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import type { Alergi, Keparahan, TipeAlergen } from '@/lib/api/types';

/**
 * `GET|POST /api/v1/pasien/alergi` and `PUT|DELETE .../{id}`
 *
 * ## `keparahan` is optional on create, and the database supplies the default
 *
 * `pasien_alergi.keparahan` is `NOT NULL DEFAULT 'ringan'`
 * (`telemedicine_test.sql:280`), and `AlergiRequest` marks it `nullable` so a client may
 * send `null` to mean "use the default". The controller then calls `refresh()` on the
 * model, because Eloquent does not read a column back after an insert - without it, a
 * create that omitted `keparahan` would answer `"keparahan": null` while the row says
 * `ringan`. So the value the list shows is always the stored one, and this client does
 * not second-guess it with a local default.
 *
 * ## `dicatat_oleh_user_id` is written once and never rewritten
 *
 * `alergiUpdate` deliberately does not touch it, because it records who *first* recorded
 * the allergy and a later self-edit is not a second observation. The field is published
 * anyway, so "did I record this myself?" is answerable.
 */

/** The four `tipe_alergen` values, from `AlergiRequest::TIPE_ALERGEN`. */
export const TIPE_ALERGEN: ReadonlyArray<TipeAlergen> = [
    'obat',
    'makanan',
    'lingkungan',
    'lainnya',
];

/** The four `keparahan` values, from `AlergiRequest::KEPARAHAN`. */
export const KEPARAHAN: ReadonlyArray<Keparahan> = [
    'ringan',
    'sedang',
    'berat',
    'anafilaksis',
];

/**
 * Human labels for the two ENUMs.
 *
 * The database speaks in slugs and the screen should not, but the mapping is a
 * presentation concern and belongs on this side of the wire. A value not in these tables
 * is rendered as its own slug by {@link labelTipeAlergen} / {@link labelKeparahan} rather
 * than hidden, because a stored row the client cannot name is a fact worth surfacing
 * during a data review.
 */
const TIPE_ALERGEN_LABEL: Record<TipeAlergen, string> = {
    obat: 'Obat',
    makanan: 'Makanan',
    lingkungan: 'Lingkungan',
    lainnya: 'Lainnya',
};

const KEPARAHAN_LABEL: Record<Keparahan, string> = {
    ringan: 'Ringan',
    sedang: 'Sedang',
    berat: 'Berat',
    anafilaksis: 'Anafilaksis',
};

export function labelTipeAlergen(value: TipeAlergen): string {
    return TIPE_ALERGEN_LABEL[value] ?? value;
}

export function labelKeparahan(value: Keparahan): string {
    return KEPARAHAN_LABEL[value] ?? value;
}

export type AlergiInput = {
    tipe_alergen: TipeAlergen;
    /**
     * Free text, validated as a length only. **Deliberately not** validated against
     * `master_obat`: an allergy is frequently to a food, a latex or a household chemical
     * that has no row in a medicine catalogue, and the schema's own recorded limitation is
     * that matching is best-effort name comparison against `resep_item.nama_obat`.
     */
    nama_alergen: string;
    reaksi?: string | null;
    /** Omit entirely to let the column default apply. */
    keparahan?: Keparahan | null;
};

/** `PUT` is a partial update; only the keys present in the body are written. */
export type UpdateAlergiInput = Partial<AlergiInput>;

export async function fetchAlergi(params: { page: number; per_page: number }) {
    return request<{ alergi: Alergi[] }>('pasien/alergi', {
        searchParams: { page: params.page, per_page: params.per_page },
    });
}

export async function createAlergi(input: AlergiInput) {
    return request<{ alergi: Alergi }>('pasien/alergi', {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

export async function updateAlergi(id: number, input: UpdateAlergiInput) {
    return request<{ alergi: Alergi }>(`pasien/alergi/${id}`, {
        method: 'PUT',
        json: input,
        retry: 0,
    });
}

export async function destroyAlergi(id: number) {
    return request<{ deleted: true; id: number }>(`pasien/alergi/${id}`, {
        method: 'DELETE',
        retry: 0,
    });
}

export const alergiQueryKey = ['v1', 'pasien', 'alergi'] as const;

export function alergiOptions(params: { page: number; per_page: number }) {
    return queryOptions({
        queryKey: [...alergiQueryKey, params],
        queryFn: () => fetchAlergi(params),
    });
}

export function createAlergiMutation() {
    return mutationOptions({
        mutationFn: createAlergi,
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: alergiQueryKey });
        },
    });
}

export function updateAlergiMutation() {
    return mutationOptions({
        mutationFn: ({ id, input }: { id: number; input: UpdateAlergiInput }) =>
            updateAlergi(id, input),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: alergiQueryKey });
        },
    });
}

export function destroyAlergiMutation() {
    return mutationOptions({
        mutationFn: destroyAlergi,
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: alergiQueryKey });
        },
    });
}
