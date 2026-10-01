import { useEffect } from 'react';
import { useParams } from 'react-router';
import { PageHeader } from '@/components/layout/page-header';
import { PembayaranMenunggu } from '@/features/pembayaran/pembayaran-menunggu';

/**
 * `/pembayaran/:pesananId` - the payment screen, keyed on the ORDER.
 *
 * ## Why the route carries an order id and not an invoice id
 *
 * The order id IS returned by checkout; the invoice id is not. `GET /invoice/{id}` now
 * exists, so a typed invoice id can be resolved to its real status, amount and payment
 * history before a payment is started - but no endpoint lists a patient's invoices, so the
 * id still cannot be discovered automatically and the field stays. Both facts are recorded
 * in `web/ux/patterns/F06.md` §12.
 *
 * ## What "paid" means here
 *
 * Only one thing: `pesanan_obat.status` leaving `menunggu_pembayaran`. That transition is
 * written by `PaymentService::LANJUT` inside the settlement transaction, and a retried
 * webhook does not write it again because the duplicate branch returns before any
 * assignment. The screen therefore renders the column and says nothing beyond it.
 *
 * The document title is set here, and to a constant: `web/AGENTS.md` forbids order
 * numbers, invoice numbers and any medical data in a tab title.
 */
export function PembayaranPage() {
    const { pesananId } = useParams();

    useEffect(() => {
        const sebelumnya = document.title;

        document.title = 'Pembayaran';

        return () => {
            document.title = sebelumnya;
        };
    }, []);

    return (
        <>
            <PageHeader
                title="Pembayaran"
                description="Lengkapi pembayaran agar pesanan diproses."
            />

            <PembayaranMenunggu pesananId={Number(pesananId)} />
        </>
    );
}
