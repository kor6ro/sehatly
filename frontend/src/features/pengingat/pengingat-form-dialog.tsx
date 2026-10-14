import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ApiError } from '@/lib/http';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { zonaPerangkat } from '@/lib/waktu';
import {
    createPengingatMutation,
    updatePengingatMutation,
    ZONA_PENGINGAT,
    type Pengingat,
    type PengingatInput,
    type ZonaPengingat,
} from '@/lib/api/pengingat';
import { tanggalDiZona } from '@/features/pengingat/format-pengingat';
import { Field, FieldInput, FieldSelect, FormErrorSummary } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { SelectItem } from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * Create/edit one reminder.
 *
 * ## Only the fields the API has, and no "advanced" section
 *
 * `PengingatRequest` exposes jenis, judul, keterangan, obat_id, booking_id, dosis,
 * jumlah_per_hari, tanggal_mulai, lama_hari, waktu, zona_waktu and status. There is no
 * snooze column and no weekend-shift column, so no collapsible "Pengaturan lanjutan" is
 * rendered - inventing one would be a control the scheduler never reads.
 *
 * ## `waktu` is a repeatable `HH:MM` list
 *
 * The server requires a NON-EMPTY list (a reminder with no time never fires) and refuses
 * a single-digit hour, so the time inputs and the client schema both use the same
 * `^(?:[01]\d|2[0-3]):[0-5]\d$` spelling the request uses.
 *
 * ## Create forbids `status`; edit sends the fields, not the status
 *
 * A new reminder is always `aktif`. Pausing is a separate PUT with `{status}` alone, so
 * this dialog never sends `status` and cannot race that transition.
 */

const WAKTU_REGEX = /^(?:[01]\d|2[0-3]):[0-5]\d$/;

const schema = z.object({
    jenis: z.enum(['obat', 'janji_temu']),
    judul: z
        .string()
        .trim()
        .min(1, 'Judul wajib diisi.')
        .max(200, 'Judul maksimal 200 karakter.'),
    dosis: z.string().trim().max(50, 'Dosis maksimal 50 karakter.'),
    jumlah_per_hari: z
        .string()
        .trim()
        .refine(
            (nilai) =>
                nilai === '' ||
                (/^\d{1,2}$/.test(nilai) && Number(nilai) >= 1 && Number(nilai) <= 24),
            'Isi angka 1 sampai 24.',
        ),
    tanggal_mulai: z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}$/, 'Tanggal mulai wajib diisi.'),
    lama_hari: z
        .string()
        .trim()
        .refine(
            (nilai) =>
                nilai === '' ||
                (/^\d{1,4}$/.test(nilai) && Number(nilai) >= 1 && Number(nilai) <= 3650),
            'Isi angka 1 sampai 3650.',
        ),
    waktu: z
        .array(
            z.object({
                nilai: z.string().regex(WAKTU_REGEX, 'Format waktu HH:MM.'),
            }),
        )
        .min(1, 'Minimal satu waktu pengingat.'),
    zona_waktu: z.enum(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura']),
});

type FormPengingat = z.infer<typeof schema>;

function nilaiAwal(row: Pengingat | null): FormPengingat {
    const zona = (row?.zona_waktu as ZonaPengingat | undefined) ?? 'Asia/Jakarta';

    return {
        jenis: row?.jenis ?? 'obat',
        judul: row?.judul ?? '',
        dosis: row?.dosis ?? '',
        jumlah_per_hari:
            row?.jumlah_per_hari === null || row?.jumlah_per_hari === undefined
                ? ''
                : String(row.jumlah_per_hari),
        tanggal_mulai: row?.tanggal_mulai ?? tanggalDiZona(zonaPerangkat()),
        lama_hari:
            row?.lama_hari === null || row?.lama_hari === undefined
                ? ''
                : String(row.lama_hari),
        waktu:
            row === null || row.waktu.length === 0
                ? [{ nilai: '08:00' }]
                : row.waktu.map((nilai) => ({ nilai })),
        zona_waktu: zona,
    };
}

function keInput(values: FormPengingat): PengingatInput {
    const obat = values.jenis === 'obat';

    return {
        jenis: values.jenis,
        judul: values.judul,
        dosis: obat && values.dosis !== '' ? values.dosis : null,
        jumlah_per_hari:
            obat && values.jumlah_per_hari !== ''
                ? Number(values.jumlah_per_hari)
                : null,
        tanggal_mulai: values.tanggal_mulai,
        lama_hari:
            obat && values.lama_hari !== '' ? Number(values.lama_hari) : null,
        waktu: values.waktu.map((baris) => baris.nilai),
        zona_waktu: values.zona_waktu,
    };
}

function messages(pesanKlien: string | undefined): string[] {
    return pesanKlien === undefined ? [] : [pesanKlien];
}

function fieldErrors(error: unknown, field: string): string[] {
    if (!(error instanceof ApiError)) {
        return [];
    }

    return error.fieldErrors(field);
}

export function PengingatFormDialog({
    open,
    onOpenChange,
    row,
    onSukses,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** `null` for create, the row being edited otherwise. */
    row: Pengingat | null;
    onSukses: (pesan: string) => void;
}) {
    const online = useOnlineStatus();
    const [serverError, setServerError] = useState<unknown>(null);

    const {
        register,
        handleSubmit,
        watch,
        setValue,
        control,
        formState: { errors, isSubmitting },
    } = useForm<FormPengingat>({
        resolver: zodResolver(schema),
        values: nilaiAwal(row),
    });

    const { fields, append, remove } = useFieldArray({ control, name: 'waktu' });

    const create = useMutation(createPengingatMutation());
    const update = useMutation(updatePengingatMutation());

    const jenis = watch('jenis');
    const zona = watch('zona_waktu');
    const isEdit = row !== null;

    async function onSubmit(values: FormPengingat): Promise<void> {
        setServerError(null);

        if (!online) {
            return;
        }

        try {
            const hasil =
                row === null
                    ? await create.mutateAsync(keInput(values))
                    : await update.mutateAsync({ id: row.id, input: keInput(values) });

            onSukses(hasil.message);
            onOpenChange(false);
        } catch (error) {
            setServerError(error);
        }
    }

    const busy = isSubmitting || create.isPending || update.isPending;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent data-slot="pengingat-dialog" className="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? 'Ubah pengingat' : 'Buat pengingat'}
                    </DialogTitle>

                    <DialogDescription>
                        {isEdit
                            ? 'Perubahan Anda akan disimpan dan berlaku pada jadwal berikutnya.'
                            : 'Pengingat dikirim sesuai waktu dan zona waktu yang Anda pilih.'}
                    </DialogDescription>
                </DialogHeader>

                <form
                    data-slot="pengingat-form"
                    className="flex flex-col gap-4"
                    noValidate
                    onSubmit={handleSubmit(onSubmit)}
                >
                    <FormErrorSummary error={serverError} />

                    {serverError instanceof ApiError && !serverError.isValidation ? (
                        <p role="alert" className="text-destructive text-sm">
                            {serverError.message}
                        </p>
                    ) : null}

                    <Field
                        label="Jenis pengingat"
                        errors={fieldErrors(serverError, 'jenis')}
                        required
                    >
                        <FieldSelect
                            value={jenis}
                            className="min-h-11 w-full"
                            onValueChange={(nilai) => {
                                setValue('jenis', nilai as FormPengingat['jenis'], {
                                    shouldValidate: true,
                                });
                            }}
                        >
                            <SelectItem value="obat">Obat</SelectItem>
                            <SelectItem value="janji_temu">Janji temu</SelectItem>
                        </FieldSelect>
                    </Field>

                    <Field
                        label={jenis === 'obat' ? 'Nama obat' : 'Judul pengingat'}
                        errors={[
                            ...messages(errors.judul?.message),
                            ...fieldErrors(serverError, 'judul'),
                        ]}
                        required
                    >
                        <FieldInput
                            placeholder={
                                jenis === 'obat'
                                    ? 'cth. Metformin 500 mg'
                                    : 'cth. Kontrol ulang dokter'
                            }
                            {...register('judul')}
                        />
                    </Field>

                    {jenis === 'obat' ? (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Dosis"
                                    errors={[
                                        ...messages(errors.dosis?.message),
                                        ...fieldErrors(serverError, 'dosis'),
                                    ]}
                                >
                                    <FieldInput
                                        placeholder="cth. 1 tablet"
                                        {...register('dosis')}
                                    />
                                </Field>

                                <Field
                                    label="Berapa kali sehari"
                                    errors={[
                                        ...messages(
                                            errors.jumlah_per_hari?.message,
                                        ),
                                        ...fieldErrors(
                                            serverError,
                                            'jumlah_per_hari',
                                        ),
                                    ]}
                                >
                                    <FieldInput
                                        type="number"
                                        min={1}
                                        max={24}
                                        inputMode="numeric"
                                        {...register('jumlah_per_hari')}
                                    />
                                </Field>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Tanggal mulai"
                                    errors={[
                                        ...messages(errors.tanggal_mulai?.message),
                                        ...fieldErrors(serverError, 'tanggal_mulai'),
                                    ]}
                                    required
                                >
                                    <FieldInput
                                        type="date"
                                        {...register('tanggal_mulai')}
                                    />
                                </Field>

                                <Field
                                    label="Lama konsumsi (hari)"
                                    errors={[
                                        ...messages(errors.lama_hari?.message),
                                        ...fieldErrors(serverError, 'lama_hari'),
                                    ]}
                                    hint="Kosongkan bila tanpa batas."
                                >
                                    <FieldInput
                                        type="number"
                                        min={1}
                                        max={3650}
                                        inputMode="numeric"
                                        {...register('lama_hari')}
                                    />
                                </Field>
                            </div>
                        </>
                    ) : (
                        <Field
                            label="Tanggal mulai"
                            errors={[
                                ...messages(errors.tanggal_mulai?.message),
                                ...fieldErrors(serverError, 'tanggal_mulai'),
                            ]}
                            required
                        >
                            <FieldInput type="date" {...register('tanggal_mulai')} />
                        </Field>
                    )}

                    <Field
                        label="Waktu pengingat"
                        errors={[
                            ...messages(errors.waktu?.message),
                            ...fieldErrors(serverError, 'waktu'),
                        ]}
                        hint="Satu pengingat dapat memiliki beberapa waktu."
                        required
                    >
                        <div
                            data-slot="pengingat-waktu-list"
                            className="flex flex-col gap-2"
                        >
                            {fields.map((field, index) => {
                                const errorsBaris = [
                                    ...messages(
                                        errors.waktu?.[index]?.nilai?.message,
                                    ),
                                    ...fieldErrors(serverError, `waktu.${index}`),
                                    ...fieldErrors(
                                        serverError,
                                        `waktu.${index}.nilai`,
                                    ),
                                ];

                                return (
                                    <div key={field.id} className="flex flex-col gap-1">
                                        <div className="flex items-center gap-2">
                                            <Input
                                                data-slot="pengingat-waktu-input"
                                                type="time"
                                                aria-label={`Waktu pengingat ${index + 1}`}
                                                aria-invalid={
                                                    errorsBaris.length > 0
                                                        ? true
                                                        : undefined
                                                }
                                                className="min-h-11 w-full max-w-40"
                                                {...register(`waktu.${index}.nilai`)}
                                            />

                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon"
                                                data-slot="pengingat-waktu-hapus"
                                                aria-label={`Hapus waktu ${index + 1}`}
                                                className="size-11 shrink-0"
                                                disabled={fields.length === 1}
                                                onClick={() => {
                                                    remove(index);
                                                }}
                                            >
                                                <Trash2 aria-hidden />
                                            </Button>
                                        </div>

                                        {errorsBaris.length === 0 ? null : (
                                            <ul className="text-destructive text-xs">
                                                {errorsBaris.map((pesan) => (
                                                    <li key={pesan}>{pesan}</li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                );
                            })}

                            <Button
                                type="button"
                                variant="outline"
                                data-slot="pengingat-waktu-tambah"
                                className="min-h-11 w-fit"
                                disabled={fields.length >= 24}
                                onClick={() => {
                                    append({ nilai: '12:00' });
                                }}
                            >
                                <Plus aria-hidden />

                                Tambah waktu
                            </Button>
                        </div>
                    </Field>

                    <Field
                        label="Zona waktu"
                        errors={fieldErrors(serverError, 'zona_waktu')}
                        hint="Waktu pengingat mengikuti zona ini."
                        required
                    >
                        <FieldSelect
                            value={zona}
                            className="min-h-11 w-full"
                            onValueChange={(nilai) => {
                                setValue(
                                    'zona_waktu',
                                    nilai as FormPengingat['zona_waktu'],
                                    { shouldValidate: true },
                                );
                            }}
                        >
                            {ZONA_PENGINGAT.map((item) => (
                                <SelectItem key={item.nilai} value={item.nilai}>
                                    {item.label} ({item.nilai})
                                </SelectItem>
                            ))}
                        </FieldSelect>
                    </Field>

                    {!online ? (
                        <p className="text-muted-foreground text-sm">
                            Menyimpan pengingat dinonaktifkan sampai koneksi kembali.
                        </p>
                    ) : null}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => {
                                onOpenChange(false);
                            }}
                        >
                            Batal
                        </Button>

                        <Button
                            type="submit"
                            data-slot="pengingat-simpan"
                            className="min-h-11"
                            disabled={busy}
                            aria-disabled={!online || busy ? true : undefined}
                        >
                            {busy ? 'Menyimpan...' : isEdit ? 'Simpan perubahan' : 'Simpan pengingat'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
