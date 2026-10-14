import { useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import {
    adminDokterDetailOptions,
    ubahStatusDokter,
    verifikasiDokter,
    type AdminDokter,
} from '@/lib/api/admin';
import { ApiError } from '@/lib/http';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { AdminErrorState, AdminGate } from '@/features/admin/admin-gate';
import {
    AdminAktifBadge,
    AdminTelemedisinBadge,
    AdminVerifikasiBadge,
    StrMasaBerlaku,
} from '@/features/admin/status-badges';
import { tanggalSingkat } from '@/features/admin/format-admin';
import { gantiDokterCache } from '@/features/admin/cache';
import { pesanRingkas } from '@/features/admin/pesan';
import { AdminJadwalSection, AdminLiburSection } from '@/features/admin/admin-jadwal';
import { PageHeader } from '@/components/layout/page-header';
import { OfflineBanner } from '@/components/offline-banner';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/states/empty-state';
import { ForbiddenState, NotFoundState } from '@/components/states/error-state';
import { SkeletonRows } from '@/components/states/loading-state';

type TabDetail = 'kredensial' | 'jadwal' | 'libur';

const TAB: ReadonlyArray<{ nilai: TabDetail; label: string }> = [
    { nilai: 'kredensial', label: 'Kredensial' },
    { nilai: 'jadwal', label: 'Jadwal' },
    { nilai: 'libur', label: 'Libur' },
];

function tabValid(nilai: string | null): nilai is TabDetail {
    return nilai === 'kredensial' || nilai === 'jadwal' || nilai === 'libur';
}

/**
 * `/admin/dokter/:id` and `/admin/dokter/:id/jadwal` - one screen, three tabs.
 *
 * ## The tab lives in the URL, and that is why the two routes share this page
 *
 * The pattern requires a tab to be addressable (`?tab=jadwal`), and the F14
 * contract names `/admin/dokter/{id}/jadwal` as the schedule path. Rendering the
 * same page for both and defaulting the tab by route (`tabAwal="jadwal"`) means
 * one component owns the doctor's credentials, their status decisions and their
 * schedule, and a deep link to any of the three always lands on the tab it
 * names. Changing tabs calls `setSearchParams(..., { replace: true })` so tab
 * browsing does not fill the history stack.
 *
 * ## The destructive action is the only dialog; activation is one click
 *
 * Suspending shows the blast radius the server computed (`data.dampak`) before
 * the button is usable, and states that bookings are NOT cancelled. Activating
 * a suspended doctor and toggling telemedicine availability are additive and
 * reversible, so they act directly with a toast - the `_global.md` rule against
 * dialogs on reversible actions.
 */
export function AdminDokterDetailPage({ tabAwal }: { tabAwal?: TabDetail } = {}) {
    useDocumentTitle('Detail dokter');

    return (
        <AdminGate>
            <AdminDokterDetailContent tabAwal={tabAwal} />
        </AdminGate>
    );
}

function AdminDokterDetailContent({ tabAwal }: { tabAwal?: TabDetail }) {
    const online = useOnlineStatus();
    const queryClient = useQueryClient();
    const params = useParams();
    const [searchParams, setSearchParams] = useSearchParams();

    const id = Number(params.id);
    const idValid = Number.isInteger(id) && id > 0;

    const tabUrl = searchParams.get('tab');
    const tab: TabDetail = tabValid(tabUrl)
        ? tabUrl
        : (tabAwal ?? 'kredensial');

    const detail = useQuery({
        ...adminDokterDetailOptions(id),
        enabled: idValid,
    });

    const [dialogNonaktif, setDialogNonaktif] = useState(false);
    const [dialogVerifikasi, setDialogVerifikasi] = useState(false);
    const pemicuNonaktif = useRef<HTMLButtonElement | null>(null);
    const pemicuVerifikasi = useRef<HTMLButtonElement | null>(null);

    const status = useMutation({
        mutationFn: (body: { status_aktif: boolean; tersedia_telemedisin?: boolean }) =>
            ubahStatusDokter(id, body),
        onSuccess: (hasil) => {
            gantiDokterCache(queryClient, hasil.data.dokter);
            setDialogNonaktif(false);
            toast.success('Status dokter diperbarui.');
        },
        onError: (error) => {
            toast.error(pesanRingkas(error) ?? 'Status dokter gagal diperbarui.');
        },
    });

    const putuskan = useMutation({
        mutationFn: (nilai: 'terverifikasi' | 'ditolak') =>
            verifikasiDokter(id, nilai),
        onSuccess: (hasil) => {
            gantiDokterCache(queryClient, hasil.data.dokter);
            setDialogVerifikasi(false);
            toast.success('Status dokter diperbarui.');
        },
        onError: (error) => {
            toast.error(pesanRingkas(error) ?? 'Status dokter gagal diperbarui.');
        },
    });

    const pilihTab = (nilai: TabDetail): void => {
        setSearchParams({ tab: nilai }, { replace: true });
    };

    if (!idValid) {
        return (
            <>
                <PageHeader title="Detail dokter" />

                <NotFoundState
                    title="Dokter tidak ditemukan"
                    detail="Alamat ini tidak menunjuk dokter yang valid."
                    action={
                        <Button asChild variant="outline" className="mt-1 min-h-11">
                            <Link to="/admin/dokter">Kembali ke daftar dokter</Link>
                        </Button>
                    }
                />
            </>
        );
    }

    if (detail.isError) {
        if (detail.error instanceof ApiError && detail.error.isForbidden) {
            return (
                <>
                    <PageHeader title="Detail dokter" />

                    <ForbiddenState
                        title="Akses ditolak"
                        detail="Anda tidak memiliki izin untuk membuka halaman ini."
                        action={
                            <Button asChild variant="outline" className="mt-1 min-h-11">
                                <Link to="/admin/dokter">
                                    Kembali ke daftar dokter
                                </Link>
                            </Button>
                        }
                    />
                </>
            );
        }

        if (detail.error instanceof ApiError && detail.error.isNotFound) {
            return (
                <>
                    <PageHeader title="Detail dokter" />

                    <NotFoundState
                        title="Dokter tidak ditemukan"
                        detail="Data dokter ini tidak ada atau sudah dihapus."
                        action={
                            <Button asChild variant="outline" className="mt-1 min-h-11">
                                <Link to="/admin/dokter">
                                    Kembali ke daftar dokter
                                </Link>
                            </Button>
                        }
                    />
                </>
            );
        }

        return (
            <>
                <PageHeader title="Detail dokter" />

                <AdminErrorState
                    title="Gagal memuat detail dokter."
                    error={detail.error}
                    onRetry={() => {
                        void detail.refetch();
                    }}
                />
            </>
        );
    }

    const dokter: AdminDokter | undefined = detail.data?.data.dokter;
    const dampak = detail.data?.data.dampak;
    const nama = dokter?.nama_lengkap ?? (dokter === undefined ? 'Memuat...' : `Dokter #${dokter.id}`);

    return (
        <>
            <PageHeader
                title={nama}
                description="Kredensial, kelayakan, dan jadwal praktik."
                action={
                    <div data-testid="admin-aksi" className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" className="h-11">
                            <Link to="/admin/dokter">
                                <ArrowLeft aria-hidden />
                                Dokter
                            </Link>
                        </Button>

                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => {
                                void detail.refetch();
                            }}
                        >
                            <RefreshCw aria-hidden />
                            Muat ulang
                        </Button>
                    </div>
                }
            />

            <OfflineBanner message="Anda sedang offline. Perubahan tidak dikirim." />

            {detail.isPending || dokter === undefined ? (
                <SkeletonRows rows={4} />
            ) : (
                <>
                    <div className="flex flex-wrap gap-2">
                        <AdminVerifikasiBadge status={dokter.status_verifikasi} />
                        <AdminAktifBadge aktif={dokter.status_aktif} />
                        <AdminTelemedisinBadge tersedia={dokter.tersedia_telemedisin} />
                    </div>

                    <div
                        role="group"
                        aria-label="Pilih tampilan dokter"
                        className="flex flex-wrap gap-2"
                    >
                        {TAB.map((item) => (
                            <div key={item.nilai} data-testid="admin-aksi">
                                <Button
                                    type="button"
                                    variant={tab === item.nilai ? 'default' : 'outline'}
                                    aria-pressed={tab === item.nilai}
                                    className="h-11"
                                    onClick={() => {
                                        pilihTab(item.nilai);
                                    }}
                                >
                                    {item.label}
                                </Button>
                            </div>
                        ))}
                    </div>

                    {tab === 'kredensial' ? (
                        <div className="flex flex-col gap-6">
                            <section
                                aria-label="Kredensial dokter"
                                className="flex flex-col gap-3"
                            >
                                <h2 className="text-lg font-semibold">Kredensial</h2>

                                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div className="flex flex-col gap-1">
                                        <dt className="text-muted-foreground text-sm">
                                            STR
                                        </dt>

                                        <dd className="text-base tracking-wide tabular-nums">
                                            {dokter.nomor_str ?? 'Belum diisi'}
                                        </dd>
                                    </div>

                                    <div className="flex flex-col gap-1">
                                        <dt className="text-muted-foreground text-sm">
                                            Masa berlaku STR
                                        </dt>

                                        <dd>
                                            <StrMasaBerlaku dokter={dokter} />
                                        </dd>
                                    </div>

                                    <div className="flex flex-col gap-1">
                                        <dt className="text-muted-foreground text-sm">
                                            SIP
                                        </dt>

                                        <dd className="text-base tracking-wide tabular-nums">
                                            {dokter.nomor_sip ?? 'Belum diisi'}
                                        </dd>
                                    </div>

                                    <div className="flex flex-col gap-1">
                                        <dt className="text-muted-foreground text-sm">
                                            Masa berlaku SIP
                                        </dt>

                                        <dd className="text-base tabular-nums">
                                            {dokter.sip_berlaku_sampai === null
                                                ? 'Belum diisi'
                                                : `s.d. ${tanggalSingkat(dokter.sip_berlaku_sampai)}`}
                                        </dd>
                                    </div>
                                </dl>

                                <p className="text-muted-foreground text-sm">
                                    Nomor STR dan SIP ditampilkan tersamarkan. Berkas
                                    kredensial tidak dipublikasikan oleh server.
                                </p>
                            </section>

                            <Separator />

                            <section
                                aria-label="Kelayakan dokter"
                                className="flex flex-col gap-3"
                            >
                                <h2 className="text-lg font-semibold">Kelayakan</h2>

                                <p className="text-base tabular-nums">
                                    Booking aktif: {dampak?.booking_aktif ?? 0} · Jadwal
                                    aktif: {dampak?.jadwal_aktif ?? 0} · Libur mendatang:{' '}
                                    {dampak?.libur_mendatang ?? 0}
                                </p>

                                {dokter.status_verifikasi === 'pending' ? (
                                    <div
                                        data-testid="admin-aksi"
                                        className="flex flex-wrap gap-2"
                                    >
                                        <Button
                                            type="button"
                                            className="h-11"
                                            data-testid="admin-tulis"
                                            disabled={!online}
                                            onClick={(event) => {
                                                pemicuVerifikasi.current =
                                                    event.currentTarget;
                                                setDialogVerifikasi(true);
                                            }}
                                        >
                                            Verifikasi / Tolak
                                        </Button>
                                    </div>
                                ) : null}

                                <div
                                    data-testid="admin-aksi"
                                    className="flex flex-wrap gap-2"
                                >
                                    {dokter.status_aktif ? (
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            className="h-11"
                                            data-testid="admin-tulis"
                                            disabled={!online || detail.isPending}
                                            data-slot="admin-nonaktif-dokter"
                                            onClick={(event) => {
                                                pemicuNonaktif.current =
                                                    event.currentTarget;
                                                setDialogNonaktif(true);
                                            }}
                                        >
                                            Nonaktifkan dokter
                                        </Button>
                                    ) : (
                                        <Button
                                            type="button"
                                            className="h-11"
                                            data-testid="admin-tulis"
                                            disabled={!online || status.isPending}
                                            onClick={() => {
                                                status.mutate({
                                                    status_aktif: true,
                                                });
                                            }}
                                        >
                                            Aktifkan dokter
                                        </Button>
                                    )}

                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="h-11"
                                        data-testid="admin-tulis"
                                        aria-pressed={dokter.tersedia_telemedisin}
                                        disabled={!online || status.isPending}
                                        onClick={() => {
                                            status.mutate({
                                                status_aktif: dokter.status_aktif,
                                                tersedia_telemedisin:
                                                    !dokter.tersedia_telemedisin,
                                            });
                                        }}
                                    >
                                        {dokter.tersedia_telemedisin
                                            ? 'Tersedia telemedisin'
                                            : 'Tidak tersedia telemedisin'}
                                    </Button>
                                </div>
                            </section>
                        </div>
                    ) : null}

                    {tab === 'jadwal' ? (
                        <AdminJadwalSection dokterId={dokter.id} />
                    ) : null}

                    {tab === 'libur' ? <AdminLiburSection dokterId={dokter.id} /> : null}
                </>
            )}

            <Dialog open={dialogNonaktif} onOpenChange={setDialogNonaktif}>
                <DialogContent
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        pemicuNonaktif.current?.focus();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Nonaktifkan {nama}?</DialogTitle>

                        <DialogDescription>
                            Dokter tidak akan tampil di direktori dan tidak menerima
                            booking baru.
                        </DialogDescription>
                    </DialogHeader>

                    <p className="text-base tabular-nums">
                        {dampak?.booking_aktif ?? 0} booking aktif tidak otomatis
                        dibatalkan dan tetap berjalan.
                    </p>

                    <DialogFooter
                        data-testid="admin-aksi"
                        className="flex flex-wrap gap-2 sm:justify-end"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => {
                                setDialogNonaktif(false);
                            }}
                        >
                            Batal
                        </Button>

                        <Button
                            type="button"
                            variant="destructive"
                            className="h-11"
                            data-testid="admin-tulis"
                            disabled={!online || status.isPending}
                            onClick={() => {
                                status.mutate({ status_aktif: false });
                            }}
                        >
                            Nonaktifkan dokter
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={dialogVerifikasi} onOpenChange={setDialogVerifikasi}>
                <DialogContent
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        pemicuVerifikasi.current?.focus();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Verifikasi dokter ini?</DialogTitle>

                        <DialogDescription>
                            Keputusan ini dicatat pada jejak audit dan hanya dapat dibuat
                            satu kali.
                        </DialogDescription>
                    </DialogHeader>

                    {dokter === undefined ? (
                        <EmptyState
                            compact
                            title="Data dokter belum dimuat."
                            description="Muat ulang halaman lalu coba lagi."
                        />
                    ) : (
                        <div className="flex flex-col gap-2 text-base">
                            <p className="font-semibold">
                                {dokter.nama_lengkap ?? `Dokter #${dokter.id}`}
                            </p>

                            <p className="tabular-nums">
                                STR {dokter.nomor_str ?? 'Belum diisi'} · berlaku sampai{' '}
                                {tanggalSingkat(dokter.str_berlaku_sampai)}
                            </p>

                            <p className="text-muted-foreground text-sm">
                                Dengan memverifikasi, dokter ini dapat tampil di
                                direktori publik dan menerima booking baru.
                            </p>
                        </div>
                    )}

                    <DialogFooter
                        data-testid="admin-aksi"
                        className="flex flex-wrap gap-2 sm:justify-end"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => {
                                setDialogVerifikasi(false);
                            }}
                        >
                            Batal
                        </Button>

                        <Button
                            type="button"
                            variant="destructive"
                            className="h-11"
                            data-testid="admin-tulis"
                            disabled={!online || putuskan.isPending}
                            onClick={() => {
                                putuskan.mutate('ditolak');
                            }}
                        >
                            Tolak verifikasi
                        </Button>

                        <Button
                            type="button"
                            className="h-11"
                            data-testid="admin-tulis"
                            disabled={!online || putuskan.isPending}
                            onClick={() => {
                                putuskan.mutate('terverifikasi');
                            }}
                        >
                            Verifikasi dokter
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
