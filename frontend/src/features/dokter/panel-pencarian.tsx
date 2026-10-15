import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { Search } from 'lucide-react';
import { dokterOptions } from '@/lib/api/dokter';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/states/error-state';
import { SkeletonRows } from '@/components/states/loading-state';
import { serahkanKueri } from '@/features/dokter/direktori';

/**
 * The dashboard's doctor search: a field that opens a suggestion panel when focused, in
 * the shape the reference (Zalora) uses - the term itself, then the searches this reader
 * made before, then a short list worth typing.
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
 * claim a dependency it does not have, and `PencarianBar` in the landing header has no
 * guard for the same reason. The two places the network IS needed own their own failure:
 * this panel's list renders `ErrorState`, and the directory that receives the term renders
 * `ErrorState` with the query preserved (F03 AC-8).
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
 */
function catatRiwayat(kueri: string): string[] {
    const bersih = kueri.trim().replace(/\s+/g, ' ');

    if (bersih === '') {
        return bacaRiwayat();
    }

    const kunci = bersih.toLowerCase();
    const lama = bacaRiwayat().filter(
        (item) => item.trim().replace(/\s+/g, ' ').toLowerCase() !== kunci,
    );
    const baru = [bersih, ...lama].slice(0, BATAS_RIWAYAT);

    tulisRiwayat(baru);

    return baru;
}

export function PanelPencarian({ className }: { className?: string }) {
    const navigate = useNavigate();
    const wadah = useRef<HTMLDivElement>(null);

    const [kueri, setKueri] = useState('');
    const [terbuka, setTerbuka] = useState(false);
    const [riwayat, setRiwayat] = useState<string[]>(() => bacaRiwayat());

    /**
     * The suggestions, fetched only while the panel is open.
     *
     * `enabled` is the whole reason the dashboard costs no extra request to render: the
     * list is asked for the first time the field is focused and then sits in the cache for
     * every later opening.
     *
     * No `sort` is sent. `fetchDokter` omits `relevan` from the query string because it IS
     * the server's default, so the request asks for nothing special and the server answers
     * with its own order - which is the ranking this section is labelled for.
     */
    const saran = useQuery({
        ...dokterOptions({ page: 1, per_page: BATAS_SARAN }),
        enabled: terbuka,
    });
    const rows = saran.data?.data.dokter ?? [];

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

        setRiwayat(catatRiwayat(bersih));
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
            className={cn('relative', className)}
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
                    cari(persis);
                }}
                className="border-input bg-background focus-within:border-foreground/40 flex h-12 min-w-0 items-center gap-1 rounded-full border pl-4 pr-1 transition-colors"
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
                <div
                    data-slot="dashboard-cari-panel"
                    className="bg-popover text-popover-foreground absolute left-0 right-0 top-full z-40 mt-2 overflow-hidden rounded-2xl shadow-xl"
                >
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
                                cari(persis);
                            }}
                            className="hover:bg-accent flex min-h-11 w-full items-center gap-3 px-4 py-3 text-left text-sm transition-colors"
                        >
                            <Search aria-hidden className="text-muted-foreground size-4 shrink-0" />

                            <span className="min-w-0 flex-1 truncate">{persis}</span>
                        </button>
                    ) : null}

                    {/**
                     * The past searches. A grey block rather than a list, because these are
                     * chips to pick from rather than rows to read down - the reference's
                     * arrangement, and the right one: a term is recognised by shape at a
                     * glance, so they wrap.
                     */}
                    {riwayat.length > 0 ? (
                        <div data-slot="dashboard-cari-riwayat" className="bg-muted px-4 py-3">
                            <div className="flex items-center justify-between gap-3">
                                <p className="text-foreground text-sm font-semibold">
                                    Pencarian sebelumnya
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
                                                setKueri(item);
                                                cari(item);
                                            }}
                                            className="border-input bg-background hover:bg-accent max-w-full min-h-11 truncate rounded-full border px-4 text-sm transition-colors"
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
                    <div data-slot="dashboard-cari-saran" className="px-4 py-3">
                        <p className="text-foreground text-sm font-semibold">
                            Dokter paling banyak dikonsultasikan
                        </p>

                        {saran.isPending ? (
                            <SkeletonRows rows={BATAS_SARAN} className="mt-3" />
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
                                            setTerbuka(false);
                                        }}
                                    >
                                        Lihat dokter terdekat
                                    </Link>
                                </Button>
                            </div>
                        ) : (
                            <ul className="mt-2 flex flex-col">
                                {rows.map((dokter) => (
                                    <li key={dokter.id}>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setKueri(dokter.nama_lengkap);
                                                cari(dokter.nama_lengkap);
                                            }}
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
            ) : null}
        </div>
    );
}
