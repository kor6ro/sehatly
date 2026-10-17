import { PageHeader } from '@/components/layout/page-header';
import { EmptyState } from '@/components/states/empty-state';

export function MarketingPromoPage() {
    return (
        <div className="flex flex-col gap-6">
            <PageHeader
                title="Kelola Promo"
                description="Buat dan kelola kode promo untuk pasien."
            />

            <EmptyState
                title="Halaman ini sedang disiapkan"
                description="Endpoint API untuk promo belum tersedia. Halaman ini akan aktif setelah MarketingPromoController selesai dibuat (plan section 5.3)."
            />
        </div>
    );
}
