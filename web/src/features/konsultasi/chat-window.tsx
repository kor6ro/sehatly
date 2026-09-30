import { useEffect, useRef, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import {
    Check,
    CheckCheck,
    FileText,
    Image as ImageIcon,
    Loader2,
    Paperclip,
    RefreshCw,
    Send,
    WifiOff,
} from 'lucide-react';
import { ApiError } from '@/lib/http';
import { formatWaktu } from '@/lib/format';
import {
    kirimPesanMutation,
    LABEL_PENGIRIM,
    TIPE_PESAN_BERKAS,
    TIPE_PESAN_LABEL,
} from '@/lib/api/konsultasi';
import type { KonsultasiPesan } from '@/lib/api/types';
import type { RealtimeStats } from '@/lib/realtime/konsultasi-realtime';
import type { SubscriptionState } from '@/lib/realtime/socket';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Field, FieldTextarea, FormErrorSummary } from '@/components/form/field';
import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/states/empty-state';
import { dispatchFlash } from '@/lib/flash';

/**
 * The consultation transcript.
 *
 * ## What this component does NOT do
 *
 * It does not deduplicate. It cannot: the transcript it is handed is already through
 * `MessageDedupe`, which is the only place that knows what has been delivered. A
 * second `Set` here would be a second, weaker copy of the same rule, and the two would
 * disagree the first time a message arrived over the socket before the history page
 * did.
 *
 * ## The status strip is not decoration
 *
 * It carries `data-subscription`, `data-duplicates-suppressed` and
 * `data-resyncs`. Those are load-bearing, not styling hooks:
 *
 * - `data-subscription="confirmed"` is the **broker's** `pusher:subscribed`, so
 *   "the realtime path works" is an observed fact rather than an inference from "no
 *   error appeared". A client that only knew it had sent the subscribe frame could not
 *   tell a working subscription from a refused one.
 * - `data-duplicates-suppressed` is the honest count of messages withheld because
 *   they had already arrived. On the sender's own screen it is **expected to be
 *   non-zero on every send**: the server broadcasts to the whole private channel and
 *   the sender is subscribed to it, so every message the author writes arrives twice.
 *   A count of zero there would mean the dedupe was NOT running.
 */
export function ChatWindow({
    konsultasiId,
    pesan,
    subscriptionState,
    stats,
    onResubscribe,
    onMarkRead,
    disabled = false,
    className,
}: {
    konsultasiId: number;
    pesan: KonsultasiPesan[];
    subscriptionState: SubscriptionState;
    stats: RealtimeStats;
    onResubscribe: () => Promise<void>;
    /** Called when the transcript is on screen and has unread lines from the other party. */
    onMarkRead?: () => void;
    disabled?: boolean;
    className?: string;
}) {
    const [draft, setDraft] = useState('');
    const akhir = useRef<HTMLDivElement>(null);

    const kirim = useMutation(kirimPesanMutation(konsultasiId));

    useEffect(() => {
        akhir.current?.scrollIntoView({ block: 'end' });
    }, [pesan.length]);

    const kirimTeks = async (): Promise<void> => {
        const isi = draft.trim();

        if (isi === '') {
            return;
        }

        setDraft('');

        try {
            await kirim.mutateAsync({ tipe_pesan: 'teks', isi });
        } catch (error) {
            // The draft is restored rather than discarded: a send that failed has
            // written nothing, and losing what the doctor typed because the network
            // blipped is not an acceptable trade for a clean input.
            setDraft(isi);

            dispatchFlash({
                level: 'error',
                message:
                    error instanceof ApiError
                        ? error.message
                        : 'Pesan gagal dikirim.',
            });
        }
    };

    return (
        <section
            data-slot="chat-window"
            data-konsultasi={konsultasiId}
            data-subscription={subscriptionState}
            data-duplicates-suppressed={stats.duplicateSuppressedCount}
            data-unidentified={stats.unidentifiedEventCount}
            data-resyncs={stats.resyncCount}
            data-connected={stats.connected ? 'true' : 'false'}
            className={cn('flex flex-col gap-3', className)}
        >
            <RealtimeStrip
                subscriptionState={subscriptionState}
                stats={stats}
                onResubscribe={onResubscribe}
            />

            {pesan.length === 0 ? (
                <EmptyState
                    compact
                    title="Belum ada pesan"
                    description="Belum ada pesan. Pesan pertama akan muncul di sini untuk Anda dan dokter."
                />
            ) : (
                <ol
                    data-slot="chat-pesan"
                    className="flex max-h-96 flex-col gap-2 overflow-y-auto"
                >
                    {pesan.map((baris) => (
                        <PesanBaris
                            key={baris.id}
                            pesan={baris}
                            onMarkRead={onMarkRead}
                        />
                    ))}
                </ol>
            )}

            <div ref={akhir} aria-hidden />

            {kirim.isError ? (
                <FormErrorSummary error={kirim.error} />
            ) : null}

            <form
                className="flex items-end gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    void kirimTeks();
                }}
            >
                <Field label="Tulis pesan" className="flex-1">
                    <FieldTextarea
                        rows={2}
                        value={draft}
                        disabled={disabled || kirim.isPending}
                        placeholder="Tulis pesan untuk dokter."
                        onChange={(event) => {
                            setDraft(event.target.value);
                        }}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter' && !event.shiftKey) {
                                event.preventDefault();
                                void kirimTeks();
                            }
                        }}
                    />
                </Field>

                <Button
                    type="submit"
                    disabled={disabled || kirim.isPending || draft.trim() === ''}
                >
                    {kirim.isPending ? <Loader2 className="animate-spin" /> : <Send />}

                    Kirim
                </Button>
            </form>

            {disabled ? (
                <p className="text-muted-foreground text-xs">
                    Percakapan ini sudah ditutup. Endpoint POST /chat menolak
                    menulis pada konsultasi yang tidak berlangsung.
                </p>
            ) : null}
        </section>
    );
}

/**
 * One message row.
 *
 * ## Grouped by sender, and the grouping is a visual fact not a query
 *
 * Consecutive rows from the same side share a label and a tighter gap, which is the
 * whole of "grouped by sender" - no `<table>`, no nested list, and no re-fetch. The
 * `pengirim_tipe` a bubble asks about is the three-value ENUM, not `users.tipe`, so
 * a system line is visibly not either party and is never rendered as one of them.
 */
function PesanBaris({
    pesan,
    onMarkRead,
}: {
    pesan: KonsultasiPesan;
    onMarkRead?: () => void;
}) {
    const [lihatLampiran, setLihatLampiran] = useState(false);
    const lampiran = (TIPE_PESAN_BERKAS as readonly string[]).includes(
        pesan.tipe_pesan,
    );

    useEffect(() => {
        if (pesan.dibaca_at === null && pesan.pengirim_tipe !== 'sistem') {
            onMarkRead?.();
        }
    }, [pesan.dibaca_at, pesan.pengirim_tipe, onMarkRead]);

    return (
        <li
            data-slot="chat-pesan-baris"
            data-pesan-id={pesan.id}
            data-pengirim={pesan.pengirim_tipe}
            data-tipe={pesan.tipe_pesan}
            className="bg-muted/40 flex flex-col gap-1 rounded-md px-3 py-2"
        >
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-xs font-semibold">
                    {LABEL_PENGIRIM[pesan.pengirim_tipe] ?? pesan.pengirim_tipe}
                </span>

                <span className="text-muted-foreground text-xs tabular-nums">
                    {formatWaktu(pesan.terkirim_at)}
                </span>

                {pesan.tipe_pesan !== 'teks' ? (
                    <Badge variant="secondary" className="text-xs">
                        {TIPE_PESAN_LABEL[pesan.tipe_pesan] ?? pesan.tipe_pesan}
                    </Badge>
                ) : null}

                {/**
                 * The read tick.
                 *
                 * Two states and one icon each, and a system row gets neither: neither
                 * party wrote it, so "read" would be a claim about somebody who
                 * cannot read.
                 */}
                {pesan.pengirim_tipe === 'sistem' ? null : pesan.dibaca_at === null ? (
                    <Check aria-hidden className="text-muted-foreground size-3" />
                ) : (
                    <CheckCheck
                        aria-label="Sudah dibaca"
                        className="text-success size-3"
                    />
                )}
            </div>

            {pesan.isi === null || pesan.isi === '' ? null : (
                <p className="text-sm whitespace-pre-wrap break-words">
                    {pesan.isi}
                </p>
            )}

            {lampiran ? (
                <div className="flex flex-col gap-1">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="w-fit"
                        onClick={() => {
                            setLihatLampiran((value) => !value);
                        }}
                    >
                        {pesan.tipe_pesan === 'gambar' ? (
                            <ImageIcon />
                        ) : (
                            <FileText />
                        )}

                        {lihatLampiran ? 'Sembunyikan' : 'Lihat'}

                        {pesan.file_ukuran_kb === null
                            ? ''
                            : ` (${pesan.file_ukuran_kb} KB)`}
                    </Button>

                    {lihatLampiran && pesan.file_url !== null ? (
                        pesan.tipe_pesan === 'gambar' ? (
                            <img
                                src={pesan.file_url}
                                alt={pesan.file_nama ?? 'Lampiran gambar'}
                                className="max-h-64 rounded-md border object-contain"
                            />
                        ) : (
                            <a
                                href={pesan.file_url}
                                target="_blank"
                                rel="noreferrer"
                                className="text-primary inline-flex items-center gap-1 text-sm underline"
                            >
                                <Paperclip aria-hidden />

                                {pesan.file_nama ?? pesan.file_url}
                            </a>
                        )
                    ) : null}
                </div>
            ) : null}
        </li>
    );
}

/**
 * The connection strip: what the socket is doing, and the button to make it stop.
 *
 * Degradation is a first-class state, not an error. The broker does not replay, so
 * every message sent while the socket was down exists only in the database - and the
 * transcript above is served by REST, which is why the history still loads and the
 * list is still correct. What is lost is the next few seconds of live delivery, and
 * saying so is the honest thing; a blank screen would be a lie.
 */
function RealtimeStrip({
    subscriptionState,
    stats,
    onResubscribe,
}: {
    subscriptionState: SubscriptionState;
    stats: RealtimeStats;
    onResubscribe: () => Promise<void>;
}) {
    const [sibuk, setSibuk] = useState(false);

    const label = ((): string => {
        if (subscriptionState === 'confirmed' && stats.connected) {
            return 'Tersambung realtime';
        }

        if (subscriptionState === 'refused') {
            return 'Kanal ditolak server';
        }

        if (subscriptionState === 'pending') {
            return 'Menghubungkan kanal';
        }

        return 'Mode REST, tanpa realtime';
    })();

    return (
        <div
            data-slot="realtime-status"
            data-subscription={subscriptionState}
            data-connected={stats.connected ? 'true' : 'false'}
            className="text-muted-foreground flex flex-wrap items-center gap-2 text-xs"
            role="status"
            aria-live="polite"
        >
            {stats.connected ? null : <WifiOff aria-hidden className="size-3" />}

            <span>{label}</span>

            {stats.connected ? null : (
                <span>Pesan tetap tersimpan dan dimuat ulang dari server.</span>
            )}

            {stats.lastFailure === null ? null : (
                <span className="text-destructive">
                    Sambungan terputus. Coba hubungkan ulang.
                </span>
            )}

            <Button
                type="button"
                variant="outline"
                size="sm"
                className="ml-auto"
                disabled={sibuk}
                onClick={() => {
                    setSibuk(true);
                    void onResubscribe().finally(() => {
                        setSibuk(false);
                    });
                }}
            >
                {sibuk ? <Loader2 className="animate-spin" /> : <RefreshCw />}

                Hubungkan ulang
            </Button>
        </div>
    );
}
