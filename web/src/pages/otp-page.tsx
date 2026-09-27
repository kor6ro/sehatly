import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { ShieldCheck } from 'lucide-react';
import { verifyOtp } from '@/lib/api/auth';
import { ApiError, establishSession } from '@/lib/http';
import { getDeviceId } from '@/lib/token';
import { queryClient } from '@/lib/query-client';
import { dispatchFlash } from '@/lib/flash';
import {
    clearPendingOtp,
    describeIdentifier,
    getPendingOtp,
} from '@/stores/pending-otp';
import { Field, useFieldControl } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { AuthLayout } from '@/pages/auth-layout';

/**
 * `OtpService::KODE_DIGIT`. Six, zero-padded, and always transported as a **string**: a
 * code may begin with `0`, and a client that parsed it as a number would submit five
 * digits that can never match.
 */
const KODE_DIGIT = 6;

/**
 * The six boxes, wired to their `Field`.
 *
 * `InputOTP` renders an `<input>` with `inputMode="numeric"`, so the `Field` plumbing is
 * applied to it by hand - `useFieldControl` is exported for exactly this, and the kit's
 * `InputOTP` cannot be modified (relocated registry code).
 */
function OtpInput({
    value,
    onChange,
    disabled,
}: {
    value: string;
    onChange: (value: string) => void;
    disabled: boolean;
}) {
    const control = useFieldControl();

    return (
        <InputOTP
            id={control.id}
            maxLength={KODE_DIGIT}
            value={value}
            onChange={onChange}
            disabled={disabled}
            autoComplete="one-time-code"
            inputMode="numeric"
            aria-invalid={control.invalid || undefined}
            aria-describedby={control.describedBy}
            containerClassName="justify-start"
        >
            <InputOTPGroup>
                {Array.from({ length: KODE_DIGIT }, (_unused, index) => (
                    <InputOTPSlot key={index} index={index} />
                ))}
            </InputOTPGroup>
        </InputOTP>
    );
}

/**
 * Seconds until the code expires, counted down from the server's own
 * `kedaluwarsa_at`.
 *
 * The countdown is a **read** of the deadline, not a decision: the submit button is not
 * disabled on zero. A browser clock is not a trusted clock, and refusing to send a request
 * on the strength of a local countdown would reject a code the server would still accept -
 * which is the worse of the two failures.
 */
function useCountdown(kedaluwarsaAt: string | null, ttlDetik: number): number {
    const fallback = useMemo(
        () => new Date(Date.now() + ttlDetik * 1000),
        [ttlDetik],
    );

    const deadline = useMemo(
        () => (kedaluwarsaAt === null ? fallback : new Date(kedaluwarsaAt)),
        [fallback, kedaluwarsaAt],
    );

    const [remaining, setRemaining] = useState(() =>
        Math.max(0, Math.ceil((deadline.getTime() - Date.now()) / 1000)),
    );

    useEffect(() => {
        if (Number.isNaN(deadline.getTime())) {
            return;
        }

        const tick = (): void => {
            setRemaining(
                Math.max(0, Math.ceil((deadline.getTime() - Date.now()) / 1000)),
            );
        };

        tick();

        const timer = globalThis.setInterval(tick, 1000);

        return () => {
            globalThis.clearInterval(timer);
        };
    }, [deadline]);

    return remaining;
}

/**
 * `/otp` - the second step of both auth flows, and the only place a token is issued.
 *
 * ## The code is never read out of the response
 *
 * `AuthController` publishes `otp.kode` through `OtpService::plainTextForClient()`, which
 * returns the plaintext **only** when `app()->environment('local')`. Rendering it, or
 * auto-filling it, would be a development affordance shipping to real users, so
 * `OtpChallenge` in `lib/api/types.ts` does not declare the field at all: reading it is a
 * compile error rather than a review comment. This screen therefore only ever shows
 * `kedaluwarsa_at` and `ttl_detik`, and the code arrives by whatever channel the real
 * deployment uses.
 *
 * ## Why the context is read from storage rather than router state
 *
 * `VerifyOtpRequest` identifies the account by phone or email and has no other handle on
 * it, and a reload of this screen must still be able to verify - so the identifier comes
 * from `stores/pending-otp.ts`, not from `location.state`.
 */
export function OtpPage() {
    const navigate = useNavigate();
    const pending = useMemo(() => getPendingOtp(), []);
    const [kode, setKode] = useState('');
    const [serverError, setServerError] = useState<unknown>(null);
    const [submitting, setSubmitting] = useState(false);

    const remaining = useCountdown(pending?.kedaluwarsa_at ?? null, pending?.ttl_detik ?? 300);

    if (pending === null) {
        return <OtpContextMissing />;
    }

    // Bound to a local after the guard so the closures below see a non-nullable value.
    const context = pending;

    const kodeErrors =
        serverError instanceof ApiError ? serverError.fieldErrors('kode') : [];
    const identifierErrors =
        serverError instanceof ApiError
            ? [
                  ...serverError.fieldErrors('no_telepon'),
                  ...serverError.fieldErrors('email'),
              ]
            : [];

    async function onSubmit(event: React.FormEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();

        if (kode.length !== KODE_DIGIT) {
            return;
        }

        setServerError(null);

        setSubmitting(true);

        const deviceId = getDeviceId();

        try {
            const result = await verifyOtp({
                ...context.identifier,
                kode,
                tujuan: context.tujuan,
                ...(deviceId === null ? {} : { device_id: deviceId }),
            });

            establishSession(result.data.token);

            /**
             * The cache is cleared *after* the pair is stored and *before* the first
             * navigation, so the dashboard's `/me` is a fresh read and no query made
             * under a previous identity can be visible under this one.
             */
            queryClient.clear();

            clearPendingOtp();

            dispatchFlash({
                level: 'success',
                message:
                    context.tujuan === 'login'
                        ? 'Selamat datang kembali.'
                        : 'Verifikasi berhasil. Akun aktif.',
            });

            await navigate('/dashboard', { replace: true });
        } catch (error) {
            setServerError(error);
            setKode('');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <AuthLayout
            title="Kode OTP"
            description={`Masukkan ${KODE_DIGIT} digit kode yang dikirim ke ${describeIdentifier(context.identifier)}.`}
        >
            <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
                {serverError instanceof ApiError && !serverError.isValidation ? (
                    <Alert variant="destructive" role="alert">
                        <ShieldCheck />
                        <AlertTitle>Gagal memverifikasi</AlertTitle>
                        <AlertDescription>
                            <p>{serverError.message}</p>
                        </AlertDescription>
                    </Alert>
                ) : null}

                {identifierErrors.length > 0 ? (
                    <Alert variant="destructive" role="alert">
                        <ShieldCheck />
                        <AlertTitle>Akun tidak ditemukan</AlertTitle>
                        <AlertDescription>
                            {identifierErrors.map((message) => (
                                <p key={message}>{message}</p>
                            ))}
                        </AlertDescription>
                    </Alert>
                ) : null}

                {/**
                 * `errors.kode` is the field the server puts every rejection reason on -
                 * expired, already used, wrong purpose - and the message is what tells
                 * "request a new code" apart from "start over". It is therefore rendered
                 * on the control, not collapsed into a single banner.
                 */}
                <Field label="Kode OTP" errors={kodeErrors} required>
                    <OtpInput value={kode} onChange={setKode} disabled={submitting} />
                </Field>

                <p
                    className="text-muted-foreground text-xs"
                    data-slot="otp-countdown"
                >
                    {remaining > 0
                        ? `Kode berlaku ${Math.floor(remaining / 60)} menit ${remaining % 60} detik lagi.`
                        : 'Kode mungkin sudah kedaluwarsa. Kirim ulang dengan masuk kembali.'}
                </p>

                <Button
                    type="submit"
                    disabled={submitting || kode.length !== KODE_DIGIT}
                >
                    {submitting ? <Spinner /> : null}

                    {submitting ? 'Memverifikasi...' : 'Verifikasi'}
                </Button>

                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => {
                        clearPendingOtp();

                        void navigate('/login', { replace: true });
                    }}
                >
                    Kembali
                </Button>
            </form>
        </AuthLayout>
    );
}

/**
 * `/otp` reached without a pending attempt: a reload after the context was consumed, a
 * direct visit, or a hand-typed URL.
 *
 * There is no way to recover the identifier from the URL - `VerifyOtpRequest` needs it
 * and it is deliberately not a query parameter - so the honest answer is to send the
 * visitor back to the step that knows it.
 */
function OtpContextMissing() {
    const navigate = useNavigate();

    return (
        <AuthLayout
            title="Kode OTP"
            description="Tidak ada percobaan masuk atau pendaftaran yang sedang berjalan."
        >
            <Alert variant="destructive">
                <ShieldCheck />

                <AlertTitle>Sesi verifikasi tidak ditemukan</AlertTitle>

                <AlertDescription>
                    <p>
                        Halaman ini hanya berlaku setelah Anda mengirim nomor telepon
                        atau email. Kode OTP yang sudah terverifikasi tidak dapat diulang.
                    </p>

                    <Button
                        type="button"
                        size="sm"
                        onClick={() => {
                            void navigate('/login', { replace: true });
                        }}
                    >
                        Kembali ke halaman masuk
                    </Button>
                </AlertDescription>
            </Alert>
        </AuthLayout>
    );
}
