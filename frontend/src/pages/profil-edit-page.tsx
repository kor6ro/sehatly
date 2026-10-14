import { useId, useState } from 'react';
import { Link, Navigate, useNavigate, useParams, useSearchParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AlertCircle } from 'lucide-react';
import { lengkapiSignUp, type LengkapiSignUpInput } from '@/lib/api/auth';
import { meOptions } from '@/lib/api/me';
import { ApiError } from '@/lib/http';
import { dispatchFlash } from '@/lib/flash';
import { queryClient } from '@/lib/query-client';
import { clearTokens } from '@/lib/token';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { Field, FieldInput, FormErrorSummary } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { ThemeToggle } from '@/components/layout/theme-toggle';
import { ErrorState } from '@/components/states/error-state';
import { cn } from '@/lib/utils';

/**
 * The two mandatory UU PDP consents, one checkbox each.
 *
 * Deliberately NOT the single combined box `/register` used. That box asked for two
 * decisions with one act and recorded two ledger rows from it, which is defensible when
 * the alternative is a screen nobody reaches; here the sign-up form is the whole point of
 * the page, so each decision gets its own control and its own refusal message. The
 * server's `accepted` on each key is the same shape - two fields, two rules, two rows.
 */
const PESAN_SYARAT = 'Anda harus menyetujui Syarat dan Ketentuan.';
const PESAN_PRIVASI = 'Anda harus menyetujui Kebijakan Privasi.';

/**
 * The completion rules, mirroring `LengkapiSignUpRequest::rules()` field for field.
 *
 * The three demographic fields are validated HERE rather than left to the server because
 * each of them is a 422 the user could have avoided: `alamat_lengkap` is `NOT NULL` with
 * no default, `jenis_kelamin` is a closed `ENUM('L','P')`, and `tanggal_lahir` is a
 * `DATE` that refuses both a future date and anything that is not `Y-m-d`.
 *
 * `no_telepon` is absent on purpose - the endpoint does not accept it, so a form field
 * for it would be a control that cannot be obeyed. It is rendered locked instead, with
 * the one action that CAN change it.
 */
const schema = z.object({
    nama_lengkap: z
        .string()
        .trim()
        .min(3, 'Nama lengkap minimal 3 karakter.')
        .max(150, 'Nama lengkap maksimal 150 karakter.'),
    jenis_kelamin: z.enum(['L', 'P']),
    tanggal_lahir: z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}$/, 'Tanggal lahir harus format YYYY-MM-DD.')
        .refine(
            (value) => value >= '1900-01-01',
            'Tanggal lahir tidak boleh sebelum tahun 1900.',
        )
        .refine(
            (value) => value <= new Date().toISOString().slice(0, 10),
            'Tanggal lahir tidak boleh di masa depan.',
        ),
    tempat_lahir: z.string().trim().max(100, 'Maksimal 100 karakter.'),
    alamat_lengkap: z.string().trim().min(5, 'Alamat minimal 5 karakter.'),
    email: z
        .string()
        .trim()
        .max(255, 'Email maksimal 255 karakter.')
        .refine(
            (value) => value === '' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value),
            'Format email tidak valid.',
        ),
    syarat: z.boolean().refine((value) => value === true, { message: PESAN_SYARAT }),
    privasi: z.boolean().refine((value) => value === true, { message: PESAN_PRIVASI }),
});

type LengkapiForm = z.infer<typeof schema>;

/**
 * `/profil/edit/:userId?sign_up=true`
 *
 * ## The halodoc shape, and why the `/register` route is gone
 *
 * halodoc does not have a separate registration form. It signs the phone number first,
 * then hands the visitor this page to finish the account at
 * `profil/edit/{id}?sign_up=true` - one door, and the door does not change shape halfway
 * through. Sehatly now does the same, and `/register` was removed from the router rather
 * than left redirecting, because a route that exists to say "that page moved" is a link
 * the whole codebase has to keep not-breaking against.
 *
 * The two halves of the URL are both load-bearing:
 *
 * - **`{userId}`** must be this session's own account. It is not an authorisation - the
 *   server decides whose row this is from the bearer token, and the endpoint never reads
 *   an id from the body - so a mismatch is a typo or a stale link, and the answer is to
 *   replace it with the right one rather than to 403.
 * - **`sign_up=true`** is what makes this the completion screen. Without it the path is
 *   still the halodoc edit URL, and the existing `/profil` screen owns editing an account
 *   that already exists, so an absent flag lands there.
 *
 * ## Why this is NOT inside `AppShell`
 *
 * The account arriving here has just been minted: no `pasien` row, no role grant, no
 * ledger. Every item in the shell's navigation resolves to a patient screen that has
 * nothing to render for them, and a sidebar that offers six dead ends next to the form
 * that would make them work is worse than no sidebar. So this page carries its own thin
 * brand bar - the mark, the wordmark, the theme switch - and gives the form the whole
 * width it needs. Once `Buat Akun` succeeds the shell is the right frame again, and that
 * is where the next navigation goes.
 */
export function ProfilEditPage() {
    useDocumentTitle('Yuk, Buat Akun Kamu! | Sehatly');

    const navigate = useNavigate();
    const { userId } = useParams();
    const [searchParams] = useSearchParams();
    const [serverError, setServerError] = useState<unknown>(null);

    const signUp = searchParams.get('sign_up') === 'true';

    const {
        register: bind,
        handleSubmit,
        setValue,
        watch,
        formState: { errors, isSubmitting },
    } = useForm<LengkapiForm>({
        resolver: zodResolver(schema),
        mode: 'onSubmit',
        defaultValues: {
            nama_lengkap: '',
            jenis_kelamin: 'L',
            tanggal_lahir: '',
            tempat_lahir: '',
            alamat_lengkap: '',
            email: '',
            syarat: false,
            privasi: false,
        },
    });

    const consentId = useId();
    const privasiId = useId();
    const phoneId = useId();

    const jenisKelamin = watch('jenis_kelamin');
    const syarat = watch('syarat');
    const privasi = watch('privasi');

    const me = useQuery(meOptions());

    // The edit case belongs to `/profil`, which already exists and already owns the
    // "change something about an account that is finished" flow. Doing it again here
    // would be a second profile editor with a different payload.
    if (!signUp) {
        return <Navigate to="/profil" replace />;
    }

    if (me.isPending) {
        return (
            <div className="flex min-h-screen items-center justify-center">
                <Spinner className="size-6" aria-label="Memuat akun..." />
            </div>
        );
    }

    if (me.isError) {
        return (
            <div className="mx-auto max-w-lg px-4 py-16">
                <ErrorState error={me.error} onRetry={() => void me.refetch()} />
            </div>
        );
    }

    const akun = me.data.data.user;

    if (String(akun.id) !== userId) {
        // The id in the path is a label for a row the server already resolved from the
        // token, so a wrong one is a stale link rather than an attempt on someone
        // else's record. Correct it instead of refusing - and because `replace`, the
        // visitor never sees a URL that does not belong to them.
        return (
            <Navigate to={`/profil/edit/${akun.id}?sign_up=true`} replace />
        );
    }

    // Finished accounts have nowhere to go here: the consents were already taken and
    // the row already exists, so replaying this form would be a second decision on a
    // ledger that only records decisions actually made. They go where every other door
    // in this app puts a signed-in visitor now - the landing page, not the dashboard.
    if (akun.nama_lengkap !== '' && akun.pasien !== undefined) {
        return <Navigate to="/" replace />;
    }

    const nasional = akun.no_telepon.replace(/^0/, '');

    function ubahNomor(): void {
        // The shell is real but its identity is the phone number, which is the one
        // fact this form refuses to change. Starting over therefore means dropping the
        // session, not editing it - and dropping it here rather than on the server,
        // because the token is ours to discard and revoking it would cost a round trip
        // on a decision that has already been made.
        clearTokens();
        queryClient.clear();
        void navigate('/login', { replace: true });
    }

    async function onSubmit(values: LengkapiForm): Promise<void> {
        setServerError(null);

        const payload: LengkapiSignUpInput = {
            nama_lengkap: values.nama_lengkap,
            jenis_kelamin: values.jenis_kelamin,
            tanggal_lahir: values.tanggal_lahir,
            alamat_lengkap: values.alamat_lengkap,
            persetujuan_syarat_ketentuan: values.syarat,
            persetujuan_kebijakan_privasi: values.privasi,
        };

        // An empty optional field is omitted rather than sent as `''`: `nullable` still
        // runs the string rules on a value that is PRESENT, so `''` would fail `max`
        // on nothing and put `unique:users,email` in front of a value nobody meant.
        if (values.email !== '') {
            payload.email = values.email;
        }

        if (values.tempat_lahir !== '') {
            payload.tempat_lahir = values.tempat_lahir;
        }

        try {
            await lengkapiSignUp(payload);

            // Re-read before the next screen asks for the row it just created, so the
            // landing page's header - which greets by name - is not the shell the cache
            // still holds.
            await queryClient.invalidateQueries({ queryKey: ['v1', 'me'] });

            dispatchFlash({
                level: 'success',
                message: 'Akun kamu sudah jadi. Selamat datang di Sehatly!',
            });

            await navigate('/', { replace: true });
        } catch (error) {
            setServerError(error);
        }
    }

    return (
        <div className="bg-background min-h-screen">
            {/**
             * The thin bar, and the whole of this page's chrome. It is not `AppShell`'s
             * header - that one needs an account that can use the sidebar - and not
             * `LandingHeader` either, which offers "Masuk" to a visitor who is already
             * holding a token. The mark, the wordmark and the theme switch are the three
             * things that stay true on both sides of the sign-up boundary.
             */}
            <header className="border-border bg-background sticky top-0 z-30 border-b">
                <div className="mx-auto flex h-14 max-w-6xl items-center justify-between px-4">
                    <Link
                        to="/"
                        className="flex items-center gap-2"
                        aria-label="Sehatly"
                    >
                        <img src="/logo.svg" alt="" className="size-7" />
                        <span className="text-lg font-semibold tracking-tight">
                            Sehatly
                        </span>
                    </Link>

                    <ThemeToggle />
                </div>
            </header>

            <main className="mx-auto grid max-w-6xl gap-8 px-4 py-10 md:grid-cols-2 md:items-center md:gap-14 md:py-16">
                {/**
                 * The left half, and the reason this is a two-column page rather than a
                 * centred card: the visitor needs to know WHAT they are finishing before
                 * they start filling. halodoc puts the same two lines on the left of the
                 * same split, which is the shape this borrows.
                 */}
                <section className="flex flex-col gap-3">
                    <h1 className="text-3xl font-bold tracking-tight md:text-4xl">
                        Yuk, Buat Akun Kamu!
                    </h1>

                    <p className="text-muted-foreground max-w-md text-base leading-relaxed">
                        Isi data diri sesuai KTP atau paspor. Nomor ponsel kamu sudah
                        terverifikasi, jadi tinggal beberapa langkah lagi.
                    </p>

                    <div className="border-border mt-3 max-w-md rounded-lg border p-4 text-sm leading-relaxed">
                        <p className="text-muted-foreground">
                            Data yang kamu isi disimpan untuk kebutuhan layanan medis
                            Sehatly dan dilindungi sesuai{' '}
                            <Link
                                to="/kebijakan-privasi"
                                className="text-primary font-medium underline underline-offset-4"
                            >
                                Kebijakan Privasi
                            </Link>
                            .
                        </p>
                    </div>
                </section>

                <section className="border-border bg-card rounded-xl border p-5 shadow-sm sm:p-7">
                    <form
                        onSubmit={handleSubmit(onSubmit)}
                        className="flex flex-col gap-5"
                        noValidate
                    >
                        <FormErrorSummary error={serverError} />

                        <Field
                            label="Nama Lengkap"
                            errors={[
                                ...messages(errors.nama_lengkap?.message),
                                ...fieldOf(serverError, 'nama_lengkap'),
                            ]}
                            required
                        >
                            <FieldInput
                                autoComplete="name"
                                placeholder="Nama sesuai KTP atau paspor"
                                {...bind('nama_lengkap')}
                            />
                        </Field>

                        <div className="grid gap-5 sm:grid-cols-2">
                            <Field
                                label="Tanggal Lahir"
                                errors={[
                                    ...messages(errors.tanggal_lahir?.message),
                                    ...fieldOf(serverError, 'tanggal_lahir'),
                                ]}
                                required
                            >
                                <FieldInput
                                    type="date"
                                    max={new Date().toISOString().slice(0, 10)}
                                    min="1900-01-01"
                                    {...bind('tanggal_lahir')}
                                />
                            </Field>

                            {/**
                             * Two cards rather than a dropdown. It is a closed, two-value
                             * question the answer to is either one of, so a select would
                             * hide half the options behind a click to ask a question with
                             * no free text in it - and both values stay visible, which is
                             * what makes the choice readable at a glance.
                             */}
                            <fieldset className="flex flex-col gap-2">
                                <legend className="text-foreground mb-2 text-sm font-medium">
                                    Jenis Kelamin{' '}
                                    <span className="text-destructive" aria-hidden>
                                        *
                                    </span>
                                </legend>

                                <div
                                    role="radiogroup"
                                    aria-label="Jenis kelamin"
                                    aria-invalid={
                                        errors.jenis_kelamin === undefined
                                            ? undefined
                                            : true
                                    }
                                    className="grid grid-cols-2 gap-3"
                                >
                                    {(
                                        [
                                            ['L', 'Laki-laki'],
                                            ['P', 'Perempuan'],
                                        ] as const
                                    ).map(([nilai, label]) => (
                                        <label
                                            key={nilai}
                                            className={cn(
                                                'border-input flex min-h-11 cursor-pointer items-center justify-center rounded-lg border px-3 text-sm font-medium transition-colors has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-offset-2',
                                                jenisKelamin === nilai
                                                    ? 'border-primary bg-primary/5 text-primary'
                                                    : 'bg-background hover:bg-muted',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                value={nilai}
                                                checked={jenisKelamin === nilai}
                                                onChange={() =>
                                                    setValue(
                                                        'jenis_kelamin',
                                                        nilai,
                                                        { shouldValidate: true },
                                                    )
                                                }
                                                className="sr-only"
                                            />

                                            {label}
                                        </label>
                                    ))}
                                </div>

                                {errors.jenis_kelamin === undefined ? null : (
                                    <p className="text-destructive flex items-start gap-1.5 text-xs">
                                        <AlertCircle
                                            aria-hidden
                                            className="mt-0.5 size-3 shrink-0"
                                        />

                                        {errors.jenis_kelamin.message}
                                    </p>
                                )}
                            </fieldset>
                        </div>

                        <Field
                            label="Tempat Lahir (Opsional)"
                            errors={[
                                ...messages(errors.tempat_lahir?.message),
                                ...fieldOf(serverError, 'tempat_lahir'),
                            ]}
                        >
                            <FieldInput
                                placeholder="Kota kelahiran"
                                {...bind('tempat_lahir')}
                            />
                        </Field>

                        <Field
                            label="Alamat Lengkap"
                            hint="Sesuai KTP atau paspor, termasuk kelurahan, kecamatan, dan kota."
                            errors={[
                                ...messages(errors.alamat_lengkap?.message),
                                ...fieldOf(serverError, 'alamat_lengkap'),
                            ]}
                            required
                        >
                            <FieldInput
                                autoComplete="street-address"
                                placeholder="Jl. Merdeka No. 17, Bandung"
                                {...bind('alamat_lengkap')}
                            />
                        </Field>

                        {/**
                         * Locked, and the lock is honest: this number was proved over OTP
                         * a minute ago and the endpoint refuses to take it back, because
                         * accepting it would let a verified session re-point its own
                         * account at a number nobody has demonstrated control of. "Ubah"
                         * is therefore not an edit - there is nothing to edit here - but
                         * the start-over that changing a number actually requires.
                         */}
                        <div className="flex flex-col gap-2">
                            <label
                                htmlFor={phoneId}
                                className="text-foreground text-sm font-medium"
                            >
                                Nomor Ponsel{' '}
                                <span
                                    className="text-muted-foreground font-normal"
                                    aria-hidden
                                >
                                    (terverifikasi)
                                </span>
                            </label>

                            <div className="flex items-stretch gap-2">
                                <span
                                    aria-hidden
                                    className="bg-muted flex h-10 shrink-0 items-center gap-1.5 rounded-md border border-input px-2.5 text-sm"
                                >
                                    <span className="flex h-3 w-4 shrink-0 flex-col overflow-hidden rounded-[1px] ring-1 ring-black/15">
                                        <span className="h-1/2 bg-[#ce1126]" />
                                        <span className="h-1/2 bg-white" />
                                    </span>

                                    <span className="tabular-nums">+62</span>
                                </span>

                                <input
                                    id={phoneId}
                                    readOnly
                                    value={nasional}
                                    className="border-input bg-muted text-foreground h-10 min-w-0 flex-1 rounded-md border px-3 text-sm tabular-nums"
                                />

                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-10 shrink-0"
                                    onClick={ubahNomor}
                                >
                                    Ubah
                                </Button>
                            </div>

                            <p className="text-muted-foreground text-xs leading-relaxed">
                                Ganti nomor berarti memulai dari awal dengan nomor lain.
                            </p>
                        </div>

                        <Field
                            label="Email (Opsional)"
                            hint="Bisa digunakan untuk masuk jika nomor ponsel tidak tersedia."
                            errors={[
                                ...messages(errors.email?.message),
                                ...fieldOf(serverError, 'email'),
                            ]}
                        >
                            <FieldInput
                                type="email"
                                autoComplete="email"
                                placeholder="nama@contoh.id"
                                {...bind('email')}
                            />
                        </Field>

                        {/**
                         * Two decisions, two controls. The ledger gets one row per box,
                         * written when the account is finished rather than when it was
                         * minted - a box nobody has ticked is not consent, and this page
                         * is the first place anybody could tick one.
                         */}
                        <div data-slot="lengkapi-consent" className="flex flex-col gap-4">
                            <ConsentBox
                                id={`${consentId}-syarat`}
                                checked={syarat}
                                onCheckedChange={(nilai) =>
                                    setValue('syarat', nilai, {
                                        shouldValidate: true,
                                    })
                                }
                                error={errors.syarat?.message}
                            >
                                Saya menyetujui{' '}
                                <Link
                                    to="/syarat-ketentuan"
                                    className="text-primary underline underline-offset-4 hover:underline"
                                >
                                    Syarat dan Ketentuan
                                </Link>{' '}
                                Sehatly.
                            </ConsentBox>

                            <ConsentBox
                                id={`${privasiId}-privasi`}
                                checked={privasi}
                                onCheckedChange={(nilai) =>
                                    setValue('privasi', nilai, {
                                        shouldValidate: true,
                                    })
                                }
                                error={errors.privasi?.message}
                            >
                                Saya menyetujui{' '}
                                <Link
                                    to="/kebijakan-privasi"
                                    className="text-primary underline underline-offset-4 hover:underline"
                                >
                                    Kebijakan Privasi
                                </Link>{' '}
                                Sehatly.
                            </ConsentBox>
                        </div>

                        <Button
                            type="submit"
                            className="min-h-11"
                            disabled={isSubmitting}
                        >
                            {isSubmitting ? <Spinner /> : null}

                            {isSubmitting ? 'Menyimpan...' : 'Buat Akun'}
                        </Button>
                    </form>
                </section>
            </main>
        </div>
    );
}

/**
 * One consent decision: a checkbox, its own label and its own refusal.
 *
 * The error is rendered under the box rather than into `FormErrorSummary`, because two
 * independent decisions cannot be attributed to a single summary line - the user has to
 * be able to see WHICH box is the one standing between them and the button.
 */
function ConsentBox({
    id,
    checked,
    onCheckedChange,
    error,
    children,
}: {
    id: string;
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    error: string | undefined;
    children: React.ReactNode;
}) {
    const errorId = `${id}-error`;

    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex items-start gap-3">
                <Checkbox
                    id={id}
                    checked={checked}
                    onCheckedChange={(nilai) => onCheckedChange(nilai === true)}
                    aria-invalid={error === undefined ? undefined : true}
                    aria-describedby={error === undefined ? undefined : errorId}
                    className="relative mt-0.5 size-6 after:absolute after:-inset-2.5 after:content-['']"
                />

                <Label htmlFor={id} className="font-normal leading-relaxed">
                    {children}
                </Label>
            </div>

            {error === undefined ? null : (
                <p
                    id={errorId}
                    className="text-destructive flex items-start gap-1.5 text-xs"
                >
                    <AlertCircle aria-hidden className="mt-0.5 size-3 shrink-0" />

                    {error}
                </p>
            )}
        </div>
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
