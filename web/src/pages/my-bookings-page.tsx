import { useState } from 'react';
import { useQueries, useQuery } from '@tanstack/react-query';
import { BookingList } from '@/features/booking/booking-list';
import { bookingPasienOptions } from '@/lib/api/booking';
import { dokterDetailOptions } from '@/lib/api/dokter';
import { useDocumentTitle } from '@/hooks/use-document-title';
import type { Booking, StatusBooking } from '@/lib/api/types';
import { Button } from '@/components/ui/button';
import { Link } from 'react-router';
import { Stethoscope } from 'lucide-react';

const PER_PAGE = 10;

/**
 * `/booking` - the caller's own bookings, `GET /api/v1/pasien/booking`.
 *
 * Behind `RequireAuth` and behind `permission:booking.lihat` on the server. The route
 * therefore serves two very different callers - a patient who sees rows, and an account
 * with no `pasien` row who gets a 403 from `PasienRecordAccess::ownPasien()` - and the 403
 * is rendered as `ForbiddenState` by the shared list rather than as a retryable failure,
 * because no retry changes it.
 *
 * ## Doctor names come from the public directory, one read per unique doctor
 *
 * `BookingResource` publishes `dokter_id` and no name (it eager-loads `dokter` but never
 * emits it), while F12's dialog and row both have to name the doctor. The public
 * `GET /dokter/{id}` is the only endpoint that answers the name, so the page resolves the
 * unique ids on the current page and passes a `dokter_id -> name` resolver down. A 404
 * (unverified, inactive, opted out, expired STR) is a legitimate outcome and falls back to
 * the id the list already rendered, never to a fabricated name.
 */
export function MyBookingsPage() {
    const [page, setPage] = useState(1);
    const [status, setStatus] = useState<StatusBooking | undefined>(undefined);

    useDocumentTitle('Janji temu | Sehatly');

    const list = useQuery(
        bookingPasienOptions({
            page,
            per_page: PER_PAGE,
            ...(status === undefined ? {} : { status }),
        }),
    );

    const rows = list.data?.data.booking ?? [];
    const dokterIds = [...new Set(rows.map((row) => row.dokter_id))];

    const dokter = useQueries({
        queries: dokterIds.map((id) => dokterDetailOptions(String(id))),
    });

    const namaPerDokter = new Map<number, string>();

    dokterIds.forEach((id, index) => {
        const nama = dokter[index]?.data?.data.dokter.nama_lengkap;

        if (nama !== undefined && nama !== null && nama !== '') {
            namaPerDokter.set(id, nama);
        }
    });

    return (
        <BookingList
            filters={{
                page,
                per_page: PER_PAGE,
                ...(status === undefined ? {} : { status }),
            }}
            onPageChange={setPage}
            onFilterChange={(value) => {
                setStatus(value);
                setPage(1);
            }}
            tanggal={null}
            loading={list.isPending}
            error={list.isError ? list.error : null}
            meta={list.data?.meta}
            rows={rows}
            doctorName={(booking: Booking) =>
                namaPerDokter.get(booking.dokter_id) ??
                `Dokter #${String(booking.dokter_id)}`
            }
            denganBannerOffline
            onRetry={() => {
                void list.refetch();
            }}
            headerTitle="Booking saya"
            headerDescription="Semua booking pada akun ini."
            headerAction={
                <Button asChild variant="outline">
                    <Link to="/dokter">
                        <Stethoscope />

                        Booking dokter baru
                    </Link>
                </Button>
            }
        />
    );
}

