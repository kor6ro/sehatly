import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { CheckCheck } from 'lucide-react';
import {
    notifikasiBadgeOptions,
    notifikasiQueryKey,
    tandaiSemuaDibacaMutation,
    unreadDari,
} from '@/lib/api/notifikasi';
import { queryClient } from '@/lib/query-client';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { OfflineBanner } from '@/components/offline-banner';
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
 * `total` describes the filtered page-set. This line reads the badge query's own `unread`,
 * the same query the shell bell reads, so the two cannot disagree about the account.
 *
 * ## Mark-all is disabled at zero, and the badge comes from a refetch
 *
 * `{ditandai: 0}` is the honest answer for "already read", so the mutation treats it as
 * success and never writes a local zero: `tandaiSemuaDibacaMutation` invalidates the
 * notification prefix on settle, and the badge changes only when the refetch answers.
 * The control is disabled while `meta.unread === 0`, so the second click of a double
 * click cannot spend another request.
 *
 * ## Offline: detect and block, never queue
 *
 * `_global.md` §7 #1 forbids queueing writes. The banner explains that the rows are the
 * last stored data, the reason under the status line says the two write actions are
 * unavailable, and the buttons keep `aria-disabled` rather than disappearing. Coming
 * back online invalidates the notification prefix, so the screen catches up without a
 * reload.
 *
 * ## `perawat` and `kurir` are refused here, permanently
 *
 * They are real `users.tipe` values that hold no role at all, so `notifikasi.lihat` is
 * never granted and every route in the group answers 403. The list renders the server's
 * own message inside `ForbiddenState` and no rows exist to leak, which is AC-10.
 */
export function NotifikasiPage() {
    useDocumentTitle('Notifikasi | Sehatly');

    const [filter, setFilter] = useState<'semua' | 'true'>('semua');
    const online = useOnlineStatus();

    const badge = useQuery(notifikasiBadgeOptions());
    const tandaiSemua = useMutation(tandaiSemuaDibacaMutation());

    const unread = unreadDari(badge.data?.meta);
    const tanpaData = badge.data === undefined;
    const tidakAdaUnread = !tanpaData && unread === 0;

    const sebelumnyaOnline = useRef(online);

    useEffect(() => {
        if (online && !sebelumnyaOnline.current) {
            void queryClient.invalidateQueries({ queryKey: notifikasiQueryKey });
        }

        sebelumnyaOnline.current = online;
    }, [online]);

    const tombolTerkunci = tanpaData || tidakAdaUnread || tandaiSemua.isPending;

    return (
        <>
            <PageHeader
                title="Notifikasi"
                description="Pembaruan tentang janji temu, pembayaran, resep, dan konsultasi Anda."
                action={
                    <>
                        <Button
                            data-slot="notifikasi-filter"
                            type="button"
                            variant={filter === 'semua' ? 'default' : 'outline'}
                            aria-pressed={filter === 'semua'}
                            className="min-h-11"
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
                            aria-pressed={filter === 'true'}
                            className="min-h-11"
                            disabled={tidakAdaUnread}
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
                            className="min-h-11"
                            disabled={tombolTerkunci}
                            aria-disabled={!online || tombolTerkunci ? true : undefined}
                            onClick={() => {
                                if (!online) {
                                    return;
                                }

                                tandaiSemua.mutate();
                            }}
                        >
                            <CheckCheck aria-hidden />

                            Tandai semua dibaca
                        </Button>
                    </>
                }
            />

            <OfflineBanner message="Anda sedang offline. Notifikasi yang tampil adalah data terakhir yang tersimpan." />

            <p
                data-slot="notifikasi-status"
                role="status"
                aria-live="polite"
                className="text-muted-foreground text-sm"
            >
                {tanpaData ? (
                    badge.isError ? (
                        'Jumlah belum dibaca tidak tersedia.'
                    ) : (
                        'Memuat jumlah belum dibaca...'
                    )
                ) : (
                    <>
                        <span className="tabular-nums" data-slot="notifikasi-unread">
                            {unread}
                        </span>{' '}
                        belum dibaca.
                    </>
                )}
            </p>

            {!online ? (
                <p data-slot="notifikasi-offline-alasan" className="text-muted-foreground text-sm">
                    Tandai dibaca dan tandai semua dibaca dinonaktifkan sampai koneksi kembali.
                </p>
            ) : null}

            <NotifikasiList
                key={filter}
                {...(filter === 'semua' ? {} : { unread: filter })}
                onTampilkanSemua={() => {
                    setFilter('semua');
                }}
            />
        </>
    );
}
