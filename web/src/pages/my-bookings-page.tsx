import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { BookingList } from '@/features/booking/booking-list';
import { bookingPasienOptions } from '@/lib/api/booking';
import type { StatusBooking } from '@/lib/api/types';
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
 */
export function MyBookingsPage() {
    const [page, setPage] = useState(1);
    const [status, setStatus] = useState<StatusBooking | undefined>(undefined);

    const list = useQuery(
        bookingPasienOptions({
            page,
            per_page: PER_PAGE,
            ...(status === undefined ? {} : { status }),
        }),
    );

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
            rows={list.data?.data.booking ?? []}
            onRetry={() => {
                void list.refetch();
            }}
            headerTitle="Booking saya"
            headerDescription="Semua booking pada akun ini, dibaca dari GET /api/v1/pasien/booking."
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
