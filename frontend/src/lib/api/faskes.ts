import { queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';

/**
 * The public venue list behind `GET /api/v1/faskes` - what the landing page's
 * "Apotek Terdekat" menu opens.
 *
 * ## `tipe` is sent only when it is not the default
 *
 * The server defaults `?tipe=` to `apotek` ({@link TIPE_FASKES_DEFAULT}), which is the
 * pharmacy list this menu asks for. Sending it anyway would work, but leaving it off
 * keeps the panel's cache key equal to the plainest possible request - one visitor
 * who has a position and one who does not differ ONLY by `lat`/`lng`, and neither
 * differs from the no-argument call the empty panel makes.
 *
 * ## `lat`/`lng` travel as one thing, or not at all
 *
 * Same rule as `dokter.ts` applies to `?sort=terdekat`: the server refuses a half
 * pair with a 422 ({@link IndexFaskesRequest} on the PHP side), so the pair is
 * assembled here rather than at the call site and a caller holding one coordinate
 * simply gets the alphabetical list with `jarak_km: null` on every row.
 *
 * `usePosisiPasien()` supplies the pair: while the browser is still working out a
 * position the panel shows skeletons instead of issuing a request that would have to
 * be repeated.
 */

/** The `faskes.tipe` ENUM, in declaration order (`docs/enums.json`, `faskes.tipe`). */
export type TipeFaskes =
    | 'rumah_sakit'
    | 'klinik'
    | 'puskesmas'
    | 'apotek'
    | 'laboratorium';

/** What the server answers for `GET /api/v1/faskes` per row. */
export type Faskes = {
    id: number;
    nama: string;
    tipe: TipeFaskes;
    alamat: string | null;
    telepon: string | null;
    /** The row's `jam_operasional` JSON, `null` when no schedule was entered. */
    jam_operasional: unknown;
    /** The venue's own map pin, `null` when it was entered without one. */
    latitude: number | null;
    longitude: number | null;
    /**
     * Kilometres from the caller's position, rounded to whole metres - and `null`
     * whenever the request carried no position OR the venue has no pin. The client
     * renders both nulls as "jarak belum tersedia" rather than as a missing row.
     */
    jarak_km: number | null;
};

/** What {@link fetchFaskes} accepts. Everything is optional; the server defaults `tipe`. */
export type FaskesFilters = {
    tipe?: TipeFaskes;
    lat?: number | null;
    lng?: number | null;
    page?: number;
    per_page?: number;
};

/** The server's own default, so leaving `tipe` off the wire means the same thing. */
export const TIPE_FASKES_DEFAULT: TipeFaskes = 'apotek';

/**
 * `GET /api/v1/faskes`
 *
 * Answers `{ faskes: Faskes[] }` inside the standard envelope, with `meta` as a
 * sibling of `data`. An empty array is a legitimate answer: a database with no
 * active pharmacy has nothing to list, and the panel renders its empty state.
 */
export async function fetchFaskes(filters: FaskesFilters = {}) {
    // Narrowed into consts so the pair is `number` INSIDE the branch that sends it:
    // `lat` and `lng` are typed `number | null` at the boundary, and a `null` that
    // reached the query string would be `?lat=null`, which the server refuses with
    // a 422. The alias is also what proves the pair leaves together.
    const lat = typeof filters.lat === 'number' ? filters.lat : null;
    const lng = typeof filters.lng === 'number' ? filters.lng : null;
    const adaPosisi = lat !== null && lng !== null;

    const tipe = filters.tipe ?? TIPE_FASKES_DEFAULT;

    return request<{ faskes: Faskes[] }>('faskes', {
        searchParams: {
            ...(tipe === TIPE_FASKES_DEFAULT ? {} : { tipe }),
            ...(adaPosisi ? { lat, lng } : {}),
            ...(filters.page === undefined ? {} : { page: filters.page }),
            ...(filters.per_page === undefined ? {} : { per_page: filters.per_page }),
        },
    });
}

export const faskesQueryKey = ['v1', 'faskes'] as const;

/**
 * The list query, keyed by the whole filter set - position included, so the moment a
 * position arrives the cache grows a second entry rather than overwriting the
 * anonymous one with rows that happened to reorder.
 *
 * No `staleTime` is set on purpose: the panel is small, the rows are a directory
 * that changes rarely, and React Query's default already keeps a completed fetch
 * from repeating within its window.
 */
export function faskesOptions(filters: FaskesFilters = {}) {
    return queryOptions({
        queryKey: [...faskesQueryKey, filters],
        queryFn: () => fetchFaskes(filters),
    });
}
