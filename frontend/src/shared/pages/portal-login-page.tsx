import { useState } from 'react';
import { Navigate, useNavigate } from 'react-router';
import { useMutation } from '@tanstack/react-query';
import { login, verifyOtp } from '@/lib/api/auth';
import { establishSession } from '@/lib/http';
import { getDeviceId } from '@/lib/token';
import { useSession } from '@/hooks/use-session';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ApiError } from '@/lib/http';
import { PORTAL_HOME, PORTAL_NAMA, type Portal } from '@/lib/portal';

/**
 * The sign-in surface of every staff portal: phone + password, in one page.
 *
 * ## Password for staff, OTP for patients
 *
 * The patient portal keeps the OTP-only door (`MasukDialog` on the landing page): a
 * patient proves the phone they already own, and there is no password to forget or
 * leak. Staff accounts are CREATED BY AN ADMIN (`POST /admin/dokter` mints the row
 * with a password), so the credential the staff member actually holds is the one the
 * admin set - and a login screen that ignored it would force every doctor, apothecary
 * and courier through an OTP channel they may not have wired up at all.
 *
 * No second backend exchange exists for this, and none is needed: `POST /auth/masuk`
 * takes the password as an OPTIONAL field (`LoginRequest` marks it `sometimes`). Sent
 * and wrong, the answer is the byte-identical 401 with a decoy-hash delay; sent and
 * right, the server mints the OTP challenge as always. The token still comes only
 * from `POST /auth/otp/verify` - the security model is untouched, this page merely
 * carries the password step the admin portal's login page pioneered.
 *
 * ## Why the code may be verified without a human typing it - and when it may not
 *
 * `AuthController::login` includes `otp.kode` in the response ONLY when
 * `IssuedOtp::plainTextForClient()` sees `app()->environment('local')`: the dev
 * stand-in for a delivery channel nobody runs locally. In any other environment the
 * key is `null`, so this page falls back to a code box: the OTP really was sent over
 * the deployment's channel and the human completes the same two steps the patient
 * door does. `OtpChallenge` deliberately omits `kode` from its type to stop a
 * one-box bypass from being built; the cast below reads the dev-only key in ONE
 * place, guarded by the null check that decides the fallback.
 *
 * ## Where the visitor lands
 *
 * `PORTAL_HOME[portal]`, the same address `/` redirects to under the guard. A wrong
 * portal is still refused after a successful sign-in - `RequirePortalAuth` reads
 * `tipe` from `/me` and renders the wrong-portal page - so a patient's password on
 * the doctor door gets an honest refusal rather than a doctor's workspace.
 */
export function PortalLoginPage({ portal }: { portal: Portal }) {
    const adaSesi = useSession();
    const navigate = useNavigate();

    const [noTelepon, setNoTelepon] = useState('');
    const [password, setPassword] = useState('');
    const [kodeManual, setKodeManual] = useState('');
    const [menungguKode, setMenungguKode] = useState(false);
    const [errorMsg, setErrorMsg] = useState<string | null>(null);

    const tujuan = () => noTelepon.trim().replace(/\s+/g, '');

    const selesaikanSesi = (token: Awaited<ReturnType<typeof verifyOtp>>['data']['token']) => {
        establishSession(token);
        navigate(PORTAL_HOME[portal], { replace: true });
    };

    const masuk = useMutation({
        mutationFn: async () => {
            setErrorMsg(null);
            const hasil = await login({ no_telepon: tujuan(), password });

            // The dev-only key behind `OtpChallenge`'s deliberate omission - see the
            // docblock. Absent in every non-local environment, which is the branch
            // that reveals the manual code box below.
            const kode = (hasil.data.otp as { kode?: string | null }).kode;

            if (!kode) {
                setMenungguKode(true);
                return;
            }

            const verifikasi = await verifyOtp({
                no_telepon: tujuan(),
                kode,
                tujuan: 'login',
                device_id: getDeviceId() ?? undefined,
            });
            selesaikanSesi(verifikasi.data.token);
        },
        onError: (err) => {
            if (err instanceof ApiError) {
                const fieldErrors = [
                    ...err.fieldErrors('no_telepon'),
                    ...err.fieldErrors('password'),
                ];
                setErrorMsg(fieldErrors.length > 0 ? fieldErrors.join(' ') : err.message);
            } else {
                setErrorMsg('Terjadi kesalahan. Coba lagi.');
            }
        },
    });

    const verifikasiKode = useMutation({
        mutationFn: async () => {
            setErrorMsg(null);
            const hasil = await verifyOtp({
                no_telepon: tujuan(),
                kode: kodeManual.trim(),
                tujuan: 'login',
                device_id: getDeviceId() ?? undefined,
            });
            selesaikanSesi(hasil.data.token);
        },
        onError: (err) => {
            if (err instanceof ApiError) {
                const fieldErrors = err.fieldErrors('kode');
                setErrorMsg(fieldErrors.length > 0 ? fieldErrors.join(' ') : err.message);
            } else {
                setErrorMsg('Terjadi kesalahan. Coba lagi.');
            }
        },
    });

    if (adaSesi) {
        return <Navigate to={PORTAL_HOME[portal]} replace />;
    }

    return (
        <main className="flex min-h-screen flex-col items-center justify-center bg-muted/40 p-4">
            <div className="w-full max-w-sm space-y-6 rounded-xl border bg-background p-8 shadow-sm">
                {/* Header */}
                <div className="flex flex-col items-center gap-2 text-center">
                    <img src="/logo.svg" alt="Sehatly" className="size-10" />
                    <h1 className="text-xl font-semibold">{PORTAL_NAMA[portal]}</h1>
                    <p className="text-muted-foreground text-sm">
                        Masuk dengan nomor telepon dan kata sandi akun Anda.
                    </p>
                </div>

                {/* Step 1: phone + password (the admin portal's shape, generalized). */}
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (menungguKode) {
                            verifikasiKode.mutate();
                        } else {
                            masuk.mutate();
                        }
                    }}
                >
                    <div className="space-y-1.5">
                        <Label htmlFor="no_telepon">Nomor telepon</Label>
                        <Input
                            id="no_telepon"
                            type="tel"
                            placeholder="081234567890"
                            autoComplete="username"
                            value={noTelepon}
                            onChange={(e) => setNoTelepon(e.target.value)}
                            disabled={masuk.isPending || menungguKode}
                            required
                        />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="password">Kata sandi</Label>
                        <Input
                            id="password"
                            type="password"
                            placeholder="••••••••"
                            autoComplete="current-password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            disabled={masuk.isPending || menungguKode}
                            required
                        />
                    </div>

                    {/**
                     * Step 2, revealed only when the backend withheld `otp.kode`
                     * (every environment but `local`): the code went over the real
                     * delivery channel and is entered here.
                     */}
                    {menungguKode && (
                        <div className="space-y-1.5">
                            <Label htmlFor="kode">Kode OTP</Label>
                            <Input
                                id="kode"
                                inputMode="numeric"
                                placeholder="6 digit kode"
                                autoComplete="one-time-code"
                                value={kodeManual}
                                onChange={(e) => setKodeManual(e.target.value)}
                                disabled={verifikasiKode.isPending}
                                required
                            />
                            <p className="text-muted-foreground text-xs">
                                Kode sudah dikirim ke nomor Anda melalui kanal OTP portal ini.
                            </p>
                        </div>
                    )}

                    {errorMsg && <p className="text-destructive text-sm">{errorMsg}</p>}

                    {menungguKode ? (
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={verifikasiKode.isPending || kodeManual.trim().length === 0}
                        >
                            {verifikasiKode.isPending ? 'Memproses...' : 'Verifikasi'}
                        </Button>
                    ) : (
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={masuk.isPending || !noTelepon || !password}
                        >
                            {masuk.isPending ? 'Memproses...' : 'Masuk'}
                        </Button>
                    )}
                </form>
            </div>
        </main>
    );
}
