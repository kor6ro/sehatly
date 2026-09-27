import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { ApiMeta } from '@/lib/http';
import { describeRange } from '@/lib/api/pagination';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * Page navigation built from the project's `meta` block.
 *
 * ## Why it reads `current_page`/`last_page` and not the row count
 *
 * `ApiResponse::pageMeta()` is the only place these numbers are spelled, so a client that
 * derived "is there a next page" from `rows.length < per_page` would be wrong on the last
 * page of a full result and wrong again whenever the 100 cap clamps `per_page`. The block
 * already accounts for the cap, because `per_page` there is the size that was *actually
 * applied*.
 *
 * The range line comes from `describeRange`, which returns `null` for an empty page so the
 * control can render nothing rather than "0-0 dari 0" next to an empty state that already
 * says there is nothing there.
 */
export function Pagination({
    meta,
    onPageChange,
    className,
}: {
    meta: ApiMeta | undefined;
    onPageChange: (page: number) => void;
    className?: string;
}) {
    if (meta === undefined || meta.last_page <= 1) {
        return null;
    }

    const range = describeRange(meta);

    return (
        <nav
            aria-label="Navigasi halaman"
            data-slot="pagination"
            className={cn(
                'flex flex-col items-center justify-between gap-3 sm:flex-row',
                className,
            )}
        >
            {range === null ? null : (
                <p className="text-muted-foreground text-sm">{range}</p>
            )}

            <div className="flex items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={meta.current_page <= 1}
                    onClick={() => {
                        onPageChange(meta.current_page - 1);
                    }}
                >
                    <ChevronLeft />

                    Sebelumnya
                </Button>

                <span className="text-muted-foreground text-sm tabular-nums">
                    {meta.current_page} / {meta.last_page}
                </span>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={meta.current_page >= meta.last_page}
                    onClick={() => {
                        onPageChange(meta.current_page + 1);
                    }}
                >
                    Berikutnya

                    <ChevronRight />
                </Button>
            </div>
        </nav>
    );
}
