import { useParams } from 'react-router';
import { PageHeader } from '@/components/layout/page-header';
import { PembayaranMenunggu } from '@/features/pembayaran/pembayaran-menunggu';

/**
 * `/pembayaran/:pesananId` - the payment screen, keyed on the ORDER.
 *
 * ## Why the route carries an order id and not an invoice id
 *
 * The plan specifies a `PaymentWaitingPage` that polls `GET /invoice/{id}`. That route does
 * not exist: `routes/api.php:1159-1162` registers only the `bayar` POST, and no other route
 * reads `invoice` or `pembayaran` at all. Worse, the checkout 201 does not publish
 * `invoice_id` and `PesananObatResource` has no invoice field, so an invoice id is not
 * derivable from anything the order flow returns.
 *
 * The order id IS returned, so this screen is addressed by it, reads the real order, and
 * asks for the invoice id with an explanation rather than pretending to discover one. Both
 * facts are recorded in `.omo/evidence/task-48-sehatly.md` as findings; neither is worked
 * around by inventing an endpoint.
 *
 * ## What "paid" means here
 *
 * Only one thing: `pesanan_obat.status` leaving `menunggu_pembayaran`. That transition is
 * written by `PaymentService::LANJUT` inside the settlement transaction, and a retried
 * webhook does not write it again because the duplicate branch returns before any
 * assignment. The screen therefore renders the column and says nothing beyond it.
 */
export function PembayaranPage() {
    const { pesananId } = useParams();

    return (
        <>
            <PageHeader
                title="Pembayaran"
                description="POST /api/v1/invoice/{id}/bayar. Status settlement dibaca dari pesanan, karena tidak ada endpoint untuk membaca invoice."
            />

            <PembayaranMenunggu pesananId={Number(pesananId)} />
        </>
    );
}
