import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import { z } from 'zod';
import type {
    Konsultasi,
    KonsultasiDaftar,
    KonsultasiPesan,
    StatusKonsultasiDasbor,
    TipePesanBerkas,
} from '@/lib/api/types';

/**
 * Module 3's seven consultation endpoints, all behind `auth:sanctum`.
 *
 * | method | path | guard | success |
 * | --- | --- | --- | --- |
 * | `POST` | `/api/v1/konsultasi/mulai` | owns a `pasien` row | 201 |
 * | `PUT` | `/api/v1/konsultasi/{id}/terima` | `tipe:dokter` + `konsultasi.mulai` | 200 |
 * | `PUT` | `/api/v1/konsultasi/{id}/selesai` | `tipe:dokter` + `konsultasi.selesai` | 200 |
 * | `GET` | `/api/v1/konsultasi/{id}` | a party to it, else 404 | 200 |
 * | `GET` | `/api/v1/konsultasi/{id}/chat` | a party to it, else 404 | 200 + `meta` |
 * | `POST` | `/api/v1/konsultasi/{id}/chat` | `konsultasi.chat` | 201 |
 * | `POST` | `/api/v1/konsultasi/{id}/chat/baca` | `konsultasi.chat` | 200 |
 *
 * ## The chat list is the one ASCENDING list in this application
 *
 * `KonsultasiService::riwayat()` orders `(terkirim_at, id)` - a chat transcript that
 * starts at the newest message is useless. `id` is the tiebreaker because
 * `terkirim_at` is a one-second-resolution `TIMESTAMP`, so a burst ties and MySQL is
 * free to return a tied block in any order, which would make the page boundary
 * non-deterministic and could drop or repeat a message between two pages.
 *
 * ## Every write is `retry: 0`
 *
 * A replayed `POST /chat` is a second message in a clinical transcript, and a
 * replayed `PUT /selesai` spends a one-shot state-machine transition. The shared
 * `QueryClient` already disables mutation retries; repeating it per call is the same
 * belt-and-braces `lib/api/booking.ts` argues for.
 */

/**
 * The five `tipe_pesan` values a human may send, in the DDL's order.
 *
 * The ENUM is eight members (`telemedicine_test.sql:568`-`:569`); `resep`,
 * `surat_keterangan` and `sistem` are `KonsultasiService::TIPE_PESAN_SISTEM` and are
 * written by the server when the corresponding document is created.
 * `KonsultasiService::kirim()` rejects the three with a 422 on `tipe_pesan`, so a
 * picker that offers only these five can never reach a refusal.
 */
export const TIPE_PESAN_KIRIM = ['teks', 'gambar', 'dokumen', 'audio', 'video_note'] as const;

/** `KonsultasiRequest::TIPE_PESAN_BERKAS`: the four that need an upload. */
export const TIPE_PESAN_BERKAS = ['gambar', 'dokumen', 'audio', 'video_note'] as const;

/** Does this message type carry a file rather than typed text? */
export function tipePesanBerkas(value: string): value is TipePesanBerkas {
    return (TIPE_PESAN_BERKAS as readonly string[]).includes(value);
}

export const TIPE_PESAN_LABEL: Readonly<Record<string, string>> = {
    teks: 'Teks',
    gambar: 'Gambar',
    dokumen: 'Dokumen',
    audio: 'Audio',
    video_note: 'Catatan video',
    resep: 'Resep',
    surat_keterangan: 'Surat keterangan',
    sistem: 'Sistem',
};

export const LABEL_PENGIRIM: Readonly<Record<string, string>> = {
    pasien: 'Pasien',
    dokter: 'Dokter',
    sistem: 'Sistem',
};

/**
 * The four statuses `GET /konsultasi` speaks, in the server's rank order.
 *
 * `KonsultasiService::STATUS_DASBOR`, restated: the same four values are the
 * endpoint's default listing, its `Rule::in` filter set and its `CASE` ordering
 * rank (most actionable first). A filter chip that offers only these four cannot
 * produce the 422 an out-of-set value would.
 */
export const STATUS_DASBOR_KONSULTASI: ReadonlyArray<StatusKonsultasiDasbor> = [
    'menunggu_dokter',
    'berlangsung',
    'menunggu_resep',
    'selesai',
];

const LABEL_STATUS_DASBOR: Record<StatusKonsultasiDasbor, string> = {
    menunggu_dokter: 'Menunggu diterima',
    berlangsung: 'Berlangsung',
    menunggu_resep: 'Menunggu resep',
    selesai: 'Selesai',
};

/** The F13 dashboard's wording, which prefers "Menunggu diterima" over the enum name. */
export function labelStatusKonsultasiDasbor(value: StatusKonsultasiDasbor): string {
    return LABEL_STATUS_DASBOR[value] ?? value;
}

/** `KonsultasiRequest::TIPE_LAYANAN` as the start endpoint narrows it. */
export const TIPE_KONSULTASI_LABEL: Readonly<Record<string, string>> = {
    chat: 'Chat',
    video_call: 'Video call',
    telepon: 'Telepon',
};

// ============================================================================
// The request bodies
// ============================================================================

/**
 * `POST /konsultasi/mulai`.
 *
 * Two mutually exclusive forms. `booking_id` starts the consultation a booking
 * reserved; `dokter_id` + `tipe` starts one with no booking at all, which works
 * because `konsultasi.booking_id` is `NULL UNIQUE` and MySQL allows any number of
 * NULLs in a UNIQUE index. Exactly one form must be sent, so the schema is a
 * discriminated union rather than two optional keys.
 */
export const mulaiKonsultasiSchema = z
    .object({
        booking_id: z.number().int().positive().optional(),
        dokter_id: z.number().int().positive().optional(),
        tipe: z.enum(['chat', 'video_call']).optional(),
    })
    .refine((value) => value.booking_id !== undefined || value.dokter_id !== undefined, {
        message: 'Kirim booking_id atau dokter_id, salah satu wajib.',
    })
    .refine(
        (value) =>
            !(value.booking_id !== undefined && value.dokter_id !== undefined) &&
            !(value.booking_id !== undefined && value.tipe !== undefined),
        { message: 'booking_id dan dokter_id adalah dua bentuk yang berbeda.' },
    );

export type MulaiKonsultasiInput = z.infer<typeof mulaiKonsultasiSchema>;

/**
 * `PUT /konsultasi/{id}/selesai`, the six SOAP fields, mirrored from
 * `SelesaikanKonsultasiRequest::rules()`.
 *
 * ## `catatan_asessment` is spelled the DDL's way, and it is the trap in this form
 *
 * `telemedicine_test.sql:550` writes `catatan_asessment`, one `s` short of the
 * English word, and `KonsultasiService::KOLOM_SOAP` publishes that exact spelling as
 * the writable list. It looks like a typo; it is not one, and "fixing" it here is the
 * single most damaging edit that could be made to this file.
 *
 * ## Why the `array_key_exists` guard is safe server-side, and what it means here
 *
 * `KonsultasiService::tulisSoap()` skips a column that is ABSENT from the payload, so
 * a field the doctor left out keeps whatever it held. That same guard used to swallow
 * a MISSPELLED key silently: the request validated, the service accepted it, and the
 * value was written nowhere. It is now closed from both sides - `tulisSoap()` throws
 * a `LogicException` on any key outside `KOLOM_SOAP`, and `KonsultasiRequest` refuses
 * an undeclared key at the top level.
 *
 * That server guard is precisely why this schema must be a CLOSED zod object rather
 * a loose record: `z.object()` strips an unknown key by default, which would turn a
 * typo in this file into a silently-omitted field and reproduce the original defect
 * one layer down. `.strict()` refuses it loudly instead.
 *
 * ## `max:255` on `diagnosis_kerja` is the DDL's, not a business limit
 *
 * It is `VARCHAR(255) NULL` at `:552` and the only one of the six with a length the
 * database itself enforces. The other five are `TEXT` capped at `max:16000`, which
 * is deliberately under MySQL's 65535-BYTE `TEXT` ceiling because the table is
 * utf8mb4 and one character can be four bytes.
 */
const soapText = z.string().trim().max(16_000);

export const soapSchema = z
    .object({
        catatan_subjektif: z.union([soapText, z.null()]).optional(),
        catatan_objektif: z.union([soapText, z.null()]).optional(),
        catatan_asessment: z.union([soapText, z.null()]).optional(),
        catatan_plan: z.union([soapText, z.null()]).optional(),
        diagnosis_kerja: z.union([z.string().trim().max(255), z.null()]).optional(),
        saran_tindak_lanjut: z.union([soapText, z.null()]).optional(),
    })
    .strict();

export type SoapInput = z.infer<typeof soapSchema>;

/** `POST /konsultasi/{id}/chat`, the typed half. `berkas` goes in a FormData body. */
export const kirimPesanSchema = z
    .object({
        tipe_pesan: z.enum(TIPE_PESAN_KIRIM),
        isi: z.union([soapText, z.null()]).optional(),
    })
    .refine((value) => value.tipe_pesan !== 'teks' || (value.isi ?? '') !== '', {
        message: 'Pesan teks tidak boleh kosong.',
        path: ['isi'],
    });

export type KirimPesanInput = z.infer<typeof kirimPesanSchema>;

/** `GET /konsultasi/{id}/chat`'s query string. `per_page` is capped at 100 server-side. */
export type RiwayatPesanFilters = {
    page: number;
    per_page: number;
};

/**
 * `GET /konsultasi`'s query string, from `IndexKonsultasiRequest`.
 *
 * `status` is the closed `STATUS_DASBOR_KONSULTASI` set and an absent value means the
 * whole set; `per_page` is capped at 100 through `PasienRecordAccess::perPage()`, so
 * the caller reads the applied size back from `meta.per_page` rather than assuming
 * what it sent.
 */
export type KonsultasiDaftarFilters = {
    page: number;
    per_page: number;
    status?: StatusKonsultasiDasbor;
};

// ============================================================================
// The calls
// ============================================================================

export async function mulaiKonsultasi(input: MulaiKonsultasiInput) {
    return request<{ konsultasi: Konsultasi }>('konsultasi/mulai', {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

export async function terimaKonsultasi(id: number) {
    return request<{ konsultasi: Konsultasi }>(`konsultasi/${id}/terima`, {
        method: 'PUT',
        retry: 0,
    });
}

/**
 * `PUT /konsultasi/{id}/selesai`, and the check that the write landed.
 *
 * The response carries the consultation back **after** the transaction, so a caller
 * that only fires the request and toasts on 200 has proved nothing: it has proved
 * the request was accepted, not that the six fields were stored. This returns the
 * row so the form can assert that each submitted field reads back equal to what was
 * sent - which is the only client-side check that catches a key the service silently
 * did not write, which is the defect class `tulisSoap()` was hardened against.
 */
export async function selesaiKonsultasi(id: number, soap: SoapInput) {
    return request<{ konsultasi: Konsultasi }>(`konsultasi/${id}/selesai`, {
        method: 'PUT',
        json: soap,
        retry: 0,
    });
}

export async function fetchKonsultasi(id: number) {
    return request<{ konsultasi: Konsultasi }>(`konsultasi/${id}`);
}

/**
 * `GET /api/v1/konsultasi` - the caller's own doctor-side worklist.
 *
 * Doctor-only (`tipe:dokter` at the route), and the tenant filter is the query, so
 * another doctor's rows are absent rather than refused: there is no 403/404 oracle
 * to probe. Rows are `KonsultasiDaftarResource`, the allow-list shape that carries
 * no NIK, no contact detail, no fee and no medical note.
 */
export async function fetchKonsultasiDaftar(filters: KonsultasiDaftarFilters) {
    return request<{ konsultasi: KonsultasiDaftar[] }>('konsultasi', {
        searchParams: {
            page: filters.page,
            per_page: filters.per_page,
            ...(filters.status === undefined ? {} : { status: filters.status }),
        },
    });
}

export async function fetchRiwayatPesan(
    id: number,
    filters: RiwayatPesanFilters,
) {
    return request<{ pesan: KonsultasiPesan[] }>(`konsultasi/${id}/chat`, {
        searchParams: {
            page: filters.page,
            per_page: filters.per_page,
        },
    });
}

/**
 * A typed message. `retry: 0` is not a default here but a requirement: a replay is
 * a second line in a clinical transcript.
 */
export async function kirimPesan(id: number, input: KirimPesanInput) {
    return request<{ pesan: KonsultasiPesan }>(`konsultasi/${id}/chat`, {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

/** An attachment. `multipart` so the file is a real upload rather than base64. */
export async function kirimBerkas(
    id: number,
    tipePesan: TipePesanBerkas,
    berkas: File,
    isi: string | null,
) {
    const form = new FormData();

    form.append('tipe_pesan', tipePesan);
    form.append('berkas', berkas);

    if (isi !== null && isi !== '') {
        form.append('isi', isi);
    }

    return request<{ pesan: KonsultasiPesan }>(`konsultasi/${id}/chat`, {
        method: 'POST',
        body: form,
        retry: 0,
    });
}

/** `POST /chat/baca`. The response counts the OTHER party's messages that moved. */
export async function tandaiPesanDibaca(id: number) {
    return request<{
        konsultasi_id: number;
        jumlah_ditandai_baca: number;
    }>(`konsultasi/${id}/chat/baca`, {
        method: 'POST',
        retry: 0,
    });
}

// ============================================================================
// Cache keys
// ============================================================================

export const konsultasiQueryKey = ['v1', 'konsultasi'] as const;

export const riwayatPesanQueryKey = ['v1', 'konsultasi', 'chat'] as const;

export const konsultasiDaftarQueryKey = ['v1', 'konsultasi', 'daftar'] as const;

export function konsultasiOptions(id: number) {
    return queryOptions({
        queryKey: [...konsultasiQueryKey, id],
        queryFn: () => fetchKonsultasi(id),
    });
}

export function konsultasiDaftarOptions(filters: KonsultasiDaftarFilters) {
    return queryOptions({
        queryKey: [...konsultasiDaftarQueryKey, filters],
        queryFn: () => fetchKonsultasiDaftar(filters),
    });
}

export function riwayatPesanOptions(
    id: number,
    filters: RiwayatPesanFilters,
) {
    return queryOptions({
        queryKey: [...riwayatPesanQueryKey, id, filters],
        queryFn: () => fetchRiwayatPesan(id, filters),
    });
}

/**
 * A send invalidates the transcript, and deliberately nothing else.
 *
 * The realtime layer owns the live append; refetching the whole history after every
 * message would be both a wasted request and a second render of every row, which is
 * the failure `dedupe.ts` exists to prevent. The invalidation is what covers the case
 * the socket cannot: a send from ANOTHER device of the SAME account, where no
 * broadcast is owed to this client because it published on the other one.
 */
export function kirimPesanMutation(id: number) {
    return mutationOptions({
        mutationFn: (input: KirimPesanInput) => kirimPesan(id, input),
        onSuccess: () => {
            void queryClient.invalidateQueries({
                queryKey: [...riwayatPesanQueryKey, id],
            });
        },
    });
}

export function selesaiKonsultasiMutation(id: number) {
    return mutationOptions({
        mutationFn: (soap: SoapInput) => selesaiKonsultasi(id, soap),
        onSuccess: (result) => {
            queryClient.setQueryData(
                [...konsultasiQueryKey, id],
                result,
            );
        },
    });
}

export function terimaKonsultasiMutation(id: number) {
    return mutationOptions({
        mutationFn: () => terimaKonsultasi(id),
        onSuccess: (result) => {
            queryClient.setQueryData([...konsultasiQueryKey, id], result);

            /**
             * Accepting moves the row from `menunggu_dokter` to `berlangsung`, which
             * changes both its status and its rank in the dashboard's ordering, so the
             * worklist prefix is invalidated as well as the detail. The prefix is
             * `konsultasiDaftarQueryKey`, not `konsultasiQueryKey`: the latter would
             * also refetch every cached chat transcript on every accept.
             */
            void queryClient.invalidateQueries({
                queryKey: konsultasiDaftarQueryKey,
            });
        },
    });
}
