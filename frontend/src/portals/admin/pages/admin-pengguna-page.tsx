import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { KeyRound, RefreshCw, Search, ShieldCheck, UserPlus } from 'lucide-react';
import { toast } from 'sonner';
import {
    adminPenggunaOptions,
    adminRolesOptions,
    resetSandiPenggunaMutation,
    ubahStatusPenggunaMutation,
    type AdminPengguna,
    type AdminPenggunaFilter,
    type TipeFilterPengguna,
} from '@/lib/api/admin';
import { PORTAL_NAMA, portalTipe } from '@/lib/portal';
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
 * ## The selection lives in the URL
 *
 * `?tipe=<role>` (absent = the union view) is a `searchParams` read rather
 * than component state, because the SIDEBAR links straight into it - one
 * item per role, the split this page's audience asked for - a refresh keeps
 * the selection, and the query key is the same object the dashboard's
 * statistics use, so "staf" still means one thing in two places. The chip
 * strip mirrors the sidebar one-for-one plus "Semua"; a valid `?tipe=`
 * outside that set (the `staf` alias a legacy link carries, `dokter`,
 * `perawat`) keeps working and earns a chip of its own, so the pressed state
 * never disagrees with the address bar.
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
 * ## The create button belongs to the types the server actually creates
 *
 * `POST /admin/pengguna` refuses every type outside the server's
 * {@see \App\Services\Admin\AdminPenggunaService::TIPE_BUAT}
 * (apoteker/kurir/marketing/admin), so a "Buat akun" button on any other
 * view would promise a form whose every choice 422s. Those views show the
 * door that DOES exist instead - patients register themselves over
 * `POST /auth/sign-up` and OTP, doctors are minted through the Dokter menu,
 * superadmins only from the seeder - and the empty state says the same,
 * because an empty list is exactly when someone goes looking for the
 * create button.
 *
 * ## The role card states the policy the list below is filtered by
 *
 * A role view (`?tipe=<role>`, one sidebar item each) renders a summary
 * card above the directory: the role's description, the portal its holders
 * operate, and every permission code it holds with its display name. The
 * payload comes from `GET /admin/roles`, which answers `RbacCatalog` - the
 * same constants the seeder writes and `permission:` middleware enforces -
 * so the card cannot advertise a grant the server disagrees with. The card
 * answers "role apa ini?", which is what the per-role split raised;
 * underneath it the directory is the same list it always was, narrowed to
 * one `tipe=`.
 */
const PER_HALAMAN = 20;

type Tab = 'semua' | TipeFilterPengguna;

/**
 * The chip strip: the union view plus the six roles the sidebar splits
 * into, in sidebar order.
 *
 * `staf` is NOT a standing chip - it is the filter ALIAS whose members are
 * five of these six listed individually, and showing the umbrella beside
 * its members is the ambiguity this page's split exists to remove. It
 * remains a valid `?tipe=` (the dashboard's count and any legacy link still
 * land on it) and then earns a chip of its own, appended rather than
 * standing in the default set - see `chips` below. `dokter` and `perawat`
 * are the same story: valid filters, no standing chip.
 */
const TAB: ReadonlyArray<{ nilai: Tab; label: string }> = [
    { nilai: 'semua', label: 'Semua' },
    { nilai: 'pasien', label: 'Pasien' },
    { nilai: 'apoteker', label: 'Apoteker' },
    { nilai: 'kurir', label: 'Kurir' },
    { nilai: 'marketing', label: 'Marketing' },
    { nilai: 'admin', label: 'Admin' },
    { nilai: 'superadmin', label: 'Superadmin' },
];

/**
 * Every `?tipe=` the server accepts: the chips' set plus the `staf` alias
 * and the two row types no chip stands for. Mirrors
 * `AdminPenggunaService::TIPE_FILTER` - the type `TipeFilterPengguna` is the
 * compile-time half, this set the runtime half - and a value outside it
 * falls back to the union view rather than asking the API a question it
 * would answer 422.
 */
const TIPE_SAHIH: ReadonlySet<string> = new Set([
    ...TAB.map((item) => item.nilai),
    'staf',
    'dokter',
    'perawat',
]);

/**
 * The types `POST /admin/pengguna` creates - the server's `TIPE_BUAT` plus
 * the `staf` alias, whose five members are all creatable. Every type OUTSIDE
 * this set shows {@see PINTU_BUAT}'s door instead of a button that would 422
 * on submit.
 */
const TIPE_DIBUAT: ReadonlySet<string> = new Set([
    'staf',
    'apoteker',
    'kurir',
    'marketing',
    'admin',
]);

/** The door that DOES exist, for each type the create form does not offer. */
const PINTU_BUAT: Partial<Record<TipeFilterPengguna, string>> = {
    pasien: 'Pasien mendaftar sendiri lewat aplikasi.',
    dokter: 'Dokter dibuat lewat menu Dokter.',
    superadmin: 'Akun superadmin hanya dibuat dari seeder.',
    perawat: 'Tipe ini belum memiliki role, jadi tidak bisa dibuat.',
};

/** Chip/title label for a filter value - `labelTipeAkun` predates the alias. */
function labelPilihan(tipe: TipeFilterPengguna): string {
    return tipe === 'staf' ? 'Staf' : labelTipeAkun(tipe);
}

/** The portal a role's holders operate, in the login pages' own words. */
function namaPortalRole(tipe: string): string {
    const portal = portalTipe(tipe);
    return portal === 'pasien' ? 'Aplikasi Sehatly' : PORTAL_NAMA[portal];
}

export function AdminPenggunaPage() {
    const online = useOnlineStatus();

    // --- The URL is the state -------------------------------------------------
    const [searchParams, setSearchParams] = useSearchParams();
    const tipeMentah = searchParams.get('tipe');
    // The URL IS the filter, validated against the server's vocabulary; an
    // unknown value falls back to the union view rather than asking the API a
    // question it would answer 422.
    const tipeAktif: TipeFilterPengguna | null =
        tipeMentah !== null && TIPE_SAHIH.has(tipeMentah)
            ? (tipeMentah as TipeFilterPengguna)
            : null;

    useDocumentTitle(
        tipeAktif ? `Manajemen Akun · ${labelPilihan(tipeAktif)}` : 'Manajemen Akun',
    );

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
        tipe: tipeAktif ?? undefined,
        status,
        q: qParam || undefined,
        page: halaman,
        per_page: PER_HALAMAN,
    };

    const daftar = useQuery(adminPenggunaOptions(filter));

    // The role card: only a role view has one, and only once the catalogue
    // answers - a failed fetch hides the card, never the directory. The
    // union view never asks: there is no card to fill there.
    const katalog = useQuery({ ...adminRolesOptions(), enabled: tipeAktif !== null });
    const roleAktif = tipeAktif
        ? katalog.data?.data.roles.find((role) => role.role === tipeAktif)
        : undefined;

    // Where a create button may appear, and the door that exists where it may not.
    const bolehBuat = tipeAktif === null || TIPE_DIBUAT.has(tipeAktif);
    const pintuBuat = tipeAktif ? PINTU_BUAT[tipeAktif] : undefined;

    // The chip strip mirrors the sidebar plus "Semua"; a valid `?tipe=` outside
    // that set appends its own chip so URL and pressed state never disagree.
    const chips: ReadonlyArray<{ nilai: Tab; label: string }> =
        tipeAktif && !TAB.some((item) => item.nilai === tipeAktif)
            ? [...TAB, { nilai: tipeAktif, label: labelPilihan(tipeAktif) }]
            : TAB;
    const tekan = (nilai: Tab): boolean =>
        nilai === 'semua' ? tipeAktif === null : tipeAktif === nilai;

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
                    description="Direktori akun per role: telusuri, aktifkan, nonaktifkan, atau atur ulang sandinya."
                    action={
                        /**
                         * The button appears only where the server accepts a
                         * create (`TIPE_DIBUAT`); every other view states the
                         * door that DOES exist instead - a button whose every
                         * submit would 422 is worse than its absence, which
                         * is exactly the misreading an earlier version of
                         * this page invited on the patient tab.
                         */
                        bolehBuat ? (
                            <Button asChild data-testid="admin-tulis">
                                <Link to="/admin/pengguna/buat">
                                    <UserPlus />
                                    Buat akun
                                </Link>
                            </Button>
                        ) : (
                            <p className="text-muted-foreground text-sm">{pintuBuat}</p>
                        )
                    }
                />

                {/* --- What this role IS: only a role view carries one --- */}
                {roleAktif ? (
                    <section
                        data-testid="admin-ringkasan-role"
                        aria-label={`Ringkasan role ${labelTipeAkun(roleAktif.role)}`}
                        className="space-y-3 rounded-lg border p-4"
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant="outline">{labelTipeAkun(roleAktif.role)}</Badge>
                            <span className="text-sm text-muted-foreground">
                                {namaPortalRole(roleAktif.role)}
                            </span>
                            <span
                                className="text-sm text-muted-foreground"
                                aria-hidden="true"
                            >
                                ·
                            </span>
                            <span className="text-sm text-muted-foreground">
                                {roleAktif.izin.length} izin
                            </span>
                        </div>

                        <p className="text-sm">{roleAktif.deskripsi}</p>

                        {/* The raw code rides in `title`: the display name is
                            derived, so the code is the unambiguous identity of
                            the grant when someone copies it into a route. */}
                        <div className="flex flex-wrap gap-1.5">
                            {roleAktif.izin.map((izin) => (
                                <Badge key={izin.kode} variant="secondary" title={izin.kode}>
                                    {izin.nama}
                                </Badge>
                            ))}
                        </div>
                    </section>
                ) : null}

                {/* --- Tabs, search, status --- */}
                <div className="flex flex-wrap items-end gap-3">
                    <div
                        role="group"
                        aria-label="Filter tipe akun"
                        className="flex flex-wrap gap-2"
                    >
                        {chips.map((item) => (
                            <div key={item.nilai} data-testid="admin-aksi">
                                <Button
                                    type="button"
                                    variant={tekan(item.nilai) ? 'default' : 'outline'}
                                    aria-pressed={tekan(item.nilai)}
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
                                {bolehBuat
                                    ? 'Tidak ada akun yang cocok dengan filter ini.'
                                    : (pintuBuat ??
                                      'Tidak ada akun yang cocok dengan filter ini.')}
                            </p>
                            {bolehBuat ? (
                                <Button asChild variant="outline" data-testid="admin-tulis">
                                    <Link to="/admin/pengguna/buat">
                                        <UserPlus />
                                        Buat akun
                                    </Link>
                                </Button>
                            ) : null}
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
