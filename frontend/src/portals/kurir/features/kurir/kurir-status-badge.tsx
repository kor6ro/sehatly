import { CircleCheck, CircleX, Clock, Package, PackageCheck, Truck } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { StatusPesananObat } from '@/lib/api/types';
import { labelStatusPesanan } from '@/lib/api/pesanan-obat';

const TAMPILAN: Record<StatusPesananObat, { icon: React.ElementType; kelas: string }> = {
    menunggu_pembayaran: {
        icon: Clock,
        kelas: 'bg-warning-subtle text-warning-subtle-foreground border-transparent',
    },
    diproses: {
        icon: Package,
        kelas: 'bg-warning-subtle text-warning-subtle-foreground border-transparent',
    },
    siap: {
        icon: PackageCheck,
        kelas: 'bg-success-subtle text-success-subtle-foreground border-transparent',
    },
    sedang_dikirim: {
        icon: Truck,
        kelas: 'bg-primary/10 text-primary border-transparent',
    },
    selesai: {
        icon: CircleCheck,
        kelas: 'bg-success-subtle text-success-subtle-foreground border-transparent',
    },
    dibatalkan: {
        icon: CircleX,
        kelas: 'bg-destructive-subtle text-destructive-subtle-foreground border-transparent',
    },
};

export function KurirStatusBadge({
    status,
    className,
}: {
    status: StatusPesananObat;
    className?: string;
}) {
    const tampilan = TAMPILAN[status] ?? TAMPILAN.diproses;
    const Ikon = tampilan.icon;

    return (
        <Badge
            variant="outline"
            data-slot="kurir-status-badge"
            data-status={status}
            className={cn('gap-1 font-normal', tampilan.kelas, className)}
        >
            <Ikon aria-hidden className="size-3.5" />

            {labelStatusPesanan(status)}
        </Badge>
    );
}
