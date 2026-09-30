import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation } from '@tanstack/react-query';
import {
    createAlergiMutation,
    updateAlergiMutation,
    KEPARAHAN,
    TIPE_ALERGEN,
    labelKeparahan,
    labelTipeAlergen,
    type AlergiInput,
    type UpdateAlergiInput,
} from '@/lib/api/alergi';
import { ApiError } from '@/lib/http';
import type { Alergi } from '@/lib/api/types';
import { dispatchFlash } from '@/lib/flash';
import { Field, FieldInput, FieldSelect, FormErrorSummary } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
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
 * The create and edit form for one allergy.
 *
 * ## `keparahan` is a choice here, not a default
 *
 * `pasien_alergi.keparahan` is `NOT NULL DEFAULT 'ringan'`, and `AlergiRequest` marks it
 * `nullable` so a client may omit it and let the database fill it in. The form always sends
 * a value, so the stored value is always the one the user picked rather than a default
 * silently applied on their behalf - and because `PasienController::alergiStore()` calls
 * `refresh()` after the insert, the row the list shows afterwards is the real stored value
 * either way.
 */
const schema = z.object({
    tipe_alergen: z.enum(['obat', 'makanan', 'lingkungan', 'lainnya']),
    nama_alergen: z
        .string()
        .trim()
        .min(2, 'Nama alergen minimal 2 karakter.')
        .max(150, 'Nama alergen maksimal 150 karakter.'),
    reaksi: z.string().trim().max(255, 'Reaksi maksimal 255 karakter.'),
    keparahan: z.enum(['ringan', 'sedang', 'berat', 'anafilaksis']),
});

type AlergiForm = z.infer<typeof schema>;

function toCreatePayload(values: AlergiForm): AlergiInput {
    return {
        tipe_alergen: values.tipe_alergen,
        nama_alergen: values.nama_alergen,
        reaksi: values.reaksi === '' ? null : values.reaksi,
        keparahan: values.keparahan,
    };
}

/** `PUT` is partial, so only the keys the user actually changed are sent. */
function toUpdatePayload(values: AlergiForm, original: Alergi): UpdateAlergiInput {
    const payload: UpdateAlergiInput = {};

    if (values.tipe_alergen !== original.tipe_alergen) {
        payload.tipe_alergen = values.tipe_alergen;
    }

    if (values.nama_alergen !== original.nama_alergen) {
        payload.nama_alergen = values.nama_alergen;
    }

    if (values.reaksi !== (original.reaksi ?? '')) {
        payload.reaksi = values.reaksi === '' ? null : values.reaksi;
    }

    if (values.keparahan !== original.keparahan) {
        payload.keparahan = values.keparahan;
    }

    return payload;
}

export function AlergiDialog({
    open,
    onOpenChange,
    row,
    selfUserId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    row: Alergi | null;
    /**
     * The signed-in `users.id`, so a row can be labelled as self-reported.
     * `PasienAlergiResource` publishes `dicatat_oleh_user_id` as a bare column with no
     * foreign key, and the write path sets it to the caller's own id, so equality with
     * this value is exactly "the patient recorded this themselves".
     */
    selfUserId: number | null;
}) {
    const isEdit = row !== null;
    const [serverError, setServerError] = useState<unknown>(null);

    const {
        register,
        handleSubmit,
        reset,
        watch,
        setValue,
        formState: { errors, isSubmitting },
    } = useForm<AlergiForm>({
        resolver: zodResolver(schema),
        values: {
            tipe_alergen: row?.tipe_alergen ?? 'obat',
            nama_alergen: row?.nama_alergen ?? '',
            reaksi: row?.reaksi ?? '',
            keparahan: row?.keparahan ?? 'ringan',
        },
    });

    const create = useMutation(createAlergiMutation());
    const update = useMutation(updateAlergiMutation());

    const tipeAlergen = watch('tipe_alergen');
    const keparahan = watch('keparahan');

    async function onSubmit(values: AlergiForm): Promise<void> {
        setServerError(null);

        try {
            const result =
                row === null
                    ? await create.mutateAsync(toCreatePayload(values))
                    : await update.mutateAsync({
                          id: row.id,
                          input: toUpdatePayload(values, row),
                      });

            dispatchFlash({ level: 'success', message: result.message });

            onOpenChange(false);

            reset();
        } catch (error) {
            setServerError(error);
        }
    }

    const busy = isSubmitting || create.isPending || update.isPending;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? 'Ubah alergi' : 'Tambah alergi'}
                    </DialogTitle>

                    <DialogDescription>
                        {isEdit
                            ? 'Perubahan Anda akan disimpan pada data alergi.'
                            : 'Alergi baru akan ditambahkan pada data Anda.'}
                        {' '}Nama alergen bebas teks dan tidak dicocokkan dengan katalog obat.
                    </DialogDescription>
                </DialogHeader>

                <form
                    onSubmit={handleSubmit(onSubmit)}
                    className="flex flex-col gap-4"
                    noValidate
                >
                    <FormErrorSummary error={serverError} />

                    {serverError instanceof ApiError && !serverError.isValidation ? (
                        <p className="text-destructive text-sm">{serverError.message}</p>
                    ) : null}

                    <Field
                        label="Tipe alergen"
                        errors={fieldErrors(serverError, 'tipe_alergen')}
                        required
                    >
                        <FieldSelect
                            value={tipeAlergen}
                            onValueChange={(value) => {
                                setValue(
                                    'tipe_alergen',
                                    value as AlergiForm['tipe_alergen'],
                                    { shouldValidate: true },
                                );
                            }}
                        >
                            {TIPE_ALERGEN.map((value) => (
                                <SelectItem key={value} value={value}>
                                    {labelTipeAlergen(value)}
                                </SelectItem>
                            ))}
                        </FieldSelect>
                    </Field>

                    <Field
                        label="Nama alergen"
                        errors={[
                            ...messages(errors.nama_alergen?.message),
                            ...fieldErrors(serverError, 'nama_alergen'),
                        ]}
                        required
                    >
                        <FieldInput
                            placeholder="Contoh: Penisilin"
                            {...register('nama_alergen')}
                        />
                    </Field>

                    <Field
                        label="Reaksi"
                        errors={[
                            ...messages(errors.reaksi?.message),
                            ...fieldErrors(serverError, 'reaksi'),
                        ]}
                    >
                        <FieldInput
                            placeholder="Contoh: ruak,ulae"
                            {...register('reaksi')}
                        />
                    </Field>

                    <Field
                        label="Keparahan"
                        errors={fieldErrors(serverError, 'keparahan')}
                        required
                    >
                        <FieldSelect
                            value={keparahan}
                            onValueChange={(value) => {
                                setValue(
                                    'keparahan',
                                    value as AlergiForm['keparahan'],
                                    { shouldValidate: true },
                                );
                            }}
                        >
                            {KEPARAHAN.map((value) => (
                                <SelectItem key={value} value={value}>
                                    {labelKeparahan(value)}
                                </SelectItem>
                            ))}
                        </FieldSelect>
                    </Field>

                    {isEdit && selfUserId !== null && row !== null ? (
                        <p className="text-muted-foreground text-xs">
                            {row.dicatat_oleh_user_id === selfUserId
                                ? 'Data ini dicatat oleh Anda sendiri.'
                                : `Data ini dicatat oleh pengguna lain (id ${String(row.dicatat_oleh_user_id)}).`}
                        </p>
                    ) : null}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                onOpenChange(false);
                            }}
                        >
                            Batal
                        </Button>

                        <Button type="submit" disabled={busy}>
                            {busy ? <Spinner /> : null}

                            {busy ? 'Menyimpan...' : 'Simpan'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function messages(clientMessage: string | undefined): string[] {
    return clientMessage === undefined ? [] : [clientMessage];
}

function fieldErrors(error: unknown, field: string): string[] {
    if (!(error instanceof ApiError)) {
        return [];
    }

    return error.fieldErrors(field);
}
