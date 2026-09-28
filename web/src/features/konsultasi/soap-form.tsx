import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { Loader2, Save, Stethoscope } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { dispatchFlash } from '@/lib/flash';
import { selesaiKonsultasiMutation, soapSchema, type SoapInput } from '@/lib/api/konsultasi';
import type { Konsultasi } from '@/lib/api/types';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldTextarea, FormErrorSummary } from '@/components/form/field';

/**
 * The SOAP form, and it writes through `PUT /konsultasi/{id}/selesai`.
 *
 * ## `catatan_asessment` is the DDL's spelling and this form must not "fix" it
 *
 * `telemedicine_test.sql:550` writes `catatan_asessment`, one `s` short of the
 * English word, and `KonsultasiService::KOLOM_SOAP` publishes that exact string as
 * the writable list. The schema in `lib/api/konsultasi.ts` is the single place the key
 * is spelled; the form binds to it and never names a field itself.
 *
 * ## A 200 is not proof the note was stored
 *
 * The endpoint returns the consultation **after** the transaction, so this form reads
 * the response back and compares each submitted field against what came back. That
 * comparison is the only client-side check that catches a key the service accepted and
 * did not write - the defect class `KonsultasiService::tulisSoap()` was hardened
 * against, and a 200 alone would hide it completely. A field that comes back
 * different (or `null` when a value was sent) is reported by name.
 *
 * The alternative - trusting 200 - is what a form built against a service that
 * silently drops unknown keys would do, and it is precisely why the "typo validates,
 * is accepted, and writes nowhere" failure is so hard to notice.
 */
export function SoapForm({ konsultasi }: { konsultasi: Konsultasi }) {
    const simpan = useMutation(selesaiKonsultasiMutation(konsultasi.id));

    const form = useForm<SoapInput>({
        resolver: zodResolver(soapSchema),
        defaultValues: {
            catatan_subjektif: konsultasi.catatan_subjektif,
            catatan_objektif: konsultasi.catatan_objektif,
            catatan_asessment: konsultasi.catatan_asessment,
            catatan_plan: konsultasi.catatan_plan,
            diagnosis_kerja: konsultasi.diagnosis_kerja,
            saran_tindak_lanjut: konsultasi.saran_tindak_lanjut,
        },
    });

    const kirim = form.handleSubmit(async (nilai) => {
        try {
            const hasil = await simpan.mutateAsync(nilai);
            const kembali = hasil.data.konsultasi;

            const hilang = KOLOM_SOAP.filter((kolom) => {
                const terkirimNilai = nilai[kolom];

                if (terkirimNilai === undefined) {
                    return false;
                }

                return (kembali[kolom] ?? null) !== terkirimNilai;
            });

            if (hilang.length > 0) {
                /**
                 * The response says the write did not land. Saying nothing here would
                 * be the failure the todo is about: a form that reports success on a
                 * row the database never received.
                 */
                dispatchFlash({
                    level: 'error',
                    message: `Server mengembalikan 200 tetapi kolom berikut tidak tersimpan: ${hilang.join(', ')}.`,
                });

                return;
            }

            dispatchFlash({ level: 'success', message: hasil.message });
        } catch (error) {
            dispatchFlash({
                level: 'error',
                message:
                    error instanceof ApiError
                        ? error.message
                        : 'Catatan SOAP gagal disimpan.',
            });
        }
    });

    return (
        <Card data-slot="soap-form">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Stethoscope aria-hidden />

                    Catatan SOAP
                </CardTitle>

                <CardDescription>
                    Enam kolom yang ditulis doctor-only melalui PUT
                    /api/v1/konsultasi/{konsultasi.id}/selesai. Field yang dikosongkan
                    dipertahankan, bukan dihapus.
                </CardDescription>
            </CardHeader>

            <CardContent>
                <FormErrorSummary error={simpan.error} />

                <form className="flex flex-col gap-4" onSubmit={kirim}>
                    {KOLOM_SOAP.map((kolom) => (
                        <Field
                            key={kolom}
                            label={LABEL_SOAP[kolom]}
                            hint={HINT_SOAP[kolom]}
                            errors={fieldErrors(simpan.error, kolom)}
                        >
                            <FieldTextarea
                                rows={kolom === 'diagnosis_kerja' ? 2 : 4}
                                maxLength={kolom === 'diagnosis_kerja' ? 255 : 16_000}
                                disabled={simpan.isPending}
                                {...form.register(kolom)}
                            />
                        </Field>
                    ))}

                    <Button type="submit" disabled={simpan.isPending} className="w-fit">
                        {simpan.isPending ? (
                            <Loader2 className="animate-spin" />
                        ) : (
                            <Save />
                        )}

                        Simpan catatan SOAP
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

/**
 * The six writable columns, in the server's own order.
 *
 * `KonsultasiService::KOLOM_SOAP`, restated. The form iterates this, so a column
 * added server-side appears here without a second edit - and a column that exists
 * only client-side cannot, because this list is a plain array rather than a derived
 * one.
 */
const KOLOM_SOAP = [
    'catatan_subjektif',
    'catatan_objektif',
    'catatan_asessment',
    'catatan_plan',
    'diagnosis_kerja',
    'saran_tindak_lanjut',
] as const satisfies readonly (keyof SoapInput)[];

const LABEL_SOAP: Record<(typeof KOLOM_SOAP)[number], string> = {
    catatan_subjektif: 'Subjektif (S)',
    catatan_objektif: 'Objektif (O)',
    // The label is the DDL's spelling too, so the field's name is visible to the
    // reader rather than hidden behind a "corrected" one.
    catatan_asessment: 'Asesment (A)',
    catatan_plan: 'Plan (P)',
    diagnosis_kerja: 'Diagnosis kerja',
    saran_tindak_lanjut: 'Saran tindak lanjut',
};

const HINT_SOAP: Record<(typeof KOLOM_SOAP)[number], string> = {
    catatan_subjektif: 'Maksimum 16000 karakter.',
    catatan_objektif: 'Maksimum 16000 karakter.',
    catatan_asessment:
        'Nama kolom di server adalah catatan_asessment, mengikuti telemedicine_test.sql:550.',
    catatan_plan: 'Maksimum 16000 karakter.',
    diagnosis_kerja: 'Maksimum 255 karakter, mengikuti VARCHAR(255) di skema.',
    saran_tindak_lanjut: 'Maksimum 16000 karakter.',
};

/**
 * The 422 messages for one field, rendered **per field and in full**.
 *
 * A 422 can carry more than one message for one key, and a toast has room for one
 * line - so the structured answer goes to the field that produced it, and
 * `FormErrorSummary` above carries only the count.
 */
function fieldErrors(error: unknown, kolom: string): string[] {
    if (error instanceof ApiError) {
        return error.fieldErrors(kolom);
    }

    return [];
}
