import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react';
import { connectEcho, disconnectEcho } from '@/lib/echo';
import { EchoRealtimeSocket, type SubscriptionState } from '@/lib/realtime/socket';
import type { KonsultasiPesan } from '@/lib/api/types';
import {
    KonsultasiRealtime,
    type RealtimeStats,
} from '@/lib/realtime/konsultasi-realtime';
import { gabungTranscript } from '@/lib/realtime/transcript';

/**
 * The one realtime client, created once for the tab.
 *
 * Module scope rather than a React context because its lifetime is the tab's, not a
 * component's: a client rebuilt on every render of every consultation screen would
 * throw the dedupe set away on each navigation, and the next message would render a
 * second time. The Dart client argues the same point for `disconnect()` keeping the
 * subscription registry and the dedupe set.
 */
let instance: KonsultasiRealtime | null = null;

export function realtimeClient(): KonsultasiRealtime {
    if (instance === null) {
        const socket = new EchoRealtimeSocket(connectEcho(), {
            onSignal: (signal) => {
                instance?.onSignal(signal);
            },
            onFrame: (frame) => {
                instance?.onFrame(frame);
            },
            onSubscriptionState: (channel, state) => {
                instance?.onSubscriptionState(channel, state);
            },
        });

        instance = new KonsultasiRealtime(socket);
    }

    return instance;
}

/**
 * Release the tab's socket. Called from the sign-out path only.
 *
 * A route change deliberately does **not** do this: keeping the socket and the dedupe
 * set across a navigation is what stops a back-navigation from re-rendering a
 * transcript the user has already read.
 */
export function releaseRealtime(): void {
    disconnectEcho();
    instance = null;
}

export type KonsultasiChannel = {
    /**
     * The transcript, oldest first, already through the dedupe gate.
     *
     * Ordered on `(terkirim_at, id)` here rather than trusted, because
     * `terkirim_at` is a one-second-resolution `TIMESTAMP` and a burst ties - the
     * same tie the server's own query breaks with `id`.
     */
    messages: KonsultasiPesan[];
    subscriptionState: SubscriptionState;
    stats: RealtimeStats;
    /** The caller-driven half of recovery, mirroring the Dart `resubscribe()`. */
    resubscribe: () => Promise<void>;
};

/**
 * Bind a component to the realtime client for one consultation.
 *
 * ## The subscribe is an EFFECT, and that effect owns the resync handler
 *
 * The handler is installed and removed in the same effect that subscribes, so the
 * history fetch a reconnect triggers is always scoped to the consultation on screen
 * and never outlives it. The alternative - a settable `onConnect` property, which is
 * the Dart client's shape - needs a second teardown, and the leak that causes is a
 * resync writing a previous consultation's rows into the current transcript.
 *
 * ## The history page is SEEDED; the resync backfill is DELIVERED
 *
 * They look alike and they are opposites. `initial` is on screen already, so
 * remembering its ids and staying quiet is right. A resync backfill is the window the
 * broker never replayed, so it has to be delivered - see `MessageDedupe.seedHistory`.
 */
export function useKonsultasiChannel(
    konsultasiId: number,
    hooks: {
        /** The history page the caller is already rendering. */
        initial: readonly KonsultasiPesan[];
        /** Re-fetched after every reconnect to close the gap the broker did not replay. */
        fetchHistory: () => Promise<readonly KonsultasiPesan[]>;
    },
): KonsultasiChannel {
    const client = realtimeClient();
    const { initial, fetchHistory } = hooks;

    const [live, setLive] = useState<KonsultasiPesan[]>([]);
    const [subscriptionState, setSubscriptionState] = useState<SubscriptionState>(
        'idle',
    );

    /**
     * Held in a ref, not read from the closure.
     *
     * The subscribe effect must not re-run because the caller's `fetchHistory` is a
     * new function identity on every render - that would unsubscribe and resubscribe
     * on each render, which is a `POST /api/broadcasting/auth` per render against an
     * endpoint that rate limits, and it is the mechanism by which a chat ends up
     * permanently "reconnecting".
     */
    const fetchHistoryRef = useRef(fetchHistory);

    fetchHistoryRef.current = fetchHistory;

    useEffect(() => {
        const stopMessages = client.onMessage((message) => {
            if (message.konsultasi_id !== konsultasiId) {
                return;
            }

            setLive((sebelumnya) =>
                sebelumnya.some((row) => row.id === message.id)
                    ? sebelumnya
                    : [...sebelumnya, message],
            );
        });

        const stopStates = client.onChange(() => {
            setSubscriptionState(
                client.getSubscription(konsultasiId)?.state ?? 'idle',
            );
        });

        client.setResyncHandler(async () => fetchHistoryRef.current());
        client.connect();

        void client.subscribeKonsultasi(konsultasiId);

        return () => {
            stopMessages();
            stopStates();
            client.setResyncHandler(null);
            client.unsubscribe(konsultasiId);
            setLive([]);
        };    }, [client, konsultasiId]);

    /**
     * Seed the dedupe set from whatever history the caller is showing.
     *
     * Runs on every change of `initial`, which is every successful refetch - and
     * that is the point. A history page that arrives while a live frame for the same
     * row is already on screen is exactly the overlap the dedupe exists for, and
     * re-seeding is what makes the second arrival of that row a no-op.
     */
    useEffect(() => {
        client.seedHistory(initial);
    }, [client, initial]);

    const resubscribe = useCallback(async () => {
        await client.resubscribe();
    }, [client]);

    const gabung = useMemo(
        () => gabungTranscript(initial, live),
        [initial, live],
    );

    const stats = useSyncExternalStore(
        (listener) => client.onChange(listener),
        () => client.getStats(),
        () => client.getStats(),
    );

    return {
        messages: gabung,
        subscriptionState,
        stats,
        resubscribe,
    };
}
