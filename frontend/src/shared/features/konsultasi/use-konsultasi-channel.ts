import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react';
import { connectEcho, disconnectEcho } from '@/lib/echo';
import {
    EchoRealtimeSocket,
    type RealtimeSocket,
    type RealtimeSocketListener,
    type SubscriptionState,
} from '@/lib/realtime/socket';
import type { KonsultasiPesan } from '@/lib/api/types';
import {
    KonsultasiRealtime,
    type RealtimeStats,
} from '@/lib/realtime/konsultasi-realtime';
import type { RealtimeDibaca } from '@/lib/realtime/read-receipt';
import {
    bolehKirimMengetik,
    mengetikKedaluwarsa,
    MENGETIK_KEDALUWARSA_MS,
    type RealtimeMengetik,
} from '@/lib/realtime/mengetik';
import { gabungTranscript } from '@/lib/realtime/transcript';

/**
 * TEST SEAM - dev/test only. Production never defines this global.
 *
 * The real transport is `EchoRealtimeSocket`, which builds an Echo client from the
 * Reverb build-time env. A Playwright spec cannot use it: there is no broker in the
 * mocked run (no WebSocket, no `/api/broadcasting/auth`), and without the Reverb env
 * `connectEcho()` throws during render. So the spec installs this factory from
 * `page.addInitScript` and gets a deterministic `RealtimeSocket` it can push
 * `chat.pesan` / `chat.dibaca` frames and `chat.mengetik` whispers through, on
 * demand.
 *
 * The seam is a read, not a switch: nothing in `src/` writes the property, and the
 * fallback below is what every production render takes. It stays deliberately tiny
 * and typed, and fails safe - if it were ever set to something that is not a
 * function the real transport is still used.
 */
declare global {
    // eslint-disable-next-line no-var
    var __sehatlyRealtimeSocketFactory:
        | ((listener: RealtimeSocketListener) => RealtimeSocket)
        | undefined;
}

function buatRealtimeSocket(listener: RealtimeSocketListener): RealtimeSocket {
    const factory = globalThis.__sehatlyRealtimeSocketFactory;

    return typeof factory === 'function'
        ? factory(listener)
        : new EchoRealtimeSocket(connectEcho(), listener);
}

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
        const socket = buatRealtimeSocket({
            onSignal: (signal) => {
                instance?.onSignal(signal);
            },
            onFrame: (frame) => {
                instance?.onFrame(frame);
            },
            onWhisper: (frame) => {
                instance?.onWhisper(frame);
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
    /** The latest `chat.dibaca` marker seen, or `null`. */
    dibaca: RealtimeDibaca | null;
    /** The other party's unexpired typing whisper, or `null`. */
    pengetik: RealtimeMengetik | null;
    /** Emit a throttled typing whisper; a no-op on a dead or unconfirmed channel. */
    kirimMengetik: () => void;
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
        /**
         * The caller's own account id, from `GET /me`.
         *
         * The whisper listener needs it to ignore a whisper that reaches this
         * client from its own connection, and to know which account id a
         * `chat.mengetik` payload must carry.
         */
        sayaUserId: number | null;
    },
): KonsultasiChannel {
    const client = realtimeClient();
    const { initial, fetchHistory, sayaUserId } = hooks;

    const [live, setLive] = useState<KonsultasiPesan[]>([]);
    const [subscriptionState, setSubscriptionState] = useState<SubscriptionState>(
        'idle',
    );
    const [dibaca, setDibaca] = useState<RealtimeDibaca | null>(null);
    const [pengetik, setPengetik] = useState<RealtimeMengetik | null>(null);

    /** Local receive time, so expiry never depends on the sender's clock. */
    const pengetikDiterima = useRef<number | null>(null);
    const pengetikTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const mengetikTerakhir = useRef<number | null>(null);

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
        mengetikTerakhir.current = null;
        setDibaca(null);
        setPengetik(null);

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
        };
    }, [client, konsultasiId]);

    /**
     * The two F08 event streams, and the expiry that keeps typing honest.
     *
     * A whisper is a hint with no history, so the WATCHER owns its lifetime: the
     * timer here is what guarantees the indicator cannot hang after the last
     * whisper, a send, or a disconnect. Expiry is measured from the local receive
     * time - a sender's `at` can be arbitrarily skewed.
     */
    useEffect(() => {
        mengetikTerakhir.current = null;

        const stopDibaca = client.onDibaca((marker) => {
            setDibaca(marker);
        });

        const stopMengetik = client.onMengetik((mengetik) => {
            if (sayaUserId !== null && mengetik.user_id === sayaUserId) {
                return;
            }

            pengetikDiterima.current = Date.now();
            setPengetik(mengetik);

            if (pengetikTimer.current !== null) {
                clearTimeout(pengetikTimer.current);
            }

            pengetikTimer.current = setTimeout(() => {
                pengetikTimer.current = null;

                if (mengetikKedaluwarsa(pengetikDiterima.current, Date.now())) {
                    pengetikDiterima.current = null;
                    setPengetik(null);
                }
            }, MENGETIK_KEDALUWARSA_MS);
        });

        const stopJatuh = client.onChange(() => {
            if (client.getStats().connected) {
                return;
            }

            pengetikDiterima.current = null;

            if (pengetikTimer.current !== null) {
                clearTimeout(pengetikTimer.current);
                pengetikTimer.current = null;
            }

            setPengetik(null);
        });

        return () => {
            stopDibaca();
            stopMengetik();
            stopJatuh();

            if (pengetikTimer.current !== null) {
                clearTimeout(pengetikTimer.current);
                pengetikTimer.current = null;
            }
        };
    }, [client, konsultasiId, sayaUserId]);

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

    const kirimMengetik = useCallback(() => {
        if (sayaUserId === null) {
            return;
        }

        const sekarang = Date.now();

        if (!bolehKirimMengetik(mengetikTerakhir.current, sekarang)) {
            return;
        }

        mengetikTerakhir.current = sekarang;

        client.kirimMengetik(konsultasiId, {
            user_id: sayaUserId,
            at: new Date(sekarang).toISOString(),
        });
    }, [client, konsultasiId, sayaUserId]);

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
        dibaca,
        pengetik,
        kirimMengetik,
    };
}
