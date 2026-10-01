import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { CalendarClock, ChevronRight, Clock3, Pill, RefreshCw } from 'lucide-react';
import { ApiError } from '@/lib/http';
import {
    LABEL_STATUS_RESEP,
    STATUS_BISA_DIVERIFIKASI,
    antreanResepOptions,
} from '@/lib/api/resep';
import type { StatusAntreanResep } from '@/lib/api/resep';
import { formatTanggal } from '@/lib/format';
import { formatWaktuZona } from '@/lib/waktu';
import { Pagination } from '@/components/layout/pagination';
import { SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import {
    ErrorState,
    ForbiddenState,
    NotFoundState,
} from '@/components/states/error-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    KedaluwarsaBadge,
    StatusResepBadge,
} from '@/features/resep/resep-status-badge';

/**
 * The pharmacist's verification queue, read from `GET /resep`.
 *
 * ## The filter is the server's own closed set
 *
 * `AntreanResepRequest` accepts `status` only from `ResepStateMachine::BISA_DIVERIFIKASI`
 * (`aktif`, `diproses`); any other value is a 422 naming the field, not an empty page.
 * The chips enumerate exactly that constant plus "Semua", so no offered option can be
 * refused and no accepted option is hidden.
 *
 * ## What a row may and may not show
 *
 * `ResepAntreanResource` publishes row identity, timing, status flags and an item count.
 * It deliberately withholds drug names, `catatan_dokter`, the QR token and patient
 * identity, so this list renders only the published fields: `nomor_resep`, the status
 * badge (text + icon + colour), `tanggal_resep` zone-labelled, `berlaku_sampai`, the
 * "Kedaluwarsa" marker and `jumlah_item`. Opening a row is what reads clinical content,
 * through the separately gated `GET /resep/{id}`.
 *
 * ## Three states, one per block
 *
 * Skeleton while loading, `EmptyState` with a next action when there is nothing to
 * verify (clear the filter, or refetch), and `ErrorState` with "Coba lagi" - except for
 * 403 and 404, which are not retryable and get `ForbiddenState` / `NotFoundState`.
 */
const PER_HALAMAN = 15;

export function AntreanVerifikasiList({
    onPilih,
}: {
    onPilih: (id: number) => void;
}) {
    const [halaman, setHalaman] = useState(1);
    const [status, setStatus] = useState<StatusAntreanResep | ''>('');

    const antrean = useQuery(
        antreanResepOptions({
            page: halaman,
            per_page: PER_HALAMAN,
            ...(status === '' ? {} : { status }),
        }),
    );

    const pilihStatus = (nilai: StatusAntreanResep | ''): void => {
        setStatus(nilai);
        setHalaman(1);
    };

    return (
        <div data-slot="antrean-verifikasi" className="flex flex-col gap-4">
            <div
                data-slot="antrean-filter"
                role="group"
                aria-label="Filter status resep"
                className="flex flex-wrap gap-2"
            >
                <Button
                    type="button"
                    className="min-h-11"
                    variant={status === '' ? 'default' : 'outline'}
                    data-slot="antrean-filter-semua"
                    data-status="semua"
                    aria-pressed={status === ''}
                    onClick={() => {
                        pilihStatus('');
                    }}
                >
                    Semua
                </Button>

                {STATUS_BISA_DIVERIFIKASI.map((nilai) => (
                    <Button
                        key={nilai}
                        type="button"
                        className="min-h-11"
                        variant={status === nilai ? 'default' : 'outline'}
                        data-slot="antrean-filter-status"
                        data-status={nilai}
                        aria-pressed={status === nilai}
                        onClick={() => {
                            pilihStatus(nilai);
                        }}
                    >
                        {LABEL_STATUS_RESEP[nilai]}
                    </Button>
                ))}
            </div>

            {antrean.isPending ? (
                <SkeletonRows rows={5} />
            ) : antrean.isError ? (
                antrean.error instanceof ApiError && antrean.error.isForbidden ? (
                    <ForbiddenState detail="Akun ini tidak memiliki izin verifikasi resep, sehingga antrean tidak dapat dimuat." />
                ) : antrean.error instanceof ApiError &&
                  antrean.error.isNotFound ? (
                    <NotFoundState
                        title="Antrean tidak ditemukan"
                        detail="Antrean verifikasi tidak tersedia saat ini. Muat ulang halaman atau hubungi admin bila berlanjut."
                    />
                ) : (
                    <ErrorState
                        error={antrean.error}
                        onRetry={() => {
                            void antrean.refetch();
                        }}
                    />
                )
            ) : antrean.data.data.resep.length === 0 ? (
                <EmptyState
                    title="Tidak ada resep yang menunggu verifikasi"
                    description={
                        status === ''
                            ? 'Semua resep yang masuk sudah ditangani. Muat ulang untuk memeriksa bila ada resep baru.'
                            : `Tidak ada resep berstatus "${LABEL_STATUS_RESEP[status]}" saat ini. Tampilkan semua status untuk melihat antrean lainnya.`
                    }
                    action={
                        status === '' ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                                data-slot="antrean-muat-ulang"
                                onClick={() => {
                                    void antrean.refetch();
                                }}
                            >
                                <RefreshCw aria-hidden />

                                Muat ulang
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                                data-slot="antrean-tampilkan-semua"
                                onClick={() => {
                                    pilihStatus('');
                                }}
                            >
                                Tampilkan semua status
                            </Button>
                        )
                    }
                />
            ) : (
                <>
                    <ul data-slot="antrean-resep" className="flex flex-col gap-3">
                        {antrean.data.data.resep.map((resep) => (
                            <li key={resep.id}>
                                <Card
                                    data-slot="antrean-resep-item"
                                    data-status={resep.status}
                                >
                                    <CardContent className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="flex min-w-0 flex-col gap-1.5">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-semibold">
                                                    {resep.nomor_resep}
                                                </span>

                                                <StatusResepBadge
                                                    status={resep.status}
                                                />

                                                {resep.is_kedaluwarsa ? (
                                                    <KedaluwarsaBadge />
                                                ) : null}
                                            </div>

                                            <p className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                                <span className="flex items-center gap-1.5">
                                                    <CalendarClock
                                                        aria-hidden
                                                        className="size-3.5 shrink-0"
                                                    />

                                                    Tanggal resep{' '}
                                                    {formatWaktuZona(
                                                        resep.tanggal_resep,
                                                    )}
                                                </span>

                                                <span className="flex items-center gap-1.5">
                                                    <Clock3
                                                        aria-hidden
                                                        className="size-3.5 shrink-0"
                                                    />

                                                    Berlaku sampai{' '}
                                                    {formatTanggal(
                                                        resep.berlaku_sampai,
                                                    )}
                                                </span>

                                                <span className="flex items-center gap-1.5">
                                                    <Pill
                                                        aria-hidden
                                                        className="size-3.5 shrink-0"
                                                    />

                                                    {resep.jumlah_item} obat
                                                </span>
                                            </p>
                                        </div>

                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="min-h-11 shrink-0"
                                            data-slot="antrean-buka"
                                            onClick={() => {
                                                onPilih(resep.id);
                                            }}
                                        >
                                            Buka verifikasi

                                            <ChevronRight aria-hidden />
                                        </Button>
                                    </CardContent>
                                </Card>
                            </li>
                        ))}
                    </ul>

                    <Pagination
                        meta={antrean.data.meta}
                        onPageChange={setHalaman}
                    />
                </>
            )}
        </div>
    );
}
