import { useQuery, useMutation } from '@tanstack/react-query';
import { z } from 'zod';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState, useEffect } from 'react';
import { Plus, Edit, Archive, FileText } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { SelectItem } from '@/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { PageHeader } from '@/components/layout/page-header';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState } from '@/components/states/error-state';
import { LoadingState, SkeletonRows } from '@/components/states/loading-state';
import { toast } from 'sonner';
import {
    artikelListOptions,
    storeArtikelMutation,
    updateArtikelMutation,
    destroyArtikelMutation,
    type Artikel,
    type ArtikelInput,
    type ArtikelStatus,
} from '@/lib/api/marketing';
import { Field, FieldInput, FieldSelect, FieldTextarea } from '@/components/form/field';


const schemaArtikel = z.object({
    kategori_id: z.string().min(1, 'Kategori wajib dipilih.'),
    judul: z.string().trim().min(3, 'Judul minimal 3 karakter.').max(200, 'Judul maksimal 200 karakter.'),
    slug: z.string().trim().min(1, 'Slug wajib diisi.').max(255, 'Slug maksimal 255 karakter.'),
    ringkasan: z.string().trim().max(500, 'Ringkasan maksimal 500 karakter.').optional().or(z.literal('')),
    konten: z.string().trim().min(1, 'Konten wajib diisi.'),
    cover_url: z.string().trim().max(500, 'URL sampul maksimal 500 karakter.').optional().or(z.literal('')),
    status: z.enum(['draft', 'review', 'terbit', 'arsip']),
});

type FormArtikel = z.infer<typeof schemaArtikel>;

function statusBadge(status: ArtikelStatus) {
    const config: Record<ArtikelStatus, { variant: 'secondary' | 'successSubtle' | 'warningSubtle' | 'destructiveSubtle'; icon: React.ElementType; label: string }> = {
        draft: { variant: 'secondary', icon: Edit, label: 'Draft' },
        review: { variant: 'warningSubtle', icon: FileText, label: 'Review' },
        terbit: { variant: 'successSubtle', icon: FileText, label: 'Terbit' },
        arsip: { variant: 'destructiveSubtle', icon: Archive, label: 'Arsip' },
    };
    return config[status];
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

function nilaiAwalArtikel(row: Artikel | null): FormArtikel {
    return {
        kategori_id: String(row?.kategori_id ?? ''),
        judul: row?.judul ?? '',
        slug: row?.slug ?? '',
        ringkasan: row?.ringkasan ?? '',
        konten: row?.konten ?? '',
        cover_url: row?.cover_url ?? '',
        status: row?.status ?? 'draft',
    };
}

function keInputArtikel(values: FormArtikel): ArtikelInput {
    return {
        kategori_id: Number(values.kategori_id),
        judul: values.judul,
        slug: values.slug,
        ringkasan: values.ringkasan || null,
        konten: values.konten,
        cover_url: values.cover_url || null,
        status: values.status,
    };
}

function fieldMsg(errors: Record<string, { message?: string } | undefined>, field: string): string[] {
    const e = errors[field];
    return e && e.message ? [e.message] : [];
}

function generateSlug(judul: string): string {
    return judul
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 255);
}

export function MarketingArtikelPage() {
    const { data, isLoading, error, refetch } = useQuery(artikelListOptions());

    const [openCreate, setOpenCreate] = useState(false);
    const [openDetail, setOpenDetail] = useState(false);
    const [openArsip, setOpenArsip] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [detailRow, setDetailRow] = useState<Artikel | null>(null);
    const [arsipId, setArsipId] = useState<number | null>(null);

    const create = useMutation(storeArtikelMutation());
    const update = useMutation(updateArtikelMutation(0));
    const destroy = useMutation(destroyArtikelMutation(0));

    const isEdit = editingId !== null;
    const row = data?.find((a) => a.id === editingId) ?? null;

    const {
        register,
        handleSubmit,
        watch,
        setValue,
        formState: { errors, isSubmitting },
    } = useForm<FormArtikel>({
        resolver: zodResolver(schemaArtikel),
        defaultValues: nilaiAwalArtikel(null),
    });

    const busy = isSubmitting || create.isPending || update.isPending || destroy.isPending;

    const judulWatch = watch('judul');
    const slugWatch = watch('slug');

    useEffect(() => {
        // Hasilkan slug otomatis dari judul saat membuat baru dan slug masih kosong.
        // Saat edit, jangan menimpa slug yang sudah ada.
        if (!isEdit && judulWatch && !slugWatch) {
            setValue('slug', generateSlug(judulWatch), { shouldValidate: true });
        }
    }, [judulWatch, slugWatch, isEdit, setValue]);

    async function onSubmit(values: FormArtikel): Promise<void> {
        try {
            if (isEdit && row) {
                await update.mutateAsync(keInputArtikel(values));
                toast.success('Artikel diperbarui.');
            } else {
                await create.mutateAsync(keInputArtikel(values));
                toast.success('Artikel dibuat.');
            }
            setOpenCreate(false);
            await refetch();
        } catch {
            // error ditangani oleh form
        }
    }

    async function arsipkan(): Promise<void> {
        if (!arsipId) return;
        try {
            await destroy.mutateAsync();
            toast.success('Artikel diarsipkan.');
            setOpenArsip(false);
            setArsipId(null);
            await refetch();
        } catch {
            // error ditangani oleh komponen pemanggil
        }
    }

    return (
        <div className="flex flex-col gap-6">
            <PageHeader
                title="Kelola Artikel"
                description="Buat dan kelola artikel kesehatan untuk halaman publik."
                action={
                    <Button
                        onClick={() => {
                            setEditingId(null);
                            setOpenCreate(true);
                        }}
                    >
                        <Plus size={20} /> Buat Artikel
                    </Button>
                }
            />

            {isLoading && !data ? (
                <LoadingState label="Memuat daftar artikel...">
                    <SkeletonRows rows={3} />
                </LoadingState>
            ) : data && data.length === 0 ? (
                <EmptyState
                    title="Belum ada artikel"
                    description="Mulai dengan membuat artikel pertama."
                    action={
                        <Button onClick={() => { setEditingId(null); setOpenCreate(true); }}>
                            Buat artikel pertama
                        </Button>
                    }
                />
            ) : error ? (
                <ErrorState error={error} onRetry={refetch} title="Gagal memuat artikel" />
            ) : data ? (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm border-border">
                        <thead>
                            <tr className="bg-muted text-muted-foreground">
                                <th className="text-left p-3 font-medium">Judul</th>
                                <th className="text-left p-3 font-medium">Status</th>
                                <th className="text-left p-3 font-medium">Kategori</th>
                                <th className="text-left p-3 font-medium">Penulis</th>
                                <th className="text-left p-3 font-medium">Diperbarui</th>
                                <th className="text-right p-3 font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((artikel) => (
                                <tr key={artikel.id} className="border-b border-border hover:bg-accent/50 transition-colors">
                                    <td className="p-3 text-foreground">{artikel.judul}</td>
                                    <td className="p-3">
                                        <span className="flex items-center gap-1.5">
                                            {(() => {
                                                const Ik = statusBadge(artikel.status).icon;
                                                return <Ik size={14} />;
                                            })()}
                                            {statusBadge(artikel.status).label}
                                        </span>
                                    </td>
                                    <td className="p-3 text-muted-foreground">
                                        {artikel.kategori?.nama ?? '-'}
                                    </td>
                                    <td className="p-3 text-muted-foreground">{artikel.penulis_user_id ?? '-'}</td>
                                    <td className="p-3 text-muted-foreground tabular-nums">
                                        {formatTanggal(artikel.diubah_at)}
                                    </td>
                                    <td className="p-3 text-right">
                                        <div className="flex items-center gap-1">
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="size-10"
                                                onClick={() => {
                                                    setDetailRow(artikel);
                                                    setOpenDetail(true);
                                                }}
                                                aria-label={`Detail ${artikel.judul}`}
                                            >
                                                <FileText size={16} />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="size-10"
                                                onClick={() => {
                                                    setEditingId(artikel.id);
                                                    setOpenCreate(true);
                                                }}
                                                aria-label={`Edit ${artikel.judul}`}
                                            >
                                                <Edit size={16} />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="size-10 text-destructive"
                                                onClick={() => {
                                                    setArsipId(artikel.id);
                                                    setOpenArsip(true);
                                                }}
                                                aria-label={`Arsipkan ${artikel.judul}`}
                                            >
                                                <Archive size={16} />
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
                        <DialogTitle>{isEdit ? 'Edit Artikel' : 'Buat Artikel'}</DialogTitle>
                        <DialogDescription>
                            {isEdit ? 'Perubahan akan disimpan dan terlihat di daftar artikel.' : 'Isi data artikel baru untuk halaman publik.'}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
                        <Field label="Kategori" errors={fieldMsg(errors, 'kategori_id')} required>
                            <FieldSelect
                                value={watch('kategori_id')}
                                onValueChange={(nilai) => setValue('kategori_id', nilai, { shouldValidate: true })}
                                className="min-h-11 w-full"
                                placeholder="Pilih kategori"
                            >
                                <SelectItem value="1">Kesehatan Umum</SelectItem>
                                <SelectItem value="2">Nutrisi</SelectItem>
                                <SelectItem value="3">Kesehatan Mental</SelectItem>
                            </FieldSelect>
                        </Field>

                        <Field label="Judul" errors={fieldMsg(errors, 'judul')} required>
                            <FieldInput placeholder="cth. Manfaat Olahraga untuk Kesehatan" {...register('judul')} />
                        </Field>

                        <Field label="Slug" errors={fieldMsg(errors, 'slug')} required>
                            <FieldInput placeholder="cth. manfaat-olahraga-kesehatan" {...register('slug')} />
                        </Field>

                        <Field label="Ringkasan" errors={fieldMsg(errors, 'ringkasan')}>
                            <FieldTextarea rows={3} placeholder="Ringkasan singkat artikel..." {...register('ringkasan')} />
                        </Field>

                        <Field label="Konten" errors={fieldMsg(errors, 'konten')} required>
                            <FieldTextarea rows={6} placeholder="Isi lengkap artikel..." {...register('konten')} />
                        </Field>

                        <Field label="URL Sampul" errors={fieldMsg(errors, 'cover_url')}>
                            <FieldInput placeholder="cth. https://sehatly.test/storage/cover/artikel.jpg" {...register('cover_url')} />
                        </Field>

                        <Field label="Status" errors={fieldMsg(errors, 'status')} required>
                            <FieldSelect
                                value={watch('status')}
                                onValueChange={(nilai) => setValue('status', nilai as ArtikelStatus, { shouldValidate: true })}
                                className="min-h-11 w-full"
                            >
                                <SelectItem value="draft">Draft</SelectItem>
                                <SelectItem value="review">Review</SelectItem>
                                <SelectItem value="terbit">Terbit</SelectItem>
                                <SelectItem value="arsip">Arsip</SelectItem>
                            </FieldSelect>
                        </Field>

                        <DialogFooter>
                            <Button type="button" variant="outline" className="min-h-11" onClick={() => { setOpenCreate(false); setEditingId(null); }}>
                                Batal
                            </Button>
                            <Button type="submit" className="min-h-11" disabled={busy}>
                                {busy ? 'Menyimpan...' : isEdit ? 'Simpan perubahan' : 'Simpan artikel'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog detail */}
            <Dialog open={openDetail} onOpenChange={setOpenDetail}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Detail Artikel</DialogTitle>
                    </DialogHeader>
                    {detailRow ? (
                        <div className="flex flex-col gap-4 py-4">
                            <div className="flex items-start gap-3">
                                <FileText className="size-6 shrink-0 text-muted-foreground" />
                                <div className="flex flex-col">
                                    <h2 className="text-lg font-semibold text-foreground">{detailRow.judul}</h2>
                                    <span className="flex items-center gap-1.5 text-sm">
                                        {(() => {
                                            const Ik = statusBadge(detailRow.status).icon;
                                            return <Ik size={14} />;
                                        })()}
                                        {statusBadge(detailRow.status).label}
                                    </span>
                                </div>
                            </div>
                            {detailRow.ringkasan && (
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">Ringkasan</p>
                                    <p className="text-sm text-foreground">{detailRow.ringkasan}</p>
                                </div>
                            )}
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Konten</p>
                                <p className="text-sm text-foreground whitespace-pre-wrap break-words">{detailRow.konten}</p>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2 text-sm text-muted-foreground">
                                <div>
                                    <p className="text-xs text-muted-foreground/70">Kategori</p>
                                    <p className="text-foreground">{detailRow.kategori?.nama ?? '-'}</p>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground/70">Penulis</p>
                                    <p className="text-foreground">{detailRow.penulis_user_id ?? '-'}</p>
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
                                <Button
                                    variant="outline"
                                    className="min-h-11 text-destructive"
                                    onClick={() => {
                                        setArsipId(detailRow.id);
                                        setOpenDetail(false);
                                        setOpenArsip(true);
                                    }}
                                >
                                    <Archive size={16} /> Arsipkan
                                </Button>
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

            {/* Dialog konfirmasi arsip */}
            <Dialog open={openArsip} onOpenChange={(open) => { setOpenArsip(open); if (!open) setArsipId(null); }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Arsip artikel?</DialogTitle>
                        <DialogDescription>
                            Artikel ini akan ditandai arsip dan tidak lagi tampil di halaman umum. Tindakan ini dapat dibatalkan.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" className="min-h-11" onClick={() => { setOpenArsip(false); setArsipId(null); }}>
                            Batal
                        </Button>
                        <Button variant="destructive" className="min-h-11" onClick={arsipkan} disabled={destroy.isPending}>
                            {destroy.isPending ? 'Mengarsipkan...' : 'Arsipkan artikel'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
