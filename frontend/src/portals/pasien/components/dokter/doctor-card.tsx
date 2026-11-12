import { MapPin, Star, Video } from 'lucide-react';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { labelTipeDokter } from '@/lib/api/dokter';
import type { Decimal, DokterFaskesUtama, DokterTipe } from '@/lib/api/types';
import { formatDecimal, formatJarak, formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';

export function DoctorCard({
    id,
    nama,
    tipe,
    spesialisasi,
    biaya,
    rating,
    konsultasi,
    pengalaman,
    ulasan,
    faskes,
    jarak,
    onLihatProfil,
}: {
    id: number;
    nama: string;
    tipe: DokterTipe;
    spesialisasi: string | null;
    biaya: number | string | null;
    rating: number | string | null;
    konsultasi: number;
    pengalaman?: number | null;
    ulasan?: number | null;
    faskes?: DokterFaskesUtama | null;
    jarak?: Decimal;
    onLihatProfil?: () => void;
}) {
    const pengalamanTampil =
        typeof pengalaman === 'number' && pengalaman > 0 ? pengalaman : null;
    const ulasanTampil = typeof ulasan === 'number' && ulasan > 0 ? ulasan : null;
    const jarakTampil =
        faskes === null || faskes === undefined ? null : formatJarak(jarak ?? null);

    const isi = (
        <CardContent className="flex h-full flex-col gap-3 p-4">
            {/* Avatar + nama + badge */}
            <div className="flex items-start gap-3">
                <div
                    aria-hidden
                    className="bg-primary/10 text-primary flex size-11 shrink-0 items-center justify-center rounded-xl text-sm font-bold"
                >
                    {inisialKartu(nama)}
                </div>

                <div className="min-w-0 flex-1">
                    <p className="text-foreground line-clamp-2 text-sm font-semibold">
                        {nama}
                    </p>
                    <p className="text-muted-foreground mt-0.5 text-xs">
                        {labelTipeDokter(tipe)}
                        {spesialisasi !== null && spesialisasi !== ''
                            ? ` · ${spesialisasi}`
                            : ''}
                    </p>
                </div>

                <span className="border-success/60 text-success flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium">
                    <Video aria-hidden className="size-3" />
                    Online
                </span>
            </div>

            {/* Lokasi */}
            <div className="flex items-start gap-2" data-slot="dokter-lokasi">
                <MapPin aria-hidden className="text-muted-foreground mt-0.5 size-3.5 shrink-0" />
                <div className="min-w-0">
                    {faskes === null || faskes === undefined ? (
                        <p className="text-muted-foreground text-xs">Konsultasi online saja</p>
                    ) : (
                        <>
                            <p className="line-clamp-1 text-xs font-medium">
                                {faskes.nama ?? 'Tempat praktik belum dicatat'}
                            </p>
                            {faskes.alamat !== null && faskes.alamat !== '' && (
                                <p className="text-muted-foreground line-clamp-1 text-xs">
                                    {faskes.alamat}
                                </p>
                            )}
                            {jarakTampil !== null && (
                                <p className="text-primary text-xs font-medium" data-slot="dokter-jarak">
                                    {jarakTampil} dari lokasimu
                                </p>
                            )}
                        </>
                    )}
                </div>
            </div>

            {/* Stat */}
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span
                    className={cn(
                        'flex items-center gap-1 text-xs font-semibold',
                        rating !== null ? 'text-warning' : 'text-muted-foreground',
                    )}
                >
                    <Star
                        aria-hidden
                        className={cn('size-3.5', rating !== null ? 'fill-warning' : 'fill-none')}
                    />
                    {rating !== null ? formatDecimal(rating, 1) : '—'}
                    {ulasanTampil !== null && (
                        <span className="text-muted-foreground font-normal">({ulasanTampil})</span>
                    )}
                </span>
                {pengalamanTampil !== null && (
                    <span className="text-muted-foreground text-xs">{pengalamanTampil} thn</span>
                )}
                <span className="text-muted-foreground text-xs">{konsultasi} konsultasi</span>
            </div>

            {/* Footer */}
            <div className="mt-auto flex items-center justify-between gap-2 pt-1">
                <div>
                    <p className="text-muted-foreground text-xs">Mulai dari</p>
                    <p className="text-foreground text-sm font-bold tabular-nums" data-slot="dokter-biaya">
                        {formatRupiah(biaya)}
                    </p>
                </div>
                <Button
                    asChild
                    size="sm"
                    className="min-h-9 shrink-0"
                    onClick={(e) => e.stopPropagation()}
                >
                    <Link to={`/booking/${String(id)}`}>Pesan</Link>
                </Button>
            </div>
        </CardContent>
    );

    if (onLihatProfil !== undefined) {
        return (
            <button
                type="button"
                className="block h-full w-full cursor-pointer text-left"
                onClick={onLihatProfil}
            >
                <Card className="h-full transition-shadow hover:shadow-md" data-slot="dokter-kartu">
                    {isi}
                </Card>
            </button>
        );
    }

    return (
        <Card className="h-full transition-shadow hover:shadow-md" data-slot="dokter-kartu">
            {isi}
        </Card>
    );
}

function inisialKartu(nama: string): string {
    const tanpaGelar = nama.replace(/\b(dr|drg|ns|apt|prof|bidan|psikolog)\.?\s+/gi, '');
    const kata = tanpaGelar.split(/\s+/).filter((k) => k.length > 0);
    if (kata.length === 0) return nama.slice(0, 1).toUpperCase();
    return kata
        .slice(0, 2)
        .map((k) => k[0].toUpperCase())
        .join('');
}
