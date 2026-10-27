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
 *
 * ## `numbered` is opt-in, so the twenty other call sites do not change
 *
 * F03 §5 asks the directory for numbered pages (`< 1 2 3 >`), because a patient comparing
 * results wants to jump straight to page 3 rather than press "Berikutnya" twice. Rendering
 * numbers everywhere would change every list screen in the product, so the prop defaults to
 * `false` and the directory passes `true`. The window is capped at seven entries with
 * first/last always visible, so `last_page: 40` cannot produce forty buttons.
 */
export function Pagination({
    meta,
    onPageChange,
    className,
    numbered = false,
}: {
    meta: ApiMeta | undefined;
    onPageChange: (page: number) => void;
    className?: string;
    numbered?: boolean;
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

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={meta.current_page <= 1}
                    onClick={() => {
                        onPageChange(meta.current_page - 1);
                    }}
                    className="min-h-11"
                >
                    <ChevronLeft />

                    Sebelumnya
                </Button>

                {numbered ? (
                    <div className="flex flex-wrap items-center gap-1">
                        {nomorHalaman(meta).map((nomor, index, daftar) => {
                            const sebelumnya = daftar[index - 1];
                            const adaJeda =
                                sebelumnya !== undefined && nomor - sebelumnya > 1;

                            return (
                                <span
                                    key={nomor}
                                    className="flex items-center gap-1"
                                >
                                    {adaJeda ? (
                                        <span
                                            aria-hidden
                                            className="text-muted-foreground px-1 text-sm"
                                        >
                                            …
                                        </span>
                                    ) : null}

                                    <Button
                                        type="button"
                                        variant={
                                            nomor === meta.current_page
                                                ? 'default'
                                                : 'outline'
                                        }
                                        size="sm"
                                        aria-current={
                                            nomor === meta.current_page
                                                ? 'page'
                                                : undefined
                                        }
                                        aria-label={`Halaman ${nomor}`}
                                        className="min-h-11 min-w-11 tabular-nums"
                                        onClick={() => {
                                            onPageChange(nomor);
                                        }}
                                    >
                                        {nomor}
                                    </Button>
                                </span>
                            );
                        })}
                    </div>
                ) : (
                    <span className="text-muted-foreground text-sm tabular-nums">
                        {meta.current_page} / {meta.last_page}
                    </span>
                )}

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={meta.current_page >= meta.last_page}
                    onClick={() => {
                        onPageChange(meta.current_page + 1);
                    }}
                    className="min-h-11"
                >
                    Berikutnya

                    <ChevronRight />
                </Button>
            </div>
        </nav>
    );
}

/**
 * The page numbers to render: all of them up to seven, otherwise first, last and the
 * current neighbourhood.
 *
 * Items are unique and ascending by construction, which matters because they are React
 * keys: a `Set` de-duplicates the case where `current_page` already is `1` or `last_page`.
 */
function nomorHalaman(meta: ApiMeta): number[] {
    if (meta.last_page <= 7) {
        return Array.from({ length: meta.last_page }, (_unused, index) => index + 1);
    }

    const terlihat = new Set([1, meta.last_page, meta.current_page - 1, meta.current_page, meta.current_page + 1]);

    return [...terlihat]
        .filter((nomor) => nomor >= 1 && nomor <= meta.last_page)
        .sort((a, b) => a - b);
}
