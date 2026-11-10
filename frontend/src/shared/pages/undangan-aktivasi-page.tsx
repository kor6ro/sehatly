import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { request } from '@/lib/http';
import { ApiError } from '@/lib/http';
import { Button } from '@/components/ui/button';
import { Field, FieldInput } from '@/shared/components/form/field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { CheckCircle, Loader2 } from 'lucide-react';

/**
 * `POST /api/v1/auth/undangan/{token}/aktivasi`
 *
 * Public page — no auth required. The token comes from the URL path.
 * The invitee enters their OTP (sent to their phone) and sets a password.
 * On success they are redirected to /login.
 */
export function UndanganAktivasiPage() {
    const { token } = useParams<{ token: string }>();
    const navigate = useNavigate();

    const [otp, setOtp] = useState('');
    const [password, setPassword] = useState('');
    const [konfirmasi, setKonfirmasi] = useState('');
    const [loading, setLoading] = useState(false);
    const [sukses, setSukses] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    async function kirim(e: React.FormEvent) {
        e.preventDefault();
        setError(null);
        setFieldErrors({});

        if (password !== konfirmasi) {
            setFieldErrors({ konfirmasi: 'Konfirmasi sandi tidak cocok.' });
            return;
        }

        setLoading(true);
        try {
            await request(`auth/undangan/${token}/aktivasi`, {
                method: 'POST',
                json: { otp, password },
            });
            setSukses(true);
            setTimeout(() => void navigate('/login'), 2500);
        } catch (err) {
            if (err instanceof ApiError) {
                if (err.status === 404) {
                    setError('Token undangan tidak valid atau sudah kedaluwarsa.');
                } else {
                    const fields = err.failedFields;
                    if (fields.length > 0) {
                        const fe: Record<string, string> = {};
                        for (const f of fields) {
                            const msgs = err.fieldErrors(f);
                            if (msgs[0]) fe[f] = msgs[0];
                        }
                        setFieldErrors(fe);
                    } else {
                        setError(err.message);
                    }
                }
            } else {
                setError('Terjadi kesalahan. Coba lagi.');
            }
        } finally {
            setLoading(false);
        }
    }

    if (sukses) {
        return (
            <div className="min-h-screen flex items-center justify-center p-4">
                <div className="w-full max-w-sm space-y-4 text-center">
                    <CheckCircle className="mx-auto h-12 w-12 text-green-500" />
                    <h1 className="text-xl font-semibold">Akun berhasil diaktivasi</h1>
                    <p className="text-muted-foreground text-sm">
                        Mengalihkan ke halaman masuk...
                    </p>
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen flex items-center justify-center p-4">
            <div className="w-full max-w-sm space-y-6">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold">Aktivasi undangan</h1>
                    <p className="text-muted-foreground text-sm">
                        Masukkan kode OTP yang dikirim ke nomor Anda, lalu buat kata sandi.
                    </p>
                </div>

                {error ? (
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                ) : null}

                <form onSubmit={(e) => void kirim(e)} className="space-y-4">
                    <Field
                        label="Kode OTP"
                        required
                        errors={fieldErrors.otp ? [fieldErrors.otp] : []}
                    >
                        <FieldInput
                            inputMode="numeric"
                            maxLength={6}
                            placeholder="6 digit"
                            value={otp}
                            onChange={(e) => setOtp(e.target.value.replace(/\D/g, ''))}
                        />
                    </Field>

                    <Field
                        label="Kata sandi baru"
                        required
                        errors={fieldErrors.password ? [fieldErrors.password] : []}
                    >
                        <FieldInput
                            type="password"
                            placeholder="Min. 8 karakter"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                        />
                    </Field>

                    <Field
                        label="Konfirmasi kata sandi"
                        required
                        errors={fieldErrors.konfirmasi ? [fieldErrors.konfirmasi] : []}
                    >
                        <FieldInput
                            type="password"
                            placeholder="Ulangi kata sandi"
                            value={konfirmasi}
                            onChange={(e) => setKonfirmasi(e.target.value)}
                        />
                    </Field>

                    <Button type="submit" className="w-full" disabled={loading}>
                        {loading ? <Loader2 className="animate-spin" /> : null}
                        Aktifkan akun
                    </Button>
                </form>
            </div>
        </div>
    );
}
