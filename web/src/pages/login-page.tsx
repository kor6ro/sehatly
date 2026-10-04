import { useState } from 'react';
import { Navigate, useNavigate } from 'react-router';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { KeyRound } from 'lucide-react';
import { login, type Identifier } from '@/lib/api/auth';
import { ApiError } from '@/lib/http';
import { getAccessToken } from '@/lib/token';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { PESAN_TELEPON_FORMAT, apakahTeleponValid } from '@/lib/telepon';
import { setPendingOtp } from '@/stores/pending-otp';
import { Field, FieldInput, FormErrorSummary } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Spinner } from '@/components/ui/spinner';
import { ErrorState } from '@/components/states/error-state';
import { AuthLayout } from '@/pages/auth-layout';

/**
 * The identifier's two shapes, and the rules that apply to each.
 *
 * `AuthRequest::identifierRules()` validates `no_telepon` as
 * `['nullable','string','max:20']` and `email` as `['nullable','string','email','max:255']`,
 * plus `LoginRequest`'s own `regex:/^\+?[0-9]{8,20}$/` on the phone. These mirror all
 * three so an obviously-invalid value does not cost a round trip.
 *
 * The phone rule mirrors the server's `/^\+?[0-9]{8,20}$/` after the backend
 * normalisation landed (commit `aacb7e8`): `08xx`, `62xx` and `+62xx` are all
 * accepted and sent as typed. `AuthRequest::prepareForValidation()` folds them into
 * the canonical `08…` before the lookup, so the two spellings reach one account. The
 * server remains the authority for everything else.
 */
type IdentifierKind = 'no_telepon' | 'email';

function schemaFor(kind: IdentifierKind) {
    return z.object({
        identifier:
            kind === 'email'
                ? z
                      .string()
                      .trim()
                      .min(1, 'Isi email.')
                      .max(255, 'Email maksimal 255 karakter.')
                      .email('Format email tidak valid.')
                : z
                      .string()
                      .trim()
                      .min(1, 'Isi nomor telepon.')
                      .max(20, 'Nomor telepon maksimal 20 karakter.')
                      .refine(apakahTeleponValid, PESAN_TELEPON_FORMAT),
    });
}

type LoginForm = z.infer<ReturnType<typeof schemaFor>>;

/**
 * Turn the two UI fields into the one of `no_telepon` / `email` the server accepts.
 *
 * `AuthRequest` refuses both absent and puts the same message on both keys, so the payload
 * carries exactly one. `no_telepon` wins when both are sent, which is why only one is ever
 * sent here.
 */
function toIdentifier(values: LoginForm, kind: IdentifierKind): Identifier {
    if (kind === 'email') {
        return { email: values.identifier };
    }

    // The stored spelling is what is looked up, exactly. Stripping spaces would make an
    // account that was registered as `0812 3456` unreachable.
    return { no_telepon: values.identifier };
}

const PESAN_KEAMANAN_TELEPON =
    'Kami akan mengirim kode 6 digit ke nomor ini untuk memastikan akun Anda aman.';
const PESAN_KEAMANAN_EMAIL =
    'Kami akan mengirim kode 6 digit ke email ini untuk memastikan akun Anda aman.';

/**
 * `/login`
 *
 * ## The response is not a session, and this screen is built around that
 *
 * `POST /auth/login` answers `{ success, data: { otp }, message }` and **no token**, by
 * design. So a successful submit here does not sign anybody in; it records the identifier,
 * moves to `/otp` and waits for the code that was sent to the phone. A screen that treated
 * this response as a session would be silently broken, and the failure would only show up
 * on the first authenticated request.
 */
export function LoginPage() {
    useDocumentTitle('Masuk | Sehatly');

    const navigate = useNavigate();
    const [kind, setKind] = useState<IdentifierKind>('no_telepon');
    const [serverError, setServerError] = useState<unknown>(null);

    const {
        register,
        handleSubmit,
        setError,
        formState: { errors, isSubmitting },
    } = useForm<LoginForm>({
        resolver: zodResolver(schemaFor(kind)),
        defaultValues: { identifier: '' },
    });

    // Already signed in: this screen is a door, and a visitor standing inside the
    // building has no business looking at it. They are sent to the landing page, the
    // one screen a signed-in account and a stranger can both read.
    if (getAccessToken() !== null) {
        return <Navigate to="/" replace />;
    }

    async function onSubmit(values: LoginForm): Promise<void> {
        setServerError(null);

        const identifier = toIdentifier(values, kind);

        try {
            const result = await login(identifier);

            setPendingOtp({
                identifier,
                tujuan: result.data.otp.tujuan,
                kedaluwarsa_at: result.data.otp.kedaluwarsa_at,
                ttl_detik: result.data.otp.ttl_detik,
            });

            await navigate('/otp', { replace: true });
        } catch (error) {
            applyLoginFailure(error, setServerError, setError);
        }
    }

    const kirimUlang = (): void => {
        void handleSubmit(onSubmit)();
    };

    return (
        <AuthLayout
            title="Masuk"
            description="Masukkan nomor telepon atau email kamu."
            footer={
                /**
                 * Not a cross-link, because there is nothing to cross to: `/register`
                 * is gone and the phone path needs no separate form. What a visitor
                 * without an account actually needs to know is that their number is
                 * enough - the account is minted on the spot and finished once the code
                 * proves it. Email is left out of the promise deliberately, since an
                 * unknown address still has no delivery channel to send a code to.
                 */
                <>
                    Belum punya akun? Dengan nomor ponsel, akun baru dibuat otomatis
                    setelah kode verifikasi diterima.
                </>
            }
        >
            <form
                onSubmit={handleSubmit(onSubmit)}
                className="flex flex-col gap-4"
                noValidate
            >
                <FormErrorSummary error={serverError} />

                {/**
                 * The 401 is rendered as a banner rather than as a field error because the
                 * server sends it with an **empty** `errors` map: a wrong password is not
                 * attributable to the password field in a way the user can act on, and
                 * `AuthController` deliberately gives an unknown identifier and a wrong
                 * password the same body so the endpoint is not an account oracle.
                 */}
                {serverError instanceof ApiError && serverError.isUnauthorized ? (
                    <Alert variant="destructive" role="alert">
                        <KeyRound />
                        <AlertTitle>Gagal masuk</AlertTitle>
                        <AlertDescription>
                            <p>{serverError.message}</p>
                        </AlertDescription>
                    </Alert>
                ) : null}

                {serverError instanceof ApiError && serverError.isForbidden ? (
                    <Alert variant="destructive" role="alert">
                        <KeyRound />
                        <AlertTitle>Akun dinonaktifkan</AlertTitle>
                        <AlertDescription>
                            <p>{serverError.message}</p>
                        </AlertDescription>
                    </Alert>
                ) : null}

                {/**
                 * Everything the two branches above do not claim: a 500, a 429, a dropped
                 * connection. The values already typed stay in the form, and the retry
                 * re-submits them, which is what AC-9 asks for.
                 */}
                {serverError instanceof ApiError &&
                !serverError.isUnauthorized &&
                !serverError.isForbidden &&
                !serverError.isValidation ? (
                    <ErrorState error={serverError} onRetry={kirimUlang} />
                ) : null}

                <Field
                    label={kind === 'email' ? 'Email' : 'Nomor telepon'}
                    hint={
                        kind === 'email'
                            ? undefined
                            : 'Gunakan nomor yang aktif di WhatsApp/SMS. Format 08xx atau +628xx sama-sama diterima.'
                    }
                    errors={[
                        ...(errors.identifier?.message === undefined
                            ? []
                            : [errors.identifier.message]),
                        ...fieldErrorsOf(serverError, kind),
                    ]}
                    required
                >
                    <FieldInput
                        type={kind === 'email' ? 'email' : 'tel'}
                        inputMode={kind === 'email' ? 'email' : 'numeric'}
                        autoComplete="username"
                        placeholder={
                            kind === 'email' ? 'nama@contoh.id' : '08xx xxxx xxxx'
                        }
                        {...register('identifier')}
                    />
                </Field>

                <div
                    className="flex flex-wrap items-center gap-2"
                    role="group"
                    aria-label="Jenis identitas"
                >
                    <span className="text-muted-foreground text-xs">Gunakan</span>

                    <Button
                        type="button"
                        variant={kind === 'no_telepon' ? 'secondary' : 'ghost'}
                        size="sm"
                        className="min-h-11"
                        aria-pressed={kind === 'no_telepon'}
                        onClick={() => setKind('no_telepon')}
                    >
                        Telepon
                    </Button>

                    <Button
                        type="button"
                        variant={kind === 'email' ? 'secondary' : 'ghost'}
                        size="sm"
                        className="min-h-11"
                        aria-pressed={kind === 'email'}
                        onClick={() => setKind('email')}
                    >
                        Email
                    </Button>
                </div>

                <Button type="submit" className="min-h-11" disabled={isSubmitting}>
                    {isSubmitting ? <Spinner /> : null}

                    {isSubmitting ? 'Mengirim kode...' : 'Lanjutkan'}
                </Button>

                <p className="text-muted-foreground text-sm">
                    {kind === 'no_telepon'
                        ? PESAN_KEAMANAN_TELEPON
                        : PESAN_KEAMANAN_EMAIL}
                </p>
            </form>
        </AuthLayout>
    );
}

/**
 * Attach a 422 to the field the user actually filled in.
 *
 * The server names the key it received - `no_telepon` or `email` - and this form sends
 * exactly one of them, so the mapping is by identity rather than by position. A 401 has
 * an empty `errors` map and therefore contributes nothing here, which is correct: the
 * banner owns that case.
 */
function fieldErrorsOf(error: unknown, kind: IdentifierKind): string[] {
    if (!(error instanceof ApiError)) {
        return [];
    }

    return error.fieldErrors(kind);
}

type SetFieldError = (name: 'identifier', value: { message: string }) => void;

function applyLoginFailure(
    error: unknown,
    setServerError: (value: unknown) => void,
    setError: SetFieldError,
): void {
    setServerError(error);

    if (!(error instanceof ApiError) || !error.isValidation) {
        return;
    }

    /**
     * `requireAtLeastOneIdentifier()` writes the same message onto *both* keys, so a
     * client that sent neither is told about both. This form cannot send neither - the
     * field is required - but a `max:20` or `email` failure still lands on `identifier`,
     * and that one is worth putting on the control itself.
     */
    const identifierMessages = [
        ...error.fieldErrors('no_telepon'),
        ...error.fieldErrors('email'),
    ];

    if (identifierMessages.length > 0) {
        setError('identifier', { message: identifierMessages[0] ?? '' });
    }
}
