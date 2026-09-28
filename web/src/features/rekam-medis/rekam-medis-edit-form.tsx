import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { Loader2, Lock, Pencil, Save, Stamp } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { dispatchFlash } from '@/lib/flash';
import {
    amandemenRekamMedisMutation,
    finalisasiRekamMedisMutation,
    rekamMedisIsiSchema,
    ubahRekamMedisMutation,
    type RekamMedisIsi,
} from '@/lib/api/rekam-medis';
import type { RekamMedis } from '@/lib/api/types';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldTextarea, FormErrorSummary } from '@/components/form/field';
import { StatusDokumenBadge, VersiBadge } from '@/features/konsultasi/status-badge';

/**
 * The doctor-side editor, with the two actions a signed record actually has.
 *
 * ## A draft has one action; a signed record has two different ones
 *
 * `status_dokumen` is the whole gate:
 *
 * | status | `PUT /rekam-medis/{id}` | `PUT .../final` | `POST .../amandemen` |
 * | --- | --- | --- | --- |
 * | `draft` | in-place edit | sign it | - |
 * | `final` | **422**, points at `/amandemen` | **422**, already signed | new row at `MAX(versi) + 1` |
 * | `diamendemen` | 422 | 422 | new row |
 *
 * The three are not the same request and the difference is not cosmetic. An
 * amendment does not touch the signed row: it inserts a NEW row with a fresh `uuid`
 * and `status_dokumen = 'diamendemen'`, so the chain keeps every revision and the
 * superseded one is left byte-identical. So the amendment form is a SEPARATE form
 * with a separate submit and an explicit label - not the edit form with a different
 * button - because a doctor amending a signed note is making a clinical act, and the
 * UI says so.
 *
 * `tanggal_periksa` is absent from both forms on purpose. It is part of the chain
 * group `(pasien_id, dokter_id, tanggal_periksa)`, and an amendment that moved it
 * would file the new revision in a different chain from the one it supersedes. The
 * server marks it `prohibited` inside `perubahan`; the schema here cannot express it.
 */
export function RekamMedisEditForm({ rekam }: { rekam: RekamMedis }) {
    const [mode, setMode] = useState<'edit' | 'amandemen'>('edit');

    const ubah = useMutation(ubahRekamMedisMutation(rekam.id));
    const finalisasi = useMutation(finalisasiRekamMedisMutation(rekam.id));
    const amandemen = useMutation(amandemenRekamMedisMutation(rekam.id));

    const form = useForm<RekamMedisIsi>({
        resolver: zodResolver(rekamMedisIsiSchema),
        defaultValues: {
            keluhan_utama: rekam.keluhan_utama,
            riwayat_penyakit_sekarang: rekam.riwayat_penyakit_sekarang,
            riwayat_penyakit_dahulu: rekam.riwayat_penyakit_dahulu,
            riwayat_keluarga: rekam.riwayat_keluarga,
            riwayat_psikososial: rekam.riwayat_psikososial,
            hasil_pemeriksaan_fisik: rekam.hasil_pemeriksaan_fisik,
            subjektif: rekam.subjektif,
            objektif: rekam.objektif,
            asesmen: rekam.asesmen,
            plan: rekam.plan,
            diagnosis_kerja: rekam.diagnosis_kerja,
            instruksi_tindak_lanjut: rekam.instruksi_tindak_lanjut,
            status_tindak_lanjut: rekam.status_tindak_lanjut,
            jadwal_kontrol: rekam.jadwal_kontrol,
        },
    });

    const draft = rekam.status_dokumen === 'draft';

    const kirimEdit = form.handleSubmit(async (nilai) => {
        try {
            const hasil = await ubah.mutateAsync(nilai);

            dispatchFlash({ level: 'success', message: hasil.message });
        } catch (error) {
            dispatchFlash({ level: 'error', message: pesanGagal(error) });
        }
    });

    /**
     * The amendment sends the WHOLE change set, not a diff.
     *
     * `RekamMedisService::periksaAmandemen()` refuses a change set that is empty AND
     * refuses one that produces a version identical to its parent, and it compares
     * the incoming keys against `KOLOM_ISI` - so a set carrying every column, most
     * of them unchanged, is accepted as long as at least one genuinely differs. That
     * is the honest shape for a form: a doctor amends by reading the whole note and
     * writing the corrected whole note, not by describing a delta.
     */
    const kirimAmandemen = form.handleSubmit(async (nilai) => {
        try {
            const hasil = await amandemen.mutateAsync({ perubahan: nilai });

            dispatchFlash({ level: 'success', message: hasil.message });
            setMode('edit');
        } catch (error) {
            dispatchFlash({ level: 'error', message: pesanGagal(error) });
        }
    });

    return (
        <Card data-slot="rekam-medis-edit-form" data-mode={mode}>
            <CardHeader>
                <CardTitle className="flex flex-wrap items-center gap-2">
                    {draft ? (
                        <>
                            <Pencil aria-hidden />

                            Ubah rekam medis
                        </>
                    ) : (
                        <>
                            <Stamp aria-hidden />

                            Rekam medis bertanda tangan
                        </>
                    )}

                    <StatusDokumenBadge status={rekam.status_dokumen} />
                    <VersiBadge versi={rekam.versi} terbaru={rekam.adalah_versi_terkini} />
                </CardTitle>

                <CardDescription>
                    {draft
                        ? 'PUT /api/v1/rekam-medis/{id} hanya berlaku pada status draft. Setelah ditandatangani, ubah dalam bentuk amandemen.'
                        : 'Catatan yang sudah ditandatangani tidak dapat diubah in place. Server menjawab 422 pada PUT dan menunjuk ke /amandemen.'}
                </CardDescription>
            </CardHeader>

            <CardContent>
                <FormErrorSummary error={ubah.error ?? amandemen.error ?? finalisasi.error} />

                {/**
                 * The signed record carries no editable form at all. Rendering disabled
                 * inputs for a signed note would suggest the doctor can change it; the
                 * only action a signed record has is an amendment.
                 */}
                {!draft ? (
                    <p className="text-muted-foreground text-sm">
                        Isi catatan tidak dapat disunting. Gunakan tombol "Ajukan
                        amandemen" di bawah untuk membuat versi baru.
                    </p>
                ) : (
                    <form
                        className="flex flex-col gap-4"
                        onSubmit={mode === 'amandemen' ? kirimAmandemen : kirimEdit}
                    >
                        {KOLOM.map((kolom) => (
                            <Field
                                key={kolom}
                                label={LABEL[kolom]}
                                errors={fieldErrors(
                                    mode === 'amandemen'
                                        ? amandemen.error
                                        : ubah.error,
                                    kolom,
                                )}
                            >
                                <FieldTextarea
                                    rows={3}
                                    maxLength={
                                        kolom === 'diagnosis_kerja' ? 255 : 16_000
                                    }
                                    disabled={ubah.isPending || amandemen.isPending}
                                    {...form.register(kolom)}
                                />
                            </Field>
                        ))}

                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                disabled={ubah.isPending || amandemen.isPending}
                            >
                                {ubah.isPending || amandemen.isPending ? (
                                    <Loader2 className="animate-spin" />
                                ) : (
                                    <Save />
                                )}

                                {mode === 'amandemen'
                                    ? 'Kirim sebagai amandemen'
                                    : 'Simpan perubahan'}
                            </Button>

                            <Button
                                type="button"
                                variant="outline"
                                disabled={ubah.isPending || amandemen.isPending}
                                onClick={() => {
                                    finalisasi
                                        .mutateAsync()
                                        .then((hasil) => {
                                            dispatchFlash({
                                                level: 'success',
                                                message: hasil.message,
                                            });
                                        })
                                        .catch((error: unknown) => {
                                            dispatchFlash({
                                                level: 'error',
                                                message: pesanGagal(error),
                                            });
                                        });
                                }}
                            >
                                <Lock aria-hidden />

                                Tanda tangani (final)
                            </Button>
                        </div>
                    </form>
                )}
            </CardContent>

            {!draft ? (
                <CardFooter className="flex flex-wrap gap-2">
                    {mode === 'amandemen' ? (
                        <>
                            <Button
                                type="button"
                                onClick={() => {
                                    void kirimAmandemen();
                                }}
                                disabled={amandemen.isPending}
                            >
                                {amandemen.isPending ? (
                                    <Loader2 className="animate-spin" />
                                ) : (
                                    <Stamp />
                                )}

                                Konfirmasi kirim sebagai amandemen
                            </Button>

                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    setMode('edit');
                                }}
                            >
                                Batal
                            </Button>
                        </>
                    ) : (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setMode('amandemen');
                            }}
                        >
                            <Stamp aria-hidden />

                            Ajukan amandemen
                        </Button>
                    )}
                </CardFooter>
            ) : null}
        </Card>
    );
}

/** The fourteen writable columns, in `RekamMedisService::KOLOM_ISI` order. */
const KOLOM = [
    'keluhan_utama',
    'riwayat_penyakit_sekarang',
    'riwayat_penyakit_dahulu',
    'riwayat_keluarga',
    'riwayat_psikososial',
    'hasil_pemeriksaan_fisik',
    'subjektif',
    'objektif',
    'asesmen',
    'plan',
    'diagnosis_kerja',
    'instruksi_tindak_lanjut',
] as const satisfies readonly (keyof RekamMedisIsi)[];

const LABEL: Record<(typeof KOLOM)[number], string> = {
    keluhan_utama: 'Keluhan utama',
    riwayat_penyakit_sekarang: 'Riwayat penyakit sekarang',
    riwayat_penyakit_dahulu: 'Riwayat penyakit dahulu',
    riwayat_keluarga: 'Riwayat keluarga',
    riwayat_psikososial: 'Riwayat psikososial',
    hasil_pemeriksaan_fisik: 'Hasil pemeriksaan fisik',
    subjektif: 'Subjektif (S)',
    objektif: 'Objektif (O)',
    asesmen: 'Asesmen (A)',
    plan: 'Plan (P)',
    diagnosis_kerja: 'Diagnosis kerja',
    instruksi_tindak_lanjut: 'Instruksi tindak lanjut',
};

function fieldErrors(error: unknown, kolom: string): string[] {
    return error instanceof ApiError ? error.fieldErrors(kolom) : [];
}

function pesanGagal(error: unknown): string {
    return error instanceof ApiError ? error.message : 'Permintaan gagal.';
}
