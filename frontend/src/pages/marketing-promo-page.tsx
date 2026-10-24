import { useQuery, useMutation } from '@tanstack/react-query';
import { z } from 'zod';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { Plus, Edit, Ban, FileText, CheckCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { SelectItem } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { PageHeader } from '@/components/layout/page-header';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState } from '@/components/states/error-state';
import { LoadingState, SkeletonRows } from '@/components/states/loading-state';
import { toast } from 'sonner';
import {
    promoListOptions,
    storePromoMutation,
    updatePromoMutation,
    destroyPromoMutation,
    type Promo,
    type PromoInput,
    type PromoTipeDiskon,
} from '@/lib/api/marketing';
import { Field, FieldInput, FieldSelect } from '@/components/form/field';


const schemaPromo = z.object({
    kode: z.string().trim().toUpperCase().min(1, 'Kode promo wajib diisi.').max(30, 'Kode maksimal 30 karakter.'),
    nama: z.string().trim().min(1, 'Nama promo wajib diisi.').max(150, 'Nama maksimal 150 karakter.'),
    tipe_diskon: z.enum(['persen', 'nominal', 'gratis_ongkir']),
    nilai: z.string().min(1, 'Nilai diskon wajib diisi.'),
    min_transaksi: z.string().optional().or(z.literal('')),
    maks_diskon: z.string().optional().or(z.literal('')),
    kuota_total: z.string().optional().or(z.literal('')),
    kuota_per_user: z.string().optional().or(z.literal('')),
    mulai_at: z.string().min(1, 'Tanggal mulai wajib diisi.'),
    selesai_at: z.string().min(1, 'Tanggal selesai wajib diisi.'),
    status_aktif: z.boolean().optional(),
});

type FormPromo = z.infer<typeof schemaPromo>;

function statusBadgePromo(aktif: boolean) {
    return aktif
        ? { variant: 'successSubtle', icon: CheckCircle, label: 'Aktif' }
        : { variant: 'destructiveSubtle', icon: Ban, label: 'Nonaktif' };
}

function formatTanggal(iso: string | null): string {
    if (!iso) return '-';
    try {
        const d = new Date(iso);
        return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    } catch {
        return '-';
    }
}

function formatRentang(mulai: string | null, selesai: string | null): string {
    if (!mulai || !selesai) return '-';
    return `${formatTanggal(mulai)} – ${formatTanggal(selesai)}`;
}

function formatNilai(tipe: PromoTipeDiskon | null, nilai: string | null): string {
    if (!nilai) return '-';
    if (tipe === 'persen') return `${nilai}%`;
    if (tipe === 'nominal') return `Rp ${nilai}`;
    return nilai;
}

function nilaiAwalPromo(row: Promo | null): FormPromo {
    if (!row) {
        return {
            kode: '',
            nama: '',
            tipe_diskon: 'persen',
            nilai: '',
            min_transaksi: '',
            maks_diskon: '',
            kuota_total: '',
            kuota_per_user: '',
            mulai_at: '',
            selesai_at: '',
            status_aktif: true,
        };
    }
    return {
        kode: row.kode,
        nama: row.nama,
        tipe_diskon: (row.tipe_diskon as PromoTipeDiskon) ?? 'persen',
        nilai: row.nilai ?? '',
        min_transaksi: row.min_transaksi ?? '',
        maks_diskon: row.maks_diskon ?? '',
        kuota_total: row.kuota_total !== null ? String(row.kuota_total) : '',
        kuota_per_user: String(row.kuota_per_user ?? ''),
        mulai_at: row.mulai_at ? new Date(row.mulai_at).toISOString().slice(0, 16) : '',
        selesai_at: row.selesai_at ? new Date(row.selesai_at).toISOString().slice(0, 16) : '',
        status_aktif: row.status_aktif ?? true,
    };
}

function keInputPromo(values: FormPromo): PromoInput {
    return {
        kode: values.kode,
        nama: values.nama,
        tipe_diskon: values.tipe_diskon,
        nilai: values.nilai,
        min_transaksi: values.min_transaksi || null,
        maks_diskon: values.maks_diskon || null,
        kuota_total: values.kuota_total === '' ? null : Number(values.kuota_total),
        kuota_per_user: values.kuota_per_user === '' ? 1 : Number(values.kuota_per_user),
        mulai_at: values.mulai_at,
        selesai_at: values.selesai_at,
        status_aktif: values.status_aktif ?? true,
    };
}

function fieldMsg(errors: Record<string, { message?: string } | undefined>, field: string): string[] {
    const e = errors[field];
    return e && e.message ? [e.message] : [];
}

export function MarketingPromoPage() {
    const { data, isLoading, error, refetch } = useQuery(promoListOptions());

    const [openCreate, setOpenCreate] = useState(false);
    const [openDetail, setOpenDetail] = useState(false);
    const [openNonaktif, setOpenNonaktif] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [detailRow, setDetailRow] = useState<Promo | null>(null);
    const [nonaktifId, setNonaktifId] = useState<number | null>(null);

    const create = useMutation(storePromoMutation());
    const update = useMutation(updatePromoMutation(0));
    const destroy = useMutation(destroyPromoMutation(0));

    const isEdit = editingId !== null;
    const row = data?.find((p) => p.id === editingId) ?? null;
    if (isEdit && !row) return null;

    const {
        register,
        handleSubmit,
        watch,
        setValue,
        formState: { errors, isSubmitting },
    } = useForm<FormPromo>({
        resolver: zodResolver(schemaPromo),
        defaultValues: nilaiAwalPromo(null),
    });

    const busy = isSubmitting || create.isPending || update.isPending || destroy.isPending;

    async function onSubmit(values: FormPromo): Promise<void> {
        try {
            if (isEdit && row) {
                await update.mutateAsync(keInputPromo(values));
                toast.success('Promo diperbarui.');
            } else {
                await create.mutateAsync(keInputPromo(values));
                toast.success('Promo dibuat.');
            }
            setOpenCreate(false);
            await refetch();
        } catch {
            // error ditangani oleh form
        }
    }

    async function nonaktifkan(): Promise<void> {
        if (!nonaktifId) return;
        try {
            await destroy.mutateAsync();
            toast.success('Promo dinonaktifkan.');
            setOpenNonaktif(false);
            setNonaktifId(null);
            await refetch();
        } catch {
            // error ditangani oleh komponen pemanggil
        }
    }

    return (
        <div className="flex flex-col gap-6">
            <PageHeader
                title="Kelola Promo"
                description="Buat dan kelola kode promo untuk pasien."
                action={
                    <Button
                        onClick={() => {
                            setEditingId(null);
                            setOpenCreate(true);
                        }}
                    >
                        <Plus size={20} /> Buat Promo
                    </Button>
                }
            />

            {isLoading && !data ? (
                <LoadingState label="Memuat daftar promo...">
                    <SkeletonRows rows={3} />
                </LoadingState>
            ) : data && data.length === 0 ? (
                <EmptyState
                    title="Belum ada promo"
                    description="Mulai dengan membuat promo pertama."
                    action={
                        <Button onClick={() => { setEditingId(null); setOpenCreate(true); }}>
                            Buat promo pertama
                        </Button>
                    }
                />
            ) : error ? (
                <ErrorState error={error} onRetry={refetch} title="Gagal memuat promo" />
            ) : data ? (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm border-border">
                        <thead>
                            <tr className="bg-muted text-muted-foreground">
                                <th className="text-left p-3 font-medium">Kode</th>
                                <th className="text-left p-3 font-medium">Judul</th>
                                <th className="text-left p-3 font-medium">Tipe Diskon</th>
                                <th className="text-right p-3 font-medium">Nilai</th>
                                <th className="text-left p-3 font-medium">Kuota</th>
                                <th className="text-left p-3 font-medium">Berlaku</th>
                                <th className="text-left p-3 font-medium">Status</th>
                                <th className="text-right p-3 font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((promo) => (
                                <tr key={promo.id} className="border-b border-border hover:bg-accent/50 transition-colors">
                                    <td className="p-3 text-foreground font-mono">{promo.kode}</td>
                                    <td className="p-3 text-foreground">{promo.nama}</td>
                                    <td className="p-3 text-muted-foreground">
                                        {promo.tipe_diskon === 'persen' ? 'Persen' : promo.tipe_diskon === 'nominal' ? 'Nominal' : 'Gratis Ongkir'}
                                    </td>
                                    <td className="p-3 text-right text-foreground tabular-nums">
                                        {formatNilai(promo.tipe_diskon, promo.nilai)}
                                    </td>
                                    <td className="p-3 text-muted-foreground">
                                        {promo.kuota_total === null ? 'Tak terbatas' : `${promo.kuota_total} tersisa`}
                                    </td>
                                    <td className="p-3 text-muted-foreground">
                                        {formatRentang(promo.mulai_at, promo.selesai_at)}
                                    </td>
                                    <td className="p-3">
                                        <span className="flex items-center gap-1.5">
                                            {(() => {
                                                const Ik = statusBadgePromo(promo.status_aktif).icon;
                                                return <Ik size={14} />;
                                            })()}
                                            {statusBadgePromo(promo.status_aktif).label}
                                        </span>
                                    </td>
                                    <td className="p-3 text-right">
                                        <div className="flex items-center gap-1">
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="size-10"
                                                onClick={() => {
                                                    setDetailRow(promo);
                                                    setOpenDetail(true);
                                                }}
                                                aria-label={`Detail ${promo.kode}`}
                                            >
                                                <FileText size={16} />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="size-10"
                                                onClick={() => {
                                                    setEditingId(promo.id);
                                                    setOpenCreate(true);
                                                }}
                                                aria-label={`Edit ${promo.kode}`}
                                            >
                                                <Edit size={16} />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="size-10 text-destructive"
                                                onClick={() => {
                                                    setNonaktifId(promo.id);
                                                    setOpenNonaktif(true);
                                                }}
                                                aria-label={`Nonaktifkan ${promo.kode}`}
                                            >
                                                <Ban size={16} />
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : null}

            {/* Dialog create/edit */}
            <Dialog open={openCreate} onOpenChange={(open) => { setOpenCreate(open); if (!open) { setEditingId(null); } }}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{isEdit ? 'Edit Promo' : 'Buat Promo'}</DialogTitle>
                        <DialogDescription>
                            {isEdit ? 'Perubahan akan disimpan dan terlihat di daftar promo.' : 'Isi data promo baru untuk pasien.'}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
                        <Field label="Kode Promo" errors={fieldMsg(errors, 'kode')} required>
                            <FieldInput placeholder="cth. RAMADHAN20" {...register('kode')} />
                        </Field>

                        <Field label="Judul Promo" errors={fieldMsg(errors, 'nama')} required>
                            <FieldInput placeholder="cth. Promo Ramadhan 20%" {...register('nama')} />
                        </Field>

                        <Field label="Tipe Diskon" errors={fieldMsg(errors, 'tipe_diskon')} required>
                            <FieldSelect
                                value={watch('tipe_diskon')}
                                onValueChange={(nilai) => setValue('tipe_diskon', nilai as PromoTipeDiskon, { shouldValidate: true })}
                                className="min-h-11 w-full"
                            >
                                <SelectItem value="persen">Persen (%)</SelectItem>
                                <SelectItem value="nominal">Nominal (Rupiah)</SelectItem>
                                <SelectItem value="gratis_ongkir">Gratis Ongkir</SelectItem>
                            </FieldSelect>
                        </Field>

                        <Field label="Nilai Diskon" errors={fieldMsg(errors, 'nilai')} required>
                            <FieldInput type="number" inputMode="decimal" placeholder="cth. 20" {...register('nilai')} />
                        </Field>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Min. Transaksi" errors={fieldMsg(errors, 'min_transaksi')}>
                                <FieldInput type="number" inputMode="decimal" placeholder="cth. 100000" {...register('min_transaksi')} />
                            </Field>

                            <Field label="Maks. Diskon" errors={fieldMsg(errors, 'maks_diskon')}>
                                <FieldInput type="number" inputMode="decimal" placeholder="cth. 50000" {...register('maks_diskon')} />
                            </Field>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Kuota Total" errors={fieldMsg(errors, 'kuota_total')}>
                                <FieldInput type="number" inputMode="numeric" placeholder="Kosongkan untuk tak terbatas" {...register('kuota_total')} />
                            </Field>

                            <Field label="Kuota per User" errors={fieldMsg(errors, 'kuota_per_user')}>
                                <FieldInput type="number" inputMode="numeric" placeholder="cth. 1" {...register('kuota_per_user')} />
                            </Field>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Berlaku Dari" errors={fieldMsg(errors, 'mulai_at')} required>
                                <FieldInput type="datetime-local" {...register('mulai_at')} />
                            </Field>

                            <Field label="Berlaku Sampai" errors={fieldMsg(errors, 'selesai_at')} required>
                                <FieldInput type="datetime-local" {...register('selesai_at')} />
                            </Field>
                        </div>

                        <Field label="Aktif" errors={[]} required={false}>
                            <Checkbox
                                {...register('status_aktif')}
                                defaultChecked
                                className="peer h-5 w-5 shrink-0 rounded-sm"
                            />
                        </Field>

                        <DialogFooter>
                            <Button type="button" variant="outline" className="min-h-11" onClick={() => { setOpenCreate(false); setEditingId(null); }}>
                                Batal
                            </Button>
                            <Button type="submit" className="min-h-11" disabled={busy}>
                                {busy ? 'Menyimpan...' : isEdit ? 'Simpan perubahan' : 'Simpan promo'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog detail */}
            <Dialog open={openDetail} onOpenChange={setOpenDetail}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Detail Promo</DialogTitle>
                    </DialogHeader>
                    {detailRow ? (
                        <div className="flex flex-col gap-4 py-4">
                            <div className="flex items-start gap-3">
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    width="1em"
                                    height="1em"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    className="size-6 shrink-0 text-muted-foreground"
                                >
                                    <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0l-2.83-2.83a2 2 0 0 1 0-2.83l7.17-7.17a2 2 0 0 1 2.83 0l2.83 2.83a2 2 0 0 1 0 2.83z" />
                                    <path d="M11.5 11.5a2 2 0 1 1-4 0 2 2 0 0 1 4 0z" />
                                </svg>
                                <div className="flex flex-col">
                                    <h2 className="text-lg font-semibold text-foreground">{detailRow.kode}</h2>
                                    <p className="text-sm text-foreground">{detailRow.nama}</p>
                                    <span className="flex items-center gap-1.5 text-sm">
                                        {(() => {
                                            const Ik = statusBadgePromo(detailRow.status_aktif).icon;
                                            return <Ik size={14} />;
                                        })()}
                                        {statusBadgePromo(detailRow.status_aktif).label}
                                    </span>
                                </div>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Tipe Diskon</p>
                                <p className="text-sm text-foreground">
                                    {detailRow.tipe_diskon === 'persen' ? 'Persen' : detailRow.tipe_diskon === 'nominal' ? 'Nominal' : 'Gratis Ongkir'}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Nilai</p>
                                <p className="text-sm text-foreground tabular-nums">{formatNilai(detailRow.tipe_diskon, detailRow.nilai)}</p>
                            </div>
                            {detailRow.min_transaksi && (
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">Min. Transaksi</p>
                                    <p className="text-sm text-foreground tabular-nums">Rp {detailRow.min_transaksi}</p>
                                </div>
                            )}
                            {detailRow.maks_diskon && (
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">Maks. Diskon</p>
                                    <p className="text-sm text-foreground tabular-nums">Rp {detailRow.maks_diskon}</p>
                                </div>
                            )}
                            <div className="grid gap-4 sm:grid-cols-2 text-sm text-muted-foreground">
                                <div>
                                    <p className="text-xs text-muted-foreground/70">Kuota Total</p>
                                    <p className="text-foreground tabular-nums">{detailRow.kuota_total === null ? 'Tak terbatas' : String(detailRow.kuota_total)}</p>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground/70">Kuota per User</p>
                                    <p className="text-foreground tabular-nums">{String(detailRow.kuota_per_user)}</p>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground/70">Berlaku Dari</p>
                                    <p className="text-foreground tabular-nums">{formatTanggal(detailRow.mulai_at)}</p>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground/70">Berlaku Sampai</p>
                                    <p className="text-foreground tabular-nums">{formatTanggal(detailRow.selesai_at)}</p>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground/70">Dibuat</p>
                                    <p className="text-foreground tabular-nums">{formatTanggal(detailRow.dibuat_at)}</p>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground/70">Diubah</p>
                                    <p className="text-foreground tabular-nums">{formatTanggal(detailRow.diubah_at)}</p>
                                </div>
                            </div>
                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() => {
                                        setEditingId(detailRow.id);
                                        setOpenCreate(true);
                                        setOpenDetail(false);
                                    }}
                                >
                                    <Edit size={16} /> Edit
                                </Button>
                                {detailRow.status_aktif ? (
                                    <Button
                                        variant="outline"
                                        className="min-h-11 text-destructive"
                                        onClick={() => {
                                            setNonaktifId(detailRow.id);
                                            setOpenDetail(false);
                                            setOpenNonaktif(true);
                                        }}
                                    >
                                        <Ban size={16} /> Nonaktifkan
                                    </Button>
                                ) : null}
                                <Button
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() => { setOpenDetail(false); setDetailRow(null); }}
                                >
                                    Kembali
                                </Button>
                            </DialogFooter>
                        </div>
                    ) : null}
                </DialogContent>
            </Dialog>

            {/* Dialog konfirmasi nonaktifkan */}
            <Dialog open={openNonaktif} onOpenChange={(open) => { setOpenNonaktif(open); if (!open) setNonaktifId(null); }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Nonaktifkan promo?</DialogTitle>
                        <DialogDescription>
                            Promo ini tidak bisa lagi digunakan oleh pasien. Tindakan ini dapat diubah kembali.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" className="min-h-11" onClick={() => { setOpenNonaktif(false); setNonaktifId(null); }}>
                            Batal
                        </Button>
                        <Button variant="destructive" className="min-h-11" onClick={nonaktifkan} disabled={destroy.isPending}>
                            {destroy.isPending ? 'Mengnonaktifkan...' : 'Nonaktifkan promo'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
