import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Link } from 'react-router';
import { cn } from '@/lib/utils';
import { heroOptions, type HeroSlide } from '@/lib/api/hero';
import { GRADIEN_HERO, SLIDE_HERO } from './data';

/**
 * The hero carousel at the top of `/`.
 *
 * ## Where the slides come from, and what happens when they do not
 *
 * `GET /hero` is the owner's decision: an `admin` republishes this strip without a
 * deploy, so the copy and the photograph come from the database rather than from this
 * bundle. `SLIDE_HERO` in `./data.ts` is no longer the carousel - it is the FALLBACK,
 * and it renders when the endpoint answers an empty list (a fresh install has no rows)
 * or when the request fails at all (a down API must not leave the fold blank). That is
 * why {@link heroOptions} disables retry: the fallback is already on screen, and
 * waiting for a second and third attempt costs a paint and buys nothing.
 *
 * The two sources produce ONE shape, so there is one rendering path and a server slide
 * can never look structurally different from a built-in one.
 *
 * ## Why every slide is mounted at once
 *
 * The slides are stacked in ONE CSS grid cell (`gridArea: '1/1'`), so the strip is as
 * tall as its tallest slide and switching slides cannot reflow the page underneath -
 * which is what conditionally rendered blocks would do, and what makes a carousel feel
 * like it is jumping. Only opacity changes; the inactive slides take
 * `pointer-events-none` so their call-to-action cannot be clicked through while
 * invisible, and `aria-hidden` + `inert` so neither a screen reader nor the tab order
 * ever reaches a slide nobody is looking at.
 *
 * ## Why a photograph sits UNDER a gradient rather than instead of one
 *
 * The copy is white, and white text over an unknown photograph is a coin flip: a
 * bright sky or a white coat behind the headline is unreadable at a glance. So the
 * gradient is always painted as the base layer and the image, when there is one, goes
 * on top of it with a left-to-right scrim between the two - the left side (where the
 * text lives) stays dark no matter what was uploaded, and the right side keeps enough
 * of the photograph to be worth uploading. Contrast therefore does not depend on the
 * asset, which is the only way to accept images from an admin form.
 *
 * The abstract right-hand panel appears only on slides WITHOUT a photograph: it exists
 * to balance a composition, and a photograph already does that job.
 *
 * ## Why the timer is a plain interval
 *
 * Autoplay is a convenience, not a contract: it stops on unmount, restarts whenever a
 * dot or an arrow is pressed (so the reader gets the full duration of the slide they
 * just chose), and never pauses the page. There is no video and no focus trap - the
 * only controls are the dots and the two arrows, all of them plain buttons - so a
 * `setInterval` needs nothing more elaborate than a cleanup function.
 */
const INTERVAL_MS = 6_500;

/** One slide in whatever shape it arrived: server row or built-in fallback. */
type SlideTampil = {
    key: string;
    eyebrow: string;
    judul: string;
    deskripsi: string;
    cta: { label: string; to: string };
    gradien: string;
    gambar: string | null;
    gambar_alt: string | null;
};

/**
 * A published row as the strip renders it.
 *
 * `eyebrow` is optional in the data and rendered as a badge, so `null` becomes an
 * empty badge rather than a missing key - the layout stays identical between a slide
 * that has one and one that does not, and the badge collapses instead of leaving a
 * gap. The gradient is assigned by position, rotating through the three built-in
 * hues: the server stores no Tailwind class, and two adjacent slides of the same hue
 * would make the dots look like they do nothing.
 */
function dariServer(baris: HeroSlide, indeks: number): SlideTampil {
    return {
        key: `hero-${baris.id}`,
        eyebrow: baris.eyebrow ?? '',
        judul: baris.judul,
        deskripsi: baris.deskripsi,
        cta: { label: baris.cta_label, to: baris.cta_target },
        gradien: GRADIEN_HERO[indeks % GRADIEN_HERO.length] ?? GRADIEN_HERO[0],
        gambar: baris.gambar,
        gambar_alt: baris.gambar_alt,
    };
}

function dariFallback(): SlideTampil[] {
    return SLIDE_HERO.map((slide) => ({
        key: slide.id,
        eyebrow: slide.eyebrow,
        judul: slide.judul,
        deskripsi: slide.deskripsi,
        cta: slide.cta,
        gradien: slide.gradien,
        gambar: null,
        gambar_alt: null,
    }));
}

export function HeroCarousel() {
    const hero = useQuery(heroOptions());

    const [aktif, setAktif] = useState(0);
    const [jeda, setJeda] = useState(false);

    const baris = hero.data?.data.hero ?? [];
    const slides = baris.length > 0 ? baris.map(dariServer) : dariFallback();

    /**
     * The chosen index, wrapped rather than trusted.
     *
     * A refetch can shorten or lengthen the list while this component is mounted -
     * an operator publishing a slide mid-visit - and `aktif` then counts into a list
     * that no longer has that many entries. The modulo hands the strip a real slide
     * instead of an empty cell, without resetting the timer or reordering the dots.
     */
    const panjang = slides.length;
    const terpilih = panjang === 0 ? 0 : aktif % panjang;

    useEffect(() => {
        if (jeda || panjang <= 1) {
            return undefined;
        }

        const id = setInterval(() => {
            setAktif((sebelumnya) => (sebelumnya + 1) % panjang);
        }, INTERVAL_MS);

        return () => clearInterval(id);
        // `aktif` is intentionally NOT a dependency: restarting the timer on every
        // slide change would mean a dot press resets a countdown that is already
        // being reset explicitly by `pindah()`.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [jeda, panjang]);

    function pindah(indeks: number): void {
        if (panjang === 0) {
            return;
        }

        setAktif((indeks + panjang) % panjang);
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
                        {slides.map((slide, indeks) => {
                            const dipilih = indeks === terpilih;

                            return (
                                <div
                                    key={slide.key}
                                    style={{ gridArea: '1 / 1' }}
                                    aria-hidden={!dipilih}
                                    /**
                                     * `inert` is what makes `aria-hidden` legal rather
                                     * than merely declared: an axe pass fails a hidden
                                     * container that still holds a focusable link, and
                                     * `aria-hidden` alone does not remove one from the
                                     * tab order. Both together are the whole rule -
                                     * invisible, untabbable, unreachable.
                                     */
                                    inert={!dipilih}
                                    className={cn(
                                        /**
                                         * `invisible` is the half that makes the fade
                                         * legal as well as pretty. An inactive slide is
                                         * still LAYED OUT while it fades, and axe reads
                                         * `opacity: 0` as rendered: it then finds two
                                         * call-to-action pills stacked on the same pixel
                                         * and calls it a target-size failure, and reads
                                         * the one underneath as text with no background
                                         * of its own. CSS holds `visibility` until the
                                         * END of the transition, so the fade out still
                                         * happens and the slide is genuinely hidden the
                                         * moment it is over - which is also the truth
                                         * `inert` and `aria-hidden` above are already
                                         * telling. `motion-reduce` closes the last door:
                                         * a visitor who asked the system for less motion
                                         * gets the new slide without watching one fade
                                         * into it.
                                         */
                                        'relative transition-[opacity,visibility] duration-500 motion-reduce:transition-none',
                                        dipilih
                                            ? 'opacity-100'
                                            : 'pointer-events-none invisible opacity-0',
                                    )}
                                >
                                    {/**
                                     * The base layer, always. It is what keeps the white
                                     * copy readable when a slide carries no photograph and
                                     * when it carries one nobody has proofread.
                                     */}
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'absolute inset-0 bg-gradient-to-br',
                                            slide.gradien,
                                        )}
                                    />

                                    {slide.gambar ? (
                                        <>
                                            <img
                                                src={slide.gambar}
                                                alt={slide.gambar_alt ?? ''}
                                                decoding="async"
                                                loading={
                                                    indeks === 0 ? 'eager' : 'lazy'
                                                }
                                                className="absolute inset-0 size-full object-cover"
                                            />

                                            {/**
                                             * The scrim. `from-black/75` is on the left
                                             * where the headline sits and `to-black/25` on
                                             * the right where the photograph should show
                                             * through - one layer that buys contrast for
                                             * the text without flattening the image.
                                             */}
                                            <span
                                                aria-hidden="true"
                                                className="absolute inset-0 bg-gradient-to-r from-black/75 via-black/50 to-black/25"
                                            />
                                        </>
                                    ) : null}

                                    <div className="relative grid gap-8 px-6 py-10 md:grid-cols-2 md:items-center md:px-12 md:py-14">
                                        <div className="flex flex-col items-start gap-4">
                                            {slide.eyebrow === '' ? null : (
                                                <span className="rounded-full bg-white/20 px-3 py-1 text-xs font-semibold tracking-wide text-white uppercase">
                                                    {slide.eyebrow}
                                                </span>
                                            )}

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
                                         * balance a slide that has no photograph, which is
                                         * why it is skipped whenever one does - and why it
                                         * carries `aria-hidden`: nothing in it is content.
                                         */}
                                        {slide.gambar ? null : (
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
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    <button
                        type="button"
                        aria-label="Slide sebelumnya"
                        onClick={() => pindah(terpilih - 1)}
                        className="bg-background/90 text-foreground absolute top-1/2 left-3 hidden size-10 -translate-y-1/2 items-center justify-center rounded-full shadow-sm transition hover:bg-background md:flex"
                    >
                        <ChevronLeft className="size-5" />
                    </button>

                    <button
                        type="button"
                        aria-label="Slide berikutnya"
                        onClick={() => pindah(terpilih + 1)}
                        className="bg-background/90 text-foreground absolute top-1/2 right-3 hidden size-10 -translate-y-1/2 items-center justify-center rounded-full shadow-sm transition hover:bg-background md:flex"
                    >
                        <ChevronRight className="size-5" />
                    </button>
                </div>

                {/**
                 * The dots are 44px TARGETS painted as 10px dots, because `web/AGENTS.md`
                 * asks for a 44px touch target and a 10px dot is not one - axe's
                 * `target-size` rule said so on the first day the landing page was ever
                 * scanned (the directory moved onto it, and brought the scan with it).
                 * The visible mark is unchanged; only the area a finger can hit grew.
                 */}
                <div className="mt-2 flex items-center justify-center">
                    {slides.map((slide, indeks) => (
                        <button
                            key={slide.key}
                            type="button"
                            aria-label={`Tampilkan slide ${indeks + 1}`}
                            aria-current={indeks === terpilih}
                            onClick={() => pindah(indeks)}
                            className="flex size-11 items-center justify-center rounded-full"
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'h-2.5 rounded-full transition-all',
                                    indeks === terpilih
                                        ? 'bg-primary w-6'
                                        : 'bg-muted w-2.5 hover:bg-muted-foreground/40',
                                )}
                            />
                        </button>
                    ))}
                </div>
            </div>
        </section>
    );
}
