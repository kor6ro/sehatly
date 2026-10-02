import { Link } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { ApiError } from '@/lib/http';
import { preferensiNotifikasiOptions } from '@/lib/api/notifikasi-preferensi';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { PageHeader } from '@/components/layout/page-header';
import { OfflineBanner } from '@/components/offline-banner';
import { Button } from '@/components/ui/button';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';
import { PreferensiNotifikasiForm } from '@/features/notifikasi/preferensi-notifikasi-form';

/**
 * `/profil/notifikasi` - "Notifikasi & pengingat".
 *
 * The page owns the three read states and the offline banner; the form owns the write
 * states. A 403 gets `ForbiddenState` with the server's own message and a way back,
 * which is what `perawat`/`kurir` accounts - who hold no role at all - will see.
 */
export function ProfilNotifikasiPage() {
    useDocumentTitle('Notifikasi & pengingat | Sehatly');

    const preferensi = useQuery(preferensiNotifikasiOptions());

    if (preferensi.isPending) {
        return (
            <>
                <PageHeader
                    title="Notifikasi & pengingat"
                    description="Atur apa yang dikirim ke perangkat Anda dan kapan."
                />

                <div data-slot="preferensi-loading">
                    <SkeletonRows rows={6} />
                </div>
            </>
        );
    }

    if (
        preferensi.isError &&
        preferensi.error instanceof ApiError &&
        preferensi.error.isForbidden
    ) {
        return (
            <>
                <PageHeader title="Notifikasi & pengingat" />

                <ForbiddenState
                    detail={preferensi.error.message}
                    action={
                        <Button asChild variant="outline" className="min-h-11">
                            <Link to="/dashboard">Kembali</Link>
                        </Button>
                    }
                />
            </>
        );
    }

    if (preferensi.isError && preferensi.data === undefined) {
        return (
            <>
                <PageHeader title="Notifikasi & pengingat" />

                <ErrorState
                    error={preferensi.error}
                    title="Gagal memuat preferensi notifikasi."
                    onRetry={() => {
                        void preferensi.refetch();
                    }}
                />
            </>
        );
    }

    return (
        <>
            <PageHeader
                title="Notifikasi & pengingat"
                description="Atur apa yang dikirim ke perangkat Anda dan kapan."
            />

            <OfflineBanner message="Anda sedang offline. Preferensi yang tampil adalah data terakhir yang tersimpan." />

            <PreferensiNotifikasiForm awal={preferensi.data.data.preferensi} />
        </>
    );
}
