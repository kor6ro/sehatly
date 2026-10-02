import { Star } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The five-star row, always carrying its number.
 *
 * `web/AGENTS.md` forbids a status conveyed by colour or icon alone, and F04 §8 says a
 * star row must be accompanied by the number. The glyphs are therefore `aria-hidden`
 * and the wrapper is a `role="img"` whose `aria-label` is `"{n} dari 5"` - a screen
 * reader reads one number instead of five "star" words, and a sighted reader can count
 * the filled shapes even when the fill colour does not survive a colour-blind filter.
 *
 * The fill uses `--warning` (the token F04 §4.2 allows alongside `--primary`), and the
 * empty state uses `--muted-foreground`. The component never renders a `0,0` numeral:
 * the number it announces is the value it was given.
 */
export function BintangRating({
    nilai,
    className,
}: {
    nilai: number;
    className?: string;
}) {
    const penuh = Math.max(0, Math.min(5, Math.round(nilai)));

    return (
        <span
            role="img"
            aria-label={`${nilai} dari 5`}
            data-slot="bintang-rating"
            className={cn('inline-flex items-center gap-0.5', className)}
        >
            {[1, 2, 3, 4, 5].map((bintang) => (
                <Star
                    key={bintang}
                    aria-hidden
                    className={cn(
                        'size-4 shrink-0',
                        bintang <= penuh
                            ? 'text-warning fill-warning'
                            : 'text-muted-foreground fill-none',
                    )}
                />
            ))}
        </span>
    );
}
