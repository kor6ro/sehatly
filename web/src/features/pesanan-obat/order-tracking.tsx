import { useQuery } from '@tanstack/react-query';
import { MapPin, PackageSearch, Truck } from 'lucide-react';
import { formatRupiah, formatWaktu } from '@/lib/format';
import { labelStatusPesanan, pesananObatOptions, urutanStatusPesanan } from '@/lib/api/pesanan-obat';
import { STATUS_PESANAN_TERMINAL } from '@/lib/api/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState, NotFoundState } from '@/components/states/error-state';
import { EmptyState } from '@/components/states/empty-state';
import { ApiError } from '@/lib/http';

/**
 * `pesanan_obat_tracking` as a vertical timeline.
 *
 * ## The current state comes from `pesanan.status`, not from the last row
 *
 * The trail is append-only history and the query behind it carries **no `orderBy`**, so
 * the rows come back in InnoDB primary-key order - oldest-first in practice, not by
 * contract. Deriving the state from the final row would therefore be reading an accident.
 * The badge reads `pesanan.status` and the timeline is shown for what it is: a history.
 *
 * ## Four of the six statuses cannot be produced by any endpoint that exists
 *
 * `PesananObatStateMachine::TRANSISI` reaches all six, but the state machine is not wired
 * to a route, so settlement is the only automatic move and it lands on `diproses`. The
 * timeline renders every row it is given and labels every status, including a seventh
 * value the `VARCHAR(100)` column would permit: `urutanStatusPesanan` returns `null` for
 * it, and a row with no marker is drawn without one rather than pinned to step 0.
 */
export function OrderTracking({ pesananId }: { pesananId: number }) {
    const pesanan = useQuery({
        ...pesananObatOptions(pesananId),
        refetchInterval: (query) => {
            const status = query.state.data?.data.pesanan.status;

            return status === undefined || STATUS_PESANAN_TERMINAL.includes(status)
                ? false
                : 15_000;
        },
    });

    if (pesanan.isPending) {
        return <SkeletonRows rows={6} />;
    }

    if (pesanan.isError) {
        if (pesanan.error instanceof ApiError && pesanan.error.isForbidden) {
            return (
                <ForbiddenState detail="Endpoint ini hanya untuk akun pasien, apoteker, admin, atau superadmin." />
            );
        }

        if (pesanan.error instanceof ApiError && pesanan.error.isNotFound) {
            return (
                <NotFoundState
                    title="Pesanan tidak ditemukan"
                    detail="Id tersebut tidak ada atau bukan milik pihak yang berhak."
                />
            );
        }

        return (
            <ErrorState
                error={pesanan.error}
                onRetry={() => {
                    void pesanan.refetch();
                }}
            />
        );
    }

    const order = pesanan.data.data.pesanan;
    const trail = order.tracking ?? [];

    return (
        <div data-slot="order-tracking" className="flex flex-col gap-4">
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <PackageSearch aria-hidden />

                        Pesanan {order.nomor_pesanan}
                    </CardTitle>

                    <CardDescription>
                        Status saat ini{' '}
                        <span data-slot="tracking-status" className="font-medium">
                            {labelStatusPesanan(order.status)}
                        </span>
                        , dibaca dari `pesanan_obat.status`. Dibuat {formatWaktu(order.dibuat_at)}.
                    </CardDescription>
                </CardHeader>

                <CardContent>
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <dt className="text-muted-foreground">Subtotal</dt>
                        <dd className="text-right tabular-nums">{formatRupiah(order.subtotal)}</dd>

                        <dt className="text-muted-foreground">Biaya kirim</dt>
                        <dd className="text-right tabular-nums">
                            {formatRupiah(order.biaya_kirim)}
                        </dd>

                        <dt className="text-muted-foreground">Total</dt>
                        <dd className="text-right font-semibold tabular-nums">
                            {formatRupiah(order.total)}
                        </dd>

                        <dt className="text-muted-foreground">Nomor resi</dt>
                        <dd className="text-right font-mono">
                            {order.no_resi ?? 'Belum ada'}
                        </dd>
                    </dl>
                </CardContent>
            </Card>

            {trail.length === 0 ? (
                <EmptyState
                    title="Belum ada riwayat"
                    description="Endpoint ini hanya mengembalikan tracking pada respons detail. Pesanan yang baru dibuat belum punya baris riwayat selain baris pertama."
                />
            ) : (
                <ol data-slot="tracking-timeline" className="flex flex-col">
                    {trail.map((row) => {
                        const urutan = urutanStatusPesanan(row.status);

                        return (
                            <li
                                key={row.id}
                                data-slot="tracking-row"
                                data-status={row.status}
                                data-urutan={urutan === null ? 'tidak-dikenal' : String(urutan)}
                                className="border-l-2 py-3 pl-4"
                            >
                                <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                    {row.status === 'sedang_dikirim' ? (
                                        <Truck aria-hidden className="size-3.5" />
                                    ) : null}

                                    {labelStatusPesanan(row.status)}
                                </p>

                                <p className="text-muted-foreground text-xs">
                                    {formatWaktu(row.waktu)}
                                </p>

                                {row.keterangan === null ? null : (
                                    <p data-slot="tracking-keterangan" className="mt-1 text-sm">
                                        {row.keterangan}
                                    </p>
                                )}

                                {row.lokasi === null ? null : (
                                    <p
                                        data-slot="tracking-lokasi"
                                        className="text-muted-foreground mt-1 flex items-center gap-1 text-xs"
                                    >
                                        <MapPin aria-hidden className="size-3" />

                                        {row.lokasi}
                                    </p>
                                )}
                            </li>
                        );
                    })}
                </ol>
            )}
        </div>
    );
}
