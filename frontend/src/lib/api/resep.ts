import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import {
    type AntreanResepFilters,
    type AntreanResepResponse,
    type BuatResepInput,
    type BuatResepResponse,
    type CariObatFilters,
    type CariObatResponse,
    type CekInteraksiResponse,
    type ResepDetailResponse,
    type RiwayatResepFilters,
    type RiwayatResepResponse,
    type VerifikasiResepInput,
    type VerifikasiResepResponse,
} from '@/lib/api/resep-peringatan';

/**
 * Module 4's transport: the seven prescription and medicine calls, their cache keys and their
 * mutations.
 *
 * The clinical half - labels, closed vocabularies, request schemas, and the grouping and
 * severity rules - lives in `lib/api/resep-peringatan.ts` and is re-exported below, so a
 * component still has one import site. The split exists because `lib/http.ts` reads
 * `import.meta.env` at module load and `node --test` runs these sources with no bundler, so
 * a unit test cannot reach anything that transitively imports the transport.
 *
 * ## `catatan_dodio` is the override channel, and it is the ONLY one
 *
 * `StoreResepRequest` marks `catatan_dokter` - the `resep` COLUMN the note is stored in - as
 * `prohibited`, and accepts `catatan_dodio` instead. That asymmetry is the whole persistence
 * story for a contraindication override: a note can only be written as an ACKNOWLEDGEMENT,
 * never as a direct write, and `ResepService::catatan()` refuses the request with a 422 when a
 * `kontraindikasi` warning exists and the note is empty. So a prescription that overrides a
 * contraindication cannot exist without the note, and the note cannot be written without going
 * through the acknowledgement field. There is no migration here to point at because there is no
 * `resep_interaksi` table; `resep.catatan_dokter` IS the record.
 *
 * ## A 422 on `catatan_dodio` means a contraindication was found and NOTHING was written
 *
 * `ResepService::buat()` evaluates the warning set and calls `catatan()` BEFORE
 * `tulisDenganNomorUnik()`. The refusal happens first, so the composer's gate can re-submit
 * without risking a duplicate: the prescription is created exactly once, after the
 * acknowledgement.
 *
 * ## Every write is `retry: 0`
 *
 * A replayed `POST /resep/{id}/verifikasi` would spend the single `resep_verifikasi` row the
 * UNIQUE key at `:788` allows, and a replayed create would write a second prescription.
 */
export * from '@/lib/api/resep-peringatan';

/** `GET /obat`. The catalogue barely changes, so the query holds it for a minute. */
export async function cariObat(filters: CariObatFilters) {
    return request<CariObatResponse>('obat', {
        searchParams: {
            ...(filters.search === '' ? {} : { search: filters.search }),
            ...(filters.kelas_obat == null ? {} : { kelas_obat: filters.kelas_obat }),
            ...(filters.requires_resep == null ? {} : { requires_resep: filters.requires_resep }),
            ...(filters.per_page === undefined ? {} : { per_page: filters.per_page }),
        },
    });
}

export async function buatResep(konsultasiId: number, input: BuatResepInput) {
    return request<BuatResepResponse>(`konsultasi/${konsultasiId}/resep`, {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

export async function fetchResep(id: number) {
    return request<ResepDetailResponse>(`resep/${id}`);
}

/**
 * `GET /resep/{id}/cek-interaksi` - the CURRENT warning set for the STORED items.
 *
 * Separate from {@link fetchResep} because the answer CHANGES: `obat_interaksi` and
 * `pasien_alergi` are live tables, so a warning can appear after the prescription was written
 * and acknowledged. A pharmacy that trusted the prescriber's original 201 would dispense
 * against a stale set.
 */
export async function cekInteraksiResep(id: number) {
    return request<CekInteraksiResponse>(`resep/${id}/cek-interaksi`);
}

/**
 * `POST /resep/{id}/verifikasi` - the pharmacist's single answer, and it happens once.
 *
 * `resep_verifikasi.resep_id` is `UNIQUE` (`:788`), so a second answer is a 422 both from the
 * pre-check and from the constraint. `retry: 0` is therefore not hygiene, it is the difference
 * between a 201 and a 422 on a key that cannot be spent twice.
 */
export async function verifikasiResep(id: number, input: VerifikasiResepInput) {
    return request<VerifikasiResepResponse>(`resep/${id}/verifikasi`, {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

/** `GET /pasien/resep` - the caller's own prescriptions, newest first. */
export async function riwayatResep(filters: RiwayatResepFilters) {
    return request<RiwayatResepResponse>('pasien/resep', {
        searchParams: {
            page: filters.page,
            per_page: filters.per_page,
            ...(filters.status == null ? {} : { status: filters.status }),
        },
    });
}

/**
 * `GET /resep` - the pharmacist's verification queue, newest first.
 *
 * The response is `ResepAntreanResource`, so the rows carry no clinical content and the
 * detail remains a separate `GET /resep/{id}`. `status` is restricted to the two values
 * `AntreanResepRequest` accepts; sending anything else is a 422, which is why the filter
 * type is the constant's own union rather than `string`.
 */
export async function antreanResep(filters: AntreanResepFilters) {
    return request<AntreanResepResponse>('resep', {
        searchParams: {
            page: filters.page,
            per_page: filters.per_page,
            ...(filters.status == null ? {} : { status: filters.status }),
        },
    });
}

// ============================================================================
// Cache keys
// ============================================================================

export const resepQueryKey = ['v1', 'resep'] as const;

export const obatQueryKey = ['v1', 'obat'] as const;

export const riwayatResepQueryKey = ['v1', 'pasien', 'resep'] as const;

/** `GET /resep`'s cache key; the prefix is invalidated whenever a verification lands. */
export const antreanResepQueryKey = ['v1', 'resep', 'antrean'] as const;

/**
 * The catalogue, at `staleTime: 60_000` rather than the module-wide 30 s.
 *
 * `master_obat` is reference data. A doctor typing "amox" runs a query per debounce step, and
 * re-running it every 30 s means the same two rows are fetched again while a prescription is
 * being composed. A minute is long enough to remove that and short enough that a catalogue
 * edit still shows up inside one composing session.
 */
export function obatOptions(filters: CariObatFilters) {
    return queryOptions({
        queryKey: [...obatQueryKey, filters],
        queryFn: () => cariObat(filters),
        staleTime: 60_000,
    });
}

export function resepOptions(id: number) {
    return queryOptions({
        queryKey: [...resepQueryKey, id],
        queryFn: () => fetchResep(id),
    });
}

export function cekInteraksiOptions(id: number) {
    return queryOptions({
        queryKey: [...resepQueryKey, id, 'cek-interaksi'],
        queryFn: () => cekInteraksiResep(id),
    });
}

export function riwayatResepOptions(filters: RiwayatResepFilters) {
    return queryOptions({
        queryKey: [...riwayatResepQueryKey, filters],
        queryFn: () => riwayatResep(filters),
    });
}

export function antreanResepOptions(filters: AntreanResepFilters) {
    return queryOptions({
        queryKey: [...antreanResepQueryKey, filters],
        queryFn: () => antreanResep(filters),
    });
}

/**
 * The create invalidates the patient's history and writes the new prescription into the cache
 * from the response.
 *
 * The response already carries the whole prescription WITH its items
 * (`$resep->loadMissing('resepItem')` in the service), so `setQueryData` is both cheaper than a
 * refetch and the honest accounting: one user action, one write.
 */
export function buatResepMutation(konsultasiId: number) {
    return mutationOptions({
        mutationFn: (input: BuatResepInput) => buatResep(konsultasiId, input),
        onSuccess: (result) => {
            queryClient.setQueryData([...resepQueryKey, result.data.resep.id], {
                data: {
                    resep: result.data.resep,
                    verifikasi: null,
                    warning: result.data.warning,
                    warning_grup: result.data.warning_grup,
                },
                message: result.message,
            });

            void queryClient.invalidateQueries({ queryKey: riwayatResepQueryKey });
        },
    });
}

/**
 * A verification invalidates the prescription and the patient's history.
 *
 * It CANNOT replace the cache from the response the way the create does, because
 * `ResepResource` publishes `items` through `whenLoaded()` and the verification path never
 * eager-loads them - so a `setQueryData` here would leave a detail screen holding a
 * prescription with no item rows, which is the one thing a pharmacy must not see. The refetch
 * is the correct answer, not an optimisation left out.
 */
export function verifikasiResepMutation(id: number) {
    return mutationOptions({
        mutationFn: (input: VerifikasiResepInput) => verifikasiResep(id, input),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: [...resepQueryKey, id] });
            void queryClient.invalidateQueries({ queryKey: riwayatResepQueryKey });
            void queryClient.invalidateQueries({ queryKey: antreanResepQueryKey });
        },
    });
}
