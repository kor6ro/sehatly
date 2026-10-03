import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { AlertCircle, ChevronDown, Mail, ShieldCheck, Smartphone } from 'lucide-react';
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
 * The WhatsApp glyph, drawn inline because lucide ships no brand icons.
 *
 * The mark is not decoration: the point of a per-channel button is that the channel is
 * recognised before it is read, and this is Meta's trademark used nominatively - naming
 * the service the button calls - not a Sehatly mark. The words "Kirim Kode melalui
 * WhatsApp" are always rendered next to it, so the icon never carries the meaning alone.
 */
function WhatsAppIcon({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden
            className={className}
        >
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413" />
        </svg>
    );
}

/**
 * `/` - the sign-in dialog: one door for entering and for joining.
 *
 * ## Why this is a dialog and not a route
 *
 * The header's action is the whole reason a visitor is here, and a route costs a page
 * load to collect one phone number. The dialog also has to hold the whole exchange -
 * number, then channel, then code - because sending the visitor to a page between them
 * is the half-measure this replaces. The `/login` route still exists for a reload of
 * `/otp` (which has no other way to recover its identifier) and for anyone holding an
 * old link.
 *
 * ## Three states, and why "Lanjut" sends nothing
 *
 * `nomor` -> `kanal` -> `kode`.
 *
 * The first transition is local: it validates the digits, and it costs no request, so a
 * typo is answered before anything is sent. The second state shows that number beside
 * "Salah nomor? Ganti Nomor Ponsel" and the two channel buttons; typing over the number
 * at any point drops back to `nomor`, because a changed number has to pass "Lanjut"
 * again before the channel buttons come back.
 *
 * The request is made by the channel button, so nothing leaves the browser until the
 * visitor has seen the number they are about to use.
 *
 * ## The SMS button is real, and it is disabled
 *
 * Both channels are rendered, because the choice is the point of the step. The second is
 * `disabled` because this build ships no SMS sender: `config/otp.php` declares `sms` and
 * `email` only to have `PemilihPengirimOtp` refuse them loudly rather than quietly
 * deliver a WhatsApp. A button labelled SMS that sends WhatsApp is a worse thing to
 * ship than a button that cannot be pressed, so the caption under the pair says which
 * channel actually carries the code.
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

    const [langkah, setLangkah] = useState<'nomor' | 'kanal' | 'kode'>('nomor');
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

    /**
     * `nomor` -> `kanal`. Local only: no request, so a mistyped digit is caught before
     * anything is sent. What it commits is the *offer* - the channel buttons are an offer
     * for this one number - and typing over the number afterwards drops back to `nomor`,
     * so the offer has to be made again.
     */
    function onLanjut(event: React.FormEvent<HTMLFormElement>): void {
        event.preventDefault();

        if (langkah !== 'nomor' || sedangKirim) {
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
        setLangkah('kanal');
    }

    /**
     * `kanal` -> `kode`. The one request across the first two states, made by the
     * channel button rather than by "Lanjut".
     */
    async function onKirimKode(): Promise<void> {
        if (sedangKirim || langkah !== 'kanal') {
            return;
        }

        const nilai = nomor.replace(/[\s-]/g, '');
        setServerError(null);

        if (!NASIONAL.test(nilai)) {
            // Editing the field already drops back to `nomor`, so this is only reachable
            // if the value was somehow never valid - go back rather than send it.
            setLangkah('nomor');
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

                            {/**
                             * The lead phrase is emphasised the way the reference dialog
                             * emphasises it, so the sentence says what to type before it
                             * says what you get. It promises only what this build can
                             * keep: a number with no account is told so below, rather
                             * than being invited to "membuat akun baru" on the spot -
                             * that step needs the profile form, which is not built yet.
                             */}
                            <DialogDescription>
                                <strong>Masukkan nomor ponsel</strong> untuk masuk ke
                                Sehatly.
                            </DialogDescription>
                        </DialogHeader>

                        <form
                            onSubmit={onLanjut}
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
                                hideLabel
                                errors={galatNomor === null ? [] : [galatNomor]}
                                required
                            >
                                <div className="flex items-stretch gap-2">
                                    {/**
                                     * The prefix box: flag, `+62`, caret.
                                     *
                                     * The caret promises a country picker and Indonesia is
                                     * the only country this endpoint accepts, so it is
                                     * `aria-hidden` with no pointer - it reads as the
                                     * control it is not instead of doing nothing when
                                     * pressed. The flag is two bands of div rather than
                                     * `🇮🇩`, which Windows renders as the letters I and D.
                                     */}
                                    <span
                                        aria-hidden
                                        className="bg-muted flex h-10 shrink-0 items-center gap-1.5 rounded-md border px-2.5 text-sm"
                                    >
                                        <span className="flex h-3 w-4 shrink-0 flex-col overflow-hidden rounded-[1px] ring-1 ring-black/15">
                                            <span className="h-1/2 bg-[#ce1126]" />
                                            <span className="h-1/2 bg-white" />
                                        </span>

                                        <span className="tabular-nums">+62</span>

                                        <ChevronDown className="size-3.5 opacity-60" />
                                    </span>

                                    {/**
                                     * Editable in `kanal` too, and typing un-commits it.
                                     *
                                     * A `readOnly` field here would be the literal reading
                                     * of "committed", but Chrome selects the whole value
                                     * of a read-only input on focus - so the number
                                     * lands highlighted, next to the link that offers to
                                     * change it. Keeping it editable and dropping back to
                                     * `nomor` on any change does the same job (a new
                                     * number has to pass "Lanjut" before the channel
                                     * buttons come back) without either the highlight or
                                     * a field the visitor has to be told how to unlock.
                                     */}
                                    <FieldInput
                                        type="tel"
                                        inputMode="numeric"
                                        autoComplete="tel-national"
                                        placeholder="82116927632"
                                        className="h-10 flex-1 tabular-nums"
                                        value={nomor}
                                        onChange={(event) => {
                                            setNomor(event.target.value);

                                            if (langkah !== 'nomor') {
                                                setLangkah('nomor');
                                                setServerError(null);
                                            }
                                        }}
                                    />
                                </div>
                            </Field>

                            {langkah === 'nomor' ? (
                                <Button
                                    type="submit"
                                    className="min-h-11"
                                    disabled={sedangKirim}
                                >
                                    Lanjut
                                </Button>
                            ) : (
                                <>
                                    <p className="text-muted-foreground text-center text-sm">
                                        Salah nomor?{' '}

                                        <button
                                            type="button"
                                            onClick={gantiNomor}
                                            className="text-primary font-medium underline underline-offset-4"
                                        >
                                            Ganti Nomor Ponsel
                                        </button>
                                    </p>

                                    <div className="flex flex-col gap-3">
                                        <Button
                                            type="button"
                                            onClick={() => void onKirimKode()}
                                            className="min-h-11 gap-2"
                                            disabled={sedangKirim}
                                        >
                                            {sedangKirim ? (
                                                <Spinner />
                                            ) : (
                                                <WhatsAppIcon className="size-5" />
                                            )}

                                            {sedangKirim
                                                ? 'Mengirim kode...'
                                                : 'Kirim Kode melalui WhatsApp'}
                                        </Button>

                                        {/**
                                         * Renders, and presses nothing. See the note at the
                                         * top of the file: no SMS sender ships in this
                                         * build, and `PemilihPengirimOtp` refuses the
                                         * channel rather than quietly sending a WhatsApp.
                                         */}
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="border-primary/40 text-primary min-h-11 gap-2"
                                            disabled
                                        >
                                            <Mail aria-hidden className="size-5" />
                                            Kirim Kode melalui SMS
                                        </Button>
                                    </div>

                                    <p className="text-muted-foreground text-center text-xs leading-relaxed">
                                        Kanal SMS belum tersedia. Kode dikirim melalui
                                        WhatsApp.
                                    </p>
                                </>
                            )}

                            <p className="text-muted-foreground text-center text-xs leading-relaxed">
                                Dengan masuk atau mendaftar, saya menyetujui{' '}
                                <Link
                                    to="/syarat-ketentuan"
                                    onClick={tutup}
                                    className="text-primary font-medium underline underline-offset-4"
                                >
                                    Syarat Ketentuan
                                </Link>{' '}
                                dan{' '}
                                <Link
                                    to="/kebijakan-privasi"
                                    onClick={tutup}
                                    className="text-primary font-medium underline underline-offset-4"
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
