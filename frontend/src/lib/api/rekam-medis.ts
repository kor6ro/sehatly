import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import { z } from 'zod';
import type {
    AksesRekamMedis,
    PeranAkses,
    RekamMedis,
    RekamMedisDaftar,
    TujuanAkses,
} from '@/lib/api/types';

/**
 * The seven medical-record endpoints.
 *
 * | method | path | guard | success |
 * | --- | --- | --- | --- |
 * | `POST` | `/api/v1/konsultasi/{id}/rekam-medis` | `tipe:dokter` + `rekam_medis.simpan` | 201, a DRAFT |
 * | `PUT` | `/api/v1/rekam-medis/{id}` | `tipe:dokter` + `rekam_medis.simpan` | 200, draft only |
 * | `PUT` | `/api/v1/rekam-medis/{id}/final` | `tipe:dokter` + `rekam_medis.final` | 200 |
 * | `POST` | `/api/v1/rekam-medis/{id}/amandemen` | `tipe:dokter` + `rekam_medis.final` | 201, TWO rows after |
 * | `GET` | `/api/v1/rekam-medis/{id}` | a party to it, else 404 | 200 + ONE access-log row |
 * | `GET` | `/api/v1/rekam-medis` | a `pasien` row, else 403 | 200 + meta, writes NO log row |
 * | `GET` | `/api/v1/rekam-medis/{id}/akses` | same as the detail read | 200 + meta, writes NO log row |
 *
 * ## Every successful read writes an `akses_rekam_medis_log` row
 *
 * `RekamMedisService::findForAccess()` is the only way to read a record in the
 * application, and it delegates to `RekamMedisAccessLogger::baca()`, which writes the
 * log row and performs the read in one transaction. A stranger gets 404 and zero log
 * rows; an account with no profile row gets 403 and zero log rows; a success writes
 * exactly one.
 *
 * The consequence for this client is the rule the query layer has to honour:
 *
 * - **No `staleTime` infinity and no `gcTime` infinity on the read query.** A cached
 *   record that renders without a network call is a render that is not in the access
 *   log, and the log is the compliance record, not a diagnostic. The module-wide
 *   `staleTime: 30_000` is the honest compromise: it is a short freshness window, it
 *   is shared with every other screen, and going under it would mean a refetch on
 *   every focus change.
 * - **No fetch on render, and no refetch keyed on a changing value.** A query whose
 *   `queryKey` includes a fresh `Date.now()` is a read per render. The key here is
 *   the record id and nothing else.
 * - **One read, not one per widget.** `RekamMedisResource` publishes the whole
 *   document, the four child collections and the `ran` chain in a single response, so
 *   there is nothing to fan out over. The chain is published as a reduced projection
 *   deliberately: opening a revision is a SEPARATE read and a separate log row, which
 *   is the correct accounting rather than a limitation to work around.
 *
 * `refetchOnWindowFocus` is `false` project-wide, which is what keeps a tab-switch
 * from manufacturing an access-log row per focus event.
 */

export const STATUS_DOKUMEN_LABEL: Readonly<Record<string, string>> = {
    draft: 'Draft',
    final: 'Final',
    diamendemen: 'Diamendemen',
};

export const LABEL_TIPE_KUNJUNGAN: Readonly<Record<string, string>> = {
    telemedisin: 'Telemedisin',
    rawat_jalan: 'Rawat jalan',
    rawat_inap: 'Rawat inap',
    igd: 'IGD',
    home_visit: 'Kunjungan rumah',
};

export const LABEL_STATUS_TINDAK_LANJUT: Readonly<Record<string, string>> = {
    pulang_dengan_obat: 'Pulang dengan obat',
    kontrol: 'Kontrol',
    rujuk: 'Rujuk',
    rawat_inap: 'Rawat inap',
    ke_igd: 'Ke IGD',
};

export const LABEL_JENIS_DIAGNOSA: Readonly<Record<string, string>> = {
    utama: 'Utama',
    sekunder: 'Sekunder',
    diferensial: 'Diferensial',
    komplikasi: 'Komplikasi',
};

export const LABEL_TIPE_LAMPIRAN: Readonly<Record<string, string>> = {
    hasil_lab: 'Hasil lab',
    radiologi: 'Radiologi',
    foto_klinis: 'Foto klinis',
    dokumen_lain: 'Dokumen lain',
};

/**
 * The fourteen writable content columns, in `RekamMedisService::KOLOM_ISI` order.
 *
 * ## `.strict()` is load-bearing, twice over
 *
 * The server refuses an undeclared top-level key with a message naming it, and
 * `RekamMedisService::tolakKolomAsing()` throws a `LogicException` on one it reaches
 * by another path. `z.object()` would instead **strip** an unknown key, turning a
 * typo in this file into a silently omitted field that validates, is accepted, and
 * writes nowhere - the exact defect class `tulisSoap()` was hardened against. So both
 * schemas below are `.strict()` and a typo fails at the boundary.
 *
 * ## The ceilings are the DDL's
 *
 * `diagnosis_kerja` is `VARCHAR(255)` at `:641` and is the only one of the fourteen
 * with a length the database enforces. The rest are `TEXT` capped at 16000, under
 * MySQL's 65535-BYTE ceiling because the table is utf8mb4 and a character can be four
 * bytes.
 *
 * `jadwal_kontrol` is a `DATE` (`date_format:Y-m-d`), and `Y-m-d` rather than
 * `Date.toISOString()` is deliberate: a `Date` serialised that way is UTC midnight, so
 * a local-calendar read-back in a negative offset shifts the appointment a day.
 */
const text = z.string().trim().max(16_000);

export const rekamMedisIsiSchema = z
    .object({
        keluhan_utama: z.union([text, z.null()]).optional(),
        riwayat_penyakit_sekarang: z.union([text, z.null()]).optional(),
        riwayat_penyakit_dahulu: z.union([text, z.null()]).optional(),
        riwayat_keluarga: z.union([text, z.null()]).optional(),
        riwayat_psikososial: z.union([text, z.null()]).optional(),
        hasil_pemeriksaan_fisik: z.union([text, z.null()]).optional(),
        subjektif: z.union([text, z.null()]).optional(),
        objektif: z.union([text, z.null()]).optional(),
        asesmen: z.union([text, z.null()]).optional(),
        plan: z.union([text, z.null()]).optional(),
        diagnosis_kerja: z.union([z.string().trim().max(255), z.null()]).optional(),
        instruksi_tindak_lanjut: z.union([text, z.null()]).optional(),
        status_tindak_lanjut: z
            .enum([
                'pulang_dengan_obat',
                'kontrol',
                'rujuk',
                'rawat_inap',
                'ke_igd',
            ])
            .nullable()
            .optional(),
        jadwal_kontrol: z
            .string()
            .regex(/^\d{4}-\d{2}-\d{2}$/, 'Format jadwal kontrol adalah Y-m-d.')
            .nullable()
            .optional(),
    })
    .strict();

export type RekamMedisIsi = z.infer<typeof rekamMedisIsiSchema>;

/**
 * `POST /konsultasi/{id}/rekam-medis` and `PUT /rekam-medis/{id}`.
 *
 * `tanggal_periksa` is `datetime NOT NULL` at `:630` and is offered on the create
 * and on the draft edit, but **not** on an amendment: it is part of the chain group
 * `(pasien_id, dokter_id, tanggal_periksa)`, so an amendment that moved it would be
 * filed in a different chain from the record it supersedes - two unrelated rows
 * carrying consecutive version numbers with the history split in half. The server
 * marks it `prohibited` inside `perubahan` and this schema cannot express it.
 */
export const simpanRekamMedisSchema = rekamMedisIsiSchema.extend({
    tanggal_periksa: z.string().datetime({ offset: true }).optional(),
});

export type SimpanRekamMedisInput = z.infer<typeof simpanRekamMedisSchema>;

/**
 * `POST /rekam-medis/{id}/amandemen`: the change set is NESTED under `perubahan`.
 *
 * `PUT` is flat because it is a partial update of a document a client already holds a
 * representation of. An amendment is a SET OF CHANGES, and nesting it means the error
 * envelope can say the changes you sent are wrong rather than blaming a clinical field
 * for a fact about the request's shape. It also means the two calls cannot be
 * confused for one another.
 */
export const amandemenSchema = z
    .object({
        perubahan: rekamMedisIsiSchema,
    })
    .strict();

export type AmandemenInput = z.infer<typeof amandemenSchema>;

// ============================================================================
// The calls
// ============================================================================

export async function simpanRekamMedis(konsultasiId: number, input: SimpanRekamMedisInput) {
    return request<{ rekam_medis: RekamMedis }>(
        `konsultasi/${konsultasiId}/rekam-medis`,
        { method: 'POST', json: input, retry: 0 },
    );
}

export async function ubahRekamMedis(id: number, input: RekamMedisIsi) {
    return request<{ rekam_medis: RekamMedis }>(`rekam-medis/${id}`, {
        method: 'PUT',
        json: input,
        retry: 0,
    });
}

export async function finalisasiRekamMedis(id: number) {
    return request<{ rekam_medis: RekamMedis }>(`rekam-medis/${id}/final`, {
        method: 'PUT',
        retry: 0,
    });
}

/**
 * `POST /rekam-medis/{id}/amandemen`, and TWO rows exist afterwards.
 *
 * The original is never touched: the service inserts a new row at `MAX(versi) + 1`
 * over the chain group, with a fresh `uuid` and `status_dokumen = 'diamendemen'`. So
 * the response is the NEW row, and the chain the caller then holds has one more
 * entry and a different head. Refetching is therefore not optional here, and this
 * mutation does it rather than leaving the caller with a stale `ran` that is missing
 * the revision it just created.
 */
export async function amandemenRekamMedis(id: number, input: AmandemenInput) {
    return request<{ rekam_medis: RekamMedis }>(`rekam-medis/${id}/amandemen`, {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

export async function fetchRekamMedis(id: number) {
    return request<{ rekam_medis: RekamMedis }>(`rekam-medis/${id}`);
}

// ============================================================================
// Cache keys
// ============================================================================

export const rekamMedisQueryKey = ['v1', 'rekam-medis'] as const;

export function rekamMedisOptions(id: number) {
    return queryOptions({
        queryKey: [...rekamMedisQueryKey, id],
        queryFn: () => fetchRekamMedis(id),
    });
}

/**
 * The three writes that change a record's content replace the cache from the
 * response rather than invalidating it.
 *
 * A refetch would be a **second** `GET /rekam-medis/{id}` and therefore a second
 * `akses_rekam_medis_log` row for a read the client did not need: the response to
 * every one of these calls already carries the whole document, the four child
 * collections and the refreshed `ran` chain. Replacing the cache is both cheaper and
 * the honest accounting - one user action, one read of the record.
 */
export function simpanRekamMedisMutation(konsultasiId: number) {
    return mutationOptions({
        mutationFn: (input: SimpanRekamMedisInput) =>
            simpanRekamMedis(konsultasiId, input),
        onSuccess: (result) => {
            queryClient.setQueryData(
                [...rekamMedisQueryKey, result.data.rekam_medis.id],
                result,
            );
        },
    });
}

export function ubahRekamMedisMutation(id: number) {
    return mutationOptions({
        mutationFn: (input: RekamMedisIsi) => ubahRekamMedis(id, input),
        onSuccess: (result) => {
            queryClient.setQueryData([...rekamMedisQueryKey, id], result);
        },
    });
}

export function finalisasiRekamMedisMutation(id: number) {
    return mutationOptions({
        mutationFn: () => finalisasiRekamMedis(id),
        onSuccess: (result) => {
            queryClient.setQueryData([...rekamMedisQueryKey, id], result);
        },
    });
}

export function amandemenRekamMedisMutation(id: number) {
    return mutationOptions({
        mutationFn: (input: AmandemenInput) => amandemenRekamMedis(id, input),
        onSuccess: (result) => {
            queryClient.setQueryData(
                [...rekamMedisQueryKey, result.data.rekam_medis.id],
                result,
            );
        },
    });
}

// ============================================================================
// The list index and the access log: two reads that write NO log row
// ============================================================================

/**
 * The Indonesian label for each `users.tipe` value the access log publishes.
 *
 * Keyed on {@link PeranAkses}, so a new account type in the generated `users.tipe`
 * enum fails the type check here rather than rendering a raw column value.
 */
export const LABEL_PERAN_AKSES: Readonly<Record<PeranAkses, string>> = {
    pasien: 'Pasien',
    dokter: 'Dokter',
    perawat: 'Perawat',
    apoteker: 'Apoteker',
    kurir: 'Kurir',
    admin: 'Admin',
    superadmin: 'Superadmin',
};

/**
 * The Indonesian label for each `akses_rekam_medis_log.tujuan_akses` value.
 *
 * The vocabulary is closed and comes from `docs/enums.json`, so this map is total over
 * the five generated values.
 */
export const LABEL_TUJUAN_AKSES: Readonly<Record<TujuanAkses, string>> = {
    perawatan: 'Perawatan',
    klaim: 'Klaim',
    audit: 'Audit',
    pasien_sendiri: 'Pasien sendiri',
    kepentingan_hukum: 'Kepentingan hukum',
};

/** `GET /rekam-medis`'s query string. `per_page` is capped at 100 server-side. */
export type DaftarRekamMedisFilters = {
    page: number;
    per_page: number;
};

/**
 * `GET /api/v1/rekam-medis` - the caller patient's OWN list.
 *
 * `RekamMedisService::daftar()` selects through `DB::table()`, so no `RekamMedis`
 * model is hydrated and no `akses_rekam_medis_log` row is written: a list names no
 * single record, and one row per listed record would falsify the trail. That is what
 * makes this the primary source for the hub - the detail read is reserved for a row
 * the patient actually opens.
 */
export async function fetchDaftarRekamMedis(filters: DaftarRekamMedisFilters) {
    return request<{ rekam_medis: RekamMedisDaftar[] }>('rekam-medis', {
        searchParams: {
            page: filters.page,
            per_page: filters.per_page,
        },
    });
}

/**
 * The index's cache key.
 *
 * A distinct third segment (`'daftar'`) keeps it apart from the detail key
 * `['v1','rekam-medis', id]`; the two must never share a cache entry, because one
 * writes an access-log row and the other does not.
 */
export const daftarRekamMedisQueryKey = ['v1', 'rekam-medis', 'daftar'] as const;

export function daftarRekamMedisOptions(filters: DaftarRekamMedisFilters) {
    return queryOptions({
        queryKey: [...daftarRekamMedisQueryKey, filters],
        queryFn: () => fetchDaftarRekamMedis(filters),
    });
}

/** `GET /rekam-medis/{id}/akses`'s query string. */
export type AksesRekamMedisFilters = {
    page: number;
    per_page: number;
};

/**
 * `GET /api/v1/rekam-medis/{id}/akses` - the access history of ONE record.
 *
 * It reads the log ABOUT the record rather than the record itself, so it writes no
 * `akses_rekam_medis_log` row. Ownership is answered by the same resolver as the
 * detail read: a non-party gets 404 and an account with no profile gets 403.
 */
export async function fetchAksesRekamMedis(
    id: number,
    filters: AksesRekamMedisFilters,
) {
    return request<{ akses: AksesRekamMedis[] }>(`rekam-medis/${id}/akses`, {
        searchParams: {
            page: filters.page,
            per_page: filters.per_page,
        },
    });
}

export function aksesRekamMedisOptions(id: number, filters: AksesRekamMedisFilters) {
    return queryOptions({
        queryKey: [...rekamMedisQueryKey, id, 'akses', filters],
        queryFn: () => fetchAksesRekamMedis(id, filters),
    });
}
