import { Award, MessageSquare, Star, Stethoscope, Video } from 'lucide-react';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { labelTipeDokter } from '@/lib/api/dokter';
import type { DokterTipe } from '@/lib/api/types';
import { formatDecimal, formatRupiah } from '@/lib/format';

/**
 * One doctor card, shared by every surface that prints a directory row: the section on the
 * landing page (`features/dokter/direktori.tsx`, which used to be the page at `/dokter`)
 * and the "Spesialis Anak" style pick in `DirektoriSection`.
 *
 * It used to live at the bottom of `doctor-directory-page.tsx`, which was honest while
 * that page was the only screen that could show a doctor. The landing page now prints the
 * same rows when a visitor picks a specialisation out of the header's rail, and a second
 * copy of this card is the exact drift this codebase keeps refusing: two cards that agree
 * today and disagree the first time a badge rule changes. So the card moved here, with its
 * `data-slot` and its props untouched - every selector the directory's tests use still
 * finds it.
 *
 * ## The `Tersedia telemedisin` badge is drawn for every card
 *
 * Eligibility is enforced server-side: `v_dokter_katalog` only publishes verified,
 * active, STR-valid doctors with `tersedia_telemedisin = 1`, so a card in this list cannot
 * be anything else.
 *
 * ## `pengalaman_tahun` and `jumlah_ulasan` are drawn only when positive
 *
 * They are the two fields the F03 backend commit added to the list projection, and
 * `DokterResource` int-casts both, so a `NULL` column arrives as `0`. Printing "0 tahun
 * pengalaman" would present an unfilled column as a fact, and "0 ulasan" would be a
 * scoreboard for a doctor who has simply not been reviewed. Omitting the badge is the
 * honest reading of both zeros, and it is one rule applied to both counts rather than two
 * different policies.
 */
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
}: {
    id: number;
    nama: string;
    tipe: DokterTipe;
    /**
     * The view's `GROUP_CONCAT` string, or `null`. It is `null` and not `[]` for a doctor
     * with no `dokter_spesialisasi` row, so the card says "Spesialisasi belum dicatat"
     * rather than rendering an empty list with no explanation.
     */
    spesialisasi: string | null;
    biaya: number | string | null;
    rating: number | string | null;
    konsultasi: number;
    /** `dokter.pengalaman_tahun`, `0` when unset. Optional so a stale cached row cannot crash the card. */
    pengalaman?: number | null;
    /** Recomputed review count from `ulasan_dokter`; `0` means no reviews yet. */
    ulasan?: number | null;
}) {
    const pengalamanTampil =
        typeof pengalaman === 'number' && pengalaman > 0 ? pengalaman : null;
    const ulasanTampil = typeof ulasan === 'number' && ulasan > 0 ? ulasan : null;

    return (
        <Card className="h-full" data-slot="dokter-kartu">
            <CardContent className="flex h-full flex-col gap-3">
                <div className="flex flex-col gap-1">
                    <Link
                        to={`/dokter/${String(id)}`}
                        className="text-base font-medium underline-offset-4 hover:underline"
                    >
                        {nama}
                    </Link>

                    <p className="text-muted-foreground text-sm">
                        {labelTipeDokter(tipe)}
                    </p>
                </div>

                <p className="text-sm">
                    {spesialisasi === null || spesialisasi === ''
                        ? 'Spesialisasi belum dicatat'
                        : spesialisasi}
                </p>

                <div className="mt-auto flex flex-wrap items-center gap-2">
                    <Badge variant="secondary" data-slot="dokter-biaya">
                        Mulai {formatRupiah(biaya)}
                    </Badge>

                    <Badge variant="outline">
                        <Star aria-hidden className="size-3" />

                        {formatDecimal(rating, 2)}
                    </Badge>

                    {pengalamanTampil === null ? null : (
                        <Badge variant="outline" data-slot="dokter-pengalaman">
                            <Award aria-hidden className="size-3" />

                            {`${String(pengalamanTampil)} tahun pengalaman`}
                        </Badge>
                    )}

                    {ulasanTampil === null ? null : (
                        <Badge variant="outline" data-slot="dokter-ulasan">
                            <MessageSquare aria-hidden className="size-3" />

                            {`${String(ulasanTampil)} ulasan`}
                        </Badge>
                    )}

                    <Badge variant="outline">
                        <Stethoscope aria-hidden className="size-3" />

                        {`${String(konsultasi)} konsultasi`}
                    </Badge>

                    <Badge variant="outline" className="border-success/60">
                        <Video aria-hidden className="text-success size-3" />
                        Tersedia telemedisin
                    </Badge>
                </div>
            </CardContent>
        </Card>
    );
}
