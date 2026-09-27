import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';

/**
 * The empty state, and the reason a zero-result list is never a blank box.
 *
 * ## "Empty" is three different things and only one of them is a bug
 *
 * | situation | `meta.total` | what the user needs |
 * | --- | --- | --- |
 * | the account has no rows yet | `0` | to be told what this list is for, and how to add the first one |
| the filter matched nothing | `0` | to be told the *filter* is the reason, and to be offered a way to clear it |
 * | the page is past the last one | `> 0` | to be sent back, because the rows exist |
 *
 * All three render an empty `data` array. `isPastLastPage()` in `lib/api/pagination.ts`
 * tells the last one apart, and the caller picks the copy - because only the caller knows
 * whether a filter is active. What this component guarantees is that none of the three
 * ever renders nothing at all.
 *
 * ## The pattern is decoration, and it is decorative on purpose
 *
 * `PlaceholderPattern` is the shadcn empty-state texture, already in this project's
 * component set. It sits at low opacity behind the copy, marked `aria-hidden`, so it adds
 * no noise to the accessibility tree - the text is the message.
 */
export function EmptyState({
    title,
    description,
    action,
    className,
    compact = false,
}: {
    title: string;
    description: string;
    action?: ReactNode;
    className?: string;
    /** Drops the texture for an inline use inside a table cell or a card body. */
    compact?: boolean;
}) {
    return (
        <div
            data-slot="empty-state"
            className={cn(
                'bg-muted/30 relative flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed text-center',
                compact ? 'px-4 py-6' : 'px-6 py-12',
                className,
            )}
        >
            <PlaceholderPattern
                aria-hidden
                className="text-muted-foreground/20 absolute inset-0 size-full"
            />

            <div className="relative flex flex-col items-center gap-2">
                <p className="text-base font-semibold">{title}</p>

                <p className="text-muted-foreground max-w-prose text-sm">{description}</p>

                {action}
            </div>
        </div>
    );
}
