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
 * ## The page is a sequence of titled sections, not a card pile
 *
 * The layout follows the reference admin screen this portal was modelled on,
 * top to bottom: a welcome banner carrying the online pill, a four-card
 * summary strip, a "Grafik & ringkasan" pair (trend chart beside the booking
 * breakdown), a "Log aktivitas sistem" table, and a "Statistik dokter" strip
 * that breaks the first card's total into its verification and activity
 * states. Each section after the cards carries its jump link in the heading -
 * the dashboard's job is to answer "is everything fine" and to hand the
 * "why" question to the page that owns it (`/admin/laporan`,
 * `/admin/audit-log`, `/admin/dokter`), which is also why the audit feed is a
 * fixed five rows: it is a glance, not the log itself.
 *
 * The 30-day window is fixed rather than user-configurable: `/admin/laporan`
 * already carries the range picker for anyone who wants another one, and a
 * front page whose numbers move with a control nobody remembers touching is a
 * worse front page.
 *
 * ## The trend strip draws all thirty days, including the empty ones
 *
 * `harian` only lists the days the report found rows for, so plotting it
 * directly makes the axis a list of "days something happened" - which reads as
 * a longer, denser fortnight than the window actually was. The strip rebuilds
 * the full `dari`..`sampai` series and draws the empty days as muted stubs at
 * the baseline: a gap is information ("nothing happened on the 26th"), and a
 * true timeline is what makes it visible. Bars stay plain CSS - a charting
 * dependency for thirty rectangles is not a trade this portal needs to make.
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
    const auditRows = audit.data?.data.audit ?? [];

    const totalDokter = dokter.data?.meta?.total ?? 0;
    const jumlahTerverifikasi = terverifikasi.data?.meta?.total ?? 0;
    const jumlahMenunggu = menunggu.data?.meta?.total ?? 0;
    const jumlahDitolak = ditolak.data?.meta?.total ?? 0;
    const jumlahAktif = dokterAktif.data?.meta?.total ?? 0;

    const deretHarian = useMemo(
        () => susunHarianLengkap(dari, booking.data?.data.harian ?? []),
        [dari, booking.data],
    );
    const maksHarian = Math.max(1, ...deretHarian.map((h) => h.total));

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
                <div className="space-y-8">
                    {/**
                     * The welcome banner. One card-width block rather than two
                     * loose columns: the pill belongs to the banner the way a
                     * status light belongs on an instrument panel - right edge,
                     * always in the same place, never scrolling past.
                     */}
                    <section className="bg-card flex flex-wrap items-center justify-between gap-3 rounded-xl border p-5">
                        <div>
                            <h2 className="text-xl font-semibold">
                                Selamat datang, Admin 👋
                            </h2>

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
                    </section>

                    {/** The four-card summary strip - one glance, four numbers. */}
                    <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <Kartu
                            label="Total Dokter"
                            nilai={String(totalDokter)}
                            keterangan={`${jumlahTerverifikasi} terverifikasi`}
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
                    </section>

                    {/**
                     * Grafik & ringkasan: the trend chart beside the booking
                     * breakdown. Equal columns and `items-stretch` so the two
                     * cards share a bottom edge - ragged heights are what made
                     * the earlier 2x2 grid read as a pile rather than a row.
                     */}
                    <section className="space-y-3">
                        <JudulSeksi judul="Grafik & ringkasan" />

                        <div className="grid grid-cols-1 items-stretch gap-6 lg:grid-cols-2">
                            <Card className="h-full">
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Tren booking harian
                                    </CardTitle>

                                    <p className="text-muted-foreground text-xs">
                                        Batang per hari dalam rentang - hari
                                        kosong tetap digambar
                                    </p>
                                </CardHeader>

                                <CardContent>
                                    {(ringkasBooking?.total ?? 0) === 0 ? (
                                        <p className="text-muted-foreground text-sm">
                                            Belum ada booking pada rentang ini.
                                        </p>
                                    ) : (
                                        <>
                                            <div className="flex items-center justify-between text-xs">
                                                <span className="text-muted-foreground inline-flex items-center gap-1.5">
                                                    <span className="bg-primary/60 size-2 rounded-full" />

                                                    Booking
                                                </span>

                                                <span className="text-muted-foreground">
                                                    skala maks {maksHarian}
                                                </span>
                                            </div>

                                            <div className="mt-2 flex h-28 items-end gap-[2px]">
                                                {deretHarian.map((h) => (
                                                    <div
                                                        key={h.tanggal}
                                                        title={`${formatTanggal(h.tanggal)}: ${h.total} booking`}
                                                        style={{
                                                            height: `${Math.max(6, (h.total / maksHarian) * 100)}%`,
                                                        }}
                                                        className={
                                                            h.total === 0
                                                                ? 'bg-muted flex-1 rounded-sm'
                                                                : 'bg-primary/60 flex-1 rounded-sm transition-colors hover:bg-primary'
                                                        }
                                                    />
                                                ))}
                                            </div>

                                            <div className="text-muted-foreground mt-1 flex justify-between text-xs">
                                                <span>{formatTanggal(dari)}</span>

                                                <span>{formatTanggal(sampai)}</span>
                                            </div>
                                        </>
                                    )}
                                </CardContent>
                            </Card>

                            <Card className="h-full">
                                <CardHeader className="flex flex-row items-center justify-between">
                                    <CardTitle className="text-base">
                                        Status booking
                                    </CardTitle>

                                    <Link
                                        to="/admin/laporan"
                                        className="text-sm text-primary hover:underline"
                                    >
                                        Laporan →
                                    </Link>
                                </CardHeader>

                                <CardContent className="space-y-2">
                                    {STATUS_BOOKING_LAPORAN.filter(
                                        (status) =>
                                            (ringkasBooking?.per_status[status] ??
                                                0) > 0,
                                    ).map((status) => (
                                        <div
                                            key={status}
                                            className="flex items-center justify-between rounded-md border px-3 py-2 text-sm"
                                        >
                                            <span>
                                                {labelStatusBooking(status)}
                                            </span>

                                            <Badge variant="outline">
                                                {ringkasBooking?.per_status[
                                                    status
                                                ] ?? 0}
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
                        </div>
                    </section>

                    {/**
                     * Log aktivitas sistem: the five newest audit rows as a
                     * table, the columns mirroring the full log's own so the
                     * eye learns one shape. The heading owns the jump link -
                     * "Semua →" is the page that answers why.
                     */}
                    <section className="space-y-3">
                        <JudulSeksi
                            judul="Log aktivitas sistem"
                            ke="/admin/audit-log"
                            labelTautan="Semua →"
                        />

                        <Card>
                            <CardContent className="pt-6">
                                {audit.isError ? (
                                    <p className="text-muted-foreground text-sm">
                                        Aktivitas gagal dimuat.
                                    </p>
                                ) : auditRows.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        Belum ada aktivitas tercatat.
                                    </p>
                                ) : (
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="text-muted-foreground border-b text-left text-xs">
                                                <th className="py-2 pr-3 font-medium">
                                                    Waktu
                                                </th>
                                                <th className="py-2 pr-3 font-medium">
                                                    Tabel
                                                </th>
                                                <th className="py-2 pr-3 font-medium">
                                                    Rekaman
                                                </th>
                                                <th className="py-2 text-right font-medium">
                                                    Aksi
                                                </th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            {auditRows.map((baris) => (
                                                <tr
                                                    key={baris.id}
                                                    className="border-b last:border-b-0"
                                                >
                                                    <td className="text-muted-foreground py-2 pr-3 text-xs">
                                                        {formatWaktu(
                                                            baris.dibuat_at,
                                                        )}
                                                    </td>

                                                    <td className="py-2 pr-3">
                                                        {baris.tabel_target}
                                                    </td>

                                                    <td className="py-2 pr-3">
                                                        {baris.record_id ?? '-'}
                                                    </td>

                                                    <td className="py-2 text-right">
                                                        <Badge variant="outline">
                                                            {labelAksi(
                                                                baris.aksi,
                                                            )}
                                                        </Badge>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </CardContent>
                        </Card>
                    </section>

                    {/**
                     * Statistik dokter: the first card's total broken into its
                     * four standing states, as an even strip. Numbers carry the
                     * colour their state deserves - amber and red are the two
                     * an operator must act on; the rest stay quiet.
                     */}
                    <section className="space-y-3">
                        <JudulSeksi
                            judul="Statistik dokter"
                            ke="/admin/dokter"
                            labelTautan="Detail →"
                        />

                        <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
                            <StatistikKecil
                                label="Terverifikasi"
                                nilai={jumlahTerverifikasi}
                                ket={`dari total ${totalDokter} dokter`}
                            />
                            <StatistikKecil
                                label="Menunggu verifikasi"
                                nilai={jumlahMenunggu}
                                ket={
                                    jumlahMenunggu > 0
                                        ? 'perlu ditinjau'
                                        : 'semua sudah ditinjau'
                                }
                                warna={
                                    jumlahMenunggu > 0 ? 'text-amber-600' : undefined
                                }
                            />
                            <StatistikKecil
                                label="Ditolak"
                                nilai={jumlahDitolak}
                                warna={jumlahDitolak > 0 ? 'text-red-600' : undefined}
                            />
                            <StatistikKecil
                                label="Akun aktif"
                                nilai={jumlahAktif}
                                ket="siap melayani"
                                warna="text-green-600"
                            />
                        </div>
                    </section>
                </div>
            )}
        </AdminGate>
    );
}

function JudulSeksi({
    judul,
    ke,
    labelTautan,
}: {
    judul: string;
    ke?: string;
    labelTautan?: string;
}) {
    return (
        <div className="flex items-center justify-between gap-3">
            <h2 className="text-base font-semibold">{judul}</h2>

            {ke !== undefined ? (
                <Link
                    to={ke}
                    className="text-sm text-primary hover:underline"
                >
                    {labelTautan}
                </Link>
            ) : null}
        </div>
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

function StatistikKecil({
    label,
    nilai,
    ket,
    warna,
}: {
    label: string;
    nilai: number;
    ket?: string;
    warna?: string;
}) {
    return (
        <div className="bg-card rounded-xl border p-4">
            <p className="text-muted-foreground text-sm">{label}</p>

            <p className={`text-2xl font-bold ${warna ?? ''}`}>{nilai}</p>

            {ket !== undefined ? (
                <p className="text-muted-foreground text-xs">{ket}</p>
            ) : null}
        </div>
    );
}

/**
 * Rebuild `harian` as one row per day across `dari`..`sampai`, with the
 * report's zero-days filled in, so the trend axis is a true timeline rather
 * than a list of the days that happened to have bookings. `dari` is
 * `YYYY-MM-DD`; `harian` is the report's own day-keyed rows, absent days
 * included as absent.
 */
function susunHarianLengkap(
    dari: string,
    harian: { tanggal: string; total: number }[],
): { tanggal: string; total: number }[] {
    const peta = new Map(harian.map((h) => [h.tanggal, h.total]));
    const mulai = new Date(`${dari}T00:00:00`);
    const hasil: { tanggal: string; total: number }[] = [];

    for (let i = 0; i < 30; i += 1) {
        const hari = new Date(mulai);
        hari.setDate(mulai.getDate() + i);

        const ymd = keYmd(hari);

        hasil.push({ tanggal: ymd, total: peta.get(ymd) ?? 0 });
    }

    return hasil;
}

function keYmd(tanggal: Date): string {
    const tahun = String(tanggal.getFullYear()).padStart(4, '0');
    const bulan = String(tanggal.getMonth() + 1).padStart(2, '0');
    const hari = String(tanggal.getDate()).padStart(2, '0');

    return `${tahun}-${bulan}-${hari}`;
}
