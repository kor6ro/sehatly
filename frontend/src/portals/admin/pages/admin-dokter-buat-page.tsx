import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useMutation } from '@tanstack/react-query';
import { toast } from 'sonner';
import { buatDokterMutation, type BuatDokterInput } from '@/lib/api/admin';
import { ApiError } from '@/lib/http';
import { AdminGate } from '@/portals/admin/features/admin/admin-gate';
import { PageHeader } from '@/shared/components/layout/page-header';
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

    const fieldError = (key: string): string | undefined =>
        errors[key]?.[0];

    const handleSubmit = (e: React.FormEvent): void => {
        e.preventDefault();
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
            biaya_konsultasi_online: form.biaya_konsultasi_online
                ? Number(form.biaya_konsultasi_online)
                : null,
        };

        buat.mutate(input);
    };

    return (
        <>
            <PageHeader
                title="Buat akun dokter"
                description="Akun baru akan mulai dengan status verifikasi pending."
            />

            <form
                onSubmit={handleSubmit}
                className="flex max-w-xl flex-col gap-5"
            >
                <fieldset className="flex flex-col gap-4">
                    <legend className="text-sm font-semibold">Identitas akun</legend>

                    <Field label="Nama lengkap" error={fieldError('nama_lengkap')}>
                        <Input
                            value={form.nama_lengkap}
                            autoComplete="off"
                            onChange={(e) => { set('nama_lengkap', e.target.value); }}
                        />
                    </Field>

                    <Field label="Nomor telepon" error={fieldError('no_telepon')}>
                        <Input
                            value={form.no_telepon}
                            autoComplete="off"
                            onChange={(e) => { set('no_telepon', e.target.value); }}
                        />
                    </Field>

                    <Field label="Email (opsional)" error={fieldError('email')}>
                        <Input
                            type="email"
                            value={form.email}
                            autoComplete="off"
                            onChange={(e) => { set('email', e.target.value); }}
                        />
                    </Field>

                    <Field label="Kata sandi awal" error={fieldError('kata_sandi')}>
                        <Input
                            type="password"
                            value={form.kata_sandi}
                            autoComplete="new-password"
                            onChange={(e) => { set('kata_sandi', e.target.value); }}
                        />
                    </Field>
                </fieldset>

                <fieldset className="flex flex-col gap-4">
                    <legend className="text-sm font-semibold">Kredensial dokter</legend>

                    <Field label="Tipe dokter" error={fieldError('tipe')}>
                        <Select
                            value={form.tipe}
                            onValueChange={(v) => { set('tipe', v); }}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Pilih tipe" />
                            </SelectTrigger>

                            <SelectContent>
                                {TIPE_DOKTER.map((t) => (
                                    <SelectItem key={t.nilai} value={t.nilai}>
                                        {t.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>

                    <Field label="Nomor STR" error={fieldError('nomor_str')}>
                        <Input
                            value={form.nomor_str}
                            autoComplete="off"
                            onChange={(e) => { set('nomor_str', e.target.value); }}
                        />
                    </Field>

                    <Field label="STR berlaku sampai" error={fieldError('str_berlaku_sampai')}>
                        <Input
                            type="date"
                            value={form.str_berlaku_sampai}
                            onChange={(e) => { set('str_berlaku_sampai', e.target.value); }}
                        />
                    </Field>

                    <Field label="Nomor SIP (opsional)" error={fieldError('nomor_sip')}>
                        <Input
                            value={form.nomor_sip}
                            autoComplete="off"
                            onChange={(e) => { set('nomor_sip', e.target.value); }}
                        />
                    </Field>

                    <Field label="SIP berlaku sampai (opsional)" error={fieldError('sip_berlaku_sampai')}>
                        <Input
                            type="date"
                            value={form.sip_berlaku_sampai}
                            onChange={(e) => { set('sip_berlaku_sampai', e.target.value); }}
                        />
                    </Field>

                    <Field label="Pengalaman (tahun, opsional)" error={fieldError('pengalaman_tahun')}>
                        <Input
                            type="number"
                            min={0}
                            value={form.pengalaman_tahun}
                            onChange={(e) => { set('pengalaman_tahun', e.target.value); }}
                        />
                    </Field>

                    <Field label="Biaya konsultasi online (opsional)" error={fieldError('biaya_konsultasi_online')}>
                        <Input
                            type="number"
                            min={0}
                            value={form.biaya_konsultasi_online}
                            onChange={(e) => { set('biaya_konsultasi_online', e.target.value); }}
                        />
                    </Field>
                </fieldset>

                <div className="flex gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => { void navigate('/admin/dokter'); }}
                    >
                        Batal
                    </Button>

                    <Button type="submit" disabled={buat.isPending}>
                        Buat akun dokter
                    </Button>
                </div>
            </form>
        </>
    );
}

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label>{label}</Label>
            {children}
            {error ? <p className="text-destructive text-xs">{error}</p> : null}
        </div>
    );
}
