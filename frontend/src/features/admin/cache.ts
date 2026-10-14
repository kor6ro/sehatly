import type { QueryClient } from '@tanstack/react-query';
import {
    adminDokterQueryKey,
    adminJadwalQueryKey,
    adminLiburQueryKey,
    type AdminDokter,
    type AdminDampak,
    type AdminJadwal,
    type AdminLibur,
} from '@/lib/api/admin';
import type { ApiResult } from '@/lib/http';

/**
 * The local cache writes the admin mutations perform.
 *
 * ## Why the writes are applied instead of invalidating
 *
 * Every admin mutation answers the affected row(s) as the server just committed
 * them - `PUT /admin/dokter/{id}/verifikasi` returns `data.dokter`, `POST/PUT
 * /admin/jadwal` return the written `data.jadwal`, and both deletes answer
 * `{deleted, id}`. Writing that answer into the cache makes the decision's
 * result visible inline (AC-2's badge change, AC-4's three new draft rows,
 * AC-8's per-item outcome) without a second round trip whose timing a test
 * would have to wait on. The server is still the authority: the next list read
 * replaces every field.
 *
 * The one thing none of these do is remove a row the server might still return
 * on a later refetch; they are last-writer-wins updates keyed by id, which is
 * exactly what the responses describe.
 */

type CacheDokter =
    | ApiResult<{ dokter: AdminDokter[] }>
    | ApiResult<{ dokter: AdminDokter; dampak: AdminDampak }>;

export function gantiDokterCache(qc: QueryClient, dokter: AdminDokter): void {
    qc.setQueriesData<CacheDokter | undefined>(
        { queryKey: adminDokterQueryKey },
        (lama) => {
            if (lama === undefined) {
                return lama;
            }

            const baris = lama.data.dokter;

            if (Array.isArray(baris)) {
                return {
                    ...lama,
                    data: {
                        ...lama.data,
                        dokter: baris.map((row) => (row.id === dokter.id ? dokter : row)),
                    },
                } as CacheDokter;
            }

            if (baris.id !== dokter.id) {
                return lama;
            }

            return {
                ...lama,
                data: { ...lama.data, dokter },
            } as CacheDokter;
        },
    );
}

export function tambahJadwalCache(
    qc: QueryClient,
    dokterId: number,
    baris: AdminJadwal[],
): void {
    qc.setQueryData<ApiResult<{ jadwal: AdminJadwal[] }> | undefined>(
        [...adminJadwalQueryKey, dokterId],
        (lama) => {
            if (lama === undefined) {
                return lama;
            }

            const ada = new Set(lama.data.jadwal.map((row) => row.id));

            return {
                ...lama,
                data: {
                    jadwal: [
                        ...lama.data.jadwal,
                        ...baris.filter((row) => !ada.has(row.id)),
                    ],
                },
            };
        },
    );
}

export function gantiJadwalCache(
    qc: QueryClient,
    dokterId: number,
    baris: AdminJadwal,
): void {
    qc.setQueryData<ApiResult<{ jadwal: AdminJadwal[] }> | undefined>(
        [...adminJadwalQueryKey, dokterId],
        (lama) => {
            if (lama === undefined) {
                return lama;
            }

            return {
                ...lama,
                data: {
                    jadwal: lama.data.jadwal.map((row) =>
                        row.id === baris.id ? baris : row,
                    ),
                },
            };
        },
    );
}

export function hapusJadwalCache(
    qc: QueryClient,
    dokterId: number,
    id: number,
): void {
    qc.setQueryData<ApiResult<{ jadwal: AdminJadwal[] }> | undefined>(
        [...adminJadwalQueryKey, dokterId],
        (lama) => {
            if (lama === undefined) {
                return lama;
            }

            return {
                ...lama,
                data: { jadwal: lama.data.jadwal.filter((row) => row.id !== id) },
            };
        },
    );
}

export function tambahLiburCache(
    qc: QueryClient,
    dokterId: number,
    libur: AdminLibur,
): void {
    qc.setQueryData<ApiResult<{ libur: AdminLibur[] }> | undefined>(
        [...adminLiburQueryKey, dokterId],
        (lama) => {
            if (lama === undefined) {
                return lama;
            }

            return {
                ...lama,
                data: { libur: [...lama.data.libur, libur] },
            };
        },
    );
}

export function hapusLiburCache(
    qc: QueryClient,
    dokterId: number,
    id: number,
): void {
    qc.setQueryData<ApiResult<{ libur: AdminLibur[] }> | undefined>(
        [...adminLiburQueryKey, dokterId],
        (lama) => {
            if (lama === undefined) {
                return lama;
            }

            return {
                ...lama,
                data: { libur: lama.data.libur.filter((row) => row.id !== id) },
            };
        },
    );
}
