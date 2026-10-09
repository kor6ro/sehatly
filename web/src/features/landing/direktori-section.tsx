import { useEffect, useRef } from 'react';
import { useSearchParams } from 'react-router';
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { DirektoriDokter } from '@/features/dokter/direktori';

/**
 * The doctor directory, printed on the landing page. The page it used to be - `/dokter`,
 * with its own heading, its own title and its own address - is gone.
 *
 * ## Why the whole screen moved here rather than being linked to
 *
 * Every door into that page asked one question: *who can I see, and when?* The rail in the
 * header's "Direktori Dokter" panel, the "Sering dicari" shortcuts, the search pill in the
 * bar, the drawer on a phone, the card in "Solusi Kesehatan" - all of them are a pick, not
 * a journey. Answering a pick with a new document meant a new URL to share by accident, a
 * new title to read, and a Back button to remember. So the route is retired, the address
 * forwards here, and the CONTENT - search, filters, sort, count, cards, pagination, every
 * offline and empty state - lives in `DirektoriDokter` below, unchanged.
 *
 * ## When it is on screen
 *
 * Only while the URL says so, which keeps a plain `/` the page it was before any of this
 * existed - the seven sections, the carousel, nothing else:
 *
 * | parameter | who writes it | what it shows |
 * | --- | --- | --- |
 * | `?spesialisasi=` | the rail and the shortcuts | one specialisation |
 * | `?search=` | the search pill in the bar, or on the phone | one free-text query |
 * | `?direktori=semua` | "Lihat semua dokter" in the panel | the whole table |
 *
 * They are parameters and not component state on purpose: the pick stays a plain `<a>`,
 * so it can be shared, reached with the keyboard, answered by the Back button, and read by
 * the router - which is also what lets the old `/dokter` address forward here with its
 * `?search=` and `?spesialisasi=` intact instead of dropping them.
 *
 * ## Why `key` and not an effect
 *
 * `DirektoriDokter` seeds its filters from the URL once, on mount - the old page's rule,
 * and still the right one: a chip cleared inside the results must not be overwritten by the
 * address it came from. But this section can be re-picked without unmounting (choose
 * "Spesialis Anak", then "Dokter Gigi" from the same panel), and the URL is then the only
 * thing that changed. Remounting on the two seeding parameters is the honest fix: a new
 * pick means a new question, and the answer starts from the URL again rather than from a
 * filter the visitor can no longer see.
 */
export function DirektoriSection() {
    const [params, setParams] = useSearchParams();
    const ref = useRef<HTMLElement | null>(null);

    const spesialisasi = params.get('spesialisasi');
    const pencarian = params.get('search');
    const terbuka =
        spesialisasi !== null || pencarian !== null || params.has('direktori');

    /**
     * The pick happens in a panel fixed to the top of a tall page, so the answer would
     * otherwise land below the fold. `scroll-mt-28` on the section is the matching half:
     * the bar is 107px tall, and a section scrolled flush to the top would hide its own
     * heading under it.
     */
    useEffect(() => {
        if (!terbuka) return;

        ref.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, [terbuka, spesialisasi, pencarian]);

    if (!terbuka) return null;

    const tutup = () => {
        const berikut = new URLSearchParams(params);
        berikut.delete('spesialisasi');
        berikut.delete('search');
        berikut.delete('direktori');
        setParams(berikut);
    };

    return (
        <section
            ref={ref}
            id="direktori"
            data-slot="landing-direktori"
            className="scroll-mt-28 w-full"
        >
            <div className="mx-auto w-full max-w-[1280px] px-4 py-10 md:px-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <h2 className="text-2xl leading-snug font-bold md:text-3xl">
                            Direktori Dokter
                        </h2>

                        <p className="text-muted-foreground mt-1 text-sm">
                            Temukan dokter yang tepat, lalu pesan jadwal konsultasi.
                        </p>
                    </div>

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={tutup}
                        aria-label="Tutup direktori dokter"
                        data-slot="landing-direktori-tutup"
                    >
                        <X aria-hidden className="size-4" />
                        Tutup
                    </Button>
                </div>

                <div className="mt-5">
                    <DirektoriDokter
                        key={`${spesialisasi ?? ''}|${pencarian ?? ''}`}
                    />
                </div>
            </div>
        </section>
    );
}
