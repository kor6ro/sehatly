import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router';
import { LocateFixed } from 'lucide-react';
import { dokterOptions, spesialisasiOptions } from '@/lib/api/dokter';
import type { DokterFilters } from '@/lib/api/dokter';
import { DoctorCard } from '@/portals/pasien/components/dokter/doctor-card';
import { isEmptyPage } from '@/lib/api/pagination';
import { ApiError } from '@/lib/http';
import type { DokterRingkas } from '@/lib/api/types';
import { cn } from '@/lib/utils';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { OfflineBanner } from '@/shared/components/offline-banner';
import { SkeletonRows } from '@/shared/components/states/loading-state';
import { EmptyState } from '@/shared/components/states/empty-state';
import { ErrorState } from '@/shared/components/states/error-state';
import { Button } from '@/components/ui/button';
import { usePosisiPasien } from '@/portals/pasien/features/dokter/use-posisi-pasien';

/**
 * The answering body of the landing page: pick a specialisation in the header, and this
 * is what comes back - the nearest doctors who practise there, and where they practise.
 *
 * ## This is no longer a directory
 *
 * It used to be one: a filter panel's worth of controls, a sort, removable chips, a
 * paginated grid, and a search box - a second place to ask everything the header already
 * asks. The October 2026 redesign took all of it away, and the product owner's words for
 * it were that the directory was useless: a patient does not browse a catalogue, they pick
 * a specialisation and want to know who is near them.
 *
 * What is left is the answer and nothing else:
 *
 * | kept | because |
 * | --- | --- |
 * | the picked specialisation, as a heading | it is the question, and a result set with no visible question is a number nobody can check |
 * | the distance and the venue | the whole point of the redesign |
 * | the count, and whether it is complete | a list that stops at twenty says so |
 * | offline, loading, empty and error states | they were true before and are still true |
 *
 * | removed | because |
 * | --- | --- |
 * | `Urutkan` | distance is the only order that answers "nearest"; five other orderings of a proximity question are five ways to be wrong |
 * | filter chips and `Hapus semua filter` | the one filter is a heading, and the way out is the header or the breadcrumb |
 * | pagination | the nearest ones are the ones that matter, so the page shows a bounded top-N and says what it is doing |
 * | `?tipe=`, `?telemedisin=` | nothing offers them any more, and a filter nobody can see or remove is a trap rather than a feature |
 *
 * ## It never blocks on the geolocation prompt
 *
 * The position is read by {@link usePosisiPasien}. While the browser is working it out
 * this renders skeletons rather than a list that will re-sort itself a second later under
 * the reader's eye; when the answer is "no", the list appears immediately in the default
 * order with one honest line saying so. **No position is ever invented to fill the gap.**
 *
 * ## F03 §9: the search is state, never a URL
 *
 * A free-text doctor query may be a condition, and a URL is pasted into chats, so the term
 * typed into the header's bar lives in `sessionStorage` and never in the address:
 * `serahkanKueri` parks it before navigating and this component consumes it on mount. It
 * survives a RELOAD - the one case AC-1 asks it to survive - and is printed in the count
 * line rather than offered as a chip, because a term the reader cannot see is a term they
 * cannot check.
 *
 * ## It is still public
 *
 * `DokterController` states it directly: the directory is public, and putting
 * `permission:dokter.lihat` on it would answer 401 to every anonymous visitor because
 * `EnsurePermission` needs an authenticated principal. Nothing about the address changes
 * that, so this renders for a signed-out visitor exactly as it always has.
 */

/**
 * How many doctors one screen shows.
 *
 * There is no pagination on this screen, so this is a ceiling rather than a page size -
 * and it is a ceiling the screen STATES rather than hides. A specialisation with forty
 * doctors shows the twenty nearest and says `Menampilkan 20 terdekat dari 40`, because a
 * list that silently stops is worse than one that is short on purpose.
 *
 * Twenty is the server's comfortable page and roughly a screen and a half of cards: far
 * enough down the list that "not shown" means "further away", short enough that the page
 * still scrolls like a list rather than like a database.
 */
const BATAS_TAMPIL = 20;

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
 * this is already on the screen - and a mounted component never re-runs the `useState`
 * initializer that first read the marker. Storage is the payload, this is the knock; the
 * mount below also knocks once for itself, which is how a submit from a screen this is not
 * on still arrives.
 */
const EVENT_ANTAR = 'sehatly:dokter:kueri';

/**
 * The query a visitor typed survives a RELOAD of the address they typed it on - and
 * nothing else brings it back.
 *
 * AC-1 asks for exactly that: F03 §9 keeps the free-text query out of the URL, so a reload
 * would otherwise arrive with no memory of the question. But this address is opened with
 * several intents - the header's `Dokter Terdekat`, a specialisation, an empty search -
 * and restoring the query in every case would answer "show me everyone" with a filter the
 * visitor cannot see.
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
 * mounted when the bar finishes.
 *
 * `''` is a value here, not an absence. An empty submit in the bar means "everything", and
 * it has to be able to say so.
 */
export function serahkanKueri(kueri: string): void {
    try {
        window.sessionStorage.setItem(SIMPANAN_ANTAR, kueri);
    } catch {
        // Storage refused: the answer still opens, it simply arrives without the question
        // the bar was carrying.
    }

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
            // Same as above: failing to clear costs only the one-shot guarantee.
        }
    }

    return kueri;
}

/**
 * Does this address carry a question for this component to answer?
 *
 * `LandingPage` asks this before it renders anything, because this and the seven marketing
 * sections are two answers to "what does this visitor want", and the two must never be on
 * screen together.
 *
 * `?tipe=` and `?telemedisin=` are deliberately NOT here any more. Nothing writes them,
 * nothing offers them, and this component no longer applies them: an address carrying one
 * is a link from before the redesign, and showing the marketing page for it is more honest
 * than showing a filtered list whose filter has no face and no way out.
 */
export function adaPertanyaanDirektori(params: URLSearchParams): boolean {
    return (
        params.has('spesialisasi') ||
        params.has('semua') ||
        params.has('search') ||
        // A bar search PEEKS here and is only spent inside this component, so a decision
        // that never looked at the marker would leave the answer unmounted and the seven
        // marketing sections up in its place.
        bacaKueriAntar() !== null ||
        bacaKueriTersimpan() !== ''
    );
}

export function DirektoriDokter() {
    const online = useOnlineStatus();
    const nonaktif = !online;

    /**
     * The query, seeded ONCE from whichever voice is speaking: the header's bar first,
     * then `?search=` (written by older versions of this app, still read so those links
     * arrive filtered), then nothing - unless this is a RELOAD of an address a query was
     * typed on.
     *
     * It seeds both halves of the state on purpose: the line a visitor reads and the
     * request the page fires must carry the same question, or the screen lies about what
     * it asked.
     */
    const [searchParams] = useSearchParams();
    const [kueriAwal] = useState(
        () =>
            bacaKueriAntar() ??
            searchParams.get('search') ??
            bacaKueriTersimpan(),
    );
    const [kueri, setKueri] = useState(kueriAwal);

    /**
     * The position, and the single decision it drives: whether `lat`/`lng` travel with the
     * request. Everything else on this screen is the same with and without it.
     */
    const posisi = usePosisiPasien();
    const adaPosisi = posisi.status === 'aktif' && posisi.lat !== null && posisi.lng !== null;

    /**
     * Taking delivery of the bar's handoff.
     *
     * It SUBSCRIBES as well as reads. The bar is above this answer, so most submits happen
     * while this is already on the screen, and a mounted component never runs its
     * initializer a second time: without the listener the term would sit in storage while
     * the list underneath kept answering the question asked before typing it.
     */
    useEffect(() => {
        /**
         * PEEK, never spend. `LandingPage` reads the same marker during RENDER to decide
         * whether this address is a question or the front page, and a listener that ate
         * the key would unmount this very component between the bar's submit and the
         * navigation that follows it.
         */
        const terima = (): void => {
            const dariTangan = bacaKueriAntar();

            if (dariTangan !== null) {
                simpanKueriTersimpan(dariTangan);
                setKueri(dariTangan);
            }
        };

        terima();

        window.addEventListener(EVENT_ANTAR, terima);

        return () => window.removeEventListener(EVENT_ANTAR, terima);
    }, []);

    /**
     * Spending the marker, deliberately outside the listener above and outside the render
     * that needs it. Both exits are covered - a new address, and this component going
     * away - so a marker cannot outlive the component that consumed it.
     */
    useEffect(() => {
        habiskanKueriAntar();

        return () => {
            habiskanKueriAntar();
        };
    }, [searchParams]);

    /**
     * The one filter, straight out of the address so a shared link hands over the same
     * question that was being looked at. An unknown `kode` is left in place: the API
     * answers an empty page for it, which is more informative than arriving unfiltered.
     */
    const spesialisasi = searchParams.get('spesialisasi') ?? undefined;

    const spesialisasiList = useQuery(spesialisasiOptions());
    const daftarSpesialisasi = spesialisasiList.data?.data.spesialisasi ?? [];

    /**
     * The specialisation spelled out - the heading, and what every card under it prints
     * instead of the doctor's whole list.
     *
     * Scoping the card's label to the question matters more here than it did in the
     * directory: `Dokter Fixture Dua` really does hold two rows, Anak and Kulit & Kelamin,
     * and under a heading that says Spesialis Anak a card printing both reads as a filter
     * that did not take. Nothing is hidden - the second row is still on the profile, one
     * card away.
     */
    const spesialisasiTerpilih =
        spesialisasi === undefined
            ? null
            : (daftarSpesialisasi.find((row) => row.kode === spesialisasi)?.nama ??
              spesialisasi);

    const filters = useMemo<DokterFilters>(
        () => ({
            page: 1,
            per_page: BATAS_TAMPIL,
            ...(spesialisasi === undefined ? {} : { spesialisasi }),
            ...(kueri === '' ? {} : { search: kueri }),
            // The position and the sort that needs it are one thing: with no position the
            // request asks for nothing special and the server returns its default order,
            // which is exactly what the honest line above the list says is happening.
            ...(adaPosisi
                ? { sort: 'terdekat' as const, lat: posisi.lat!, lng: posisi.lng! }
                : {}),
        }),
        [spesialisasi, kueri, adaPosisi, posisi.lat, posisi.lng],
    );

    /**
     * The request, held until the position question is answered.
     *
     * `enabled` is the whole mechanism behind "the list does not re-sort itself under the
     * reader's eye". Without it the query fires on mount with no `lat`/`lng`, paints the
     * default order a moment later, and then swaps in the distance order when the fix
     * arrives - a list that rearranges itself while somebody is reading it. Held back
     * instead, the only order anybody ever sees is the final one.
     *
     * The states that release it are all of them final: a grant, a refusal, a failure, and
     * a browser with no `geolocation` at all. Only `meminta` holds it, and that is the one
     * state the watcher in `usePosisiPasien` exists to guarantee is temporary.
     */
    const list = useQuery({
        ...dokterOptions(filters),
        enabled: posisi.status !== 'meminta',
    });

    const rows = list.data?.data.dokter ?? [];
    const meta = list.data?.meta;

    /**
     * Split by whether the doctor has a place to be nearest.
     *
     * The server already sorts the unmeasurable rows last under `?sort=terdekat`, so this
     * is a grouping and not a re-sort - it never moves a row past another row that came
     * before it. It happens with no position too, and that is deliberate: "who practises
     * where" is the question being asked whether or not the answer knows how far away.
     */
    const { bertempat, daring } = useMemo(() => {
        const bertempat: DokterRingkas[] = [];
        const daring: DokterRingkas[] = [];

        for (const row of rows) {
            (row.faskes_utama === null ? daring : bertempat).push(row);
        }

        return { bertempat, daring };
    }, [rows]);

    const jumlah = meta?.total;
    /**
     * One flag for "the number is not known yet", so the count sentence cannot be spelled
     * differently by the live region and by the emphasis on its number.
     */
    const menghitung = list.isFetching || jumlah === undefined;

    /**
     * The line under the heading, and it has to carry three facts at once: how many, what
     * was asked, and whether the order is a real one. A count with no stated cause is a
     * number nobody can check, and a "nearest" list that quietly is not would be worse
     * than no distance at all.
     */
    const penjelasan = (() => {
        if (menghitung) {
            return 'Menghitung hasil…';
        }

        const berapa = `${String(jumlah)} dokter`;

        const karena =
            kueri === '' ? '' : ` untuk "${kueri}"`;

        /**
         * The state of the ORDER, not a claim about how much is shown.
         *
         * This used to read `menampilkan semua`, which is true only while `meta.total` is
         * at most `BATAS_TAMPIL`: with forty-three matches the screen would have printed
         * "showing all" on one line and `Menampilkan 20 dari 43.` on the next. The order
         * is the thing the position actually decides, so that is what this says - and
         * whether the list is complete is the next line's job, not this one's.
         */
        const urutan = adaPosisi
            ? ' · diurutkan menurut jarak dari lokasimu'
            : ' · lokasi tidak aktif — tanpa urutan jarak';

        return `${berapa}${karena}${urutan}`;
    })();

    /**
     * Whether the list is showing all of it.
     *
     * `meta.total` counts every matching doctor, and the request asked for
     * {@link BATAS_TAMPIL}, so a larger total means rows were held back - and the screen
     * says which ones were kept by naming the ordering that chose them. Held back for
     * being further away is an honest reason; held back for being on another page would
     * have needed a page control, and there is not one any more.
     */
    const terpotong = !menghitung && jumlah !== undefined && jumlah > rows.length;

    const judulDipilih = spesialisasiTerpilih !== null;

    /**
     * No focus ever moves to the count line, and that is a deliberate absence.
     *
     * The old control focused it after a page change, so a keyboard reader who had just
     * pressed "next" landed on the sentence describing where they had arrived. There are
     * no pages any more, so the only reason left would be the first paint - and moving
     * focus on load scrolls the page and takes the caret out of the address bar, for a
     * sentence the `aria-live` below already announces by itself.
     */
    function mintaLokasi(): void {
        if (nonaktif) {
            return;
        }

        posisi.minta();
    }

    return (
        <section
            data-slot="landing-direktori"
            aria-labelledby={judulDipilih ? 'landing-direktori-judul' : undefined}
            aria-label={judulDipilih ? undefined : 'Dokter terdekat'}
            className="mx-auto flex w-full max-w-[1280px] flex-col gap-5 px-4 pt-6 pb-16 md:px-6"
        >
            {judulDipilih ? (
                <h1
                    id="landing-direktori-judul"
                    className="text-foreground text-2xl font-semibold tracking-tight"
                >
                    {spesialisasiTerpilih}
                </h1>
            ) : null}

            {/**
             * The wrapper carries the id the offline controls point at through
             * `aria-describedby`. `OfflineBanner` renders `null` while online, so the
             * wrapper is empty then and the attribute is only set offline.
             */}
            <div id="dokter-alasan-offline">
                <OfflineBanner message="Anda sedang offline. Pencarian dan lokasi tidak dikirim sampai koneksi kembali." />
            </div>

            <div className="flex min-w-0 flex-col gap-4">
                {/**
                 * What am I looking at, and in what order - one line, because the two
                 * answers belong together. There is no `Urutkan` beside it: distance is
                 * the only order that answers a proximity question, and the five other
                 * orderings the old control offered were five ways to answer a different
                 * one.
                 */}
                <div className="border-border flex flex-wrap items-center justify-between gap-3 border-b pb-4">
                    {list.isError ? null : (
                        <p
                            role="status"
                            aria-live="polite"
                            data-slot="dokter-count"
                            className="text-muted-foreground text-sm"
                        >
                            {penjelasan}
                        </p>
                    )}

                    {/**
                     * The way back from a refusal, and the only control left on this
                     * screen. `aria-disabled` rather than `disabled`, following the
                     * banner above: a natively disabled button cannot receive focus and
                     * so can never be asked why it does nothing.
                     */}
                    {posisi.status === 'meminta' || adaPosisi ? null : (
                        <Button
                            type="button"
                            variant="outline"
                            className="ml-auto min-h-11"
                            data-slot="dokter-minta-lokasi"
                            aria-disabled={nonaktif}
                            aria-describedby={
                                nonaktif ? 'dokter-alasan-offline' : undefined
                            }
                            onClick={mintaLokasi}
                        >
                            <LocateFixed aria-hidden className="size-4" />

                            {posisi.status === 'ditolak'
                                ? 'Aktifkan lokasi'
                                : posisi.status === 'tidak-sedia'
                                  ? 'Lokasi tidak tersedia'
                                  : 'Coba lagi'}
                        </Button>
                    )}
                </div>

                {/**
                 * The position is being read. Skeletons rather than a list: the sort that
                 * is coming would rearrange rows the reader had already started reading,
                 * and a short wait beats a list that moves under the eye.
                 */}
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
                    <EmptyState
                        title={
                            kueri !== ''
                                ? 'Tidak ada dokter dengan nama itu.'
                                : judulDipilih
                                  ? `Belum ada dokter ${spesialisasiTerpilih}.`
                                  : 'Belum ada dokter yang memenuhi syarat untuk tampil.'
                        }
                        description={
                            kueri !== ''
                                ? 'Coba nama lain, atau lihat seluruh dokter terdekat.'
                                : 'Dokter publik harus terverifikasi dan STR-nya masih berlaku.'
                        }
                        action={
                            <div className="flex flex-wrap justify-center gap-2">
                                {kueri === '' ? null : (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="min-h-11"
                                        aria-disabled={nonaktif}
                                        aria-describedby={
                                            nonaktif ? 'dokter-alasan-offline' : undefined
                                        }
                                        onClick={() => {
                                            setKueri('');
                                            simpanKueriTersimpan('');
                                        }}
                                    >
                                        Hapus pencarian
                                    </Button>
                                )}

                                <Button asChild variant="outline" className="min-h-11">
                                    <Link
                                        to="/?semua=1"
                                        /**
                                         * The link changes the address; it does NOT change
                                         * `kueri`, which is component state and only the
                                         * button above resets it. A door labelled "Lihat
                                         * dokter terdekat" that re-rendered the same zero
                                         * rows behind a new query string would be a door
                                         * into the same room, so both halves of the
                                         * recovery happen together: the address goes to
                                         * "nothing applied" and the question goes with it.
                                         */
                                        onClick={() => {
                                            setKueri('');
                                            simpanKueriTersimpan('');
                                        }}
                                    >
                                        Lihat dokter terdekat
                                    </Link>
                                </Button>
                            </div>
                        }
                    />
                ) : (
                    <div
                        aria-busy={list.isFetching}
                        data-slot="dokter-hasil"
                        className={cn(
                            'flex flex-col gap-6 transition-opacity',
                            list.isFetching && 'opacity-60',
                        )}
                    >
                        {/**
                         * What is held back, stated once above the list rather than left to
                         * be discovered by scrolling to a page that is not there.
                         */}
                        {terpotong ? (
                            <p className="text-muted-foreground text-sm" data-slot="dokter-terpotong">
                                {adaPosisi
                                    ? `Menampilkan ${String(rows.length)} terdekat dari ${String(jumlah)}.`
                                    : `Menampilkan ${String(rows.length)} dari ${String(jumlah)}.`}
                            </p>
                        ) : null}

                        {/** The doctors who have a place to be nearest - and, when the position is known, the nearest ones first. */}
                        {bertempat.length === 0 ? null : (
                            <ul className="grid gap-4 md:grid-cols-2">
                                {bertempat.map((row) => (
                                    <li key={row.id}>
                                        <DoctorCard
                                            id={row.id}
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
                                            faskes={row.faskes_utama}
                                            jarak={row.jarak_km}
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}

                        {/**
                         * The doctors with no `dokter_faskes` row, in their own group under
                         * a name that says what they are.
                         *
                         * They cannot be "nearest" - they have no place to be measured
                         * from - so folding them into the list above would put an
                         * unmeasurable row among measured ones with no way to tell them
                         * apart. Hiding them instead would remove real, verified,
                         * consultable doctors from a patient's options for the crime of
                         * practising by chat.
                         */}
                        {daring.length === 0 ? null : (
                            <div className="flex flex-col gap-4">
                                <div>
                                    <h2 className="text-foreground text-base font-semibold">
                                        Bisa jarak jauh
                                    </h2>

                                    <p className="text-muted-foreground text-sm">
                                        Konsultasi lewat chat dan video, tanpa tempat praktik
                                        yang bisa dituju.
                                    </p>
                                </div>

                                <ul className="grid gap-4 md:grid-cols-2">
                                    {daring.map((row) => (
                                        <li key={row.id}>
                                            <DoctorCard
                                                id={row.id}
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
                                                faskes={null}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </section>
    );
}

/**
 * A 401 from this endpoint is an anomaly rather than a "please sign in".
 *
 * `DokterController` documents why the route carries no permission gate, so a 401 means the
 * session layer answered where the public directory should have - worth its own sentence
 * rather than the generic connection error.
 */
function asDirectoryError(error: unknown): Error | string {
    if (error instanceof ApiError && error.status === 401) {
        return 'Sesi tidak dikenali. Muat ulang halaman; direktori ini seharusnya terbuka tanpa masuk.';
    }

    return error instanceof Error ? error.message : 'Terjadi kesalahan.';
}
