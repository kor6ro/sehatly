import { Ban, CircleCheck, Clock, RefreshCw } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { labelStatusRefund } from '@/lib/api/refund';
import type { Refund, StatusRefund } from '@/lib/api/refund';
import { cn } from '@/lib/utils';
import { formatRupiah } from '@/lib/format';
import { formatWaktuZona } from '@/lib/waktu';
import { Badge } from '@/components/ui/badge';

/**
 * The four `refund.status` values, as four distinguishable treatments.
 *
 * The status channel is always **text + icon + colour**: `diajukan` muted, `diproses`
 * warning, `berhasil` success, `ditolak` destructive. The icon is what carries the state
 * for a colour-blind reader or a greyscale screenshot, and the label is what carries it
 * for a screen reader; colour is the third channel, never the only one.
 *
 * The label stays `text-foreground` on a tinted fill rather than `-foreground` on a solid
 * fill because the solid success fill measures below the 4.5:1 text threshold; the tint
 * plus the coloured icon keep the hue without borrowing the failure. The icon is a
 * graphic, so it is held to 3:1, which every treatment here meets.
 */
type Treatment = {
    icon: LucideIcon;
    className: string;
    iconClassName: string;
};

const TREATMENT: Record<StatusRefund, Treatment> = {
    diajukan: {
        icon: Clock,
        className: 'text-foreground bg-muted border-transparent',
        iconClassName: 'text-muted-foreground',
    },
    diproses: {
        icon: RefreshCw,
        className: 'text-foreground bg-warning/25 border-transparent',
        iconClassName: 'text-warning-foreground',
    },
    berhasil: {
        icon: CircleCheck,
        className: 'text-foreground bg-success/25 border-transparent',
        iconClassName: 'text-success',
    },
    ditolak: {
        icon: Ban,
        className: 'text-foreground bg-destructive/15 border-transparent',
        iconClassName: 'text-destructive',
    },
};

export function RefundStatusBadge({ status }: { status: StatusRefund }) {
    const treatment = TREATMENT[status];
    const Icon = treatment.icon;

    return (
        <Badge
            variant="outline"
            className={cn('gap-1', treatment.className)}
            data-status={status}
        >
            <Icon aria-hidden className={treatment.iconClassName} />

            {labelStatusRefund(status)}
        </Badge>
    );
}

/**
 * One refund row rendered on its booking.
 *
 * ## No completion date, and no progress bar
 *
 * `refund` has no transition timestamp the endpoint publishes (`dibuat_at` is when the row
 * was written) and the policy's `sla` is `null` because no turnaround has been decided. So
 * a `diproses` or `berhasil` card states the status it actually has and stops: there is no
 * "Selesai {tanggal}", no estimate, and no progress indicator, because every one of those
 * would be a date this client invented. The only date shown is the server's `dibuat_at`,
 * converted to the device zone with its label.
 *
 * ## The amount is text, not a toast
 *
 * Money is not medical data, but it is also not a thing to announce in a transient toast:
 * the card is persistent, re-readable, and reachable by its `id` from the row's
 * "Lihat pengembalian dana" link.
 */
export function RefundCard({ refund }: { refund: Refund }) {
    return (
        <section
            id={`refund-${String(refund.booking_id)}`}
            tabIndex={-1}
            role="status"
            data-slot="refund-kartu"
            data-status={refund.status}
            className="bg-muted/40 flex flex-col gap-2 rounded-lg border p-3"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-medium">Pengembalian dana</p>

                <RefundStatusBadge status={refund.status} />
            </div>

            <dl className="grid gap-1 text-sm sm:grid-cols-2">
                <div className="flex gap-1">
                    <dt className="text-muted-foreground">Jumlah</dt>

                    <dd className="font-medium tabular-nums">
                        {formatRupiah(refund.jumlah)}
                    </dd>
                </div>

                <div className="flex gap-1">
                    <dt className="text-muted-foreground">Tujuan</dt>

                    <dd className="font-medium">
                        {refund.metode?.label ?? 'Belum tersedia'}
                    </dd>
                </div>

                <div className="flex gap-1 sm:col-span-2">
                    <dt className="text-muted-foreground">Diajukan</dt>

                    <dd>{formatWaktuZona(refund.dibuat_at)}</dd>
                </div>
            </dl>
        </section>
    );
}
