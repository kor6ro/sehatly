import { useParams } from 'react-router';
import { PageHeader } from '@/components/layout/page-header';
import { OrderTracking } from '@/features/pesanan-obat/order-tracking';

/**
 * `/pesanan/:id` - the order and its tracking trail.
 *
 * ## FINDING: there is no order list, so this screen is addressed by id
 *
 * `routes/api.php:1086-1089` registers only `GET /pesanan-obat/{id}`. There is no
 * `GET /pasien/pesanan-obat` and no `GET /pesanan-obat`, so "my orders" cannot be rendered
 * from the API at all. The nav entry therefore points at a placeholder id, the same
 * trade-off todos 28 and 35 left in place for the consultation screens - the real link is
 * the one the checkout screen supplies, because it is the only moment a patient learns an
 * order id. Reported, not faked: a list route is a backend change.
 *
 * The read is authorised for the owning patient, `apoteker`, `admin` and `superadmin`; a
 * `dokter` holds no `pesanan.lihat` and gets the server's own 403, which the component
 * renders as `ForbiddenState` rather than as a generic failure.
 */
export function PesananPage() {
    const { id } = useParams();
    const pesananId = Number(id);

    return (
        <>
            <PageHeader
                title="Lacak pesanan obat"
                description="GET /api/v1/pesanan-obat/{id}. Status pesanan dan riwayat pengiriman dibaca apa adanya dari server."
            />

            <OrderTracking pesananId={pesananId} />
        </>
    );
}
