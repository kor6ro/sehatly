import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { Search, Tag } from 'lucide-react';
import { dokterOptions } from '@/lib/api/dokter';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/shared/components/states/error-state';
import { serahkanKueri } from '@/portals/pasien/features/dokter/direktori';

/**
 * The doctor search on BOTH doors that now have it - the patient dashboard's field and the
 * landing bar's - as a field that opens a suggestion panel when focused, in the shape the
 * reference (Zalora) uses: the term itself, then the searches this reader made before,
 * then a short list worth typing.
 *
 * ## Two components, one panel
 *
 * Each door owns its own FIELD - the dashboard's pill here, `PencarianBar`'s in
 * `landing-header.tsx` - so the file holds both halves of that split:
 * {@link PanelPencarian} is the dashboard's wrapper, pill and open/close discipline, and
 * {@link PanelSaranPencarian} is the PANEL ITSELF (term row, chips, six rows), rendered
 * under whichever field opened it. Sharing the panel rather than the field is deliberate:
 * a reader who asks from the bar must meet the same panel, the same labels and - because
 * both write it through {@link catatRiwayatPencarian} - the same history, or the two
 * doors would drift on the first change and "Pencarian sebelumnya" would depend on which
 * door asked.
 *
 * ## What is borrowed, and what is not
 *
 * The SHAPE is the reference's, because it was asked for: a panel under the field rather
 * than a page after it, past searches as dismissible chips with a "clear all", and a short
 * list of things worth starting from.
 *
 * The COLOURS are not the reference's. `AGENTS.md` makes `app.css` the only source of
 * colour and forbids a second accent, so the panel is built from `popover`/`muted` and
 * never from a palette of its own. That still lands on black and white, which is what was
 * asked for: `--popover` is `oklch(1 0 0)` in light and `oklch(0.246 …)` in dark, and
 * `--popover-foreground` is its exact inverse, so the panel is ink on paper and the dark
 * theme simply turns the paper dark rather than leaving a white rectangle glowing on it.
 * No `border` either - `AGENTS.md` bans border and shadow together, and the landing
 * header's two panels (`landing-header.tsx`, `rounded-…-2xl … shadow-xl`) already settle
 * the elevation without one.
 *
 * ## What is NOT borrowed is the data
 *
 * The reference's "Pencarian populer" is a popularity ranking of queries, and this server
 * has no such thing - `GET /dokter` counts nothing about what people typed. Inventing that
 * list would be a screen that lies about what it knows, so the second section is labelled
 * for what it actually is: the doctors the server's own default order puts first, which is
 * `rating_rata_rata DESC, jumlah_konsultasi DESC` (`DokterDirectoryService`, the order the
 * client must NOT send `sort` to receive - see `fetchDokter`). Most consulted and best
 * rated is a real ranking of real columns, and it is a useful answer to "who could I even
 * search for".
 *
 * ## It searches NAMES, and that constrains what may be suggested
 *
 * `?search=` matches `users.nama_lengkap` and nothing else
 * (`DokterDirectoryService::terapkanSearch()`), so a suggestion that is not a doctor's
 * name - a specialisation, a condition, a venue - would open a directory answering zero.
 * Every row below is therefore a name that the same request would match. This is also why
 * the specialisation shortcuts that used to live in the header's panel are not
 * resurrected here: they resolve to `?spesialisasi=`, a different parameter, and offering
 * them beside a field that cannot use them is the confusion the October 2026 removal
 * already settled (F03 §12 butir 8(h)).
 *
 * ## The query still never reaches the address
 *
 * F03 §9 keeps a free-text doctor query out of the URL, because a term may be a condition
 * and the address is what gets pasted into a chat. So submitting here does exactly what
 * {@link serahkanKueri} was written for: park the term in `sessionStorage`, ring the
 * event, and navigate to a bare `/` - which `LandingPage` then answers with
 * `DirektoriDokter` because the marker is present. The dashboard and the landing bar are
 * two doors into the same handoff, not two implementations of it.
 *
 * ## Past searches live in `localStorage` - a dated deviation
 *
 * **Decided 2026-10-15 by the product owner, recorded here and in F03 §12 rather than
 * applied quietly (`AGENTS.md`, "jangan menyimpang diam-diam").**
 *
 * F03 §9 sets `sessionStorage` for the query and gives the reason plainly: a search term
 * may be a condition ("kanker"). A history list has the same contents, and the request for
 * this panel was the reference's behaviour, where "Pencarian sebelumnya" outlives the tab.
 * So this is `localStorage`, deliberately, with `Hapus Semua` as the control UU PDP asks
 * for - and with a ceiling ({@link BATAS_RIWAYAT}) so the record is a menu rather than a
 * transcript.
 *
 * What did NOT change: the CURRENT query still goes to `sessionStorage` only, never to the
 * address, and nothing here writes to `document.title`, a toast, or a log. The deviation
 * is about how long a term the reader already searched for may be remembered on their own
 * device, and it can be undone by the reader themselves at any time.
 *
 * ## Buttons, not a combobox
 *
 * Every suggestion is a real `<button>`, so Tab reaches them in reading order and Enter
 * activates the focused one, with no `aria-activedescendant` bookkeeping and no roving
 * focus to keep in sync. A combobox would buy arrow-key traversal; it would also add the
 * one thing this panel does not need - a highlighted row competing with the caret. Escape
 * closes, a pointer-down outside closes, and the panel is not a modal, so nothing is
 * trapped.
 *
 * ## No typeahead, and no offline guard on submit
 *
 * There is no request per keystroke: F03 AC-1 fixes this product's search at "tepat 1"
 * `GET /dokter` per submit, and a panel that fired one per character would break the count
 * that AC measures. The suggestions are fetched once when the panel opens and are the same
 * for every term.
 *
 * The submit is likewise NOT gated on `navigator.onLine`, unlike a booking form. It writes
 * to `sessionStorage` and navigates - neither uses the network - so a disabled button would
 * claim a dependency it does not have. The two places the network IS needed own their own
 * failure: this panel's list renders `ErrorState`, and the directory that receives the term
 * renders `ErrorState` with the query preserved (F03 AC-8).
 *
 * The landing bar keeps its OWN `navigator.onLine` gate on submit. That gate predates this
 * panel and is argued in `PencarianBar`'s own docblock, so it stays where it is: the two
 * doors share the panel and the history, but the gate on the bar is the bar's, not the
 * handoff's.
 */

/**
 * How many past searches the chips hold.
 *
 * Eight is what fits two wrapped rows at the panel's width before the block starts
 * scrolling, and it is a ceiling rather than a page size: a reader who has searched more
 * than eight things has a history, not a menu, and the ninth belongs in the directory
 * rather than in a suggestion strip. It is also the minimisation UU PDP asks for - the
 * smallest record that still does the job.
 */
const BATAS_RIWAYAT = 8;

/**
 * How many doctors the second section offers.
 *
 * Six keeps the panel under a screenful on a phone, where it sits over the dashboard
 * rather than in it - a suggestion list longer than the thing it is suggesting for has
 * stopped being a shortcut. It is also the row count the loading skeleton draws, so the
 * panel does not change height when the answer lands.
 */
const BATAS_SARAN = 6;

/**
 * The past searches, in `localStorage`.
 *
 * A DIFFERENT key from the directory's `sehatly.dokter.kueri`, and for a different purpose:
 * that one is the reload-persistence of the term currently on screen and dies with the tab,
 * while this one is the reader's own history. Conflating them would make "what was I
 * looking at" indistinguishable from "what have I looked for", and the directory would
 * start restoring terms the reader never asked it to keep.
 */
const SIMPANAN_RIWAYAT = 'sehatly.dokter.riwayat';

/**
 * The stored history, newest first.
 *
 * A browser that refuses storage - private mode, cookies disabled - reads as an empty
 * history and loses nothing else: the field still searches, it simply cannot remember.
 * The same `catch` covers a value another origin's script may have written as something
 * that is not a list of strings.
 */
function bacaRiwayat(): string[] {
    try {
        const mentah = window.localStorage.getItem(SIMPANAN_RIWAYAT);

        if (mentah === null) {
            return [];
        }

        const hasil = JSON.parse(mentah) as unknown;

        return Array.isArray(hasil)
            ? hasil.filter((item): item is string => typeof item === 'string')
            : [];
    } catch {
        return [];
    }
}

function tulisRiwayat(riwayat: readonly string[]): void {
    try {
        window.localStorage.setItem(SIMPANAN_RIWAYAT, JSON.stringify(riwayat));
    } catch {
        // Refused storage costs only the memory. See {@link bacaRiwayat}.
    }
}

/**
 * Put a term at the front of the history, and write it.
 *
 * Matching is case-insensitive and whitespace-collapsed so "dr. Rina" and "dr.  Rina" are
 * one entry rather than two chips that look identical; the FIRST spelling is the one kept,
 * because it is the one the reader typed. A term is stored ONLY here, on a successful
 * submit - never on keystroke - so an abandoned typo never becomes a record.
 *
 * Called by whichever door submits ({@link PanelPencarian} for the dashboard,
 * `PencarianBar` for the bar) and no list is handed back: {@link PanelSaranPencarian}
 * keeps its own state, reads it fresh on every opening, and is unmounted the moment the
 * panel closes - so the write IS the update.
 */
export function catatRiwayatPencarian(kueri: string): void {
    const bersih = kueri.trim().replace(/\s+/g, ' ');

    if (bersih === '') {
        return;
    }

    const kunci = bersih.toLowerCase();
    const lama = bacaRiwayat().filter(
        (item) => item.trim().replace(/\s+/g, ' ').toLowerCase() !== kunci,
    );

    tulisRiwayat([bersih, ...lama].slice(0, BATAS_RIWAYAT));
}

export function PanelPencarian({ className }: { className?: string }) {
    const navigate = useNavigate();
    const wadah = useRef<HTMLDivElement>(null);

    const [kueri, setKueri] = useState('');
    const [terbuka, setTerbuka] = useState(false);

    /**
     * Close on a pointer-down anywhere that is not this panel.
     *
     * `pointerdown` rather than `click`: the second fires after the element under the
     * pointer has already changed, and a suggestion chosen during that window would
     * navigate and then be dismissed by a listener that had already been removed.
     */
    useEffect(() => {
        if (!terbuka) {
            return;
        }

        const luar = (event: PointerEvent): void => {
            if (wadah.current?.contains(event.target as Node) !== true) {
                setTerbuka(false);
            }
        };

        document.addEventListener('pointerdown', luar);

        return () => {
            document.removeEventListener('pointerdown', luar);
        };
    }, [terbuka]);

    /**
     * Submit one term - the form's own, or a suggestion's.
     *
     * Recording happens only here, so the history holds terms that were actually run, and
     * the panel closes before the route change so a later visit back to the dashboard
     * starts folded rather than open over a page nobody is reading yet.
     */
    function cari(term: string): void {
        const bersih = term.trim();

        if (bersih === '') {
            return;
        }

        catatRiwayatPencarian(bersih);
        setTerbuka(false);

        // The term is parked, never put in the address (F03 §9) - `LandingPage` finds the
        // marker and renders the directory instead of the marketing sections.
        serahkanKueri(bersih);
        navigate('/');
    }

    const persis = kueri.trim();

    return (
        <div
            ref={wadah}
            /**
             * `z-40`-while-open is the stacking context the shared panel needs. Its
             * card rises AROUND this field (`-top-3`), so the pill has to paint on
             * top of that card's own white - which it does with `relative z-10`
             * against the panel's `z-0`. Inside this wrapper those two rank against
             * each other and nothing else; outside it they would be sorted at page
             * level, where the dashboard's `z-40` used to live on the panel itself.
             */
            className={cn('relative', terbuka ? 'z-40' : undefined, className)}
            onKeyDown={(event) => {
                if (event.key === 'Escape' && terbuka) {
                    setTerbuka(false);
                }
            }}
        >
            <form
                role="search"
                data-slot="dashboard-cari"
                onSubmit={(event) => {
                    event.preventDefault();

                    /**
                     * An empty submit OPENS rather than doing nothing.
                     *
                     * This is the one way a reader can press something in this field and be
                     * told nothing had happened: `cari('')` returns early on a blank term, so
                     * the magnifier - which looks like the primary action of the whole
                     * control - used to swallow the press silently whenever the field was
                     * empty. The reference opens its panel on that same press, and it is the
                     * only sensible answer anyway: with nothing typed, the useful next step
                     * is the list of things worth searching for, not a directory of
                     * everything.
                     */
                    if (persis === '') {
                        setTerbuka(true);
                        return;
                    }

                    cari(persis);
                }}
                /**
                 * Any press in the field focuses it, not just a press on the caret.
                 *
                 * The form is 48 px tall around a text input, so a click on the padding, on
                 * the leading space, or on the magnifier itself would otherwise leave the
                 * field unfocused - and therefore leave the panel unopened, with no feedback
                 * at all. Moving the caret to where the reader pressed is also what they
                 * asked for; `preventDefault` on the icon press is dropped because the
                 * submit that follows is the behaviour we now want.
                 */
                onPointerDown={(event) => {
                    if (event.target === event.currentTarget) {
                        event.preventDefault();
                        event.currentTarget.querySelector('input')?.focus();
                    }
                }}
                className="border-input bg-background focus-within:border-foreground/40 relative z-10 flex h-12 min-w-0 items-center gap-1 rounded-full border pl-4 pr-1 transition-colors"
            >
                <input
                    type="search"
                    value={kueri}
                    onChange={(event) => setKueri(event.target.value)}
                    onFocus={() => {
                        setTerbuka(true);
                    }}
                    aria-label="Cari dokter"
                    placeholder="Cari nama dokter (contoh: dr. Rina)"
                    className="placeholder:text-muted-foreground min-w-0 flex-1 bg-transparent text-sm outline-none"
                />

                {/**
                 * A real submit, so Enter in the field does what the circle does. 44 px
                 * because F03 §8 sets that as the floor for every target on this flow -
                 * the header's `size-8` predates the rule and is left alone.
                 */}
                <button
                    type="submit"
                    aria-label="Cari"
                    className="bg-foreground text-background hover:opacity-85 flex size-11 shrink-0 items-center justify-center rounded-full transition-opacity"
                >
                    <Search aria-hidden="true" className="size-4" />
                </button>
            </form>

            {terbuka ? (
                <PanelSaranPencarian
                    kueri={persis}
                    onCari={cari}
                    onTutup={() => setTerbuka(false)}
                />
            ) : null}
        </div>
    );
}

/**
 * The panel itself: the term, the past searches, and the catalogue's first page.
 *
 * Rendered by whichever door opened it, under that door's OWN field: the door owns the
 * term, the open state and where a search goes, while everything that survives an opening
 * lives here - the history read (fresh on every mount, and a closed panel is an unmounted
 * one) and the suggestions fetch.
 *
 * ## Its shape when open
 *
 * The card does not hang beneath the field - it rises AROUND it. The shell starts a
 * `-top-3` above the pill and the same inset past each of its sides, so the white begins
 * over the field rather than under it, and the pill keeps painting on top of that band
 * (`relative z-10` on both doors, against this root's `z-0`). What the reader sees is the
 * reference's active state: ONE card holding the input, not a dropdown attached to a
 * pill.
 *
 * Two numbers make the band work for both doors at once. `-top-3` is the white above the
 * field; `pt-[68px]` is that 12, plus the taller of the two pills (the dashboard's `h-12`
 * at 48), plus its 8 - the gap this panel has always had under the dashboard field. The
 * header's shorter `h-10` pill reads the same padding as a 16 px gap, which is the
 * reference's airier one.
 *
 * Only the BODY scrolls. A single scroll box would have its padding at the top scroll
 * away with the rows, and the rows would then slide up under the field - the band is the
 * one part of this card that must stay clean while the list moves, so the shell clips and
 * the inner box is what has a max-height of its own.
 */
export function PanelSaranPencarian({
    kueri,
    onCari,
    onTutup,
}: {
    /** The field's term, already trimmed - its own row when it is not empty. */
    kueri: string;
    /** Run this term: the door that owns the field decides where a search goes. */
    onCari: (kueri: string) => void;
    /** Close the panel - what "Lihat dokter terdekat" does before it navigates. */
    onTutup: () => void;
}) {
    const [riwayat, setRiwayat] = useState<string[]>(() => bacaRiwayat());

    /**
     * The suggestions, fetched while the panel is MOUNTED - and a door mounts it only
     * while it is open, so this costs nothing before the first focus and then sits in the
     * cache for every later opening.
     *
     * No `sort` is sent. `fetchDokter` omits `relevan` from the query string because it IS
     * the server's default, so the request asks for nothing special and the server answers
     * with its own order - which is the ranking this section is labelled for.
     */
    const saran = useQuery({
        ...dokterOptions({ page: 1, per_page: BATAS_SARAN }),
    });
    const rows = saran.data?.data.dokter ?? [];

    const persis = kueri.trim();

    return (
        <div
            data-slot="dashboard-cari-panel"
            className="bg-popover text-popover-foreground absolute -left-3 -right-3 -top-3 z-0 max-h-[70vh] overflow-hidden rounded-3xl pb-2 pt-[68px] shadow-xl"
        >
            <div className="max-h-[calc(70vh-76px)] overflow-y-auto">
                {/**
                 * The term itself, as its own row - the reference's shape, and a real
                 * answer to the commonest case: the reader typed the whole name and
                 * wants the results, not a list of things that are not it.
                 */}
                {persis !== '' ? (
                    <button
                        type="button"
                        data-slot="dashboard-cari-kueri"
                        onClick={() => {
                            onCari(persis);
                        }}
                        className="hover:bg-accent flex min-h-11 w-full items-center gap-3 px-7 py-3 text-left text-sm transition-colors"
                    >
                        {/**
                         * A tag, not another magnifier: this row IS the reader's own
                         * term, and the reference marks it as such. Three identical
                         * magnifiers in one panel would say nothing three times.
                         */}
                        <Tag aria-hidden className="text-muted-foreground size-4 shrink-0" />

                        <span className="min-w-0 flex-1 truncate">{persis}</span>
                    </button>
                ) : null}

                {/**
                 * The past searches. A grey BOX rather than a list, because these are
                 * chips to pick from rather than rows to read down - the reference's
                 * arrangement, and the right one: a term is recognised by shape at a
                 * glance, so they wrap. The `mx-3` is the card's own band, so the box
                 * starts at the pill's edges - wider than the 28 px content rail the
                 * rows below sit on, which is the reference's proportion.
                 */}
                {riwayat.length > 0 ? (
                    <div data-slot="dashboard-cari-riwayat" className="bg-muted mx-3 mt-1 rounded-xl p-3">
                        <div className="flex items-center justify-between gap-3">
                            <p className="text-foreground text-base font-semibold">
                                Pencarian sebelumnya:
                            </p>

                            {/**
                             * `min-h-11` with a negative margin rather than padding:
                             * the hit area grows to the required 44 px while the words
                             * stay where the layout put them.
                             */}
                            <button
                                type="button"
                                onClick={() => {
                                    setRiwayat([]);
                                    tulisRiwayat([]);
                                }}
                                className="text-muted-foreground hover:text-foreground -mx-3 min-h-11 px-3 text-sm underline-offset-4 transition-colors hover:underline"
                            >
                                Hapus Semua
                            </button>
                        </div>

                        <ul className="mt-1 flex flex-wrap gap-2">
                            {riwayat.map((item) => (
                                <li key={item}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            onCari(item);
                                        }}
                                        /**
                                         * A rounded RECTANGLE, not a pill.
                                         *
                                         * The reference draws its past searches as
                                         * bordered rectangles, and the difference is
                                         * not decoration: a pill is the app's tag
                                         * shape, used for a status that is either
                                         * true or false, while a rectangle reads as a
                                         * thing you can press - which is what a chip
                                         * here is. `min-h-11` still governs the touch
                                         * target; the horizontal padding carries the
                                         * rest of the shape.
                                         */
                                        className="border-input bg-background hover:bg-accent max-w-full min-h-11 truncate rounded-lg border px-4 text-sm transition-colors"
                                    >
                                        {item}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {/**
                 * The catalogue's own first page, under the name it is actually ranked
                 * by, and all three states F03 §6 requires of a list.
                 *
                 * The error branch matters most: without it a failed request would fall
                 * through to `rows.length === 0` and print "Belum ada dokter
                 * terverifikasi" - a confident answer about the catalogue when the truth
                 * was that we could not reach it.
                 */}
                <div data-slot="dashboard-cari-saran" className="px-7 py-3">
                    <p className="text-foreground text-base font-semibold">
                        Dokter paling banyak dikonsultasikan
                    </p>

                    {saran.isPending ? (
                        /**
                         * An inline skeleton, in this panel's own monochrome.
                         *
                         * `SkeletonRows` is the shared primitive and its bars are
                         * `bg-primary/10` - the one blue thing that would appear in a
                         * panel deliberately built without `primary`. Its `className`
                         * governs the wrapper, not the bars, so the tint cannot be
                         * passed away; the bars are redrawn here instead. The shape is
                         * the shared one, so it still reads as the app's loading
                         * affordance rather than as a new invention, and it is still a
                         * skeleton rather than a spinner, which is what AGENTS.md asks
                         * for. Six of them, because six is the row count that will
                         * replace them - the panel does not change height when the
                         * answer lands.
                         */
                        <div
                            data-slot="dashboard-cari-skeleton"
                            aria-hidden="true"
                            className="-mx-2 mt-3 flex flex-col gap-2"
                        >
                            {Array.from(
                                { length: BATAS_SARAN },
                                (_unused, index) => (
                                    <div
                                        key={index}
                                        className="bg-muted h-12 w-full animate-pulse rounded-md"
                                    />
                                ),
                            )}
                        </div>
                    ) : saran.isError ? (
                        <ErrorState
                            error={saran.error}
                            title="Gagal memuat saran."
                            className="mt-3"
                            onRetry={() => {
                                void saran.refetch();
                            }}
                        />
                    ) : rows.length === 0 ? (
                        <div className="mt-3">
                            <p className="text-muted-foreground text-sm">
                                Belum ada dokter yang memenuhi syarat untuk tampil.
                            </p>

                            <Button
                                asChild
                                variant="outline"
                                className="mt-3 min-h-11"
                            >
                                <Link
                                    to="/?semua=1"
                                    onClick={() => {
                                        onTutup();
                                    }}
                                >
                                    Lihat dokter terdekat
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <ul className="-mx-2 mt-2 flex flex-col">
                            {rows.map((dokter) => (
                                <li key={dokter.id}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            onCari(dokter.nama_lengkap);
                                        }}
                                        /**
                                         * `px-2` is this hover card's own padding, and
                                         * the `ul`'s `-mx-2` carries it back out: the
                                         * magnifier then sits ON the 28 px rail under
                                         * the heading rather than eight pixels to the
                                         * right of it.
                                         */
                                        className="hover:bg-accent flex min-h-11 w-full items-center gap-3 rounded-lg px-2 py-2.5 text-left transition-colors"
                                    >
                                        <Search
                                            aria-hidden
                                            className="text-muted-foreground size-4 shrink-0"
                                        />

                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm">
                                                {dokter.nama_lengkap}
                                            </span>

                                            {/**
                                             * The secondary line is a specialisation or
                                             * a doctor type - neither is a medical fact
                                             * about anybody, and `spesialisasi` is a
                                             * `GROUP_CONCAT` string that may be `null`
                                             * (`DokterRingkas`), so `tipe` stands in
                                             * rather than an empty row.
                                             */}
                                            <span className="text-muted-foreground block truncate text-xs">
                                                {dokter.spesialisasi ?? dokter.tipe}
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}
