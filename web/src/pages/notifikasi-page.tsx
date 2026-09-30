import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { CheckCheck } from 'lucide-react';
import { notifikasiBadgeOptions, tandaiSemuaDibacaMutation, unreadDari } from '@/lib/api/notifikasi';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { NotifikasiList } from '@/features/notifikasi/notifikasi-list';

/**
 * `/notifikasi` - the full notification centre.
 *
 * ## The `unread` filter is a STRING, and it is three-state
 *
 * `IndexNotifikasiRequest` applies `Rule::in(['true','1','false','0'])` to a **string**,
 * deliberately not Laravel's `boolean` rule, so `?unread=true` is the literal string
 * `"true"`. Omitted means no filter, which is NOT the same as `false` - `false` means
 * read-only. Sending `unread=false` for "show everything" would silently hide every unread
 * row, which is the opposite of what a patient who just cleared their badge expects.
 *
 * ## The headline count is `meta.unread`, never `meta.total`
 *
 * `NotifikasiController::index()` computes `unread` from a separate count of the caller's
 * own `dibaca_at IS NULL` rows, so it is invariant across `?unread` and `?page` while
 * `total` describes the filtered page-set. This line reads the badge query's own `unread`;
 * the list below reads its own `meta` for pagination, because the two queries ask for
 * different page sizes.
 *
 * ## `perawat` and `kurir` are refused here, permanently
 *
 * They are real `users.tipe` values that hold no role at all, so `notifikasi.lihat` is
 * never granted and every route in the group answers 403. That is a known gap in the RBAC
 * catalogue rather than something this client can work around, and the error state shows
 * the server's own message.
 */
export function NotifikasiPage() {
    const [filter, setFilter] = useState<'semua' | 'true'>('semua');

    const badge = useQuery(notifikasiBadgeOptions());
    const tandaiSemua = useMutation(tandaiSemuaDibacaMutation());

    const unread = unreadDari(badge.data?.meta);

    return (
        <>
            <PageHeader
                title="Notifikasi"
                description="Jumlah notifikasi yang belum dibaca berbeda dengan total notifikasi di halaman ini."
                action={
                    <>
                        <Button
                            data-slot="notifikasi-filter"
                            type="button"
                            variant={filter === 'semua' ? 'default' : 'outline'}
                            onClick={() => {
                                setFilter('semua');
                            }}
                        >
                            Semua
                        </Button>

                        <Button
                            data-slot="notifikasi-filter-unread"
                            type="button"
                            variant={filter === 'true' ? 'default' : 'outline'}
                            disabled={unread === 0}
                            onClick={() => {
                                setFilter('true');
                            }}
                        >
                            Belum dibaca
                        </Button>

                        <Button
                            data-slot="notifikasi-baca-semua"
                            type="button"
                            variant="outline"
                            disabled={unread === 0 || tandaiSemua.isPending}
                            onClick={() => {
                                tandaiSemua.mutate();
                            }}
                        >
                            <CheckCheck aria-hidden />

                            Tandai semua dibaca
                        </Button>
                    </>
                }
            />

            <p className="text-muted-foreground text-sm">
                <span data-slot="notifikasi-unread">{unread}</span> belum dibaca.
            </p>

            <NotifikasiList {...(filter === 'semua' ? {} : { unread: filter })} />
        </>
    );
}
