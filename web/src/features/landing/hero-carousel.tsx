import { useEffect, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Link } from 'react-router';
import { cn } from '@/lib/utils';
import { SLIDE_HERO } from './data';

/**
 * The hero carousel at the top of `/`.
 *
 * ## Why every slide is mounted at once
 *
 * The three slides are stacked in ONE CSS grid cell (`gridArea: '1/1'`), so the strip is
 * as tall as its tallest slide and switching slides cannot reflow the page underneath -
 * which is what three conditionally rendered blocks would do, and what makes a carousel
 * feel like it is jumping. Only opacity changes; the inactive slides take
 * `pointer-events-none` so their call-to-action cannot be clicked through while invisible,
 * and `aria-hidden` + `inert` so neither a screen reader nor the tab order ever reaches a
 * slide nobody is looking at.
 *
 * ## Why the timer is a plain interval
 *
 * Autoplay is a convenience, not a contract: it stops on unmount, restarts whenever a dot
 * or an arrow is pressed (so the reader gets the full duration of the slide they just
 * chose), and never pauses the page. There is no video and no focus trap - the only
 * controls are the dots and the two arrows, all of them plain buttons - so a
 * `setInterval` needs nothing more elaborate than a cleanup function.
 *
 * ## Why there is no image
 *
 * The reference front page sells itself with photography. Sehatly has no image assets -
 * `public/` holds the mark and nothing else - so the visual weight comes from the
 * gradient and a deliberately abstract panel on the right: shapes, not stock photos and
 * not invented doctor names.
 */
const INTERVAL_MS = 6_500;

export function HeroCarousel() {
    const [aktif, setAktif] = useState(0);
    const [jeda, setJeda] = useState(false);

    useEffect(() => {
        if (jeda) {
            return undefined;
        }

        const id = setInterval(() => {
            setAktif((sebelumnya) => (sebelumnya + 1) % SLIDE_HERO.length);
        }, INTERVAL_MS);

        return () => clearInterval(id);
    }, [jeda, aktif]);

    function pindah(indeks: number): void {
        setAktif((indeks + SLIDE_HERO.length) % SLIDE_HERO.length);
    }

    return (
        <section
            aria-label="Sorotan layanan"
            data-slot="hero-carousel"
            className="bg-background w-full"
            onMouseEnter={() => setJeda(true)}
            onMouseLeave={() => setJeda(false)}
        >
            <div className="mx-auto w-full max-w-[1280px] px-4 pt-6 pb-2 md:px-6">
                <div className="relative">
                    <div className="grid overflow-hidden rounded-3xl">
                        {SLIDE_HERO.map((slide, indeks) => {
                            const terpilih = indeks === aktif;

                            return (
                                <div
                                    key={slide.id}
                                    style={{ gridArea: '1 / 1' }}
                                    aria-hidden={!terpilih}
                                    /**
                                     * `inert` is what makes `aria-hidden` legal rather
                                     * than merely declared: an axe pass fails a hidden
                                     * container that still holds a focusable link, and
                                     * `aria-hidden` alone does not remove one from the
                                     * tab order. Both together are the whole rule -
                                     * invisible, untabbable, unreachable.
                                     */
                                    inert={!terpilih}
                                    className={cn(
                                        'bg-gradient-to-br transition-opacity duration-500',
                                        slide.gradien,
                                        terpilih
                                            ? 'opacity-100'
                                            : 'pointer-events-none opacity-0',
                                    )}
                                >
                                    <div className="grid gap-8 px-6 py-10 md:grid-cols-2 md:items-center md:px-12 md:py-14">
                                        <div className="flex flex-col items-start gap-4">
                                            <span className="rounded-full bg-white/20 px-3 py-1 text-xs font-semibold tracking-wide text-white uppercase">
                                                {slide.eyebrow}
                                            </span>

                                            <h1 className="text-3xl leading-tight font-bold text-white md:text-5xl">
                                                {slide.judul}
                                            </h1>

                                            <p className="max-w-xl text-sm leading-relaxed text-white/90 md:text-base">
                                                {slide.deskripsi}
                                            </p>

                                            <Link
                                                to={slide.cta.to}
                                                className="mt-2 inline-flex items-center gap-2 rounded-full bg-white px-5 py-3 text-sm font-semibold text-foreground shadow-sm transition hover:bg-white/90"
                                            >
                                                {slide.cta.label}
                                                <ChevronRight className="size-4" />
                                            </Link>
                                        </div>

                                        {/**
                                         * The abstract right-hand panel: a soft card with
                                         * bars where a screenshot would be. It exists to
                                         * balance the composition, which is why it carries
                                         * `aria-hidden` - nothing in it is content.
                                         */}
                                        <div
                                            aria-hidden="true"
                                            className="hidden md:block md:justify-self-end"
                                        >
                                            <div className="w-72 space-y-3 rounded-3xl bg-white/15 p-6 backdrop-blur">
                                                <div className="flex items-center gap-3">
                                                    <span className="size-10 rounded-full bg-white/70" />

                                                    <span className="flex-1 space-y-1.5">
                                                        <span className="block h-2.5 w-2/3 rounded-full bg-white/70" />
                                                        <span className="block h-2 w-1/3 rounded-full bg-white/40" />
                                                    </span>
                                                </div>

                                                <span className="block h-2.5 w-full rounded-full bg-white/40" />
                                                <span className="block h-2.5 w-5/6 rounded-full bg-white/40" />
                                                <span className="block h-2.5 w-3/5 rounded-full bg-white/40" />

                                                <span className="ml-auto block h-9 w-32 rounded-full bg-white/80" />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    <button
                        type="button"
                        aria-label="Slide sebelumnya"
                        onClick={() => pindah(aktif - 1)}
                        className="bg-background/90 text-foreground absolute top-1/2 left-3 hidden size-10 -translate-y-1/2 items-center justify-center rounded-full shadow-sm transition hover:bg-background md:flex"
                    >
                        <ChevronLeft className="size-5" />
                    </button>

                    <button
                        type="button"
                        aria-label="Slide berikutnya"
                        onClick={() => pindah(aktif + 1)}
                        className="bg-background/90 text-foreground absolute top-1/2 right-3 hidden size-10 -translate-y-1/2 items-center justify-center rounded-full shadow-sm transition hover:bg-background md:flex"
                    >
                        <ChevronRight className="size-5" />
                    </button>
                </div>

                <div className="mt-4 flex items-center justify-center gap-2">
                    {SLIDE_HERO.map((slide, indeks) => (
                        <button
                            key={slide.id}
                            type="button"
                            aria-label={`Tampilkan slide ${indeks + 1}`}
                            aria-current={indeks === aktif}
                            onClick={() => pindah(indeks)}
                            className={cn(
                                'size-2.5 rounded-full transition-all',
                                indeks === aktif
                                    ? 'bg-primary w-6'
                                    : 'bg-muted hover:bg-muted-foreground/40',
                            )}
                        />
                    ))}
                </div>
            </div>
        </section>
    );
}
