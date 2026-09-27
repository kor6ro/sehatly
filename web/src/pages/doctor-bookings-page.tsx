import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { BookingList } from '@/features/booking/booking-list';
import { bookingDokterOptions } from '@/lib/api/booking';
import { meOptions } from '@/lib/api/me';
import type { StatusBooking } from '@/lib/api/types';

const PER_PAGE = 10;

/**
 * `/dokter/booking` - the caller's own doctor-side booking list.
 *
 * ## The 403 is the expected answer for most of this app's accounts
 *
 * The route carries `permission:booking.lihat` **and** `tipe:dokter`, so a `pasien` account
 * - which is the only account type the rest of this SPA is built for, since the sidebar
 * filters every patient screen on `user.tipe === 'pasien'` - is refused before the
 * controller runs. Measured on a freshly registered patient:
 *
 * ```
 * GET /api/v1/dokter/booking  -> 403
 * {"success":false,"message":"This action is unauthorized.","errors":{}}
 * ```
 *
 * That is a **capability refusal, not a broken request**: no retry can change it and the
 * caller's account is the subject. So the shared list renders `ForbiddenState` with the
 * server's own message and without a retry button, and the copy explains the account-type
 * requirement rather than inviting the user to try again.
 *
 * The `me` query is read first so the page can name the account it is refusing, which is
 * the difference between a patient who understands and a patient who files a bug.
 */
export function DoctorBookingsPage() {
    const [page, setPage] = useState(1);
    const [status, setStatus] = useState<StatusBooking | undefined>(undefined);
    const [tanggal, setTanggal] = useState<string | null>(null);

    const me = useQuery(meOptions());

    const list = useQuery(
        bookingDokterOptions({
            page,
            per_page: PER_PAGE,
            ...(status === undefined ? {} : { status }),
            ...(tanggal === null || tanggal === '' ? {} : { tanggal }),
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
            tanggal={tanggal}
            onTanggalChange={(value) => {
                setTanggal(value === '' ? null : value);
                setPage(1);
            }}
            loading={list.isPending}
            error={list.isError ? list.error : null}
            meta={list.data?.meta}
            rows={list.data?.data.booking ?? []}
            headerTitle="Booking masuk"
            headerDescription="Booking pada akun dokter ini, dibaca dari GET /api/v1/dokter/booking. Endpoint ini hanya dapat diakses akun bertipe dokter."
            headerAction={
                <span className="text-muted-foreground text-sm">
                    {me.data?.data.user.nama_lengkap ?? '-'}
                </span>
            }
            onRetry={() => {
                void list.refetch();
            }}
        />
    );
}
