import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import type { PesananObatTracking, StatusPesananObat } from '@/lib/api/types';

// ============================================================================
// Response shape — transcribed from KurirPesananResource
// ============================================================================

/**
 * One order as the courier portal sees it — deliberately narrower than
 * `PesananObat`. No `pasien_id`, no `resep_id`, no money columns.
 * Transcribed field by field from `KurirPesananResource::toArray()`.
 */
export type KurirPesanan = {
    id: number;
    nomor_pesanan: string;
    status: StatusPesananObat;
    /** `pesanan_obat.tipe` — always `resep_dokter` in practice. */
    tipe: string;
    alamat_kirim: string;
    /** Carrier name from the `KurirPesanan` ENUM, or `null` when unset. */
    kurir: string | null;
    no_resi: string | null;
    /** The pickup point. `null` when the relation was not loaded. */
    apotek: {
        id: number;
        nama: string;
        alamat: string;
    } | null;
    dibuat_at: string | null;
    diubah_at: string | null;
    /**
     * Present only on `GET /kurir/pesanan/{id}` (detail loads the relation).
     * Absent — not `null` — on the queue and on write responses.
     */
    tracking?: PesananObatTracking[];
};

// ============================================================================
// Write input shapes — transcribed from AmbilPesananRequest / AntarPesananRequest
// ============================================================================

/** `PUT /kurir/pesanan/{id}/ambil` body. All fields nullable/optional. */
export type AmbilPesananInput = {
    keterangan?: string | null;
    lokasi?: string | null;
    no_resi?: string | null;
};

/**
 * `PUT /kurir/pesanan/{id}/antar` body.
 * `no_resi` is `prohibited` here — the server rejects it with a 422.
 */
export type AntarPesananInput = {
    keterangan?: string | null;
    lokasi?: string | null;
};

// ============================================================================
// Fetchers
// ============================================================================

export async function fetchKurirAntrean() {
    return request<{ pesanan: KurirPesanan[] }>('kurir/pesanan');
}

export async function fetchKurirPesanan(id: number) {
    return request<{ pesanan: KurirPesanan }>(`kurir/pesanan/${id}`);
}

export async function ambilPesanan(id: number, input: AmbilPesananInput = {}) {
    return request<{ pesanan: KurirPesanan }>(`kurir/pesanan/${id}/ambil`, {
        method: 'PUT',
        json: input,
        retry: 0,
    });
}

export async function antarPesanan(id: number, input: AntarPesananInput = {}) {
    return request<{ pesanan: KurirPesanan }>(`kurir/pesanan/${id}/antar`, {
        method: 'PUT',
        json: input,
        retry: 0,
    });
}

// ============================================================================
// Query / mutation options
// ============================================================================

export const kurirAntreanQueryKey = ['v1', 'kurir', 'pesanan'] as const;

export function kurirAntreanOptions() {
    return queryOptions({
        queryKey: kurirAntreanQueryKey,
        queryFn: fetchKurirAntrean,
        refetchInterval: 30_000,
    });
}

export function kurirPesananOptions(id: number) {
    return queryOptions({
        queryKey: [...kurirAntreanQueryKey, id],
        queryFn: () => fetchKurirPesanan(id),
    });
}

/**
 * Claim a `siap` order: `siap -> sedang_dikirim`.
 *
 * On success, invalidates both the queue and the detail so both surfaces
 * reflect the new status without a manual refresh.
 */
export function ambilPesananMutation(id: number) {
    return mutationOptions({
        mutationFn: (input: AmbilPesananInput) => ambilPesanan(id, input),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: kurirAntreanQueryKey });
        },
    });
}

/**
 * Confirm delivery: `sedang_dikirim -> selesai`.
 *
 * Same invalidation as `ambilPesananMutation`.
 */
export function antarPesananMutation(id: number) {
    return mutationOptions({
        mutationFn: (input: AntarPesananInput) => antarPesanan(id, input),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: kurirAntreanQueryKey });
        },
    });
}
