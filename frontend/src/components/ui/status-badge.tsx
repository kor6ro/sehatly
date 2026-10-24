import * as React from 'react';
import { cn } from '@/lib/utils';
import { Badge, badgeVariants } from '@/components/ui/badge';
import type { VariantProps } from 'class-variance-authority';

/**
 * Generic status badge used by feature cards (e.g. refund status). It wraps the
 * shared `Badge` primitive and adds an optional leading icon plus a `data-status`
 * attribute for test scoping. Variants mirror the subtle badge treatments.
 */
export type StatusBadgeVariant =
    | 'default'
    | 'secondary'
    | 'destructive'
    | 'outline'
    | 'muted'
    | 'successSubtle'
    | 'warningSubtle'
    | 'destructiveSubtle';

const STATUS_BADGE_EXTRA: Record<string, string> = {
    muted: 'border-transparent bg-muted text-muted-foreground',
};

export interface StatusBadgeProps extends React.ComponentProps<'span'> {
    variant?: StatusBadgeVariant;
    icon?: React.ComponentType<{ className?: string; 'aria-hidden'?: boolean }>;
    dataStatus?: string;
}

export function StatusBadge({
    className,
    variant = 'default',
    icon: Icon,
    dataStatus,
    children,
    ...props
}: StatusBadgeProps) {
    const extra = STATUS_BADGE_EXTRA[variant ?? 'default'] ?? '';

    return (
        <Badge
            variant={
                variant === 'successSubtle' ||
                variant === 'warningSubtle' ||
                variant === 'destructiveSubtle'
                    ? (variant as VariantProps<typeof badgeVariants>['variant'])
                    : variant === 'muted'
                      ? 'outline'
                      : (variant as VariantProps<typeof badgeVariants>['variant'])
            }
            data-slot="status-badge"
            data-status={dataStatus}
            className={cn('gap-1', extra, className)}
            {...props}
        >
            {Icon ? <Icon aria-hidden /> : null}
            {children}
        </Badge>
    );
}

export { badgeVariants };