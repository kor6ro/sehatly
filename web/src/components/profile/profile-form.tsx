import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation } from '@tanstack/react-query';
import { updateProfilMutation, type UpdateProfilInput } from '@/lib/api/pasien-profil';
import { ApiError } from '@/lib/http';
import type { PasienProfile } from '@/lib/api/types';
import { toNumber } from '@/lib/format';
import { dispatchFlash } from '@/lib/flash';
import { Field, FieldInput, FormErrorSummary } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { ErrorState } from '@/components/states/error-state';

/**
 * `PUT /api/v1/pasien/profil` - the writable subset that this client can actually present.
 *
 * The rules mirror `UpdatePasienProfileRequest::rules()` for the ten keys below, and the
 * eight `master_*` reference columns are absent because the API exposes no endpoint to
 * populate them (see `ReferenceIdsNotice`). Every key here is `sometimes` server-side, so
 * the form sends only what the user actually changed - a full re-submit would be accepted,
 * but a partial one is what the endpoint was designed for and cannot blank a value the
 * user did not touch.
 */
const schema = z.object({
    nama_lengkap: z
        .string()
        .trim()
        .min(3, 'Nama lengkap minimal 3 karakter.')
        .max(150, 'Nama lengkap maksimal 150 karakter.'),
    tempat_lahir: z
        .string()
        .trim()
        .max(100, 'Tempat lahir maksimal 100 karakter.'),
    pekerjaan: z.string().trim().max(100, 'Pekerjaan maksimal 100 karakter.'),
    alamat_lengkap: z
        .string()
        .trim()
        .min(5, 'Alamat minimal 5 karakter.')
        .max(2000, 'Alamat maksimal 2000 karakter.'),
    rt: z
        .string()
        .trim()
        .regex(/^[0-9]{1,3}$/, 'RT harus 1 sampai 3 digit.'),
    rw: z
        .string()
        .trim()
        .regex(/^[0-9]{1,3}$/, 'RW harus 1 sampai 3 digit.'),
    kode_pos: z
        .string()
        .trim()
        .regex(/^[0-9]{5}$/, 'Kode pos harus 5 digit.'),
    tinggi_badan_cm: z
        .string()
        .trim()
        .regex(/^[0-9]{1,3}(\.[0-9])?$/, 'Gunakan satu angka desimal.')
        .refine(
            (value) => {
                const parsed = Number(value);

                return value === '' || (parsed >= 30 && parsed <= 300);
            },
            'Tinggi badan harus antara 30 dan 300 cm.',
        ),
    berat_badan_kg: z
        .string()
        .trim()
        .regex(/^[0-9]{1,3}(\.[0-9]{1,2})?$/, 'Gunakan maksimal dua angka desimal.')
        .refine(
            (value) => {
                const parsed = Number(value);

                return value === '' || (parsed >= 1 && parsed <= 500);
            },
            'Berat badan harus antara 1 dan 500 kg.',
        ),
});

type ProfilForm = z.infer<typeof schema>;

/**
 * Build the payload from the form, dropping every key the user left blank.
 *
 * An empty string is *not* the same as an absent key here: `nullable` still runs the
 * `regex` and `max` rules on a value that is present, so sending `rt: ''` is a 422 rather
 * than a clear. Omitting the key is what "leave this alone" means on a `sometimes` rule.
 *
 * `nama_lengkap` is the one exception - it is `required` server-side, so it is always sent
 * and an emptied field is a validation error rather than a clear. That is why it is not in
 * the "drop if blank" list.
 */
function toPayload(
    values: ProfilForm,
    original: PasienProfile,
): UpdateProfilInput {
    const payload: UpdateProfilInput = {
        nama_lengkap: values.nama_lengkap,
        alamat_lengkap: values.alamat_lengkap,
    };

    clearIfChanged(payload, 'tempat_lahir', values.tempat_lahir, original.tempat_lahir);
    clearIfChanged(payload, 'pekerjaan', values.pekerjaan, original.pekerjaan);
    clearIfChanged(payload, 'rt', values.rt, original.rt);
    clearIfChanged(payload, 'rw', values.rw, original.rw);
    clearIfChanged(payload, 'kode_pos', values.kode_pos, original.kode_pos);

    assignNumberIfChanged(
        payload,
        'tinggi_badan_cm',
        values.tinggi_badan_cm,
        original.tinggi_badan_cm,
    );
    assignNumberIfChanged(
        payload,
        'berat_badan_kg',
        values.berat_badan_kg,
        original.berat_badan_kg,
    );

    return payload;
}

/** The keys that are `nullable` server-side, so an emptied one means "clear this". */
type ClearableKey = 'tempat_lahir' | 'pekerjaan' | 'rt' | 'rw' | 'kode_pos';

function clearIfChanged(
    payload: UpdateProfilInput,
    key: ClearableKey,
    next: string,
    previous: string | null,
): void {
    if (next === previous) {
        return;
    }

    payload[key] = next === '' ? null : next;
}

function assignNumberIfChanged(
    payload: UpdateProfilInput,
    key: 'tinggi_badan_cm' | 'berat_badan_kg',
    next: string,
    previous: number | string | null,
): void {
    if (next === (previous === null ? '' : String(previous))) {
        return;
    }

    payload[key] = next === '' ? null : Number(next);
}

export function ProfileForm({ profile }: { profile: PasienProfile }) {
    const [serverError, setServerError] = useState<unknown>(null);

    const {
        register,
        handleSubmit,
        formState: { errors, isSubmitting },
    } = useForm<ProfilForm>({
        resolver: zodResolver(schema),
        defaultValues: {
            nama_lengkap: profile.nama_lengkap ?? '',
            tempat_lahir: profile.tempat_lahir ?? '',
            pekerjaan: profile.pekerjaan ?? '',
            alamat_lengkap: profile.alamat_lengkap,
            rt: profile.rt ?? '',
            rw: profile.rw ?? '',
            kode_pos: profile.kode_pos ?? '',
            tinggi_badan_cm:
                toNumber(profile.tinggi_badan_cm)?.toString() ?? '',
            berat_badan_kg: toNumber(profile.berat_badan_kg)?.toString() ?? '',
        },
    });

    const save = useMutation(updateProfilMutation());

    async function onSubmit(values: ProfilForm): Promise<void> {
        setServerError(null);

        try {
            const result = await save.mutateAsync(toPayload(values, profile));

            dispatchFlash({ level: 'success', message: result.message });
        } catch (error) {
            setServerError(error);
        }
    }

    if (serverError instanceof ApiError && !serverError.isValidation) {
        return (
            <ErrorState
                error={serverError}
                onRetry={() => {
                    setServerError(null);
                }}
            />
        );
    }

    return (
        <form
            onSubmit={handleSubmit(onSubmit)}
            className="flex flex-col gap-4"
            noValidate
        >
            <FormErrorSummary error={serverError} />

            <Field
                label="Nama lengkap"
                errors={messages(errors.nama_lengkap?.message, serverError, 'nama_lengkap')}
                required
            >
                <FieldInput autoComplete="name" {...register('nama_lengkap')} />
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Tempat lahir"
                    errors={messages(
                        errors.tempat_lahir?.message,
                        serverError,
                        'tempat_lahir',
                    )}
                >
                    <FieldInput {...register('tempat_lahir')} />
                </Field>

                <Field
                    label="Pekerjaan"
                    errors={messages(errors.pekerjaan?.message, serverError, 'pekerjaan')}
                >
                    <FieldInput {...register('pekerjaan')} />
                </Field>
            </div>

            <Field
                label="Alamat lengkap"
                errors={messages(
                    errors.alamat_lengkap?.message,
                    serverError,
                    'alamat_lengkap',
                )}
                required
            >
                <FieldInput {...register('alamat_lengkap')} />
            </Field>

            <div className="grid gap-4 sm:grid-cols-3">
                <Field
                    label="RT"
                    errors={messages(errors.rt?.message, serverError, 'rt')}
                >
                    <FieldInput inputMode="numeric" {...register('rt')} />
                </Field>

                <Field
                    label="RW"
                    errors={messages(errors.rw?.message, serverError, 'rw')}
                >
                    <FieldInput inputMode="numeric" {...register('rw')} />
                </Field>

                <Field
                    label="Kode pos"
                    errors={messages(errors.kode_pos?.message, serverError, 'kode_pos')}
                >
                    <FieldInput inputMode="numeric" {...register('kode_pos')} />
                </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Tinggi badan (cm)"
                    errors={messages(
                        errors.tinggi_badan_cm?.message,
                        serverError,
                        'tinggi_badan_cm',
                    )}
                >
                    <FieldInput inputMode="decimal" {...register('tinggi_badan_cm')} />
                </Field>

                <Field
                    label="Berat badan (kg)"
                    errors={messages(
                        errors.berat_badan_kg?.message,
                        serverError,
                        'berat_badan_kg',
                    )}
                >
                    <FieldInput inputMode="decimal" {...register('berat_badan_kg')} />
                </Field>
            </div>

            <Button
                type="submit"
                disabled={isSubmitting || save.isPending}
                className="w-fit"
            >
                {isSubmitting || save.isPending ? <Spinner /> : null}

                {isSubmitting || save.isPending ? 'Menyimpan...' : 'Simpan perubahan'}
            </Button>
        </form>
    );
}

function messages(
    clientMessage: string | undefined,
    serverError: unknown,
    field: string,
): string[] {
    return [
        ...(clientMessage === undefined ? [] : [clientMessage]),
        ...(serverError instanceof ApiError ? serverError.fieldErrors(field) : []),
    ];
}
