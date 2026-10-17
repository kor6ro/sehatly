import { useQuery } from '@tanstack/react-query';
import { MapPin, Package } from 'lucide-react';
import { Link } from 'react-router';
import { kurirAntreanOptions } from '@/lib/api/kurir';
import { formatWaktu } from '@/lib/format';
import { ApiError } from '@/lib/http';
import { Card, CardContent } from '@/components/ui/card';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';
import { EmptyState } from '@/components/states/empty-state';
import { KurirStatusBadge } from '@/features/kurir/kurir-status-badge';

export function KurirPesananPage() {
    const antrean = useQuery(kurirAntreanOptions());

    if (antrean.isPending) {
        return <SkeletonRows rows={5} />;
    }

    if (antrean.isError) {
        if (antrean.error instanceof ApiError && antrean.error.isForbidden) {
            return (
                <ForbiddenState detail="Halaman ini hanya untuk akun kurir." />
            );
        }

        return (
            <ErrorState
                error={antrean.error}
                onRetry={() => {
                    void antrean.refetch();
                }}
            />
        );
    }

    const pesanan = antrean.data.data.pesanan;

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between">
                <h1 className="text-xl font-semibold">Antrean pengantaran</h1>

                <span className="text-muted-foreground text-sm">
                    {pesanan.length} pesanan
                </span>
            </div>

            {pesanan.length === 0 ? (
                <EmptyState
                    title="Tidak ada pesanan"
                    description="Belum ada pesanan yang perlu diantarkan saat ini."
                />
            ) : (
                <ol className="flex flex-col gap-3">
                    {pesanan.map((p) => (
                        <li key={p.id}>
                            <Link to={`/kurir/pesanan/${String(p.id)}`}>
                                <Card className="hover:bg-accent/50 transition-colors">
                                    <CardContent className="flex flex-col gap-2">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="flex items-center gap-2">
                                                <Package
                                                    aria-hidden
                                                    className="text-muted-foreground mt-0.5 size-4 shrink-0"
                                                />

                                                <span className="font-medium">
                                                    {p.nomor_pesanan}
                                                </span>
                                            </div>

                                            <KurirStatusBadge status={p.status} />
                                        </div>

                                        {p.apotek === null ? null : (
                                            <p className="text-muted-foreground flex items-center gap-1.5 text-sm">
                                                <MapPin
                                                    aria-hidden
                                                    className="size-3.5 shrink-0"
                                                />

                                                <span className="truncate">
                                                    {p.apotek.nama}
                                                    {p.apotek.alamat
                                                        ? ` — ${p.apotek.alamat}`
                                                        : ''}
                                                </span>
                                            </p>
                                        )}

                                        <p className="text-muted-foreground truncate text-sm">
                                            <span className="font-medium text-foreground">
                                                Tujuan:{' '}
                                            </span>

                                            {p.alamat_kirim}
                                        </p>

                                        <p className="text-muted-foreground text-xs">
                                            {formatWaktu(p.dibuat_at)}
                                        </p>
                                    </CardContent>
                                </Card>
                            </Link>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
