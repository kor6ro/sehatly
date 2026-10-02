import { useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { register, type RegisterInput } from '@/lib/api/auth';
import { ApiError } from '@/lib/http';
import { getAccessToken } from '@/lib/token';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { PESAN_TELEPON_INTERIM, apakahTeleponInterimValid } from '@/lib/telepon';
import { setPendingOtp } from '@/stores/pending-otp';
import { Field, FieldInput, FieldSelect, FormErrorSummary } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { SelectItem } from '@/components/ui/select';
import { AuthLayout } from '@/pages/auth-layout';

/**
 * The registration rules, mirroring `RegisterRequest::rules()` field for field.
 *
 * The reasons the three patient-demographic fields are collected *here* rather than
 * deferred to `PUT /pasien/profil` are the DDL's: `jenis_kelamin`, `tanggal_lahir` and
 * `alamat_lengkap` are `NOT NULL` with no default, so an insert omitting any of them is
 * MySQL 1364 - a 500. A `pasien` row cannot be created empty, which means the row cannot
 * be deferred past this call either.
 *
 * `email` and `tempat_lahir` are optional on the server (`nullable`) and are optional
 * here, marked as such on the label. `bahasa` is nullable too and defaults to `id`.
 */
const schema = z.object({
    nama_lengkap: z
        .string()
        .trim()
        .min(3, 'Nama lengkap minimal 3 karakter.')
        .max(150, 'Nama lengkap maksimal 150 karakter.'),
    no_telepon: z
        .string()
        .trim()
        .min(1, 'Isi nomor telepon.')
        .refine(apakahTeleponInterimValid, PESAN_TELEPON_INTERIM),
    email: z
        .string()
        .trim()
        .max(255, 'Email maksimal 255 karakter.')
        .refine(
            (value) => value === '' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value),
            'Format email tidak valid.',
        ),
    password: z
        .string()
        .min(8, 'Kata sandi minimal 8 karakter.')
        .max(255, 'Kata sandi maksimal 255 karakter.'),
    jenis_kelamin: z.enum(['L', 'P']),
    tanggal_lahir: z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}$/, 'Tanggal lahir harus format YYYY-MM-DD.'),
    tempat_lahir: z.string().trim().max(100, 'Maksimal 100 karakter.'),
    alamat_lengkap: z.string().trim().min(5, 'Alamat minimal 5 karakter.'),
    bahasa: z.enum(['id', 'en']),
});

type RegisterForm = z.infer<typeof schema>;

/**
 * `POST /auth/register` answers 201 with the created user and an OTP challenge, and **no
 * token** - the token is issued only by `POST /auth/otp/verify`. So this screen ends at
 * `/otp` exactly as `/login` does, and the identifier it forwards is the phone number the
 * code was sent to.
 */
export function RegisterPage() {
    useDocumentTitle('Daftar | Sehatly');

    const navigate = useNavigate();
    const [serverError, setServerError] = useState<unknown>(null);

    const {
        register: bind,
        handleSubmit,
        setValue,
        watch,
        formState: { errors, isSubmitting },
    } = useForm<RegisterForm>({
        resolver: zodResolver(schema),
        mode: 'onSubmit',
        defaultValues: {
            nama_lengkap: '',
            no_telepon: '',
            email: '',
            password: '',
            jenis_kelamin: 'L',
            tanggal_lahir: '',
            tempat_lahir: '',
            alamat_lengkap: '',
            bahasa: 'id',
        },
    });

    const jenisKelamin = watch('jenis_kelamin');
    const bahasa = watch('bahasa');

    if (getAccessToken() !== null) {
        return <Navigate to="/dashboard" replace />;
    }

    async function onSubmit(values: RegisterForm): Promise<void> {
        setServerError(null);

        const payload: RegisterInput = {
            nama_lengkap: values.nama_lengkap,
            no_telepon: values.no_telepon,
            password: values.password,
            jenis_kelamin: values.jenis_kelamin,
            tanggal_lahir: values.tanggal_lahir,
            alamat_lengkap: values.alamat_lengkap,
            bahasa: values.bahasa,
        };

        // An empty optional field is omitted rather than sent as `''`, because
        // `nullable` still runs the string rules on a value that is present: `''` would
        // fail `max:255` on nothing and `unique:users,email` would treat it as a value.
        if (values.email !== '') {
            payload.email = values.email;
        }

        if (values.tempat_lahir !== '') {
            payload.tempat_lahir = values.tempat_lahir;
        }

        try {
            const result = await register(payload);

            setPendingOtp({
                identifier: { no_telepon: payload.no_telepon },
                tujuan: result.data.otp.tujuan,
                kedaluwarsa_at: result.data.otp.kedaluwarsa_at,
                ttl_detik: result.data.otp.ttl_detik,
            });

            await navigate('/otp', { replace: true });
        } catch (error) {
            setServerError(error);
        }
    }

    return (
        <AuthLayout
            title="Daftar"
            description="Buat akun pasien. Verifikasi OTP diperlukan sebelum akun dapat dipakai."
            footer={
                <>
                    Sudah punya akun?{' '}
                    <Link to="/login" className="text-primary underline-offset-4 hover:underline">
                        Masuk
                    </Link>
                </>
            }
        >
            <form
                onSubmit={handleSubmit(onSubmit)}
                className="flex flex-col gap-4"
                noValidate
            >
                <FormErrorSummary error={serverError} />

                <fieldset
                    data-slot="register-group"
                    data-grup="data-akun"
                    className="flex flex-col gap-4"
                >
                    <legend className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">
                        Data akun
                    </legend>

                    <Field
                        label="Nama lengkap"
                        errors={messages(errors.nama_lengkap?.message)}
                        required
                    >
                        <FieldInput autoComplete="name" {...bind('nama_lengkap')} />
                    </Field>

                    <Field
                        label="Nomor telepon"
                        hint="Kode OTP dikirim ke nomor ini. Gunakan nomor yang aktif di WhatsApp/SMS. Contoh: 0812 3456 7890."
                        errors={[
                            ...messages(errors.no_telepon?.message),
                            ...fieldOf(serverError, 'no_telepon'),
                        ]}
                        required
                    >
                        <FieldInput
                            type="tel"
                            inputMode="numeric"
                            autoComplete="tel"
                            placeholder="08xx xxxx xxxx"
                            {...bind('no_telepon')}
                        />
                    </Field>

                    <Field
                        label="Email (opsional)"
                        hint="Bisa digunakan untuk masuk jika nomor telepon tidak tersedia."
                        errors={[
                            ...messages(errors.email?.message),
                            ...fieldOf(serverError, 'email'),
                        ]}
                    >
                        <FieldInput
                            type="email"
                            autoComplete="email"
                            {...bind('email')}
                        />
                    </Field>

                    <Field
                        label="Kata sandi"
                        errors={[
                            ...messages(errors.password?.message),
                            ...fieldOf(serverError, 'password'),
                        ]}
                        required
                    >
                        <FieldInput
                            type="password"
                            autoComplete="new-password"
                            {...bind('password')}
                        />
                    </Field>
                </fieldset>

                <fieldset
                    data-slot="register-group"
                    data-grup="data-diri"
                    className="flex flex-col gap-4"
                >
                    <legend className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">
                        Data diri
                    </legend>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Jenis kelamin"
                            errors={messages(errors.jenis_kelamin?.message)}
                            required
                        >
                            <FieldSelect
                                value={jenisKelamin}
                                onValueChange={(value) =>
                                    setValue('jenis_kelamin', value as 'L' | 'P')
                                }
                            >
                                <SelectItem value="L">Laki-laki</SelectItem>
                                <SelectItem value="P">Perempuan</SelectItem>
                            </FieldSelect>
                        </Field>

                        <Field
                            label="Tanggal lahir"
                            errors={[
                                ...messages(errors.tanggal_lahir?.message),
                                ...fieldOf(serverError, 'tanggal_lahir'),
                            ]}
                            required
                        >
                            <FieldInput
                                type="date"
                                max={new Date().toISOString().slice(0, 10)}
                                {...bind('tanggal_lahir')}
                            />
                        </Field>
                    </div>

                    <Field
                        label="Tempat lahir (opsional)"
                        errors={[
                            ...messages(errors.tempat_lahir?.message),
                            ...fieldOf(serverError, 'tempat_lahir'),
                        ]}
                    >
                        <FieldInput {...bind('tempat_lahir')} />
                    </Field>

                    <Field
                        label="Alamat lengkap"
                        errors={[
                            ...messages(errors.alamat_lengkap?.message),
                            ...fieldOf(serverError, 'alamat_lengkap'),
                        ]}
                        required
                    >
                        <FieldInput {...bind('alamat_lengkap')} />
                    </Field>

                    <Field
                        label="Bahasa"
                        errors={messages(errors.bahasa?.message)}
                    >
                        <FieldSelect
                            value={bahasa}
                            onValueChange={(value) =>
                                setValue('bahasa', value as 'id' | 'en')
                            }
                        >
                            <SelectItem value="id">Indonesia</SelectItem>
                            <SelectItem value="en">Inggris</SelectItem>
                        </FieldSelect>
                    </Field>
                </fieldset>

                {/**
                 * Owner decision F02 §12 #4: one line of notice and two links, and NO
                 * checkbox. `AuthRequest` accepts no consent field, so a checkbox here
                 * would collect a value the server never receives - an affirmative that
                 * goes nowhere. Consent is recorded by F02, per kind, after login.
                 */}
                <p className="text-muted-foreground text-sm">
                    Dengan mendaftar, Anda menyetujui{' '}
                    <Link
                        to="/syarat-ketentuan"
                        className="text-primary underline-offset-4 hover:underline"
                    >
                        syarat dan ketentuan
                    </Link>{' '}
                    serta{' '}
                    <Link
                        to="/kebijakan-privasi"
                        className="text-primary underline-offset-4 hover:underline"
                    >
                        kebijakan privasi
                    </Link>{' '}
                    Sehatly.
                </p>

                <Button type="submit" className="min-h-11" disabled={isSubmitting}>
                    {isSubmitting ? <Spinner /> : null}

                    {isSubmitting ? 'Mendaftarkan...' : 'Daftar'}
                </Button>
            </form>
        </AuthLayout>
    );
}

function messages(message: string | undefined): string[] {
    return message === undefined ? [] : [message];
}

function fieldOf(error: unknown, field: string): string[] {
    if (!(error instanceof ApiError)) {
        return [];
    }

    return error.fieldErrors(field);
}
