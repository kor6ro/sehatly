import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Check } from 'lucide-react';
import { formatWaktu } from '@/lib/format';
import {
    labelTipeNotifikasi,
    notifikasiOptions,
    tandaiDibacaMutation,
} from '@/lib/api/notifikasi';
import { Button } from '@/components/ui/button';
import { Pagination } from '@/components/layout/pagination';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState } from '@/components/states/error-state';
import { EmptyState } from '@/components/states/empty-state';

/**
 * The notification rows, shared by the shell dropdown and the full page.
 *
 * ## It owns its own pagination, because the page's `meta` and the badge's `meta` differ
 *
 * The badge query asks for `per_page: 5` and this one asks for 10, so their `meta` blocks
 * describe different pages. Reading pagination from the badge's `meta` would page a list
 * that was never fetched. The list reads its OWN query's `meta`; the page only picks the
 * filter.
 *
 * ## There is no `channel`, so nothing here offers one
 *
 * `notifikasi` has no channel column and no delivery-state column, so push-delivery
 * outcome is unrepresentable and is logged to the application log instead. A per-channel
 * toggle or a "delivered" badge would be inventing a fact.
 *
 * ## `tautan` is an API PATH, not one of this SPA's routes
 *
 * `NotificationService` publishes `/api/v1/booking/1001` and `/api/v1/invoice/12`, which
 * are backend paths. Rendering one as an in-app link would navigate to a 404, so the path
 * is shown as text and the row is marked read instead.
 */
export function NotifikasiList({
    unread,
    perPage = 10,
    compact = false,
}: {
    unread?: 'true' | 'false';
    perPage?: number;
    /** The shell dropdown: no pagination controls, fewer rows. */
    compact?: boolean;
}) {
    const [page, setPage] = useState(1);

    const notifikasi = useQuery(
        notifikasiOptions({
            page,
            per_page: perPage,
            ...(unread === undefined ? {} : { unread }),
        }),
    );
    const tandai = useMutation(tandaiDibacaMutation());

    if (notifikasi.isPending) {
        return <SkeletonRows rows={compact ? 3 : 6} />;
    }

    if (notifikasi.isError) {
        return (
            <ErrorState
                error={notifikasi.error}
                onRetry={() => {
                    void notifikasi.refetch();
                }}
            />
        );
    }

    const rows = notifikasi.data.data.notifikasi;

    return (
        <div className="flex flex-col gap-4">
            {rows.length === 0 ? (
                <EmptyState
                    compact={compact}
                    title={
                        unread === 'true'
                            ? 'Tidak ada notifikasi baru'
                            : 'Belum ada notifikasi'
                    }
                    description="Notifikasi dibuat server saat booking dibuat, booking dibatalkan, pembayaran selesai, resep siap, atau ada pesan baru di konsultasi."
                />
            ) : (
                <ul data-slot="notifikasi-list" className="flex flex-col divide-y">
                    {rows.map((row) => (
                        <li
                            key={row.id}
                            data-slot="notifikasi-item"
                            data-tipe={row.tipe}
                            data-dibaca={row.dibaca_at === null ? 'false' : 'true'}
                            className="flex flex-col gap-1 py-3"
                        >
                            <p className="text-sm font-medium">
                                <span
                                    data-slot="notifikasi-tipe"
                                    className="text-muted-foreground mr-2 text-xs"
                                >
                                    {labelTipeNotifikasi(row.tipe)}
                                </span>

                                {row.judul}
                            </p>

                            <p className="text-muted-foreground text-xs">{row.isi}</p>

                            <div className="flex items-center justify-between gap-2">
                                <span className="text-muted-foreground text-xs">
                                    {formatWaktu(row.dibuat_at)}
                                </span>

                                {row.dibaca_at === null ? (
                                    <Button
                                        data-slot="notifikasi-baca"
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        disabled={tandai.isPending}
                                        onClick={() => {
                                            tandai.mutate(row.id);
                                        }}
                                    >
                                        <Check aria-hidden />

                                        Tandai dibaca
                                    </Button>
                                ) : (
                                    <span className="text-muted-foreground flex items-center gap-1 text-xs">
                                        <Check aria-hidden className="size-3" />

                                        Dibaca
                                    </span>
                                )}
                            </div>

                            {row.tautan === null ? null : (
                                <p className="text-muted-foreground font-mono text-xs">
                                    {row.tautan}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {compact ? null : (
                <Pagination
                    meta={notifikasi.data.meta}
                    onPageChange={(next) => {
                        setPage(next);
                    }}
                />
            )}
        </div>
    );
}
