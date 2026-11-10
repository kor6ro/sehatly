import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useMutation } from '@tanstack/react-query';
import { ArrowLeft, Eye, FileBadge, MessageSquare, Save, UserRound } from 'lucide-react';
import { toast } from 'sonner';
import { buatDokterMutation, type BuatDokterInput } from '@/lib/api/admin';
import { ApiError } from '@/lib/http';
import { AdminGate } from '@/portals/admin/features/admin/admin-gate';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

const TIPE_DOKTER: Array<{ nilai: BuatDokterInput['tipe']; label: string }> = [
    { nilai: 'dokter_umum', label: 'Dokter Umum' },
    { nilai: 'dokter_spesialis', label: 'Dokter Spesialis' },
    { nilai: 'dokter_gigi', label: 'Dokter Gigi' },
    { nilai: 'psikolog', label: 'Psikolog' },
    { nilai: 'bidan', label: 'Bidan' },
    { nilai: 'perawat', label: 'Perawat' },
    { nilai: 'apoteker', label: 'Apoteker' },
];

export function AdminDokterBuatPage() {
    return (
        <AdminGate>
            <AdminDokterBuatContent />
        </AdminGate>
    );
}

function AdminDokterBuatContent() {
    const navigate = useNavigate();

    const [form, setForm] = useState({
        nama_lengkap: '',
        no_telepon: '',
        email: '',
        kata_sandi: '',
        tipe: '' as BuatDokterInput['tipe'] | '',
        nomor_str: '',
        str_berlaku_sampai: '',
        nomor_sip: '',
        sip_berlaku_sampai: '',
        pengalaman_tahun: '',
        biaya_konsultasi_online: '',
    });

    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const buat = useMutation({
        ...buatDokterMutation(),
        onSuccess: (result) => {
            toast.success('Akun dokter berhasil dibuat.');
            void navigate(`/admin/dokter/${result.data.dokter.id}`);
        },
        onError: (error) => {
            if (error instanceof ApiError && error.isValidation) {
                setErrors(error.errors);
                return;
            }
            toast.error(error instanceof Error ? error.message : 'Gagal membuat akun dokter.');
        },
    });

    const set = (key: string, value: string): void => {
        setForm((prev) => ({ ...prev, [key]: value }));
        setErrors((prev) => ({ ...prev, [key]: [] }));
    };

    const fieldError = (key: string): string | undefined => errors[key]?.[0];

    const handleSubmit = (): void => {
        setErrors({});
        if (!form.tipe) return;
        const input: BuatDokterInput = {
            nama_lengkap: form.nama_lengkap,
            no_telepon: form.no_telepon,
            email: form.email || null,
            kata_sandi: form.kata_sandi,
            tipe: form.tipe,
            nomor_str: form.nomor_str,
            str_berlaku_sampai: form.str_berlaku_sampai,
            nomor_sip: form.nomor_sip || null,
            sip_berlaku_sampai: form.sip_berlaku_sampai || null,
            pengalaman_tahun: form.pengalaman_tahun ? Number(form.pengalaman_tahun) : null,
            biaya_konsultasi_online: form.biaya_konsultasi_online ? Number(form.biaya_konsultasi_online) : null,
        };
        buat.mutate(input);
    };

    const dash = (v: string) => v.trim() || '-';
    const labelTipe = (v: string) => TIPE_DOKTER.find(t => t.nilai === v)?.label ?? '-';

    return (
        <div className="space-y-6">
            {/* header bar */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <Button type="button" variant="outline" size="icon" aria-label="Kembali"
                        onClick={() => void navigate('/admin/dokter')}>
                        <ArrowLeft className="size-4" />
                    </Button>
                    <div>
                        <h1 className="text-xl font-semibold">Buat Dokter Baru</h1>
                        <p className="text-sm text-muted-foreground">Akun baru akan mulai dengan status verifikasi pending.</p>
                    </div>
                </div>
                <div className="flex gap-2">
                    <Button type="button" variant="outline" onClick={() => void navigate('/admin/dokter')}>
                        Batal
                    </Button>
                    <Button type="button" disabled={buat.isPending || !form.tipe} onClick={handleSubmit}>
                        <Save className="size-4" />
                        {buat.isPending ? 'Menyimpan…' : 'Simpan Dokter'}
                    </Button>
                </div>
            </div>

            {/* 2-column grid */}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_300px] lg:items-start">
                {/* left column */}
                <div className="space-y-4">
                    {/* Identitas akun */}
                    <section className="rounded-xl border">
                        <header className="flex items-center gap-3 border-b px-4 py-3">
                            <div className="grid size-8 place-items-center rounded-lg bg-primary/10 text-primary">
                                <UserRound className="size-4" />
                            </div>
                            <div>
                                <p className="text-sm font-semibold">Identitas Akun</p>
                                <p className="text-xs text-muted-foreground">Nama, kontak, dan kata sandi awal</p>
                            </div>
                        </header>
                        <div className="space-y-4 p-4">
                            <Field label="Nama lengkap" error={fieldError('nama_lengkap')} required>
                                <Input value={form.nama_lengkap} autoComplete="off"
                                    placeholder="Contoh: dr. Rina Kartika, Sp.A"
                                    onChange={(e) => set('nama_lengkap', e.target.value)} />
                            </Field>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Field label="Nomor telepon" error={fieldError('no_telepon')} required>
                                    <Input value={form.no_telepon} autoComplete="off" placeholder="08…"
                                        onChange={(e) => set('no_telepon', e.target.value)} />
                                </Field>
                                <Field label="Email (opsional)" error={fieldError('email')}>
                                    <Input type="email" value={form.email} autoComplete="off" placeholder="Opsional"
                                        onChange={(e) => set('email', e.target.value)} />
                                </Field>
                            </div>
                            <Field label="Kata sandi awal" error={fieldError('kata_sandi')} required>
                                <Input type="password" value={form.kata_sandi} autoComplete="new-password"
                                    onChange={(e) => set('kata_sandi', e.target.value)} />
                            </Field>
                        </div>
                    </section>

                    {/* Tipe dokter */}
                    <section className="rounded-xl border">
                        <header className="flex items-center gap-3 border-b px-4 py-3">
                            <div className="grid size-8 place-items-center rounded-lg bg-primary/10 text-primary">
                                <MessageSquare className="size-4" />
                            </div>
                            <div>
                                <p className="text-sm font-semibold">Tipe Dokter</p>
                                <p className="text-xs text-muted-foreground">Pilih satu tipe untuk dokter ini</p>
                            </div>
                        </header>
                        <div className="p-4">
                            <Field label="Tipe dokter" error={fieldError('tipe')} required>
                                <Select value={form.tipe} onValueChange={(v) => set('tipe', v)}>
                                    <SelectTrigger><SelectValue placeholder="Pilih tipe" /></SelectTrigger>
                                    <SelectContent>
                                        {TIPE_DOKTER.map((t) => (
                                            <SelectItem key={t.nilai} value={t.nilai}>{t.label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        </div>
                    </section>

                    {/* Legalitas */}
                    <section className="rounded-xl border">
                        <header className="flex items-center gap-3 border-b px-4 py-3">
                            <div className="grid size-8 place-items-center rounded-lg bg-primary/10 text-primary">
                                <FileBadge className="size-4" />
                            </div>
                            <div>
                                <p className="text-sm font-semibold">Legalitas</p>
                                <p className="text-xs text-muted-foreground">STR dan SIP beserta masa berlaku</p>
                            </div>
                        </header>
                        <div className="space-y-4 p-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Field label="Nomor STR" error={fieldError('nomor_str')} required>
                                    <Input value={form.nomor_str} autoComplete="off"
                                        onChange={(e) => set('nomor_str', e.target.value)} />
                                </Field>
                                <Field label="STR berlaku sampai" error={fieldError('str_berlaku_sampai')} required>
                                    <Input type="date" value={form.str_berlaku_sampai}
                                        onChange={(e) => set('str_berlaku_sampai', e.target.value)} />
                                </Field>
                                <Field label="Nomor SIP (opsional)" error={fieldError('nomor_sip')}>
                                    <Input value={form.nomor_sip} autoComplete="off"
                                        onChange={(e) => set('nomor_sip', e.target.value)} />
                                </Field>
                                <Field label="SIP berlaku sampai (opsional)" error={fieldError('sip_berlaku_sampai')}>
                                    <Input type="date" value={form.sip_berlaku_sampai}
                                        onChange={(e) => set('sip_berlaku_sampai', e.target.value)} />
                                </Field>
                            </div>
                        </div>
                    </section>

                    {/* Tarif & pengalaman */}
                    <section className="rounded-xl border">
                        <header className="flex items-center gap-3 border-b px-4 py-3">
                            <div className="grid size-8 place-items-center rounded-lg bg-primary/10 text-primary">
                                <FileBadge className="size-4" />
                            </div>
                            <div>
                                <p className="text-sm font-semibold">Tarif & Pengalaman</p>
                                <p className="text-xs text-muted-foreground">Opsional</p>
                            </div>
                        </header>
                        <div className="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
                            <Field label="Pengalaman (tahun)" error={fieldError('pengalaman_tahun')}>
                                <Input type="number" min={0} value={form.pengalaman_tahun}
                                    onChange={(e) => set('pengalaman_tahun', e.target.value)} />
                            </Field>
                            <Field label="Biaya konsultasi online (Rp)" error={fieldError('biaya_konsultasi_online')}>
                                <Input type="number" min={0} value={form.biaya_konsultasi_online}
                                    onChange={(e) => set('biaya_konsultasi_online', e.target.value)} />
                            </Field>
                        </div>
                    </section>
                </div>

                {/* right column: preview */}
                <aside className="rounded-xl border">
                    <header className="flex items-center gap-3 border-b px-4 py-3">
                        <div className="grid size-8 place-items-center rounded-lg bg-primary/10 text-primary">
                            <Eye className="size-4" />
                        </div>
                        <p className="text-sm font-semibold">Preview Data</p>
                    </header>
                    <dl className="divide-y px-4">
                        {([
                            ['Nama', dash(form.nama_lengkap)],
                            ['Telepon', dash(form.no_telepon)],
                            ['Email', dash(form.email)],
                            ['Tipe', labelTipe(form.tipe)],
                            ['Nomor STR', dash(form.nomor_str)],
                            ['STR s.d.', form.str_berlaku_sampai || '-'],
                            ['Nomor SIP', dash(form.nomor_sip)],
                            ['SIP s.d.', form.sip_berlaku_sampai || '-'],
                        ] as [string, string][]).map(([dt, dd]) => (
                            <div key={dt} className="flex items-center justify-between gap-2 py-2.5 text-sm">
                                <dt className="text-muted-foreground">{dt}</dt>
                                <dd className="font-medium text-right break-all">{dd}</dd>
                            </div>
                        ))}
                        <div className="flex items-center justify-between gap-2 py-2.5 text-sm">
                            <dt className="text-muted-foreground">Verifikasi</dt>
                            <dd><Badge variant="outline">Pending</Badge></dd>
                        </div>
                    </dl>
                </aside>
            </div>
        </div>
    );
}

function Field({
    label,
    error,
    required,
    children,
}: {
    label: string;
    error?: string;
    required?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label>{label}{required && <span className="text-destructive ml-0.5">*</span>}</Label>
            {children}
            {error ? <p className="text-destructive text-xs">{error}</p> : null}
        </div>
    );
}
