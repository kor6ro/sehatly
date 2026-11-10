import { useRef, useState } from 'react';
import { Link, useParams } from 'react-router';
import { CheckCircle2, Loader2, ShieldCheck } from 'lucide-react';
import { request, ApiError } from '@/lib/http';
import { resendOtp } from '@/lib/api/auth';
import { KODE_OTP_PANJANG } from '@/lib/otp';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { OtpInput } from '@/shared/components/auth/otp-field';
import { Field, FieldInput } from '@/shared/components/form/field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

const MIN_SANDI = 8;

/**
 * `/aktivasi-undangan/:token` on the admin and marketing portals.
 *
 * The invitation flow has three facts the screen has to carry:
 *
 * 1. The token is the URL, and it is the only thing that identifies the
 *    invitation. It is never rendered back into the page - a screenshot or a
 *    support ticket must not become a reusable credential.
 * 2. The phone number is NOT in the token. The backend deliberately resolves
 *    the token to a user and never publishes that user's number on an
 *    anonymous route, so the invitee re-states the number the admin invited.
 *    That is also what lets `resendOtp` mint the `verifikasi_telepon` code the
 *    activation endpoint demands.
 * 3. The account is still `diundang` until this succeeds. Activation consumes
 *    the token, hashes the chosen password, marks the phone verified, and moves
 *    the account to `aktif`.
 *
 * In `APP_ENV=local` the resend response carries the code (`OtpService`'s
 * dev-only rule), so the demo can complete without a WhatsApp/SMS gateway. In
 * every other environment the code really is delivered out of band and this
 * screen shows no code.
 */
export function UndanganAktivasiPage() {
    useDocumentTitle('Aktivasi Undangan');

    const { token = '' } = useParams<{ token: string }>();

    const [noTelepon, setNoTelepon] = useState('');
    const [otp, setOtp] = useState('');
    const [sandi, setSandi] = useState('');
    const [ulangi, setUlangi] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [pesanError, setPesanError] = useState<string | null>(null);
    const [pesanSukses, setPesanSukses] = useState<string | null>(null);
    const [kodeDemo, setKodeDemo] = useState<string | null>(null);
    const [mengirim, setMengirim] = useState(false);
    const [menyimpan, setMenyimpan] = useState(false);
    const [selesai, setSelesai] = useState(false);
    const otpRef = useRef<HTMLInputElement | null>(null);

    const sibuk = mengirim || menyimpan;

    const petakanGalat = (err: unknown) => {
        if (!(err instanceof ApiError)) {
            setPesanError('Terjadi kesalahan. Coba lagi.');
            return;
        }

        const field: Record<string, string> = {};
        for (const nama of ['no_telepon', 'otp', 'password']) {
            const pesan = err.fieldErrors(nama);
            if (pesan.length > 0) field[nama] = pesan[0];
        }

        if (Object.keys(field).length > 0) {
            setErrors(field);
            return;
        }

        setPesanError(err.message);
    };

    async function kirimKode() {
        setErrors({});
        setPesanError(null);
        setPesanSukses(null);
        setKodeDemo(null);

        const nomor = noTelepon.trim().replace(/\s+/g, '');
        if (nomor === '') {
            setErrors({ no_telepon: 'Nomor HP wajib diisi.' });
            return;
        }

        setMengirim(true);
        try {
            const hasil = await resendOtp({
                no_telepon: nomor,
                tujuan: 'verifikasi_telepon',
            });

            // `kode` is present only in `local` (see the class docblock). The
            // cast is the same boundary the staff login page uses; the type on
            // `OtpChallenge` deliberately omits it.
            const kode = (hasil.data.otp as { kode?: string | null }).kode ?? null;
            setKodeDemo(kode);
            setPesanSukses(hasil.message);
        } catch (err) {
            petakanGalat(err);
        } finally {
            setMengirim(false);
        }
    }

    async function aktivasi(e: React.FormEvent) {
        e.preventDefault();
        setErrors({});
        setPesanError(null);

        const lokal: Record<string, string> = {};
        if (noTelepon.trim() === '') lokal.no_telepon = 'Nomor HP wajib diisi.';
        if (otp.trim().length !== KODE_OTP_PANJANG) {
            lokal.otp = `Kode OTP harus ${KODE_OTP_PANJANG} digit.`;
        }
        if (sandi.length < MIN_SANDI) {
            lokal.password = `Sandi minimal ${MIN_SANDI} karakter.`;
        }
        if (sandi !== ulangi) {
            lokal.ulangi = 'Konfirmasi sandi tidak sama.';
        }

        if (Object.keys(lokal).length > 0) {
            setErrors(lokal);
            return;
        }

        setMenyimpan(true);
        try {
            await request(`auth/undangan/${encodeURIComponent(token)}/aktivasi`, {
                method: 'POST',
                json: { otp: otp.trim(), password: sandi },
                retry: 0,
            });
            setSelesai(true);
        } catch (err) {
            petakanGalat(err);
        } finally {
            setMenyimpan(false);
        }
    }

    if (token === '' || selesai) {
        return (
            <div className="flex min-h-screen flex-col items-center justify-center p-4">
                <div className="w-full max-w-sm space-y-4 text-center">
                    <div className="mx-auto grid size-16 place-items-center rounded-full bg-success-subtle text-success-subtle-foreground">
                        {selesai ? (
                            <CheckCircle2 className="size-8" aria-hidden />
                        ) : (
                            <ShieldCheck className="size-8" aria-hidden />
                        )}
                    </div>
                    <h1 className="text-xl font-semibold">
                        {selesai ? 'Akun berhasil diaktifkan' : 'Token undangan tidak valid'}
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        {selesai
                            ? 'Sandi sudah tersimpan. Kamu bisa masuk memakai nomor HP dan sandi yang baru dibuat.'
                            : 'Buka tautan aktivasi yang dikirim admin. Token di alamat ini tidak terbaca.'}
                    </p>
                    <Button asChild variant="outline" className="w-full">
                        <Link to="/login">Ke halaman masuk</Link>
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="flex min-h-screen flex-col items-center justify-center p-4">
            <div className="w-full max-w-md space-y-6 rounded-xl border bg-background p-8 shadow-sm">
                <div className="flex flex-col items-center gap-2 text-center">
                    <img src="/logo.svg" alt="Sehatly" className="size-10" />
                    <h1 className="text-2xl font-semibold">Aktivasi akun</h1>
                    <p className="text-muted-foreground text-sm">
                        Undangan ini terikat ke satu nomor HP. Masukkan nomor yang
                        diundang, minta kode OTP, lalu buat sandi.
                    </p>
                </div>

                {pesanError !== null && (
                    <Alert variant="destructive">
                        <AlertDescription>{pesanError}</AlertDescription>
                    </Alert>
                )}

                {pesanSukses !== null && (
                    <Alert>
                        <AlertDescription>
                            {pesanSukses}
                            {kodeDemo !== null && (
                                <span className="mt-1 block font-mono text-sm">
                                    Mode demo, kode OTP: <strong>{kodeDemo}</strong>
                                </span>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                <form onSubmit={(e) => void aktivasi(e)} className="space-y-4" noValidate>
                    <Field
                        label="Nomor HP"
                        required
                        hint="Nomor yang menerima undangan."
                        errors={errors.no_telepon ? [errors.no_telepon] : []}
                    >
                        <FieldInput
                            inputMode="tel"
                            placeholder="08xxxxxxxxxx"
                            autoComplete="tel"
                            value={noTelepon}
                            onChange={(e) => setNoTelepon(e.target.value)}
                            disabled={sibuk}
                        />
                    </Field>

                    <Button
                        type="button"
                        variant="outline"
                        className="w-full"
                        onClick={() => void kirimKode()}
                        disabled={sibuk}
                    >
                        {mengirim && <Loader2 className="animate-spin" />}
                        {mengirim ? 'Mengirim kode...' : 'Kirim kode OTP'}
                    </Button>

                    <Field
                        label="Kode OTP"
                        required
                        errors={errors.otp ? [errors.otp] : []}
                    >
                        <OtpInput
                            value={otp}
                            onChange={setOtp}
                            disabled={sibuk}
                            inputRef={otpRef}
                        />
                    </Field>

                    <Field
                        label="Sandi baru"
                        required
                        hint={`Minimal ${MIN_SANDI} karakter.`}
                        errors={errors.password ? [errors.password] : []}
                    >
                        <FieldInput
                            type="password"
                            placeholder="Buat sandi"
                            autoComplete="new-password"
                            value={sandi}
                            onChange={(e) => setSandi(e.target.value)}
                            disabled={sibuk}
                        />
                    </Field>

                    <Field
                        label="Ulangi sandi"
                        required
                        errors={errors.ulangi ? [errors.ulangi] : []}
                    >
                        <FieldInput
                            type="password"
                            placeholder="Ulangi sandi"
                            autoComplete="new-password"
                            value={ulangi}
                            onChange={(e) => setUlangi(e.target.value)}
                            disabled={sibuk}
                        />
                    </Field>

                    <Button type="submit" className="w-full" disabled={sibuk}>
                        {menyimpan && <Loader2 className="animate-spin" />}
                        {menyimpan ? 'Menyimpan...' : 'Aktifkan akun'}
                    </Button>
                </form>

                <p className="text-center text-sm text-muted-foreground">
                    Sudah aktif?{' '}
                    <Link to="/login" className="underline">
                        Masuk
                    </Link>
                </p>
            </div>
        </div>
    );
}
