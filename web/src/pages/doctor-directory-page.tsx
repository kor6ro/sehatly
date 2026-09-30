import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { Search, Star, Stethoscope } from 'lucide-react';
import {
    dokterOptions,
    labelTipeDokter,
    spesialisasiOptions,
    TIPE_DOKTER,
    type DokterFilters,
} from '@/lib/api/dokter';
import { describeRange, isEmptyPage, isPastLastPage } from '@/lib/api/pagination';
import { ApiError } from '@/lib/http';
import { formatDecimal, formatRupiah } from '@/lib/format';
import { PageHeader } from '@/components/layout/page-header';
import { Pagination } from '@/components/layout/pagination';
import { SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState } from '@/components/states/error-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Field, FieldInput, FieldSelect } from '@/components/form/field';
import { SelectItem } from '@/components/ui/select';

/**
 * `/dokter` - the public directory, readable with no session at all.
 *
 * ## Why this route is outside the auth guard
 *
 * `DokterController` states it directly: the directory is public, and putting
 * `permission:dokter.lihat` on it would answer 401 to every anonymous visitor because
 * `EnsurePermission` needs an authenticated principal - the opposite of what a
 * pre-authentication browse page needs. It would also lock out `perawat` and `kurir`, which
 * are real `users.tipe` values holding no role.
 *
 * So this screen is reachable signed-out, which is also what makes it the honest place to
 * demonstrate the 404 behaviour: no session is involved at all.
 *
 * ## A zero-result filter is a distinct state from an empty directory
 *
 * The filters draw from closed vocabularies the DDL defines, so a typo is a 422 naming the
 * field rather than a silent empty list. But a *valid* filter that matches nothing is a
 * real outcome, and it needs copy that says the filter is the reason and offers a way to
 * clear it. `hasFilters` is what separates the two empty readings.
 */
export function DoctorDirectoryPage() {
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [submittedSearch, setSubmittedSearch] = useState('');
    const [spesialisasi, setSpesialisasi] = useState<string | undefined>(undefined);
    const [tipe, setTipe] = useState<string | undefined>(undefined);
    const [telemedisinOnly, setTelemedisinOnly] = useState(false);

    const hasFilters =
        submittedSearch !== '' ||
        spesialisasi !== undefined ||
        tipe !== undefined ||
        telemedisinOnly;

    const filters = useMemo<DokterFilters>(
        () => ({
            page,
            per_page: 12,
            ...(spesialisasi === undefined ? {} : { spesialisasi }),
            ...(tipe === undefined ? {} : { tipe: tipe as never }),
            ...(submittedSearch === '' ? {} : { search: submittedSearch }),
            ...(telemedisinOnly ? { tersedia_telemedisin: true } : {}),
        }),
        [page, spesialisasi, submittedSearch, telemedisinOnly, tipe],
    );

    const list = useQuery(dokterOptions(filters));
    const spesialisasiList = useQuery(spesialisasiOptions());

    const rows = list.data?.data.dokter ?? [];
    const meta = list.data?.meta;
    const range = describeRange(meta);

    function resetToFirstPage(): void {
        setPage(1);
    }

    function clearFilters(): void {
        setSearch('');
        setSubmittedSearch('');
        setSpesialisasi(undefined);
        setTipe(undefined);
        setTelemedisinOnly(false);
        resetToFirstPage();
    }

    return (
        <>
            <PageHeader
                title="Direktori dokter"
                description="Daftar dokter yang memenuhi syarat: terverifikasi, aktif, tersedia untuk telemedisin, dan STR masih berlaku."
            />

            {/**
             * The filter bar is a form so `Enter` submits, and the search box is
             * uncontrolled-by-URL on purpose: the filter state is component state rather
             * than a query string, so a reload of a filtered directory is not something
             * this todo has to make deep-linkable.
             */}
            <Card>
                <CardContent>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();

                            setSubmittedSearch(search.trim());
                            resetToFirstPage();
                        }}
                        className="grid gap-4 md:grid-cols-4"
                    >
                        <Field label="Cari nama" className="md:col-span-2">
                            <FieldInput
                                value={search}
                                onChange={(event) => {
                                    setSearch(event.target.value);
                                }}
                                placeholder="Nama dokter"
                            />
                        </Field>

                        <Field label="Spesialisasi">
                            <FieldSelect
                                value={spesialisasi ?? 'semua'}
                                onValueChange={(value) => {
                                    setSpesialisasi(
                                        value === 'semua' ? undefined : value,
                                    );
                                    resetToFirstPage();
                                }}
                            >
                                <SelectItem value="semua">Semua spesialisasi</SelectItem>

                                {(spesialisasiList.data?.data.spesialisasi ?? []).map(
                                    (row) => (
                                        <SelectItem key={row.id} value={row.kode}>
                                            {row.nama}
                                        </SelectItem>
                                    ),
                                )}
                            </FieldSelect>
                        </Field>

                        <Field label="Tipe dokter">
                            <FieldSelect
                                value={tipe ?? 'semua'}
                                onValueChange={(value) => {
                                    setTipe(value === 'semua' ? undefined : value);
                                    resetToFirstPage();
                                }}
                            >
                                <SelectItem value="semua">Semua tipe</SelectItem>

                                {TIPE_DOKTER.map((value) => (
                                    <SelectItem key={value} value={value}>
                                        {labelTipeDokter(value)}
                                    </SelectItem>
                                ))}
                            </FieldSelect>
                        </Field>

                        <div className="flex items-end gap-2 md:col-span-4">
                            <Button type="submit" variant="outline">
                                <Search />

                                Terapkan
                            </Button>

                            <Button
                                type="button"
                                onClick={() => {
                                    setTelemedisinOnly((value) => !value);
                                    resetToFirstPage();
                                }}
                                aria-pressed={telemedisinOnly}
                                variant={telemedisinOnly ? 'default' : 'ghost'}
                            >
                                Telemedisin saja
                            </Button>

                            {hasFilters ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={clearFilters}
                                >
                                    Reset filter
                                </Button>
                            ) : null}
                        </div>
                    </form>

                    {/**
                     * `master_spesialisasi` is a 16-row reference table and
                     * `DokterController::spesialisasiIndex()` reports its real row count
                     * rather than a constant, so a schema-only database answers `0` here
                     * and the dropdown would otherwise be an empty control with no
                     * explanation.
                     */}
                    {spesialisasiList.isError ? (
                        <p className="text-destructive mt-3 text-sm">
                            Daftar spesialisasi gagal dimuat, filter spesialisasi dinonaktifkan.
                        </p>
                    ) : null}

                    {spesialisasiList.isSuccess &&
                    (spesialisasiList.data.data.spesialisasi.length === 0) ? (
                        <p className="text-muted-foreground mt-3 text-sm">
                            Belum ada data spesialisasi, sehingga filter spesialisasi tidak
                            memiliki pilihan.
                        </p>
                    ) : null}
                </CardContent>
            </Card>

            {list.isPending ? (
                <SkeletonRows rows={5} />
            ) : list.isError ? (
                <ErrorState
                    error={asDirectoryError(list.error)}
                    onRetry={() => {
                        void list.refetch();
                    }}
                />
            ) : isEmptyPage(meta, rows.length) ? (
                <EmptyState
                    title={
                        hasFilters
                            ? 'Tidak ada dokter yang cocok'
                            : 'Direktori kosong'
                    }
                    description={
                        hasFilters
                            ? 'Tidak ada dokter yang memenuhi filter ini. Longgarkan filter atau gunakan kata kunci yang lebih umum.'
                            : 'Belum ada dokter yang memenuhi syarat untuk tampil di direktori publik.'
                    }
                    action={
                        hasFilters ? (
                            <Button type="button" variant="outline" onClick={clearFilters}>
                                Reset filter
                            </Button>
                        ) : undefined
                    }
                />
            ) : isPastLastPage(meta, rows.length) ? (
                <EmptyState
                    title="Halaman ini kosong"
                    description={`Halaman ${String(meta?.current_page ?? page)} di luar jangkauan. Ada ${String(meta?.total ?? 0)} dokter yang cocok.`}
                    action={
                        <Button
                            type="button"
                            variant="outline"
                            onClick={resetToFirstPage}
                        >
                            Kembali ke halaman pertama
                        </Button>
                    }
                />
            ) : (
                <>
                    {range === null ? null : (
                        <p className="text-muted-foreground text-sm">{range}</p>
                    )}

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
                                />
                            </li>
                        ))}
                    </ul>

                    <Pagination meta={meta} onPageChange={setPage} />
                </>
            )}
        </>
    );
}

function DoctorCard({
    id,
    nama,
    tipe,
    spesialisasi,
    biaya,
    rating,
    konsultasi,
}: {
    id: number;
    nama: string;
    tipe: string;
    /**
     * The view's `GROUP_CONCAT` string, or `null`. It is `null` and not `[]` for a doctor
     * with no `dokter_spesialisasi` row, so the card says "Spesialisasi belum dicatat"
     * rather than rendering an empty list with no explanation.
     */
    spesialisasi: string | null;
    biaya: number | string | null;
    rating: number | string | null;
    konsultasi: number;
}) {
    return (
        <Card className="h-full">
            <CardContent className="flex h-full flex-col gap-3">
                <div className="flex flex-col gap-1">
                    <Link
                        to={`/dokter/${id}`}
                        className="hover:underline font-medium underline-offset-4"
                    >
                        {nama}
                    </Link>

                    <p className="text-muted-foreground text-sm">
                        {labelTipeDokter(tipe as never)}
                    </p>
                </div>

                <p className="text-muted-foreground text-sm">
                    {spesialisasi === null || spesialisasi === ''
                        ? 'Spesialisasi belum dicatat'
                        : spesialisasi}
                </p>

                <div className="mt-auto flex flex-wrap items-center gap-2">
                    <Badge variant="secondary">{formatRupiah(biaya)}</Badge>

                    <Badge variant="outline">
                        <Star className="size-3" />

                        {formatDecimal(rating, 2)}
                    </Badge>

                    <Badge variant="outline">
                        <Stethoscope className="size-3" />

                        {konsultasi} konsultasi
                    </Badge>
                </div>
            </CardContent>
        </Card>
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
