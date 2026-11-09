import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { KeyRound, RefreshCw, Search, ShieldCheck, UserPlus } from 'lucide-react';
import { toast } from 'sonner';
import {
    adminPenggunaOptions,
    resetSandiPenggunaMutation,
    ubahStatusPenggunaMutation,
    type AdminPengguna,
    type AdminPenggunaFilter,
    type TipeFilterPengguna,
} from '@/lib/api/admin';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { AdminErrorState, AdminGate } from '@/portals/admin/features/admin/admin-gate';
import {
    labelStatusAkun,
    labelTipeAkun,
} from '@/portals/admin/features/admin/format-admin';
import { formatWaktu } from '@/lib/format';
import { pesanRingkas } from '@/portals/admin/features/admin/pesan';
import { PageHeader } from '@/shared/components/layout/page-header';
import { Pagination } from '@/shared/components/layout/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldInput, FieldSelect } from '@/shared/components/form/field';
import { SelectItem } from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { SkeletonRows } from '@/shared/components/states/loading-state';

/**
 * The account directory: every `users` row the operator may read, and the two
 * writes the backend actually offers (status toggle, password reset).
 *
 * ## The tab lives in the URL
 *
 * `?tipe=staf|pasien` (absent = all three tabs' union) is a `searchParams`
 * read rather than component state, because the sidebar links straight into
 * it, a refresh keeps the tab, and the query key is the same object the
 * dashboard's statistics use - one definition of "staff" in two places.
 *
 * ## Why a list, and not the table the dokter page uses
 *
 * An account row carries a name, a masked phone, an email, an account type, a
 * status and two timestamps: six fields that read as a sentence, not as six
 * columns. The dokter table works because its columns are mostly status; this
 * one is a roster, so each row is a card with the actions that touch THAT
 * account pinned to its own edge - a password reset never lands on the wrong
 * row.
 *
 * ## The create button belongs to the staff tabs
 *
 * `POST /admin/pengguna` refuses `tipe: 'pasien'` - the server's
 * {@see \App\Services\Admin\AdminPenggunaService::TIPE_BUAT} is
 * apoteker/kurir/marketing/admin, because patients arrive through
 * `POST /auth/sign-up` and OTP - so the patient tab shows the door that
 * actually exists ("Pasien mendaftar sendiri lewat aplikasi") instead of a
 * button leading to a form whose every patient choice would 422. Its empty
 * state says the same, for the same reason: an empty list is exactly when
 * someone goes looking for the create button.
 */
const PER_HALAMAN = 20;

type Tab = 'semua' | 'staf' | 'pasien';

const TAB: ReadonlyArray<{ nilai: Tab; label: string }> = [
    { nilai: 'semua', label: 'Semua' },
    { nilai: 'staf', label: 'Staf' },
    { nilai: 'pasien', label: 'Pasien' },
];

export function AdminPenggunaPage() {
    useDocumentTitle('Manajemen Akun');
    const online = useOnlineStatus();

    // --- The URL is the state -------------------------------------------------
    const [searchParams, setSearchParams] = useSearchParams();
    const tipeParam = searchParams.get('tipe');
    const tab: Tab = tipeParam === 'staf' || tipeParam === 'pasien' ? tipeParam : 'semua';
    const qParam = searchParams.get('q') ?? '';
    const statusParam = searchParams.get('status');
    const status =
        statusParam === 'aktif' || statusParam === 'nonaktif' ? statusParam : undefined;
    const halaman = Math.max(1, Number(searchParams.get('page') ?? '1') || 1);

    // The draft search box: committed on submit (Enter or the button) so a
    // half-typed name does not refetch per keystroke or churn the URL.
    const [qDraft, setQDraft] = useState(qParam);
    useEffect(() => {
        setQDraft(qParam);
    }, [qParam]);

    const tulis = (next: Record<string, string | null>) => {
        const params = new URLSearchParams(searchParams);
        for (const [key, value] of Object.entries(next)) {
            if (value === null || value === '') {
                params.delete(key);
            } else {
                params.set(key, value);
            }
        }
        setSearchParams(params, { replace: true });
    };

    const filter: AdminPenggunaFilter = {
        tipe: tab === 'semua' ? undefined : (tab as TipeFilterPengguna),
        status,
        q: qParam || undefined,
        page: halaman,
        per_page: PER_HALAMAN,
    };

    const daftar = useQuery(adminPenggunaOptions(filter));

    // --- The two writes -------------------------------------------------------
    const gantiStatus = useMutation({
        ...ubahStatusPenggunaMutation(),
        onSuccess: (_result, input) => {
            toast.success(
                input.status === 'aktif'
                    ? 'Akun diaktifkan kembali.'
                    : 'Akun dinonaktifkan.',
            );
        },
        onError: (error) => toast.error(pesanRingkas(error)),
    });

    const resetSandi = useMutation({
        ...resetSandiPenggunaMutation(),
        onSuccess: (result) => {
            // The plaintext exists exactly once - it must be on screen NOW,
            // before any cache sweep or navigation can drop it.
            setPasswordBaru(result.data.password_baru);
            setNamaReset(result.data.pengguna.nama_lengkap);
        },
        onError: (error) => toast.error(pesanRingkas(error)),
    });

    // Confirm dialogs, one pair of pieces of state so a row's action never
    // leaks into another row: the row is identified by id + name at click time.
    const [konfirmasi, setKonfirmasi] = useState<
        { id: number; nama: string; tujuan: 'aktif' | 'nonaktif' } | null
    >(null);
    const [resetMinta, setResetMinta] = useState<{ id: number; nama: string } | null>(null);
    const [passwordBaru, setPasswordBaru] = useState<string | null>(null);
    const [namaReset, setNamaReset] = useState('');

    const rows = daftar.data?.data.pengguna ?? [];
    const meta = daftar.data?.meta;

    const muatUlang = () => {
        void daftar.refetch();
    };

    // --- The row --------------------------------------------------------------
    const baris = (akun: AdminPengguna) => {
        const tipeAkun = akun.tipe;
        const bolehNonaktif = akun.status === 'aktif';

        return (
            <li
                key={akun.id}
                data-testid="admin-baris"
                className="rounded-lg border p-4 space-y-3"
            >
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1 min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="font-medium truncate">{akun.nama_lengkap}</p>
                            <Badge variant="outline">{labelTipeAkun(tipeAkun)}</Badge>
                            <Badge
                                variant={akun.status === 'aktif' ? 'default' : 'secondary'}
                            >
                                {labelStatusAkun(akun.status)}
                            </Badge>
                            {akun.telepon_terverifikasi ? (
                                <span
                                    className="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                    title="Nomor telepon sudah diverifikasi"
                                >
                                    <ShieldCheck className="h-3.5 w-3.5" />
                                    Terverifikasi
                                </span>
                            ) : null}
                        </div>

                        <p className="text-sm text-muted-foreground">
                            {akun.no_telepon}
                            {akun.email ? ` · ${akun.email}` : ''}
                        </p>

                        <p className="text-xs text-muted-foreground">
                            Terakhir masuk{' '}
                            {akun.last_login_at
                                ? formatWaktu(akun.last_login_at)
                                : 'belum pernah'}{' '}
                            · Dibuat {formatWaktu(akun.dibuat_at)}
                        </p>
                    </div>

                    <div
                        data-testid="admin-aksi"
                        className="flex flex-wrap items-center gap-2 shrink-0"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={!online || gantiStatus.isPending}
                            onClick={() =>
                                setKonfirmasi({
                                    id: akun.id,
                                    nama: akun.nama_lengkap,
                                    tujuan: bolehNonaktif ? 'nonaktif' : 'aktif',
                                })
                            }
                        >
                            {bolehNonaktif ? 'Nonaktifkan' : 'Aktifkan'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={!online || resetSandi.isPending}
                            onClick={() =>
                                setResetMinta({ id: akun.id, nama: akun.nama_lengkap })
                            }
                        >
                            <KeyRound />
                            Reset sandi
                        </Button>
                    </div>
                </div>
            </li>
        );
    };

    return (
        <AdminGate>
            <div className="space-y-6">
                <PageHeader
                    title="Manajemen Akun"
                    description="Buat dan atur akun staf, pantau akun pasien - aktifkan, nonaktifkan, atau atur ulang sandinya."
                    action={
                        /**
                         * The create button is STAFF-only, so the patient tab
                         * must not offer it. `POST /admin/pengguna` refuses
                         * `tipe: 'pasien'` (the server's `TIPE_BUAT` is
                         * apoteker/kurir/marketing/admin) because patients
                         * register themselves over `POST /auth/sign-up` and
                         * OTP - a "Buat akun" button on a tab that can only
                         * ever list patients would promise a form the server
                         * will not accept, which is exactly the misreading
                         * this page used to invite. The tab states the
                         * correct door instead.
                         */
                        tab === 'pasien' ? (
                            <p className="text-muted-foreground text-sm">
                                Pasien mendaftar sendiri lewat aplikasi.
                            </p>
                        ) : (
                            <Button asChild data-testid="admin-tulis">
                                <Link to="/admin/pengguna/buat">
                                    <UserPlus />
                                    Buat akun
                                </Link>
                            </Button>
                        )
                    }
                />

                {/* --- Tabs, search, status --- */}
                <div className="flex flex-wrap items-end gap-3">
                    <div
                        role="group"
                        aria-label="Filter tipe akun"
                        className="flex flex-wrap gap-2"
                    >
                        {TAB.map((item) => (
                            <div key={item.nilai} data-testid="admin-aksi">
                                <Button
                                    type="button"
                                    variant={tab === item.nilai ? 'default' : 'outline'}
                                    aria-pressed={tab === item.nilai}
                                    className="h-11"
                                    onClick={() =>
                                        tulis({
                                            tipe: item.nilai === 'semua' ? null : item.nilai,
                                            page: null,
                                        })
                                    }
                                >
                                    {item.label}
                                </Button>
                            </div>
                        ))}
                    </div>

                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            tulis({ q: qDraft.trim() || null, page: null });
                        }}
                    >
                        <Field label="Cari" className="w-56">
                            <FieldInput
                                data-testid="admin-cari"
                                placeholder="Nama atau nomor"
                                value={qDraft}
                                onChange={(event) => setQDraft(event.target.value)}
                            />
                        </Field>

                        <Button type="submit" variant="secondary" className="h-10">
                            <Search />
                            Cari
                        </Button>
                    </form>

                    <Field label="Status" className="w-48">
                        <FieldSelect
                            value={status ?? 'semua'}
                            onValueChange={(value) =>
                                tulis({
                                    status: value === 'semua' ? null : value,
                                    page: null,
                                })
                            }
                        >
                            <SelectItem value="semua">Semua status</SelectItem>
                            <SelectItem value="aktif">Aktif</SelectItem>
                            <SelectItem value="nonaktif">Nonaktif</SelectItem>
                        </FieldSelect>
                    </Field>

                    <Button
                        type="button"
                        variant="ghost"
                        className="h-10"
                        onClick={muatUlang}
                        disabled={daftar.isFetching}
                    >
                        <RefreshCw />
                        Muat ulang
                    </Button>
                </div>

                {/* --- The list --- */}
                <div className="space-y-4">
                    {daftar.isPending ? (
                        <div data-testid="admin-muat" className="space-y-2">
                            <SkeletonRows rows={6} />
                        </div>
                    ) : daftar.isError ? (
                        <AdminErrorState error={daftar.error} onRetry={muatUlang} />
                    ) : rows.length === 0 ? (
                        <div className="rounded-lg border border-dashed p-10 text-center space-y-2">
                            <p className="font-medium">Belum ada akun</p>
                            <p className="text-sm text-muted-foreground">
                                {tab === 'pasien'
                                    ? 'Tidak ada pasien yang cocok dengan filter ini. Pasien mendaftar sendiri lewat aplikasi.'
                                    : 'Tidak ada akun yang cocok dengan filter ini.'}
                            </p>
                            {tab === 'pasien' ? null : (
                                <Button asChild variant="outline" data-testid="admin-tulis">
                                    <Link to="/admin/pengguna/buat">
                                        <UserPlus />
                                        Buat akun
                                    </Link>
                                </Button>
                            )}
                        </div>
                    ) : (
                        <ul className="space-y-3">{rows.map(baris)}</ul>
                    )}

                    <Pagination
                        meta={meta}
                        onPageChange={(next) => tulis({ page: String(next) })}
                    />
                </div>
            </div>

            {/* --- Confirm: status toggle --- */}
            <Dialog
                open={konfirmasi !== null}
                onOpenChange={(open) => {
                    if (!open) setKonfirmasi(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {konfirmasi?.tujuan === 'nonaktif'
                                ? 'Nonaktifkan akun?'
                                : 'Aktifkan akun?'}
                        </DialogTitle>
                        <DialogDescription>
                            {konfirmasi?.tujuan === 'nonaktif'
                                ? `${konfirmasi?.nama} tidak akan bisa masuk sampai akunnya diaktifkan kembali, dan seluruh sesinya dicabut sekarang juga.`
                                : `${konfirmasi?.nama} akan bisa masuk kembali dengan sandi yang berlaku sebelumnya.`}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setKonfirmasi(null)}
                        >
                            Batal
                        </Button>
                        <Button
                            type="button"
                            data-testid="admin-aksi"
                            disabled={gantiStatus.isPending}
                            onClick={() => {
                                if (!konfirmasi) return;
                                gantiStatus.mutate(
                                    { id: konfirmasi.id, status: konfirmasi.tujuan },
                                    { onSettled: () => setKonfirmasi(null) },
                                );
                            }}
                        >
                            {konfirmasi?.tujuan === 'nonaktif'
                                ? 'Nonaktifkan'
                                : 'Aktifkan'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* --- Confirm: password reset --- */}
            <Dialog
                open={resetMinta !== null && passwordBaru === null}
                onOpenChange={(open) => {
                    if (!open) setResetMinta(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Atur ulang sandi?</DialogTitle>
                        <DialogDescription>
                            Sandi {resetMinta?.nama} akan diganti dengan yang baru. Sandi
                            lama berhenti berlaku seketika, dan yang baru hanya ditampilkan
                            satu kali.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setResetMinta(null)}
                        >
                            Batal
                        </Button>
                        <Button
                            type="button"
                            data-testid="admin-aksi"
                            disabled={resetSandi.isPending}
                            onClick={() => {
                                if (!resetMinta) return;
                                resetSandi.mutate(resetMinta.id, {
                                    onSettled: () => setResetMinta(null),
                                });
                            }}
                        >
                            Atur ulang
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* --- The new password, shown exactly once --- */}
            <Dialog
                open={passwordBaru !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPasswordBaru(null);
                        setNamaReset('');
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Sandi baru untuk {namaReset}</DialogTitle>
                        <DialogDescription>
                            Catat sekarang - setelah jendela ini ditutup, tidak ada
                            endpoint yang bisa menampilkannya lagi.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="rounded-lg border bg-muted p-3">
                        <code
                            data-testid="admin-sandi-baru"
                            className="block break-all font-mono text-sm"
                        >
                            {passwordBaru}
                        </code>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setPasswordBaru(null);
                                setNamaReset('');
                            }}
                        >
                            Tutup
                        </Button>
                        <Button
                            type="button"
                            onClick={() => {
                                if (!passwordBaru) return;
                                void navigator.clipboard.writeText(passwordBaru);
                                toast.success('Sandi disalin.');
                            }}
                        >
                            Salin
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminGate>
    );
}
