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

/**
 * Halaman login khusus portal admin.
 *
 * Berbeda dari `PortalLoginPage` yang memakai `MasukDialog` (flow OTP dua langkah),
 * halaman ini menerima no. telepon + password lalu menyelesaikan OTP secara otomatis
 * di background — karena di environment dev backend mengembalikan `kode` langsung di
 * response body (`$issued->plainTextForClient()`), sehingga admin tidak perlu
 * membuka log atau menerima SMS.
 *
 * Flow:
 *   1. POST /auth/masuk  → dapat { otp: { kode, tujuan } }
 *   2. POST /auth/otp/verify dengan kode tersebut → dapat token
 *   3. establishSession() → redirect ke /admin/dokter
 */
export function AdminLoginPage() {
    const adaSesi = useSession();
    const navigate = useNavigate();

    const [noTelepon, setNoTelepon] = useState('');
    const [password, setPassword] = useState('');
    const [errorMsg, setErrorMsg] = useState<string | null>(null);

    const masuk = useMutation({
        mutationFn: async () => {
            setErrorMsg(null);

            // Normalisasi: hilangkan spasi, ganti awalan 0 dengan 62 jika perlu
            const nomor = noTelepon.trim().replace(/\s+/g, '');

            // Step 1: login → dapat OTP challenge (termasuk kode di dev)
            const loginResult = await login({ no_telepon: nomor, password });

            const kode = (loginResult.data.otp as { kode?: string }).kode;

            if (!kode) {
                setErrorMsg(
                    'Kode OTP tidak tersedia di response. Pastikan environment bukan production, atau cek log Laravel.',
                );
                return;
            }

            // Step 2: verify OTP secara otomatis
            const verifyResult = await verifyOtp({
                no_telepon: nomor,
                kode,
                tujuan: 'login',
                device_id: getDeviceId() ?? undefined,
            });

            // Step 3: simpan token dan arahkan ke workspace
            establishSession(verifyResult.data.token);
            navigate('/admin/dokter', { replace: true });
        },
        onError: (err) => {
            if (err instanceof ApiError) {
                // Field error no_telepon / password / kode
                const fieldErrors = [
                    ...err.fieldErrors('no_telepon'),
                    ...err.fieldErrors('password'),
                    ...err.fieldErrors('kode'),
                ];
                setErrorMsg(fieldErrors.length > 0 ? fieldErrors.join(' ') : err.message);
            } else {
                setErrorMsg('Terjadi kesalahan. Coba lagi.');
            }
        },
    });

    if (adaSesi) {
        return <Navigate to="/admin/dokter" replace />;
    }

    return (
        <main className="flex min-h-screen flex-col items-center justify-center bg-muted/40 p-4">
            <div className="w-full max-w-sm space-y-6 rounded-xl border bg-background p-8 shadow-sm">
                {/* Header */}
                <div className="flex flex-col items-center gap-2 text-center">
                    <img src="/logo.svg" alt="Sehatly" className="size-10" />
                    <h1 className="text-xl font-semibold">Portal Admin</h1>
                    <p className="text-muted-foreground text-sm">
                        Masuk dengan akun admin atau superadmin.
                    </p>
                </div>

                {/* Form */}
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        masuk.mutate();
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
                            disabled={masuk.isPending}
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
                            disabled={masuk.isPending}
                            required
                        />
                    </div>

                    {errorMsg && (
                        <p className="text-destructive text-sm">{errorMsg}</p>
                    )}

                    <Button
                        type="submit"
                        className="w-full"
                        disabled={masuk.isPending || !noTelepon || !password}
                    >
                        {masuk.isPending ? 'Memproses...' : 'Masuk'}
                    </Button>
                </form>
            </div>
        </main>
    );
}
