import { useQuery } from '@tanstack/react-query';
import { FileText, Tag } from 'lucide-react';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHeader } from '@/shared/components/layout/page-header';
import { ErrorState } from '@/shared/components/states/error-state';
import { LoadingState } from '@/shared/components/states/loading-state';
import {
    artikelListOptions,
    promoListOptions,
    type Artikel,
    type Promo,
} from '@/lib/api/marketing';

function hitungArtikel(data: Artikel[] | undefined) {
    if (!data) return null;
    return {
        total: data.length,
        draft: data.filter((a) => a.status === 'draft').length,
        terbit: data.filter((a) => a.status === 'terbit').length,
        arsip: data.filter((a) => a.status === 'arsip').length,
    };
}

function hitungPromo(data: Promo[] | undefined) {
    if (!data) return null;
    return {
        total: data.length,
        aktif: data.filter((p) => p.status_aktif).length,
        nonaktif: data.filter((p) => !p.status_aktif).length,
    };
}

function KartuArtikel({ error, onRetry }: { error: unknown; onRetry: () => void }) {
    const { data, isLoading } = useQuery(artikelListOptions());
    const stats = hitungArtikel(data);

    if (isLoading && !stats) {
        return (
            <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                    <CardTitle className="text-sm font-medium text-muted-foreground">
                        Artikel
                    </CardTitle>
                    <FileText aria-hidden className="text-muted-foreground size-4" />
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                    <LoadingState label="Memuat artikel..." />
                </CardContent>
            </Card>
        );
    }

    if (error) {
        return (
            <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                    <CardTitle className="text-sm font-medium text-muted-foreground">
                        Artikel
                    </CardTitle>
                    <FileText aria-hidden className="text-muted-foreground size-4" />
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                    <ErrorState error={error} onRetry={onRetry} title="Gagal memuat artikel" />
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">Artikel</CardTitle>
                <FileText aria-hidden className="text-muted-foreground size-4" />
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                <div className="grid gap-2 sm:grid-cols-3">
                    <div className="bg-muted rounded-md p-3">
                        <p className="text-xs text-muted-foreground">Total</p>
                        <p className="text-lg font-semibold text-foreground">{stats?.total ?? 0}</p>
                    </div>
                    <div className="bg-muted rounded-md p-3">
                        <p className="text-xs text-muted-foreground">Draft</p>
                        <p className="text-lg font-semibold text-foreground">{stats?.draft ?? 0}</p>
                    </div>
                    <div className="bg-muted rounded-md p-3">
                        <p className="text-xs text-muted-foreground">Terbit</p>
                        <p className="text-lg font-semibold text-foreground">{stats?.terbit ?? 0}</p>
                    </div>
                    <div className="bg-muted rounded-md p-3">
                        <p className="text-xs text-muted-foreground">Arsip</p>
                        <p className="text-lg font-semibold text-foreground">{stats?.arsip ?? 0}</p>
                    </div>
                </div>
                <Button
                    asChild
                    variant="outline"
                    size="sm"
                    className="w-full min-h-11"
                >
                    <Link to="/marketing/artikel">Kelola artikel</Link>
                </Button>
            </CardContent>
        </Card>
    );
}

function KartuPromo({ error, onRetry }: { error: unknown; onRetry: () => void }) {
    const { data, isLoading } = useQuery(promoListOptions());
    const stats = hitungPromo(data);

    if (isLoading && !stats) {
        return (
            <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                    <CardTitle className="text-sm font-medium text-muted-foreground">Promo</CardTitle>
                    <Tag aria-hidden className="text-muted-foreground size-4" />
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                    <LoadingState label="Memuat promo..." />
                </CardContent>
            </Card>
        );
    }

    if (error) {
        return (
            <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                    <CardTitle className="text-sm font-medium text-muted-foreground">Promo</CardTitle>
                    <Tag aria-hidden className="text-muted-foreground size-4" />
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                    <ErrorState error={error} onRetry={onRetry} title="Gagal memuat promo" />
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">Promo</CardTitle>
                <Tag aria-hidden className="text-muted-foreground size-4" />
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                <div className="grid gap-2 sm:grid-cols-3">
                    <div className="bg-muted rounded-md p-3">
                        <p className="text-xs text-muted-foreground">Total</p>
                        <p className="text-lg font-semibold text-foreground">{stats?.total ?? 0}</p>
                    </div>
                    <div className="bg-muted rounded-md p-3">
                        <p className="text-xs text-muted-foreground">Aktif</p>
                        <p className="text-lg font-semibold text-foreground">{stats?.aktif ?? 0}</p>
                    </div>
                    <div className="bg-muted rounded-md p-3">
                        <p className="text-xs text-muted-foreground">Nonaktif</p>
                        <p className="text-lg font-semibold text-foreground">
                            {stats?.nonaktif ?? 0}
                        </p>
                    </div>
                </div>
                <Button
                    asChild
                    variant="outline"
                    size="sm"
                    className="w-full min-h-11"
                >
                    <Link to="/marketing/promo">Kelola promo</Link>
                </Button>
            </CardContent>
        </Card>
    );
}

export function MarketingDashboardPage() {
    const artikelQuery = useQuery(artikelListOptions());
    const promoQuery = useQuery(promoListOptions());

    const loading = artikelQuery.isLoading || promoQuery.isLoading;
    const error = artikelQuery.error ?? promoQuery.error;

    const onRetry = () => {
        artikelQuery.refetch();
        promoQuery.refetch();
    };

    if (loading && !artikelQuery.data && !promoQuery.data) {
        return (
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Dashboard Marketing"
                    description="Kelola konten artikel dan promosi Sehatly."
                />
                <LoadingState label="Memuat dashboard..." />
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-6">
            <PageHeader
                title="Dashboard Marketing"
                description="Kelola konten artikel dan promosi Sehatly."
            />

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-2">
                <KartuArtikel error={error} onRetry={onRetry} />
                <KartuPromo error={error} onRetry={onRetry} />
            </div>

            {error && (
                <ErrorState error={error} onRetry={onRetry} title="Gagal memuat dashboard" />
            )}
        </div>
    );
}
