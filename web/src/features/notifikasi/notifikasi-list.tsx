import { useState } from 'react';
import { Link } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { Check, ChevronRight } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { formatWaktuZona } from '@/lib/waktu';
import { meOptions } from '@/lib/api/me';
import { labelTipeNotifikasi, notifikasiOptions } from '@/lib/api/notifikasi';
import type { Notifikasi, TipeNotifikasi } from '@/lib/api/types';
import {
    halamanIndeksTipe,
    ruteDariTautan,
} from '@/features/notifikasi/deep-link';
import { useTandaiDibaca } from '@/features/notifikasi/use-tandai-dibaca';
import { Button } from '@/components/ui/button';
import { Pagination } from '@/components/layout/pagination';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';
import { EmptyState } from '@/components/states/empty-state';

/**
 * The notification rows, shared by the full page and - in shape, not in markup - by the
 * shell dropdown.
 *
 * ## Deep links are resolved, never passed through
 *
 * `tautan` is an API path. {@link ruteDariTautan} is the only thing allowed to turn one
 * into an SPA destination, and it answers `null` for everything except the four anchored
 * patterns in the F11 table. A row with a resolvable `tautan` is a `<Link>` whose
 * accessible name is "Buka {judul}"; every other row is plain content with a sentence
 * naming where the reader can go instead, and no anchor at all. The raw `tautan` string
 * is never rendered - showing it as text was the old behaviour and it leaks an API path
 * into a user-facing surface.
 *
 * ## The time is `formatWaktuZona`, because `dibuat_at` is an instant
 *
 * `notifikasi.dibuat_at` is a `TIMESTAMP` published as ISO-8601 UTC, so it is converted
 * to the device zone and labelled (`_global.md` §5). A bare `09.30` is a defect: the
 * device zone can be set manually and be wrong.
 *
 * ## Medical text is `text-base`, never truncated
 *
 * `isi` may legitimately carry a cancellation reason or a prescription summary for the
 * account that owns the row. `web/AGENTS.md` requires medical text at a readable size
 * and forbids ellipsis, so the summary line is `text-base` and carries no `truncate` or
 * `line-clamp`.
 */

function IsiBaris({ row }: { row: Notifikasi }) {
    return (
        <>
            <p className="text-base font-medium">
                <span
                    data-slot="notifikasi-tipe"
                    className="text-muted-foreground mr-2 text-xs"
                >
                    {labelTipeNotifikasi(row.tipe)}
                </span>

                <span data-slot="notifikasi-judul">{row.judul}</span>
            </p>

            <p data-slot="notifikasi-isi" className="text-muted-foreground text-base">
                {row.isi}
            </p>

            <p data-slot="notifikasi-waktu" className="text-muted-foreground text-xs">
                {formatWaktuZona(row.dibuat_at)}
            </p>
        </>
    );
}

/**
 * The §4.3 fallback for a `tautan` that is present but unmapped.
 *
 * The page name comes from the row's own type, so a booking row says "Buka dari halaman
 * Booking" and a `sistem` row - which has no index route - stops after the first
 * sentence. There is deliberately no anchor here: AC-3 asserts that a malicious or
 * unknown `tautan` produces no navigation, and the index pages are one nav entry away.
 */
function PesanTakDikenal({ tipe }: { tipe: TipeNotifikasi }) {
    const halaman = halamanIndeksTipe(tipe);

    return (
        <p data-slot="notifikasi-tautan-tak-dikenal" className="text-muted-foreground text-sm">
            Tidak dapat dibuka langsung.
            {halaman === null ? '' : ` Buka dari halaman ${halaman}.`}
        </p>
    );
}

export function NotifikasiList({
    unread,
    perPage = 10,
    compact = false,
    onTampilkanSemua,
}: {
    unread?: 'true' | 'false';
    perPage?: number;
    compact?: boolean;
    /** Clears the parent's filter, for the empty-filter state's way out. */
    onTampilkanSemua?: () => void;
}) {
    const [page, setPage] = useState(1);

    const me = useQuery(meOptions());
    const { online, tandaiSatu, sedangMenandai } = useTandaiDibaca();

    const notifikasi = useQuery(
        notifikasiOptions({
            page,
            per_page: perPage,
            ...(unread === undefined ? {} : { unread }),
        }),
    );

    if (notifikasi.isPending) {
        return (
            <div data-slot="notifikasi-loading">
                <SkeletonRows rows={compact ? 3 : 6} />
            </div>
        );
    }

    if (notifikasi.isError && notifikasi.error instanceof ApiError && notifikasi.error.isForbidden) {
        return (
            <ForbiddenState
                detail={notifikasi.error.message}
                action={
                    <Button asChild variant="outline" className="min-h-11">
                        <Link to="/dashboard">Kembali</Link>
                    </Button>
                }
            />
        );
    }

    if (notifikasi.isError && notifikasi.data === undefined) {
        return (
            <ErrorState
                error={notifikasi.error}
                title="Gagal memuat notifikasi."
                onRetry={() => {
                    void notifikasi.refetch();
                }}
            />
        );
    }

    const rows = notifikasi.data?.data.notifikasi ?? [];
    const tipePengguna = me.data?.data.user.tipe;

    return (
        <div className="flex flex-col gap-4">
            {rows.length === 0 ? (
                unread === 'true' ? (
                    <EmptyState
                        compact={compact}
                        title="Tidak ada notifikasi baru."
                        description="Semua notifikasi sudah dibaca."
                        action={
                            onTampilkanSemua === undefined ? undefined : (
                                <Button
                                    data-slot="notifikasi-tampilkan-semua"
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={onTampilkanSemua}
                                >
                                    Tampilkan semua
                                </Button>
                            )
                        }
                    />
                ) : (
                    <EmptyState
                        compact={compact}
                        title="Belum ada notifikasi."
                        description="Notifikasi muncul saat janji temu dibuat, pembayaran diterima, resep siap, atau ada pesan baru."
                    />
                )
            ) : (
                <ul data-slot="notifikasi-list" className="flex flex-col divide-y">
                    {rows.map((row) => {
                        const rute = ruteDariTautan(row.tautan, tipePengguna, row.payload);
                        const belumDibaca = row.dibaca_at === null;

                        return (
                            <li
                                key={row.id}
                                data-slot="notifikasi-item"
                                data-tipe={row.tipe}
                                data-dibaca={belumDibaca ? 'false' : 'true'}
                                className="flex flex-col py-3"
                            >
                                {rute === null ? (
                                    <div
                                        data-slot="notifikasi-item-isi"
                                        className="flex min-h-11 flex-col gap-1"
                                    >
                                        <IsiBaris row={row} />

                                        {row.tautan === null ? null : (
                                            <PesanTakDikenal tipe={row.tipe} />
                                        )}
                                    </div>
                                ) : (
                                    <Link
                                        data-slot="notifikasi-item-link"
                                        to={rute}
                                        aria-label={`Buka ${row.judul}`}
                                        className="focus-visible:ring-ring/50 hover:bg-accent/50 -mx-2 flex min-h-11 flex-row items-center gap-2 rounded-md px-2 py-1 outline-none focus-visible:ring-[3px]"
                                        onClick={() => {
                                            tandaiSatu(row.id, !belumDibaca);
                                        }}
                                    >
                                        <span className="flex flex-1 flex-col gap-1">
                                            <IsiBaris row={row} />
                                        </span>

                                        <ChevronRight
                                            aria-hidden
                                            className="text-muted-foreground size-5 shrink-0"
                                        />
                                    </Link>
                                )}

                                <div className="mt-2 flex items-center justify-between gap-2">
                                    {belumDibaca ? (
                                        <Button
                                            data-slot="notifikasi-baca"
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="min-h-11"
                                            disabled={sedangMenandai}
                                            aria-disabled={
                                                !online || sedangMenandai ? true : undefined
                                            }
                                            onClick={() => {
                                                tandaiSatu(row.id, false);
                                            }}
                                        >
                                            <Check aria-hidden />

                                            Tandai dibaca
                                        </Button>
                                    ) : (
                                        <span
                                            data-slot="notifikasi-dibaca"
                                            className="text-muted-foreground flex items-center gap-2 text-sm"
                                        >
                                            <Check aria-hidden className="size-4" />

                                            Dibaca
                                        </span>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}

            {compact ? null : (
                <Pagination
                    meta={notifikasi.data?.meta}
                    onPageChange={(next) => {
                        setPage(next);
                    }}
                />
            )}
        </div>
    );
}
