import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router';
import { AlertCircle, Info, ShieldCheck } from 'lucide-react';
import { verifyOtp } from '@/lib/api/auth';
import { ApiError, establishSession } from '@/lib/http';
import { getDeviceId } from '@/lib/token';
import { queryClient } from '@/lib/query-client';
import { dispatchFlash } from '@/lib/flash';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { useOnlineStatus } from '@/hooks/use-online-status';
import {
    KODE_OTP_PANJANG,
    pesanHitungMundurOtp,
    pesanRalatKodeOtp,
    pesanTerlaluBanyakOtp,
} from '@/lib/otp';
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
import { OfflineBanner } from '@/components/offline-banner';
import { AuthLayout } from '@/pages/auth-layout';

/**
 * The six boxes, wired to their `Field`.
 *
 * `InputOTP` renders an `<input>` with `inputMode="numeric"` and the `data-input-otp`
 * attribute, so the `Field` plumbing is applied to it by hand - `useFieldControl` is
 * exported for exactly this, and the kit's `InputOTP` cannot be modified (relocated
 * registry code). Slots are 44 px and 8 px apart so each one is a full touch target; the
 * whole row is one logical field with `autocomplete="one-time-code"`.
 */
function OtpInput({
    value,
    onChange,
    disabled,
    inputRef,
}: {
    value: string;
    onChange: (value: string) => void;
    disabled: boolean;
    inputRef: React.RefObject<HTMLInputElement | null>;
}) {
    const control = useFieldControl();

    return (
        <InputOTP
            id={control.id}
            ref={inputRef}
            maxLength={KODE_OTP_PANJANG}
            value={value}
            onChange={onChange}
            disabled={disabled}
            autoComplete="one-time-code"
            inputMode="numeric"
            aria-invalid={control.invalid || undefined}
            aria-describedby={control.describedBy}
            containerClassName="justify-start"
        >
            <InputOTPGroup className="gap-2 tabular-nums">
                {Array.from({ length: KODE_OTP_PANJANG }, (_unused, index) => (
                    <InputOTPSlot
                        key={index}
                        index={index}
                        className="h-11 w-11 rounded-md border text-base"
                    />
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
 *
 * ## Resend is disabled and says so
 *
 * `POST /auth/otp/resend` does not exist (F01 §4.4, `[TERBLOKIR backend]`). The button is
 * rendered with the pattern's cooldown label but is honestly unavailable, with a visible
 * reason, instead of calling a route that would 404. The recovery path is `Kembali`.
 */
export function OtpPage() {
    useDocumentTitle('Kode OTP | Sehatly');

    const navigate = useNavigate();
    const online = useOnlineStatus();
    const inputRef = useRef<HTMLInputElement>(null);
    const offlineHintId = useId();
    const resendHintId = useId();
    const pending = useMemo(() => getPendingOtp(), []);
    const [kode, setKode] = useState('');
    const [serverError, setServerError] = useState<unknown>(null);
    const [submitting, setSubmitting] = useState(false);
    const [upayaFokus, setUpayaFokus] = useState(0);

    const remaining = useCountdown(
        pending?.kedaluwarsa_at ?? null,
        pending?.ttl_detik ?? 300,
    );

    const kedaluwarsa = remaining === 0;

    /**
     * AC-3: focus enters slot 1 when the screen opens, and `preventScroll` keeps a small
     * viewport from jumping past the heading to the input.
     */
    useEffect(() => {
        if (pending === null) {
            return;
        }

        inputRef.current?.focus({ preventScroll: true });
    }, [pending]);

    /**
     * AC-5: focus returns to slot 1 after a rejected code. It is an effect rather than a
     * call in the catch block because the input is still `disabled` while the request is
     * in flight; focusing into a disabled control is silently dropped. The counter makes
     * two identical rejections two focus attempts.
     */
    useEffect(() => {
        if (upayaFokus === 0 || submitting) {
            return;
        }

        inputRef.current?.focus({ preventScroll: true });
    }, [upayaFokus, submitting]);

    if (pending === null) {
        return <OtpContextMissing />;
    }

    const context = pending;

    const kodeErrors = (
        serverError instanceof ApiError ? serverError.fieldErrors('kode') : []
    ).map(pesanRalatKodeOtp);
    const identifierErrors =
        serverError instanceof ApiError
            ? [
                  ...serverError.fieldErrors('no_telepon'),
                  ...serverError.fieldErrors('email'),
              ]
            : [];

    const terlaluBanyak = serverError instanceof ApiError && serverError.status === 429;
    const gangguan =
        serverError instanceof ApiError &&
        !serverError.isValidation &&
        !terlaluBanyak &&
        identifierErrors.length === 0;

    const fokusSlotPertama = (): void => {
        setUpayaFokus((nilai) => nilai + 1);
    };

    async function onSubmit(event: React.FormEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();

        // The offline guard, the in-flight guard and the length guard all live here as
        // well as on the button, so a programmatic submit cannot produce a request the
        // disabled button promises will not happen.
        if (!online || submitting || kode.length !== KODE_OTP_PANJANG) {
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

            /**
             * AC-5: a rejected code keeps what was typed and puts focus back on slot 1,
             * and no toast is dispatched anywhere on this path - the message is inline.
             */
            if (error instanceof ApiError && error.fieldErrors('kode').length > 0) {
                fokusSlotPertama();
            }
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <AuthLayout
            title="Kode OTP"
            description={`Masukkan ${KODE_OTP_PANJANG} digit kode yang dikirim ke ${describeIdentifier(context.identifier)}.`}
        >
            <OfflineBanner />

            <Alert role="status" data-slot="otp-share-warning">
                <Info />
                <AlertTitle>Rahasiakan kode ini</AlertTitle>
                <AlertDescription>
                    <p>
                        Jangan bagikan kode ini kepada siapa pun, termasuk yang mengaku
                        dari Sehatly.
                    </p>
                </AlertDescription>
            </Alert>

            <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
                {terlaluBanyak ? (
                    <Alert variant="destructive" role="alert">
                        <ShieldCheck />
                        <AlertDescription>
                            <p>
                                {pesanTerlaluBanyakOtp(
                                    (serverError as ApiError).message,
                                    (serverError as ApiError).retryAfter,
                                )}
                            </p>
                        </AlertDescription>
                    </Alert>
                ) : null}

                {gangguan ? (
                    <Alert variant="destructive" role="alert">
                        <ShieldCheck />
                        <AlertTitle>Gagal memverifikasi</AlertTitle>
                        <AlertDescription>
                            <p>{(serverError as ApiError).message}</p>
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
                    <OtpInput
                        value={kode}
                        onChange={setKode}
                        disabled={submitting}
                        inputRef={inputRef}
                    />
                </Field>

                {kedaluwarsa ? (
                    <div
                        role="status"
                        aria-live="polite"
                        data-slot="otp-countdown"
                        className="border-warning/40 bg-warning/10 flex items-start gap-2 rounded-lg border p-3 text-sm"
                    >
                        <AlertCircle aria-hidden className="mt-0.5 size-4 shrink-0" />
                        <p>Kode sudah kedaluwarsa.</p>
                    </div>
                ) : (
                    <p
                        role="timer"
                        aria-live="off"
                        data-slot="otp-countdown"
                        className="text-muted-foreground text-sm tabular-nums"
                    >
                        {pesanHitungMundurOtp(remaining)}
                    </p>
                )}

                {!online ? (
                    <p id={offlineHintId} className="text-muted-foreground text-sm">
                        Anda sedang luring. Verifikasi dan kirim ulang tidak tersedia
                        sampai koneksi kembali.
                    </p>
                ) : null}

                <Button
                    type="submit"
                    className="min-h-11"
                    disabled={submitting || kode.length !== KODE_OTP_PANJANG || !online}
                    aria-disabled={
                        submitting || kode.length !== KODE_OTP_PANJANG || !online
                            ? true
                            : undefined
                    }
                    aria-describedby={online ? undefined : offlineHintId}
                    data-slot="otp-verify"
                >
                    {submitting ? <Spinner /> : null}

                    {submitting ? 'Memverifikasi...' : 'Verifikasi'}
                </Button>

                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    disabled
                    aria-disabled="true"
                    aria-describedby={online ? resendHintId : `${resendHintId} ${offlineHintId}`}
                    data-slot="otp-resend"
                >
                    Kirim ulang kode (60 dtk)
                </Button>

                <p id={resendHintId} className="text-muted-foreground text-xs">
                    Kirim ulang kode belum tersedia. Gunakan tombol Kembali untuk meminta
                    kode baru.
                </p>

                <Button
                    type="button"
                    variant="ghost"
                    className="min-h-11"
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
                        className="min-h-11"
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
