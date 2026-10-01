import {
    Ban,
    CheckCircle2,
    Clock,
    FileText,
    Package,
    PackageCheck,
    RotateCcw,
    TimerOff,
    Truck,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { labelStatusInvoice, labelStatusPembayaran } from '@/lib/api/pembayaran';
import { labelStatusPesanan } from '@/lib/api/pesanan-obat';
import type {
    StatusInvoice,
    StatusPembayaran,
    StatusPesananObat,
} from '@/lib/api/types';

/**
 * Status, as the three channels `web/AGENTS.md` requires: text, icon and colour.
 *
 * The fills are the same treatments `BookingStatusBadge` uses, for the same reason: a
 * coloured *word* cannot be read in greyscale, and `bg-warning`/`bg-success` chips keep
 * the text at `*-foreground`, which is a measured-contrast pair rather than a mid-tone on
 * white. F06 shows three different vocabularies - the order's, the bill's and the
 * gateway's - and each gets its own component so a caller cannot pass the wrong label
 * function by accident.
 */

type Tampilan = {
    icon: LucideIcon;
    className: string;
};

const TAMPILAN_PESANAN: Record<StatusPesananObat, Tampilan> = {
    menunggu_pembayaran: {
        icon: Clock,
        className: 'bg-warning text-warning-foreground border-transparent',
    },
    diproses: {
        icon: Package,
        className: 'bg-primary text-primary-foreground border-transparent',
    },
    siap: {
        icon: PackageCheck,
        className: 'bg-primary text-primary-foreground border-transparent',
    },
    sedang_dikirim: {
        icon: Truck,
        className: 'bg-primary text-primary-foreground border-transparent',
    },
    selesai: {
        icon: CheckCircle2,
        className: 'bg-success text-success-foreground border-transparent',
    },
    dibatalkan: {
        icon: XCircle,
        className: 'text-destructive border-destructive',
    },
};

const TAMPILAN_INVOICE: Record<StatusInvoice, Tampilan> = {
    draft: {
        icon: FileText,
        className: 'text-muted-foreground bg-muted border-transparent',
    },
    menunggu_pembayaran: {
        icon: Clock,
        className: 'bg-warning text-warning-foreground border-transparent',
    },
    lunas: {
        icon: CheckCircle2,
        className: 'bg-success text-success-foreground border-transparent',
    },
    kadaluarsa: {
        icon: TimerOff,
        className: 'text-muted-foreground bg-muted/40 border-dashed',
    },
    dibatalkan: {
        icon: Ban,
        className: 'text-destructive border-destructive',
    },
    refund_sebagian: {
        icon: RotateCcw,
        className: 'text-muted-foreground bg-muted border-transparent',
    },
    refund_penuh: {
        icon: RotateCcw,
        className: 'text-muted-foreground bg-muted border-transparent',
    },
};

const TAMPILAN_PEMBAYARAN: Record<StatusPembayaran, Tampilan> = {
    pending: {
        icon: Clock,
        className: 'bg-warning text-warning-foreground border-transparent',
    },
    berhasil: {
        icon: CheckCircle2,
        className: 'bg-success text-success-foreground border-transparent',
    },
    gagal: {
        icon: XCircle,
        className: 'text-destructive border-destructive',
    },
    kedaluwarsa: {
        icon: TimerOff,
        className: 'text-muted-foreground bg-muted/40 border-dashed',
    },
    refund: {
        icon: RotateCcw,
        className: 'text-muted-foreground bg-muted border-transparent',
    },
};

export function StatusPesananBadge({
    status,
    className,
}: {
    status: StatusPesananObat;
    className?: string;
}) {
    return (
        <Tampil
            slot="pesanan-status"
            status={status}
            label={labelStatusPesanan(status)}
            tampilan={TAMPILAN_PESANAN[status]}
            className={className}
        />
    );
}

export function StatusInvoiceBadge({
    status,
    className,
}: {
    status: StatusInvoice;
    className?: string;
}) {
    return (
        <Tampil
            slot="invoice-status"
            status={status}
            label={labelStatusInvoice(status)}
            tampilan={TAMPILAN_INVOICE[status]}
            className={className}
        />
    );
}

export function StatusPembayaranBadge({
    status,
    className,
}: {
    status: StatusPembayaran;
    className?: string;
}) {
    return (
        <Tampil
            slot="pembayaran-status"
            status={status}
            label={labelStatusPembayaran(status)}
            tampilan={TAMPILAN_PEMBAYARAN[status]}
            className={className}
        />
    );
}

function Tampil({
    slot,
    status,
    label,
    tampilan,
    className,
}: {
    slot: string;
    status: string;
    label: string;
    tampilan: Tampilan;
    className?: string;
}) {
    const Icon = tampilan.icon;

    return (
        <Badge
            variant="outline"
            data-slot={slot}
            data-status={status}
            className={cn('gap-1', tampilan.className, className)}
        >
            <Icon aria-hidden />

            {label}
        </Badge>
    );
}
