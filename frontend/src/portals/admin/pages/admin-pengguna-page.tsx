import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { CheckCircle, KeyRound, Power, RefreshCw, Search, ShieldAlert, ShieldCheck, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import {
    adminPenggunaOptions,
    adminRingkasanOptions,
    batalkanUndanganMutation,
    kirimUlangUndanganMutation,
    resetSandiPenggunaMutation,
    setujuiPenggunaMutation,
    tolakPenggunaMutation,
    ubahStatusPenggunaMutation,
    type AdminPengguna,
    type AdminPenggunaFilter,
    type TipeFilterPengguna,
} from '@/lib/api/admin';
import { meOptions } from '@/lib/api/me';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { AdminErrorState, AdminGate } from '@/portals/admin/features/admin/admin-gate';
import { labelStatusAkun, labelTipeAkun } from '@/portals/admin/features/admin/format-admin';
import { formatWaktu } from '@/lib/format';
import { pesanRingkas } from '@/portals/admin/features/admin/pesan';
import { PageHeader } from '@/shared/components/layout/page-header';
import { Pagination } from '@/shared/components/layout/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldTextarea } from '@/shared/components/form/field';
import {
    Dialog, DialogContent, DialogDescription,
    DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import { SkeletonRows } from '@/shared/components/states/loading-state';

const PER_HALAMAN = 20;
type Tab = 'semua' | TipeFilterPengguna;

const STATUS_TAB: ReadonlyArray<{ nilai: string; label: string }> = [
    { nilai: 'semua', label: 'Semua' },
    { nilai: 'menunggu_persetujuan', label: 'Menunggu persetujuan' },
    { nilai: 'diundang', label: 'Diundang' },
    { nilai: 'aktif', label: 'Aktif' },
    { nilai: 'nonaktif', label: 'Nonaktif' },
    { nilai: 'ditolak', label: 'Ditolak' },
];

const TAB: ReadonlyArray<{ nilai: Tab; label: string }> = [
    { nilai: 'semua', label: 'Semua' },
    { nilai: 'pasien', label: 'Pasien' },
    { nilai: 'apoteker', label: 'Apoteker' },
    { nilai: 'kurir', label: 'Kurir' },
    { nilai: 'marketing', label: 'Marketing' },
    { nilai: 'admin', label: 'Admin' },
    { nilai: 'superadmin', label: 'Superadmin' },
];

const TIPE_SAHIH: ReadonlySet<string> = new Set([
    ...TAB.map((t) => t.nilai),
    'staf', 'dokter', 'perawat',
]);

const TIPE_UNDANG: ReadonlySet<string> = new Set(['dokter', 'apoteker', 'kurir', 'admin']);

function labelPilihan(tipe: TipeFilterPengguna): string {
    return tipe === 'staf' ? 'Staf' : labelTipeAkun(tipe);
}

const STAT_CARDS = [
    { key: 'total',    label: 'Total Akun',         cls: 'bg-primary/10 text-primary' },
    { key: 'aktif',    label: 'Akun Aktif',          cls: 'bg-green-100 text-green-700' },
    { key: 'diundang', label: 'Menunggu Aktivasi',   cls: 'bg-amber-100 text-amber-700' },
    { key: 'nonaktif', label: 'Nonaktif',            cls: 'bg-red-100 text-red-700' },
] as const;

export function AdminPenggunaPage() {
    const online = useOnlineStatus();
    const [searchParams, setSearchParams] = useSearchParams();

    const tipeMentah = searchParams.get('tipe');
    const tipeAktif: TipeFilterPengguna | null =
        tipeMentah !== null && TIPE_SAHIH.has(tipeMentah)
            ? (tipeMentah as TipeFilterPengguna)
            : null;

    useDocumentTitle(tipeAktif ? `Manajemen Akun · ${labelPilihan(tipeAktif)}` : 'Manajemen Akun');

    const qParam      = searchParams.get('q') ?? '';
    const statusParam = searchParams.get('status');
    const statusAktif = statusParam && STATUS_TAB.some(t => t.nilai === statusParam && t.nilai !== 'semua')
        ? statusParam : undefined;
    const halaman = Math.max(1, Number(searchParams.get('page') ?? '1') || 1);

    const [qDraft, setQDraft] = useState(qParam);
    useEffect(() => { setQDraft(qParam); }, [qParam]);

    const tulis = (next: Record<string, string | null>) => {
        const params = new URLSearchParams(searchParams);
        for (const [k, v] of Object.entries(next)) {
            if (v === null || v === '') params.delete(k); else params.set(k, v);
        }
        setSearchParams(params, { replace: true });
    };

    const filter: AdminPenggunaFilter = {
        tipe: tipeAktif ?? undefined,
        status: statusAktif as AdminPenggunaFilter['status'],
        q: qParam || undefined,
        page: halaman,
        per_page: PER_HALAMAN,
    };

    const daftar   = useQuery(adminPenggunaOptions(filter));
    const ringkasan = useQuery(adminRingkasanOptions());
    const counts   = ringkasan.data?.data.ringkasan;

    const me           = useQuery(meOptions());
    const isSuperadmin = me.data?.data.user?.tipe === 'superadmin';
    const canInvite    =
        tipeAktif !== null &&
        TIPE_UNDANG.has(tipeAktif) &&
        (tipeAktif !== 'admin' || isSuperadmin);

    const tekan = (nilai: Tab) => nilai === 'semua' ? tipeAktif === null : tipeAktif === nilai;

    const sum = (key: string) =>
        counts ? Object.values(counts).reduce((s: number, v: Record<string, number>) => s + (v[key] ?? 0), 0) : null;

    const statValues: Record<string, number | null> = {
        total:    sum('total'),
        aktif:    sum('aktif'),
        diundang: sum('diundang'),
        nonaktif: sum('nonaktif'),
    };

    // --- mutations ---
    const gantiStatus = useMutation({
        ...ubahStatusPenggunaMutation(),
        onSuccess: (_r, input) => toast.success(input.status === 'aktif' ? 'Akun diaktifkan.' : 'Akun dinonaktifkan.'),
        onError: (e) => toast.error(pesanRingkas(e)),
    });
    const resetSandi = useMutation({
        ...resetSandiPenggunaMutation(),
        onSuccess: (r) => { setPasswordBaru(r.data.password_baru); setNamaReset(r.data.pengguna.nama_lengkap); },
        onError: (e) => toast.error(pesanRingkas(e)),
    });
    const setujui = useMutation({
        ...setujuiPenggunaMutation(),
        onSuccess: () => toast.success('Akun disetujui.'),
        onError: (e) => toast.error(pesanRingkas(e)),
    });
    const tolak = useMutation({
        ...tolakPenggunaMutation(),
        onSuccess: () => toast.success('Akun ditolak.'),
        onError: (e) => toast.error(pesanRingkas(e)),
    });
    const kirimUlang = useMutation({
        ...kirimUlangUndanganMutation(),
        onSuccess: (r) => { setTokenUndangan(r.data.token); setNamaUndangan(r.data.pengguna.nama_lengkap); },
        onError: (e) => toast.error(pesanRingkas(e)),
    });
    const batalkan = useMutation({
        ...batalkanUndanganMutation(),
        onSuccess: () => toast.success('Undangan dibatalkan.'),
        onError: (e) => toast.error(pesanRingkas(e)),
    });

    // --- dialog state ---
    const [kirimUlangTarget, setKirimUlangTarget] = useState<{ undanganId: number; nama: string } | null>(null);
    const [batalkanTarget,   setBatalkanTarget]   = useState<{ undanganId: number; nama: string } | null>(null);
    const [konfirmasi,       setKonfirmasi]       = useState<{ id: number; nama: string; tujuan: 'aktif' | 'nonaktif' } | null>(null);
    const [resetMinta,       setResetMinta]       = useState<{ id: number; nama: string } | null>(null);
    const [passwordBaru,     setPasswordBaru]     = useState<string | null>(null);
    const [namaReset,        setNamaReset]        = useState('');
    const [tolakTarget,      setTolakTarget]      = useState<{ id: number; nama: string } | null>(null);
    const [alasanTolak,      setAlasanTolak]      = useState('');
    const [tokenUndangan,    setTokenUndangan]    = useState<string | null>(null);
    const [namaUndangan,     setNamaUndangan]     = useState('');

    const rows = daftar.data?.data.pengguna ?? [];
    const meta = daftar.data?.meta;
    const muatUlang = () => { void daftar.refetch(); };

    // --- row ---
    const baris = (akun: AdminPengguna) => {
        const bolehNonaktif = akun.status === 'aktif';
        const menunggu      = akun.status === 'menunggu_persetujuan';
        const diundang      = akun.status === 'diundang';
        const inisial       = akun.nama_lengkap.trim().charAt(0).toUpperCase() || '?';
        return (
            <tr key={akun.id} data-testid="admin-baris" className="border-b last:border-0 hover:bg-muted/40">
                <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                        <div aria-hidden="true" className="grid size-8 shrink-0 place-items-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                            {inisial}
                        </div>
                        <div>
                            <p className="font-medium text-sm">{akun.nama_lengkap}</p>
                            <p className="text-xs text-muted-foreground">
                                {[akun.no_telepon, akun.email].filter(Boolean).join(' · ')}
                            </p>
                        </div>
                    </div>
                </td>
                <td className="px-4 py-3">
                    <Badge variant="outline">{labelTipeAkun(akun.tipe)}</Badge>
                </td>
                <td className="px-4 py-3">
                    <span className={`inline-flex items-center gap-1 text-xs ${akun.telepon_terverifikasi ? 'text-green-600' : 'text-muted-foreground'}`}>
                        {akun.telepon_terverifikasi ? <ShieldCheck className="size-3.5" /> : <ShieldAlert className="size-3.5" />}
                        {akun.telepon_terverifikasi ? 'Terverifikasi' : 'Belum'}
                    </span>
                </td>
                <td className="px-4 py-3 text-xs text-muted-foreground">
                    {akun.last_login_at ? formatWaktu(akun.last_login_at) : 'Belum pernah'}
                </td>
                <td className="px-4 py-3">
                    <Badge variant={akun.status === 'aktif' ? 'default' : akun.status === 'menunggu_persetujuan' ? 'secondary' : 'outline'}>
                        {labelStatusAkun(akun.status)}
                    </Badge>
                </td>
                <td className="px-4 py-3">
                    <div data-testid="admin-aksi" className="flex items-center justify-end gap-1">
                        {menunggu ? (
                            <>
                                <Button type="button" size="sm" disabled={setujui.isPending} onClick={() => setujui.mutate(akun.id)}>
                                    <CheckCircle className="size-4" /> Setujui
                                </Button>
                                <Button type="button" variant="destructive" size="sm" disabled={tolak.isPending}
                                    onClick={() => setTolakTarget({ id: akun.id, nama: akun.nama_lengkap })}>
                                    <XCircle className="size-4" /> Tolak
                                </Button>
                            </>
                        ) : diundang ? (
                            <>
                                <Button type="button" variant="outline" size="icon" title="Kirim ulang undangan"
                                    disabled={kirimUlang.isPending || akun.undangan_id === null}
                                    onClick={() => akun.undangan_id !== null && setKirimUlangTarget({ undanganId: akun.undangan_id, nama: akun.nama_lengkap })}>
                                    <RefreshCw className="size-4" />
                                </Button>
                                <Button type="button" variant="outline" size="icon" title="Batalkan undangan"
                                    className="text-destructive hover:text-destructive"
                                    disabled={batalkan.isPending || akun.undangan_id === null}
                                    onClick={() => akun.undangan_id !== null && setBatalkanTarget({ undanganId: akun.undangan_id, nama: akun.nama_lengkap })}>
                                    <XCircle className="size-4" />
                                </Button>
                            </>
                        ) : (
                            <>
                                <Button type="button" variant="outline" size="icon" title="Reset sandi"
                                    aria-label={`Reset sandi ${akun.nama_lengkap}`}
                                    disabled={!online || resetSandi.isPending}
                                    onClick={() => setResetMinta({ id: akun.id, nama: akun.nama_lengkap })}>
                                    <KeyRound className="size-4" />
                                </Button>
                                <Button type="button" variant="outline" size="icon"
                                    title={bolehNonaktif ? 'Nonaktifkan' : 'Aktifkan'}
                                    aria-label={`${bolehNonaktif ? 'Nonaktifkan' : 'Aktifkan'} ${akun.nama_lengkap}`}
                                    className={bolehNonaktif ? 'text-destructive hover:text-destructive' : 'text-green-600 hover:text-green-600'}
                                    disabled={!online || gantiStatus.isPending}
                                    onClick={() => setKonfirmasi({ id: akun.id, nama: akun.nama_lengkap, tujuan: bolehNonaktif ? 'nonaktif' : 'aktif' })}>
                                    <Power className="size-4" />
                                </Button>
                            </>
                        )}
                    </div>
                </td>
            </tr>
        );
    };

    return (
        <AdminGate>
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <PageHeader title="Akun" description="Telusuri, aktifkan, nonaktifkan, atau atur ulang sandi akun." />
                    {canInvite && (
                        <Button asChild size="sm">
                            <Link to={`/admin/pengguna/undang?tipe=${tipeAktif ?? ''}`}>
                                Undang {tipeAktif ? labelTipeAkun(tipeAktif as TipeFilterPengguna) : 'Pengguna'}
                            </Link>
                        </Button>
                    )}
                </div>

                {/* 4 stat cards */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {STAT_CARDS.map(({ key, label, cls }) => (
                        <div key={key} className="rounded-xl border bg-background p-4 flex items-center gap-3">
                            <div className={`grid size-10 shrink-0 place-items-center rounded-lg text-sm font-bold ${cls}`}>
                                {statValues[key] ?? '—'}
                            </div>
                            <p className="text-xs text-muted-foreground">{label}</p>
                        </div>
                    ))}
                </div>

                {/* tab strip */}
                <div role="tablist" aria-label="Role akun" className="flex gap-1 overflow-x-auto border-b">
                    {TAB.map((item) => {
                        const aktif = tekan(item.nilai);
                        const total = item.nilai === 'semua'
                            ? statValues['total']
                            : (counts?.[item.nilai]?.total ?? null);
                        return (
                            <button key={item.nilai} type="button" role="tab" aria-selected={aktif}
                                className={['inline-flex shrink-0 items-center gap-2 border-b-2 px-3.5 py-2.5 text-sm font-medium whitespace-nowrap transition-colors',
                                    aktif ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'].join(' ')}
                                onClick={() => tulis({ tipe: item.nilai === 'semua' ? null : item.nilai, status: null, page: null })}>
                                {item.label}
                                {total != null && (
                                    <span className={['rounded-full px-2 py-0.5 text-xs font-semibold',
                                        aktif ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'].join(' ')}>
                                        {total}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>

                {/* toolbar */}
                <div className="flex flex-wrap items-center gap-2">
                    <form className="flex flex-1 min-w-[200px] items-center gap-1 rounded-md border px-3 focus-within:ring-2 focus-within:ring-primary/30"
                        onSubmit={(e) => { e.preventDefault(); tulis({ q: qDraft.trim() || null, page: null }); }}>
                        <Search className="h-4 w-4 shrink-0 text-muted-foreground" />
                        <input data-testid="admin-cari"
                            className="h-10 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                            placeholder="Cari nama atau nomor" value={qDraft}
                            onChange={(e) => setQDraft(e.target.value)} />
                    </form>
                    <select className="h-10 rounded-md border px-3 text-sm" aria-label="Filter status"
                        value={statusAktif ?? ''}
                        onChange={(e) => tulis({ status: e.target.value || null, page: null })}>
                        <option value="">Semua status</option>
                        {STATUS_TAB.filter(t => t.nilai !== 'semua').map(t => (
                            <option key={t.nilai} value={t.nilai}>{t.label}</option>
                        ))}
                    </select>

                </div>

                {/* table */}
                <div className="space-y-4">
                    {daftar.isPending ? (
                        <div data-testid="admin-muat" className="space-y-2"><SkeletonRows rows={6} /></div>
                    ) : daftar.isError ? (
                        <AdminErrorState error={daftar.error} onRetry={muatUlang} />
                    ) : rows.length === 0 ? (
                        <div className="rounded-lg border border-dashed p-10 text-center space-y-2">
                            <p className="font-medium">Belum ada akun</p>
                            <p className="text-sm text-muted-foreground">Tidak ada akun yang cocok dengan filter ini.</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border bg-background">
                            <table className="w-full min-w-[700px] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/40 text-xs text-muted-foreground">
                                        <th className="px-4 py-3 text-left font-semibold">Akun</th>
                                        <th className="px-4 py-3 text-left font-semibold">Role</th>
                                        <th className="px-4 py-3 text-left font-semibold">Verifikasi</th>
                                        <th className="px-4 py-3 text-left font-semibold">Terakhir masuk</th>
                                        <th className="px-4 py-3 text-left font-semibold">Status</th>
                                        <th className="px-4 py-3 text-right font-semibold">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>{rows.map(baris)}</tbody>
                            </table>
                        </div>
                    )}
                    <Pagination meta={meta} onPageChange={(next) => tulis({ page: String(next) })} />
                </div>
            </div>

            {/* confirm: status toggle */}
            <Dialog open={konfirmasi !== null} onOpenChange={(o) => { if (!o) setKonfirmasi(null); }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{konfirmasi?.tujuan === 'nonaktif' ? 'Nonaktifkan akun?' : 'Aktifkan akun?'}</DialogTitle>
                        <DialogDescription>
                            {konfirmasi?.tujuan === 'nonaktif'
                                ? `${konfirmasi?.nama} tidak akan bisa masuk sampai akunnya diaktifkan kembali, dan seluruh sesinya dicabut sekarang juga.`
                                : `${konfirmasi?.nama} akan bisa masuk kembali dengan sandi yang berlaku sebelumnya.`}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setKonfirmasi(null)}>Batal</Button>
                        <Button type="button" data-testid="admin-aksi" disabled={gantiStatus.isPending}
                            onClick={() => { if (!konfirmasi) return; gantiStatus.mutate({ id: konfirmasi.id, status: konfirmasi.tujuan }, { onSettled: () => setKonfirmasi(null) }); }}>
                            {konfirmasi?.tujuan === 'nonaktif' ? 'Nonaktifkan' : 'Aktifkan'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* confirm: password reset */}
            <Dialog open={resetMinta !== null && passwordBaru === null} onOpenChange={(o) => { if (!o) setResetMinta(null); }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Atur ulang sandi?</DialogTitle>
                        <DialogDescription>
                            Sandi {resetMinta?.nama} akan diganti. Sandi lama berhenti berlaku seketika, yang baru hanya ditampilkan satu kali.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setResetMinta(null)}>Batal</Button>
                        <Button type="button" data-testid="admin-aksi" disabled={resetSandi.isPending}
                            onClick={() => { if (!resetMinta) return; resetSandi.mutate(resetMinta.id, { onSettled: () => setResetMinta(null) }); }}>
                            Atur ulang
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* new password shown once */}
            <Dialog open={passwordBaru !== null} onOpenChange={(o) => { if (!o) { setPasswordBaru(null); setNamaReset(''); } }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Sandi baru untuk {namaReset}</DialogTitle>
                        <DialogDescription>Catat sekarang — tidak ada endpoint yang bisa menampilkannya lagi.</DialogDescription>
                    </DialogHeader>
                    <div className="rounded-lg border bg-muted p-3">
                        <code data-testid="admin-sandi-baru" className="block break-all font-mono text-sm">{passwordBaru}</code>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => { setPasswordBaru(null); setNamaReset(''); }}>Tutup</Button>
                        <Button type="button" onClick={() => { if (!passwordBaru) return; void navigator.clipboard.writeText(passwordBaru); toast.success('Sandi disalin.'); }}>Salin</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* tolak: alasan wajib */}
            <Dialog open={tolakTarget !== null} onOpenChange={(o) => { if (!o) { setTolakTarget(null); setAlasanTolak(''); } }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Tolak pendaftaran?</DialogTitle>
                        <DialogDescription>Akun {tolakTarget?.nama} akan ditolak. Alasan wajib diisi (min. 10 karakter).</DialogDescription>
                    </DialogHeader>
                    <Field label="Alasan penolakan" required>
                        <FieldTextarea rows={3} placeholder="Contoh: Dokumen tidak lengkap..."
                            value={alasanTolak} onChange={(e) => setAlasanTolak(e.target.value)} />
                    </Field>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => { setTolakTarget(null); setAlasanTolak(''); }}>Batal</Button>
                        <Button type="button" variant="destructive" disabled={tolak.isPending || alasanTolak.trim().length < 10}
                            onClick={() => { if (!tolakTarget) return; tolak.mutate({ id: tolakTarget.id, alasan: alasanTolak.trim() }, { onSettled: () => { setTolakTarget(null); setAlasanTolak(''); } }); }}>
                            Tolak
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* token undangan */}
            <Dialog open={tokenUndangan !== null} onOpenChange={(o) => { if (!o) { setTokenUndangan(null); setNamaUndangan(''); } }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Token undangan untuk {namaUndangan}</DialogTitle>
                        <DialogDescription>Salin dan kirimkan ke penerima. Token hanya ditampilkan sekali.</DialogDescription>
                    </DialogHeader>
                    <div className="rounded-lg border bg-muted p-3">
                        <code className="block break-all font-mono text-xs">{tokenUndangan}</code>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        URL aktivasi: <span className="font-mono">{window.location.origin}/aktivasi-undangan/{tokenUndangan}</span>
                    </p>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => { setTokenUndangan(null); setNamaUndangan(''); }}>Tutup</Button>
                        <Button type="button" onClick={() => { if (!tokenUndangan) return; void navigator.clipboard.writeText(`${window.location.origin}/aktivasi-undangan/${tokenUndangan}`); toast.success('URL aktivasi disalin.'); }}>Salin URL</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* kirim ulang undangan */}
            <Dialog open={kirimUlangTarget !== null} onOpenChange={(o) => { if (!o) setKirimUlangTarget(null); }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Kirim ulang undangan?</DialogTitle>
                        <DialogDescription>Token lama untuk {kirimUlangTarget?.nama} akan dibatalkan dan token baru diterbitkan.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setKirimUlangTarget(null)}>Batal</Button>
                        <Button type="button" disabled={kirimUlang.isPending}
                            onClick={() => { if (!kirimUlangTarget) return; kirimUlang.mutate(kirimUlangTarget.undanganId, { onSettled: () => setKirimUlangTarget(null) }); }}>
                            Kirim ulang
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* batalkan undangan */}
            <Dialog open={batalkanTarget !== null} onOpenChange={(o) => { if (!o) setBatalkanTarget(null); }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Batalkan undangan?</DialogTitle>
                        <DialogDescription>Akun {batalkanTarget?.nama} yang belum diaktifkan akan dihapus.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setBatalkanTarget(null)}>Batal</Button>
                        <Button type="button" variant="destructive" disabled={batalkan.isPending}
                            onClick={() => { if (!batalkanTarget) return; batalkan.mutate(batalkanTarget.undanganId, { onSettled: () => setBatalkanTarget(null) }); }}>
                            Batalkan undangan
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminGate>
    );
}
