import { useMemo, useState, type ReactNode } from 'react';
import { Link } from 'react-router';
import { ArrowRight, Quote } from 'lucide-react';
import { cn } from '@/lib/utils';
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
 * The seven sections below the hero, in the order the page reads.
 *
 * ## Why none of them talks to a server
 *
 * Every one is `data.ts` copy laid out in a grid - which is the whole point of keeping
 * that data out of this file: the layout code does not change when a sentence does, and
 * a section with no endpoint behind it cannot fail to load. The one that DID carry a
 * query, `SpesialisSection`, is gone: its catalog-panel shape moved into the header's
 * `PanelDirektori`, because a hover menu is where a category list belongs and the
 * landing page was publishing a second copy of the reference table for nobody.
 *
 * ## The shared section frame
 *
 * {@link Bagian} reproduces the reference page's two-column rhythm - a heading in a narrow
 * left column, the content in the wide right one - because repeating that shape is what
 * makes the sections read as one page instead of unrelated blocks. Sections whose
 * content is a full-width list (articles, testimonials) use {@link JudulBagian} instead,
 * and that difference is deliberate: a heading beside a five-row list pushes the list into
 * a third of the width it needs.
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
     * An in-page anchor target. Two sections carry it: `CekMandiriSection` is what the
     * header's "Cek Kesehatan Mandiri" jumps to, and `SolusiSection` is what the Layanan
     * panel's "Lihat semua" jumps to - both are content the navigation can promise
     * without inventing a route that has no endpoint behind it.
     *
     * The scroll margin rides along with the id: the header is sticky and TWO rows tall
     * on desktop, so a bare hash jump would park the section's heading underneath it.
     * 8rem is measured against the tall header; on a phone, where the bar is one row, it
     * simply leaves a little air above the heading, which costs nothing.
     */
    id?: string;
    judul: string;
    deskripsi?: string;
    children: ReactNode;
}) {
    return (
        <section
            id={id}
            className={cn(
                'mx-auto w-full max-w-[1280px] px-4 py-12 md:px-6',
                id === undefined ? null : 'scroll-mt-32',
            )}
        >
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
                id="solusi"
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

                <TautanBagian to="/?direktori=semua" label="Konsultasikan Kebutuhan Obatmu" />
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
