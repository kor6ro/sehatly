import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Check, Copy, Eye, MessageSquare, Send, ShieldCheck, UserRound } from 'lucide-react';
import { toast } from 'sonner';
import { buatUndanganMutation, type UndangPenggunaInput } from '@/lib/api/admin';
import { meOptions } from '@/lib/api/me';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { AdminGate } from '@/portals/admin/features/admin/admin-gate';
import { pesanRingkas } from '@/portals/admin/features/admin/pesan';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldInput } from '@/shared/components/form/field';

const TIPE_UNDANG: ReadonlyArray<{ nilai: UndangPenggunaInput['tipe']; label: string }> = [
    { nilai: 'dokter', label: 'Dokter' },
    { nilai: 'apoteker', label: 'Apoteker' },
    { nilai: 'kurir', label: 'Kurir' },
    { nilai: 'admin', label: 'Admin' },
];

const KANAL: ReadonlyArray<{ nilai: UndangPenggunaInput['kanal']; label: string }> = [
    { nilai: 'whatsapp', label: 'WhatsApp' },
    { nilai: 'sms', label: 'SMS' },
    { nilai: 'email', label: 'Email' },
];

export function AdminPenggunaUndangPage() {
    return (
        <AdminGate>
            <UndangContent />
        </AdminGate>
    );
}

function UndangContent() {
    useDocumentTitle('Undang Pengguna');
    const navigate = useNavigate();
    const [params] = useSearchParams();

    const me = useQuery(meOptions());
    const isSuperadmin = me.data?.data.user?.tipe === 'superadmin';
    const pilihanTipe = isSuperadmin ? TIPE_UNDANG : TIPE_UNDANG.filter(t => t.nilai !== 'admin');

    const tipeAwal = ((): UndangPenggunaInput['tipe'] => {
        const q = params.get('tipe');
        if (q && pilihanTipe.some(t => t.nilai === q)) return q as UndangPenggunaInput['tipe'];
        return 'apoteker';
    })();

    const [form, setForm] = useState({ nama_lengkap: '', no_telepon: '', email: '', tipe: tipeAwal, kanal: 'whatsapp' as UndangPenggunaInput['kanal'] });
    const [token, setToken] = useState<string | null>(null);
    const [namaHasil, setNamaHasil] = useState('');
    const [copied, setCopied] = useState(false);

    const undang = useMutation({
        ...buatUndanganMutation(),
        onSuccess: (result) => {
            setToken(result.data.token);
            setNamaHasil(result.data.pengguna.nama_lengkap);
        },
        onError: (error) => toast.error(pesanRingkas(error)),
    });

    const salin = async () => {
        if (!token) return;
        await navigator.clipboard.writeText(token);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    const dash = (v: string) => v.trim() || '-';
    const labelTipe = (v: string) => pilihanTipe.find(t => t.nilai === v)?.label ?? v;
    const labelKanal = (v: string) => KANAL.find(k => k.nilai === v)?.label ?? v;

    const chipClass = (aktif: boolean) =>
        'rounded-full border px-4 py-1.5 text-sm font-medium transition-colors ' +
        (aktif ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-background text-foreground hover:bg-muted');

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <Button type="button" variant="outline" size="icon" aria-label="Kembali" onClick={() => navigate('/admin/pengguna')}>
                        <ArrowLeft className="size-4" />
                    </Button>
                    <div>
                        <h1 className="text-xl font-semibold">Undang Pengguna Baru</h1>
                        <p className="text-sm text-muted-foreground">Akun dibuat dengan status diundang. Token aktivasi hanya ditampilkan sekali.</p>
                    </div>
                </div>
                <div className="flex gap-2">
                    <Button type="button" variant="outline" onClick={() => navigate('/admin/pengguna')}>
                        {token ? 'Selesai' : 'Batal'}
                    </Button>
                    {!token && (
                        <Button
                            type="button"
                            disabled={undang.isPending || !form.nama_lengkap || !form.no_telepon}
                            onClick={() => undang.mutate({
                                nama_lengkap: form.nama_lengkap,
                                no_telepon: form.no_telepon,
                                tipe: form.tipe,
                                kanal: form.kanal,
                                ...(form.email ? { email: form.email } : {}),
                            })}
                        >
                            <Send className="size-4" />
                            {undang.isPending ? 'Mengirim…' : 'Kirim Undangan'}
                        </Button>
                    )}
                </div>
            </div>

            {token && (
                <div className="rounded-xl border border-green-200 bg-green-50 p-4 space-y-2">
                    <p className="font-medium text-green-800">Undangan terkirim ke {namaHasil}.</p>
                    <code className="block rounded-lg border bg-white px-3 py-2 font-mono text-sm break-all">{token}</code>
                    <p className="text-xs text-green-700">Salin token sekarang — token tidak akan ditampilkan lagi.</p>
                    <Button type="button" variant="outline" size="sm" onClick={salin}>
                        {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                        {copied ? 'Tersalin' : 'Salin token'}
                    </Button>
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_300px] lg:items-start">
                <div className="space-y-4">
                    <section className="rounded-xl border">
                        <header className="flex items-center gap-3 border-b px-4 py-3">
                            <div className="grid size-8 place-items-center rounded-lg bg-primary/10 text-primary"><UserRound className="size-4" /></div>
                            <div>
                                <p className="text-sm font-semibold">Informasi Utama</p>
                                <p className="text-xs text-muted-foreground">Data dasar pengguna yang diundang</p>
                            </div>
                        </header>
                        <div className="space-y-4 p-4">
                            <Field label="Nama lengkap" required>
                                <FieldInput placeholder="Masukkan nama lengkap…" value={form.nama_lengkap} disabled={!!token}
                                    onChange={(e) => setForm(p => ({ ...p, nama_lengkap: e.target.value }))} />
                            </Field>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Field label="Nomor telepon" required>
                                    <FieldInput placeholder="08…" inputMode="tel" value={form.no_telepon} disabled={!!token}
                                        onChange={(e) => setForm(p => ({ ...p, no_telepon: e.target.value }))} />
                                </Field>
                                <Field label="Email">
                                    <FieldInput type="email" placeholder="Opsional" value={form.email} disabled={!!token}
                                        onChange={(e) => setForm(p => ({ ...p, email: e.target.value }))} />
                                </Field>
                            </div>
                        </div>
                    </section>

                    <section className="rounded-xl border">
                        <header className="flex items-center gap-3 border-b px-4 py-3">
                            <div className="grid size-8 place-items-center rounded-lg bg-primary/10 text-primary"><ShieldCheck className="size-4" /></div>
                            <div>
                                <p className="text-sm font-semibold">Tipe Akun</p>
                                <p className="text-xs text-muted-foreground">Pilih satu role untuk pengguna ini</p>
                            </div>
                        </header>
                        <div className="flex flex-wrap gap-2 p-4">
                            {pilihanTipe.map(t => (
                                <button key={t.nilai} type="button" disabled={!!token}
                                    className={chipClass(form.tipe === t.nilai)}
                                    onClick={() => setForm(p => ({ ...p, tipe: t.nilai }))}>
                                    {t.label}
                                </button>
                            ))}
                        </div>
                    </section>

                    <section className="rounded-xl border">
                        <header className="flex items-center gap-3 border-b px-4 py-3">
                            <div className="grid size-8 place-items-center rounded-lg bg-primary/10 text-primary"><MessageSquare className="size-4" /></div>
                            <div>
                                <p className="text-sm font-semibold">Kanal Pengiriman</p>
                                <p className="text-xs text-muted-foreground">Lewat mana undangan dikirim</p>
                            </div>
                        </header>
                        <div className="flex flex-wrap gap-2 p-4">
                            {KANAL.map(k => (
                                <button key={k.nilai} type="button" disabled={!!token}
                                    className={chipClass(form.kanal === k.nilai)}
                                    onClick={() => setForm(p => ({ ...p, kanal: k.nilai }))}>
                                    {k.label}
                                </button>
                            ))}
                        </div>
                    </section>
                </div>

                <aside className="rounded-xl border">
                    <header className="flex items-center gap-3 border-b px-4 py-3">
                        <div className="grid size-8 place-items-center rounded-lg bg-primary/10 text-primary"><Eye className="size-4" /></div>
                        <p className="text-sm font-semibold">Preview Data</p>
                    </header>
                    <dl className="divide-y px-4">
                        {([
                            ['Nama', dash(form.nama_lengkap)],
                            ['Telepon', dash(form.no_telepon)],
                            ['Email', dash(form.email)],
                            ['Tipe akun', labelTipe(form.tipe)],
                            ['Kanal', labelKanal(form.kanal)],
                        ] as [string, string][]).map(([dt, dd]) => (
                            <div key={dt} className="flex items-center justify-between gap-2 py-2.5 text-sm">
                                <dt className="text-muted-foreground">{dt}</dt>
                                <dd className="font-medium text-right break-all">{dd}</dd>
                            </div>
                        ))}
                        <div className="flex items-center justify-between gap-2 py-2.5 text-sm">
                            <dt className="text-muted-foreground">Status awal</dt>
                            <dd><Badge variant="outline">Diundang</Badge></dd>
                        </div>
                    </dl>
                </aside>
            </div>
        </div>
    );
}
