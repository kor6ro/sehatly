import { useMemo } from 'react';
import { useQueries, useQuery } from '@tanstack/react-query';
import { ArrowRight, BadgeCheck, Lock, ShieldCheck } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Link } from 'react-router';
import { dokterDetailOptions, dokterOptions } from '@/lib/api/dokter';
import { IlustrasiDokter } from './ilustrasi-dokter';
import type { DokterHeroItem } from './ilustrasi-dokter';

/**
 * Hero utama landing page `/`.
 * - Konten kiri: teks/tombol/badge dengan warna asli + animasi entrance fade-in-up
 * - Konten kanan: file SVG asli `/cokelat moderen model kolase foto.svg` + animasi entrance
 */

const LENCANA: readonly { label: string; sub: string; Ikon: LucideIcon }[] = [
    { label: 'Dokter Terverifikasi', sub: 'SIP & STR Resmi', Ikon: BadgeCheck },
    { label: 'Apotek Berizin', sub: 'Resep Resmi Diantar', Ikon: ShieldCheck },
    { label: 'Data Medis Aman', sub: 'Standar Privasi PDP', Ikon: Lock },
];

const CALON = 16;
const SLOT = 4;

export function HeroUtama() {
    const daftar = useQuery({
        ...dokterOptions({ page: 1, per_page: CALON }),
        retry: 0,
        staleTime: 5 * 60_000,
    });

    const idAcak = useMemo(() => {
        const baris = [...(daftar.data?.data.dokter ?? [])];

        for (let i = baris.length - 1; i > 0; i -= 1) {
            const j = Math.floor(Math.random() * (i + 1));
            [baris[i], baris[j]] = [baris[j], baris[i]];
        }

        return baris.slice(0, SLOT).map((dokter) => dokter.id);
    }, [daftar.data]);

    const detail = useQueries({
        queries: idAcak.map((id) => ({
            ...dokterDetailOptions(String(id)),
            enabled: idAcak.length > 0,
            retry: 0,
        })),
    });

    const dokterList: DokterHeroItem[] = idAcak.map((_, indeks) => {
        const d = detail[indeks]?.data?.data.dokter;
        return {
            foto: d?.foto_profil ?? null,
            nama: d?.nama_lengkap ?? undefined,
            spesialisasi: d?.spesialisasi?.[0]?.nama ?? undefined,
        };
    });

    return (
        <section
            aria-label="Sorotan utama"
            data-slot="hero-utama"
            className="relative bg-background w-full overflow-hidden"
        >
            {/* Ambient Background Glows */}
            <div
                aria-hidden="true"
                className="pointer-events-none absolute -top-28 -left-20 size-[26rem] rounded-full bg-primary/10 blur-3xl animate-pulse-slow"
            />
            <div
                aria-hidden="true"
                className="pointer-events-none absolute top-12 right-4 size-[28rem] rounded-full bg-amber-500/10 blur-3xl animate-pulse-slow"
                style={{ animationDelay: '3.5s' }}
            />
            <div
                aria-hidden="true"
                className="pointer-events-none absolute bottom-0 left-1/3 size-72 rounded-full bg-emerald-500/5 blur-2xl animate-pulse-slow"
                style={{ animationDelay: '1.8s' }}
            />

            <div className="relative mx-auto w-full max-w-[1280px] px-4 pt-10 pb-12 md:px-6 md:pt-16 md:pb-16">
                <div className="grid items-center gap-10 lg:grid-cols-2 lg:gap-14">
                    {/* Kolom Kiri */}
                    <div className="flex max-w-xl flex-col items-start gap-6">

                        {/* Badge Status — fade in up, delay 0ms */}
                        <div
                            className="inline-flex items-center gap-2 rounded-full border border-primary/25 bg-primary/10 px-3.5 py-1.5 shadow-xs backdrop-blur-xs animate-hero-fade-in-up"
                            style={{ animationDelay: '0ms' }}
                        >
                            <span className="relative flex size-2">
                                <span className="animate-ping absolute inline-flex size-full rounded-full bg-primary opacity-75" />
                                <span className="relative inline-flex size-2 rounded-full bg-primary" />
                            </span>
                            <span className="text-[11px] font-bold tracking-wider text-primary uppercase">
                                Layanan Kesehatan Terpercaya
                            </span>
                        </div>

                        {/* Judul — fade in up, delay 100ms */}
                        <h1
                            className="text-4xl leading-[1.14] font-light tracking-tight text-foreground sm:text-5xl lg:text-[3.5rem] animate-hero-fade-in-up"
                            style={{ animationDelay: '100ms' }}
                        >
                            Sehat{' '}
                            <span className="font-extrabold">
                                Lebih Mudah
                            </span>
                            , Kapan Saja
                        </h1>

                        {/* Subjudul — fade in up, delay 200ms */}
                        <p
                            className="text-muted-foreground max-w-lg text-base leading-relaxed md:text-lg animate-hero-fade-in-up"
                            style={{ animationDelay: '200ms' }}
                        >
                            Temui dokter pilihan terbaik, pesan jadwal konsultasi tanpa antre,
                            dan tebus resep obat langsung diantar ke pintu rumah Anda.
                        </p>

                        {/* Tombol CTA — fade in up, delay 300ms */}
                        <div
                            className="flex flex-wrap items-center gap-3.5 pt-1 animate-hero-fade-in-up"
                            style={{ animationDelay: '300ms' }}
                        >
                            <Link
                                to="/konsultasi"
                                className="group inline-flex h-12 items-center gap-2.5 rounded-full bg-primary px-7 text-sm font-semibold text-primary-foreground shadow-md shadow-primary/20 transition-all duration-300 hover:bg-primary/95 hover:shadow-lg hover:shadow-primary/30 hover:-translate-y-0.5 active:translate-y-0 outline-none focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                            >
                                Konsultasi Sekarang
                                <ArrowRight className="size-4 transition-transform duration-300 group-hover:translate-x-1" />
                            </Link>

                            <Link
                                to="/?semua=1"
                                className="inline-flex h-12 items-center gap-2 rounded-full border border-border bg-card/80 px-7 text-sm font-semibold text-foreground backdrop-blur-xs transition-all duration-300 hover:border-primary/40 hover:bg-accent hover:text-accent-foreground hover:-translate-y-0.5 active:translate-y-0 outline-none focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                            >
                                Cari Dokter Spesialis
                            </Link>
                        </div>

                        {/* Badge Kepercayaan — fade in up, delay 420ms */}
                        <ul
                            className="grid w-full grid-cols-1 gap-2.5 pt-3 sm:grid-cols-3 sm:gap-3 animate-hero-fade-in-up"
                            style={{ animationDelay: '420ms' }}
                        >
                            {LENCANA.map(({ label, sub, Ikon }) => (
                                <li
                                    key={label}
                                    className="group flex items-center gap-2.5 rounded-2xl border border-border/70 bg-card/70 p-2.5 backdrop-blur-xs transition-all duration-300 hover:border-primary/30 hover:bg-card hover:shadow-xs hover:-translate-y-0.5"
                                >
                                    <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary transition-colors duration-300 group-hover:bg-primary group-hover:text-primary-foreground">
                                        <Ikon className="size-4.5" aria-hidden="true" />
                                    </span>
                                    <div className="flex flex-col min-w-0">
                                        <span className="text-xs font-semibold text-foreground leading-tight truncate">
                                            {label}
                                        </span>
                                        <span className="text-[10px] text-muted-foreground leading-tight mt-0.5 truncate">
                                            {sub}
                                        </span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>

                    {/* Kolom Kanan: SVG asli */}
                    <IlustrasiDokter
                        dokter={dokterList}
                        className="w-full max-w-[580px] justify-self-center lg:justify-self-end"
                    />
                </div>
            </div>
        </section>
    );
}
