import { Award, MapPin, MessageSquare, Star, Stethoscope, Video } from 'lucide-react';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { labelTipeDokter } from '@/lib/api/dokter';
import type { Decimal, DokterFaskesUtama, DokterTipe } from '@/lib/api/types';
import { formatDecimal, formatJarak, formatRupiah } from '@/lib/format';

/**
 * One doctor card, shared by every surface that prints a directory row.
 *
 * It used to live at the bottom of `doctor-directory-page.tsx`, which was honest while
 * that page was the only screen that could show a doctor. The landing page prints the same
 * rows now, and a second copy of this card is the exact drift this codebase keeps
 * refusing: two cards that agree today and disagree the first time a badge rule changes.
 * So the card moved here, with its `data-slot` untouched - every selector the directory's
 * tests use still finds it.
 *
 * ## The name opens the profile as a page of its own
 *
 * `/dokter/{id}`. The landing page used to pass `/?dokter={id}` so the profile opened in
 * place under the list, and that is what the `ke` prop existed for; the October 2026
 * redesign sends every card to the page instead, so the prop is gone rather than left
 * taking a value it is always given. The profile page carries the about text, the
 * specialisations, the education, the venues with their coordinates, the reviews and the
 * schedule - which is more than a column beside a list has room for, and it is an address
 * a patient can send to somebody.
 *
 * ## The venue is drawn for every card, and its absence is drawn too
 *
 * `faskes_utama` is the one venue `jarak_km` was measured to, published by
 * `DokterResource`. Three states, all of them real and all of them printed rather than
 * skipped:
 *
 * | card shows | what it means |
 * | --- | --- |
 * | name, address and a distance | a practice at a known place, and how far |
 * | name and address, no distance | a real practice whose venue has no map pin - an address you can reach, a distance nobody can compute |
 * | `Tanpa tempat praktik — lewat chat & video` | an online-only doctor: no `dokter_faskes` row at all |
 *
 * A card that printed only the third state's rows, or that silently dropped the second,
 * would make a real practice look like it did not exist.
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
    faskes,
    jarak,
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
    /** The doctor's primary venue, or `null` for an online-only doctor. */
    faskes?: DokterFaskesUtama | null;
    /** Kilometres from the reader's position. `null` when no position was asked for, or the venue has no pin. */
    jarak?: Decimal;
}) {
    const pengalamanTampil =
        typeof pengalaman === 'number' && pengalaman > 0 ? pengalaman : null;
    const ulasanTampil = typeof ulasan === 'number' && ulasan > 0 ? ulasan : null;
    const jarakTampil =
        faskes === null || faskes === undefined
            ? null
            : formatJarak(jarak ?? null);

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

                {/**
                 * The venue, and this is the row the redesigned home page is built around:
                 * a patient picking a surgeon is asking "who is near me, and where do they
                 * practise" - a name with no building behind it answers half the question.
                 */}
                <div className="flex items-start gap-2" data-slot="dokter-lokasi">
                    <MapPin
                        aria-hidden
                        className="text-muted-foreground mt-0.5 size-4 shrink-0"
                    />

                    <div className="min-w-0">
                        {faskes === null || faskes === undefined ? (
                            <p className="text-muted-foreground text-sm">
                                Tanpa tempat praktik — lewat chat &amp; video
                            </p>
                        ) : (
                            <>
                                <p className="text-sm font-medium">
                                    {faskes.nama ?? 'Tempat praktik belum dicatat'}
                                </p>

                                {faskes.alamat === null || faskes.alamat === '' ? null : (
                                    <p className="text-muted-foreground text-xs">
                                        {faskes.alamat}
                                    </p>
                                )}

                                {/**
                                 * Absent, not `0`, and not a dash. A venue with no map pin
                                 * has a real address and an unknowable distance, and the
                                 * card says the address because that is the half it knows.
                                 */}
                                {jarakTampil === null ? null : (
                                    <p
                                        className="text-muted-foreground text-xs"
                                        data-slot="dokter-jarak"
                                    >
                                        {`${jarakTampil} dari lokasimu`}
                                    </p>
                                )}
                            </>
                        )}
                    </div>
                </div>

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
