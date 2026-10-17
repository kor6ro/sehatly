import { PageHeader } from '@/components/layout/page-header';
import { EmptyState } from '@/components/states/empty-state';

export function MarketingArtikelPage() {
    return (
        <div className="flex flex-col gap-6">
            <PageHeader
                title="Kelola Artikel"
                description="Buat dan kelola artikel kesehatan untuk halaman publik."
            />

            <EmptyState
                title="Halaman ini sedang disiapkan"
                description="Endpoint API untuk artikel belum tersedia. Halaman ini akan aktif setelah MarketingArtikelController selesai dibuat (plan section 5.3)."
            />
        </div>
    );
}
