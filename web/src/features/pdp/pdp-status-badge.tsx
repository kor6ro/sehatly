import { CheckCircle2, Clock, XCircle } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';

/**
 * The three-state consent badge: text, icon and colour, never colour alone.
 *
 * `null`, `true` and `false` are three different facts and the copy says which: "Belum
 * dijawab" is not "Tidak disetujui", and rendering both the same way would collapse a
 * refusal into an absence.
 *
 * The treatment is the soft one - tinted fill, coloured icon, `--foreground` text - and
 * not the filled `bg-success text-success-foreground` chip other status badges use. White
 * on `--success` measures below the 4.5:1 the project requires, which the axe pass in
 * `pdp-f02.spec.ts` caught; the tint keeps the colour cue in the icon and the wash while
 * the reading text stays at full contrast.
 */

type Tampilan = {
    icon: LucideIcon;
    label: string;
    className: string;
    iconClassName: string;
};

const TAMPILAN: Record<'null' | 'true' | 'false', Tampilan> = {
    null: {
        icon: Clock,
        label: 'Belum dijawab',
        className:
            'bg-warning-subtle text-warning-subtle-foreground border-transparent',
        iconClassName: 'text-warning-subtle-foreground',
    },
    true: {
        icon: CheckCircle2,
        label: 'Disetujui',
        className:
            'bg-success-subtle text-success-subtle-foreground border-transparent',
        iconClassName: 'text-success-subtle-foreground',
    },
    false: {
        icon: XCircle,
        label: 'Tidak disetujui',
        className:
            'bg-destructive-subtle text-destructive-subtle-foreground border-transparent',
        iconClassName: 'text-destructive-subtle-foreground',
    },
};

export function PdpStatusBadge({
    efektif,
    className,
}: {
    efektif: boolean | null;
    className?: string;
}) {
    const kunci = efektif === null ? 'null' : efektif ? 'true' : 'false';
    const tampilan = TAMPILAN[kunci];
    const Icon = tampilan.icon;

    return (
        <Badge
            variant="outline"
            data-slot="pdp-status"
            data-status={kunci}
            className={cn('gap-1', tampilan.className, className)}
        >
            <Icon aria-hidden className={tampilan.iconClassName} />

            {tampilan.label}
        </Badge>
    );
}
