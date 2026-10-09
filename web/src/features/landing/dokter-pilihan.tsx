import { useEffect, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { ArrowRight, X } from 'lucide-react';
import { DoctorCard } from '@/components/dokter/doctor-card';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { dokterOptions, spesialisasiOptions } from '@/lib/api/dokter';
import { cn } from '@/lib/utils';

/**
 * The specialisation the visitor picked in the header's rail, printed on the landing page
 * itself instead of at `/dokter`.
 *
 * ## Why the choice no longer navigates
 *
 * Picking "Dokter Gigi" used to be a link into the directory - a full page, with its own
 * search, its own filters and its own pagination, thrown in front of somebody who had
 * asked one question: *who sees patients for this?* The visitor lost the landing page and
 * gained a screen whose other controls were all still irrelevant to them. The rail's rows
 * and the "Sering dicari" shortcuts now point at `/?spesialisasi=…` - the SAME route, so
 * the page never reloads and the choice is still a plain `<a>` with a shareable address,
 * a working Back button and no hover-only door.
 *
 * This section is what that address renders. It is deliberately a PREVIEW: six cards, one
 * heading, and a single link out to `/dokter?spesialisasi=…`, which is where the search,
 * the sort and the rest of the table live. Removing that link would make the directory
 * unreachable from the one place that advertises it; keeping it short keeps the promise
 * small.
 *
 * ## Why it renders nothing at all when no specialisation is chosen
 *
 * The landing page stacks seven sections in a fixed order, and a permanent "pick a
 * specialisation" block would be a second copy of the rail that already exists in the
 * header - the very duplication that put this shape in the menu in the first place. So
 * the section exists only while a `?spesialisasi=` is in the URL, and plain `/` is
 * byte-for-byte the page it was before.
 */
export function DokterPilihanSection({
    kode,
    onTutup,
}: {
    /** A `master_spesialisasi.kode`, exactly what the rail sends. */
    kode: string | null;
    onTutup: () => void;
}) {
    const ref = useRef<HTMLElement | null>(null);

    /**
     * A chosen specialisation is scrolled into view, because the header sits at the top of
     * a tall page and the result of a click that happens up there would otherwise land
     * below the fold. `scroll-mt-28` on the section is the matching half: the bar is
     * 107px tall, and a section scrolled flush to the top would hide its own heading
     * under it.
     */
    useEffect(() => {
        if (kode === null) return;

        ref.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, [kode]);

    const master = useQuery(spesialisasiOptions());
    const daftar = useQuery({
        ...dokterOptions({
            page: 1,
            per_page: 6,
            spesialisasi: kode ?? undefined,
        }),
        enabled: kode !== null,
    });

    if (kode === null) return null;

    /**
     * The name comes from the reference table rather than from the URL, so a hand-edited
     * `?spesialisasi=` shows the name the API would answer with - or, while that table is
     * still loading, says so instead of printing the code.
     */
    const nama =
        master.data?.data.spesialisasi.find((baris) => baris.kode === kode)?.nama ?? null;

    const rows = daftar.data?.data.dokter ?? [];
    const kosong = daftar.isSuccess && rows.length === 0;

    return (
        <section
            ref={ref}
            id="pilihan-dokter"
            data-slot="landing-dokter-pilihan"
            className="scroll-mt-28 w-full"
        >
            <div className="mx-auto w-full max-w-[1280px] px-4 py-10 md:px-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <h2 className="text-2xl leading-snug font-bold md:text-3xl">
                            {nama ?? 'Dokter pilihanmu'}
                        </h2>

                        {/*
                            The count is printed only while there is one to print. In the
                            empty state the sentence below already says there are none,
                            and "0 dokter terverifikasi siap dikonsultasikan" would be a
                            headline about the number that very sentence explains.
                        */}
                        {daftar.isPending || rows.length > 0 ? (
                            <p className="text-muted-foreground mt-1 text-sm">
                                {daftar.isPending
                                    ? 'Menghitung hasil…'
                                    : `${String(rows.length)} dokter terverifikasi siap dikonsultasikan`}
                            </p>
                        ) : null}
                    </div>

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onTutup}
                        aria-label="Tutup pilihan dokter"
                        data-slot="landing-dokter-pilihan-tutup"
                    >
                        <X aria-hidden className="size-4" />
                        Tutup
                    </Button>
                </div>

                <div
                    aria-busy={daftar.isFetching}
                    className={cn(
                        'mt-5 transition-opacity',
                        daftar.isFetching && 'opacity-60',
                    )}
                >
                    {daftar.isPending ? (
                        <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {Array.from({ length: 3 }, (_, i) => (
                                <li key={i}>
                                    <Skeleton className="h-44 rounded-lg" />
                                </li>
                            ))}
                        </ul>
                    ) : null}

                    {daftar.isError ? (
                        <p className="text-muted-foreground text-sm">
                            Daftar dokter tidak dapat dimuat.{' '}
                            <Link
                                to={`/dokter?spesialisasi=${encodeURIComponent(kode)}`}
                                className="text-primary font-medium"
                            >
                                Buka direktori dokter
                            </Link>{' '}
                            untuk memilih langsung.
                        </p>
                    ) : null}

                    {kosong ? (
                        <p className="text-muted-foreground text-sm">
                            Belum ada dokter terverifikasi untuk spesialisasi ini.{' '}
                            <Link
                                to="/dokter"
                                className="text-primary font-medium"
                            >
                                Lihat seluruh direktori
                            </Link>{' '}
                            saja.
                        </p>
                    ) : null}

                    {rows.length > 0 ? (
                        <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
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
                    ) : null}
                </div>

                {/*
                    The one way out to the real directory. It carries the same
                    `?spesialisasi=` the rail seeded, so the screen it opens is already
                    filtered - the preview and the full table answer the same question.
                */}
                <Link
                    to={`/dokter?spesialisasi=${encodeURIComponent(kode)}`}
                    data-slot="landing-dokter-pilihan-semua"
                    className="text-primary mt-5 inline-flex items-center gap-1.5 text-sm font-semibold"
                >
                    Lihat semua di direktori dokter
                    <ArrowRight aria-hidden className="size-4" />
                </Link>
            </div>
        </section>
    );
}
