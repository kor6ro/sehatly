import { useId, useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle } from 'lucide-react';
import { register, type RegisterInput } from '@/lib/api/auth';
import { ApiError } from '@/lib/http';
import { getAccessToken } from '@/lib/token';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { PESAN_TELEPON_FORMAT, apakahTeleponValid } from '@/lib/telepon';
import { setPendingOtp } from '@/stores/pending-otp';
import { Field, FieldInput, FieldSelect, FormErrorSummary } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
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
const PESAN_CONSENT_WAJIB =
    'Anda harus menyetujui Syarat dan Ketentuan serta Kebijakan Privasi untuk mendaftar.';

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
        .max(20, 'Nomor telepon maksimal 20 karakter.')
        .refine(apakahTeleponValid, PESAN_TELEPON_FORMAT),
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
    persetujuan: z.boolean().refine((value) => value === true, {
        message: PESAN_CONSENT_WAJIB,
    }),
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
            persetujuan: false,
        },
    });

    const consentId = useId();
    const consentErrorId = `${consentId}-error`;
    const consentError = errors.persetujuan?.message;
    const jenisKelamin = watch('jenis_kelamin');
    const bahasa = watch('bahasa');
    const persetujuan = watch('persetujuan');

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
            // The checkbox is one act, both consents are mandatory and the schema
            // has already refused `false`; the server records one ledger row each.
            persetujuan_syarat_ketentuan: values.persetujuan,
            persetujuan_kebijakan_privasi: values.persetujuan,
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
                    <Link to="/login" className="text-primary underline underline-offset-4 hover:underline">
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
                        hint="Kode OTP dikirim ke nomor ini. Gunakan nomor yang aktif di WhatsApp/SMS. Format 08xx atau +628xx sama-sama diterima."
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
                 * Owner decision option (a) for F01 §12 #1: the two mandatory UU PDP
                 * consents are taken HERE, at registration, and `RegisterRequest`
                 * records one `persetujuan_pdp` row per field in the same transaction
                 * as the account. The checkbox is required and unchecked blocks the
                 * submit; the optional consents stay on F02.
                 */}
                <div data-slot="register-consent" className="flex flex-col gap-1.5">
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id={consentId}
                            checked={persetujuan}
                            onCheckedChange={(checked) =>
                                setValue('persetujuan', checked === true, {
                                    shouldValidate: true,
                                })
                            }
                            aria-invalid={consentError === undefined ? undefined : true}
                            aria-describedby={
                                consentError === undefined ? undefined : consentErrorId
                            }
                            className="after:absolute after:-inset-2.5 after:content-[''] relative mt-0.5 size-6"
                        />

                        <Label htmlFor={consentId} className="font-normal leading-relaxed">
                            Saya menyetujui{' '}
                            <Link
                                to="/syarat-ketentuan"
                                className="text-primary underline underline-offset-4 hover:underline"
                            >
                                Syarat dan Ketentuan
                            </Link>{' '}
                            serta{' '}
                            <Link
                                to="/kebijakan-privasi"
                                className="text-primary underline underline-offset-4 hover:underline"
                            >
                                Kebijakan Privasi
                            </Link>{' '}
                            Sehatly.
                        </Label>
                    </div>

                    {consentError === undefined ? null : (
                        <p
                            id={consentErrorId}
                            className="text-destructive flex items-start gap-1.5 text-xs"
                        >
                            <AlertCircle aria-hidden className="mt-0.5 size-3 shrink-0" />

                            {consentError}
                        </p>
                    )}
                </div>

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
