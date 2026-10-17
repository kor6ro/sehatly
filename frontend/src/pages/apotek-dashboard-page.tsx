import { useQuery } from '@tanstack/react-query';
import { ClipboardList, Pill } from 'lucide-react';
import { Link } from 'react-router';
import { antreanResepOptions } from '@/lib/api/resep';
import type { AntreanResepFilters } from '@/lib/api/resep';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState } from '@/components/states/error-state';
import { PageHeader } from '@/components/layout/page-header';

export function ApotekDashboardPage() {
    const filters: AntreanResepFilters = { page: 1, per_page: 1, status: 'aktif' };
    const antrean = useQuery(antreanResepOptions(filters));

    const jumlah = antrean.data?.meta?.total ?? null;

    return (
        <div className="flex flex-col gap-6">
            <PageHeader
                title="Dashboard Apotek"
                description="Ringkasan antrean verifikasi resep hari ini."
            />

            {antrean.isPending ? (
                <SkeletonRows rows={2} />
            ) : antrean.isError ? (
                <ErrorState
                    error={antrean.error}
                    onRetry={() => void antrean.refetch()}
                />
            ) : (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Resep belum diverifikasi
                            </CardTitle>

                            <Pill aria-hidden className="text-muted-foreground size-4" />
                        </CardHeader>

                        <CardContent>
                            <p className="text-3xl font-bold tabular-nums">
                                {jumlah ?? '—'}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Antrean verifikasi
                            </CardTitle>

                            <ClipboardList aria-hidden className="text-muted-foreground size-4" />
                        </CardHeader>

                        <CardContent>
                            <Button asChild className="w-full">
                                <Link to="/apotek/resep">Buka antrean</Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            )}
        </div>
    );
}
