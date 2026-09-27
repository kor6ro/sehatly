import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { Spinner } from '@/components/ui/spinner';

/**
 * The loading state for a data-bearing view.
 *
 * ## Why a label and not only a spinner
 *
 * A spinner says "something is happening" and nothing about what. On a screen that can
 * take a second or two - the directory runs four joins behind `v_dokter_katalog` - a bare
 * spinner is indistinguishable from a hung request, and the user has no way to tell
 * whether to wait or to leave. A named, announced wait is the difference.
 *
 * `role="status"` with `aria-live="polite"` puts it in the accessibility tree without
 * stealing focus, so a screen reader announces the wait rather than only the eventual
 * result.
 */
export function LoadingState({
    label,
    className,
    children,
}: {
    label: string;
    className?: string;
    /** Skeletons, so the page keeps its shape while the data is in flight. */
    children?: ReactNode;
}) {
    return (
        <div
            role="status"
            aria-live="polite"
            data-slot="loading-state"
            className={cn('flex flex-col gap-4', className)}
        >
            <p className="text-muted-foreground flex items-center gap-2 text-sm">
                <Spinner />
                {label}
            </p>

            {children}
        </div>
    );
}

/**
 * A neutral placeholder block.
 *
 * `Skeleton` already carries the system's `bg-primary/10 animate-pulse rounded-md`, so a
 * caller only supplies a size. The one thing it must not do is guess: a skeleton that
 * does not match the shape of the content that replaces it makes the page jump on arrival,
 * which is why the list screens pass a row-shaped placeholder rather than a bare box.
 */
export function SkeletonRows({ rows = 3, className }: { rows?: number; className?: string }) {
    return (
        <div className={cn('flex flex-col gap-2', className)}>
            {Array.from({ length: rows }, (_unused, index) => (
                <div
                    key={index}
                    className="bg-primary/10 animate-pulse h-12 w-full rounded-md"
                />
            ))}
        </div>
    );
}
