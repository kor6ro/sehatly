import {
    useEffect,
    useMemo,
    useRef,
    useState,
    type FormEvent,
    type ReactNode,
    type RefObject,
} from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { Check, RefreshCw, Search, SlidersHorizontal, X } from 'lucide-react';
import {
    cariMasterPopuler,
    DEFAULT_SORT,
    dokterCountOptions,
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
import { Card, CardContent } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Field, FieldInput } from '@/components/form/field';

/**
 * The public doctor directory - the whole of the screen that used to live at `/dokter`,
 * now printed INSIDE the landing page by `DirektoriSection`.
 *
 * ## Why it moved, and why nothing was trimmed on the way
 *
 * A visitor who types a doctor's name, or picks "Dokter Gigi" out of the header's rail, has
 * asked ONE question. Sending them to a separate page answered it by handing them a new
 * document, a new title, and a Back button to remember - so the page is gone and its
 * CONTENT is this component: the search field, the type/biaya/rating filters, the sort, the
 * count, the cards, the pagination, and every offline, empty and error state they were
 * built with. This is not a reduced copy of that screen; it IS that screen, moved. The only
 * thing left behind is the page chrome - `PageHeader` and the document title - because a
 * heading inside the home page belongs to the home page, and `DirektoriSection` supplies it.
 *
 * ## Why it is still public
 *
 * `DokterController` states it directly: the directory is public, and putting
 * `permission:dokter.lihat` on it would answer 401 to every anonymous visitor because
 * `EnsurePermission` needs an authenticated principal - the opposite of what a
 * pre-authentication browse page needs. It would also lock out `perawat` and `kurir`, which
 * are real `users.tipe` values holding no role. Living on the landing page changes none of
 * that: the section renders for a signed-out visitor exactly as the page did, which is also
 * what keeps the 404 behaviour demonstrable with no session involved at all.
 *
 * ## F03: the search is state, never a URL
 *
 * `web/ux/patterns/F03.md` §9 forbids a free-text doctor search in a shareable URL,
 * because "kanker" is a condition and a URL is copied into chats. The query typed INSIDE
 * the directory therefore lives in `sessionStorage`, and comes back only on a RELOAD of
 * the address it was typed on - the one case AC-1 asks it to survive. It is no longer the
 * only voice: a search made in the header's bar does put `?search=` in the address, and
 * `?direktori=semua` means "everything", so `bacaKueriTersimpan` stays out of the way
 * whenever the URL is speaking. Filters stay in component state (the §12 #5
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
const SEARCH_MAX = 150;
const SIMPANAN_KUERI = 'sehatly.dokter.kueri';

type PilihanFilter = {
    spesialisasi: string | undefined;
    tipe: DokterTipe | undefined;
    telemedisin: boolean;
    sort: SortDokter;
};

const PILIHAN_KOSONG: PilihanFilter = {
    spesialisasi: undefined,
    tipe: undefined,
    telemedisin: false,
    sort: DEFAULT_SORT,
};

/**
 * The query a visitor typed survives a RELOAD of the address they typed it on - and
 * nothing else brings it back.
 *
 * AC-1 asks for exactly that: F03 §9 keeps the free-text query out of the URL, so a
 * reload would otherwise arrive with no memory of the question. But the directory now
 * lives on the landing page, where one address is opened with several intents: "Lihat
 * semua dokter", the drawer's entry and an empty search all arrive at `/?direktori=semua`,
 * and so does the reload of a search made there. Restoring the query in every case would
 * answer "show me everything" with a filter the visitor cannot see. A reload is the one
 * moment where they are asking for the state they just had; a fresh load of an address
 * may be asking for anything, and the URL - which now carries `?search=` when a search
 * came from the bar - is the only thing allowed to speak for the rest.
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

export function DirektoriDokter() {
    const online = useOnlineStatus();

    const [page, setPage] = useState(1);
    const [search, setSearch] = useState(() => bacaKueriTersimpan());
    const [submittedSearch, setSubmittedSearch] = useState(() => bacaKueriTersimpan());
    /**
     * Seeded from `?spesialisasi=` so a link from the landing page's specialisation
     * tiles opens the directory ALREADY filtered, instead of promising a filter it
     * silently drops. It is a one-shot read: once the state exists, clearing the chip
     * inside the page is what changes it - the URL is an entry point, not a second
     * source of truth for the filter. An unknown `kode` is left in place on purpose: the
     * API answers an empty page for it and the chip above the results can be removed,
     * which is a better outcome than arriving with no filter at all.
     */
    const [searchParams] = useSearchParams();
    const [spesialisasi, setSpesialisasi] = useState<string | undefined>(
        () => searchParams.get('spesialisasi') ?? undefined,
    );
    const [tipe, setTipe] = useState<DokterTipe | undefined>(undefined);
    const [telemedisinOnly, setTelemedisinOnly] = useState(false);
    const [sort, setSort] = useState<SortDokter>(DEFAULT_SORT);

    const [sheetOpen, setSheetOpen] = useState(false);
    const [draft, setDraft] = useState<PilihanFilter>(PILIHAN_KOSONG);

    /**
     * `?search=` from OUTSIDE the page: the landing header's search field navigates here
     * with the query in the URL, for the same reason `?spesialisasi=` arrives in it - the
     * URL states what the page is showing, so Back returns to the search that produced it
     * and a reload does not silently revert to whatever sessionStorage last held.
     *
     * It wins over the stored value when both exist, and it re-runs only when the URL
     * value CHANGES: clearing the box inside the page is untouched by it, because the
     * effect keys on the parameter rather than on the state it writes. A visit with no
     * parameter is a no-op, which is what keeps the pre-existing sessionStorage handoff
     * exactly as it was.
     */
    const kueriUrl = searchParams.get('search');

    useEffect(() => {
        if (kueriUrl === null) {
            return;
        }

        setSearch(kueriUrl);
        setSubmittedSearch(kueriUrl);
        simpanKueriTersimpan(kueriUrl);
        resetToFirstPage();
    }, [kueriUrl]);

    const hasilRef = useRef<HTMLParagraphElement | null>(null);
    const pemicuFilterMobileRef = useRef<HTMLButtonElement | null>(null);
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

    const chipAktif: ReadonlyArray<{
        kunci: 'spesialisasi' | 'tipe' | 'telemedisin';
        label: string;
    }> = (() => {
        const hasil: Array<{
            kunci: 'spesialisasi' | 'tipe' | 'telemedisin';
            label: string;
        }> = [];

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
    const teksJumlah =
        list.isFetching || jumlah === undefined
            ? 'Menghitung hasil…'
            : `${jumlah} dokter ditemukan.`;

    /**
     * Sort is deliberately NOT part of this comparison. It does not change `meta.total`,
     * so re-running the count query for it would be a second request that can only return
     * the number already on screen - and F03 AC-5 asks a sort change to cost exactly one
     * request. The draft count below still carries the draft sort, so the number belongs to
     * the query the apply button will send.
     */
    const draftBerubah =
        draft.spesialisasi !== spesialisasi ||
        draft.tipe !== tipe ||
        draft.telemedisin !== telemedisinOnly;

    const filtersDraft = useMemo<DokterFilters>(
        () => ({
            page: 1,
            per_page: 1,
            ...(draft.spesialisasi === undefined
                ? {}
                : { spesialisasi: draft.spesialisasi }),
            ...(draft.tipe === undefined ? {} : { tipe: draft.tipe }),
            ...(submittedSearch === '' ? {} : { search: submittedSearch }),
            ...(draft.telemedisin ? { tersedia_telemedisin: true } : {}),
            sort: draft.sort,
        }),
        [draft, submittedSearch],
    );

    const hitungDraft = useQuery({
        ...dokterCountOptions(filtersDraft),
        enabled: sheetOpen && online && draftBerubah,
    });

    const jumlahDraft = draftBerubah
        ? hitungDraft.data?.meta?.total
        : meta?.total;
    const sedangMenghitungDraft = draftBerubah
        ? hitungDraft.isFetching
        : list.isFetching;
    const teksTampilkan =
        sedangMenghitungDraft || jumlahDraft === undefined
            ? 'Menghitung hasil…'
            : `Tampilkan ${jumlahDraft} hasil`;

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

    function kirimPencarian(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();

        if (!online) {
            return;
        }

        const bersih = search.trim();

        simpanKueriTersimpan(bersih);
        setSearch(bersih);
        setSubmittedSearch(bersih);
        resetToFirstPage();
    }

    function hapusPencarian(): void {
        if (!online) {
            return;
        }

        setSearch('');
        simpanKueriTersimpan('');
        setSubmittedSearch('');
        resetToFirstPage();
    }

    function hapusChip(kunci: 'spesialisasi' | 'tipe' | 'telemedisin'): void {
        if (!online) {
            return;
        }

        if (kunci === 'spesialisasi') {
            setSpesialisasi(undefined);
        }

        if (kunci === 'tipe') {
            setTipe(undefined);
        }

        if (kunci === 'telemedisin') {
            setTelemedisinOnly(false);
        }

        resetToFirstPage();
    }

    function hapusSemuaFilter(): void {
        if (!online) {
            return;
        }

        setSearch('');
        simpanKueriTersimpan('');
        setSubmittedSearch('');
        setSpesialisasi(undefined);
        setTipe(undefined);
        setTelemedisinOnly(false);
        setSort(DEFAULT_SORT);
        setDraft(PILIHAN_KOSONG);
        setSheetOpen(false);
        resetToFirstPage();
    }

    function pilihPopuler(master: Spesialisasi): void {
        if (!online) {
            return;
        }

        setSearch('');
        simpanKueriTersimpan('');
        setSubmittedSearch('');
        setSpesialisasi(master.kode);
        setTipe(undefined);
        setTelemedisinOnly(false);
        setDraft({ ...PILIHAN_KOSONG, sort });
        setSheetOpen(false);
        resetToFirstPage();
    }

    function bukaSheet(): void {
        if (!online) {
            return;
        }

        setDraft({
            spesialisasi,
            tipe,
            telemedisin: telemedisinOnly,
            sort,
        });
        setSheetOpen(true);
    }

    function terapkanDraft(): void {
        if (!online) {
            return;
        }

        setSpesialisasi(draft.spesialisasi);
        setTipe(draft.tipe);
        setTelemedisinOnly(draft.telemedisin);
        setSort(draft.sort);
        setSheetOpen(false);
        resetToFirstPage();
    }

    const nonaktif = !online;

    return (
        <>
            {/**
             * The wrapper carries the id the offline controls point at through
             * `aria-describedby`. `OfflineBanner` renders `null` while online, so the
             * wrapper is empty then and the attribute is only set offline.
             */}
            <div id="dokter-alasan-offline">
                <OfflineBanner message="Anda sedang offline. Pencarian dan filter tidak dikirim sampai koneksi kembali." />
            </div>

            <Card>
                <CardContent className="flex flex-col gap-3">
                    <form
                        onSubmit={kirimPencarian}
                        className="flex flex-col gap-3"
                        data-slot="dokter-form-cari"
                    >
                        <Field
                            label="Cari dokter"
                            hint="Cari berdasarkan nama. Untuk spesialisasi, pilih filter di bawah."
                            className="max-w-xl"
                        >
                            <div className="flex items-center gap-2">
                                <div className="relative min-w-0 flex-1">
                                    <FieldInput
                                        value={search}
                                        autoComplete="off"
                                        maxLength={SEARCH_MAX}
                                        placeholder="Nama dokter (contoh: dr. Rina)"
                                        className="h-11 pr-11"
                                        data-slot="dokter-cari"
                                        onChange={(event) => {
                                            setSearch(event.target.value);
                                        }}
                                    />

                                    {search === '' ? null : (
                                        <button
                                            type="button"
                                            aria-label="Hapus pencarian"
                                            onClick={hapusPencarian}
                                            className="text-muted-foreground hover:text-foreground focus-visible:ring-ring absolute inset-y-0 right-0 flex min-h-11 min-w-11 items-center justify-center rounded-md focus-visible:ring-2"
                                        >
                                            <X aria-hidden className="size-4" />
                                        </button>
                                    )}
                                </div>

                                <Button
                                    type="submit"
                                    className="h-11"
                                    data-slot="dokter-cari-submit"
                                    aria-disabled={nonaktif}
                                    aria-describedby={
                                        nonaktif ? 'dokter-alasan-offline' : undefined
                                    }
                                >
                                    <Search aria-hidden />
                                    Cari
                                </Button>
                            </div>
                        </Field>
                    </form>

                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-muted-foreground text-sm">
                            Sering dicari:
                        </span>

                        {POPULER.map((pintasan) => {
                            const master = cariMasterPopuler(
                                daftarSpesialisasi,
                                pintasan.kataKunci,
                            );
                            const mati = master === undefined || nonaktif;

                            return (
                                <Button
                                    key={pintasan.label}
                                    type="button"
                                    variant="secondary"
                                    className="h-11"
                                    data-slot="dokter-pintasan"
                                    disabled={mati}
                                    aria-disabled={mati}
                                    onClick={() => {
                                        if (master !== undefined) {
                                            pilihPopuler(master);
                                        }
                                    }}
                                >
                                    {pintasan.label}
                                </Button>
                            );
                        })}
                    </div>
                </CardContent>
            </Card>

            <div className="grid items-start gap-6 md:grid-cols-[17rem_minmax(0,1fr)]">
                <aside
                    aria-label="Filter dokter"
                    data-slot="dokter-panel-filter"
                    className="hidden md:block"
                >
                    <div className="bg-card md:sticky md:top-4 flex flex-col gap-4 rounded-lg border p-4">
                        <h2 className="text-base font-semibold">Filter</h2>

                        <FilterIsi
                            idPrefix="panel"
                            nilai={{ spesialisasi, tipe, telemedisin: telemedisinOnly, sort }}
                            onUbah={(next) => {
                                if (nonaktif) {
                                    return;
                                }

                                setSpesialisasi(next.spesialisasi);
                                setTipe(next.tipe);
                                setTelemedisinOnly(next.telemedisin);
                                setSort(next.sort);
                                resetToFirstPage();
                            }}
                            nonaktif={nonaktif}
                            statusSpesialisasi={spesialisasiList.status}
                            barisSpesialisasi={daftarSpesialisasi}
                            onMuatUlangSpesialisasi={() => {
                                void spesialisasiList.refetch();
                            }}
                        />
                    </div>
                </aside>

                <div className="flex min-w-0 flex-col gap-3">
                    <div className="border-border bg-background sticky top-2 z-20 -mx-1 flex flex-wrap items-center gap-2 border-b px-1 py-2 md:hidden">
                        <Button
                            ref={pemicuFilterMobileRef}
                            type="button"
                            variant="outline"
                            className="h-11"
                            data-slot="dokter-filter-button"
                            aria-disabled={nonaktif}
                            aria-describedby={
                                nonaktif ? 'dokter-alasan-offline' : undefined
                            }
                            onClick={bukaSheet}
                        >
                            <SlidersHorizontal aria-hidden />
                            {chipAktif.length === 0
                                ? 'Filter'
                                : `Filter · ${String(chipAktif.length)}`}
                        </Button>
                    </div>

                    {list.isError ? null : (
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p
                                ref={hasilRef}
                                tabIndex={-1}
                                role="status"
                                aria-live="polite"
                                data-slot="dokter-count"
                                className="focus-visible:ring-ring rounded-md text-sm font-medium focus-visible:ring-2"
                            >
                                {teksJumlah}
                            </p>
                        </div>
                    )}

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
                                    className="min-h-11"
                                    data-slot="dokter-chip"
                                    aria-label={`Hapus filter ${chip.label}`}
                                    aria-disabled={nonaktif}
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
                                className="min-h-11"
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

                            <Pagination
                                meta={meta}
                                onPageChange={ubahHalaman}
                                numbered
                            />
                        </div>
                    )}
                </div>
            </div>

            <PanelFilterMobile
                open={sheetOpen}
                onOpenChange={(buka) => {
                    setSheetOpen(buka);
                }}
                pemicuRef={pemicuFilterMobileRef}
                footer={
                    <div className="flex flex-col gap-2">
                        <Button
                            type="button"
                            className="h-11 w-full"
                            aria-disabled={nonaktif}
                            disabled={
                                nonaktif || teksTampilkan === 'Menghitung hasil…'
                            }
                            onClick={terapkanDraft}
                        >
                            {teksTampilkan}
                        </Button>

                        <Button
                            type="button"
                            variant="ghost"
                            className="h-11 w-full"
                            aria-disabled={nonaktif}
                            onClick={hapusSemuaFilter}
                        >
                            Hapus semua
                        </Button>
                    </div>
                }
            >
                <FilterIsi
                    idPrefix="sheet"
                    nilai={draft}
                    onUbah={(next) => {
                        setDraft(next);
                    }}
                    nonaktif={nonaktif}
                    statusSpesialisasi={spesialisasiList.status}
                    barisSpesialisasi={daftarSpesialisasi}
                    onMuatUlangSpesialisasi={() => {
                        void spesialisasiList.refetch();
                    }}
                />
            </PanelFilterMobile>
        </>
    );
}

/**
 * The filter body, shared verbatim by the desktop sidebar and the mobile sheet so the two
 * can never drift about which options exist.
 *
 * Each group is a `fieldset` with a `legend`, and each constraint option is a real radio or
 * checkbox: `GET /dokter` accepts one `spesialisasi` and one `tipe` value, so a multi-select
 * checkbox group would promise an OR the endpoint cannot perform. `Layanan` is currently a
 * single `tersedia_telemedisin` checkbox because the backend has no `tipe_layanan` filter
 * yet - the pattern's "Kunjungan klinik" option is deferred with it.
 *
 * `Urutkan` is the one non-constraint group, so it is the kit's `Select` rather than a
 * radio list: it changes neither the result set nor the chip row, only the order. It sits in
 * the shared body so the desktop panel applies it live and the mobile sheet drafts it with
 * the other values, which is what keeps the sheet's apply flow at three taps.
 */
function FilterIsi({
    nilai,
    onUbah,
    idPrefix,
    nonaktif,
    statusSpesialisasi,
    barisSpesialisasi,
    onMuatUlangSpesialisasi,
}: {
    nilai: PilihanFilter;
    onUbah: (next: PilihanFilter) => void;
    idPrefix: string;
    nonaktif: boolean;
    statusSpesialisasi: 'pending' | 'error' | 'success';
    barisSpesialisasi: ReadonlyArray<Spesialisasi>;
    onMuatUlangSpesialisasi: () => void;
}) {
    return (
        <div className="flex flex-col gap-5">
            <fieldset className="flex flex-col gap-0.5">
                <legend className="mb-1 text-sm font-semibold">Spesialisasi</legend>

                {statusSpesialisasi === 'pending' ? (
                    <p className="text-muted-foreground px-2 text-sm">
                        Memuat spesialisasi…
                    </p>
                ) : null}

                {statusSpesialisasi === 'error' ? (
                    <div className="flex flex-col gap-2 px-2 py-1">
                        <p className="text-destructive text-sm">
                            Daftar spesialisasi belum dapat dimuat.
                        </p>

                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11 self-start"
                            onClick={onMuatUlangSpesialisasi}
                        >
                            <RefreshCw aria-hidden />
                            Coba lagi
                        </Button>
                    </div>
                ) : null}

                {statusSpesialisasi === 'success' &&
                barisSpesialisasi.length === 0 ? (
                    <p className="text-muted-foreground px-2 text-sm">
                        Belum ada data spesialisasi.
                    </p>
                ) : null}

                {barisSpesialisasi.map((row) => (
                    <FilterChoice
                        key={row.id}
                        type="radio"
                        name={`${idPrefix}-spesialisasi`}
                        value={row.kode}
                        label={row.nama}
                        checked={nilai.spesialisasi === row.kode}
                        disabled={nonaktif}
                        onPilih={() => {
                            onUbah({ ...nilai, spesialisasi: row.kode });
                        }}
                    />
                ))}
            </fieldset>

            <fieldset className="flex flex-col gap-0.5">
                <legend className="mb-1 text-sm font-semibold">Tipe dokter</legend>

                {TIPE_DOKTER.map((value) => (
                    <FilterChoice
                        key={value}
                        type="radio"
                        name={`${idPrefix}-tipe`}
                        value={value}
                        label={labelTipeDokter(value)}
                        checked={nilai.tipe === value}
                        disabled={nonaktif}
                        onPilih={() => {
                            onUbah({ ...nilai, tipe: value });
                        }}
                    />
                ))}
            </fieldset>

            <fieldset className="flex flex-col gap-0.5">
                <legend className="mb-1 text-sm font-semibold">Layanan</legend>

                <FilterChoice
                    type="checkbox"
                    name={`${idPrefix}-layanan`}
                    value="telemedisin"
                    label="Telemedisin"
                    checked={nilai.telemedisin}
                    disabled={nonaktif}
                    onPilih={() => {
                        onUbah({ ...nilai, telemedisin: !nilai.telemedisin });
                    }}
                />

                <p className="text-muted-foreground px-2 text-sm">
                    Hanya dokter yang tersedia untuk konsultasi online.
                </p>
            </fieldset>

            <fieldset className="flex flex-col gap-0.5" data-slot="dokter-urutkan-grup">
                <legend className="mb-1 text-sm font-semibold">Urutkan</legend>

                <Select
                    value={nilai.sort}
                    disabled={nonaktif}
                    onValueChange={(value) => {
                        onUbah({ ...nilai, sort: value as SortDokter });
                    }}
                >
                    <SelectTrigger
                        aria-label="Urutkan"
                        data-slot="dokter-urutkan"
                        className="h-11 w-full"
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
        </div>
    );
}

/**
 * One filter option: a full-row hit area with a real `input` under it.
 *
 * The input is stretched across the label at zero opacity rather than rendered at
 * `size-4`, because WCAG 2.2's target size applies to the interactive element itself and a
 * 16 px checkbox inside a 44 px label still measures as 16 px. The visible box is
 * `aria-hidden` decoration that follows the input's `checked` state and draws the group's
 * focus ring via `peer-focus-visible`.
 */
function FilterChoice({
    type,
    name,
    value,
    label,
    checked,
    disabled,
    onPilih,
}: {
    type: 'radio' | 'checkbox';
    name: string;
    value: string;
    label: string;
    checked: boolean;
    disabled: boolean;
    onPilih: () => void;
}) {
    return (
        <label
            className={cn(
                'hover:bg-accent has-[:focus-visible]:ring-ring relative flex min-h-11 cursor-pointer items-center gap-3 rounded-md px-2 has-[:focus-visible]:ring-2',
                disabled && 'cursor-not-allowed opacity-50',
            )}
        >
            <input
                type={type}
                name={name}
                value={value}
                checked={checked}
                disabled={disabled}
                onChange={onPilih}
                className="absolute inset-0 size-full cursor-pointer appearance-none opacity-0"
            />

            <span
                aria-hidden
                className={cn(
                    'border-input flex size-4 shrink-0 items-center justify-center border',
                    type === 'radio' ? 'rounded-full' : 'rounded-[4px]',
                    checked && 'border-primary bg-primary',
                )}
            >
                {checked ? (
                    type === 'radio' ? (
                        <span className="bg-primary-foreground size-1.5 rounded-full" />
                    ) : (
                        <Check className="text-primary-foreground size-3.5" />
                    )
                ) : null}
            </span>

            <span className="text-sm">{label}</span>
        </label>
    );
}

/**
 * The mobile filter sheet.
 *
 * Deliberately built from the installed `@radix-ui/react-dialog` primitive rather than the
 * kit's `sheet.tsx`, because that sheet paints a `bg-black/80` overlay: F03 AC-2 requires
 * the results behind the sheet to stay readable (the patient is comparing them), and an 80%
 * black scrim does not. Everything else is the kit's own pattern - same slot names, same
 * slide/fade animations from `tw-animate-css`, same title/close structure - so it reads as
 * one system. Radix supplies the focus trap and the return of focus to the trigger on
 * close.
 */
function PanelFilterMobile({
    open,
    onOpenChange,
    pemicuRef,
    footer,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /**
     * The `Filter · n` button. Radix restores focus to `context.triggerRef` on close only
     * when a `DialogPrimitive.Trigger` rendered the sheet, and this sheet is opened from
     * controlled state instead - without this ref, `onCloseAutoFocus` would run with a null
     * trigger and leave focus on `<body>`, which is exactly what F03 AC-12 forbids.
     */
    pemicuRef: RefObject<HTMLButtonElement | null>;
    footer: ReactNode;
    children: ReactNode;
}) {
    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay
                    data-slot="sheet-overlay"
                    className="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 bg-foreground/25 fixed inset-0 z-50"
                />

                <DialogPrimitive.Content
                    data-slot="sheet-content"
                    className="bg-background data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom fixed inset-x-0 bottom-0 z-50 flex max-h-[85svh] flex-col rounded-t-xl border-t"
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        pemicuRef.current?.focus();
                    }}
                >
                    <div className="flex items-start justify-between gap-2 p-4 pb-2">
                        <div className="flex flex-col gap-1">
                            <DialogPrimitive.Title className="text-base font-semibold">
                                Filter
                            </DialogPrimitive.Title>

                            <DialogPrimitive.Description className="sr-only">
                                Pilih filter untuk mempersempit daftar dokter.
                            </DialogPrimitive.Description>
                        </div>

                        <DialogPrimitive.Close
                            aria-label="Tutup"
                            className="text-muted-foreground hover:text-foreground focus-visible:ring-ring -mt-1 -mr-2 flex min-h-11 min-w-11 items-center justify-center rounded-md focus-visible:ring-2"
                        >
                            <X aria-hidden className="size-4" />
                        </DialogPrimitive.Close>
                    </div>

                    <div className="flex-1 overflow-y-auto px-4 pb-4">
                        {children}
                    </div>

                    <div className="border-t p-4">{footer}</div>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
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
