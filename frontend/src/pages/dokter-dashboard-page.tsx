import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertCircle, RefreshCw } from 'lucide-react';
import { bookingDokterOptions } from '@/lib/api/booking';
import {
    konsultasiDaftarOptions,
    konsultasiDaftarQueryKey,
} from '@/lib/api/konsultasi';
import { jadwalQueryKey } from '@/lib/api/jadwal';
import { meOptions } from '@/lib/api/me';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { formatTanggal } from '@/lib/format';
import { labelZona, zonaPerangkat } from '@/lib/waktu';
import type { Booking, KonsultasiDaftar } from '@/lib/api/types';
import {
    AntreanDokter,
    type Kepadatan,
    type RentangAntrean,
} from '@/features/dashboard-dokter/antrean';
import {
    DaftarKonsultasiAktif,
    StripMenunggu,
} from '@/features/dashboard-dokter/menunggu';
import { JadwalMinggu } from '@/features/dashboard-dokter/jadwal';
import { PintasanDialog } from '@/features/dashboard-dokter/pintasan';
import { PageHeader } from '@/components/layout/page-header';
import { OfflineBanner } from '@/components/offline-banner';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';

const PER_HALAMAN = 10;

const KEPADATAN_KEY = 'sehatly.f13.kepadatan';

const ZONA_KONFLIK =
    'Konsultasi sudah dimulai di perangkat lain. Daftar disegarkan.';

/**
 * `/dokter/dashboard` - F13's one-screen doctor worklist.
 *
 * ## Three data requests, and the strip no longer depends on chat notifications
 *
 * The dashboard reads today's bookings (`GET /dokter/booking?tanggal=`), the
 * doctor's own consultations (`GET /konsultasi`) and the weekly schedule
 * (`GET /dokter/{dokter}/jadwal`). `GET /konsultasi` replaced the benchmark's
 * notification-derived discovery path: the F13 decision is that a consultation
 * created but never messaged must still appear, which a notification payload could
 * not guarantee. `history`/`/me` is shared with the shell and deduplicated by the
 * QueryClient.
 *
 * ## The strip is driven by the consultation list, the queue by bookings
 *
 * `BookingResource` carries the complaint and the visit geometry but no
 * consultation id; `KonsultasiDaftarResource` carries the id and status but no
 * complaint. The queue rows are therefore bookings, and a row links to a
 * consultation only when the consultation list publishes one with a matching
 * `booking.id`. A missing link says "Konsultasi belum dimulai pasien." - it never
 * guesses an id.
 *
 * ## Offline is a thin slice, exactly as `_global.md` §7 decided
 *
 * The banner shows, the write actions disable, and coming back online refetches.
 * Nothing is queued: a replayed `PUT /terima` is a second state transition.
 */
export function DokterDashboardPage() {
    useDocumentTitle('Dasbor Dokter');

    const queryClient = useQueryClient();
    const online = useOnlineStatus();

    const [halaman, setHalaman] = useState(1);
    const [rentang, setRentang] = useState<RentangAntrean>('hari_ini');
    const [konflik, setKonflik] = useState(false);
    const [kepadatan, setKepadatan] = useState<Kepadatan>(muatKepadatan);

    const { hariIni, besok, labelHariIni } = useMemo(() => {
        const sekarang = new Date();
        const esok = new Date(sekarang);
        esok.setDate(esok.getDate() + 1);

        const zona = zonaPerangkat();

        const judul = new Intl.DateTimeFormat('id-ID', {
            timeZone: zona,
            weekday: 'long',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }).format(sekarang);

        return {
            hariIni: keYmd(sekarang),
            besok: keYmd(esok),
            labelHariIni: `${judul} (${labelZona(zona)})`,
        };
    }, []);

    useEffect(() => {
        try {
            window.localStorage.setItem(KEPADATAN_KEY, kepadatan);
        } catch {}
    }, [kepadatan]);

    const me = useQuery(meOptions());

    const user = me.data?.data.user;
    const isDokter = user?.tipe === 'dokter';
    const dokterId = user?.dokter?.id ?? null;

    const tanggalAktif =
        rentang === 'hari_ini' ? hariIni : rentang === 'besok' ? besok : null;

    const bookings = useQuery({
        ...bookingDokterOptions({
            page: halaman,
            per_page: PER_HALAMAN,
            ...(tanggalAktif === null ? {} : { tanggal: tanggalAktif }),
        }),
        enabled: isDokter,
    });

    const daftar = useQuery({
        ...konsultasiDaftarOptions({ page: 1, per_page: 100 }),
        enabled: isDokter,
    });

    const rowsKonsultasi = daftar.data?.data.konsultasi;

    const konsultasiByBooking = useMemo(() => {
        const peta = new Map<number, KonsultasiDaftar>();

        for (const row of rowsKonsultasi ?? []) {
            if (row.booking !== null) {
                peta.set(row.booking.id, row);
            }
        }

        return peta;
    }, [rowsKonsultasi]);

    const onlineSebelumnya = useRef(online);

    useEffect(() => {
        if (!onlineSebelumnya.current && online) {
            void bookings.refetch();
            void daftar.refetch();
        }

        onlineSebelumnya.current = online;
    }, [online, bookings.refetch, daftar.refetch]);

    const muatUlang = (): void => {
        void bookings.refetch();
        void daftar.refetch();

        if (dokterId !== null) {
            void queryClient.invalidateQueries({
                queryKey: [...jadwalQueryKey, String(dokterId)],
            });
        }
    };

    const padaKonflik = (): void => {
        setKonflik(true);

        void queryClient.invalidateQueries({ queryKey: konsultasiDaftarQueryKey });
    };

    if (me.isPending) {
        return (
            <>
                <PageHeader title="Dasbor Dokter" description="Memuat akun..." />

                <SkeletonRows rows={4} />
            </>
        );
    }

    if (me.isError) {
        return (
            <>
                <PageHeader title="Dasbor Dokter" />

                <ErrorState
                    error={me.error}
                    onRetry={() => {
                        void me.refetch();
                    }}
                />
            </>
        );
    }

    if (!isDokter) {
        return (
            <>
                <PageHeader title="Dasbor Dokter" />

                <ForbiddenState detail="Dasbor ini hanya tersedia untuk akun dokter." />
            </>
        );
    }

    const rowsBooking: Booking[] = bookings.data?.data.booking ?? [];
    const menunggu = (rowsKonsultasi ?? []).filter(
        (row) => row.status === 'menunggu_dokter',
    );
    const aktif = (rowsKonsultasi ?? []).filter(
        (row) => row.status === 'berlangsung' || row.status === 'menunggu_resep',
    );

    const labelAntrean =
        rentang === 'semua' ? 'Semua tanggal' : formatTanggal(tanggalAktif);

    return (
        <>
            <PageHeader
                title="Dasbor Dokter"
                description={labelHariIni}
                action={
                    <>
                        <PintasanDialog />

                        <Button
                            type="button"
                            variant="outline"
                            data-testid="f13-aksi"
                            className="h-11"
                            onClick={muatUlang}
                        >
                            <RefreshCw aria-hidden />

                            Muat ulang
                        </Button>
                    </>
                }
            />

            {konflik ? (
                <Alert variant="destructive" role="alert" data-slot="f13-race">
                    <AlertCircle aria-hidden />

                    <AlertTitle>Konsultasi sudah dimulai</AlertTitle>

                    <AlertDescription>{ZONA_KONFLIK}</AlertDescription>
                </Alert>
            ) : null}

            <OfflineBanner />

            <div className="flex flex-col gap-6 lg:grid lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] lg:items-start">
                <div className="order-2 lg:order-1">
                    <AntreanDokter
                        rows={rowsBooking}
                        meta={bookings.data?.meta}
                        loading={bookings.isPending}
                        error={bookings.isError ? bookings.error : null}
                        onRetry={() => {
                            void bookings.refetch();
                        }}
                        onPageChange={(next) => {
                            setHalaman(next);
                        }}
                        halaman={halaman}
                        rentang={rentang}
                        onRentangChange={(next) => {
                            setRentang(next);
                            setHalaman(1);
                        }}
                        tanggalLabel={labelAntrean}
                        kepadatan={kepadatan}
                        onKepadatanChange={setKepadatan}
                        konsultasiByBooking={konsultasiByBooking}
                    />
                </div>

                <div className="order-1 flex flex-col gap-6 lg:order-2">
                    <StripMenunggu
                        rows={menunggu}
                        loading={daftar.isPending}
                        error={daftar.isError ? daftar.error : null}
                        onRetry={() => {
                            void daftar.refetch();
                        }}
                        online={online}
                        onKonflik={padaKonflik}
                    />

                    <DaftarKonsultasiAktif rows={aktif} />

                    <JadwalMinggu dokterId={dokterId === null ? null : String(dokterId)} enabled />
                </div>
            </div>
        </>
    );
}

function keYmd(tanggal: Date): string {
    const tahun = String(tanggal.getFullYear()).padStart(4, '0');
    const bulan = String(tanggal.getMonth() + 1).padStart(2, '0');
    const hari = String(tanggal.getDate()).padStart(2, '0');

    return `${tahun}-${bulan}-${hari}`;
}

function muatKepadatan(): Kepadatan {
    try {
        return window.localStorage.getItem(KEPADATAN_KEY) === 'ringkas'
            ? 'ringkas'
            : 'lengkap';
    } catch {
        return 'lengkap';
    }
}
