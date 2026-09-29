import { Link } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Bell, Loader2 } from 'lucide-react';
import {
    NOTIFIKASI_REFETCH_MS,
    labelTipeNotifikasi,
    notifikasiBadgeOptions,
    tandaiSemuaDibacaMutation,
    unreadDari,
} from '@/lib/api/notifikasi';
import { formatWaktu } from '@/lib/format';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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
 * ## `refetchInterval` is a badge cadence, and it stops in a hidden tab
 *
 * `notifikasiBadgeOptions()` polls every {@link NOTIFIKASI_REFETCH_MS}. The endpoint carries
 * no `throttle:` middleware, so nothing is being pushed against; `refetchIntervalInBackground`
 * is left off so a backgrounded tab stops asking rather than polling all night.
 *
 * ## PDP consent is not a gate, on purpose
 *
 * `PersetujuanPdp::require()` is called from exactly one place in the whole application,
 * `SuratKeteranganService:255`, and it gates referral letters. `NotificationService` has no
 * consent check. Hiding a patient's own booking and payment confirmations behind a consent
 * they never gave would be inventing a rule the API does not have.
 */
export function NotificationBell() {
    const badge = useQuery(notifikasiBadgeOptions());
    const tandaiSemua = useMutation(tandaiSemuaDibacaMutation());

    const unread = unreadDari(badge.data?.meta);
    const rows = badge.data?.data.notifikasi ?? [];

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    data-slot="notifikasi-bell"
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="relative"
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
                    <p className="text-muted-foreground flex items-center gap-2 px-2 py-4 text-sm">
                        <Loader2 className="animate-spin" aria-hidden />

                        Memuat notifikasi...
                    </p>
                ) : rows.length === 0 ? (
                    <p
                        data-slot="notifikasi-kosong"
                        className="text-muted-foreground px-2 py-4 text-sm"
                    >
                        Belum ada notifikasi.
                    </p>
                ) : (
                    <ul data-slot="notifikasi-dropdown-list" className="max-h-80 flex-col overflow-y-auto">
                        {rows.map((row) => (
                            <li
                                key={row.id}
                                data-slot="notifikasi-dropdown-item"
                                data-dibaca={row.dibaca_at === null ? 'false' : 'true'}
                                className="flex flex-col gap-0.5 px-2 py-2"
                            >
                                <p className="text-sm">
                                    <span className="text-muted-foreground mr-1 text-xs">
                                        {labelTipeNotifikasi(row.tipe)}
                                    </span>

                                    {row.judul}
                                </p>

                                <p className="text-muted-foreground text-xs">
                                    {row.isi}
                                </p>

                                <p className="text-muted-foreground text-xs">
                                    {formatWaktu(row.dibuat_at)}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}

                <DropdownMenuSeparator />

                <DropdownMenuItem asChild>
                    <Link to="/notifikasi">Lihat semua notifikasi</Link>
                </DropdownMenuItem>

                <DropdownMenuItem
                    data-slot="notifikasi-baca-semua"
                    disabled={unread === 0 || tandaiSemua.isPending}
                    onSelect={() => {
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
