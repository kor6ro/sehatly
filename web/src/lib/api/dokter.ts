import { keepPreviousData, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import type { DokterDetail, DokterRingkas, DokterTipe, Spesialisasi } from '@/lib/api/types';

/**
 * The public doctor directory: `GET /dokter`, `GET /dokter/{dokter}` and
 * `GET /master-spesialisasi`.
 *
 * ## All three are PUBLIC, and that is the plan's instruction rather than an omission
 *
 * `DokterController` documents why no `permission:` appears: `dokter.lihat` resolves
 * through `EnsurePermission`, which answers 401 when there is no authenticated principal,
 * so the gate would make the endpoint refuse every anonymous visitor - the opposite of
 * what a pre-authentication directory page needs. It would also lock out `perawat` and
 * `kurir`, which are real `users.tipe` values holding **no** role and therefore no grant.
 *
 * The consequence for this client is that `/dokter` and `/dokter/:id` sit **outside** the
 * auth guard in `app/router.tsx`, and that a 401 from either of them is a real anomaly
 * worth surfacing rather than a routine "please sign in".
 */

/**
 * `?spesialisasi=`, `?tipe=`, `?search=`, `?tersedia_telemedisin=`, `?page=`, `?per_page=`.
 *
 * Every one of these is `nullable` on the server and every one is a *closed* vocabulary
 * the DDL defines, which is why a typo is a 422 naming the field rather than a silently
 * empty list: `?tipe=dokter` and `?tipe=spesialis` are both refused (the enum members are
 * `dokter_spesialis` and the `tipe` here is `master_spesialisasi.tipe`'s different
 * three-value enum). The list screen therefore only ever sends values it got from
 * `master-spesialisasi` or from {@link TIPE_DOKTER}.
 */
export type DokterFilters = {
    page: number;
    per_page: number;
    /** A `master_spesialisasi.kode` (non-numeric) or its `id` (numeric). */
    spesialisasi?: string;
    tipe?: DokterTipe;
    search?: string;
    tersedia_telemedisin?: boolean;
};

/** `dokter.tipe`, the seven-value ENUM at `telemedicine_test.sql:412`. */
export const TIPE_DOKTER: ReadonlyArray<DokterTipe> = [
    'dokter_umum',
    'dokter_spesialis',
    'dokter_gigi',
    'psikolog',
    'bidan',
    'perawat',
    'apoteker',
];

const TIPE_DOKTER_LABEL: Record<DokterTipe, string> = {
    dokter_umum: 'Dokter Umum',
    dokter_spesialis: 'Dokter Spesialis',
    dokter_gigi: 'Dokter Gigi',
    psikolog: 'Psikolog',
    bidan: 'Bidan',
    perawat: 'Perawat',
    apoteker: 'Apoteker',
};

export function labelTipeDokter(value: DokterTipe): string {
    return TIPE_DOKTER_LABEL[value] ?? value;
}

export async function fetchDokter(filters: DokterFilters) {
    return request<{ dokter: DokterRingkas[] }>('dokter', {
        searchParams: {
            page: filters.page,
            per_page: filters.per_page,
            ...(filters.spesialisasi === undefined
                ? {}
                : { spesialisasi: filters.spesialisasi }),
            ...(filters.tipe === undefined ? {} : { tipe: filters.tipe }),
            ...(filters.search === undefined || filters.search === ''
                ? {}
                : { search: filters.search }),
            ...(filters.tersedia_telemedisin === undefined
                ? {}
                : { tersedia_telemedisin: filters.tersedia_telemedisin }),
        },
    });
}

/**
 * `GET /api/v1/dokter/{dokter}`
 *
 * Answers **404** for a doctor who does not exist, is not `status_verifikasi =
 * terverifikasi`, is inactive, has `tersedia_telemedisin = 0`, whose `str_berlaku_sampai`
 * has passed, or whose account is soft-deleted - one status for all six, on purpose, so an
 * anonymous caller cannot enumerate the verification state of every doctor account. The
 * detail page therefore renders a real not-found state for all of them and must never spin
 * forever waiting for a body that will not arrive.
 *
 * The route parameter is a `string`, not an `int`: a non-numeric segment must produce the
 * 404 envelope, not a `TypeError`.
 */
export async function fetchDokterDetail(id: string) {
    return request<{ dokter: DokterDetail }>(`dokter/${id}`);
}

export async function fetchSpesialisasi() {
    return request<{ spesialisasi: Spesialisasi[] }>('master-spesialisasi');
}

export const dokterQueryKey = ['v1', 'dokter'] as const;

export const dokterDetailQueryKey = ['v1', 'dokter', 'detail'] as const;

export const spesialisasiQueryKey = ['v1', 'master-spesialisasi'] as const;

/**
 * The list query, keyed by the whole filter set.
 *
 * Every filter is in the key, so switching from page 3 to page 1, or adding a
 * specialisation, is a different cache entry and cannot show a stale page under new
 * filters.
 *
 * ## Why previous data is kept as a placeholder
 *
 * F03 §6: a filter change on a slow connection must dim the existing results and say
 * `Menghitung hasil…`, never blank the list and jump the scroll position. Without a
 * placeholder React Query has no data for the new key on first fetch, so `isPending`
 * would replace the rows with skeletons. `keepPreviousData` keeps the last page
 * rendered while `isFetching` is true, which is exactly the signal the page uses to
 * swap the count for `Menghitung hasil…` and to dim the list.
 */
export function dokterOptions(filters: DokterFilters) {
    return queryOptions({
        queryKey: [...dokterQueryKey, filters],
        queryFn: () => fetchDokter(filters),
        placeholderData: keepPreviousData,
    });
}

/**
 * The count-only query behind the mobile sheet's `Tampilkan {n} hasil`.
 *
 * The sheet edits a **draft** filter set that is not applied until the patient
 * confirms, so the applied list's `meta.total` cannot answer "how many results would
 * this draft produce?". This reads the same endpoint with `per_page: 1` and uses only
 * `meta.total`. The key is separate from {@link dokterOptions} (`count` plus the
 * filters object, and `per_page: 1` never equals the list's 12), so it can never
 * collide with a cached list page.
 */
export function dokterCountOptions(filters: DokterFilters) {
    return queryOptions({
        queryKey: [...dokterQueryKey, 'count', filters],
        queryFn: () => fetchDokter(filters),
    });
}

export function dokterDetailOptions(id: string) {
    return queryOptions({
        queryKey: [...dokterDetailQueryKey, id],
        queryFn: () => fetchDokterDetail(id),
        /**
         * A 404 is the API's answer for six different situations the client is forbidden
         * from telling apart, so it must not be retried and must not be cached as a
         * transient failure. `retry: 0` here plus the 4xx rule in `lib/query-client.ts`
         * agree; the explicit setting documents the intent at the call site.
         */
        retry: 0,
    });
}

/**
 * The specialisation reference list.
 *
 * `DokterController::spesialisasiIndex()` answers `ApiResponse::singlePageMeta()`, so
 * `meta.last_page` is always 1 and this is never paginated - a 16-row master table has
 * nothing to page. The client parses one list envelope for it anyway, which is the point
 * of `singlePageMeta`.
 *
 * `total` is the table's real row count, not a constant. A schema-only database answers
 * `0` here, and the filter dropdown then has to say so rather than render an empty
 * control.
 */
export function spesialisasiOptions() {
    return queryOptions({
        queryKey: spesialisasiQueryKey,
        queryFn: fetchSpesialisasi,
        staleTime: 300_000,
    });
}
