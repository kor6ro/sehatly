import { useNavigate, useParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { Pill } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { meOptions } from '@/lib/api/me';
import { resepOptions } from '@/lib/api/resep';
import type { StatusResep } from '@/lib/api/types';
import { PageHeader } from '@/components/layout/page-header';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState, NotFoundState } from '@/components/states/error-state';
import { EmptyState } from '@/components/states/empty-state';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { CheckoutForm } from '@/features/pesanan-obat/checkout-form';

/**
 * `/checkout/:resepId` - turn a verified prescription into a parcel awaiting payment.
 *
 * ## The statuses the server will accept, and where the list comes from
 *
 * `ResepVerifikasiService::siapDipenuhi()` refuses anything before `diverifikasi` and
 * `ResepVerifikasiService::bolehDipenuhi()` is the predicate behind it, both raising a 422
 * on `resep_id`. The client enumerates the acceptable statuses so a patient is not offered
 * a button guaranteed to fail. It is a courtesy, not the control: the server re-checks
 * inside its own transaction, so a stale client cannot force a checkout.
 *
 * ## `dokter` is refused `pesanan.buat`
 *
 * So a prescriber is told so up front rather than shown a form that 403s on submit.
 * `perawat` and `kurir` are real `users.tipe` values holding no role at all, and the same
 * gate refuses them.
 */
const BISA_CHECKOUT: ReadonlyArray<StatusResep> = [
    'diverifikasi',
    'dipenuhi',
    'dikirim',
    'selesai',
];

export function CheckoutPage() {
    const { resepId } = useParams();
    const navigate = useNavigate();

    const saya = useQuery(meOptions());
    const id = Number(resepId);

    const resep = useQuery({
        ...resepOptions(id),
        enabled: Number.isInteger(id) && id > 0,
    });

    const user = saya.data?.data.user ?? null;
    const boleh = user?.tipe === 'pasien' || user?.tipe === 'superadmin';

    if (!boleh) {
        return (
            <>
                <PageHeader
                    title="Checkout resep"
                    description="POST /api/v1/resep/{id}/checkout, diizinkan oleh permission:pesanan.buat."
                />

                <ForbiddenState detail="Endpoint ini hanya untuk akun pasien. Akun dokter, apoteker, dan admin tidak memegang izin pesanan.buat." />
            </>
        );
    }

    return (
        <>
            <PageHeader
                title="Checkout resep"
                description="POST /api/v1/resep/{id}/checkout. Resep harus sudah diverifikasi apoteker dan belum melewati tanggal berlaku."
            />

            {resep.isPending ? (
                <SkeletonRows rows={6} />
            ) : resep.isError ? (
                <ResepGagal
                    error={resep.error}
                    onRetry={() => {
                        void resep.refetch();
                    }}
                />
            ) : (resep.data.data.resep.items ?? []).length === 0 ? (
                <EmptyState
                    title="Resep tidak punya item"
                    description="Pesanan obat diturunkan dari item resep, jadi resep tanpa item tidak dapat dipesan."
                />
            ) : !BISA_CHECKOUT.includes(resep.data.data.resep.status) ? (
                <Alert data-slot="checkout-belum-diverifikasi" variant="default">
                    <Pill aria-hidden />

                    <AlertTitle>Resep belum bisa dipesan</AlertTitle>

                    <AlertDescription>
                        <p>
                            Status resep saat ini{' '}
                            <code data-slot="checkout-status-resep">
                                {resep.data.data.resep.status}
                            </code>
                            . Server menolak checkout dengan 422 selama resep belum
                            diverifikasi atau sudah kedaluwarsa, jadi form tidak ditawarkan.
                        </p>
                    </AlertDescription>
                </Alert>
            ) : (
                <CheckoutForm
                    resep={resep.data.data.resep}
                    onSelesai={(pesananId) => {
                        void navigate(`/pesanan/${pesananId}`);
                    }}
                />
            )}
        </>
    );
}

function ResepGagal({ error, onRetry }: { error: unknown; onRetry: () => void }) {
    if (error instanceof ApiError && error.isForbidden) {
        return (
            <ForbiddenState detail="Resep milik akun lain, atau akun ini tidak memiliki baris pasien." />
        );
    }

    if (error instanceof ApiError && error.isNotFound) {
        return (
            <NotFoundState
                title="Resep tidak ditemukan"
                detail="Id tersebut tidak ada atau bukan milik pihak yang berhak."
            />
        );
    }

    return <ErrorState error={error} onRetry={onRetry} />;
}
