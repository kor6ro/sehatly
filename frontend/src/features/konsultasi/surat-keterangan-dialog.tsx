import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { FileSignature, Loader2, Send } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { dispatchFlash } from '@/lib/flash';
import { useOnlineStatus } from '@/hooks/use-online-status';
import {
    buatSuratKeteranganMutation,
    labelTipeSuratKeterangan,
    TIPE_SURAT_KETERANGAN,
    type BuatSuratKeteranganInput,
    type HasilSuratKeterangan,
} from '@/lib/api/surat-keterangan';
import type { TipeSuratKeterangan } from '@/lib/api/types';
import { formatTanggal } from '@/lib/format';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { SelectItem } from '@/components/ui/select';
import {
    Field,
    FieldInput,
    FieldSelect,
    FieldTextarea,
    FormErrorSummary,
} from '@/components/form/field';

/**
 * The doctor's "Surat keterangan" action on `/konsultasi/:id`.
 *
 * The letter is issued through `POST /konsultasi/{id}/surat-keterangan`, and its
 * number is rendered **inline** in the result card rather than in the toast: the
 * number is a document identifier a clinician may need to quote at any moment, and a
 * toast lives three seconds. The toast is deliberately generic - "Surat keterangan
 * dibuat." - because a letter type plus a patient context in a shared-screen
 * notification is exactly what `_global.md` §3 forbids.
 *
 * Referral keys are shown only for `surat_rujukan`, which is the only type the
 * service accepts them on; sending them on another type is a 422 server-side.
 */
export function SuratKeteranganDialog({ konsultasiId }: { konsultasiId: number }) {
    const online = useOnlineStatus();

    const [terbuka, setTerbuka] = useState(false);
    const [tipe, setTipe] = useState<TipeSuratKeterangan>('surat_sakit');
    const [tanggalMulai, setTanggalMulai] = useState('');
    const [tanggalSelesai, setTanggalSelesai] = useState('');
    const [isi, setIsi] = useState('');
    const [faskesTujuan, setFaskesTujuan] = useState('');
    const [diagnosisKerja, setDiagnosisKerja] = useState('');
    const [icd10, setIcd10] = useState('');
    const [alasanRujukan, setAlasanRujukan] = useState('');
    const [berlakuSampai, setBerlakuSampai] = useState('');
    const [nomorSep, setNomorSep] = useState('');
    const [hasil, setHasil] = useState<HasilSuratKeterangan | null>(null);
    const [faskesError, setFaskesError] = useState<string | null>(null);

    const buat = useMutation(buatSuratKeteranganMutation(konsultasiId));

    const kirim = (): void => {
        if (tipe === 'surat_rujukan' && faskesTujuan.trim() === '') {
            setFaskesError('Faskes tujuan wajib diisi untuk surat rujukan.');

            return;
        }

        setFaskesError(null);

        const input: BuatSuratKeteranganInput = {
            tipe,
            ...(tanggalMulai === '' ? {} : { tanggal_mulai: tanggalMulai }),
            ...(tanggalSelesai === '' ? {} : { tanggal_selesai: tanggalSelesai }),
            ...(isi.trim() === '' ? {} : { isi: isi.trim() }),
        };

        if (tipe === 'surat_rujukan') {
            input.faskes_tujuan_id = Number(faskesTujuan);
            input.diagnosis_kerja = diagnosisKerja.trim();
            input.alasan_rujukan = alasanRujukan.trim();

            if (icd10.trim() !== '') {
                input.icd10_kode = icd10.trim();
            }

            if (berlakuSampai !== '') {
                input.berlaku_sampai = berlakuSampai;
            }

            if (nomorSep.trim() !== '') {
                input.nomor_sep = nomorSep.trim();
            }
        }

        buat.mutate(input, {
            onSuccess: (response) => {
                setHasil({
                    surat_keterangan: response.data.surat_keterangan,
                    rujukan: response.data.rujukan,
                });
                setTerbuka(false);

                dispatchFlash({
                    level: 'success',
                    message: 'Surat keterangan dibuat.',
                });
            },
        });
    };

    return (
        <div
            data-slot="f13-surat"
            className="flex flex-col gap-3"
        >
            <Button
                type="button"
                variant="outline"
                data-testid="f13-aksi"
                className="h-11 w-fit"
                disabled={!online}
                onClick={() => {
                    setTerbuka(true);
                }}
            >
                <FileSignature aria-hidden />

                Surat keterangan
            </Button>

            {hasil === null ? null : (
                <Card data-slot="f13-surat-hasil">
                    <CardHeader>
                        <CardTitle className="text-base">Surat keterangan dibuat</CardTitle>

                        <CardDescription>
                            Simpan nomor surat berikut untuk keperluan verifikasi.
                        </CardDescription>
                    </CardHeader>

                    <CardContent className="flex flex-col gap-3 text-sm">
                        <dl className="grid gap-3 sm:grid-cols-2">
                            <div className="flex flex-col gap-0.5">
                                <dt className="text-muted-foreground text-xs">Nomor surat</dt>

                                <dd
                                    data-slot="f13-nomor-surat"
                                    className="font-mono text-base font-medium break-words"
                                >
                                    {hasil.surat_keterangan.nomor_surat}
                                </dd>
                            </div>

                            <div className="flex flex-col gap-0.5">
                                <dt className="text-muted-foreground text-xs">Tipe</dt>

                                <dd className="font-medium">
                                    {labelTipeSuratKeterangan(hasil.surat_keterangan.tipe)}
                                </dd>
                            </div>

                            <div className="flex flex-col gap-0.5">
                                <dt className="text-muted-foreground text-xs">Tanggal mulai</dt>

                                <dd>{formatTanggal(hasil.surat_keterangan.tanggal_mulai)}</dd>
                            </div>

                            <div className="flex flex-col gap-0.5">
                                <dt className="text-muted-foreground text-xs">Tanggal selesai</dt>

                                <dd>{formatTanggal(hasil.surat_keterangan.tanggal_selesai)}</dd>
                            </div>
                        </dl>

                        {hasil.rujukan === null ? null : (
                            <>
                                <Separator />

                                <p>
                                    Rujukan ke faskes #{hasil.rujukan.faskes_tujuan_id ?? '-'}{' '}
                                    berlaku sampai{' '}
                                    {formatTanggal(hasil.rujukan.berlaku_sampai)}.
                                </p>
                            </>
                        )}
                    </CardContent>
                </Card>
            )}

            <Dialog
                open={terbuka}
                onOpenChange={(open) => {
                    setTerbuka(open);
                }}
            >
                <DialogContent className="max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Surat keterangan</DialogTitle>

                        <DialogDescription>
                            Surat diterbitkan atas nama pasien pada konsultasi ini. Nomor
                            surat muncul di halaman setelah tersimpan.
                        </DialogDescription>
                    </DialogHeader>

                    <FormErrorSummary error={buat.error} />

                    <div className="flex flex-col gap-4">
                        <Field label="Tipe surat" required>
                            <FieldSelect
                                value={tipe}
                                onValueChange={(value) => {
                                    setTipe(value as TipeSuratKeterangan);
                                }}
                            >
                                {TIPE_SURAT_KETERANGAN.map((row) => (
                                    <SelectItem key={row} value={row}>
                                        {labelTipeSuratKeterangan(row)}
                                    </SelectItem>
                                ))}
                            </FieldSelect>
                        </Field>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Tanggal mulai" hint="Opsional. Format Y-m-d.">
                                <FieldInput
                                    type="date"
                                    value={tanggalMulai}
                                    onChange={(event) => {
                                        setTanggalMulai(event.target.value);
                                    }}
                                />
                            </Field>

                            <Field label="Tanggal selesai" hint="Opsional. Format Y-m-d.">
                                <FieldInput
                                    type="date"
                                    value={tanggalSelesai}
                                    onChange={(event) => {
                                        setTanggalSelesai(event.target.value);
                                    }}
                                />
                            </Field>
                        </div>

                        {tipe !== 'surat_rujukan' ? null : (
                            <>
                                <Field
                                    label="Faskes tujuan"
                                    required
                                    hint="Id faskes tujuan rujukan."
                                    errors={
                                        faskesError === null
                                            ? fieldErrors(buat.error, 'faskes_tujuan_id')
                                            : [faskesError]
                                    }
                                >
                                    <FieldInput
                                        type="number"
                                        min={1}
                                        value={faskesTujuan}
                                        onChange={(event) => {
                                            setFaskesTujuan(event.target.value);
                                        }}
                                    />
                                </Field>

                                <Field label="Diagnosis kerja" hint="Opsional.">
                                    <FieldInput
                                        maxLength={255}
                                        value={diagnosisKerja}
                                        onChange={(event) => {
                                            setDiagnosisKerja(event.target.value);
                                        }}
                                    />
                                </Field>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label="Kode ICD-10" hint="Opsional.">
                                        <FieldInput
                                            maxLength={8}
                                            value={icd10}
                                            onChange={(event) => {
                                                setIcd10(event.target.value);
                                            }}
                                        />
                                    </Field>

                                    <Field label="Nomor SEP" hint="Opsional.">
                                        <FieldInput
                                            maxLength={30}
                                            value={nomorSep}
                                            onChange={(event) => {
                                                setNomorSep(event.target.value);
                                            }}
                                        />
                                    </Field>
                                </div>

                                <Field label="Alasan rujukan" hint="Opsional.">
                                    <FieldTextarea
                                        rows={3}
                                        maxLength={16_000}
                                        value={alasanRujukan}
                                        onChange={(event) => {
                                            setAlasanRujukan(event.target.value);
                                        }}
                                    />
                                </Field>

                                <Field label="Berlaku sampai" hint="Opsional. Format Y-m-d.">
                                    <FieldInput
                                        type="date"
                                        value={berlakuSampai}
                                        onChange={(event) => {
                                            setBerlakuSampai(event.target.value);
                                        }}
                                    />
                                </Field>
                            </>
                        )}

                        <Field label="Isi surat" hint="Opsional, maksimum 16000 karakter.">
                            <FieldTextarea
                                rows={4}
                                maxLength={16_000}
                                value={isi}
                                onChange={(event) => {
                                    setIsi(event.target.value);
                                }}
                            />
                        </Field>

                        {online ? null : (
                            <p className="text-muted-foreground text-sm">
                                Anda sedang luring. Surat tidak dapat dibuat sampai koneksi
                                kembali.
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => {
                                setTerbuka(false);
                            }}
                        >
                            Batal
                        </Button>

                        <Button
                            type="button"
                            className="h-11"
                            disabled={buat.isPending || !online}
                            onClick={kirim}
                        >
                            {buat.isPending ? (
                                <Loader2 className="animate-spin" aria-hidden />
                            ) : (
                                <Send aria-hidden />
                            )}

                            Simpan surat
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function fieldErrors(error: unknown, field: string): string[] {
    if (error instanceof ApiError) {
        return error.fieldErrors(field);
    }

    return [];
}
