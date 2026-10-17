import { Construction, FileText, Tag } from 'lucide-react';
import { Link } from 'react-router';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/layout/page-header';

export function MarketingDashboardPage() {
    return (
        <div className="flex flex-col gap-6">
            <PageHeader
                title="Dashboard Marketing"
                description="Kelola konten artikel dan promosi Sehatly."
            />

            <div className="grid gap-4 sm:grid-cols-2">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between pb-2">
                        <CardTitle className="text-sm font-medium text-muted-foreground">
                            Artikel
                        </CardTitle>

                        <FileText aria-hidden className="text-muted-foreground size-4" />
                    </CardHeader>

                    <CardContent className="flex flex-col gap-3">
                        <p className="text-muted-foreground text-sm">
                            Buat dan kelola artikel kesehatan untuk halaman publik.
                        </p>

                        <Button asChild variant="outline" size="sm" className="w-full">
                            <Link to="/marketing/artikel">Kelola artikel</Link>
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between pb-2">
                        <CardTitle className="text-sm font-medium text-muted-foreground">
                            Promo
                        </CardTitle>

                        <Tag aria-hidden className="text-muted-foreground size-4" />
                    </CardHeader>

                    <CardContent className="flex flex-col gap-3">
                        <p className="text-muted-foreground text-sm">
                            Buat dan kelola kode promo untuk pasien.
                        </p>

                        <Button asChild variant="outline" size="sm" className="w-full">
                            <Link to="/marketing/promo">Kelola promo</Link>
                        </Button>
                    </CardContent>
                </Card>
            </div>

            <div className="text-muted-foreground flex items-center gap-2 text-sm">
                <Construction aria-hidden className="size-4 shrink-0" />
                <span>
                    Endpoint API untuk artikel dan promo belum tersedia — halaman ini akan
                    menampilkan data setelah backend selesai (plan section 5.3).
                </span>
            </div>
        </div>
    );
}
