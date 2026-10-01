import { mutationOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';
import { riwayatPesanQueryKey } from '@/lib/api/konsultasi';
import type {
    RujukanHasil,
    SuratKeterangan,
    TipeSuratKeterangan,
} from '@/lib/api/types';

/**
 * `POST /api/v1/konsultasi/{id}/surat-keterangan` - the four-value letter vocabulary
 * and its one write call.
 *
 * The endpoint carries `permission:surat_keterangan.buat` and `tipe:dokter`, so only
 * a doctor issues a letter; a referral additionally requires an approved
 * `berbagi_data_medis` consent server-side, which can answer 403 and is surfaced
 * rather than retried.
 */

/** `SuratKeteranganTipe::nilai()`, the closed set `Rule::in` accepts. */
export const TIPE_SURAT_KETERANGAN: ReadonlyArray<TipeSuratKeterangan> = [
    'surat_sakit',
    'surat_sehat',
    'surat_rujukan',
    'surat_kematian',
];

const LABEL_TIPE_SURAT: Record<TipeSuratKeterangan, string> = {
    surat_sakit: 'Surat sakit',
    surat_sehat: 'Surat sehat',
    surat_rujukan: 'Surat rujukan',
    surat_kematian: 'Surat keterangan kematian',
};

export function labelTipeSuratKeterangan(value: TipeSuratKeterangan): string {
    return LABEL_TIPE_SURAT[value] ?? value;
}

/**
 * `BuatSuratKeteranganRequest::rules()`'s writable subset.
 *
 * The identity columns (`pasien_id`, `dokter_id`, `nomor_surat`, `qr_token`,
 * `jumlah_hari`, `file_url`) are `prohibited` server-side and are deliberately absent
 * here: the patient is read off the consultation and the doctor off the account.
 * Every remaining key is optional because whether the referral keys are mandatory
 * depends on `tipe`, and the service is the single owner of that rule.
 */
export type BuatSuratKeteranganInput = {
    tipe: TipeSuratKeterangan;
    /** `Y-m-d`, a wall-clock day. */
    tanggal_mulai?: string;
    /** `Y-m-d`. */
    tanggal_selesai?: string;
    isi?: string;
    faskes_tujuan_id?: number;
    diagnosis_kerja?: string;
    icd10_kode?: string;
    alasan_rujukan?: string;
    /** `Y-m-d`. */
    berlaku_sampai?: string;
    nomor_sep?: string;
};

export type HasilSuratKeterangan = {
    surat_keterangan: SuratKeterangan;
    /**
     * The referral as a single object, or `null` for a letter that is not a
     * `surat_rujukan`. The controller states this explicitly rather than publishing
     * an empty list.
     */
    rujukan: RujukanHasil | null;
};

export async function buatSuratKeterangan(
    konsultasiId: number,
    input: BuatSuratKeteranganInput,
) {
    return request<HasilSuratKeterangan>(
        `konsultasi/${konsultasiId}/surat-keterangan`,
        {
            method: 'POST',
            json: input,
            retry: 0,
        },
    );
}

/**
 * Issuing a letter writes a `surat_keterangan` system line into the consultation's
 * transcript, so the transcript prefix is invalidated. A replayed POST is a second
 * letter, which is why the call passes `retry: 0`.
 */
export function buatSuratKeteranganMutation(konsultasiId: number) {
    return mutationOptions({
        mutationFn: (input: BuatSuratKeteranganInput) =>
            buatSuratKeterangan(konsultasiId, input),
        onSuccess: () => {
            void queryClient.invalidateQueries({
                queryKey: [...riwayatPesanQueryKey, konsultasiId],
            });
        },
    });
}
