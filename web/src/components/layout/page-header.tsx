import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The heading every screen opens with: a title, an optional description that names the
 * endpoint the screen reads, and an optional action cluster.
 *
 * Naming the endpoint in the description is deliberate. This client talks to a real API
 * whose eligibility rules are enforced server-side, and a reader debugging a screen needs
 * to know which of the eight patient endpoints produced the view in front of them - and
 * whether a missing row was filtered by the server or by this UI.
 */
export function PageHeader({
    title,
    description,
    action,
    className,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between',
                className,
            )}
        >
            <div className="flex flex-col gap-1">
                <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>

                {description === undefined ? null : (
                    <p className="text-muted-foreground text-sm">{description}</p>
                )}
            </div>

            {action === undefined ? null : (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {action}
                </div>
            )}
        </div>
    );
}
