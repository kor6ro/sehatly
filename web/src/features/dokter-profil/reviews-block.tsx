import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { BadgeCheck } from 'lucide-react';
import type { ApiMeta } from '@/lib/http';
import type { Iso } from '@/lib/api/types';
import {
    bacaMetaUlasan,
    jumlahUlasan,
    SORT_ULASAN,
    ulasanOptions,
    type MetaUlasan,
    type SortUlasan,
    type UlasanDokter,
    type UlasanFilters,
} from '@/lib/api/ulasan';
import { formatDecimal } from '@/lib/format';
import { zonaPerangkat } from '@/lib/waktu';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState } from '@/components/states/error-state';
import { LoadingState, SkeletonRows } from '@/components/states/loading-state';
import { Pagination } from '@/components/layout/pagination';
import { BintangRating } from '@/features/dokter-profil/bintang-rating';

const PER_HALAMAN = 5;

/**
 * `Ulasan pasien` - F04's reviews block on `/dokter/:id`, against the endpoint the
 * backend added in commit `7d27a6a`.
 *
 * ## The summary is a rule, not a formatting choice
 *
 * F04 AC-5: below five reviews the rating summary is **not rendered at all** - no stars,
 * no `0,0`. `agregat()` publishes `rata_rata: null` for a doctor with no reviews
 * precisely so a zero cannot be mistaken for a rating, and this block adds the product
 * rule: a 5-of-1 average is not evidence, so `Belum cukup ulasan untuk menampilkan
 * rating.` plus the count is the honest answer. The distribution is hidden by the same
 * rule, and `bacaMetaUlasan` fails closed on a malformed `meta` (see there).
 *
 * ## One source of numbers
 *
 * Everything numeric here comes from the `/ulasan` response: `total`, `rata_rata`, the
 * two sub-averages and `distribusi`. The stored `dokter.rating_rata_rata` /
 * `dokter.jumlah_ulasan` columns have no writer (F04 blocker #5), so the profile's old
 * `Informasi lain` cells that read them were removed when this block landed - two
 * sources for one number is exactly the defect the pattern names P0.
 *
 * ## Filtering and sorting keep the aggregate
 *
 * `?rating=` narrows the LIST only. The server computes the aggregate over every review
 * regardless, so clicking the 4★ bar leaves the five bars and the summary unchanged and
 * only swaps the rows below. The selected bar carries `aria-pressed` and can be pressed
 * again to clear the filter; the filter-empty state also offers `Tampilkan semua
 * ulasan`.
 *
 * ## Review items
 *
 * Author identity follows the API: `is_anonim` or a missing `penulis` renders `Pasien`,
 * never a name the server withheld. The body is `text-base`, `break-words`, and carries
 * no `truncate`/`line-clamp` - `web/AGENTS.md` makes review text medical-adjacent data
 * that must not be ellipsised. The doctor's reply is a separate muted block with its own
 * date, and negative reviews are never hidden: there is no control to hide anything.
 */
export function ReviewsBlock({ dokterId }: { dokterId: string }) {
    const [halaman, setHalaman] = useState(1);
    const [rating, setRating] = useState<number | null>(null);
    const [sort, setSort] = useState<SortUlasan>('terbaru');

    const filters: UlasanFilters = {
        page: halaman,
        per_page: PER_HALAMAN,
        ...(rating === null ? {} : { rating }),
        sort,
    };

    const ulasan = useQuery(ulasanOptions(dokterId, filters));

    const meta = ulasan.data === undefined ? null : bacaMetaUlasan(ulasan.data.meta);
    const rows = ulasan.data?.data.ulasan ?? [];

    /**
     * The OVERALL count, not `meta.total`. A `?rating=` filter makes `meta.total` the
     * filtered page-set's total, while the summary rule and the `{n} ulasan` copy speak
     * about every review. The distribution sums to the overall count by construction.
     */
    const total = meta === null ? 0 : jumlahUlasan(meta);

    const pilihRating = (next: number | null): void => {
        setRating(next);
        setHalaman(1);
    };

    const ringkasanBoleh = meta !== null && total >= 5 && meta.rata_rata !== null;

    return (
        <Card data-slot="reviews-block">
            <CardHeader>
                <CardTitle className="text-base">Ulasan pasien</CardTitle>
            </CardHeader>

            <CardContent className="flex flex-col gap-4">
                {ulasan.isPending ? (
                    <LoadingState label="Memuat ulasan pasien...">
                        <SkeletonRows rows={3} />
                    </LoadingState>
                ) : null}

                {ulasan.isError ? (
                    <ErrorState
                        title="Ulasan belum dapat dimuat."
                        error={ulasan.error}
                        onRetry={() => {
                            void ulasan.refetch();
                        }}
                    />
                ) : null}

                {ulasan.isSuccess && meta !== null ? (
                    <div className="flex flex-col gap-4" data-slot="ulasan-konten">
                        {total === 0 && rating === null ? (
                            <EmptyState
                                compact
                                title="Belum ada ulasan."
                                description="Ulasan muncul setelah pasien menyelesaikan konsultasi."
                            />
                        ) : (
                            <>
                                {ringkasanBoleh ? (
                                    <RingkasanUlasan
                                        meta={meta}
                                        total={total}
                                        rating={rating}
                                        onPilihRating={pilihRating}
                                    />
                                ) : (
                                    <p
                                        data-slot="ulasan-belum-cukup"
                                        className="text-base"
                                    >
                                        Belum cukup ulasan untuk menampilkan
                                        rating.{' '}
                                        <span className="text-muted-foreground">
                                            <span className="tabular-nums">
                                                {total}
                                            </span>{' '}
                                            ulasan.
                                        </span>
                                    </p>
                                )}

                                {rating !== null && rows.length === 0 ? (
                                    <EmptyState
                                        compact
                                        title={`Tidak ada ulasan ${rating} bintang.`}
                                        description="Filter ini tidak menemukan ulasan pada bintang tersebut."
                                        action={
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="min-h-11"
                                                onClick={() => {
                                                    pilihRating(null);
                                                }}
                                            >
                                                Tampilkan semua ulasan
                                            </Button>
                                        }
                                    />
                                ) : (
                                    <DaftarUlasan
                                        rows={rows}
                                        meta={ulasan.data?.meta}
                                        sort={sort}
                                        onSort={(next) => {
                                            setSort(next);
                                            setHalaman(1);
                                        }}
                                        onPage={setHalaman}
                                    />
                                )}
                            </>
                        )}

                        {/**
                         * F04 AC-6's exact sentence. It is rendered for every loaded
                         * state - including the empty one - because the policy is what
                         * makes even an empty block trustworthy. There is deliberately
                         * no control beside it to hide or opt out of reviews.
                         */}
                        <p
                            data-slot="kebijakan-ulasan"
                            className="text-muted-foreground text-sm"
                        >
                            Ulasan hanya dapat ditulis oleh pasien yang telah
                            menyelesaikan konsultasi. Tidak ada ulasan berbayar atau
                            berinsentif.
                        </p>
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}

/**
 * The summary line, the two optional sub-ratings and the five clickable bars.
 *
 * The bar row is a real `button` with a full label (`Tampilkan hanya 4 bintang`), a
 * visible count beside a width-proportional bar, and `aria-pressed` for the selected
 * state - F04 §8 asks for radio semantics and the numeric label is what makes the bar
 * readable without seeing its width. Every row is `min-h-11`, so the filter target is
 * at least 44 px at both viewports.
 */
function RingkasanUlasan({
    meta,
    total,
    rating,
    onPilihRating,
}: {
    meta: MetaUlasan;
    total: number;
    rating: number | null;
    onPilihRating: (next: number | null) => void;
}) {
    return (
        <div className="flex flex-col gap-4" data-slot="ringkasan-ulasan">
            <p className="text-base" data-slot="ringkasan-rating">
                <span className="text-lg font-semibold tabular-nums">
                    {formatDecimal(meta.rata_rata, 1)}
                </span>{' '}
                dari 5
                <span aria-hidden> • </span>
                <span className="tabular-nums">{total}</span> ulasan
            </p>

            {meta.rata_rata_komunikasi === null &&
            meta.rata_rata_akurasi === null ? null : (
                <dl className="flex flex-wrap gap-x-6 gap-y-1 text-base">
                    {meta.rata_rata_komunikasi === null ? null : (
                        <div className="flex items-center gap-2">
                            <dt className="text-muted-foreground">Komunikasi</dt>

                            <dd className="font-medium tabular-nums">
                                {formatDecimal(meta.rata_rata_komunikasi, 1)}
                            </dd>
                        </div>
                    )}

                    {meta.rata_rata_akurasi === null ? null : (
                        <div className="flex items-center gap-2">
                            <dt className="text-muted-foreground">Akurasi</dt>

                            <dd className="font-medium tabular-nums">
                                {formatDecimal(meta.rata_rata_akurasi, 1)}
                            </dd>
                        </div>
                    )}
                </dl>
            )}

            <ul
                aria-label="Distribusi rating"
                data-slot="distribusi-rating"
                className="flex flex-col gap-1"
            >
                {[5, 4, 3, 2, 1].map((bintang) => {
                    const jumlah =
                        meta.distribusi[
                            String(bintang) as keyof typeof meta.distribusi
                        ];
                    const persen =
                        total === 0 ? 0 : Math.round((jumlah / total) * 100);
                    const terpilih = rating === bintang;

                    return (
                        <li key={bintang}>
                            <button
                                type="button"
                                data-slot="distribusi-bintang"
                                aria-pressed={terpilih}
                                aria-label={`Tampilkan hanya ${String(bintang)} bintang`}
                                onClick={() => {
                                    onPilihRating(terpilih ? null : bintang);
                                }}
                                className={cn(
                                    'focus-visible:ring-ring hover:bg-accent flex min-h-11 w-full items-center gap-3 rounded-md px-2 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none',
                                    terpilih && 'bg-accent',
                                )}
                            >
                                <span className="w-14 shrink-0 text-base whitespace-nowrap tabular-nums">
                                    {bintang} ★
                                </span>

                                <span
                                    aria-hidden
                                    className="bg-muted h-2 min-w-0 flex-1 overflow-hidden rounded-full"
                                >
                                    <span
                                        className="bg-primary block h-full rounded-full"
                                        style={{ width: `${String(persen)}%` }}
                                    />
                                </span>

                                <span className="w-10 shrink-0 text-right text-base tabular-nums">
                                    {jumlah}
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

/**
 * The sort control, the review list and the pagination.
 *
 * `rows.length === 0` renders only the control and the pagination: the caller has
 * already decided that the filter-empty state does not belong here (it renders its own
 * message), so an empty list here would be a second, contradictory sentence.
 */
function DaftarUlasan({
    rows,
    meta,
    sort,
    onSort,
    onPage,
}: {
    rows: UlasanDokter[];
    meta: ApiMeta | undefined;
    sort: SortUlasan;
    onSort: (next: SortUlasan) => void;
    onPage: (page: number) => void;
}) {
    return (
        <div className="flex flex-col gap-3" data-slot="ulasan-daftar">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-base font-medium">Daftar ulasan</p>

                <Select
                    value={sort}
                    onValueChange={(next) => {
                        onSort(next as SortUlasan);
                    }}
                >
                    <SelectTrigger
                        size="sm"
                        aria-label="Urutkan ulasan"
                        className="min-h-11"
                    >
                        <SelectValue />
                    </SelectTrigger>

                    <SelectContent>
                        {SORT_ULASAN.map((opsi) => (
                            <SelectItem key={opsi.value} value={opsi.value}>
                                {opsi.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            {rows.length === 0 ? null : (
                <ul
                    aria-label="Daftar ulasan pasien"
                    className="flex flex-col divide-y"
                >
                    {rows.map((row) => (
                        <li key={row.id}>
                            <ItemUlasan row={row} />
                        </li>
                    ))}
                </ul>
            )}

            <Pagination meta={meta} onPageChange={onPage} />
        </div>
    );
}

/** One review: rating, identity, verification, date, full body and the doctor's reply. */
function ItemUlasan({ row }: { row: UlasanDokter }) {
    const penulis =
        row.is_anonim || row.penulis === null || row.penulis === ''
            ? 'Pasien'
            : row.penulis;

    return (
        <article data-slot="ulasan-item" className="flex flex-col gap-2 py-4">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <BintangRating nilai={row.rating} />

                <span className="text-muted-foreground text-sm tabular-nums">
                    {row.rating}/5
                </span>

                <span className="text-base font-medium">{penulis}</span>

                {/**
                 * The same presentation `DoctorTrustBadge` uses: text plus a success
                 * icon, with the text in `--foreground` so the 4.5:1 contrast floor
                 * does not depend on the status hue.
                 */}
                <Badge variant="outline" className="border-success/60">
                    <BadgeCheck aria-hidden className="text-success" />

                    Terverifikasi
                </Badge>

                <time
                    dateTime={row.dibuat_at ?? undefined}
                    className="text-muted-foreground text-sm"
                >
                    {tanggalSingkat(row.dibuat_at)}
                </time>
            </div>

            {row.isi === null || row.isi === '' ? null : (
                <p className="text-base leading-relaxed break-words whitespace-pre-line">
                    {row.isi}
                </p>
            )}

            {row.balasan_dokter === null || row.balasan_dokter === '' ? null : (
                <div
                    data-slot="balasan-dokter"
                    className="bg-muted/50 flex flex-col gap-1 rounded-md p-3"
                >
                    <p className="text-sm font-medium">Balasan dokter</p>

                    <p className="text-base leading-relaxed break-words whitespace-pre-line">
                        {row.balasan_dokter}
                    </p>

                    {row.dibalas_at === null ? null : (
                        <time
                            dateTime={row.dibalas_at}
                            className="text-muted-foreground text-xs"
                        >
                            {tanggalSingkat(row.dibalas_at)}
                        </time>
                    )}
                </div>
            )}
        </article>
    );
}

/**
 * An ISO-8601 instant as `04 Okt 2026`, in the reader's own zone.
 *
 * `dibuat_at` is a real instant (`toISOString()`), so it is converted to the device
 * zone and read as a calendar date - the same treatment `formatWaktuZona` gives every
 * other instant. The short month is what F04 §4.3 specifies for a review date. A
 * malformed value is returned unchanged rather than as "Invalid Date".
 */
function tanggalSingkat(value: Iso): string {
    if (value === null || value === '') {
        return '-';
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('id-ID', {
        timeZone: zonaPerangkat(),
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(parsed);
}
