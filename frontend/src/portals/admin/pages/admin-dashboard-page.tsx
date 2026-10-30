import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { formatRupiah, formatTanggal, formatWaktu } from '@/lib/format';
import {
    STATUS_BOOKING_LAPORAN,
    adminAuditOptions,
    adminDokterOptions,
    adminLaporanBookingOptions,
    adminLaporanKehadiranOptions,
    adminLaporanPendapatanOptions,
} from '@/lib/api/admin';
import {
    labelAksi,
    labelStatusBooking,
} from '@/portals/admin/features/admin/format-admin';
import { AdminGate } from '@/portals/admin/features/admin/admin-gate';
import { PageHeader } from '@/shared/components/layout/page-header';
import { SkeletonRows } from '@/shared/components/states/loading-state';
import { ErrorState } from '@/shared/components/states/error-state';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

/**
 * `/admin/dashboard` - the admin portal's front page.
 *
 * Every number here is read from an endpoint that already existed: the doctor
 * list's `meta.total` (also its `status_verifikasi`/`status_aktif` filters, one
 * `per_page:1` query each - the count is the meta, not the rows), the three
 * report aggregates over the fixed 30-day window (`/admin/laporan/*`, whose
 * service refuses ranges beyond 366 days), and the newest five audit rows. No
 * dashboard-only endpoint is added - a summary screen that requires its own API
 * is a second place for the same arithmetic to drift.
 *
 * The 30-day window is fixed rather than user-configurable: `/admin/laporan`
 * already carries the range picker for anyone who wants another one, and a
 * front page whose numbers move with a control nobody remembers touching is a
 * worse front page. `harian` from the booking report drives the trend strip as
 * plain CSS bars - a charting dependency for thirty rectangles is not a trade
 * this portal needs to make.
 */
export function AdminDashboardPage() {
    useDocumentTitle('Dasbor Admin');

    const online = useOnlineStatus();

    const { dari, sampai } = useMemo(() => {
        const sekarang = new Date();
        const mulai = new Date(sekarang);
        mulai.setDate(mulai.getDate() - 29);

        return { dari: keYmd(mulai), sampai: keYmd(sekarang) };
    }, []);

    const rentang = useMemo(() => ({ dari, sampai }), [dari, sampai]);

    const dokter = useQuery(adminDokterOptions({ per_page: 1 }));
    const terverifikasi = useQuery(
        adminDokterOptions({ per_page: 1, status_verifikasi: 'terverifikasi' }),
    );
    const menunggu = useQuery(
        adminDokterOptions({ per_page: 1, status_verifikasi: 'pending' }),
    );
    const ditolak = useQuery(
        adminDokterOptions({ per_page: 1, status_verifikasi: 'ditolak' }),
    );
    const dokterAktif = useQuery(
        adminDokterOptions({ per_page: 1, status_aktif: true }),
    );
    const booking = useQuery(adminLaporanBookingOptions(rentang));
    const pendapatan = useQuery(adminLaporanPendapatanOptions(rentang));
    const kehadiran = useQuery(adminLaporanKehadiranOptions(rentang));
    const audit = useQuery(adminAuditOptions({ page: 1, per_page: 5 }));

    const semuaQuery = [
        dokter,
        terverifikasi,
        menunggu,
        ditolak,
        dokterAktif,
        booking,
        pendapatan,
        kehadiran,
    ];

    const muatUlang = (): void => {
        for (const q of semuaQuery) {
            void q.refetch();
        }

        void audit.refetch();
    };

    const memuat = semuaQuery.some((q) => q.isPending);
    const gagal = semuaQuery.find((q) => q.isError);

    const ringkasBooking = booking.data?.data.ringkasan;
    const ringkasPendapatan = pendapatan.data?.data.ringkasan;
    const ringkasKehadiran = kehadiran.data?.data.ringkasan;
    const harian = booking.data?.data.harian ?? [];
    const maksHarian = Math.max(1, ...harian.map((h) => h.total));

    return (
        <AdminGate>
            <PageHeader
                title="Dasbor Admin"
                description={`${formatTanggal(dari)} – ${formatTanggal(sampai)} (30 hari terakhir)`}
                action={
                    <Button type="button" variant="outline" onClick={muatUlang}>
                        Muat ulang
                    </Button>
                }
            />

            {memuat ? (
                <SkeletonRows rows={6} />
            ) : gagal ? (
                <ErrorState error={gagal.error} onRetry={muatUlang} />
            ) : (
                <>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 className="text-xl font-semibold">Selamat datang, Admin 👋</h2>

                            <p className="text-muted-foreground text-sm">
                                Ringkasan aktivitas klinik 30 hari terakhir.
                            </p>
                        </div>

                        <span
                            className={
                                online
                                    ? 'inline-flex items-center gap-1.5 rounded-full border border-green-200 bg-green-50 px-3 py-1 text-xs font-medium text-green-700'
                                    : 'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium text-muted-foreground'
                            }
                        >
                            <span
                                className={
                                    online
                                        ? 'size-1.5 rounded-full bg-green-500'
                                        : 'size-1.5 rounded-full bg-muted-foreground'
                                }
                            />

                            {online ? 'SISTEM ONLINE' : 'SISTEM OFFLINE'}
                        </span>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <Kartu
                            label="Total Dokter"
                            nilai={String(dokter.data?.meta?.total ?? 0)}
                            keterangan={`${terverifikasi.data?.meta?.total ?? 0} terverifikasi`}
                            keteranganHijau
                        />
                        <Kartu
                            label="Booking"
                            nilai={String(ringkasBooking?.total ?? 0)}
                            keterangan={`${ringkasBooking?.per_status.selesai ?? 0} selesai`}
                            keteranganHijau
                        />
                        <Kartu
                            label="Pendapatan"
                            nilai={formatRupiah(ringkasPendapatan?.total ?? '0')}
                            keterangan={`${ringkasPendapatan?.jumlah_invoice ?? 0} invoice lunas`}
                        />
                        <Kartu
                            label="Kehadiran"
                            nilai={String(ringkasKehadiran?.check_in ?? 0)}
                            keterangan={`check-in dari ${ringkasKehadiran?.total ?? 0} terjadwal`}
                        />
                    </div>

                    <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Tren booking harian</CardTitle>

                                <p className="text-muted-foreground text-xs">
                                    Batang per hari dengan aktivitas
                                </p>
                            </CardHeader>

                            <CardContent>
                                {harian.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        Belum ada booking pada rentang ini.
                                    </p>
                                ) : (
                                    <>
                                        <div className="flex h-24 items-end gap-[2px]">
                                            {harian.map((h) => (
                                                <div
                                                    key={h.tanggal}
                                                    title={`${formatTanggal(h.tanggal)}: ${h.total} booking`}
                                                    style={{
                                                        height: `${Math.max(6, (h.total / maksHarian) * 100)}%`,
                                                    }}
                                                    className="flex-1 rounded-sm bg-primary/60 transition-colors hover:bg-primary"
                                                />
                                            ))}
                                        </div>

                                        <div className="text-muted-foreground mt-1 flex justify-between text-xs">
                                            <span>{formatTanggal(harian[0]?.tanggal ?? dari)}</span>

                                            <span>
                                                {formatTanggal(
                                                    harian[harian.length - 1]?.tanggal ?? sampai,
                                                )}
                                            </span>
                                        </div>
                                    </>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle className="text-base">Rekap dokter</CardTitle>

                                <Link
                                    to="/admin/dokter"
                                    className="text-sm text-primary hover:underline"
                                >
                                    Detail →
                                </Link>
                            </CardHeader>

                            <CardContent className="space-y-2">
                                <div className="rounded-md border border-blue-200 bg-blue-50 px-3 py-2">
                                    <p className="text-2xl font-bold text-blue-700">
                                        {terverifikasi.data?.meta?.total ?? 0}
                                    </p>

                                    <p className="text-xs font-medium text-blue-700">
                                        Terverifikasi
                                    </p>
                                </div>

                                <BarisRekap
                                    label="Menunggu verifikasi"
                                    nilai={menunggu.data?.meta?.total ?? 0}
                                    warna="text-amber-600"
                                />
                                <BarisRekap
                                    label="Ditolak"
                                    nilai={ditolak.data?.meta?.total ?? 0}
                                    warna="text-red-600"
                                />
                                <BarisRekap
                                    label="Akun aktif"
                                    nilai={dokterAktif.data?.meta?.total ?? 0}
                                />
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle className="text-base">Status booking</CardTitle>

                                <Link
                                    to="/admin/laporan"
                                    className="text-sm text-primary hover:underline"
                                >
                                    Laporan →
                                </Link>
                            </CardHeader>

                            <CardContent className="space-y-2">
                                {STATUS_BOOKING_LAPORAN.filter(
                                    (status) => (ringkasBooking?.per_status[status] ?? 0) > 0,
                                ).map((status) => (
                                    <div
                                        key={status}
                                        className="flex items-center justify-between rounded-md border px-3 py-2 text-sm"
                                    >
                                        <span>{labelStatusBooking(status)}</span>

                                        <Badge variant="outline">
                                            {ringkasBooking?.per_status[status] ?? 0}
                                        </Badge>
                                    </div>
                                ))}

                                {(ringkasBooking?.total ?? 0) === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        Belum ada booking pada rentang ini.
                                    </p>
                                ) : null}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle className="text-base">Aktivitas terkini</CardTitle>

                                <Link
                                    to="/admin/audit-log"
                                    className="text-sm text-primary hover:underline"
                                >
                                    Lihat semua →
                                </Link>
                            </CardHeader>

                            <CardContent className="space-y-2">
                                {(audit.data?.data.audit ?? []).map((baris) => (
                                    <div
                                        key={baris.id}
                                        className="flex items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">
                                                {baris.tabel_target} · {baris.record_id ?? '-'}
                                            </p>

                                            <p className="text-muted-foreground text-xs">
                                                {formatWaktu(baris.dibuat_at)}
                                            </p>
                                        </div>

                                        <Badge variant="outline">{labelAksi(baris.aksi)}</Badge>
                                    </div>
                                ))}

                                {audit.isError ? (
                                    <p className="text-muted-foreground text-sm">
                                        Aktivitas gagal dimuat.
                                    </p>
                                ) : null}

                                {!audit.isError && (audit.data?.data.audit ?? []).length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        Belum ada aktivitas tercatat.
                                    </p>
                                ) : null}
                            </CardContent>
                        </Card>
                    </div>
                </>
            )}
        </AdminGate>
    );
}

function Kartu({
    label,
    nilai,
    keterangan,
    keteranganHijau = false,
}: {
    label: string;
    nilai: string;
    keterangan: string;
    keteranganHijau?: boolean;
}) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <p className="text-muted-foreground text-sm">{label}</p>

                <p className="text-2xl font-bold">{nilai}</p>
            </CardHeader>

            <CardContent>
                <p
                    className={
                        keteranganHijau
                            ? 'text-xs font-medium text-green-600'
                            : 'text-muted-foreground text-xs'
                    }
                >
                    {keterangan}
                </p>
            </CardContent>
        </Card>
    );
}

function BarisRekap({
    label,
    nilai,
    warna,
}: {
    label: string;
    nilai: number;
    warna?: string;
}) {
    return (
        <div className="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
            <span>{label}</span>

            <span className={`font-semibold ${warna ?? ''}`}>{nilai}</span>
        </div>
    );
}

function keYmd(tanggal: Date): string {
    const tahun = String(tanggal.getFullYear()).padStart(4, '0');
    const bulan = String(tanggal.getMonth() + 1).padStart(2, '0');
    const hari = String(tanggal.getDate()).padStart(2, '0');

    return `${tahun}-${bulan}-${hari}`;
}
