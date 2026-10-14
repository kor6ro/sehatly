import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { RefreshCw } from 'lucide-react';
import {
    adminLaporanBookingOptions,
    adminLaporanKehadiranOptions,
    adminLaporanPendapatanOptions,
    type LaporanRange,
} from '@/lib/api/admin';
import type { ApiMeta } from '@/lib/http';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { formatRupiah } from '@/lib/format';
import { AdminErrorState, AdminGate } from '@/features/admin/admin-gate';
import { tanggalSingkat } from '@/features/admin/format-admin';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { Field, FieldInput } from '@/components/form/field';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { EmptyState } from '@/components/states/empty-state';
import { SkeletonRows } from '@/components/states/loading-state';

type ChipPeriode = 'hari' | 'minggu' | 'bulan' | 'kustom';

const CHIP: ReadonlyArray<{ nilai: Exclude<ChipPeriode, 'kustom'>; label: string }> = [
    { nilai: 'hari', label: 'Hari ini' },
    { nilai: 'minggu', label: '7 hari' },
    { nilai: 'bulan', label: 'Bulan ini' },
];

function ymd(tanggal: Date): string {
    return [
        String(tanggal.getFullYear()).padStart(4, '0'),
        String(tanggal.getMonth() + 1).padStart(2, '0'),
        String(tanggal.getDate()).padStart(2, '0'),
    ].join('-');
}

function geser(tanggal: Date, hari: number): Date {
    const salinan = new Date(tanggal);
    salinan.setDate(salinan.getDate() + hari);

    return salinan;
}

function rangeHariIni(): LaporanRange {
    const sekarang = new Date();

    return { dari: ymd(sekarang), sampai: ymd(sekarang) };
}

function rangeMinggu(): LaporanRange {
    const sekarang = new Date();

    return { dari: ymd(geser(sekarang, -6)), sampai: ymd(sekarang) };
}

function rangeBulan(): LaporanRange {
    const sekarang = new Date();

    return {
        dari: ymd(new Date(sekarang.getFullYear(), sekarang.getMonth(), 1)),
        sampai: ymd(sekarang),
    };
}

const POLA_TANGGAL = /^\d{4}-\d{2}-\d{2}$/;

function rangeDariUrl(dari: string | null, sampai: string | null): LaporanRange | null {
    if (dari === null || sampai === null) {
        return null;
    }

    if (!POLA_TANGGAL.test(dari) || !POLA_TANGGAL.test(sampai) || sampai < dari) {
        return null;
    }

    return { dari, sampai };
}

function peringatanLedger(hasil: { meta?: ApiMeta } | undefined): boolean {
    const meta = hasil?.meta as (ApiMeta & { peringatan_ledger?: boolean }) | undefined;

    return meta?.peringatan_ledger === true;
}

function persen(bagian: number, total: number): string {
    if (total <= 0) {
        return '-';
    }

    return `${new Intl.NumberFormat('id-ID', {
        maximumFractionDigits: 1,
    }).format((bagian / total) * 100)}%`;
}

/**
 * `/admin/laporan` - F14's report screen: aggregates only, and no export.
 *
 * ## Three independent panels
 *
 * The screen issues three requests (booking, pendapatan, kehadiran) and each
 * panel owns its loading, error and retry state, so one failing aggregate does
 * not blank the other two. The range is the URL (`?dari=&sampai=`) because a
 * report link must be shareable; each chip rewrites it with `replace`.
 *
 * ## There is no export, and the screen says so
 *
 * No CSV/PDF route exists in the backend, so no download control is rendered -
 * the pattern's step 13 is explicit that offering one would be a lie. The note
 * points at the browser's own print instead.
 *
 * ## `no_show` is shown as "belum dicatat", not as a zero
 *
 * `AdminLaporanService` documents that no writer moves a booking into
 * `no_show`, so its count is structurally zero. Rendering "0 tidak hadir" would
 * read as "nobody missed an appointment"; the honest rendering is "-" with the
 * reason, and the day a writer ships the same cell starts showing numbers.
 */
export function AdminLaporanPage() {
    useDocumentTitle('Laporan');

    return (
        <AdminGate>
            <AdminLaporanContent />
        </AdminGate>
    );
}

function AdminLaporanContent() {
    const [searchParams, setSearchParams] = useSearchParams();

    const dariUrl = searchParams.get('dari');
    const sampaiUrl = searchParams.get('sampai');

    const range = useMemo(
        () => rangeDariUrl(dariUrl, sampaiUrl) ?? rangeHariIni(),
        [dariUrl, sampaiUrl],
    );

    const [kustom, setKustom] = useState(false);
    const [dariKustom, setDariKustom] = useState(range.dari);
    const [sampaiKustom, setSampaiKustom] = useState(range.sampai);

    const booking = useQuery(adminLaporanBookingOptions(range));
    const pendapatan = useQuery(adminLaporanPendapatanOptions(range));
    const kehadiran = useQuery(adminLaporanKehadiranOptions(range));

    const terapkan = (baru: LaporanRange): void => {
        setSearchParams({ dari: baru.dari, sampai: baru.sampai }, { replace: true });
        setKustom(false);
    };

    const chipAktif: ChipPeriode = kustom
        ? 'kustom'
        : range.dari === rangeHariIni().dari
          ? 'hari'
          : range.dari === rangeBulan().dari
            ? 'bulan'
            : 'minggu';

    const ringkasanBooking = booking.data?.data.ringkasan;
    const ringkasanPendapatan = pendapatan.data?.data.ringkasan;
    const ringkasanKehadiran = kehadiran.data?.data.ringkasan;

    const harian = booking.data?.data.harian ?? [];
    const uangHarian = useMemo(() => {
        const peta = new Map<string, string>();

        for (const row of pendapatan.data?.data.harian ?? []) {
            peta.set(row.tanggal, row.total);
        }

        return peta;
    }, [pendapatan.data]);

    const kosong =
        !booking.isPending &&
        !pendapatan.isPending &&
        !kehadiran.isPending &&
        (ringkasanBooking?.total ?? 0) === 0 &&
        Number(ringkasanPendapatan?.total ?? '0') === 0 &&
        (ringkasanKehadiran?.total ?? 0) === 0;

    return (
        <>
            <PageHeader
                title="Laporan"
                description={`${tanggalSingkat(range.dari)} – ${tanggalSingkat(range.sampai)} (WIB)`}
                action={
                    <div data-testid="admin-aksi">
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => {
                                void booking.refetch();
                                void pendapatan.refetch();
                                void kehadiran.refetch();
                            }}
                        >
                            <RefreshCw aria-hidden />
                            Muat ulang
                        </Button>
                    </div>
                }
            />

            <div className="flex flex-wrap gap-2" role="group" aria-label="Periode laporan">
                {CHIP.map((item) => (
                    <div key={item.nilai} data-testid="admin-aksi">
                        <Button
                            type="button"
                            variant={chipAktif === item.nilai ? 'default' : 'outline'}
                            aria-pressed={chipAktif === item.nilai}
                            className="h-11"
                            onClick={() => {
                                terapkan(
                                    item.nilai === 'hari'
                                        ? rangeHariIni()
                                        : item.nilai === 'minggu'
                                          ? rangeMinggu()
                                          : rangeBulan(),
                                );
                            }}
                        >
                            {item.label}
                        </Button>
                    </div>
                ))}

                <div data-testid="admin-aksi">
                    <Button
                        type="button"
                        variant={chipAktif === 'kustom' ? 'default' : 'outline'}
                        aria-pressed={chipAktif === 'kustom'}
                        aria-expanded={kustom}
                        className="h-11"
                        onClick={() => {
                            setKustom((lama) => !lama);
                        }}
                    >
                        Kustom
                    </Button>
                </div>
            </div>

            {kustom ? (
                <form
                    className="flex flex-col gap-3 sm:flex-row sm:items-end"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (dariKustom !== '' && sampaiKustom !== '') {
                            terapkan({ dari: dariKustom, sampai: sampaiKustom });
                        }
                    }}
                >
                    <Field label="Dari tanggal">
                        <FieldInput
                            type="date"
                            value={dariKustom}
                            onChange={(event) => {
                                setDariKustom(event.target.value);
                            }}
                        />
                    </Field>

                    <Field label="Sampai tanggal">
                        <FieldInput
                            type="date"
                            value={sampaiKustom}
                            onChange={(event) => {
                                setSampaiKustom(event.target.value);
                            }}
                        />
                    </Field>

                    <div data-testid="admin-aksi">
                        <Button type="submit" variant="secondary" className="h-11">
                            Terapkan
                        </Button>
                    </div>
                </form>
            ) : null}

            {peringatanLedger(booking.data) ||
            peringatanLedger(pendapatan.data) ||
            peringatanLedger(kehadiran.data) ? (
                <Alert variant="destructive" role="alert" data-slot="admin-ledger">
                    <AlertTitle>Peringatan laporan pendapatan</AlertTitle>

                    <AlertDescription>
                        Angka pendapatan dapat berubah setelah perbaikan pencatatan
                        pembatalan.
                    </AlertDescription>
                </Alert>
            ) : null}

            {kosong ? (
                <EmptyState
                    title="Belum ada aktivitas pada periode ini."
                    description="Pilih periode lain untuk melihat laporan."
                    action={
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => {
                                terapkan(rangeMinggu());
                            }}
                        >
                            Ubah periode
                        </Button>
                    }
                />
            ) : (
                <>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Total booking
                                </CardTitle>

                                {booking.isPending ? null : booking.isError ? null : (
                                    <CardDescription className="tabular-nums">
                                        {ringkasanBooking?.total ?? 0} booking pada
                                        periode ini
                                    </CardDescription>
                                )}
                            </CardHeader>

                            <CardContent>
                                {booking.isPending ? (
                                    <SkeletonRows rows={2} />
                                ) : booking.isError ? (
                                    <AdminErrorState
                                        title="Gagal memuat laporan booking."
                                        error={booking.error}
                                        onRetry={() => {
                                            void booking.refetch();
                                        }}
                                    />
                                ) : (
                                    <dl className="flex flex-col gap-1 text-base tabular-nums">
                                        <div className="flex justify-between gap-2">
                                            <dt>Selesai</dt>
                                            <dd>
                                                {ringkasanBooking?.per_status.selesai ??
                                                    0}
                                            </dd>
                                        </div>

                                        <div className="flex justify-between gap-2">
                                            <dt>Dibatalkan</dt>
                                            <dd>
                                                {ringkasanBooking?.per_status
                                                    .dibatalkan ?? 0}
                                            </dd>
                                        </div>

                                        <div className="flex justify-between gap-2">
                                            <dt>Kadaluarsa</dt>
                                            <dd>
                                                {ringkasanBooking?.per_status
                                                    .kadaluarsa ?? 0}
                                            </dd>
                                        </div>

                                        <div className="flex justify-between gap-2">
                                            <dt>Tidak hadir</dt>
                                            <dd
                                                title={
                                                    (ringkasanBooking?.per_status
                                                        .no_show ?? 0) > 0
                                                        ? undefined
                                                        : 'Belum ada pencatat status tidak hadir'
                                                }
                                            >
                                                {(ringkasanBooking?.per_status
                                                    .no_show ?? 0) > 0
                                                    ? ringkasanBooking?.per_status
                                                          .no_show
                                                    : '—'}
                                            </dd>
                                        </div>
                                    </dl>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Pendapatan</CardTitle>

                                {pendapatan.isPending || pendapatan.isError ? null : (
                                    <CardDescription className="tabular-nums">
                                        {ringkasanPendapatan?.jumlah_invoice ?? 0} invoice
                                        lunas
                                    </CardDescription>
                                )}
                            </CardHeader>

                            <CardContent>
                                {pendapatan.isPending ? (
                                    <SkeletonRows rows={2} />
                                ) : pendapatan.isError ? (
                                    <AdminErrorState
                                        title="Gagal memuat pendapatan."
                                        error={pendapatan.error}
                                        onRetry={() => {
                                            void pendapatan.refetch();
                                        }}
                                    />
                                ) : (
                                    <p className="text-2xl font-semibold tabular-nums">
                                        {formatRupiah(
                                            ringkasanPendapatan?.total ?? '0',
                                        )}
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Kehadiran</CardTitle>

                                {kehadiran.isPending || kehadiran.isError ? null : (
                                    <CardDescription>
                                        Dari booking pada periode ini
                                    </CardDescription>
                                )}
                            </CardHeader>

                            <CardContent>
                                {kehadiran.isPending ? (
                                    <SkeletonRows rows={2} />
                                ) : kehadiran.isError ? (
                                    <AdminErrorState
                                        title="Gagal memuat kehadiran."
                                        error={kehadiran.error}
                                        onRetry={() => {
                                            void kehadiran.refetch();
                                        }}
                                    />
                                ) : (
                                    <dl className="flex flex-col gap-1 text-base tabular-nums">
                                        <div className="flex justify-between gap-2">
                                            <dt>Check-in</dt>
                                            <dd>{ringkasanKehadiran?.check_in ?? 0}</dd>
                                        </div>

                                        <div className="flex justify-between gap-2">
                                            <dt>Selesai</dt>
                                            <dd>
                                                {ringkasanKehadiran?.selesai ?? 0}{' '}
                                                <span className="text-muted-foreground">
                                                    (
                                                    {persen(
                                                        ringkasanKehadiran?.selesai ?? 0,
                                                        ringkasanKehadiran?.total ?? 0,
                                                    )}
                                                    )
                                                </span>
                                            </dd>
                                        </div>

                                        <div className="flex justify-between gap-2">
                                            <dt>Tidak hadir</dt>
                                            <dd
                                                title={
                                                    (ringkasanKehadiran?.no_show ?? 0) >
                                                    0
                                                        ? undefined
                                                        : 'Belum ada pencatat status tidak hadir'
                                                }
                                            >
                                                {(ringkasanKehadiran?.no_show ?? 0) > 0
                                                    ? ringkasanKehadiran?.no_show
                                                    : '—'}
                                            </dd>
                                        </div>
                                    </dl>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <section aria-label="Rincian harian" className="flex flex-col gap-3">
                        <h2 className="text-lg font-semibold">Rincian harian</h2>

                        {booking.isPending ? (
                            <SkeletonRows rows={4} />
                        ) : booking.isError ? (
                            <AdminErrorState
                                title="Gagal memuat rincian harian."
                                error={booking.error}
                                onRetry={() => {
                                    void booking.refetch();
                                }}
                            />
                        ) : harian.length === 0 ? (
                            <EmptyState
                                compact
                                title="Belum ada aktivitas pada periode ini."
                                description="Rincian harian muncul bila ada booking pada rentang tanggal ini."
                            />
                        ) : (
                            <div
                                role="group"
                                aria-label="Tabel rincian harian"
                                tabIndex={0}
                                className="overflow-x-auto"
                            >
                                <table className="w-full min-w-[36rem] border-collapse text-left text-base">
                                    <caption className="sr-only">
                                        Rincian booking dan pendapatan per hari
                                    </caption>

                                    <thead>
                                        <tr className="border-border border-b">
                                            <th scope="col" className="py-2 pr-3 font-medium">
                                                Tanggal
                                            </th>
                                            <th scope="col" className="py-2 pr-3 text-right font-medium">
                                                Booking
                                            </th>
                                            <th scope="col" className="py-2 pr-3 text-right font-medium">
                                                Selesai
                                            </th>
                                            <th scope="col" className="py-2 pr-3 text-right font-medium">
                                                Dibatalkan
                                            </th>
                                            <th scope="col" className="py-2 text-right font-medium">
                                                Pendapatan
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {[...harian].reverse().map((row) => (
                                            <tr
                                                key={row.tanggal}
                                                className="border-border border-b last:border-b-0"
                                            >
                                                <th
                                                    scope="row"
                                                    className="py-2 pr-3 font-normal tabular-nums"
                                                >
                                                    {tanggalSingkat(row.tanggal)}
                                                </th>

                                                <td className="py-2 pr-3 text-right tabular-nums">
                                                    {row.total}
                                                </td>

                                                <td className="py-2 pr-3 text-right tabular-nums">
                                                    {row.per_status.selesai ?? 0}
                                                </td>

                                                <td className="py-2 pr-3 text-right tabular-nums">
                                                    {row.per_status.dibatalkan ?? 0}
                                                </td>

                                                <td className="py-2 text-right tabular-nums">
                                                    {uangHarian.has(row.tanggal)
                                                        ? formatRupiah(
                                                              uangHarian.get(
                                                                  row.tanggal,
                                                              ) ?? '0',
                                                          )
                                                        : '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                </>
            )}

            <p
                data-slot="admin-catatan-ekspor"
                className="text-muted-foreground text-sm"
            >
                Ekspor belum tersedia pada versi ini. Gunakan cetak peramban bila perlu.
            </p>
        </>
    );
}
