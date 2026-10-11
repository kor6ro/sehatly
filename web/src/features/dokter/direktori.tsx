import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router';
import { X } from 'lucide-react';
import {
    cariMasterPopuler,
    DEFAULT_SORT,
    dokterOptions,
    labelTipeDokter,
    POPULER,
    SORT_DOKTER,
    spesialisasiOptions,
    TIPE_DOKTER,
    type DokterFilters,
    type SortDokter,
} from '@/lib/api/dokter';
import { DoctorCard } from '@/components/dokter/doctor-card';
import { isEmptyPage, isPastLastPage } from '@/lib/api/pagination';
import { ApiError } from '@/lib/http';
import type { DokterTipe, Spesialisasi } from '@/lib/api/types';
import { cn } from '@/lib/utils';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { Pagination } from '@/components/layout/pagination';
import { OfflineBanner } from '@/components/offline-banner';
import { SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState } from '@/components/states/error-state';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

/**
 * The public doctor directory - the whole of the answering body of the landing page: the
 * filters, the sort, the count, the cards, the pagination, and every offline, empty and
 * error state they were built with.
 *
 * ## Where it renders
 *
 * On `/`. There is no `/dokter` page any more: the directory is the ANSWER to whatever the
 * visitor asked in the header - a specialisation, a doctor type, telemedisin, or a name
 * typed into the bar - and an answer belongs on the page that was asked, not on a second
 * address the visitor then has to back out of. `LandingPage` renders this whenever the URL
 * or the handed-over search says there is a question to answer, and the seven marketing
 * sections otherwise.
 *
 * What did NOT move is the asking. The search field, the sixteen specialisations, the type
 * chips and the telemedisin switch all live in the header now - one place to ask, rather
 * than a form on the answer as well. The one thing this component still owns is the SEARCH
 * TERM ITSELF, as a removable chip, because a result set with no visible question is a
 * number nobody can check.
 *
 * ## Why it is still public
 *
 * `DokterController` states it directly: the directory is public, and putting
 * `permission:dokter.lihat` on it would answer 401 to every anonymous visitor because
 * `EnsurePermission` needs an authenticated principal - the opposite of what a
 * pre-authentication browse page needs. It would also lock out `perawat` and `kurir`, which
 * are real `users.tipe` values holding no role. Nothing about the address changes that:
 * the component renders for a signed-out visitor exactly as it always has, which is also
 * what keeps the 404 behaviour demonstrable with no session involved at all.
 *
 * ## F03: the search is state, never a URL
 *
 * `web/ux/patterns/F03.md` §9 forbids a free-text doctor search in a shareable URL,
 * because "kanker" is a condition and a URL is copied into chats. The query typed into the
 * header's bar therefore lives in `sessionStorage` and never in the address:
 * `serahkanKueri` parks it immediately before the navigation, and this component consumes
 * it on mount, so the bar can open this answer already filtered without ever writing the
 * question into the address. It comes back on a RELOAD of `/` - the one case AC-1 asks it
 * to survive - and is shown as a removable chip, so the visitor can see what is being
 * answered and take it back. `?search=` is still READ, for the links old versions of this
 * app wrote, and never written by this one. Filters stay in component state (the §12 #5
 * URL-shareability decision is still open).
 *
 * ## Desktop filters are live, mobile filters are drafted
 *
 * F03 §2 steps 3-6: a persistent desktop sidebar applies immediately, while the mobile
 * bottom sheet edits a draft and only commits on `Tampilkan {n} hasil`, so a slow
 * connection cannot make the list flicker behind the patient's finger. The draft count
 * comes from `dokterCountOptions`, a `per_page=1` read of the same endpoint.
 *
 * ## `aria-disabled`, not `disabled`, on the offline controls
 *
 * F03 AC-9 asks for controls that say why they do nothing instead of vanishing. A natively
 * `disabled` button cannot receive focus and so can never be asked. These keep their place
 * in the tab order, carry `aria-disabled`, point at the visible offline banner through
 * `aria-describedby`, and their handlers return before any state change.
 */

const PER_HALAMAN = 12;
const SIMPANAN_KUERI = 'sehatly.dokter.kueri';
/**
 * The one-shot marker a search in the header's bar leaves on the way to `/`.
 *
 * It is a separate key from {@link SIMPANAN_KUERI} because the two answer different
 * questions: this one means "somebody just arrived with THIS", that one means "this is
 * what a reload of this address should restore". Conflating them would make the bar's
 * empty submit - "everything" - indistinguishable from "there was never a query".
 */
const SIMPANAN_ANTAR = 'sehatly.dokter.kueri.antar';

/**
 * The doorbell that goes with the marker.
 *
 * The bar sits ABOVE this answer, so a visitor who types into it is usually typing while
 * the directory is already on the screen - and a mounted component never re-runs the
 * `useState` initializer that first read the marker. Storage is the payload, this is the
 * knock; the mount below also knocks once for itself, which is how a submit from a screen
 * the directory is not on still arrives.
 */
const EVENT_ANTAR = 'sehatly:dokter:kueri';

/**
 * The three constraints a visitor can pick. `sort` is deliberately not one of them: it
 * arranges a result set rather than choosing it, so it stays in component state and never
 * reaches the address.
 */
type PilihanFilter = {
    spesialisasi: string | undefined;
    tipe: DokterTipe | undefined;
    telemedisin: boolean;
};

const PILIHAN_KOSONG: PilihanFilter = {
    spesialisasi: undefined,
    tipe: undefined,
    telemedisin: false,
};

/**
 * The query a visitor typed survives a RELOAD of the address they typed it on - and
 * nothing else brings it back.
 *
 * AC-1 asks for exactly that: F03 §9 keeps the free-text query out of the URL, so a
 * reload would otherwise arrive with no memory of the question. But this address is opened
 * with several intents: "Lihat semua dokter" in the panel, the drawer's entry on a phone
 * and an empty search all arrive here, and so does the reload of a search made
 * here. Restoring the query in every case would answer "show me everything" with a filter
 * the visitor cannot see. A reload is the one moment where they are asking for the state
 * they just had; a fresh load may be asking for anything, and the URL - which still
 * carries `?search=` when an old link wrote it - is the only thing allowed to speak for
 * the rest.
 */
function bacaKueriTersimpan(): string {
    try {
        const navigasi = performance.getEntriesByType('navigation')[0] as
            | PerformanceNavigationTiming
            | undefined;

        if (navigasi?.type !== 'reload') return '';

        return window.sessionStorage.getItem(SIMPANAN_KUERI) ?? '';
    } catch {
        return '';
    }
}

function simpanKueriTersimpan(kueri: string): void {
    try {
        if (kueri === '') {
            window.sessionStorage.removeItem(SIMPANAN_KUERI);
        } else {
            window.sessionStorage.setItem(SIMPANAN_KUERI, kueri);
        }
    } catch {
        // A browser that refuses storage (private mode, disabled cookies) loses the
        // reload persistence and nothing else: the query still works in memory.
    }
}

/**
 * Hand a query from the header's search field to this component.
 *
 * F03 §9 is the reason this is a `sessionStorage` key rather than `?search=`: a free-text
 * doctor query may be a condition, and the address of the page that ANSWERED it is the
 * thing that gets pasted into a chat. So the bar writes the marker, then navigates to a
 * bare `/`, and this component's state initializer reads the marker once. The same call
 * rings {@link EVENT_ANTAR} alongside the write, because that component is usually still
 * mounted when the bar finishes - the bar is above the answer it is asking about - and a
 * mounted component never re-runs an initializer.
 *
 * It is a marker and not a setting: {@link habiskanKueriAntar} spends it, so the
 * next arrival at this address - a bookmark, the panel's "Lihat semua dokter", a Back
 * button - starts from the whole table rather than from somebody else's question.
 *
 * `''` is a value here, not an absence. An empty submit in the bar means "everything", and
 * it has to be able to say so.
 */
export function serahkanKueri(kueri: string): void {
    try {
        window.sessionStorage.setItem(SIMPANAN_ANTAR, kueri);
    } catch {
        // Storage refused (private mode, disabled cookies): the directory still opens, it
        // simply arrives without the question the bar was carrying.
    }

    // Rung even when storage refused. A visitor who just typed a name should see it
    // answered; the reload it survives may be the thing they give up, not the answer.
    window.dispatchEvent(new Event(EVENT_ANTAR));
}

/**
 * A read that does NOT spend the marker.
 *
 * React StrictMode invokes a `useState` initializer twice in development, so a read that
 * removed the key in the same call would return the query the first time and `null` the
 * second - and the committed state would be the one with nothing in it.
 */
function bacaKueriAntar(): string | null {
    try {
        return window.sessionStorage.getItem(SIMPANAN_ANTAR);
    } catch {
        return null;
    }
}

/** Read the marker and clear it in the same call: a handoff happens exactly once. */
function habiskanKueriAntar(): string | null {
    const kueri = bacaKueriAntar();

    if (kueri !== null) {
        try {
            window.sessionStorage.removeItem(SIMPANAN_ANTAR);
        } catch {
            // Same as above: failing to clear costs only the one-shot guarantee, and the
            // marker is still read before it is written over.
        }
    }

    return kueri;
}

/**
 * The address parameters that mean "this visit is asking the directory a question", plus
 * a handed-over or stored search term - the one question the address is not allowed to
 * carry (F03 §9).
 *
 * `LandingPage` asks this before it renders anything, because the directory and the seven
 * marketing sections are two answers to "what does this visitor want", and the two must
 * never be on screen together: a patient who typed a name into the bar is looking at the
 * directory, and a patient who typed nothing is looking at the front page.
 */
export function adaPertanyaanDirektori(params: URLSearchParams): boolean {
    return (
        params.has('spesialisasi') ||
        params.has('tipe') ||
        params.has('telemedisin') ||
        params.has('semua') ||
        params.has('search') ||
        // A bar search PEEKS here and is only spent inside this component, so a decision
        // that never looked at the marker would leave the answer unmounted and the seven
        // marketing sections up in its place - the visitor would be shown a promo strip
        // instead of their own question.
        bacaKueriAntar() !== null ||
        bacaKueriTersimpan() !== ''
    );
}

export function DirektoriDokter() {
    const online = useOnlineStatus();

    /**
     * The query, seeded ONCE from whichever voice is speaking, in the order a visitor
     * would want to be answered in:
     *
     * 1. the header's bar, which parked it in `sessionStorage` moments ago;
     * 2. `?search=`, written by older versions of this app that put it in the address,
     *    and still read so those links arrive filtered;
     * 3. nothing at all - unless this is a RELOAD of an address a query was typed on.
     *
     * Read in one place so that two initializers cannot disagree about what was typed,
     * and so that a reload never restores a query the URL has already replaced. It seeds
     * both halves of the state on purpose: the box a visitor sees and the request the
     * page fires must carry the same question, or the screen lies about what it asked.
     */
    const [searchParams, setSearchParams] = useSearchParams();
    const [kueriAwal] = useState(
        () =>
            bacaKueriAntar() ??
            searchParams.get('search') ??
            bacaKueriTersimpan(),
    );

    const [page, setPage] = useState(1);
    const [submittedSearch, setSubmittedSearch] = useState(kueriAwal);

    /**
     * The three CONSTRAINTS live in the address, and that is the whole reason a pick in
     * the header's panel can stay a plain `<a>`: the panel writes `?spesialisasi=`,
     * `?tipe=` and `?telemedisin=`, they land here already applied, and a visitor who
     * shares the link hands over the same question they were looking at.
     *
     * `sort` and `page` stay local on purpose. They ARRANGE a result set rather than
     * choose it, so a shared link should not carry somebody's ordering or a page number
     * that may not exist any more.
     *
     * `?semua=1` is the directory with nothing applied - the address "Lihat semua dokter"
     * and "Hapus semua filter" both point at, and the one that keeps this answer on
     * screen after the last chip is removed.
     *
     * An unknown `kode` is left in place on purpose: the API answers an empty page for it
     * and the chip above the results can be removed, which is a better outcome than
     * arriving with no filter at all.
     */
    const spesialisasi = searchParams.get('spesialisasi') ?? undefined;
    const tipeDariUrl = searchParams.get('tipe');
    const tipe: DokterTipe | undefined =
        tipeDariUrl !== null && TIPE_DOKTER.includes(tipeDariUrl as DokterTipe)
            ? (tipeDariUrl as DokterTipe)
            : undefined;
    const telemedisinOnly = searchParams.get('telemedisin') === '1';
    const [sort, setSort] = useState<SortDokter>(DEFAULT_SORT);

    /**
     * Taking delivery of the bar's handoff.
     *
     * It SUBSCRIBES as well as reads. The bar is above this answer, so most submits happen
     * while this component is already on the screen, and a mounted component never runs its
     * initializer a second time: without the listener the term would sit in storage while
     * the list underneath kept answering the question the visitor had asked before typing
     * it. The term is applied to the request as well as to the reload store - AC-1 wants a
     * reload of the address it was typed on to keep it - and the shortlist starts over at
     * page one: a new question, not a new page of the old one.
     */
    useEffect(() => {
        /**
         * PEEK, never spend. `LandingPage` reads the same marker during RENDER to decide
         * whether this address is a question or the front page, and a listener that ate
         * the key would unmount this very component between the bar's submit and the
         * navigation that follows it - the marketing sections would flash where the
         * visitor's own query should be.
         */
        const terima = (): void => {
            const dariTangan = bacaKueriAntar();

            if (dariTangan !== null) {
                simpanKueriTersimpan(dariTangan);
                setSubmittedSearch(dariTangan);
                setPage(1);
            }
        };

        // The mount case, and the case where nobody was listening when the bar rang: a
        // submit from a screen this directory is not on leaves the marker in storage and
        // reaches here as a plain read.
        terima();

        window.addEventListener(EVENT_ANTAR, terima);

        return () => window.removeEventListener(EVENT_ANTAR, terima);
    }, []);

    /**
     * Spending the marker, deliberately outside the listener above and outside the render
     * that needs it.
     *
     * Both exits are covered - a new address, and this component going away - so a marker
     * cannot outlive the component that consumed it. An earlier version spent it inside
     * the listener, and the symptom was precise: the bar accepted the query, the URL
     * changed, and the visitor was looking at a promo strip, because the decision that
     * keeps this answer mounted had already looked at a key that was no longer there.
     */
    useEffect(() => {
        habiskanKueriAntar();

        return () => {
            habiskanKueriAntar();
        };
    }, [searchParams]);

    const hasilRef = useRef<HTMLParagraphElement | null>(null);
    const fokusHasil = useRef(false);

    const filters = useMemo<DokterFilters>(
        () => ({
            page,
            per_page: PER_HALAMAN,
            ...(spesialisasi === undefined ? {} : { spesialisasi }),
            ...(tipe === undefined ? {} : { tipe }),
            ...(submittedSearch === '' ? {} : { search: submittedSearch }),
            ...(telemedisinOnly ? { tersedia_telemedisin: true } : {}),
            sort,
        }),
        [page, spesialisasi, submittedSearch, sort, telemedisinOnly, tipe],
    );

    const list = useQuery(dokterOptions(filters));
    const spesialisasiList = useQuery(spesialisasiOptions());

    const rows = list.data?.data.dokter ?? [];
    const meta = list.data?.meta;
    const daftarSpesialisasi = spesialisasiList.data?.data.spesialisasi ?? [];

    /**
     * Where a card goes: `/?dokter={id}` on THIS page, so the profile opens in place and
     * the list is one click behind it - carrying whatever the visitor is standing in, so
     * "Kembali" puts them back exactly where they were. The `/dokter/:id` page still
     * exists for the cards reached from OUTSIDE this list (a booking, a notification),
     * where there is no list to come back to.
     */
    function alamatDokter(id: number): string {
        const berikut = new URLSearchParams(searchParams);

        berikut.delete('dokter');

        // The list has to still be there when the visitor comes back from the profile.
        // A search-only visit carries no parameter at all - its question lives in
        // `sessionStorage` and was spent on mount - so without a marker of its own,
        // removing `?dokter=` would land on the marketing sections and the row they were
        // reading would be gone.
        if (
            !berikut.has('spesialisasi') &&
            !berikut.has('tipe') &&
            !berikut.has('telemedisin') &&
            !berikut.has('semua') &&
            !berikut.has('search')
        ) {
            berikut.set('semua', '1');
        }

        berikut.set('dokter', String(id));

        return `/?${berikut.toString()}`;
    }

    const chipAktif: ReadonlyArray<{
        kunci: 'cari' | 'spesialisasi' | 'tipe' | 'telemedisin';
        label: string;
    }> = (() => {
        const hasil: Array<{
            kunci: 'cari' | 'spesialisasi' | 'tipe' | 'telemedisin';
            label: string;
        }> = [];

        /**
         * First, and the only one that is not a filter: a search term is a question the
         * bar asked and this answer is obeying, so it has to be visible here or the count
         * below is a number with no stated cause. It never appears in the address, which
         * is exactly why it needs a face somewhere.
         */
        if (submittedSearch !== '') {
            hasil.push({ kunci: 'cari', label: `Mencari "${submittedSearch}"` });
        }

        if (spesialisasi !== undefined) {
            hasil.push({
                kunci: 'spesialisasi',
                label:
                    daftarSpesialisasi.find((row) => row.kode === spesialisasi)?.nama ??
                    spesialisasi,
            });
        }

        if (tipe !== undefined) {
            hasil.push({ kunci: 'tipe', label: labelTipeDokter(tipe) });
        }

        if (telemedisinOnly) {
            hasil.push({ kunci: 'telemedisin', label: 'Telemedisin' });
        }

        return hasil;
    })();

    const hasFilters =
        submittedSearch !== '' ||
        spesialisasi !== undefined ||
        tipe !== undefined ||
        telemedisinOnly;

    const jumlah = meta?.total;
    /**
     * One flag for "the number is not known yet", so the count sentence cannot be spelled
     * differently by the live region and by the emphasis on its number below.
     */
    const menghitung = list.isFetching || jumlah === undefined;
    const teksJumlah = menghitung ? 'Menghitung hasil…' : `${jumlah} dokter ditemukan.`;

    /**
     * Focus the results counter after a page change, so a keyboard user who pressed a page
     * number is put back at the top of the new list instead of at the pagination control
     * they just left.
     */
    useEffect(() => {
        if (!fokusHasil.current) {
            return;
        }

        fokusHasil.current = false;
        hasilRef.current?.focus();
    }, [page]);

    function resetToFirstPage(): void {
        setPage(1);
    }

    function ubahHalaman(berikut: number): void {
        if (berikut === page) {
            return;
        }

        fokusHasil.current = true;
        setPage(berikut);
    }

    /**
     * Writing the three constraints back into the address, so the URL the visitor sees is
     * always the filter they are looking at - and so a chip removal, a panel choice and a
     * draft commit can never disagree about what is applied. One applier for all three,
     * because a value committed by one control and forgotten by the other is the defect
     * this function exists to make impossible.
     */
    function tulisFilter(
        next: PilihanFilter,
        opsi: { semua?: boolean } = {},
    ): void {
        if (nonaktif) {
            return;
        }

        const berikut = new URLSearchParams(searchParams);

        if (next.spesialisasi === undefined) {
            berikut.delete('spesialisasi');
        } else {
            berikut.set('spesialisasi', next.spesialisasi);
        }

        if (next.tipe === undefined) {
            berikut.delete('tipe');
        } else {
            berikut.set('tipe', next.tipe);
        }

        if (next.telemedisin) {
            berikut.set('telemedisin', '1');
        } else {
            berikut.delete('telemedisin');
        }

        /**
         * An answer with nothing applied still has to BE an answer. `?semua=1` is what
         * keeps this directory on screen once the last constraint is gone: without it the
         * address falls back to `/`, which is the marketing sections, and a visitor
         * removing their last chip would watch the list they were reading disappear under
         * their own finger.
         */
        const kosong =
            next.spesialisasi === undefined &&
            next.tipe === undefined &&
            !next.telemedisin;

        if (opsi.semua === true || kosong) {
            berikut.set('semua', '1');
        } else {
            berikut.delete('semua');
        }

        setSearchParams(berikut);
        resetToFirstPage();
    }

    /**
     * Clearing the search is not the same act as clearing a filter: the filters are
     * addresses the visitor can also hand-edit, share and press Back out of, and the
     * search is a `sessionStorage` value they cannot see at all. Both are chips, and only
     * one of them can be undone from the address bar - which is why the search chip
     * returns before the applier runs.
     */
    function hapusChip(kunci: 'cari' | 'spesialisasi' | 'tipe' | 'telemedisin'): void {
        if (!online) {
            return;
        }

        if (kunci === 'cari') {
            setSubmittedSearch('');
            simpanKueriTersimpan('');

            // The question the address was answering is gone, and an address that then
            // carries nothing would render the marketing sections instead - the list
            // would disappear under the visitor's finger. `?semua=1` keeps the answer on
            // screen with nothing applied, which is what an empty search meant anyway.
            if (
                !spesialisasi &&
                tipe === undefined &&
                !telemedisinOnly &&
                !searchParams.has('semua') &&
                !searchParams.has('search')
            ) {
                tulisFilter(PILIHAN_KOSONG, { semua: true });
            } else {
                resetToFirstPage();
            }

            return;
        }

        tulisFilter({
            spesialisasi: kunci === 'spesialisasi' ? undefined : spesialisasi,
            tipe: kunci === 'tipe' ? undefined : tipe,
            telemedisin: kunci === 'telemedisin' ? false : telemedisinOnly,
        });
    }

    function hapusSemuaFilter(): void {
        if (!online) {
            return;
        }

        simpanKueriTersimpan('');
        setSubmittedSearch('');
        setSort(DEFAULT_SORT);

        // `?semua=1` is what keeps this answer on screen once the last constraint is gone.
        tulisFilter(PILIHAN_KOSONG, { semua: true });
    }

    function pilihPopuler(master: Spesialisasi): void {
        if (!online) {
            return;
        }

        simpanKueriTersimpan('');
        setSubmittedSearch('');

        tulisFilter({
            spesialisasi: master.kode,
            tipe: undefined,
            telemedisin: false,
        });
    }

    const nonaktif = !online;

    /**
     * `Urutkan` is not a constraint: it reorders a result set without choosing one, so it
     * is the one control in this component that must NOT write the address. A sort change
     * that pushed a history entry would make Back mean "put the order back", which is a
     * question nobody asked.
     */
    function urutkanSaja(nilai: SortDokter): void {
        if (nonaktif) {
            return;
        }

        setSort(nilai);
    }

    /**
     * The specialisation the visitor picked, spelled out - and what every card under this
     * heading prints instead of the doctor's whole list.
     *
     * `Dokter Fixture Dua` really does hold two rows, Anak and Kulit & Kelamin, and in an
     * unfiltered directory the card says both, because there the card is describing the
     * doctor. Here it is not: this screen is the answer to "who are the Spesialis Anak
     * doctors", and an answer that prints a specialisation the question never asked for
     * reads as a filter that did not take. Nothing is hidden - the second row is still on
     * the profile, one card away - the label is simply scoped to the question.
     */
    const spesialisasiTerpilih =
        spesialisasi === undefined
            ? null
            : (daftarSpesialisasi.find((row) => row.kode === spesialisasi)?.nama ??
              spesialisasi);

    /**
     * The headline over the answer. A pick names itself - "Spesialis Anak" - and an
     * unfiltered visit gets the F03 §4.3 copy verbatim, so the sentence is the same one
     * the standalone directory used to print and a screen reader hears the same words it
     * always did.
     */
    const judul = spesialisasiTerpilih ?? 'Direktori dokter';

    return (
        <section
            data-slot="landing-direktori"
            aria-labelledby="landing-direktori-judul"
            className="mx-auto flex w-full max-w-[1280px] flex-col gap-5 px-4 pt-6 pb-16 md:px-6"
        >
            <header className="flex flex-col gap-2">
                <h1
                    id="landing-direktori-judul"
                    className="text-foreground text-3xl font-bold tracking-tight md:text-4xl"
                >
                    {judul}
                </h1>

                <p className="text-muted-foreground max-w-2xl text-base md:text-lg">
                    Temukan dokter yang tepat, lalu pesan jadwal konsultasi.
                </p>
            </header>

            {/**
             * The wrapper carries the id the offline controls point at through
             * `aria-describedby`. `OfflineBanner` renders `null` while online, so the
             * wrapper is empty then and the attribute is only set offline.
             */}
            <div id="dokter-alasan-offline">
                <OfflineBanner message="Anda sedang offline. Pencarian dan filter tidak dikirim sampai koneksi kembali." />
            </div>

            {/**
             * The results column and nothing else.
             *
             * There is no filter panel here any more. Every constraint it used to hold -
             * the sixteen specialisations, the seven doctor types, telemedisin - is in
             * the header's panel now, and a panel that repeats a menu the visitor just
             * used is not a second way to choose: it is the same sixteen rows, printed
             * under an answer that already obeyed them. What belongs under the headline
             * is what the choice produced - what is applied, how many, who, and in what
             * order - and the way out is the chips, the breadcrumb and the front page.
             */}
            <div className="flex min-w-0 flex-col gap-4">
                {/**
                 * What am I looking at, and in what order - one line, because the two
                 * answers belong together. `Urutkan` is not a constraint: it reorders
                 * the rows without removing one, which is why it is not in the
                 * header's panel either (a menu is where you CHOOSE; ordering a
                 * result set is what you do with a result set). It is the one thing
                 * on this page that stays the visitor's own arrangement and never
                 * reaches the address, so it is also the one control that cannot be
                 * shared - deliberately.
                 */}
                <div className="border-border flex flex-wrap items-center justify-between gap-3 border-b pb-4">
                    {list.isError ? null : (
                        <p
                            ref={hasilRef}
                            tabIndex={-1}
                            role="status"
                            aria-live="polite"
                            data-slot="dokter-count"
                            className="text-muted-foreground focus-visible:ring-ring rounded-md text-sm focus-visible:ring-2"
                        >
                            {menghitung ? (
                                teksJumlah
                            ) : (
                                <>
                                    <span className="text-foreground font-semibold tabular-nums">
                                        {String(jumlah)}
                                    </span>{' '}
                                    dokter ditemukan.
                                </>
                            )}
                        </p>
                    )}

                    <div className="ml-auto">
                        <UrutkanGrup nilai={sort} onUbah={urutkanSaja} nonaktif={nonaktif} />
                    </div>
                </div>

                {chipAktif.length === 0 ? null : (
                    <div
                        className="flex flex-wrap items-center gap-2"
                        data-slot="dokter-chip-aktif"
                    >
                        {chipAktif.map((chip) => (
                            <Button
                                key={chip.kunci}
                                type="button"
                                variant="secondary"
                                className="min-h-11 rounded-full"
                                data-slot="dokter-chip"
                                aria-label={`Hapus filter ${chip.label}`}
                                aria-disabled={nonaktif}
                                aria-describedby={
                                    nonaktif ? 'dokter-alasan-offline' : undefined
                                }
                                onClick={() => {
                                    hapusChip(chip.kunci);
                                }}
                            >
                                {chip.label}

                                <X aria-hidden className="size-3.5" />
                            </Button>
                        ))}

                        <Button
                            type="button"
                            variant="ghost"
                            className="min-h-11 rounded-full"
                            data-slot="dokter-hapus-semua"
                            aria-disabled={nonaktif}
                            onClick={hapusSemuaFilter}
                        >
                            Hapus semua filter
                        </Button>
                    </div>
                )}

                {list.isPending ? (
                    <SkeletonRows rows={5} />
                ) : list.isError ? (
                    <ErrorState
                        title="Gagal memuat daftar dokter. Periksa koneksi lalu coba lagi."
                        error={asDirectoryError(list.error)}
                        onRetry={() => {
                            void list.refetch();
                        }}
                    />
                ) : isEmptyPage(meta, rows.length) ? (
                    hasFilters ? (
                        <EmptyState
                            title="Tidak ada dokter yang cocok."
                            description="Coba hapus satu filter atau gunakan kata kunci lain."
                            action={
                                <div className="flex flex-col items-center gap-3">
                                    {chipAktif.length === 0 ? null : (
                                        <div className="flex flex-wrap justify-center gap-2">
                                            {chipAktif.map((chip) => (
                                                <Button
                                                    key={chip.kunci}
                                                    type="button"
                                                    variant="secondary"
                                                    className="min-h-11"
                                                    aria-label={`Hapus filter ${chip.label}`}
                                                    aria-disabled={nonaktif}
                                                    onClick={() => {
                                                        hapusChip(chip.kunci);
                                                    }}
                                                >
                                                    {`Hapus filter "${chip.label}"`}
                                                </Button>
                                            ))}
                                        </div>
                                    )}

                                    <div className="flex flex-wrap justify-center gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="min-h-11"
                                            aria-disabled={nonaktif}
                                            onClick={hapusSemuaFilter}
                                        >
                                            Hapus semua filter
                                        </Button>

                                        {POPULER.map((pintasan) => {
                                            const master = cariMasterPopuler(
                                                daftarSpesialisasi,
                                                pintasan.kataKunci,
                                            );
                                            const mati =
                                                master === undefined || nonaktif;

                                            return (
                                                <Button
                                                    key={pintasan.label}
                                                    type="button"
                                                    variant="outline"
                                                    className="min-h-11"
                                                    disabled={mati}
                                                    aria-disabled={mati}
                                                    onClick={() => {
                                                        if (master !== undefined) {
                                                            pilihPopuler(master);
                                                        }
                                                    }}
                                                >
                                                    {`Cari "${pintasan.label}"`}
                                                </Button>
                                            );
                                        })}

                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="min-h-11"
                                            aria-disabled={nonaktif}
                                            onClick={hapusSemuaFilter}
                                        >
                                            Lihat semua dokter
                                        </Button>
                                    </div>
                                </div>
                            }
                        />
                    ) : (
                        <EmptyState
                            title="Belum ada dokter yang memenuhi syarat untuk tampil."
                            description="Direktori publik menampilkan dokter terverifikasi yang tersedia untuk telemedisin."
                            action={
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link to="/">Kembali ke beranda</Link>
                                </Button>
                            }
                        />
                    )
                ) : isPastLastPage(meta, rows.length) ? (
                    <EmptyState
                        title="Halaman ini kosong"
                        description={`Halaman ${String(meta?.current_page ?? page)} di luar jangkauan. Ada ${String(meta?.total ?? 0)} dokter yang cocok.`}
                        action={
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                                onClick={() => {
                                    ubahHalaman(1);
                                }}
                            >
                                Kembali ke halaman pertama
                            </Button>
                        }
                    />
                ) : (
                    <div
                        aria-busy={list.isFetching}
                        data-slot="dokter-hasil"
                        className={cn(
                            'flex flex-col gap-4 transition-opacity',
                            list.isFetching && 'opacity-60',
                        )}
                    >
                        <ul className="grid gap-4 md:grid-cols-2">
                            {rows.map((row) => (
                                <li key={row.id}>
                                    <DoctorCard
                                        id={row.id}
                                        ke={alamatDokter(row.id)}
                                        nama={row.nama_lengkap}
                                        tipe={row.tipe}
                                        spesialisasi={
                                            spesialisasiTerpilih ?? row.spesialisasi
                                        }
                                        biaya={row.biaya_konsultasi_online}
                                        rating={row.rating_rata_rata}
                                        konsultasi={row.jumlah_konsultasi}
                                        pengalaman={row.pengalaman_tahun}
                                        ulasan={row.jumlah_ulasan}
                                    />
                                </li>
                            ))}
                        </ul>

                        <Pagination
                            meta={meta}
                            onPageChange={ubahHalaman}
                            numbered
                        />
                    </div>
                )}
            </div>
        </section>
    );
}


/**
 * `Urutkan` - the one control in this feature that is not a constraint.
 *
 * It reorders a result set without choosing one, so it does not belong in the header's
 * panel (a menu is where you CHOOSE) and it never reaches the address (nobody shares
 * "Paling mahal" with a colleague). What it IS is the one arrangement the visitor owns,
 * which is why it sits beside the count: the number and the order are the two things a
 * reader needs before they look at a single card.
 *
 * `aria-label="Urutkan"` on the trigger and the `Urutkan` legend above it are deliberate
 * duplicates: a reader who hears the group first and a reader who meets the control first
 * are told the same thing either way.
 */
function UrutkanGrup({
    nilai,
    onUbah,
    nonaktif,
}: {
    nilai: SortDokter;
    onUbah: (nilai: SortDokter) => void;
    nonaktif: boolean;
}) {
    return (
        <fieldset className="flex flex-col gap-1.5" data-slot="dokter-urutkan-grup">
            <legend className="text-muted-foreground mb-1.5 text-xs font-semibold tracking-wide uppercase">
                Urutkan
            </legend>

            <Select
                value={nilai}
                disabled={nonaktif}
                onValueChange={(value) => {
                    onUbah(value as SortDokter);
                }}
            >
                {/**
                 * The `!` below is because the kit's own trigger carries
                 * `data-[size=default]:h-9`: an attribute variant is generated after a
                 * plain `h-11`, so the same specificity later in the sheet wins and the
                 * kit's 36px would silently take over. This is the only control that
                 * arranges the rows now, and F03 §8 asks for a 44px target on it.
                 */}
                <SelectTrigger
                    aria-label="Urutkan"
                    data-slot="dokter-urutkan"
                    aria-describedby={nonaktif ? 'dokter-alasan-offline' : undefined}
                    className="h-11! w-full md:w-56"
                >
                    <SelectValue />
                </SelectTrigger>

                <SelectContent>
                    {SORT_DOKTER.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </fieldset>
    );
}


/**
 * A 401 from a public endpoint is a real anomaly, not a routine "please sign in", so it
 * gets its own wording. Everything else keeps the server's own message, which is the only
 * copy that is actually specific to the failure.
 */
function asDirectoryError(error: unknown): unknown {
    if (error instanceof ApiError && error.isUnauthorized) {
        return new ApiError(
            error.status,
            'Direktori dokter seharusnya dapat diakses tanpa masuk. Pesan ini menunjukkan ada masalah pada konfigurasi akses.',
        );
    }

    return error;
}
