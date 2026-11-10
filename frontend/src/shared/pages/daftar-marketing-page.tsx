import { useState } from 'react';
import { Link } from 'react-router';
import { CheckCircle, Hourglass, Loader2 } from 'lucide-react';
import { request, ApiError } from '@/lib/http';
import { Button } from '@/components/ui/button';
import { Field, FieldInput } from '@/shared/components/form/field';
import { Alert, AlertDescription } from '@/components/ui/alert';

const MIN_SANDI = 8;

/**
 * `POST /api/v1/auth/daftar/marketing`
 *
 * Rute publik: /daftar/marketing
 * Akun dibuat dengan status `menunggu_persetujuan` — tidak bisa masuk
 * sampai admin menyetujuinya.
 */
export function DaftarMarketingPage() {
    const [form, setForm] = useState({
        nama_lengkap: '',
        no_telepon: '',
        email: '',
        kata_sandi: '',
        konfirmasi: '',
    });
    const [loading, setLoading]       = useState(false);
    const [sukses, setSukses]         = useState(false);
    const [error, setError]           = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) =>
        setForm((f) => ({ ...f, [k]: e.target.value }));

    async function kirim(e: React.FormEvent) {
        e.preventDefault();
        setError(null);
        setFieldErrors({});

        const local: Record<string, string> = {};
        if (form.kata_sandi.length < MIN_SANDI)
            local.kata_sandi = `Sandi minimal ${MIN_SANDI} karakter.`;
        if (form.kata_sandi !== form.konfirmasi)
            local.konfirmasi = 'Konfirmasi sandi tidak sama.';
        if (Object.keys(local).length) { setFieldErrors(local); return; }

        setLoading(true);
        try {
            await request('auth/daftar/marketing', {
                method: 'POST',
                json: {
                    nama_lengkap: form.nama_lengkap,
                    no_telepon: form.no_telepon,
                    email: form.email || undefined,
                    password: form.kata_sandi,
                    password_confirmation: form.konfirmasi,
                },
            });
            setSukses(true);
        } catch (err) {
            if (err instanceof ApiError) {
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
            } else {
                setError('Terjadi kesalahan. Coba lagi.');
            }
        } finally {
            setLoading(false);
        }
    }

    if (sukses) {
        return (
            <div className="flex min-h-screen flex-col items-center justify-center p-4">
                <div className="w-full max-w-sm space-y-4 text-center">
                    <div className="mx-auto grid size-16 place-items-center rounded-full bg-amber-100">
                        <Hourglass className="size-8 text-amber-700" aria-hidden />
                    </div>
                    <h1 className="text-xl font-semibold">Pendaftaran terkirim</h1>
                    <p className="text-muted-foreground text-sm">
                        Admin akan meninjau pendaftaranmu. Kamu belum bisa masuk sampai
                        disetujui, dan kamu akan menerima pemberitahuan setelahnya.
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
            <div className="w-full max-w-sm space-y-6 rounded-xl border bg-background p-8 shadow-sm">
                <div className="flex flex-col items-center gap-2 text-center">
                    <img src="/logo.svg" alt="Sehatly" className="size-10" />
                    <h1 className="text-2xl font-semibold">Daftar mitra marketing</h1>
                    <p className="text-muted-foreground text-sm">
                        Pendaftaran perlu disetujui admin sebelum akun bisa dipakai.
                    </p>
                </div>

                {error && (
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <form onSubmit={(e) => void kirim(e)} className="space-y-4" noValidate>
                    <Field
                        label="Nama lengkap"
                        required
                        errors={fieldErrors.nama_lengkap ? [fieldErrors.nama_lengkap] : []}
                    >
                        <FieldInput
                            placeholder="Nama lengkap"
                            autoComplete="name"
                            value={form.nama_lengkap}
                            onChange={set('nama_lengkap')}
                        />
                    </Field>

                    <Field
                        label="Nomor HP"
                        required
                        errors={fieldErrors.no_telepon ? [fieldErrors.no_telepon] : []}
                    >
                        <FieldInput
                            inputMode="tel"
                            placeholder="08xxxxxxxxxx"
                            autoComplete="tel"
                            value={form.no_telepon}
                            onChange={set('no_telepon')}
                        />
                    </Field>

                    <Field
                        label="Email (opsional)"
                        errors={fieldErrors.email ? [fieldErrors.email] : []}
                    >
                        <FieldInput
                            type="email"
                            placeholder="Opsional"
                            autoComplete="email"
                            value={form.email}
                            onChange={set('email')}
                        />
                    </Field>

                    <Field
                        label="Sandi"
                        required
                        errors={fieldErrors.kata_sandi ? [fieldErrors.kata_sandi] : []}
                        hint={`Minimal ${MIN_SANDI} karakter.`}
                    >
                        <FieldInput
                            type="password"
                            placeholder="••••••••"
                            autoComplete="new-password"
                            value={form.kata_sandi}
                            onChange={set('kata_sandi')}
                        />
                    </Field>

                    <Field
                        label="Ulangi sandi"
                        required
                        errors={fieldErrors.konfirmasi ? [fieldErrors.konfirmasi] : []}
                    >
                        <FieldInput
                            type="password"
                            placeholder="••••••••"
                            autoComplete="new-password"
                            value={form.konfirmasi}
                            onChange={set('konfirmasi')}
                        />
                    </Field>

                    <Button type="submit" className="w-full" disabled={loading}>
                        {loading && <Loader2 className="animate-spin" />}
                        {loading ? 'Mengirim…' : 'Kirim pendaftaran'}
                    </Button>
                </form>

                <p className="text-center text-sm text-muted-foreground">
                    Sudah punya akun?{' '}
                    <Link to="/login" className="underline">
                        Masuk
                    </Link>
                </p>
            </div>
        </div>
    );
}

