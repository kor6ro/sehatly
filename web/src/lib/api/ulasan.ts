import { keepPreviousData, mutationOptions, queryOptions } from '@tanstack/react-query';
import { request, type ApiMeta } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import type { Iso } from '@/lib/api/types';

/**
 * F04's two review endpoints, both unblocked by the backend commit that added
 * `UlasanDokterService`.
 *
 * | method | path | guard | success |
 * | --- | --- | --- | --- |
 * | `GET` | `/api/v1/dokter/{dokter}/ulasan` | public | 200 + `meta` with the aggregate |
 * | `POST` | `/api/v1/konsultasi/{id}/ulasan` | the consultation's own patient, `selesai` | 201 |
 *
 * ## `meta` carries the aggregate, and `total` is the FILTERED page-set
 *
 * `DokterController::ulasanIndex()` merges `distribusi`, `rata_rata`,
 * `rata_rata_komunikasi` and `rata_rata_akurasi` into the standard pagination block,
 * and the aggregate is computed over EVERY review of the doctor even when `?rating=`
 * narrows the page. So clicking a distribution bar never changes the distribution it
 * was clicked on, and the summary keeps speaking about all reviews while the list
 * below it shows one star rating.
 *
 * `request()` types every response `meta` as the project-wide {@link ApiMeta}, so the
 * four extra keys are invisible to the type system. {@link bacaMetaUlasan} reads them
 * the way `unreadDari` reads `meta.unread`, and never asserts a number the server did
 * not send.
 *
 * ## The write is one-shot, and the database enforces it
 *
 * `SimpanUlasanRequest` accepts five fields; `pasien_id`, `dokter_id`, `konsultasi_id`
 * and the reply columns are `prohibited`. `ulasan_dokter.konsultasi_id` is
 * `NOT NULL UNIQUE`, so a second attempt is a 422 translated from MySQL's 1062 - which
 * is why this client sends the POST once (`retry: 0`) and offers no edit path.
 */

/**
 * One `ulasan_dokter` row, transcribed field by field from `UlasanDokterResource`.
 *
 * `penulis` is already masked server-side (`NamaMasker`) when the author opted out of
 * anonymity, and `null` when the review is anonymous or the patient row is gone. The
 * client must not derive or recover a name: when this is `null` the renderer says
 * `Pasien`.
 */
export type UlasanDokter = {
    id: number;
    rating: number;
    /** `null` when the author scored overall but not the breakdown - never 0. */
    rating_komunikasi: number | null;
    rating_akurasi: number | null;
    /** `null` for a rating-only review. */
    isi: string | null;
    is_anonim: boolean;
    /** The masked display name, or `null` for an anonymous review. */
    penulis: string | null;
    balasan_dokter: string | null;
    /** ISO-8601 UTC, or `null` when the doctor has not replied. */
    dibalas_at: Iso;
    /** ISO-8601 UTC. The only timestamp on the row; there is no edit time. */
    dibuat_at: Iso;
};

/** All five keys are always present in the server's `distribusi` map. */
export type DistribusiBintang = Record<'1' | '2' | '3' | '4' | '5', number>;

/** The pagination block plus the recomputed aggregate, as the endpoint publishes it. */
export type MetaUlasan = ApiMeta & {
    distribusi: DistribusiBintang;
    /** `null` when the doctor has no reviews - never `0`, which is a rating. */
    rata_rata: number | null;
    rata_rata_komunikasi: number | null;
    rata_rata_akurasi: number | null;
};

/**
 * The closed `sort` vocabulary, exact copy of `UlasanDokterService::SORT_VALUES`.
 *
 * `membantu` ("most helpful") is deliberately absent: it needs a vote table the schema
 * does not have, and the server refuses it with a 422 on `errors.sort`.
 */
export type SortUlasan = 'terbaru' | 'tertinggi' | 'terendah';

/** The control's options, in the order the service documents them. */
export const SORT_ULASAN: ReadonlyArray<{ value: SortUlasan; label: string }> = [
    { value: 'terbaru', label: 'Terbaru' },
    { value: 'tertinggi', label: 'Rating tertinggi' },
    { value: 'terendah', label: 'Rating terendah' },
];

export type UlasanFilters = {
    page: number;
    per_page: number;
    /** 1-5. Omitted means every rating. */
    rating?: number;
    sort?: SortUlasan;
};

/**
 * One page of a doctor's public reviews.
 *
 * `retry: 0` because the block owns a single explicit `Coba lagi`: ky's automatic retry
 * would make one tap into two requests, and the screen could not show which one failed.
 * A retry of a GET is safe by itself - the count and the error latency are what is
 * wrong here.
 */
export async function fetchUlasanDokter(dokterId: string, filters: UlasanFilters) {
    return request<{ ulasan: UlasanDokter[] }>(`dokter/${dokterId}/ulasan`, {
        retry: 0,
        searchParams: {
            page: filters.page,
            per_page: filters.per_page,
            ...(filters.rating === undefined ? {} : { rating: filters.rating }),
            ...(filters.sort === undefined ? {} : { sort: filters.sort }),
        },
    });
}

/**
 * The body of `POST /konsultasi/{id}/ulasan`, matching `SimpanUlasanRequest`.
 *
 * The optional members are omitted rather than sent as `null`: the request validates
 * them `nullable`, and omitting `is_anonim` makes the SERVICE apply the DDL's own
 * default of `true` - anonymity is opt-out, never opt-in.
 */
export type SimpanUlasanInput = {
    rating: number;
    rating_komunikasi?: number;
    rating_akurasi?: number;
    isi?: string;
    is_anonim?: boolean;
};

/**
 * The caller-patient's review of one `selesai` consultation.
 *
 * A 403 means the account owns no `pasien` row; a 404 means the consultation is not
 * theirs or is absent (one status for both, so the endpoint is not an existence
 * oracle); a 422 means the status is not `selesai` or a review already exists.
 */
export async function simpanUlasan(konsultasiId: number, input: SimpanUlasanInput) {
    return request<{ ulasan: UlasanDokter }>(`konsultasi/${konsultasiId}/ulasan`, {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

/**
 * The review queries, under one prefix so a successful write can invalidate every
 * cached doctor page without knowing which one is open.
 */
export const ulasanQueryKey = ['v1', 'ulasan'] as const;

/**
 * A page of reviews, keyed by the whole filter set.
 *
 * Every filter is in the key, so switching star or page is a different cache entry and
 * cannot show a stale page under new filters. `placeholderData: keepPreviousData` keeps
 * the list rendered while the next page loads, the same treatment the directory gives a
 * filter change: a block that blanks and jumps the scroll position on every bar click
 * is worse than one that dims for a moment.
 */
export function ulasanOptions(dokterId: string, filters: UlasanFilters) {
    return queryOptions({
        queryKey: [...ulasanQueryKey, 'dokter', dokterId, filters],
        queryFn: () => fetchUlasanDokter(dokterId, filters),
        placeholderData: keepPreviousData,
    });
}

/**
 * The write, invalidating the review prefix on success.
 *
 * The list on the doctor profile is recomputed from `ulasan_dokter` on every read, so a
 * successful write makes every cached review page stale in both directions - the new
 * row and the aggregate. Invalidating the prefix is the only correct response; patching
 * one cache entry would leave the other pages lying.
 */
export function simpanUlasanMutation(konsultasiId: number) {
    return mutationOptions({
        mutationFn: (input: SimpanUlasanInput) => simpanUlasan(konsultasiId, input),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ulasanQueryKey });
        },
    });
}

/**
 * The doctor's TOTAL review count, derived from the distribution.
 *
 * `meta.total` is the paginator's total for the FILTERED list, so it shrinks to the
 * number of 4-star reviews while `?rating=4` is active. The distribution is computed
 * over EVERY review of the doctor, so its five bars always sum to the overall count -
 * which is what the `>= 5` summary rule needs, and what the `{n} ulasan` copy must
 * show while a filter is applied.
 */
export function jumlahUlasan(meta: MetaUlasan): number {
    return (
        meta.distribusi['1'] +
        meta.distribusi['2'] +
        meta.distribusi['3'] +
        meta.distribusi['4'] +
        meta.distribusi['5']
    );
}

/**
 * Read the aggregate keys off a `meta` block that the type system types as `ApiMeta`.
 *
 * Every value is validated rather than cast: a missing `distribusi` key means `0`
 * reviews on that star, an absent average means `null`, and a value that is not a
 * finite number is treated as absent. The screen then renders what the server actually
 * sent instead of `NaN` or a fabricated `0,0`.
 */
export function bacaMetaUlasan(meta: ApiMeta | undefined): MetaUlasan {
    const sumber = (meta ?? {}) as Record<string, unknown>;
    const distribusiSumber = (sumber.distribusi ?? {}) as Record<string, unknown>;

    return {
        current_page: bulat(sumber.current_page),
        last_page: Math.max(1, bulat(sumber.last_page)),
        per_page: bulat(sumber.per_page),
        total: bulat(sumber.total),
        from: angka(sumber.from),
        to: angka(sumber.to),
        distribusi: {
            '1': bulat(distribusiSumber['1']),
            '2': bulat(distribusiSumber['2']),
            '3': bulat(distribusiSumber['3']),
            '4': bulat(distribusiSumber['4']),
            '5': bulat(distribusiSumber['5']),
        },
        rata_rata: angka(sumber.rata_rata),
        rata_rata_komunikasi: angka(sumber.rata_rata_komunikasi),
        rata_rata_akurasi: angka(sumber.rata_rata_akurasi),
    };
}

/** A finite number, or `null` - so `from`/`to`/averages fail closed. */
function angka(value: unknown): number | null {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

/** A non-negative integer, or `0` - so a count is never negative or fractional. */
function bulat(value: unknown): number {
    return typeof value === 'number' && Number.isFinite(value) && value >= 0
        ? Math.trunc(value)
        : 0;
}
