import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import type {
    JenisPersetujuanPdp,
    PdpDokumen,
    PersetujuanPdp,
} from '@/lib/api/types';

/**
 * F02's three PDP endpoints.
 *
 * | method | path | `data` | meta |
 * | --- | --- | --- | --- |
 * | `GET` | `/api/v1/pdp/dokumen` | `{ dokumen }` | `singlePageMeta(5)` |
 * | `GET` | `/api/v1/pdp/persetujuan` | `{ persetujuan }` | `singlePageMeta(5)` |
 * | `POST` | `/api/v1/pdp/persetujuan` | `{ persetujuan }` | none |
 *
 * ## The server is the version authority, and this module never guesses one
 *
 * `dokumenOptions()` is the read a decision depends on: the active `versi_dokumen` per
 * `jenis` comes from there and is echoed back on the write. There is deliberately no
 * client-side version constant, no `naikVersi()`, and no "send a higher version to
 * withdraw" - the backend refuses any version that is not the active one with a 422 on
 * `versi_dokumen`, and withdrawal is a new row on the same active version.
 *
 * ## Why the mutation invalidates BOTH reads
 *
 * A 201 appends a row, so `persetujuan` is stale. A 200 is idempotent and writes nothing,
 * but the client cannot tell from its own state whether the row it holds is still the
 * effective one. The `dokumen` read is invalidated as well because the only 422 this
 * write can produce is a version collision, and the controller's instruction for that
 * case is "refetch the catalogue and resend" - so the cache is refreshed whether the
 * write succeeded or was refused.
 *
 * ## There is no polling and no Reverb subscription
 *
 * F02 is not a consultation channel. The screen refetches on invalidation and on a
 * user-visible retry, and it never claims the data is live.
 */

/** `persetujuan_pdp.jenis` in DDL order, which is the order the API publishes. */
export const JENIS_PERSETUJUAN_PDP: ReadonlyArray<JenisPersetujuanPdp> = [
    'syarat_ketentuan',
    'kebijakan_privasi',
    'berbagi_data_medis',
    'pemasaran',
    'komunikasi_tindak_lanjut',
];

/**
 * The three consents that gate a booking or a payment.
 *
 * Owner decision 2026-10-01: `syarat_ketentuan`, `kebijakan_privasi` and
 * `berbagi_data_medis` are required before the first booking or payment. `pemasaran` and
 * `komunikasi_tindak_lanjut` are optional by design and never gate anything: refusing a
 * promotion must not cost a patient access to care.
 */
export const PERSETUJUAN_WAJIB: ReadonlyArray<JenisPersetujuanPdp> = [
    'syarat_ketentuan',
    'kebijakan_privasi',
    'berbagi_data_medis',
];

const LABEL_JENIS: Record<JenisPersetujuanPdp, string> = {
    syarat_ketentuan: 'Syarat dan ketentuan',
    kebijakan_privasi: 'Kebijakan privasi',
    berbagi_data_medis: 'Berbagi data medis',
    pemasaran: 'Informasi dan promosi',
    komunikasi_tindak_lanjut: 'Pesan tindak lanjut',
};

export function labelJenis(jenis: JenisPersetujuanPdp): string {
    return LABEL_JENIS[jenis];
}

/** The write body, and the three keys `StorePersetujuanPdpRequest` accepts. */
export type CatatPersetujuanInput = {
    jenis: JenisPersetujuanPdp;
    versi_dokumen: string;
    disetujui: boolean;
};

export async function fetchDokumen() {
    return request<{ dokumen: PdpDokumen[] }>('pdp/dokumen');
}

export async function fetchPersetujuan() {
    return request<{ persetujuan: PersetujuanPdp[] }>('pdp/persetujuan');
}

export async function catatPersetujuan(input: CatatPersetujuanInput) {
    return request<{ persetujuan: PersetujuanPdp }>('pdp/persetujuan', {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

export const pdpDokumenQueryKey = ['v1', 'pdp', 'dokumen'] as const;

export const pdpPersetujuanQueryKey = ['v1', 'pdp', 'persetujuan'] as const;

export function dokumenOptions() {
    return queryOptions({
        queryKey: pdpDokumenQueryKey,
        queryFn: fetchDokumen,
    });
}

export function persetujuanOptions() {
    return queryOptions({
        queryKey: pdpPersetujuanQueryKey,
        queryFn: fetchPersetujuan,
    });
}

export function catatPersetujuanMutation() {
    return mutationOptions({
        mutationFn: catatPersetujuan,
        onSuccess: () => {
            void queryClient.invalidateQueries({
                queryKey: pdpPersetujuanQueryKey,
            });

            void queryClient.invalidateQueries({
                queryKey: pdpDokumenQueryKey,
            });
        },
    });
}

/**
 * The active version of one `jenis`, or `null` while the catalogue is unavailable.
 *
 * A `null` return must block the write rather than produce an empty string: the request
 * would otherwise be a 422 about `versi_dokumen.required` instead of the truth, which is
 * that the client does not yet know what version to send.
 */
export function versiAktif(
    dokumen: ReadonlyArray<PdpDokumen> | undefined,
    jenis: JenisPersetujuanPdp,
): string | null {
    return dokumen?.find((entri) => entri.jenis === jenis)?.versi_dokumen ?? null;
}

/**
 * Which of the three mandatory consents are not `true`, in DDL order.
 *
 * `efektif !== true` rather than `=== false` on purpose: `null` means "not answered" and
 * `false` means "refused", and both must block. Collapsing them is the gate bug this
 * function exists to prevent.
 */
export function persetujuanBelumDiberikan(
    persetujuan: ReadonlyArray<PersetujuanPdp> | undefined,
): JenisPersetujuanPdp[] {
    return PERSETUJUAN_WAJIB.filter((jenis) => {
        const entri = persetujuan?.find((row) => row.jenis === jenis);

        return entri?.efektif !== true;
    });
}
