import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useParams, useSearchParams } from 'react-router';
import { ArrowLeft } from 'lucide-react';
import { BookingForm } from '@/features/booking/booking-form';
import { BookingList } from '@/features/booking/booking-list';
import { dokterDetailOptions } from '@/lib/api/dokter';
import { bookingPasienOptions } from '@/lib/api/booking';
import { ApiError } from '@/lib/http';
import { formatRupiah } from '@/lib/format';
import type { StatusBooking } from '@/lib/api/types';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { SkeletonRows } from '@/components/states/loading-state';
import { NotFoundState } from '@/components/states/error-state';

const PER_PAGE = 10;

/**
 * `/booking/:dokterId` - make one booking with one doctor.
 *
 * ## Why the doctor detail is a hard gate, not a convenience
 *
 * The form needs `nama_lengkap` and `biaya_konsultasi_online`, and the booking row itself
 * publishes neither - `BookingResource` carries `dokter_id` and nothing else about the
 * doctor, deliberately, because the patient list is tenant-scoped and a resource that
 * eagerly joined `users` would republish a profile through every list row. So the name and
 * the fee have to come from `GET /api/v1/dokter/{dokter}`, and that call's 404 is the
 * doctor's six-way "not available" answer.
 *
 * Showing the form before that resolves would mean showing "Dokter: -" and "Biaya: -" on
 * a screen whose whole purpose is to confirm what is being booked, so the gate is here.
 *
 * ## The deep link is a real feature, and it is a `Y-m-d` string
 *
 * `?tanggal=2026-10-05` pre-selects a day, and `?jam=09:00:00` pre-selects a start time,
 * so a booking link can name its own slot. Both are read as **strings** and passed through
 * `tanggalKeDate` / `jamKeHms`; neither is ever handed to the API as a `Date`, for the
 * reason `lib/tanggal.ts` exists. An unparseable value is ignored rather than trusted, and
 * the form falls back to requiring a fresh choice.
 */
export function BookingCreatePage() {
    /**
     * The parameter name is `dokterId`, not `id`: the route is `/booking/:dokterId`,
     * and reading any other key yields `undefined`, which disables the detail query and
     * leaves the page on its skeleton forever. That exact failure was the first thing the
     * Playwright suite caught, because the pending branch carries the generic title.
     */
    const { dokterId: dokterIdParam } = useParams<{ dokterId: string }>();
    const [searchParams] = useSearchParams();

    const [status, setStatus] = useState<StatusBooking | undefined>(undefined);
    const [page, setPage] = useState(1);
    const [dibuat, setDibuat] = useState<string | null>(null);

    const dokterId = dokterIdParam ?? '';
    const tanggalAwal = searchParams.get('tanggal');
    const jamAwal = searchParams.get('jam');

    const dokter = useQuery({
        ...dokterDetailOptions(dokterId),
        enabled: dokterId !== '',
    });

    const list = useQuery(
        bookingPasienOptions({ page, per_page: PER_PAGE, ...(status === undefined ? {} : { status }) }),
    );

    if (dokter.isPending) {
        return (
            <>
                <PageHeader title="Buat booking" />

                <SkeletonRows rows={4} />
            </>
        );
    }

    if (dokter.isError) {
        return (
            <>
                <PageHeader title="Buat booking" />

                {/**
                 * `DokterController` answers one 404 for six situations and publishes one
                 * generic message so nobody can enumerate them, so the copy is limited to
                 * what the client is allowed to say.
                 */}
                {dokter.error instanceof ApiError &&
                dokter.error.isNotFound ? (
                    <NotFoundState
                        title="Dokter tidak dapat dipesan"
                        detail="Profil dokter ini tidak tersedia di direktori, sehingga booking tidak dapat dibuat."
                        action={
                            <Button asChild variant="outline" size="sm">
                                <Link to="/dokter">
                                    <ArrowLeft />

                                    Kembali ke direktori
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <p className="text-destructive text-sm">
                        {dokter.error instanceof Error
                            ? dokter.error.message
                            : 'Gagal memuat data dokter.'}
                    </p>
                )}
            </>
        );
    }

    const row = dokter.data.data.dokter;

    return (
        <>
            <PageHeader
                title={`Booking dengan ${row.nama_lengkap}`}
                description="Lengkapi tanggal, jam, tipe layanan, dan keluhan sebelum mengirim booking."
                action={
                    <Button asChild variant="outline">
                        <Link to="/booking">
                            <ArrowLeft />

                            Booking saya
                        </Link>
                    </Button>
                }
            />

            <Card>
                <CardContent className="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
                    <span className="font-medium">{row.nama_lengkap}</span>

                    <span className="text-muted-foreground">
                        Biaya konsultasi: {formatRupiah(row.biaya_konsultasi_online)}
                    </span>

                    <span className="text-muted-foreground">
                        Durasi default:{' '}
                        {row.durasi_default_menit === null
                            ? '-'
                            : `${row.durasi_default_menit} menit`}
                    </span>
                </CardContent>
            </Card>

            {/**
             * The confirmation carries the server's own `nomor_booking`. Nothing is written
             * into the list by hand: the mutation invalidates `['v1','booking']` in its
             * `onSuccess`, so the row below is a fresh `GET /api/v1/pasien/booking` and the
             * new booking is on screen because the server has it, not because the client
             * decided to show it.
             */}
            {dibuat === null ? null : (
                <Card className="border-success/40 bg-success/10">
                    <CardContent className="flex flex-col gap-1">
                        <p className="font-medium">
                            Booking {dibuat} berhasil dibuat.
                        </p>

                        <p className="text-muted-foreground text-sm">
                            Status awal adalah menunggu pembayaran. Nomor antrean
                            belum ditetapkan oleh sistem, sehingga tidak
                            ditampilkan.
                        </p>
                    </CardContent>
                </Card>
            )}

            <BookingForm
                dokterId={dokterId}
                dokterNama={row.nama_lengkap}
                biaya={row.biaya_konsultasi_online}
                tanggalAwal={tanggalAwal}
                onCreated={setDibuat}
                jamAwal={jamAwal}
            />

            <BookingList
                filters={{ page, per_page: PER_PAGE, ...(status === undefined ? {} : { status }) }}
                onPageChange={setPage}
                onFilterChange={(value) => {
                    setStatus(value);
                    setPage(1);
                }}
                tanggal={null}
                loading={list.isPending}
                error={list.isError ? list.error : null}
                meta={list.data?.meta}
                rows={list.data?.data.booking ?? []}
                onRetry={() => {
                    void list.refetch();
                }}
                headerTitle="Booking terakhir"
                headerDescription="Daftar booking pada akun ini."
            />
        </>
    );
}
