import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { ApiError } from '@/lib/http';
import { konsultasiOptions } from '@/lib/api/konsultasi';
import { meOptions } from '@/lib/api/me';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { PageHeader } from '@/components/layout/page-header';
import { OfflineBanner } from '@/components/offline-banner';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState, NotFoundState } from '@/components/states/error-state';
import { EmptyState } from '@/components/states/empty-state';
import { Button } from '@/components/ui/button';
import { FormUlasan } from '@/features/ulasan/form-ulasan';

/**
 * `/konsultasi/:id/ulasan` - the patient's write surface for one completed
 * consultation.
 *
 * ## It is the deep-link target the notification already points at
 *
 * `NotificationService::ulasanDiminta()` publishes
 * `/api/v1/konsultasi/{id}/ulasan` when a consultation is finished, and
 * `features/notifikasi/deep-link.ts` maps that exact API path onto this route. The
 * route therefore exists to be *linked to*, not only to be found: the consultation
 * page also offers `Tulis ulasan` once its status is `selesai`.
 *
 * ## The server owns every rule, and the page mirrors the visible half
 *
 * `POST /konsultasi/{id}/ulasan` resolves the caller's own `pasien` row (403 when there
 * is none), scopes the lookup by `pasien_id` (404 for another patient's consultation,
 * byte-identical to an absent id) and refuses a non-`selesai` status with a 422. This
 * page gates on the same facts from `GET /konsultasi/{id}` and `GET /me` so a patient
 * never fills a form that cannot be submitted - and the form still renders the server's
 * own 422 inline if the state changed after the page loaded.
 *
 * ## Nothing clinical is in the title or the URL
 *
 * `document.title` is the fixed `Tulis ulasan | Sehatly`, and the only variable in the
 * address is the numeric consultation id the notification itself carries. The SOAP
 * fields, the diagnosis and the review body never reach either.
 */
export function KonsultasiUlasanPage() {
    useDocumentTitle('Tulis ulasan | Sehatly');

    const { id } = useParams<{ id: string }>();
    const konsultasiId = Number(id);

    const sesi = useQuery({
        ...konsultasiOptions(konsultasiId),
        enabled: Number.isInteger(konsultasiId),
    });

    const saya = useQuery(meOptions());

    if (!Number.isInteger(konsultasiId)) {
        return (
            <Halaman>
                <PageHeader title="Tulis ulasan" />

                <NotFoundState
                    title="Konsultasi tidak ditemukan"
                    detail="Alamat halaman harus berisi nomor konsultasi berupa angka."
                    action={
                        <Button asChild variant="outline" size="sm" className="min-h-11">
                            <Link to="/konsultasi">Daftar konsultasi saya</Link>
                        </Button>
                    }
                />
            </Halaman>
        );
    }

    if (sesi.isPending || saya.isPending) {
        return (
            <Halaman>
                <PageHeader title="Tulis ulasan" description="Memuat konsultasi..." />

                <SkeletonRows rows={4} />
            </Halaman>
        );
    }

    if (sesi.isError) {
        if (sesi.error instanceof ApiError && sesi.error.isNotFound) {
            return (
                <Halaman>
                    <PageHeader title="Tulis ulasan" />

                    <NotFoundState
                        title="Konsultasi tidak ditemukan"
                        detail="Konsultasi ini tidak ada atau bukan milik pihak yang berhak. Ulasan hanya dapat ditulis oleh pasien pemilik konsultasi."
                        action={
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                className="min-h-11"
                            >
                                <Link to="/konsultasi">Daftar konsultasi saya</Link>
                            </Button>
                        }
                    />
                </Halaman>
            );
        }

        if (sesi.error instanceof ApiError && sesi.error.isForbidden) {
            return (
                <Halaman>
                    <PageHeader title="Tulis ulasan" />

                    <ForbiddenState
                        detail="Ulasan hanya dapat ditulis oleh akun pasien pemilik konsultasi."
                    />
                </Halaman>
            );
        }

        return (
            <Halaman>
                <PageHeader title="Tulis ulasan" />

                <ErrorState
                    error={sesi.error}
                    onRetry={() => {
                        void sesi.refetch();
                    }}
                />
            </Halaman>
        );
    }

    if (saya.isError) {
        return (
            <Halaman>
                <PageHeader title="Tulis ulasan" />

                <ErrorState
                    error={saya.error}
                    onRetry={() => {
                        void saya.refetch();
                    }}
                />
            </Halaman>
        );
    }

    const konsultasi = sesi.data.data.konsultasi;
    const user = saya.data.data.user;

    if (user.tipe !== 'pasien') {
        return (
            <Halaman>
                <PageHeader title="Tulis ulasan" />

                <ForbiddenState detail="Ulasan hanya dapat ditulis oleh akun pasien pemilik konsultasi." />
            </Halaman>
        );
    }

    if (konsultasi.status !== 'selesai') {
        return (
            <Halaman>
                <PageHeader title="Tulis ulasan" />

                <EmptyState
                    title="Ulasan belum dapat ditulis."
                    description="Ulasan hanya dapat ditulis setelah konsultasi berstatus selesai."
                    action={
                        <Button asChild variant="outline" className="min-h-11">
                            <Link to={`/konsultasi/${String(konsultasi.id)}`}>
                                Kembali ke konsultasi
                            </Link>
                        </Button>
                    }
                />
            </Halaman>
        );
    }

    const namaDokter = konsultasi.dokter?.nama_lengkap ?? null;

    return (
        <Halaman>
            <Button asChild variant="ghost" className="min-h-11 w-fit">
                <Link to={`/konsultasi/${String(konsultasi.id)}`}>
                    <ArrowLeft aria-hidden />

                    Kembali ke konsultasi
                </Link>
            </Button>

            <PageHeader
                title="Tulis ulasan"
                description={
                    namaDokter === null
                        ? `Untuk konsultasi #${String(konsultasi.id)}.`
                        : `Untuk konsultasi #${String(konsultasi.id)} bersama ${namaDokter}.`
                }
            />

            <OfflineBanner message="Anda sedang offline. Ulasan tidak dikirim sampai koneksi kembali." />

            <FormUlasan konsultasi={konsultasi} />
        </Halaman>
    );
}

/**
 * The page frame. `AppShell` already owns the `<main>` landmark for every signed-in
 * screen, so this only constrains width and rhythm - a second `<main>` here would be a
 * duplicate landmark in one document.
 */
function Halaman({ children }: { children: ReactNode }) {
    return <div className="mx-auto flex w-full max-w-3xl flex-col gap-6">{children}</div>;
}
