import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation } from '@tanstack/react-query';
import {
    createAnggotaKeluargaMutation,
    updateAnggotaKeluargaMutation,
    HUBUNGAN_KELUARGA,
    type AnggotaKeluargaInput,
    type UpdateAnggotaKeluargaInput,
} from '@/lib/api/anggota-keluarga';
import { ApiError } from '@/lib/http';
import type { AnggotaKeluarga } from '@/lib/api/types';
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
 * The create and edit form for one family member.
 *
 * ## The NIK input is write-only, and on edit it starts empty on purpose
 *
 * `PasienAnggotaKeluargaResource` masks `nik` on read through the same `NikMasker` the
 * patient's own record uses, so what the list shows is `3273........0021` - not a valid
 * `digits:16` value and not the stored one. There is no way to recover the real NIK from
 * the server, which is the intended trade: a value the client must re-submit is a value
 * the server never has to disclose.
 *
 * So on edit the field is empty and **submitting it empty omits the key entirely** (see
 * {@link toUpdatePayload}), leaving the stored value untouched. Pre-filling it with the
 * masked string would be worse than useless: the server would reject it, and a user who
 * pressed "save" anyway would overwrite a real identifier with a broken one.
 */
const schema = z.object({
    /**
     * Kept as a string in form state and parsed on submit: `z.coerce.number()` types the
     * resolver's input as `unknown` and breaks `useForm<AnggotaForm>`, and a `Select` never
     * produced a number in the DOM anyway.
     */
    hubungan_id: z.string().min(1, 'Pilih hubungan keluarga.'),
    nama_lengkap: z
        .string()
        .trim()
        .min(3, 'Nama lengkap minimal 3 karakter.')
        .max(150, 'Nama lengkap maksimal 150 karakter.'),
    jenis_kelamin: z.enum(['L', 'P']),
    tanggal_lahir: z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}$/, 'Tanggal lahir harus format YYYY-MM-DD.'),
    nik: z
        .string()
        .trim()
        .regex(/^$|^\d{16}$/, 'NIK harus tepat 16 digit angka.'),
    no_telepon: z
        .string()
        .trim()
        .regex(/^$|^\+?[0-9]{8,20}$/, 'Nomor telepon harus 8 sampai 20 digit.'),
    catatan_alergi: z.string().trim().max(2000, 'Maksimal 2000 karakter.'),
});

type AnggotaForm = z.infer<typeof schema>;

function toCreatePayload(values: AnggotaForm): AnggotaKeluargaInput {
    return {
        hubungan_id: Number(values.hubungan_id),
        nama_lengkap: values.nama_lengkap,
        jenis_kelamin: values.jenis_kelamin,
        tanggal_lahir: values.tanggal_lahir,
        nik: values.nik === '' ? null : values.nik,
        no_telepon: values.no_telepon === '' ? null : values.no_telepon,
        catatan_alergi: values.catatan_alergi === '' ? null : values.catatan_alergi,
    };
}

/**
 * `PUT` is a partial update, so an untouched field must not be in the payload at all.
 *
 * `AnggotaKeluargaRequest` uses `sometimes`, and `anggotaKeys()` writes only the keys
 * present in the body - which is the whole reason this does not simply reuse
 * {@link toCreatePayload}: sending `nik: null` on an edit would **clear** the stored NIK,
 * and sending the masked value would 422.
 */
function toUpdatePayload(
    values: AnggotaForm,
    original: AnggotaKeluarga,
): UpdateAnggotaKeluargaInput {
    const payload: UpdateAnggotaKeluargaInput = {};

    if (Number(values.hubungan_id) !== original.hubungan_id) {
        payload.hubungan_id = Number(values.hubungan_id);
    }

    if (values.nama_lengkap !== original.nama_lengkap) {
        payload.nama_lengkap = values.nama_lengkap;
    }

    if (values.jenis_kelamin !== original.jenis_kelamin) {
        payload.jenis_kelamin = values.jenis_kelamin;
    }

    if (values.tanggal_lahir !== (original.tanggal_lahir ?? '')) {
        payload.tanggal_lahir = values.tanggal_lahir;
    }

    // The only way to *change* a NIK is to type a new one; an empty box means "leave it".
    if (values.nik !== '') {
        payload.nik = values.nik;
    }

    if (values.no_telepon !== (original.no_telepon ?? '')) {
        payload.no_telepon = values.no_telepon === '' ? null : values.no_telepon;
    }

    if (values.catatan_alergi !== (original.catatan_alergi ?? '')) {
        payload.catatan_alergi =
            values.catatan_alergi === '' ? null : values.catatan_alergi;
    }

    return payload;
}

export function AnggotaKeluargaDialog({
    open,
    onOpenChange,
    row,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** `null` for create, the row being edited for update. */
    row: AnggotaKeluarga | null;
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
    } = useForm<AnggotaForm>({
        resolver: zodResolver(schema),
        values: {
            hubungan_id: String(row?.hubungan_id ?? HUBUNGAN_KELUARGA[0]?.id ?? 1),
            nama_lengkap: row?.nama_lengkap ?? '',
            jenis_kelamin: row?.jenis_kelamin ?? 'L',
            tanggal_lahir: row?.tanggal_lahir ?? '',
            nik: '',
            no_telepon: row?.no_telepon ?? '',
            catatan_alergi: row?.catatan_alergi ?? '',
        },
    });

    const create = useMutation(createAnggotaKeluargaMutation());
    const update = useMutation(updateAnggotaKeluargaMutation());

    const hubunganId = watch('hubungan_id');
    const jenisKelamin = watch('jenis_kelamin');

    async function onSubmit(values: AnggotaForm): Promise<void> {
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
                        {isEdit ? 'Ubah anggota keluarga' : 'Tambah anggota keluarga'}
                    </DialogTitle>

                    <DialogDescription>
                        {isEdit
                            ? 'Perubahan dikirim ke PUT /api/v1/pasien/anggota-keluarga/{id}. Kolom NIK dikosongkan karena nilai yang disimpan dimasking oleh server.'
                            : 'Data dikirim ke POST /api/v1/pasien/anggota-keluarga.'}
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
                        label="Hubungan keluarga"
                        errors={fieldErrors(serverError, 'hubungan_id')}
                        required
                    >
                        <FieldSelect
                            value={hubunganId}
                            onValueChange={(value) => {
                                setValue('hubungan_id', value, {
                                    shouldValidate: true,
                                });
                            }}
                        >
                            {HUBUNGAN_KELUARGA.map((row_) => (
                                <SelectItem key={row_.id} value={String(row_.id)}>
                                    {row_.nama}
                                </SelectItem>
                            ))}
                        </FieldSelect>
                    </Field>

                    <Field
                        label="Nama lengkap"
                        errors={[
                            ...messages(errors.nama_lengkap?.message),
                            ...fieldErrors(serverError, 'nama_lengkap'),
                        ]}
                        required
                    >
                        <FieldInput {...register('nama_lengkap')} />
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Jenis kelamin"
                            errors={fieldErrors(serverError, 'jenis_kelamin')}
                            required
                        >
                            <FieldSelect
                                value={jenisKelamin}
                                onValueChange={(value) => {
                                    setValue('jenis_kelamin', value as 'L' | 'P', {
                                        shouldValidate: true,
                                    });
                                }}
                            >
                                <SelectItem value="L">Laki-laki</SelectItem>
                                <SelectItem value="P">Perempuan</SelectItem>
                            </FieldSelect>
                        </Field>

                        <Field
                            label="Tanggal lahir"
                            errors={[
                                ...messages(errors.tanggal_lahir?.message),
                                ...fieldErrors(serverError, 'tanggal_lahir'),
                            ]}
                            required
                        >
                            <FieldInput
                                type="date"
                                max={new Date().toISOString().slice(0, 10)}
                                {...register('tanggal_lahir')}
                            />
                        </Field>
                    </div>

                    <Field
                        label="NIK"
                        hint="16 digit. Kosongkan saat mengubah untuk mempertahankan nilai lama."
                        errors={[
                            ...messages(errors.nik?.message),
                            ...fieldErrors(serverError, 'nik'),
                        ]}
                    >
                        <FieldInput
                            inputMode="numeric"
                            maxLength={16}
                            {...register('nik')}
                        />
                    </Field>

                    <Field
                        label="Nomor telepon"
                        errors={[
                            ...messages(errors.no_telepon?.message),
                            ...fieldErrors(serverError, 'no_telepon'),
                        ]}
                    >
                        <FieldInput
                            type="tel"
                            inputMode="numeric"
                            {...register('no_telepon')}
                        />
                    </Field>

                    <Field
                        label="Catatan alergi"
                        errors={[
                            ...messages(errors.catatan_alergi?.message),
                            ...fieldErrors(serverError, 'catatan_alergi'),
                        ]}
                    >
                        <FieldInput {...register('catatan_alergi')} />
                    </Field>

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
