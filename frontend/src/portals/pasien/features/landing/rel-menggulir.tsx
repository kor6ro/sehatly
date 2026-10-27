import {
    useCallback,
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { cn } from '@/lib/utils';

/**
 * A one-column list that scrolls inside its own window and draws its OWN scrollbar -
 * the left-hand rail of the Zalora-shaped catalog panel.
 *
 * ## Why it draws the bar instead of letting the platform
 *
 * Because it does not work to let the platform. Chromium 153 - measured in isolation, on
 * a throwaway page with nothing else going on - reserves `offsetWidth - clientWidth === 0`
 * for an overflowed box whether or not `::-webkit-scrollbar { width: 8px }` is present:
 * the scrollbar is an OVERLAY one, it paints only while it is being dragged, and sizing
 * the pseudo-element buys no layout space. `scrollbar-width: thin` is no better - it
 * makes Chromium take over the axis and IGNORE the pseudo-elements entirely.
 *
 * That matters more here than anywhere else on the page, because the rail is the one
 * component whose entire claim is "there is more below": sixteen specialties in a window
 * that fits about ten. A panel whose only hint of that is a scrollbar that may never
 * appear reads as a list that simply ENDS. So `.gulir-sendiri` switches the native bar
 * off on both spellings, and this component measures the box and lays a real, draggable
 * element over its right edge - identical on every machine, which is the whole point.
 *
 * ## Why the thumb is measured in pixels and not percent
 *
 * Because it has to be dragged: a percentage thumb and a pointer move by different
 * amounts, and a scrollbar you can grab but that fights you is worse than none. `tinggi`
 * is the window's share of the content, `atas` is the scroll's, both multiplied by the
 * window's own height - at the bottom of the list the two sum to the window exactly, so
 * the thumb lands on the end of its track.
 *
 * The `<ul>` only exists when the caller has data to put in it, so the subscription runs
 * from mount rather than from a query flag: whoever renders this renders it with rows.
 */
export function RelMenggulir({
    slot,
    className,
    label,
    children,
}: {
    /** The `data-slot` the assertions scope themselves to; the thumb gets `-gulir`. */
    slot: string;
    className?: string;
    /** Names the scrollable region, which has no visible title of its own. */
    label: string;
    children: ReactNode;
}) {
    const relRef = useRef<HTMLUListElement>(null);
    const [gulir, setGulir] = useState({ muat: false, tinggi: 0, atas: 0 });
    const geser = useRef<{ y: number; scroll: number; rasio: number } | null>(null);

    const ukurGulir = useCallback(() => {
        const el = relRef.current;
        if (!el) return;

        const kelebihan = el.scrollHeight - el.clientHeight;
        if (kelebihan <= 1) {
            // only write when the answer changed, or every scroll re-renders the panel
            setGulir((kini) => (kini.muat ? { muat: false, tinggi: 0, atas: 0 } : kini));
            return;
        }

        const tinggi = Math.max(28, (el.clientHeight / el.scrollHeight) * el.clientHeight);
        setGulir({
            muat: true,
            tinggi,
            atas: (el.scrollTop / el.scrollHeight) * el.clientHeight,
        });
    }, []);

    useEffect(() => {
        const el = relRef.current;
        if (!el) return;

        ukurGulir();
        el.addEventListener('scroll', ukurGulir, { passive: true });
        window.addEventListener('resize', ukurGulir);

        const pengamat = new ResizeObserver(ukurGulir);
        pengamat.observe(el);

        return () => {
            el.removeEventListener('scroll', ukurGulir);
            window.removeEventListener('resize', ukurGulir);
            pengamat.disconnect();
        };
    }, [ukurGulir]);

    return (
        <div className="relative">
            <ul
                ref={relRef}
                data-slot={slot}
                aria-label={label}
                className={cn('gulir-sendiri overflow-y-auto', className)}
            >
                {children}
            </ul>

            {gulir.muat ? (
                <span
                    data-slot={`${slot}-gulir`}
                    aria-hidden="true"
                    className="bg-border/70 hover:bg-border absolute top-0 right-0 w-1.5 cursor-grab touch-none rounded-full active:cursor-grabbing"
                    style={{
                        height: `${gulir.tinggi}px`,
                        transform: `translateY(${gulir.atas}px)`,
                    }}
                    onPointerDown={(event) => {
                        const el = relRef.current;
                        if (!el) return;

                        event.preventDefault();
                        event.currentTarget.setPointerCapture(event.pointerId);
                        geser.current = {
                            y: event.clientY,
                            scroll: el.scrollTop,
                            rasio: el.scrollHeight / el.clientHeight,
                        };
                    }}
                    onPointerMove={(event) => {
                        const el = relRef.current;
                        const g = geser.current;
                        if (!el || !g) return;

                        el.scrollTop = g.scroll + (event.clientY - g.y) * g.rasio;
                    }}
                    onPointerUp={() => {
                        geser.current = null;
                    }}
                    onPointerCancel={() => {
                        geser.current = null;
                    }}
                />
            ) : null}
        </div>
    );
}
