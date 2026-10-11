import { useEffect, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router';
import { DEFAULT_SORT, dokterOptions, spesialisasiOptions } from '@/lib/api/dokter';
import { DoctorCard } from '@/components/dokter/doctor-card';
import { IKON_BAWAAN, IKON_SPESIALISASI } from '@/features/landing/ikon-spesialis';
import { Skeleton } from '@/components/ui/skeleton';

/** How many of the answer's cards the landing page prints - two rows of four, no more. */
const PER_HALAMAN_JAWABAN = 8;

/**
 * The landing page's half of the doctor directory: the ANSWER to a pick, not the
 * directory itself.
 *
 * ## Why the flip
 *
 * When this section carried the whole screen, every door into it was a pick and every
 * pick paid for itself with a page: a visitor who chose "Dokter Gigi" got sixteen
 * specialisations, a type filter, a sort and three pages of pagination, none of which
 * they had asked about. That is what a DIRECTORY is for, and the directory is `/dokter`
 * again - a page whose doors are the navigation, so nobody reaches it by accident.
 *
 * What stays here is what a pick actually asked for, in the order it asked it:
 *
 * 1. **which** specialisation - its glyph (from `IKON_SPESIALISASI`, falling back to
 *    `IKON_BAWAAN` rather than to a glyph borrowed from another specialisation) and its
 *    name from the master list;
 * 2. **how many** - the same `{n} dokter ditemukan.` sentence the directory prints, so a
 *    number means the same thing on both surfaces;
 * 3. **who** - up to eight cards, in the same `DoctorCard` the directory uses;
 * 4. **where this sits** - a breadcrumb over all of it, `Home / Direktori Dokter / {nama}`.
 *
 * ## Why a breadcrumb instead of two buttons
 *
 * This used to end in a row of exits: `Tutup` carrying an X, and `Buka direktori lengkap`
 * into the directory. Both are gone, and the breadcrumb carries what they carried between
 * them. `Home` is the way back to the whole landing page - that was `Tutup`'s job.
 * `Direktori Dokter` opens `/dokter?spesialisasi=…` with the choice already applied -
 * that was `Buka direktori lengkap`'s job, label and all. Two exits, both phrased as
 * places rather than as commands bolted onto an answer.
 *
 * They were removable because neither was ever the only way out: the sticky bar above
 * carries `Direktori Dokter`, the sixteen-row panel and every other door in the app, so a
 * band that offers none of its own is not a band that traps anybody. The trail is here to
 * SAY where the visitor is - which is what a breadcrumb is for - not to be the last exit
 * in the building.
 *
 * ## When it is on screen
 *
 * Only while `?spesialisasi=` says so. The carousel stays: the pick view is a page in its
 * own right, and a category page whose banner sits above its breadcrumb is the shape this
 * reference uses. The seven sections BELOW this one do not render at all, though - nobody
 * reading "Spesialis Anak, 1 dokter ditemukan." was also asking about promos and the
 * medical dictionary - and a plain `/` renders them exactly as it always did.
 *
 * The band keeps its `bg-muted` and its borders either way: it is a panel of answers
 * standing on its own white, so it needs no second flavour for being alone.
 *
 * The choice is a URL parameter and not component state on purpose: the pick stays a plain
 * `<a>`, so it can be shared, reached with the keyboard, answered by the Back button, and
 * read by the router - which is also what lets the header's rail keep writing the address
 * it always did.
 *
 * `?search=` and `?direktori=semua` are no longer read here at all. They are forwarders
 * now: `LandingPage` sends both to `/dokter` before this component can render, because a
 * free-text question and "the whole table" are what a PAGE is for.
 */
export function DirektoriSection() {
    const [params] = useSearchParams();
    const ref = useRef<HTMLElement | null>(null);

    const kode = params.get('spesialisasi');

    const master = useQuery(spesialisasiOptions());
    const baris = (master.data?.data.spesialisasi ?? []).find(
        (row) => row.kode === kode,
    );
    const Ikon = (kode === null ? undefined : IKON_SPESIALISASI[kode]) ?? IKON_BAWAAN;

    const hasil = useQuery({
        ...dokterOptions({
            page: 1,
            per_page: PER_HALAMAN_JAWABAN,
            ...(kode === null ? {} : { spesialisasi: kode }),
            sort: DEFAULT_SORT,
        }),
        // A plain `/` must not fetch: the section renders nothing and the request would
        // be for the whole table nobody asked about.
        enabled: kode !== null,
    });

    /**
     * The pick happens in a panel fixed to the top of a tall page, and the pick view now
     * opens with the carousel - so without this the click would appear to do nothing at
     * all, since the carousel is the same carousel `/` was already showing. What gets
     * scrolled to is the BREADCRUMB rather than the band: arriving on the trail puts
     * "where am I", the heading and the cards in one frame, with the banner one flick up
     * instead of a screen away.
     *
     * `scroll-mt-28` is the matching half of the 107px sticky bar - flush to the top and
     * the trail would sit underneath it.
     */
    useEffect(() => {
        if (kode === null) return;

        ref.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, [kode]);

    if (kode === null) return null;

    const nama = baris?.nama ?? kode;
    const total = hasil.data?.meta?.total;
    const teksJumlah = hasil.isPending
        ? 'Menghitung hasil…'
        : hasil.isError
          ? 'Jumlah tidak dapat dimuat.'
          : `${String(total ?? 0)} dokter ditemukan.`;

    const rows = hasil.data?.data.dokter ?? [];

    return (
        <>
            {/**
             * The trail, and the first thing in the pick view after the banner. It is
             * what the scroll effect above lands on, which is why it - and not the band
             * - carries `scroll-mt-28`.
             *
             * `Home` is what `Tutup` was: one click back to the whole landing page,
             * which the sticky bar does not itself offer. `Direktori Dokter` is what
             * `Buka direktori lengkap` was, X-button and all, and it keeps the choice
             * as `?spesialisasi=` so the directory opens already narrowed instead of
             * promising a filter it silently drops. The last crumb is the page, so it is
             * not a link.
             */}
            <nav
                ref={ref}
                aria-label="Breadcrumb"
                data-slot="landing-krikib"
                className="bg-background scroll-mt-28 w-full"
            >
                <ol className="mx-auto flex w-full max-w-[1280px] flex-wrap items-center gap-x-2 gap-y-1 px-4 py-3 text-sm md:px-6">
                    <li className="flex items-center gap-2">
                        <Link
                            to="/"
                            className="text-muted-foreground hover:text-foreground underline-offset-4 hover:underline"
                        >
                            Home
                        </Link>
                        <span aria-hidden className="text-muted-foreground/50">
                            /
                        </span>
                    </li>

                    <li className="flex items-center gap-2">
                        <Link
                            to={`/dokter?spesialisasi=${encodeURIComponent(kode)}`}
                            className="text-muted-foreground hover:text-foreground underline-offset-4 hover:underline"
                        >
                            Direktori Dokter
                        </Link>
                        <span aria-hidden className="text-muted-foreground/50">
                            /
                        </span>
                    </li>

                    <li>
                        <span aria-current="page" className="text-foreground font-medium">
                            {nama}
                        </span>
                    </li>
                </ol>
            </nav>

            <section
                id="direktori"
                data-slot="landing-direktori"
                className="border-border w-full border-y bg-muted"
            >
                <div className="mx-auto w-full max-w-[1280px] px-4 py-10 md:px-6 md:py-14">
                    <div className="flex min-w-0 items-center gap-3">
                        {/**
                         * The specialisation's own glyph, never a generic one: a mark
                         * that says "Dentist" above the word "Dokter Gigi" is a second
                         * label, and a mark borrowed from another specialisation is a
                         * lie. The master list is still loading, so the fallback draws
                         * `IKON_BAWAAN` - no glyph at all would leave this circle empty.
                         */}
                        <span className="border-border bg-background flex size-12 shrink-0 items-center justify-center rounded-full border">
                            <Ikon aria-hidden className="text-foreground size-5" />
                        </span>

                        <div className="min-w-0">
                            <h2 className="text-2xl leading-snug font-bold md:text-3xl">
                                {nama}
                            </h2>

                            <p
                                role="status"
                                aria-live="polite"
                                data-slot="landing-direktori-jumlah"
                                className="text-muted-foreground mt-1 text-sm"
                            >
                                {teksJumlah}
                            </p>
                        </div>
                    </div>

                    <div className="mt-6">
                        {hasil.isPending ? (
                            <ul
                                aria-hidden
                                className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
                            >
                                {Array.from({ length: 4 }, (_, i) => (
                                    <li key={i}>
                                        <Skeleton className="h-56 rounded-xl" />
                                    </li>
                                ))}
                            </ul>
                        ) : hasil.isError ? (
                            <p className="text-muted-foreground text-sm">
                                Gagal memuat daftar dokter. Periksa koneksi lalu coba
                                lagi.
                            </p>
                        ) : rows.length === 0 ? (
                            /**
                             * The honest empty answer, and the one a visitor on THIS page is
                             * asking for: not "no filter matched" - they applied no filter -
                             * but "this specialisation has nobody in it right now". The
                             * seeded fixture genuinely has no dentist, so this state is the
                             * one a reader of `/?spesialisasi=GIGI` will meet.
                             */
                            <p className="text-muted-foreground text-sm">
                                Belum ada dokter pada spesialisasi ini.
                            </p>
                        ) : (
                            <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {rows.map((row) => (
                                    <li key={row.id}>
                                        <DoctorCard
                                            id={row.id}
                                            nama={row.nama_lengkap}
                                            tipe={row.tipe}
                                            spesialisasi={row.spesialisasi}
                                            biaya={row.biaya_konsultasi_online}
                                            rating={row.rating_rata_rata}
                                            konsultasi={row.jumlah_konsultasi}
                                            pengalaman={row.pengalaman_tahun}
                                            ulasan={row.jumlah_ulasan}
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </section>
        </>
    );
}
