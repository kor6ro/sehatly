import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { AlertCircle, MessageCircle, ShieldCheck, Smartphone } from 'lucide-react';
import { login, resendOtp, verifyOtp } from '@/lib/api/auth';
import { ApiError, establishSession } from '@/lib/http';
import { getDeviceId } from '@/lib/token';
import { queryClient } from '@/lib/query-client';
import { dispatchFlash } from '@/lib/flash';
import {
    KODE_OTP_PANJANG,
    labelKirimUlangOtp,
    pesanHitungMundurOtp,
    pesanRalatKodeOtp,
    pesanSisaPercobaanOtp,
    pesanTerlaluBanyakOtp,
} from '@/lib/otp';
import { useCountdown } from '@/hooks/use-countdown';
import {
    clearPendingOtp,
    sebutanIdentifier,
    setPendingOtp,
    type PendingOtp,
} from '@/stores/pending-otp';
import { Field, FieldInput, FormErrorSummary } from '@/components/form/field';
import { OtpInput } from '@/components/auth/otp-field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * How long the resend button stays locked after a request, in seconds.
 *
 * Deliberately the same number as the OTP page's own constant rather than an import:
 * the two are two surfaces on one flow, and the countdown a user sees must not depend
 * on which surface sent the code. `web/src/lib/otp.ts` is where to move it if a third
 * surface appears.
 */
const COOLDOWN_KIRIM_ULANG = 60;

/**
 * The national-number rule, for a field that sits after a fixed `+62`.
 *
 * `8` then 7-12 digits: an Indonesian mobile number is `08xx`, and the prefix box has
 * already taken the `0`. Checking it here rather than by sending the request means a
 * typo is answered without a round trip, and it is what makes `0${nilai}` below a valid
 * canonical local number by construction.
 */
const NASIONAL = /^8\d{7,11}$/;

/**
 * `/` - the sign-in dialog: one door for entering and for joining.
 *
 * ## Why this is a dialog and not a route
 *
 * The header's action is the whole reason a visitor is here, and a route costs a page
 * load to collect one phone number. The dialog also has to hold BOTH steps - number,
 * then code - because sending the visitor to a page between them is the half-measure
 * this replaces. The `/login` route still exists for a reload of `/otp` (which has no
 * other way to recover its identifier) and for anyone holding an old link.
 *
 * ## The 401 on the first step means one thing only
 *
 * `AuthController::login` answers 401 for an unknown identifier, and this screen never
 * sends a password - {@link LoginInput}'s field is optional and omitted here - so a 401
 * cannot mean "wrong password". It means the number has no account, and the alert says
 * so and offers the one action that fixes it. A 403 (a suspended account) keeps the
 * server's own wording.
 */
export function LoginDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const navigate = useNavigate();

    const [langkah, setLangkah] = useState<'nomor' | 'kode'>('nomor');
    const [nomor, setNomor] = useState('');
    const [galatNomor, setGalatNomor] = useState<string | null>(null);
    const [sedangKirim, setSedangKirim] = useState(false);
    const [serverError, setServerError] = useState<unknown>(null);

    const [pending, setPending] = useState<PendingOtp | null>(null);
    const [kode, setKode] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [resending, setResending] = useState(false);
    const [resendError, setResendError] = useState<unknown>(null);
    const [resendNotice, setResendNotice] = useState<string | null>(null);
    const [resendCooldown, setResendCooldown] = useState(0);
    const [upayaFokus, setUpayaFokus] = useState(0);

    const verifyLock = useRef(false);
    const resendLock = useRef(false);
    const inputRef = useRef<HTMLInputElement | null>(null);

    /**
     * The deadline is only real once a code exists. Passing `ttl_detik: 0` before that
     * gives a deadline already in the past, so the counter reads 0 and every subsequent
     * `setRemaining(0)` is a no-op React bails out of - the dialog does not re-render
     * once a second while the visitor is still typing a phone number.
     */
    const remaining = useCountdown(
        pending?.kedaluwarsa_at ?? null,
        pending?.ttl_detik ?? 0,
    );

    const cooldownAktif = resendCooldown > 0;

    useEffect(() => {
        if (!cooldownAktif) {
            return;
        }

        const timer = globalThis.setInterval(() => {
            setResendCooldown((sisa) => Math.max(0, sisa - 1));
        }, 1000);

        return () => {
            globalThis.clearInterval(timer);
        };
    }, [cooldownAktif]);

    useEffect(() => {
        if (upayaFokus === 0 || submitting) {
            return;
        }

        inputRef.current?.focus({ preventScroll: true });
    }, [upayaFokus, submitting]);

    useEffect(() => {
        if (langkah !== 'kode') {
            return;
        }

        const timer = globalThis.setTimeout(() => {
            inputRef.current?.focus({ preventScroll: true });
        }, 0);

        return () => {
            globalThis.clearTimeout(timer);
        };
    }, [langkah]);

    /**
     * Reopening starts at the number again, so a visitor who dismissed the dialog in the
     * middle of a code does not come back to a stale challenge whose deadline has since
     * run out. The number typed is kept: it is what they were in the middle of doing.
     */
    useEffect(() => {
        if (open) {
            return;
        }

        setLangkah('nomor');
        setPending(null);
        setKode('');
        setServerError(null);
        setResendError(null);
        setResendNotice(null);
        setResendCooldown(0);
        setGalatNomor(null);
    }, [open]);

    async function onKirimKode(event: React.FormEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();

        if (sedangKirim) {
            return;
        }

        const nilai = nomor.replace(/[\s-]/g, '');
        setServerError(null);

        if (!NASIONAL.test(nilai)) {
            setGalatNomor(
                nilai.startsWith('8')
                    ? 'Gunakan 8 sampai 12 digit setelah +62, contoh 8211 6927 632.'
                    : 'Nomor ponsel Indonesia diawali 8 setelah +62, contoh 8211 6927 632.',
            );
            return;
        }

        setGalatNomor(null);
        setSedangKirim(true);

        /**
         * The canonical local spelling. `+628211…` and `08211…` are one number, and the
         * server folds both to this; sending it directly is what keeps the masked display
         * on the next step (`nomor 0812****88`) identical to the row in `users`.
         */
        const kanonik = `0${nilai}`;

        try {
            const result = await login({ no_telepon: kanonik });

            const challenge: PendingOtp = {
                identifier: { no_telepon: kanonik },
                tujuan: result.data.otp.tujuan,
                kedaluwarsa_at: result.data.otp.kedaluwarsa_at,
                ttl_detik: result.data.otp.ttl_detik,
            };

            setPending(challenge);
            setPendingOtp(challenge);
            setKode('');
            setLangkah('kode');
        } catch (error) {
            setServerError(error);
        } finally {
            setSedangKirim(false);
        }
    }

    async function onVerifikasi(): Promise<void> {
        if (pending === null || kode.length !== KODE_OTP_PANJANG) {
            return;
        }

        if (verifyLock.current) {
            return;
        }

        verifyLock.current = true;
        setServerError(null);
        setResendNotice(null);
        setSubmitting(true);

        const deviceId = getDeviceId();

        try {
            const result = await verifyOtp({
                ...pending.identifier,
                kode,
                tujuan: pending.tujuan,
                ...(deviceId === null ? {} : { device_id: deviceId }),
            });

            establishSession(result.data.token);

            // After the pair is stored and before the first navigation, so the dashboard's
            // `/me` is a fresh read and no query made under a previous identity survives.
            queryClient.clear();

            clearPendingOtp();

            dispatchFlash({ level: 'success', message: 'Selamat datang kembali.' });

            onOpenChange(false);

            await navigate('/dashboard', { replace: true });
        } catch (error) {
            setServerError(error);

            // A rejected code keeps what was typed and puts focus back on slot 1.
            if (error instanceof ApiError && error.fieldErrors('kode').length > 0) {
                setUpayaFokus((nilai) => nilai + 1);
            }
        } finally {
            verifyLock.current = false;
            setSubmitting(false);
        }
    }

    async function onKirimUlang(): Promise<void> {
        if (pending === null || resending || resendLock.current || cooldownAktif) {
            return;
        }

        resendLock.current = true;
        setResending(true);
        setResendError(null);
        setResendNotice(null);
        setServerError(null);
        // Locked before the request resolves, so a second click cannot slip through.
        setResendCooldown(COOLDOWN_KIRIM_ULANG);

        const deviceId = getDeviceId();

        try {
            const result = await resendOtp({
                ...pending.identifier,
                tujuan: pending.tujuan,
                ...(deviceId === null ? {} : { device_id: deviceId }),
            });

            // The server closed the previous code, so the new deadline replaces it and
            // the code typed for the old one is dropped. One timer.
            const diperbarui: PendingOtp = {
                ...pending,
                kedaluwarsa_at: result.data.otp.kedaluwarsa_at,
                ttl_detik: result.data.otp.ttl_detik,
            };

            setPending(diperbarui);
            setPendingOtp(diperbarui);
            setKode('');
            setUpayaFokus((nilai) => nilai + 1);
            setResendNotice(
                `Kode baru telah dikirim ke ${sebutanIdentifier(pending.identifier)}.`,
            );
        } catch (error) {
            setResendError(error);

            const retryAfter =
                error instanceof ApiError && error.status === 429
                    ? error.retryAfter
                    : null;

            setResendCooldown(retryAfter ?? 0);
        } finally {
            resendLock.current = false;
            setResending(false);
        }
    }

    function gantiNomor(): void {
        setLangkah('nomor');
        setPending(null);
        setKode('');
        setServerError(null);
        setResendNotice(null);
        setResendCooldown(0);
    }

    const tutup = (): void => {
        onOpenChange(false);
    };

    const belumTerdaftar =
        serverError instanceof ApiError && serverError.isUnauthorized;
    const terlaluBanyak = serverError instanceof ApiError && serverError.status === 429;
    const gangguan =
        serverError instanceof ApiError &&
        !serverError.isValidation &&
        !serverError.isUnauthorized &&
        !terlaluBanyak;

    const kodeErrors = (
        serverError instanceof ApiError ? serverError.fieldErrors('kode') : []
    ).map(pesanRalatKodeOtp);

    const sisaPercobaan =
        serverError instanceof ApiError ? serverError.sisaPercobaan : null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="gap-5 px-6 pt-12 sm:max-w-md">
                {/**
                 * The badge over the top edge, the way the reference front page wears its
                 * mark. It is decorative: the dialog already announces its own title, so
                 * the image is `alt=""` and hidden from assistive technology.
                 */}
                <div
                    aria-hidden
                    className="border-border bg-background absolute -top-7 left-1/2 flex size-14 -translate-x-1/2 items-center justify-center rounded-full border shadow-sm"
                >
                    <img src="/logo.svg" alt="" className="size-8" />
                </div>

                {langkah === 'kode' && pending !== null ? (
                    <>
                        <DialogHeader className="items-center text-center">
                            <DialogTitle>Masukkan Kode Verifikasi</DialogTitle>

                            <DialogDescription>
                                Kode {KODE_OTP_PANJANG} digit dikirim ke{' '}
                                {sebutanIdentifier(pending.identifier)}.
                            </DialogDescription>
                        </DialogHeader>

                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                void onVerifikasi();
                            }}
                            className="flex flex-col gap-4"
                            noValidate
                        >
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

                            {resendError !== null ? (
                                <Alert variant="destructive" role="alert">
                                    <ShieldCheck />
                                    <AlertTitle>Gagal mengirim ulang kode</AlertTitle>
                                    <AlertDescription>
                                        <p>
                                            {resendError instanceof ApiError
                                                ? pesanTerlaluBanyakOtp(
                                                      resendError.message,
                                                      resendError.status === 429
                                                          ? resendError.retryAfter
                                                          : null,
                                                  )
                                                : 'Terjadi kesalahan yang tidak diketahui.'}
                                        </p>
                                    </AlertDescription>
                                </Alert>
                            ) : null}

                            {resendNotice === null ? null : (
                                <p
                                    role="status"
                                    className="text-muted-foreground text-sm"
                                >
                                    {resendNotice}
                                </p>
                            )}

                            {/**
                             * `errors.kode` carries every rejection reason - expired, already
                             * used, wrong purpose - and the message is what tells "request a
                             * new code" apart from "start over", so it lands on the control.
                             */}
                            <Field label="Kode OTP" errors={kodeErrors} required>
                                <OtpInput
                                    value={kode}
                                    onChange={setKode}
                                    disabled={submitting}
                                    inputRef={inputRef}
                                />
                            </Field>

                            {sisaPercobaan === null ? null : (
                                <div
                                    role="status"
                                    className="border-warning/40 bg-warning/10 flex items-start gap-2 rounded-lg border p-3 text-sm"
                                >
                                    <AlertCircle
                                        aria-hidden
                                        className="mt-0.5 size-4 shrink-0"
                                    />

                                    <p>{pesanSisaPercobaanOtp(sisaPercobaan)}</p>
                                </div>
                            )}

                            <Button
                                type="submit"
                                className="min-h-11"
                                disabled={
                                    submitting || kode.length !== KODE_OTP_PANJANG
                                }
                            >
                                {submitting ? <Spinner /> : null}

                                {submitting ? 'Memeriksa...' : 'Verifikasi'}
                            </Button>

                            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <button
                                    type="button"
                                    onClick={() => void onKirimUlang()}
                                    disabled={
                                        resending || cooldownAktif
                                    }
                                    className="text-primary underline underline-offset-4 disabled:text-muted-foreground disabled:no-underline"
                                >
                                    {labelKirimUlangOtp(resendCooldown, resending)}
                                </button>

                                <button
                                    type="button"
                                    onClick={gantiNomor}
                                    className="text-muted-foreground underline underline-offset-4"
                                >
                                    Salah nomor? Ganti Nomor Ponsel
                                </button>
                            </div>

                            <p className="text-muted-foreground text-center text-sm tabular-nums">
                                {remaining > 0
                                    ? pesanHitungMundurOtp(remaining)
                                    : 'Kode sudah kedaluwarsa. Kirim ulang untuk kode baru.'}
                            </p>
                        </form>
                    </>
                ) : (
                    <>
                        <DialogHeader className="items-center text-center">
                            <DialogTitle>Masukkan Nomor Ponsel</DialogTitle>

                            <DialogDescription>
                                Masukkan nomor ponsel untuk masuk ke Sehatly. Kami akan
                                mengirim kode verifikasi ke nomor ini.
                            </DialogDescription>
                        </DialogHeader>

                        <form
                            onSubmit={(event) => void onKirimKode(event)}
                            className="flex flex-col gap-4"
                            noValidate
                        >
                            {/**
                             * A 401 here cannot be a wrong password - this form sends none -
                             * so it is reported as what it is rather than as the server's
                             * deliberately vague wording.
                             */}
                            {belumTerdaftar ? (
                                <Alert variant="destructive" role="alert">
                                    <Smartphone />
                                    <AlertTitle>Nomor belum terdaftar</AlertTitle>
                                    <AlertDescription>
                                        <p>
                                            Nomor ini belum punya akun Sehatly. Daftar
                                            dulu untuk membuat akun dengan nomor ini.
                                        </p>
                                    </AlertDescription>
                                </Alert>
                            ) : serverError instanceof ApiError &&
                              !serverError.isValidation ? (
                                <Alert variant="destructive" role="alert">
                                    <Smartphone />
                                    <AlertTitle>Gagal mengirim kode</AlertTitle>
                                    <AlertDescription>
                                        <p>{serverError.message}</p>
                                    </AlertDescription>
                                </Alert>
                            ) : (
                                <FormErrorSummary error={serverError} />
                            )}

                            <Field
                                label="Nomor ponsel"
                                hint="Nomor aktif yang bisa menerima pesan."
                                errors={galatNomor === null ? [] : [galatNomor]}
                                required
                            >
                                <div className="flex items-stretch gap-2">
                                    <span
                                        aria-hidden
                                        className="bg-muted flex h-10 shrink-0 items-center rounded-md border px-3 text-sm tabular-nums"
                                    >
                                        +62
                                    </span>

                                    <FieldInput
                                        type="tel"
                                        inputMode="numeric"
                                        autoComplete="tel-national"
                                        placeholder="8211 6927 632"
                                        className="h-10 flex-1"
                                        value={nomor}
                                        onChange={(event) =>
                                            setNomor(event.target.value)
                                        }
                                    />
                                </div>
                            </Field>

                            <Button
                                type="submit"
                                className="min-h-11"
                                disabled={sedangKirim}
                            >
                                {sedangKirim ? (
                                    <Spinner />
                                ) : (
                                    <MessageCircle aria-hidden className="size-4" />
                                )}

                                {sedangKirim
                                    ? 'Mengirim kode...'
                                    : 'Kirim Kode'}
                            </Button>

                            <p className="text-muted-foreground text-center text-xs leading-relaxed">
                                Dengan masuk, saya menyetujui{' '}
                                <Link
                                    to="/syarat-ketentuan"
                                    onClick={tutup}
                                    className="text-primary underline underline-offset-4 hover:underline"
                                >
                                    Syarat Ketentuan
                                </Link>{' '}
                                dan{' '}
                                <Link
                                    to="/kebijakan-privasi"
                                    onClick={tutup}
                                    className="text-primary underline underline-offset-4 hover:underline"
                                >
                                    Kebijakan Privasi
                                </Link>{' '}
                                Sehatly.
                            </p>
                        </form>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
