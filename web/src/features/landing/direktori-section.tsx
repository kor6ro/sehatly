import { useEffect, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router';
import { X } from 'lucide-react';
import { DEFAULT_SORT, dokterOptions, spesialisasiOptions } from '@/lib/api/dokter';
import { DoctorCard } from '@/components/dokter/doctor-card';
import { IKON_BAWAAN, IKON_SPESIALISASI } from '@/features/landing/ikon-spesialis';
import { Button } from '@/components/ui/button';
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
 * 4. **the rest** - one door, `Buka direktori lengkap`, into `/dokter?spesialisasi=…`
 *    with the choice already applied.
 *
 * ## When it is on screen
 *
 * Only while `?spesialisasi=` says so, which keeps a plain `/` the page it was before any
 * of this existed - the carousel and the seven sections, nothing else. The parameter is a
 * parameter and not component state on purpose: the pick stays a plain `<a>`, so it can be
 * shared, reached with the keyboard, answered by the Back button, and read by the router
 * - which is also what lets the header's rail keep writing the address it always did.
 *
 * `?search=` and `?direktori=semua` are no longer read here at all. They are forwarders
 * now: `LandingPage` sends both to `/dokter` before this component can render, because a
 * free-text question and "the whole table" are what a PAGE is for.
 */
export function DirektoriSection() {
    const [params, setParams] = useSearchParams();
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
     * The pick happens in a panel fixed to the top of a tall page, so the answer would
     * otherwise land below the fold. `scroll-mt-28` on the section is the matching half:
     * the bar is 107px tall, and a section scrolled flush to the top would hide its own
     * heading under it.
     */
    useEffect(() => {
        if (kode === null) return;

        ref.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, [kode]);

    if (kode === null) return null;

    const tutup = () => {
        const berikut = new URLSearchParams(params);
        berikut.delete('spesialisasi');
        setParams(berikut);
    };

    const total = hasil.data?.meta?.total;
    const teksJumlah = hasil.isPending
        ? 'Menghitung hasil…'
        : hasil.isError
          ? 'Jumlah tidak dapat dimuat.'
          : `${String(total ?? 0)} dokter ditemukan.`;

    const rows = hasil.data?.data.dokter ?? [];

    return (
        <section
            ref={ref}
            id="direktori"
            data-slot="landing-direktori"
            className="border-border scroll-mt-28 w-full border-y bg-muted"
        >
            <div className="mx-auto w-full max-w-[1280px] px-4 py-10 md:px-6 md:py-14">
                <div className="flex flex-wrap items-start justify-between gap-3">
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
                                {baris?.nama ?? kode}
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

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="rounded-full"
                        onClick={tutup}
                        aria-label="Tutup pilihan spesialisasi"
                        data-slot="landing-direktori-tutup"
                    >
                        <X aria-hidden className="size-4" />
                        Tutup
                    </Button>
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
                            Gagal memuat daftar dokter. Periksa koneksi lalu coba lagi.
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

                {/**
                 * The one door out of the answer. It carries `?spesialisasi=` so the
                 * directory opens already filtered: the visitor's choice is not
                 * re-asked on the other side, which is the whole reason this band can
                 * be this short.
                 */}
                <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
                    <p className="text-muted-foreground max-w-xl text-sm">
                        Pencarian, filter, urutan, dan seluruh daftar dokter ada di
                        direktori lengkap.
                    </p>

                    <Button asChild className="rounded-full">
                        <Link
                            to={`/dokter?spesialisasi=${encodeURIComponent(kode)}`}
                        >
                            Buka direktori lengkap
                        </Link>
                    </Button>
                </div>
            </div>
        </section>
    );
}
