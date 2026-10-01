import type Echo from 'laravel-echo';
import { applyAccessToken } from '@/lib/echo';
import { PRIVATE_CHANNEL_PREFIX } from '@/lib/realtime/channel';

export {
    PRIVATE_CHANNEL_PREFIX,
    konsultasiChannel,
    wireName,
} from '@/lib/realtime/channel';

type ConnectionStatus =
    | 'connected'
    | 'disconnected'
    | 'connecting'
    | 'reconnecting'
    | 'failed';

/**
 * The Pusher protocol as a transport seam, matching
 * `packages/sehatly_api_client/lib/src/realtime/realtime_socket.dart` in shape.
 *
 * Reverb and Soketi both speak the Pusher protocol, so the wire format is the
 * stable part and the socket library is not. Narrowing it to a four-verb
 * interface is what makes the resume and dedupe paths in `KonsultasiRealtime`
 * testable against a fake with no port open and no broker running.
 *
 * The channel-naming constants this file used to own now live in `./channel`, a leaf
 * with no transport import, so `KonsultasiRealtime` can use them without pulling this
 * module - and therefore `laravel-echo` and `import.meta.env` - into a plain
 * `node --test` run. They are re-exported above so no other importer changes.
 */

/** What a transport reports about the connection, as a closed set. */
export type RealtimeSignal =
    /** Up, and no gap preceded it: the first subscribe needs no resync. */
    | { kind: 'connected' }
    /** Down. Every subscription is invalid. */
    | { kind: 'disconnected' }
    /**
     * Up after a drop. The trigger for the whole resume path: re-subscribe every
     * channel, then re-fetch history, because the broker replayed nothing.
     */
    | { kind: 'reconnected' }
    /** The transport failed, or the broker refused a subscribe. */
    | { kind: 'failure'; reason: string };

/** One inbound frame, carrying the LOGICAL channel name by construction. */
export type RealtimeFrame = {
    channelName: string;
    /** The event name without a leading dot, e.g. `chat.pesan`. */
    eventName: string;
    data: unknown;
};

/**
 * One inbound CLIENT whisper, the Pusher `.client-*` counterpart of a frame.
 *
 * Whispers travel only between the subscribers of an already-authorized private
 * channel and are never persisted, so `eventName` here is the logical name the
 * caller passed to `.whisper()` - not the `client-` wire name.
 */
export type RealtimeWhisperFrame = {
    channelName: string;
    /** The whisper name without the `client-` prefix, e.g. `chat.mengetik`. */
    eventName: string;
    data: unknown;
};

/**
 * Where a channel is in the subscribe handshake.
 *
 * `confirmed` is a positive statement from the broker (`pusher:subscribed`), not
 * "we sent the frame and hope". The difference is the whole diagnosis: a client that
 * only knows "sent" cannot tell a working subscription from a refused one, and a
 * refused one is the most common Reverb integration failure there is.
 */
export type SubscriptionState = 'idle' | 'pending' | 'confirmed' | 'refused';

export type RealtimeSocketListener = {
    onSignal: (signal: RealtimeSignal) => void;
    onFrame: (frame: RealtimeFrame) => void;
    onWhisper: (frame: RealtimeWhisperFrame) => void;
    onSubscriptionState: (channelName: string, state: SubscriptionState) => void;
};

/** One subscribe, as handed to the transport. */
export type RealtimeSubscribeRequest = {
    /** `konsultasi.5`, no prefix. */
    channelName: string;
    /** Server-sent event names, no leading dot: `chat.pesan`, `chat.dibaca`. */
    serverEvents: readonly string[];
    /** Client whisper names, no `client-` prefix: `chat.mengetik`. */
    whisperEvents: readonly string[];
};

export interface RealtimeSocket {
    /** Opens the connection. Idempotent, and separate from subscribe on purpose. */
    connect(): void;

    /**
     * Closes the connection and drops every subscription, keeping the client's
     * subscription registry and dedupe set - which is the difference between
     * `disconnect()` and `dispose()` in the Dart client.
     */
    disconnect(): void;

    /**
     * Authorises and subscribes, re-reading the access token immediately before the
     * auth `POST`. Throws only on a synchronous failure; a REFUSED subscribe is
     * reported through {@link RealtimeSocketListener.onSubscriptionState}.
     */
    subscribe(request: RealtimeSubscribeRequest): Promise<void>;

    /** Drops the subscription and its listeners. Idempotent. */
    unsubscribe(channelName: string): void;

    /**
     * Sends a client whisper on a subscribed channel.
     *
     * Callers must not rely on a whisper being deliverable: the transport drops
     * it when the channel is not subscribed, and nothing is persisted. A typing
     * signal is a hint, never state.
     */
    whisper(channelName: string, eventName: string, data: Record<string, unknown>): void;

    /** The current handshake state of one channel. */
    subscriptionState(channelName: string): SubscriptionState;
}

/**
 * The Echo + Pusher implementation.
 *
 * Three things it must get right, and all three are checked against the installed
 * packages rather than against a vendor's prose:
 *
 * - **The auth endpoint and headers.** Derived from `bootstrap/app.php`; see
 *   `BROADCAST_AUTH_ENDPOINT` in `lib/echo.ts`.
 * - **The event binding carries a leading dot.** `EventFormatter.format()`
 *   (`laravel-echo/src/util/event-formatter.ts`) strips a leading `.` and otherwise
 *   prefixes Echo's default namespace `App.Events`, so binding the bare
 *   `chat.pesan` waits on a channel that never fires - a subscription that
 *   confirms and a transcript that never updates.
 * - **The token is re-read per subscribe, not per connection.** `bearerToken` is
 *   consumed once in `Connector.setOptions()` and never again; see
 *   `applyAccessToken`.
 */
export class EchoRealtimeSocket implements RealtimeSocket {
    private readonly echo: Echo<'reverb'>;

    private readonly listener: RealtimeSocketListener;

    private unbindConnection: (() => void) | null = null;

    private hasConnected = false;

    private readonly states = new Map<string, SubscriptionState>();

    private readonly teardown = new Map<string, () => void>();

    /**
     * The Echo channel per logical name, kept for outgoing whispers.
     *
     * `whisper()` needs the live channel object, and Echo's `.private(name)` is
     * NOT idempotent in the way this needs: it constructs another `PusherPrivateChannel`
     * and re-subscribes it. Holding the one created by `subscribe()` is what makes
     * a whisper go out on the subscription that was actually authorized.
     */
    private readonly channels = new Map<
        string,
        ReturnType<Echo<'reverb'>['private']>
    >();

    constructor(echo: Echo<'reverb'>, listener: RealtimeSocketListener) {
        this.echo = echo;
        this.listener = listener;
    }

    connect(): void {
        if (this.unbindConnection === null) {
            this.unbindConnection = this.echo.connector.onConnectionChange((status) => {
                this.laporkan(status);
            });
        }

        this.echo.connector.connect();

        /**
         * Read the state we may have MISSED, immediately after binding.
         *
         * `Connector::connect()` has already run - the `PusherConnector` constructor opens
         * the socket before this object exists - so by the time `onConnectionChange` binds
         * its listeners, the handshake has usually already completed and the one
         * `connected` event has already been emitted into nothing.
         *
         * `onConnectionChange` deliberately does not invoke the callback with the current
         * status, so without this the client believes it is **permanently disconnected**
         * while messages are in fact arriving. Measured, not reasoned about: the symptom
         * is a strip reading "Mode REST, tanpa realtime" over a socket that is delivering
         * every frame, and a resume that never fires because no `connected` was ever
         * observed.
         *
         * `connect()` is idempotent and this runs on every call. The first report is
         * harmless because `hasConnected` is still false, so it resolves to `connected`
         * and triggers no resync; a later call reports a state the client already holds,
         * which the switch in `laporkan()` turns into no signal at all.
         */
        this.laporkan(this.echo.connector.connectionStatus());
    }

    /**
     * Turn one pusher connection state into at most one signal.
     *
     * A method rather than a closure so `connect()` can call it both for the bound
     * events and for the immediate post-bind read.
     */
    private laporkan(status: ConnectionStatus): void {
        if (status === 'connected') {
            this.listener.onSignal({
                kind: this.hasConnected ? 'reconnected' : 'connected',
            });

            this.hasConnected = true;

            return;
        }

        if (status === 'disconnected' || status === 'failed') {
            this.listener.onSignal({ kind: 'disconnected' });
        }

        /**
         * `connecting` and `reconnecting` are deliberately ignored. pusher-js passes
         * through every `state_change` including those two, and reporting them as a drop
         * would fire a resume per retry - which is a re-subscribe per retry, and one
         * `POST /api/broadcasting/auth` each, against an endpoint that rate limits.
         */
    }

    async subscribe(request: RealtimeSubscribeRequest): Promise<void> {
        applyAccessToken();

        const { channelName, serverEvents, whisperEvents } = request;

        if (this.teardown.has(channelName)) {
            return;
        }

        const channel = this.echo.private(channelName);
        const wire = `${PRIVATE_CHANNEL_PREFIX}${channelName}`;

        const boundFrames: Array<[string, (data: unknown) => void]> = [];

        for (const eventName of serverEvents) {
            const onFrame = (data: unknown): void => {
                this.listener.onFrame({ channelName, eventName, data });
            };

            channel.listen(`.${eventName}`, onFrame);
            boundFrames.push([eventName, onFrame]);
        }

        const boundWhispers: Array<[string, (data: unknown) => void]> = [];

        for (const eventName of whisperEvents) {
            const onWhisper = (data: unknown): void => {
                this.listener.onWhisper({ channelName, eventName, data });
            };

            channel.listenForWhisper(eventName, onWhisper);
            boundWhispers.push([eventName, onWhisper]);
        }

        /**
         * `.subscribed()` and `.error()` are Echo's own wrappers around
         * `pusher:subscription_succeeded` and `pusher:subscription_error`, bound
         * through the channel's `subscription` object.
         *
         * The obvious alternative - `pusher.channel(wire).bind(...)` - is a runtime
         * `TypeError`, and a silent one worth recording: pusher-js's
         * `Pusher.prototype.channel(name)` only *looks up* an already-created channel
         * and returns `undefined` otherwise, and at this point in `subscribe()` the
         * channel exists only because `PusherChannel`'s own constructor has just
         * called `this.pusher.subscribe(this.name)`. The symptom is a page error
         * beside a subscription the BROKER has already confirmed - so the transcript
         * works while the UI insists the channel was refused. That was measured, not
         * reasoned about.
         */
        channel.subscribed(() => {
            this.setState(channelName, 'confirmed');
        });

        channel.error((failure: unknown) => {
            const status = (failure as { status?: number } | null)?.status;

            this.setState(
                channelName,
                'refused',
                `Subscribe ke ${wire} ditolak dengan status ${String(status)}.`,
            );
        });

        this.setState(channelName, 'pending');

        this.channels.set(channelName, channel);

        this.teardown.set(channelName, () => {
            for (const [name, handler] of boundFrames) {
                channel.stopListening(`.${name}`, handler);
            }

            for (const [name, handler] of boundWhispers) {
                channel.stopListeningForWhisper(name, handler);
            }

            channel.stopListening('pusher:subscription_succeeded');
            channel.stopListening('pusher:subscription_error');
        });
    }

    /**
     * Send a client whisper on a channel this socket subscribed.
     *
     * Silent when the channel is unknown: Echo's own `whisper()` reaches into
     * `pusher.channels.channels[name]` without a guard and throws a `TypeError`
     * for a channel that was never created. A typing signal is not worth a page
     * error, so an unknown channel is a no-op here.
     */
    whisper(
        channelName: string,
        eventName: string,
        data: Record<string, unknown>,
    ): void {
        this.channels.get(channelName)?.whisper(eventName, data);
    }

    disconnect(): void {
        this.unbindConnection?.();
        this.unbindConnection = null;
        this.echo.connector.disconnect();
    }

    unsubscribe(channelName: string): void {
        this.teardown.get(channelName)?.();
        this.teardown.delete(channelName);
        this.states.delete(channelName);
        this.channels.delete(channelName);
        this.echo.leave(channelName);
    }

    subscriptionState(channelName: string): SubscriptionState {
        return this.states.get(channelName) ?? 'idle';
    }

    private setState(
        channelName: string,
        state: SubscriptionState,
        reason?: string,
    ): void {
        this.states.set(channelName, state);

        if (reason !== undefined) {
            this.listener.onSignal({ kind: 'failure', reason });
        }

        this.listener.onSubscriptionState(channelName, state);
    }
}
