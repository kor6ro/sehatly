import type { KonsultasiPesan } from '@/lib/api/types';
import { MessageDedupe } from '@/lib/realtime/dedupe';
import { konsultasiChannel } from '@/lib/realtime/channel';
import {
    bacaDariPayload,
    type RealtimeDibaca,
} from '@/lib/realtime/read-receipt';
import {
    mengetikDariPayload,
    type RealtimeMengetik,
} from '@/lib/realtime/mengetik';
import type {
    RealtimeFrame,
    RealtimeSignal,
    RealtimeSocket,
    RealtimeSocketListener,
    RealtimeWhisperFrame,
    SubscriptionState,
} from '@/lib/realtime/socket';

/** The event name without a leading dot, matching `KonsultasiMessageSent::broadcastAs()`. */
export const CHAT_PESAN_EVENT = 'chat.pesan';

/** The read-marker event, matching `KonsultasiChatDibaca::broadcastAs()`. */
export const CHAT_DIBACA_EVENT = 'chat.dibaca';

/**
 * The typing whisper name. Client-only: no server event, route, write or queue
 * exists for it, by owner decision (F08 §12).
 */
export const CHAT_MENGETIK_EVENT = 'chat.mengetik';

/** A gap the reconnect opened, and the rows that closed it. */
export type RealtimeResync = {
    attempt: number;
    occurredAt: number;
};

/**
 * Supplies the history a resync needs, and may return nothing.
 *
 * The callback rather than a fetch inside this class, for the same reason the Dart
 * client asks instead of fetching: the REST call needs the application's own
 * pagination, its 401 refresh and its error handling, all of which already exist in
 * `lib/http.ts`. What this class keeps is the part only it can do - putting the rows
 * through the same dedupe gate a live frame goes through.
 */
export type RealtimeResyncCallback = (
    resync: RealtimeResync,
) => Promise<readonly KonsultasiPesan[] | null> | null;

export type RealtimeStats = {
    connected: boolean;
    resyncCount: number;
    disconnectCount: number;
    subscribeAttemptCount: number;
    duplicateSuppressedCount: number;
    unidentifiedEventCount: number;
    /** The most recent transport or authorisation failure, or `null`. */
    lastFailure: string | null;
};

export type RealtimeSubscription = {
    channelName: string;
    state: SubscriptionState;
    lastFailure: string | null;
    attempts: number;
};

/**
 * The realtime layer for a consultation transcript.
 *
 * Ported from `packages/sehatly_api_client/lib/src/realtime/realtime_client.dart`,
 * and it keeps that class's three obligations because they are the three a naive
 * socket wrapper gets wrong:
 *
 * 1. **A message is delivered once.** Keyed on `konsultasi_chat.id` - the row's own
 *    primary key - seeded from the REST history so the overlap between a resync and
 *    a live frame is suppressed. See `dedupe.ts` for why no other key works.
 * 2. **A reconnect re-subscribes and then backfills.** In that order, and the order
 *    is load-bearing (see {@link KonsultasiRealtime.resume}).
 * 3. **The access token is re-read on every subscribe.** Not cached at construction.
 *    The transport does the reading; see `EchoRealtimeSocket.subscribe`.
 *
 * ## It never rotates a token
 *
 * `POST /auth/refresh` rotates both tokens and revokes the presented refresh token
 * on every use, and a *replayed* one revokes **every live refresh token for the
 * account**. A subscribe has no safe way to rotate: it may be one of several
 * concurrent subscribes, and two of them rotating would make the second look like a
 * theft and sign the user out of every device. A subscribe against an expired token
 * is refused, and the right recovery is the application's own guarded rotation in
 * `lib/http.ts`. So there is no retry loop here at all - a refused channel is
 * reported, and the only two things that try again are an explicit `resubscribe()`
 * and a reconnect.
 */
export class KonsultasiRealtime implements RealtimeSocketListener {
    private readonly socket: RealtimeSocket;

    private readonly dedupe: MessageDedupe;

    private readonly subscriptions = new Map<string, RealtimeSubscription>();

    private readonly messageListeners = new Set<(message: KonsultasiPesan) => void>();

    private readonly dibacaListeners = new Set<(dibaca: RealtimeDibaca) => void>();

    private readonly mengetikListeners = new Set<(mengetik: RealtimeMengetik) => void>();

    private readonly changeListeners = new Set<() => void>();

    private resyncHandler: RealtimeResyncCallback | null = null;

    private connected = false;

    private resyncCount = 0;

    private disconnectCount = 0;

    private subscribeAttemptCount = 0;

    private lastFailure: string | null = null;

    private pemulihan: ReturnType<typeof setTimeout> | null = null;

    private readonly pemulihanMs: number;

    private snapshot: RealtimeStats = {
        connected: false,
        resyncCount: 0,
        disconnectCount: 0,
        subscribeAttemptCount: 0,
        duplicateSuppressedCount: 0,
        unidentifiedEventCount: 0,
        lastFailure: null,
    };

    constructor(
        socket: RealtimeSocket,
        dedupeCapacity = 500,
        pemulihanMs = 12_000,
    ) {
        this.socket = socket;
        this.dedupe = new MessageDedupe(dedupeCapacity);
        this.pemulihanMs = pemulihanMs < 1 ? 1 : pemulihanMs;
    }

    /**
     * Subscribe to a live message, deduplicated, in arrival order.
     *
     * Returns an unsubscribe function. Listeners are held in a `Set`, so a
     * transcript and a badge can both watch without either stealing events from the
     * other.
     */
    onMessage(listener: (message: KonsultasiPesan) => void): () => void {
        this.messageListeners.add(listener);

        return () => {
            this.messageListeners.delete(listener);
        };
    }

    /**
     * Subscribe to `chat.dibaca`, already shape-validated.
     *
     * Not deduplicated: the payload is a marker, not a row, and a repeated
     * delivery of the same marker is harmless because the consumer keeps the
     * later of two timestamps.
     */
    onDibaca(listener: (dibaca: RealtimeDibaca) => void): () => void {
        this.dibacaListeners.add(listener);

        return () => {
            this.dibacaListeners.delete(listener);
        };
    }

    /** Subscribe to the ephemeral `chat.mengetik` whisper, already shape-validated. */
    onMengetik(listener: (mengetik: RealtimeMengetik) => void): () => void {
        this.mengetikListeners.add(listener);

        return () => {
            this.mengetikListeners.delete(listener);
        };
    }

    /**
     * Send a typing whisper, but only on a CONFIRMED subscription.
     *
     * The broker's `pusher_internal:subscription_succeeded` is the gate: sending
     * before it either falls into the transport's pre-subscribe warning or, on
     * Reverb, is refused with "not a member of the specified channel". No error
     * is surfaced - a typing hint that could not be sent is not a failure the
     * user can act on.
     */
    kirimMengetik(konsultasiId: number, mengetik: RealtimeMengetik): void {
        const channelName = konsultasiChannel(konsultasiId);
        const subscription = this.subscriptions.get(channelName);

        if (subscription === undefined || subscription.state !== 'confirmed') {
            return;
        }

        this.socket.whisper(channelName, CHAT_MENGETIK_EVENT, {
            user_id: mengetik.user_id,
            at: mengetik.at,
        });
    }

    /** Subscribe to counter and connection-state changes, for the status strip. */
    onChange(listener: () => void): () => void {
        this.changeListeners.add(listener);

        return () => {
            this.changeListeners.delete(listener);
        };
    }

    /**
     * Install the resync handler after construction.
     *
     * A mutable field rather than a constructor argument because the natural
     * caller is a React component that does not exist yet when the client is built -
     * the client is created once at start-up, the handler when the consultation
     * screen mounts. `null` means a reconnect re-subscribes and nothing else, which
     * leaves a gap: the broker does not replay, so anything sent while the socket
     * was down exists only in the database.
     */
    setResyncHandler(handler: RealtimeResyncCallback | null): void {
        this.resyncHandler = handler;
    }

    /**
     * Remember rows the renderer has already displayed, without delivering them.
     *
     * This is the "deduplicate against the REST history" rule, and it is a separate
     * call from the resync because a cold start has to do it too - there was no
     * reconnect to hang it off.
     */
    seedHistory(rows: readonly KonsultasiPesan[]): void {
        this.dedupe.seedHistory(rows);
    }

    getStats(): RealtimeStats {
        return this.snapshot;
    }

    getSubscription(konsultasiId: number): RealtimeSubscription | null {
        return this.subscriptions.get(konsultasiChannel(konsultasiId)) ?? null;
    }

    hasSeen(id: number): boolean {
        return this.dedupe.has(id);
    }

    connect(): void {
        this.socket.connect();
    }

    /**
     * Re-open the socket after a drop that pusher-js has parked.
     *
     * ## Why this exists, measured rather than assumed
     *
     * pusher-js retries a closed connection only while its own state is `connecting`
     * or `connected` (`Connection.shouldRetry()`); the retry is `retryIn(1000)` from the
     * `closed` callback. If the outage outlasts `unavailableTimeout` - 10 s by
     * default - the state becomes `unavailable`, `shouldRetry()` is false, and the
     * library **stops trying**. It never comes back on its own.
     *
     * So a tab that loses the network for eleven seconds comes back to a chat that is
     * permanently dead, with no error and no path to recovery except the user
     * pressing "Hubungkan ulang". That was measured over 90 s: `data-connected` stayed
     * `false` and `data-resyncs` stayed `0` for every sample.
     *
     * A watchdog is the right shape rather than a permanent retry loop, because the
     * loop bound is the point: this is one bounded nudge, and the transport's own
     * backoff still governs the attempt itself. It is armed only by a `disconnected`
     * that is still unresolved, and cleared the moment a connection is observed, so a
     * healthy tab schedules nothing.
     */
    private jadwalkanPemulihan(): void {
        if (this.pemulihan !== null) {
            return;
        }

        this.pemulihan = setTimeout(() => {
            this.pemulihan = null;

            if (this.connected) {
                return;
            }

            this.socket.connect();
        }, this.pemulihanMs);
    }

    private batalkanPemulihan(): void {
        if (this.pemulihan === null) {
            return;
        }

        clearTimeout(this.pemulihan);
        this.pemulihan = null;
    }

    /** Closes the transport and keeps the subscriptions and the dedupe set. */
    disconnect(): void {
        this.socket.disconnect();
    }

    /**
     * Re-open the socket if needed, then re-dispatch every live subscription,
     * re-reading the auth headers.
     *
     * The caller-driven half of recovery, and the counterpart of the Dart client's
     * `resubscribe()`. It covers the two cases a reconnect cannot:
     *
     * - the transport **parked**. pusher-js stops retrying once its state is
     *   `unavailable` (see {@link jadwalkanPemulihan}), so a channel cannot be
     *   re-subscribed onto a socket that will not reopen by itself. `connect()` first
     *   is what makes this button mean what its label says.
     * - the token **rotated** while the socket stayed up, so every channel is
     *   subscribed under a credential that no longer exists.
     */
    async resubscribe(): Promise<void> {
        this.socket.connect();

        for (const subscription of [...this.subscriptions.values()]) {
            await this.dispatchSubscribe(subscription);
        }
    }

    /**
     * Subscribes to a consultation's chat channel.
     *
     * Registers the subscription **synchronously** and dispatches afterwards.
     * Registering first is what makes a reconnect arriving before the dispatch
     * finishes still find the channel and re-subscribe it.
     */
    async subscribeKonsultasi(konsultasiId: number): Promise<void> {
        const channelName = konsultasiChannel(konsultasiId);

        let subscription = this.subscriptions.get(channelName);

        if (subscription === undefined) {
            subscription = {
                channelName,
                state: 'idle',
                lastFailure: null,
                attempts: 0,
            };

            this.subscriptions.set(channelName, subscription);
        }

        await this.dispatchSubscribe(subscription);
    }

    unsubscribe(konsultasiId: number): void {
        const channelName = konsultasiChannel(konsultasiId);

        if (!this.subscriptions.delete(channelName)) {
            return;
        }

        this.socket.unsubscribe(channelName);
        this.publish();
    }

    onSignal = (signal: RealtimeSignal): void => {
        switch (signal.kind) {
            case 'connected':
                this.connected = true;
                this.batalkanPemulihan();
                break;
            case 'disconnected':
                this.connected = false;
                this.disconnectCount += 1;
                this.jadwalkanPemulihan();

                /**
                 * Every subscription is invalid the moment the socket drops, so every
                 * one is marked `pending` again. Leaving them `confirmed` would make
                 * the published state a lie: a screen would keep saying "tersambung
                 * realtime" over a dead socket, and a check that waits for
                 * `confirmed` after a drop would pass instantly against a channel that
                 * is not delivering. `pending` is the honest word for "the subscribe
                 * we had is gone and a new one has to be dispatched", which is exactly
                 * what `resume()` is about to do.
                 */
                for (const subscription of this.subscriptions.values()) {
                    subscription.state = 'pending';
                }

                break;
            case 'reconnected':
                this.connected = true;
                this.batalkanPemulihan();

                void this.resume();
                break;
            case 'failure':
                this.lastFailure = signal.reason;
                break;
        }

        this.publish();
    };

    onFrame = (frame: RealtimeFrame): void => {
        if (frame.eventName === CHAT_PESAN_EVENT) {
            this.deliver(frame.data as KonsultasiPesan);

            return;
        }

        if (frame.eventName === CHAT_DIBACA_EVENT) {
            const dibaca = bacaDariPayload(frame.data);

            if (dibaca === null) {
                return;
            }

            for (const listener of [...this.dibacaListeners]) {
                listener(dibaca);
            }
        }
    };

    onWhisper = (frame: RealtimeWhisperFrame): void => {
        if (frame.eventName !== CHAT_MENGETIK_EVENT) {
            return;
        }

        const mengetik = mengetikDariPayload(frame.data);

        if (mengetik === null) {
            return;
        }

        for (const listener of [...this.mengetikListeners]) {
            listener(mengetik);
        }
    };

    onSubscriptionState = (channelName: string, state: SubscriptionState): void => {
        const subscription = this.subscriptions.get(channelName);

        if (subscription === undefined) {
            return;
        }

        subscription.state = state;
        this.publish();
    };

    /**
     * Put one message through the dedupe gate and deliver it if it is new.
     *
     * A message with no id is delivered and counted, never suppressed: it cannot be
     * deduplicated, and swallowing it loses a message while making the client look
     * healthy.
     */
    private deliver(message: KonsultasiPesan): void {
        if (message.id <= 0) {
            this.dedupe.countUnidentified();
            this.emit(message);
            this.publish();

            return;
        }

        if (!this.dedupe.remember(message.id)) {
            this.publish();

            return;
        }

        this.emit(message);
        this.publish();
    }

    /**
     * Deliver a resync's backfill, which is the OPPOSITE of seeding history.
     *
     * The backfill is the window the broker never replayed, so at least part of it
     * has never been rendered. Putting it through the same gate as a live frame is
     * what makes a reconnect neither lose the gap nor render the overlap twice.
     */
    private async resume(): Promise<void> {
        this.resyncCount += 1;

        /**
         * Re-subscribe FIRST, resync second.
         *
         * The broker begins delivering on a channel the moment the subscribe is
         * confirmed, and the resync is where history is fetched over REST. If the
         * resync ran first, a message sent during the fetch would arrive live
         * *before* the history page it belongs to and the transcript would render it
         * out of order. Re-subscribing first means the live path is already up when
         * the history lands, and the dedupe set - which the backfill feeds - then
         * suppresses the overlap in whichever order it arrives.
         */
        for (const subscription of [...this.subscriptions.values()]) {
            try {
                await this.dispatchSubscribe(subscription);
            } catch {
                /**
                 * Already reported by {@link dispatchSubscribe} with the wire name
                 * and the transport's own error, so reporting it again would double
                 * every failure in the log. What this catch buys is that the LOOP
                 * survives: one dead channel must not stop the others being
                 * re-subscribed, or a single revoked consultation would silence every
                 * channel on the connection.
                 */
            }
        }

        const handler = this.resyncHandler;

        if (handler === null) {
            this.publish();

            return;
        }

        try {
            const backfill = await handler({
                attempt: this.resyncCount,
                occurredAt: Date.now(),
            });

            for (const message of backfill ?? []) {
                this.deliver(message);
            }
        } catch (error) {
            /**
             * A failed backfill costs the gap and nothing else: the live path is up,
             * and the next reconnect tries again. Surfaced rather than swallowed so
             * the UI can say the transcript may be missing something.
             */
            this.lastFailure = `Riwayat setelah reconnect gagal dimuat: ${String(error)}`;
        }

        this.publish();
    }

    private async dispatchSubscribe(
        subscription: RealtimeSubscription,
    ): Promise<void> {
        subscription.attempts += 1;
        this.subscribeAttemptCount += 1;
        subscription.state = 'pending';
        this.publish();

        try {
            await this.socket.subscribe({
                channelName: subscription.channelName,
                serverEvents: [CHAT_PESAN_EVENT, CHAT_DIBACA_EVENT],
                whisperEvents: [CHAT_MENGETIK_EVENT],
            });

            subscription.lastFailure = null;
        } catch (error) {
            const reason = `Subscribe ke ${subscription.channelName} gagal: ${String(error)}`;

            subscription.lastFailure = reason;
            subscription.state = 'refused';
            this.lastFailure = reason;
            this.publish();

            throw error;
        }
    }

    private emit(message: KonsultasiPesan): void {
        for (const listener of [...this.messageListeners]) {
            listener(message);
        }
    }

    private publish(): void {
        this.snapshot = {
            connected: this.connected,
            resyncCount: this.resyncCount,
            disconnectCount: this.disconnectCount,
            subscribeAttemptCount: this.subscribeAttemptCount,
            duplicateSuppressedCount: this.dedupe.duplicateSuppressedCount,
            unidentifiedEventCount: this.dedupe.unidentifiedEventCount,
            lastFailure: this.lastFailure,
        };

        for (const listener of [...this.changeListeners]) {
            listener();
        }
    }
}
