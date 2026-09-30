import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { Package, Pill } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { meOptions } from '@/lib/api/me';
import { riwayatResepOptions } from '@/lib/api/resep';
import { BISA_CHECKOUT, resepSiapCheckout } from '@/lib/api/tujuan';
import { formatTanggal, formatWaktu } from '@/lib/format';
import { PageHeader } from '@/components/layout/page-header';
import { SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { StatusResepBadge } from '@/features/resep/resep-status-badge';

/**
 * `/checkout` - which prescription to check out, resolved from the caller's own rows.
 *
 * ## F3-06, and why this page is a list rather than a redirect
 *
 * The sidebar linked `/checkout/1`, which is a row belonging to whoever was seeded first:
 * a 404 card for every other account. `GET /api/v1/pasien/resep` is the one endpoint in the
 * 74 that lists prescriptions the caller owns, so the destination the nav can honestly
 * offer is built from it.
 *
 * It is a list and not a silent `navigate()` to the newest row, for one reason: the
 * newest prescription is not always the one that can be ordered. `POST /resep/{id}/checkout`
 * is refused with a 422 unless the prescription has reached `diverifikasi`, so redirecting
 * to the newest row would land the patient on a refusal they did not cause. The eligible
 * rows are listed, and the ones that are not eligible say which status they are in - which
 * is the same reason `checkout-page.tsx` hides its form for them.
 *
 * ## The eligibility list is shared, not copied
 *
 * `BISA_CHECKOUT` lives in `lib/api/tujuan.ts` next to the resolver, so the index and the
 * checkout screen cannot disagree about what the server will accept.
 */
export function CheckoutIndexPage() {
    const saya = useQuery(meOptions());

    const user = saya.data?.data.user ?? null;
    const boleh = user === null || user.tipe === 'pasien';

    const riwayat = useQuery({
        ...riwayatResepOptions({ page: 1, per_page: 50 }),
        enabled: boleh,
    });

    const semua = riwayat.data?.data.resep ?? [];
    const siap = resepSiapCheckout(semua);

    return (
        <>
            <PageHeader
                title="Checkout resep"
                description="POST /api/v1/resep/{id}/checkout. Sumber daftarnya adalah GET /api/v1/pasien/resep, jadi setiap baris di bawah milik akun ini."
            />

            {user !== null && user.tipe !== 'pasien' ? (
                <ForbiddenState detail="Resep adalah data milik akun pasien. Akun dokter, apoteker, admin, dan superadmin tidak memiliki baris pasien, jadi server menjawab 403 untuk riwayat resep." />
            ) : riwayat.isPending ? (
                <SkeletonRows rows={4} />
            ) : riwayat.isError ? (
                riwayat.error instanceof ApiError &&
                riwayat.error.isForbidden ? (
                    <ForbiddenState detail="Akun ini tidak memiliki data pasien, sehingga riwayat resep tidak dapat dimuat." />
                ) : (
                    <ErrorState
                        error={riwayat.error}
                        onRetry={() => {
                            void riwayat.refetch();
                        }}
                    />
                )
            ) : semua.length === 0 ? (
                <EmptyState
                    title="Belum ada resep"
                    description="Resep ditulis dokter pada sesi konsultasi, lalu diverifikasi apoteker sebelum dapat dipesan. Tidak ada resep milik akun ini untuk dicheckout."
                    action={
                        <Button asChild variant="outline" size="sm">
                            <Link to="/konsultasi">Lihat konsultasi</Link>
                        </Button>
                    }
                />
            ) : siap.length === 0 ? (
                <EmptyState
                    title="Belum ada resep yang siap dipesan"
                    description={`Server hanya menerima checkout untuk resep berstatus ${BISA_CHECKOUT.join(', ')}. Resep milik akun ini belum sampai tahap itu, jadi tidak ada yang dapat dipesan sekarang.`}
                    action={
                        <Button asChild variant="outline" size="sm">
                            <Link to="/pasien/resep">Lihat riwayat resep</Link>
                        </Button>
                    }
                />
            ) : (
                <ul data-slot="checkout-indeks" className="flex flex-col gap-3">
                    {siap.map((resep) => (
                        <li key={resep.id}>
                            <Card data-slot="checkout-indeks-item">
                                <CardHeader>
                                    <CardTitle className="flex flex-wrap items-center gap-2">
                                        <Pill aria-hidden className="size-4" />

                                        {resep.nomor_resep}
                                    </CardTitle>

                                    <CardDescription>
                                        Ditulis {formatWaktu(resep.tanggal_resep)} - berlaku
                                        sampai{' '}
                                        {resep.berlaku_sampai === null
                                            ? 'tanpa masa berlaku'
                                            : formatTanggal(resep.berlaku_sampai)}
                                        .
                                    </CardDescription>
                                </CardHeader>

                                <CardContent className="flex flex-wrap items-center justify-between gap-3">
                                    <StatusResepBadge status={resep.status} />

                                    <Button asChild size="sm">
                                        <Link to={`/checkout/${resep.id}`}>
                                            <Package aria-hidden />

                                            Checkout
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        </li>
                    ))}

                    <li>
                        <Alert variant="default">
                            <Pill aria-hidden />

                            <AlertTitle>
                                Resep lain menunggu verifikasi
                            </AlertTitle>

                            <AlertDescription>
                                <p>
                                    Hanya {siap.length} dari {semua.length} resep milik akun
                                    ini yang sudah bisa dipesan. Sisanya tetap
                                    terlihat di{' '}
                                    <Link
                                        to="/pasien/resep"
                                        className="underline"
                                    >
                                        riwayat resep
                                    </Link>
                                    .
                                </p>
                            </AlertDescription>
                        </Alert>
                    </li>
                </ul>
            )}
        </>
    );
}
