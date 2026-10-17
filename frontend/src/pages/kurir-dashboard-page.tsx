import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { Package, Truck, CheckCircle, Clock } from 'lucide-react';
import { kurirAntreanOptions } from '@/lib/api/kurir';
import { ApiError } from '@/lib/http';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';

export function KurirDashboardPage() {
    const antrean = useQuery(kurirAntreanOptions());

    if (antrean.isPending) {
        return <SkeletonRows rows={4} />;
    }

    if (antrean.isError) {
        if (antrean.error instanceof ApiError && antrean.error.isForbidden) {
            return <ForbiddenState detail="Halaman ini hanya untuk akun kurir." />;
        }

        return (
            <ErrorState
                error={antrean.error}
                onRetry={() => void antrean.refetch()}
            />
        );
    }

    const pesanan = antrean.data.data.pesanan;

    const siap = pesanan.filter((p) => p.status === 'siap').length;
    const sedangDikirim = pesanan.filter((p) => p.status === 'sedang_dikirim').length;
    const diproses = pesanan.filter((p) => p.status === 'diproses').length;
    const selesai = pesanan.filter((p) => p.status === 'selesai').length;

    return (
        <div className="flex flex-col gap-6">
            <h1 className="text-xl font-semibold">Dashboard Kurir</h1>

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatCard
                    icon={Clock}
                    label="Diproses"
                    value={diproses}
                    className="text-warning-subtle-foreground"
                />

                <StatCard
                    icon={Package}
                    label="Siap diambil"
                    value={siap}
                    className="text-primary"
                />

                <StatCard
                    icon={Truck}
                    label="Sedang dikirim"
                    value={sedangDikirim}
                    className="text-blue-500"
                />

                <StatCard
                    icon={CheckCircle}
                    label="Selesai"
                    value={selesai}
                    className="text-green-500"
                />
            </div>

            <div className="flex items-center justify-between">
                <p className="text-muted-foreground text-sm">
                    {siap + sedangDikirim} pesanan perlu ditangani
                </p>

                <Button asChild size="sm">
                    <Link to="/kurir/pesanan">Lihat antrean</Link>
                </Button>
            </div>
        </div>
    );
}

function StatCard({
    icon: Ikon,
    label,
    value,
    className,
}: {
    icon: React.ElementType;
    label: string;
    value: number;
    className?: string;
}) {
    return (
        <Card>
            <CardHeader className="pb-1">
                <CardTitle className="text-muted-foreground text-xs font-normal">
                    {label}
                </CardTitle>
            </CardHeader>

            <CardContent className="flex items-center gap-2">
                <Ikon aria-hidden className={`size-5 shrink-0 ${className ?? ''}`} />
                <span className="text-2xl font-bold">{value}</span>
            </CardContent>
        </Card>
    );
}
