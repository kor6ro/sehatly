import {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { Link } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import {
    Activity,
    ArrowRight,
    Baby,
    Bone,
    Brain,
    Droplets,
    Ear,
    Eye,
    Flower2,
    Hand,
    Heart,
    Quote,
    Scissors,
    Search,
    Sparkles,
    Stethoscope,
    Wind,
    Zap,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { spesialisasiOptions, POPULER, cariMasterPopuler } from '@/lib/api/dokter';
import { heroOptions } from '@/lib/api/hero';
import { cn } from '@/lib/utils';
import { Skeleton } from '@/components/ui/skeleton';
import {
    CEK_MANDIRI,
    KAMUS,
    KATEGORI_OBAT,
    LAYANAN,
    PROMO,
    TIPS,
    TESTIMONI,
    type Istilah,
} from './data';

/**
 * The nine sections below the hero, in the order the page reads.
 *
 * ## The one section that talks to a server
 *
 * `SpesialisSection` is the only component here with a query. Everything else is
 * `data.ts` copy laid out in a grid - which is the whole point of keeping that data out
 * of this file: the layout code does not change when a sentence does, and a section with
 * no endpoint behind it cannot fail to load.
 *
 * ## The shared section frame
 *
 * {@link Bagian} reproduces the reference page's two-column rhythm - a heading in a narrow
 * left column, the content in the wide right one - because repeating that shape is what
 * makes nine sections read as one page instead of nine unrelated blocks. Sections whose
 * content is a full-width list (articles, testimonials) use {@link JudulBagian} instead,
 * and that difference is deliberate: a heading beside a five-row list pushes the list into
 * a third of the width it needs.
 *
 * `SpesialisSection` breaks that frame ON PURPOSE, and it is the third shape in the set:
 * a self-contained catalog panel whose heading and "Lihat semua" link share a row inside
 * the panel, with the list scrolling beneath them - the way a shop's category menu is
 * built. The heading is still an `h2` carrying the same words, so the page still reads in
 * the same order; only the frame around it changes.
 */

function JudulBagian({
    judul,
    deskripsi,
    untuk,
}: {
    judul: string;
    deskripsi?: string;
    untuk?: string;
}) {
    return (
        <div className="mb-6 flex flex-col gap-2">
            <h2 id={untuk} className="text-2xl font-bold md:text-3xl">
                {judul}
            </h2>

            {deskripsi === undefined ? null : (
                <p className="text-muted-foreground max-w-2xl text-sm md:text-base">
                    {deskripsi}
                </p>
            )}
        </div>
    );
}

function Bagian({
    id,
    judul,
    deskripsi,
    children,
}: {
    /**
     * An in-page anchor target. Only one section carries it: `CekMandiriSection` is
     * what the header's "Cek Kesehatan Mandiri" jumps to, so the landing nav can
     * offer a content link the way a health portal does without inventing a route
     * that has no endpoint behind it.
     */
    id?: string;
    judul: string;
    deskripsi?: string;
    children: ReactNode;
}) {
    return (
        <section id={id} className="mx-auto w-full max-w-[1280px] px-4 py-12 md:px-6">
            <div className="grid gap-6 md:grid-cols-[minmax(0,16rem)_minmax(0,1fr)] md:gap-12">
                <div>
                    <h2 className="text-2xl leading-snug font-bold md:text-3xl">
                        {judul}
                    </h2>

                    {deskripsi === undefined ? null : (
                        <p className="text-muted-foreground mt-2 text-sm md:text-base">
                            {deskripsi}
                        </p>
                    )}
                </div>

                <div>{children}</div>
            </div>
        </section>
    );
}

/** The link that closes a section: a quiet text button with an arrow. */
function TautanBagian({ to, label }: { to: string; label: string }) {
    return (
        <Link
            to={to}
            className="text-primary hover:text-primary/80 mt-6 inline-flex items-center gap-1.5 text-sm font-semibold"
        >
            {label}
            <ArrowRight className="size-4" />
        </Link>
    );
}

/**
 * "Solusi Kesehatan di Tanganmu" - six service cards, six real routes.
 *
 * Not filtered by account type: every one of these destinations already resolves its own
 * refusal (`RequireAuth` for the guarded five, nothing for `/dokter`), so hiding a card
 * from a visitor who has not signed in would remove the only thing on the page telling
 * them what the product does.
 */
export function SolusiSection() {
    return (
        <section className="bg-muted/40 w-full">
            <Bagian
                judul="Solusi Kesehatan di Tanganmu"
                deskripsi="Dari tanya dokter sampai obat sampai di rumah, semuanya lewat satu aplikasi."
            >
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {LAYANAN.map((layanan) => {
                        const Ikon = layanan.ikon;

                        return (
                            <Link
                                key={layanan.to}
                                to={layanan.to}
                                className="bg-card flex items-center gap-4 rounded-2xl border p-4 transition hover:border-primary/40 hover:shadow-sm"
                            >
                                <span className="bg-primary/10 text-primary flex size-12 shrink-0 items-center justify-center rounded-xl">
                                    <Ikon className="size-5" />
                                </span>

                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-semibold">
                                        {layanan.judul}
                                    </span>

                                    <span className="text-muted-foreground block text-xs leading-relaxed">
                                        {layanan.deskripsi}
                                    </span>
                                </span>

                                <ArrowRight className="text-muted-foreground size-4 shrink-0" />
                            </Link>
                        );
                    })}
                </div>
            </Bagian>
        </section>
    );
}

/** "Promo & Penawaran Hari Ini" - campaign copy on a horizontal rail. */
export function PromoSection() {
    return (
        <section className="mx-auto w-full max-w-[1280px] px-4 py-12 md:px-6">
            <JudulBagian judul="Promo & Penawaran Hari Ini" />

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {PROMO.map((promo) => (
                    <div
                        key={promo.judul}
                        className={cn(
                            'bg-gradient-to-br flex min-h-44 flex-col justify-between rounded-2xl p-5 text-white',
                            promo.gradien,
                        )}
                    >
                        <div className="flex flex-col gap-2">
                            <span className="text-lg leading-snug font-bold">
                                {promo.judul}
                            </span>

                            <span className="text-xs leading-relaxed text-white/90">
                                {promo.deskripsi}
                            </span>
                        </div>

                        <Link
                            to={promo.to}
                            className="mt-4 inline-flex w-fit items-center gap-1.5 rounded-full bg-white px-3.5 py-1.5 text-xs font-semibold text-foreground transition hover:bg-white/90"
                        >
                            {promo.cta}
                            <ArrowRight className="size-3.5" />
                        </Link>
                    </div>
                ))}
            </div>
        </section>
    );
}

/**
 * The line art for a specialisation, keyed by `master_spesialisasi.kode`.
 *
 * ## Why a table and not an `if` chain
 *
 * The codes are the reference table's own (`SP.A`, `GIGI`, ...), so this map is the only
 * place that translates a code the API owns into a shape a visitor recognises. It is
 * deliberately a FALLBACK-ABLE table: an unknown or newly seeded code renders
 * {@link IKON_BAWAAN} rather than throwing or leaving an empty box, because the icon is
 * decoration and the label beside it is what actually names the specialty.
 *
 * The icons are `aria-hidden` for the same reason - a link whose accessible name is
 * "Spesialis Mata" should not also announce an eye.
 */
const IKON_SPESIALISASI: Readonly<Record<string, LucideIcon>> = {
    UMUM: Stethoscope,
    // Gigi: sparkles, because lucide ships no tooth and a bone would say "orthopaedi".
    GIGI: Sparkles,
    'SP.A': Baby,
    'SP.B': Scissors,
    'SP.BP': Scissors, // both are surgery, which is how Zalora reuses one glyph too
    'SP.JP': Heart,
    'SP.KJ': Brain,
    'SP.KK': Hand,
    'SP.M': Eye,
    'SP.N': Zap, // the impulse, which is what a nerve conducts
    'SP.OG': Flower2,
    'SP.P': Wind,
    'SP.PD': Activity,
    'SP.S': Bone,
    'SP.THT': Ear,
    'SP.U': Droplets,
};

const IKON_BAWAAN = Stethoscope;

/** One card in the panel's right-hand grid: a photograph when there is one, a painted
 * gradient when there is not - the same bargain the hero carousel makes, so the panel
 * never promises a picture it cannot show. */
type KartuPromoSeks = {
    kunci: string;
    judul: string;
    tautan: string;
    to: string;
    foto: string | null;
    alt: string;
    gradien: string;
};

/**
 * "Konsultasi Spesialis Tepercaya" - the only server-backed section, built as a WIDE
 * catalog panel in three zones under one heading row: a scrolling icon rail on the left,
 * two columns of shortcuts in the middle, and a grid of promo cards on the right.
 *
 * `GET /master-spesialisasi` is public, so this renders for a visitor with no session,
 * and its rows are the reference table the directory itself filters on - a hard-coded
 * list would drift from the table the API answers with, which is exactly the failure
 * `DoctorDirectoryPage`'s "Sering dicari" shortcuts were changed to avoid.
 *
 * ## What each zone is made of, and why it is not a picture of a shopping site
 *
 * - **Rail (left): the whole table, one row per specialisation, icon + name.** The old
 *   version was six tiles in a grid, which answered "what kinds of doctor are there?"
 *   with a sample. Fifteen rows in a rail is why the zone scrolls and why the scrollbar
 *   matters: it says the list continues, which a slice never could. `dokter_umum` stays
 *   out - it is not a "spesialis" - unless the pure set is too thin to stand alone, the
 *   guard the tiles had, minus the `slice(0, 6)`.
 * - **Middle: two short columns of shortcuts.** "Sering dicari" is `POPULER` resolved
 *   against the same table the rail just read, so a shortcut that no longer maps to a
 *   live code is skipped rather than sent to a filter that returns nothing; "Layanan" is
 *   the same `LAYANAN` the landing body already publishes, so the two never disagree.
 * - **Right: promo cards.** The admin's hero photographs come first (`GET /hero`, the
 *   product's only public photography, written in `/admin/hero` with its image and alt
 *   text), then `PROMO`'s painted gradients fill the grid to four. The query shares its
 *   key with the carousel, so on the landing page this costs no extra request.
 *
 * Nothing here is a stock photo or an invented category: every row, link and card
 * resolves to a route in `app/router.tsx`.
 *
 * Each rail row links to `/dokter?spesialisasi={kode}`; `DoctorDirectoryPage` reads that
 * parameter on mount, so the row opens the directory already filtered rather than
 * promising a filter it does not apply.
 *
 * While it loads the rail is skeletons of the same height, so the panel does not jump.
 * A failed read leaves the heading and its call to action, which still work: the
 * directory has its own filter control.
 */
export function SpesialisSection() {
    const spesialisasi = useQuery(spesialisasiOptions());
    const hero = useQuery(heroOptions());

    const { daftar, semua } = useMemo(() => {
        const baris = spesialisasi.data?.data.spesialisasi ?? [];
        const murni = baris.filter((baris_) => baris_.tipe === 'spesialis');

        // the shortcuts resolve against EVERY row, including `dokter_umum`
        return { semua: baris, daftar: murni.length >= 6 ? murni : baris };
    }, [spesialisasi.data]);

    const kartu = useMemo<KartuPromoSeks[]>(() => {
        const foto: KartuPromoSeks[] = (hero.data?.data.hero ?? [])
            .filter(
                (slide): slide is typeof slide & { gambar: string } =>
                    slide.gambar !== null,
            )
            .map((slide) => ({
                kunci: `hero-${slide.id}`,
                judul: slide.judul,
                tautan: slide.cta_label,
                to: slide.cta_target,
                foto: slide.gambar,
                alt: slide.gambar_alt ?? '',
                gradien: '',
            }));

        const lukisan: KartuPromoSeks[] = PROMO.map((promo) => ({
            kunci: promo.judul,
            judul: promo.judul,
            tautan: promo.cta,
            to: promo.to,
            foto: null,
            alt: '',
            gradien: promo.gradien,
        }));

        return [...foto, ...lukisan].slice(0, 4);
    }, [hero.data]);

    /**
     * The rail's own scrollbar.
     *
     * `tinggi` and `atas` are laid out in pixels rather than percentages because the
     * thumb has to be DRAGGED: a percentage would make its travel and the pointer's
     * travel disagree, and a scrollbar you cannot grab is a decoration.
     */
    const relRef = useRef<HTMLUListElement>(null);
    const [gulir, setGulir] = useState({ muat: false, tinggi: 0, atas: 0 });
    const geser = useRef<{ y: number; scroll: number; rasio: number } | null>(null);

    const ukurGulir = useCallback(() => {
        const el = relRef.current;
        if (!el) return;

        const kelebihan = el.scrollHeight - el.clientHeight;
        if (kelebihan <= 1) {
            // only write when the answer changed, or every scroll re-renders the section
            setGulir((kini) => (kini.muat ? { muat: false, tinggi: 0, atas: 0 } : kini));
            return;
        }

        const tinggi = Math.max(28, (el.clientHeight / el.scrollHeight) * el.clientHeight);
        setGulir({
            muat: true,
            tinggi,
            atas: (el.scrollTop / el.scrollHeight) * el.clientHeight,
        });
    }, []);

    useEffect(() => {
        // the `<ul>` only exists once the query has landed, so the subscription waits
        // for the same condition that mounts it
        if (!spesialisasi.isSuccess) return;

        const el = relRef.current;
        if (!el) return;

        ukurGulir();
        el.addEventListener('scroll', ukurGulir, { passive: true });
        window.addEventListener('resize', ukurGulir);

        return () => {
            el.removeEventListener('scroll', ukurGulir);
            window.removeEventListener('resize', ukurGulir);
        };
    }, [spesialisasi.isSuccess, ukurGulir]);

    return (
        <section className="mx-auto w-full max-w-[1280px] px-4 py-12 md:px-6">
            <div
                data-slot="spesialis-panel"
                className="border-border bg-card rounded-3xl border p-5 shadow-sm md:p-6"
            >
                <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <h2 className="text-2xl leading-snug font-bold md:text-3xl">
                            Konsultasi Spesialis Tepercaya
                        </h2>

                        <p className="text-muted-foreground mt-2 max-w-2xl text-sm md:text-base">
                            Pilih bidang yang kamu butuhkan, lalu lihat dokternya lengkap
                            dengan jadwal dan ulasan.
                        </p>
                    </div>

                    <Link
                        to="/dokter"
                        className="text-primary inline-flex shrink-0 items-center gap-1 pt-1 text-sm font-semibold hover:underline"
                    >
                        Lihat semua
                        <ArrowRight className="size-4" />
                    </Link>
                </div>

                <div
                    data-slot="spesialis-zona"
                    className="mt-5 grid items-start gap-6 lg:grid-cols-[minmax(0,16rem)_minmax(0,14rem)_minmax(0,1fr)] lg:gap-8"
                >
                    {/* Zone 1 - the rail: every specialisation, scrolling on its own */}
                    <div className="lg:border-border min-w-0 lg:border-r lg:pr-6">
                        {spesialisasi.isPending ? (
                            <div className="grid gap-1">
                                {Array.from({ length: 6 }, (_, i) => (
                                    <Skeleton key={i} className="h-14 rounded-xl" />
                                ))}
                            </div>
                        ) : null}

                        {spesialisasi.isError ? (
                            <p className="text-muted-foreground text-sm">
                                Daftar spesialis sedang tidak dapat dimuat.{' '}
                                <Link to="/dokter" className="text-primary font-medium">
                                    Buka direktori dokter
                                </Link>{' '}
                                untuk memilih langsung.
                            </p>
                        ) : null}

                        {spesialisasi.isSuccess ? (
                            <div className="relative">
                                <ul
                                    ref={relRef}
                                    data-slot="spesialis-daftar"
                                    className="gulir-sendiri max-h-[24rem] overflow-y-auto lg:max-h-[28rem]"
                                >
                                    {daftar.map((baris) => {
                                        const Ikon =
                                            IKON_SPESIALISASI[baris.kode] ?? IKON_BAWAAN;

                                        return (
                                            <li key={baris.kode}>
                                                <Link
                                                    to={`/dokter?spesialisasi=${encodeURIComponent(baris.kode)}`}
                                                    className="hover:bg-secondary flex items-center gap-3 rounded-xl px-3 py-3 transition-colors"
                                                >
                                                    <Ikon
                                                        aria-hidden="true"
                                                        className="text-foreground/80 size-5 shrink-0"
                                                    />

                                                    <span className="min-w-0 flex-1 text-sm leading-snug font-semibold">
                                                        {baris.nama}
                                                    </span>
                                                </Link>
                                            </li>
                                        );
                                    })}
                                </ul>

                                {/*
                                 * The thumb. It is an element rather than a scrollbar
                                 * pseudo-element because Chromium 153 reserves no space
                                 * for the latter - see `.gulir-sendiri` - and a rail whose
                                 * fifteen rows live in a ten-row window has to say so
                                 * without waiting for anyone to scroll first.
                                 */}
                                {gulir.muat ? (
                                    <span
                                        data-slot="spesialis-gulir"
                                        aria-hidden="true"
                                        className="bg-border/70 hover:bg-border absolute top-0 right-0 w-1.5 cursor-grab touch-none rounded-full active:cursor-grabbing"
                                        style={{
                                            height: `${gulir.tinggi}px`,
                                            transform: `translateY(${gulir.atas}px)`,
                                        }}
                                        onPointerDown={(event) => {
                                            const el = relRef.current;
                                            if (!el) return;

                                            event.preventDefault();
                                            event.currentTarget.setPointerCapture(
                                                event.pointerId,
                                            );
                                            geser.current = {
                                                y: event.clientY,
                                                scroll: el.scrollTop,
                                                rasio: el.scrollHeight / el.clientHeight,
                                            };
                                        }}
                                        onPointerMove={(event) => {
                                            const el = relRef.current;
                                            const g = geser.current;
                                            if (!el || !g) return;

                                            el.scrollTop =
                                                g.scroll + (event.clientY - g.y) * g.rasio;
                                        }}
                                        onPointerUp={() => {
                                            geser.current = null;
                                        }}
                                        onPointerCancel={() => {
                                            geser.current = null;
                                        }}
                                    />
                                ) : null}
                            </div>
                        ) : null}
                    </div>

                    {/* Zone 2 - two shortcut columns, the same data the rest of the page uses */}
                    <div data-slot="spesialis-pintasan" className="min-w-0">
                        <p className="text-foreground flex items-center gap-2 text-[13px] font-bold">
                            <Search aria-hidden="true" className="size-4" />
                            Sering dicari
                        </p>

                        <ul className="mt-2 grid gap-0.5">
                            {POPULER.map((pintasan) => {
                                const master = cariMasterPopuler(
                                    semua,
                                    pintasan.kataKunci,
                                );

                                // Absent from the reference table = not offered, rather
                                // than offered against a code that no longer exists.
                                if (master === undefined) return null;

                                return (
                                    <li key={pintasan.label}>
                                        <Link
                                            to={`/dokter?spesialisasi=${encodeURIComponent(master.kode)}`}
                                            className="hover:bg-secondary text-muted-foreground hover:text-foreground block rounded-md px-2 py-1.5 text-sm transition-colors"
                                        >
                                            {pintasan.label}
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>

                        <p className="text-foreground mt-5 flex items-center gap-2 text-[13px] font-bold">
                            <Stethoscope aria-hidden="true" className="size-4" />
                            Layanan
                        </p>

                        <ul className="mt-2 grid gap-0.5">
                            {LAYANAN.map((layanan) => (
                                <li key={layanan.to}>
                                    <Link
                                        to={layanan.to}
                                        className="hover:bg-secondary text-muted-foreground hover:text-foreground block rounded-md px-2 py-1.5 text-sm transition-colors"
                                    >
                                        {layanan.judul}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>

                    {/* Zone 3 - the picture grid */}
                    <div className="min-w-0">
                        <div
                            data-slot="spesialis-promo"
                            className="grid grid-cols-2 gap-3"
                        >
                            {kartu.map((kartu_) => (
                                <Link
                                    key={kartu_.kunci}
                                    to={kartu_.to}
                                    className="group relative block aspect-[4/3] overflow-hidden rounded-2xl"
                                >
                                    {kartu_.foto !== null ? (
                                        <img
                                            src={kartu_.foto}
                                            alt={kartu_.alt}
                                            loading="lazy"
                                            className="absolute inset-0 size-full object-cover transition-transform duration-300 group-hover:scale-105"
                                        />
                                    ) : (
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'absolute inset-0 bg-gradient-to-br',
                                                kartu_.gradien,
                                            )}
                                        />
                                    )}

                                    <span
                                        aria-hidden="true"
                                        className="absolute inset-0 bg-gradient-to-t from-black/60 via-black/10 to-transparent"
                                    />

                                    <span className="absolute inset-x-3 bottom-3 text-white">
                                        <span className="block text-sm leading-snug font-bold">
                                            {kartu_.judul}
                                        </span>

                                        <span className="mt-1 inline-flex items-center gap-1 text-xs font-semibold opacity-90">
                                            {kartu_.tautan}
                                            <ArrowRight className="size-3.5" />
                                        </span>
                                    </span>
                                </Link>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

/**
 * "Beli Obat & Suplemen Kesehatan" - categories only.
 *
 * No prices, no brands, no "Cek Sekarang" on a product: Sehatly has no catalogue
 * endpoint a visitor may read (`GET /obat` is `tipe:dokter`), so a price shown here would
 * be a number nobody can back. What the section can honestly say is which kinds of
 * medicine exist and where each one comes from - and that the third one, the one people
 * actually need, only a doctor can issue.
 */
export function ObatSection() {
    return (
        <section className="bg-muted/40 w-full">
            <Bagian
                judul="Beli Obat & Suplemen Kesehatan"
                deskripsi="Obat keras hanya dengan resep dokter; kebutuhan harian bisa didiskusikan dulu saat konsultasi."
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    {KATEGORI_OBAT.map((kategori) => {
                        const Ikon = kategori.ikon;

                        return (
                            <div
                                key={kategori.nama}
                                className="bg-card flex flex-col gap-3 rounded-2xl border p-5"
                            >
                                <span className="bg-primary/10 text-primary flex size-11 items-center justify-center rounded-xl">
                                    <Ikon className="size-5" />
                                </span>

                                <span className="text-sm font-semibold">
                                    {kategori.nama}
                                </span>

                                <span className="text-muted-foreground text-xs leading-relaxed">
                                    {kategori.deskripsi}
                                </span>
                            </div>
                        );
                    })}
                </div>

                <TautanBagian to="/dokter" label="Konsultasikan Kebutuhan Obatmu" />
            </Bagian>
        </section>
    );
}

/**
 * "Telusuri Kamus Kesehatan" - eight terms, disclosed in place.
 *
 * The chips look like the reference page's topic filters and behave like them from the
 * reader's side - pick one, get its content - but the content is here rather than on
 * another route, because there is no glossary route to send them to. One chip is active
 * at a time and the first term is open on mount, so the panel is never empty on arrival.
 */
export function KamusSection() {
    const [aktif, setAktif] = useState<Istilah>(KAMUS[0] as Istilah);

    return (
        <Bagian
            judul="Telusuri Kamus Kesehatan"
            deskripsi="Pusat edukasi untuk berbagai topik kesehatan, ditulis dengan bahasa sehari-hari."
        >
            <div className="flex flex-wrap gap-2">
                {KAMUS.map((istilah) => {
                    const terpilih = istilah.istilah === aktif.istilah;

                    return (
                        <button
                            key={istilah.istilah}
                            type="button"
                            aria-pressed={terpilih}
                            onClick={() => setAktif(istilah)}
                            className={cn(
                                'rounded-full border px-4 py-2 text-sm font-medium transition',
                                terpilih
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'bg-card hover:border-primary/40',
                            )}
                        >
                            {istilah.istilah}
                        </button>
                    );
                })}
            </div>

            <div className="bg-card mt-4 rounded-2xl border p-5">
                <p className="text-sm font-semibold">{aktif.istilah}</p>

                <p className="text-muted-foreground mt-1.5 text-sm leading-relaxed">
                    {aktif.definisi}
                </p>
            </div>

            <p className="text-muted-foreground mt-3 text-xs">
                Ringkasan informatif, bukan diagnosis. Konsultasikan keluhanmu dengan dokter
                untuk penjelasan yang berlaku untukmu.
            </p>
        </Bagian>
    );
}

/**
 * "Baca Artikel Kesehatan Terkini" - six tips with a working category filter.
 *
 * The chips filter the list that is already on the page instead of fetching or navigating,
 * which is the only version of this control that can be honest: there is no article
 * endpoint and no article route. "Terbaru" restores the original order, so the filter is
 * always reversible.
 */
export function ArtikelSection() {
    const [kategori, setKategori] = useState<string | null>(null);

    const kategoriList = useMemo(
        () => Array.from(new Set(TIPS.map((tips) => tips.kategori))),
        [],
    );

    const daftar = kategori === null
        ? TIPS
        : TIPS.filter((tips) => tips.kategori === kategori);

    return (
        <section className="mx-auto w-full max-w-[1280px] px-4 py-12 md:px-6">
            <JudulBagian
                judul="Baca Artikel Kesehatan Terkini"
                deskripsi="Info dan tips kesehatan singkat yang bisa langsung dipraktikkan."
            />

            <div className="mb-6 flex flex-wrap gap-2">
                <button
                    type="button"
                    aria-pressed={kategori === null}
                    onClick={() => setKategori(null)}
                    className={cn(
                        'rounded-full border px-4 py-2 text-sm font-medium transition',
                        kategori === null
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'bg-card hover:border-primary/40',
                    )}
                >
                    Terbaru
                </button>

                {kategoriList.map((nilai) => (
                    <button
                        key={nilai}
                        type="button"
                        aria-pressed={kategori === nilai}
                        onClick={() => setKategori(nilai)}
                        className={cn(
                            'rounded-full border px-4 py-2 text-sm font-medium transition',
                            kategori === nilai
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'bg-card hover:border-primary/40',
                        )}
                    >
                        {nilai}
                    </button>
                ))}
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                {daftar.map((tips) => (
                    <article
                        key={tips.judul}
                        className="bg-card flex gap-4 rounded-2xl border p-4"
                    >
                        <span className="bg-primary/10 text-primary flex size-12 shrink-0 items-center justify-center rounded-xl text-xs font-bold">
                            {tips.kategori.slice(0, 3).toUpperCase()}
                        </span>

                        <div className="min-w-0">
                            <h3 className="text-sm leading-snug font-semibold">
                                {tips.judul}
                            </h3>

                            <p className="text-muted-foreground mt-1 text-xs leading-relaxed">
                                {tips.ringkas}
                            </p>
                        </div>
                    </article>
                ))}
            </div>
        </section>
    );
}

/**
 * "Cek Kesehatan Mandiri" - an illustrated grid, and deliberately not a control.
 *
 * `CEK_MANDIRI` carries no route, so these render as `<li>` tiles with no hover state and
 * no chevron: the shape of a tile tells the reader whether it can be pressed, and a tile
 * that presses nothing should not pretend otherwise. What each one does say is one line
 * about the check it stands for.
 */
export function CekMandiriSection() {
    return (
        <Bagian
            id="cek-mandiri"
            judul="Cek Kesehatan Mandiri"
            deskripsi="Dapatkan gambaran ringkas tentang kesehatanmu dan ketahui penanganan selanjutnya, tanpa biaya."
        >
            <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-3">
                {CEK_MANDIRI.map((cek) => {
                    const Ikon = cek.ikon;

                    return (
                        <li key={cek.label} className="flex flex-col items-center gap-2 text-center">
                            <span className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-full">
                                <Ikon className="size-6" />
                            </span>

                            <span className="text-sm font-semibold">{cek.label}</span>

                            <span className="text-muted-foreground text-xs leading-snug">
                                {cek.deskripsi}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </Bagian>
    );
}

/**
 * "Kata Mereka tentang Sehatly" - three quotes, initials only.
 *
 * The names are initials plus a city, not full names with photos: this is illustrative
 * copy for a demo deployment, and attributing invented words to a real-looking person is
 * the one thing a landing page should not do even when everything else on it is a
 * placeholder.
 */
export function TestimoniSection() {
    return (
        <section className="mx-auto w-full max-w-[1280px] px-4 py-12 md:px-6">
            <JudulBagian judul="Kata Mereka tentang Sehatly" />

            <div className="grid gap-4 md:grid-cols-3">
                {TESTIMONI.map((testimoni) => (
                    <figure
                        key={testimoni.nama}
                        className="bg-card flex flex-col gap-4 rounded-2xl border p-6"
                    >
                        <Quote className="text-primary/40 size-7" />

                        <blockquote className="text-sm leading-relaxed italic">
                            &ldquo;{testimoni.kutipan}&rdquo;
                        </blockquote>

                        <figcaption className="mt-auto flex items-center gap-3">
                            <span className="bg-primary/10 text-primary flex size-10 items-center justify-center rounded-full text-xs font-bold">
                                {testimoni.nama
                                    .split(' ')
                                    .map((bagian) => bagian.replace('.', '').charAt(0))
                                    .join('')
                                    .slice(0, 2)}
                            </span>

                            <span className="flex flex-col">
                                <span className="text-sm font-semibold">
                                    {testimoni.nama}
                                </span>

                                <span className="text-muted-foreground text-xs">
                                    {testimoni.peran}
                                </span>
                            </span>
                        </figcaption>
                    </figure>
                ))}
            </div>
        </section>
    );
}
