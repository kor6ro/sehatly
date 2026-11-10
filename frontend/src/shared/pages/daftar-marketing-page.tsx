import { useState } from 'react';
import { Link } from 'react-router';
import { Hourglass, Loader2 } from 'lucide-react';
import { request, ApiError } from '@/lib/http';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';

const MIN_SANDI = 8;

export function DaftarMarketingPage() {
    const [form, setForm] = useState({
        nama_lengkap: '',
        no_telepon: '',
        email: '',
        kata_sandi: '',
        konfirmasi: '',
    });
    const [loading, setLoading]         = useState(false);
    const [sukses, setSukses]           = useState(false);
    const [error, setError]             = useState<string | null>(null);
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
            <main className="flex min-h-screen flex-col items-center justify-center bg-muted/40 p-4">
                <div className="w-full max-w-sm space-y-6 rounded-xl border bg-background p-8 shadow-sm text-center">
                    <div className="mx-auto grid size-16 place-items-center rounded-full bg-amber-100">
                        <Hourglass className="size-8 text-amber-700" aria-hidden />
                    </div>
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold">Pendaftaran terkirim</h1>
                        <p className="text-muted-foreground text-sm">
                            Admin akan meninjau pendaftaranmu. Kamu belum bisa masuk
                            sampai disetujui — kamu akan dihubungi setelahnya.
                        </p>
                    </div>
                    <Button asChild className="w-full">
                        <Link to="/login">Ke halaman masuk</Link>
                    </Button>
                </div>
            </main>
        );
    }

    return (
        <main className="flex min-h-screen flex-col items-center justify-center bg-muted/40 p-4">
            <div className="w-full max-w-sm space-y-6 rounded-xl border bg-background p-8 shadow-sm">
                {/* Header */}
                <div className="flex flex-col items-center gap-2 text-center">
                    <img src="/logo.svg" alt="Sehatly" className="size-10" />
                    <h1 className="text-xl font-semibold">Portal Marketing</h1>
                    <p className="text-muted-foreground text-sm">
                        Daftar sebagai mitra marketing Sehatly. Akun perlu disetujui
                        admin sebelum bisa dipakai.
                    </p>
                </div>

                {error && (
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <form onSubmit={(e) => void kirim(e)} className="space-y-4" noValidate>
                    <div className="space-y-1.5">
                        <Label htmlFor="nama_lengkap">Nama lengkap</Label>
                        <Input
                            id="nama_lengkap"
                            placeholder="Nama lengkap Anda"
                            autoComplete="name"
                            value={form.nama_lengkap}
                            onChange={set('nama_lengkap')}
                            required
                        />
                        {fieldErrors.nama_lengkap && (
                            <p className="text-destructive text-xs">{fieldErrors.nama_lengkap}</p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="no_telepon">Nomor telepon</Label>
                        <Input
                            id="no_telepon"
                            inputMode="tel"
                            placeholder="081234567890"
                            autoComplete="tel"
                            value={form.no_telepon}
                            onChange={set('no_telepon')}
                            required
                        />
                        {fieldErrors.no_telepon && (
                            <p className="text-destructive text-xs">{fieldErrors.no_telepon}</p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="email">
                            Email <span className="text-muted-foreground font-normal">(opsional)</span>
                        </Label>
                        <Input
                            id="email"
                            type="email"
                            placeholder="email@contoh.com"
                            autoComplete="email"
                            value={form.email}
                            onChange={set('email')}
                        />
                        {fieldErrors.email && (
                            <p className="text-destructive text-xs">{fieldErrors.email}</p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="kata_sandi">Kata sandi</Label>
                        <Input
                            id="kata_sandi"
                            type="password"
                            placeholder="••••••••"
                            autoComplete="new-password"
                            value={form.kata_sandi}
                            onChange={set('kata_sandi')}
                            required
                        />
                        {fieldErrors.kata_sandi ? (
                            <p className="text-destructive text-xs">{fieldErrors.kata_sandi}</p>
                        ) : (
                            <p className="text-muted-foreground text-xs">Minimal {MIN_SANDI} karakter.</p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="konfirmasi">Ulangi kata sandi</Label>
                        <Input
                            id="konfirmasi"
                            type="password"
                            placeholder="••••••••"
                            autoComplete="new-password"
                            value={form.konfirmasi}
                            onChange={set('konfirmasi')}
                            required
                        />
                        {fieldErrors.konfirmasi && (
                            <p className="text-destructive text-xs">{fieldErrors.konfirmasi}</p>
                        )}
                    </div>

                    <Button type="submit" className="w-full" disabled={loading}>
                        {loading && <Loader2 className="animate-spin" />}
                        {loading ? 'Mengirim…' : 'Kirim pendaftaran'}
                    </Button>
                </form>

                <p className="text-center text-sm text-muted-foreground">
                    Sudah punya akun?{' '}
                    <Link to="/login" className="font-medium text-primary underline-offset-4 hover:underline">
                        Masuk
                    </Link>
                </p>
            </div>
        </main>
    );
}
