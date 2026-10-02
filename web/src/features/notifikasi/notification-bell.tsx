import { Link } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Bell, Loader2 } from 'lucide-react';
import {
    labelTipeNotifikasi,
    notifikasiBadgeOptions,
    tandaiSemuaDibacaMutation,
    unreadDari,
} from '@/lib/api/notifikasi';
import { meOptions } from '@/lib/api/me';
import { formatWaktuZona } from '@/lib/waktu';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { ruteDariTautan } from '@/features/notifikasi/deep-link';
import { useTandaiDibaca } from '@/features/notifikasi/use-tandai-dibaca';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { SkeletonRows } from '@/components/states/loading-state';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * The shell's notification bell, with the unread badge the plan asks for.
 *
 * ## The badge is `meta.unread`, and that is not a page total
 *
 * `NotifikasiController::index()` adds `unread` to the pagination block from a SEPARATE
 * count of the caller's own `dibaca_at IS NULL` rows, so it is invariant across `?unread`
 * and `?page`. A badge derived from `meta.total` would show the page size, and one derived
 * from the rows on screen would show at most `per_page`.
 *
 * ## Rows are deep links, and the whole row is the target
 *
 * The dropdown used to render plain text. Each row now resolves through the same
 * {@link ruteDariTautan} whitelist the full page uses: a matched `tautan` becomes a
 * `<Link>` whose accessible name is "Buka {judul}", and everything else stays a
 * non-clickable block. A click on a link marks the row read fire-and-forget and then
 * navigates; a failed `PUT` never blocks the route change. The bell itself is a 44 px
 * target, per `web/AGENTS.md`.
 *
 * ## `refetchInterval` is a badge cadence, and it stops in a hidden tab
 *
 * `notifikasiBadgeOptions()` polls every {@link NOTIFIKASI_REFETCH_MS}. The endpoint carries
 * no `throttle:` middleware, so nothing is being pushed against; `refetchIntervalInBackground`
 * is left off so a backgrounded tab stops asking rather than polling all night.
 */
export function NotificationBell() {
    const online = useOnlineStatus();
    const badge = useQuery(notifikasiBadgeOptions());
    const me = useQuery(meOptions());
    const tandaiSemua = useMutation(tandaiSemuaDibacaMutation());
    const { tandaiSatu } = useTandaiDibaca();

    const unread = unreadDari(badge.data?.meta);
    const rows = badge.data?.data.notifikasi ?? [];
    const tipePengguna = me.data?.data.user.tipe;
    const tanpaData = badge.data === undefined;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    data-slot="notifikasi-bell"
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="relative size-11"
                    aria-label={
                        unread === 0
                            ? 'Notifikasi'
                            : `Notifikasi, ${unread} belum dibaca`
                    }
                >
                    <Bell aria-hidden />

                    {unread === 0 ? null : (
                        <Badge
                            data-slot="notifikasi-badge"
                            variant="destructive"
                            className="absolute -top-1 -right-1 px-1.5 py-0 text-[10px]"
                        >
                            {unread > 99 ? '99+' : unread}
                        </Badge>
                    )}
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-80">
                <DropdownMenuLabel className="flex items-center justify-between">
                    <span>Notifikasi</span>

                    {unread === 0 ? null : (
                        <span className="text-muted-foreground text-xs font-normal">
                            {unread} belum dibaca
                        </span>
                    )}
                </DropdownMenuLabel>

                <DropdownMenuSeparator />

                {badge.isPending ? (
                    <div data-slot="notifikasi-dropdown-loading" className="px-2 py-2">
                        <SkeletonRows rows={3} />
                    </div>
                ) : badge.isError && tanpaData ? (
                    <p
                        data-slot="notifikasi-dropdown-galat"
                        className="text-muted-foreground px-2 py-4 text-sm"
                    >
                        Gagal memuat notifikasi.
                    </p>
                ) : rows.length === 0 ? (
                    <p
                        data-slot="notifikasi-kosong"
                        className="text-muted-foreground px-2 py-4 text-sm"
                    >
                        Belum ada notifikasi.
                    </p>
                ) : (
                    <ul
                        data-slot="notifikasi-dropdown-list"
                        className="flex max-h-80 flex-col overflow-y-auto"
                    >
                        {rows.map((row) => {
                            const rute = ruteDariTautan(
                                row.tautan,
                                tipePengguna,
                                row.payload,
                            );
                            const belumDibaca = row.dibaca_at === null;

                            return (
                                <li
                                    key={row.id}
                                    data-slot="notifikasi-dropdown-item"
                                    data-dibaca={belumDibaca ? 'false' : 'true'}
                                >
                                    {rute === null ? (
                                        <div className="flex flex-col gap-0.5 px-2 py-2">
                                            <span className="text-muted-foreground mr-1 text-xs">
                                                {labelTipeNotifikasi(row.tipe)}
                                            </span>

                                            <span data-slot="notifikasi-dropdown-judul" className="text-sm">
                                                {row.judul}
                                            </span>

                                            <span className="text-muted-foreground text-xs">
                                                {formatWaktuZona(row.dibuat_at)}
                                            </span>
                                        </div>
                                    ) : (
                                        <DropdownMenuItem asChild>
                                            <Link
                                                data-slot="notifikasi-dropdown-link"
                                                to={rute}
                                                aria-label={`Buka ${row.judul}`}
                                                className="flex min-h-11 flex-col items-start gap-0.5"
                                                onClick={() => {
                                                    tandaiSatu(row.id, !belumDibaca);
                                                }}
                                            >
                                                <span className="text-muted-foreground text-xs">
                                                    {labelTipeNotifikasi(row.tipe)}
                                                </span>

                                                <span data-slot="notifikasi-dropdown-judul" className="text-sm">
                                                    {row.judul}
                                                </span>

                                                <span className="text-muted-foreground text-xs">
                                                    {formatWaktuZona(row.dibuat_at)}
                                                </span>
                                            </Link>
                                        </DropdownMenuItem>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}

                <DropdownMenuSeparator />

                <DropdownMenuItem asChild className="min-h-11">
                    <Link to="/notifikasi">Lihat semua notifikasi</Link>
                </DropdownMenuItem>

                <DropdownMenuItem
                    data-slot="notifikasi-baca-semua"
                    className="min-h-11"
                    disabled={unread === 0 || tandaiSemua.isPending || !online}
                    onSelect={() => {
                        if (!online) {
                            return;
                        }

                        tandaiSemua.mutate();
                    }}
                >
                    {tandaiSemua.isPending ? (
                        <Loader2 className="animate-spin" aria-hidden />
                    ) : null}

                    Tandai semua dibaca
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
