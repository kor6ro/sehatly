import { CircleCheck } from 'lucide-react';
import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { formatRupiah, formatTanggal, formatWaktu } from '@/lib/format';
import { meOptions } from '@/lib/api/me';
import {
    STATUS_BOOKING_LAPORAN,
    adminAuditOptions,
    adminDokterOptions,
    adminLaporanBookingOptions,
    adminLaporanKehadiranOptions,
    adminLaporanPendapatanOptions,
    adminPenggunaOptions,
    adminPerluTindakanOptions,
} from '@/lib/api/admin';
import {
    labelAksi,
    labelStatusBooking,
    labelTabelTarget,
} from '@/portals/admin/features/admin/format-admin';
import { AdminGate } from '@/portals/admin/features/admin/admin-gate';
import { PageHeader } from '@/shared/components/layout/page-header';
import { SkeletonRows } from '@/shared/components/states/loading-state';
import { ErrorState } from '@/shared/components/states/error-state';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

/**
 * `/admin/dashboard` - the admin portal's front page.
 *
 * Every number here is read from an endpoint that already existed: the doctor
 * list's `meta.total` (also its `status_verifikasi`/`status_aktif` filters, one
 * `per_page:1` query each - the count is the meta, not the rows), the same trick
 * over the account directory for its four totals, the three report aggregates
 * over the fixed 30-day window (`/admin/laporan/*`, whose service refuses ranges
 * beyond 366 days), and the newest five audit rows. No dashboard-only endpoint
 * is added - a summary screen that requires its own API is a second place for
 * the same arithmetic to drift.
 *
 * ## The page is a sequence of titled sections, not a card pile
 *
 * The layout follows the reference admin screen this portal was modelled on,
 * top to bottom: a welcome banner carrying the online pill, a four-card
 * summary strip, a "Grafik & ringkasan" pair (trend chart beside the booking
 * breakdown), one "Statistik" section that breaks the first card's total into
 * its verification and activity states beside the account directory's own four
 * counts, and finally the "Log aktivitas sistem" table. Statistics come before
 * the log because they answer "does anything need me" and the log answers
 * "what happened" - a chronology is the thing to scroll when the state of the
 * world is already known. Each section after the cards carries its jump link
 * in the heading - the dashboard's job is to answer "is everything fine" and
 * to hand the "why" question to the page that owns it (`/admin/laporan`,
 * `/admin/audit-log`, `/admin/dokter`, `/admin/pengguna`), which is also why
 * the audit feed is a fixed five rows: it is a glance, not the log itself.
 *
 * The 30-day window is fixed rather than user-configurable: `/admin/laporan`
 * already carries the range picker for anyone who wants another one, and a
 * front page whose numbers move with a control nobody remembers touching is a
 * worse front page. It is also the window "pasien baru" counts over, which is
 * why that figure states its own range in the label rather than inheriting the
 * banner's silently.
 *
 * ## The trend chart draws all thirty days, including the empty ones
 *
 * `harian` only lists the days the report found rows for, so plotting it
 * directly makes the axis a list of "days something happened" - which reads as
 * a longer, denser fortnight than the window actually was. The chart rebuilds
 * the full `dari`..`sampai` series, so an empty day is a point ON the baseline
 * ("nothing happened on the 26th") and the silhouette stays a true timeline.
 * The line is a cardinal spline rather than a polyline: thirty straight
 * segments read as a saw, and the shape this card is about is the trend.
 * Everything is hand-rolled SVG - a charting dependency for one line is not a
 * trade this portal needs to make.
 */
export function AdminDashboardPage() {
    useDocumentTitle('Dasbor Admin');

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
    const perluTindakan = useQuery(adminPerluTindakanOptions());
    const me = useQuery(meOptions());

    /**
     * The account directory's four counts, each a `per_page:1` query whose
     * answer is `meta.total` - the same trick the doctor cards above use, so
     * a count costs a row, not a second endpoint.
     *
     * `tipe: 'staf'` is the server's ALIAS for the five staff types, which is
     * why there is no per-type query here: the sidebar, this strip and the
     * list page all spell "staff" that one way. `dibuat_dari` is the window's
     * own first day, so "pasien baru" counts the same thirty days the banner
     * advertises rather than a different, unstated one.
     */
    const pasien = useQuery(
        adminPenggunaOptions({ per_page: 1, tipe: 'pasien' }),
    );
    const pasienBaru = useQuery(
        adminPenggunaOptions({ per_page: 1, tipe: 'pasien', dibuat_dari: dari }),
    );
    const staf = useQuery(adminPenggunaOptions({ per_page: 1, tipe: 'staf' }));
    const stafAktif = useQuery(
        adminPenggunaOptions({ per_page: 1, tipe: 'staf', status: 'aktif' }),
    );

    const semuaQuery = [
        dokter,
        terverifikasi,
        menunggu,
        ditolak,
        dokterAktif,
        booking,
        pendapatan,
        kehadiran,
        pasien,
        pasienBaru,
        staf,
        stafAktif,
    ];

    const muatUlang = (): void => {
        for (const q of semuaQuery) {
            void q.refetch();
        }

        void audit.refetch();
        void perluTindakan.refetch();
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

    const totalPasien = pasien.data?.meta?.total ?? 0;
    const jumlahPasienBaru = pasienBaru.data?.meta?.total ?? 0;
    const totalStaf = staf.data?.meta?.total ?? 0;
    const jumlahStafAktif = stafAktif.data?.meta?.total ?? 0;

    const deretHarian = useMemo(
        () => susunHarianLengkap(dari, booking.data?.data.harian ?? []),
        [dari, booking.data],
    );
    const maksHarian = Math.max(1, ...deretHarian.map((h) => h.total));

    return (
        <AdminGate>
            <PageHeader
                title={`Halo, ${me?.data?.data.user?.nama_lengkap?.split(' ')[0] ?? 'Admin'}`}
                description="Yang perlu kamu tangani hari ini."
            />

            {memuat ? (
                <SkeletonRows rows={6} />
            ) : gagal ? (
                <ErrorState error={gagal.error} onRetry={muatUlang} />
            ) : (
                <div className="space-y-8">
                    {/* Perlu tindakan: shown only when there is something to act on */}
                    <PerluTindakanCard data={perluTindakan.data ?? null} />

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
                            <Card className="flex h-full flex-col">
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Tren booking harian
                                    </CardTitle>
                                    <p className="text-muted-foreground text-xs">
                                        {formatTanggal(dari)} – {formatTanggal(sampai)}
                                    </p>
                                </CardHeader>

                                <CardContent className="flex min-h-0 flex-1 flex-col">
                                    {(ringkasBooking?.total ?? 0) === 0 ? (
                                        <p className="text-muted-foreground text-sm">
                                            Belum ada booking pada rentang ini.
                                        </p>
                                    ) : (
                                        <LineChart
                                            data={deretHarian}
                                            maks={maksHarian}
                                            formatLabel={formatTanggal}
                                        />
                                    )}
                                </CardContent>
                            </Card>

                            {/**
                             * Status booking: the eight DDL states as plain
                             * rows - label, count, share - and deliberately
                             * QUIET.
                             *
                             * This card sits beside the trend chart, so it
                             * must not out-shout it. Seven coloured chips,
                             * seven bar hues and seven bordered boxes made it
                             * the loudest thing on the page while carrying
                             * the least structure (a list). So: no badges, no
                             * per-row border, ONE hue for every bar - what is
                             * left are the three facts an operator reads
                             * (which state, how many, how much of the whole),
                             * with hierarchy carried by weight and the
                             * muted/foreground split rather than by colour.
                             *
                             * The bar earns its place because 63 vs 3 is a
                             * RATIO: the count is the value, the bar is the
                             * shape of it. It is decorative - the numbers
                             * beside it are the real values - so assistive
                             * tech skips it, and the denominator lives in the
                             * card header, because a percentage without one is
                             * a number that cannot be acted on.
                             *
                             * Zero-count states stay filtered out: a row of
                             * "0, 0%, empty bar" is not information, and the
                             * report page (`/admin/laporan`) is where the
                             * fixed eight-column table with its zeros belongs.
                             * Rows keep the ENUM's own order, which is the
                             * lifecycle order - waiting, live, finished,
                             * ended badly.
                             */}
                            <Card className="h-full">
                                <CardHeader className="flex flex-row items-center justify-between">
                                    <div>
                                        <CardTitle className="text-base">
                                            Status booking
                                        </CardTitle>

                                        <p className="text-muted-foreground text-xs">
                                            {ringkasBooking?.total ?? 0}{' '}
                                            booking pada rentang ini
                                        </p>
                                    </div>

                                    <Link
                                        to="/admin/laporan"
                                        className="text-sm text-primary hover:underline"
                                    >
                                        Laporan →
                                    </Link>
                                </CardHeader>

                                <CardContent>
                                    {(ringkasBooking?.total ?? 0) === 0 ? (
                                        <p className="text-muted-foreground text-sm">
                                            Belum ada booking pada rentang ini.
                                        </p>
                                    ) : (
                                        <ul className="divide-y">
                                            {STATUS_BOOKING_LAPORAN.filter(
                                                (status) =>
                                                    (ringkasBooking?.per_status[
                                                        status
                                                    ] ?? 0) > 0,
                                            ).map((status) => {
                                                const nilai =
                                                    ringkasBooking?.per_status[
                                                        status
                                                    ] ?? 0;
                                                const total =
                                                    ringkasBooking?.total ?? 0;
                                                const pct =
                                                    total > 0
                                                        ? Math.round(
                                                              (nilai / total) *
                                                                  100,
                                                          )
                                                        : 0;

                                                return (
                                                    <li
                                                        key={status}
                                                        className="flex flex-col gap-1.5 py-2.5 first:pt-0 last:pb-0"
                                                    >
                                                        <div className="flex items-baseline justify-between gap-3 text-sm">
                                                            <span className="text-muted-foreground truncate">
                                                                {labelStatusBooking(
                                                                    status,
                                                                )}
                                                            </span>

                                                            <span className="flex shrink-0 items-baseline gap-2">
                                                                <span className="font-semibold tabular-nums">
                                                                    {nilai}
                                                                </span>

                                                                <span className="text-muted-foreground w-9 text-right text-xs tabular-nums">
                                                                    {pct}%
                                                                </span>
                                                            </span>
                                                        </div>

                                                        <div
                                                            aria-hidden="true"
                                                            className="bg-muted h-1 overflow-hidden rounded-full"
                                                        >
                                                            <div
                                                                className="h-full rounded-full bg-primary/70"
                                                                style={{
                                                                    width: `${pct}%`,
                                                                }}
                                                            />
                                                        </div>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </section>

                    {/**
                     * Statistik, placed ABOVE the activity log on purpose.
                     *
                     * The two answer different questions: the statistics are a
                     * state of the world ("is anything waiting for me"), the
                     * log is a chronology ("what happened"). A front page that
                     * makes an operator scroll past five rows of history to
                     * find the first has the order backwards.
                     *
                     * The doctor card is a DONUT rather than the bar list it
                     * was: the three verification states are a PARTITION of
                     * the total - they sum to it - which is exactly the shape
                     * a pie exists to draw, and a donut leaves room for the
                     * total in the middle so the denominator is on screen
                     * rather than in a caption. "Akun aktif" is NOT part of
                     * that partition (an active account is also verified), so
                     * it stays out of the ring and is stated as a line under
                     * it instead of becoming a fifth slice that would make
                     * the percentages add up to more than 100.
                     *
                     * The pengguna card keeps its bars: its four numbers are
                     * two different populations over two different
                     * denominators, and no single ring could hold them
                     * honestly.
                     */}
                    <section className="space-y-4">
                        <JudulSeksi judul="Statistik" />

                        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                            <Card className="flex h-full flex-col">
                                <CardHeader className="flex flex-row items-center justify-between pb-2">
                                    <CardTitle className="text-base">Dokter</CardTitle>
                                    <Link
                                        to="/admin/dokter"
                                        className="text-sm text-primary hover:underline"
                                    >
                                        Detail →
                                    </Link>
                                </CardHeader>

                                <CardContent className="flex flex-1 flex-col">
                                    <DonutChart
                                        irisan={[
                                            {
                                                label: 'Terverifikasi',
                                                nilai: jumlahTerverifikasi,
                                                kelas: 'text-green-500',
                                            },
                                            {
                                                label: 'Menunggu verifikasi',
                                                nilai: jumlahMenunggu,
                                                kelas: 'text-amber-500',
                                            },
                                            {
                                                label: 'Ditolak',
                                                nilai: jumlahDitolak,
                                                kelas: 'text-red-500',
                                            },
                                        ]}
                                        total={totalDokter}
                                        labelPusat="dokter"
                                    />

                                    <p className="mt-4 border-t pt-3 text-xs">
                                        <span className="text-green-600 font-semibold">
                                            {jumlahAktif}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {' '}
                                            dari {totalDokter} akun aktif siap
                                            melayani
                                        </span>
                                    </p>
                                </CardContent>
                            </Card>

                            <Card className="flex h-full flex-col">
                                <CardHeader className="flex flex-row items-center justify-between pb-2">
                                    <CardTitle className="text-base">Pengguna</CardTitle>
                                    <Link
                                        to="/admin/pengguna"
                                        className="text-sm text-primary hover:underline"
                                    >
                                        Detail →
                                    </Link>
                                </CardHeader>

                                <CardContent className="flex flex-1 flex-col">
                                    <BarChart
                                        baris={[
                                            {
                                                label: 'Total pasien',
                                                nilai: totalPasien,
                                                total: Math.max(totalPasien, 1),
                                                warna: 'bg-primary/70',
                                            },
                                            {
                                                label: 'Pasien baru (30 hr)',
                                                nilai: jumlahPasienBaru,
                                                total: Math.max(totalPasien, 1),
                                                warna: 'bg-blue-400',
                                            },
                                            {
                                                label: 'Total staf',
                                                nilai: totalStaf,
                                                total: Math.max(totalStaf, 1),
                                                warna: 'bg-violet-400',
                                            },
                                            {
                                                label: 'Staf aktif',
                                                nilai: jumlahStafAktif,
                                                total: Math.max(totalStaf, 1),
                                                warna: 'bg-green-500',
                                            },
                                        ]}
                                        total={totalPasien + totalStaf}
                                        labelTotal="total pengguna"
                                    />
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
                                                        {labelTabelTarget(
                                                            baris.tabel_target,
                                                        )}
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

function LineChart({
    data,
    maks,
    formatLabel,
}: {
    data: { tanggal: string; total: number }[];
    maks: number;
    formatLabel: (t: string) => string;
}) {
    /**
     * The viewBox is sized to the box the card actually gives it (~2:1), so
     * `preserveAspectRatio`'s default `meet` scales the drawing to FILL the
     * card instead of letterboxing it: the old 500x160 drawn into a 160px-tall
     * strip rendered at 1:1 and sat centred with a third of the card empty on
     * each side, which is what read as untidy.
     */
    const W = 720;
    const H = 345;
    const PAD = { top: 18, right: 14, bottom: 30, left: 34 };
    const innerW = W - PAD.left - PAD.right;
    const innerH = H - PAD.top - PAD.bottom;
    const n = data.length;

    // Skala Y minimal 5 agar data kecil tetap terlihat bergelombang
    const skalaY = Math.max(maks, 5);

    const x = (i: number) => PAD.left + (i / Math.max(n - 1, 1)) * innerW;
    const y = (v: number) => PAD.top + innerH - (v / skalaY) * innerH;

    // Smooth cubic bezier path (cardinal spline)
    function smoothPath(pts: { x: number; y: number }[]): string {
        if (pts.length < 2) return '';
        const tension = 0.4;
        let d = `M ${pts[0].x},${pts[0].y}`;
        for (let i = 0; i < pts.length - 1; i++) {
            const p0 = pts[Math.max(i - 1, 0)];
            const p1 = pts[i];
            const p2 = pts[i + 1];
            const p3 = pts[Math.min(i + 2, pts.length - 1)];
            const cp1x = p1.x + (p2.x - p0.x) * tension;
            const cp1y = p1.y + (p2.y - p0.y) * tension;
            const cp2x = p2.x - (p3.x - p1.x) * tension;
            const cp2y = p2.y - (p3.y - p1.y) * tension;
            d += ` C ${cp1x},${cp1y} ${cp2x},${cp2y} ${p2.x},${p2.y}`;
        }
        return d;
    }

    const pts = data.map((d, i) => ({ x: x(i), y: y(d.total) }));
    const linePath = smoothPath(pts);

    // Area: tutup ke baseline
    const baseline = PAD.top + innerH;
    const areaPath = linePath
        + ` L ${x(n - 1)},${baseline} L ${x(0)},${baseline} Z`;

    const yTicks = [0, Math.round(skalaY / 2), skalaY];
    const xLabels = [
        { i: 0 },
        { i: Math.floor((n - 1) / 2) },
        { i: n - 1 },
    ];

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <svg
                viewBox={`0 0 ${W} ${H}`}
                className="min-h-0 w-full flex-1"
                aria-label="Line chart tren booking harian"
            >
                <defs>
                    <linearGradient id="areaGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="var(--primary)" stopOpacity="0.28" />
                        <stop offset="100%" stopColor="var(--primary)" stopOpacity="0.02" />
                    </linearGradient>
                </defs>

                {/* Grid + Y labels */}
                {yTicks.map((v) => (
                    <g key={v}>
                        <line
                            x1={PAD.left} x2={W - PAD.right}
                            y1={y(v)} y2={y(v)}
                            stroke="var(--border)"
                            strokeWidth="0.6"
                            strokeDasharray="4 4"
                        />
                        <text
                            x={PAD.left - 6} y={y(v) + 4}
                            textAnchor="end" fontSize="11"
                            fill="var(--muted-foreground)"
                        >
                            {v}
                        </text>
                    </g>
                ))}

                {/* Area fill */}
                <path d={areaPath} fill="url(#areaGrad)" />

                {/* Smooth line */}
                <path
                    d={linePath}
                    fill="none"
                    stroke="var(--primary)"
                    strokeWidth="2.5"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />

                {/* Hover targets only: thirty visible dots over the line made
                    it read as a bead chain rather than a silhouette, and the
                    value is already in the tooltip and the card beside it. */}
                {data.map((d, i) => (
                    <circle
                        key={d.tanggal}
                        cx={x(i)} cy={y(d.total)}
                        r={8}
                        fill="transparent"
                    >
                        <title>{`${formatLabel(d.tanggal)}: ${d.total} booking`}</title>
                    </circle>
                ))}

                {/* X labels */}
                {xLabels.map(({ i }) => (
                    <text
                        key={i}
                        x={x(i)} y={H - 8}
                        textAnchor={i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle'}
                        fontSize="11"
                        fill="var(--muted-foreground)"
                    >
                        {formatLabel(data[i]?.tanggal ?? '')}
                    </text>
                ))}
            </svg>

            <div className="text-muted-foreground mt-1 flex items-center gap-1.5 text-xs">
                <span className="bg-primary size-2 rounded-full" />
                Booking harian · maks {maks}
            </div>
        </div>
    );
}

/**
 * A ring of the parts that sum to a whole, with the whole in the middle.
 *
 * Built from one `<circle>` per slice using `stroke-dasharray` rather than
 * `<path>` arcs: a dash pattern needs only two numbers per slice (its length
 * and the rest of the circumference) and an offset for where it starts, so the
 * geometry is arithmetic instead of trigonometry, and a slice that rounds to a
 * sub-pixel cannot disappear the way a hand-computed arc command can.
 *
 * The slices are drawn as `stroke="currentColor"` with the caller's own
 * Tailwind colour class on the element, so the ring, its legend dot and the
 * theme all read from one literal string in the source - a dynamically
 * assembled class name (`bg-${warna}`) would be invisible to Tailwind's
 * scanner and would ship as no colour at all.
 *
 * A whole of zero draws an empty ring rather than a 100% slice of nothing:
 * "there are no doctors" and "the chart failed to load" must not look alike.
 */
function DonutChart({
    irisan,
    total,
    labelPusat,
}: {
    irisan: { label: string; nilai: number; kelas: string }[];
    total: number;
    labelPusat: string;
}) {
    const R = 42;
    const C = 2 * Math.PI * R;
    /** A hairline of separation so two adjacent slices do not read as one. */
    const CELAH = 2;

    const jumlah = irisan.reduce((jumlahAkhir, satu) => jumlahAkhir + satu.nilai, 0);
    const efektif = total > 0 ? total : jumlah;

    let akumulasi = 0;

    const potongan = irisan.map((satu) => {
        const panjang = efektif > 0 ? (satu.nilai / efektif) * C : 0;
        const mulai = akumulasi;
        akumulasi += panjang;

        return { ...satu, panjang, mulai };
    });

    /**
     * Stacked - ring on top, legend under it as a full-width table - rather
     * than the side-by-side it was.
     *
     * Beside the ring, the legend stretched to whatever width the card had
     * left (~600px), which threw each count and percentage to the far edge,
     * a half-card away from the label it belongs to: the row read as two
     * unrelated fragments, which is the "scattered" look. Under the ring the
     * rows span the card's own width and the numbers sit in FIXED columns
     * (`1fr / 3rem / 3.5rem`), so the three counts align with each other and
     * the three percentages align with each other - a table, not a scatter.
     *
     * Stacking also matches the card next to it: the pengguna card is
     * full-width rows too, so the two cards of one section now share a
     * rhythm instead of arguing about orientation.
     *
     * `flex-1 justify-center` lets the block soak up whatever height the
     * sibling card sets, so the ring floats in the middle of its card rather
     * than leaving a dead band under it.
     */
    return (
        <div className="flex w-full flex-1 flex-col items-center justify-center gap-5">
            <svg
                viewBox="0 0 120 120"
                className="h-36 w-36 shrink-0"
                role="img"
                aria-label={`Sebaran status verifikasi ${labelPusat}: ${irisan
                    .map((satu) => `${satu.nilai} ${satu.label.toLowerCase()}`)
                    .join(', ')}`}
            >
                {efektif > 0 ? (
                    potongan.map((satu) =>
                        satu.panjang <= 0 ? null : (
                            <circle
                                key={satu.label}
                                cx="60"
                                cy="60"
                                r={R}
                                fill="none"
                                className={satu.kelas}
                                stroke="currentColor"
                                strokeWidth="16"
                                strokeDasharray={`${Math.max(satu.panjang - CELAH, 1)} ${C}`}
                                strokeDashoffset={-satu.mulai}
                                transform="rotate(-90 60 60)"
                            />
                        ),
                    )
                ) : (
                    <circle
                        cx="60"
                        cy="60"
                        r={R}
                        fill="none"
                        className="stroke-muted"
                        strokeWidth="16"
                    />
                )}

                <text
                    x="60"
                    y="57"
                    textAnchor="middle"
                    fontSize="26"
                    fontWeight="700"
                    className="fill-foreground"
                >
                    {total}
                </text>
                <text
                    x="60"
                    y="74"
                    textAnchor="middle"
                    fontSize="10"
                    className="fill-muted-foreground"
                >
                    {labelPusat}
                </text>
            </svg>

            <ul className="w-full space-y-2">
                {potongan.map((satu) => {
                    const pct =
                        efektif > 0 ? Math.round((satu.nilai / efektif) * 100) : 0;

                    return (
                        <li
                            key={satu.label}
                            className="grid grid-cols-[minmax(0,1fr)_3rem_3.5rem] items-center gap-x-3 text-sm"
                        >
                            <span className="flex min-w-0 items-center gap-2">
                                <span
                                    className={`size-2.5 shrink-0 rounded-full bg-current ${satu.kelas}`}
                                />
                                <span className="truncate">{satu.label}</span>
                            </span>

                            <span className="text-right font-semibold tabular-nums">
                                {satu.nilai}
                            </span>

                            <span className="text-muted-foreground text-right text-xs tabular-nums">
                                {pct}%
                            </span>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

function BarChart({
    baris,
    total,
    labelTotal,
}: {
    baris: { label: string; nilai: number; total: number; warna: string }[];
    total: number;
    labelTotal: string;
}) {
    return (
        <div className="flex flex-1 flex-col justify-between gap-3">
            <p className="text-muted-foreground text-xs">
                {total} {labelTotal}
            </p>

            {baris.map((b) => {
                const pct = b.total > 0 ? Math.round((b.nilai / b.total) * 100) : 0;

                return (
                    <div key={b.label} className="space-y-1">
                        <div className="flex items-center justify-between text-sm">
                            <span>{b.label}</span>
                            <span className="font-semibold">{b.nilai}</span>
                        </div>
                        <div className="bg-muted h-2 w-full overflow-hidden rounded-full">
                            <div
                                className={`h-full rounded-full transition-all ${b.warna}`}
                                style={{ width: `${pct}%` }}
                            />
                        </div>
                        <p className="text-muted-foreground text-xs">{pct}% dari total</p>
                    </div>
                );
            })}
        </div>
    );
}

/**
 * "Perlu tindakan" card: shows items that need admin attention.
 * Hidden entirely when all counts are zero or null (nothing to act on).
 */
function PerluTindakanCard({ data }: { data: import('@/lib/api/admin').PerluTindakan | null }) {
    if (!data) return null;

    const items = [
        {
            key: 'marketing',
            count: data.marketing,
            label: 'Pendaftar Marketing menunggu persetujuan',
            to: '/admin/pengguna?tipe=marketing&status=menunggu_persetujuan',
        },
        {
            key: 'dokter',
            count: data.dokter,
            label: 'Dokter menunggu verifikasi',
            to: '/admin/dokter',
        },
        {
            key: 'undangan',
            count: data.undangan,
            label: 'Undangan belum diaktifkan',
            to: '/admin/pengguna?status=diundang',
        },
    ].filter((item) => (item.count ?? 0) > 0);

    return (
        <section
            aria-labelledby="perlu-tindakan-judul"
            className="rounded-xl border p-5"
        >
            <h2
                id="perlu-tindakan-judul"
                className="mb-3 text-sm font-semibold"
            >
                Perlu tindakan
            </h2>

            {items.length === 0 ? (
                <p className="flex items-center gap-2 text-sm font-medium text-green-600">
                    <CircleCheck className="size-4 shrink-0" aria-hidden="true" />
                    Semua beres. Tidak ada yang menunggu tindakan.
                </p>
            ) : (
                <ul className="space-y-2">
                    {items.map((item) => (
                        <li key={item.key}>
                            <Link
                                to={item.to}
                                className="flex items-center gap-3 rounded-lg bg-muted/50 px-4 py-3 text-sm hover:bg-muted"
                            >
                                <span className="grid min-w-[2rem] place-items-center rounded-md bg-amber-100 px-2 py-1 text-base font-bold text-amber-800">
                                    {item.count}
                                </span>
                                <span className="flex-1 font-medium">{item.label}</span>
                                <span className="text-xs font-semibold text-primary">Tinjau →</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
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
