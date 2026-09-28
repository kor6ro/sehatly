import assert from 'node:assert/strict';
import test from 'node:test';
import { KonsultasiRealtime } from '@/lib/realtime/konsultasi-realtime';
import { gabungTranscript } from '@/lib/realtime/transcript';
import type { KonsultasiPesan } from '@/lib/api/types';
import type {
    RealtimeSignal,
    RealtimeSocket,
    RealtimeSubscribeRequest,
    SubscriptionState,
} from '@/lib/realtime/socket';

/**
 * Reconnect and cross-transport dedupe, proved against a fake transport.
 *
 * ## The transport here is a FAKE, and that is the point
 *
 * `RealtimeSocket` is a four-verb interface (`connect`/`disconnect`/`subscribe`/
 * `unsubscribe`) and this file implements it in twenty lines. That is the seam the
 * protocol is designed for - the same seam `packages/sehatly_api_client` uses on the
 * Dart side - and it is NOT a mock of the API: nothing here stands in for a server
 * answer. No HTTP is faked, no payload is canned, no endpoint is intercepted. What is
 * replaced is the WebSocket, because the WebSocket is the one component whose failures
 * are nondeterministic and unobservable from a test.
 *
 * The properties asserted are the two the brief names:
 *
 * 1. a message that arrives over REST *and* over the socket renders **once**; and
 * 2. a reconnect neither loses the messages sent while the socket was down nor renders
 *    the ones already shown a second time.
 *
 * Both are order-sensitive, and the order is a race on any single end-to-end run. The
 * frame and the refetch following a POST can arrive in either order, so an e2e spec
 * can only ever observe one of the two. Fixing the order here is the only way to
 * assert the property rather than one sample of it.
 */

/** The consultation both the patient and the doctor are party to. */
const KONSULTASI = 47;

/** One `konsultasi_chat` row, with every field a wrong dedupe key would reach for. */
function pesan(over: Partial<KonsultasiPesan> = {}): KonsultasiPesan {
    return {
        id: 1,
        konsultasi_id: KONSULTASI,
        pengirim_user_id: 7,
        pengirim_tipe: 'pasien',
        tipe_pesan: 'teks',
        isi: 'Gejalanya sudah sejak kemarin.',
        file_url: null,
        file_nama: null,
        file_ukuran_kb: null,
        dibaca_at: null,
        // One-second resolution, so a burst ties and only `id` separates two rows.
        terkirim_at: '2026-01-02T03:04:05.000Z',
        ...over,
    };
}

function id(rows: readonly KonsultasiPesan[]): number[] {
    return rows.map((row) => row.id);
}

/**
 * A transport with no port open and no broker behind it.
 *
 * It records the order of the operations the client performs, which is what makes the
 * "re-subscribe FIRST, resync second" rule assertable: the resync handler pushes its
 * own marker into the same log, so the two can be compared positionally.
 */
class FakeTransport implements RealtimeSocket {
    /** Shared with the harness, so the resync handler can log into it too. */
    readonly jejak: string[];

    constructor(jejak: string[]) {
        this.jejak = jejak;
    }

    private readonly states = new Map<string, SubscriptionState>();

    /** When set, the next `subscribe` rejects, the way a refused channel does. */
    gagalkan: Error | null = null;

    connect(): void {
        this.jejak.push('connect');
    }

    disconnect(): void {
        this.jejak.push('disconnect');
    }

    async subscribe(request: RealtimeSubscribeRequest): Promise<void> {
        this.jejak.push(`subscribe ${request.channelName}`);

        if (this.gagalkan !== null) {
            throw this.gagalkan;
        }

        this.states.set(request.channelName, 'pending');
    }

    unsubscribe(channelName: string): void {
        this.jejak.push(`unsubscribe ${channelName}`);
        this.states.delete(channelName);
    }

    subscriptionState(channelName: string): SubscriptionState {
        return this.states.get(channelName) ?? 'idle';
    }
}

class Harness {
    readonly jejak: string[] = [];

    readonly diterima: KonsultasiPesan[] = [];

    readonly socket = new FakeTransport(this.jejak);

    /**
     * A 1 ms recovery watchdog, so a dropped connection's timer always fires during
     * the test rather than staying pending and holding the process open at exit.
     */
    readonly client = new KonsultasiRealtime(this.socket, 500, 1);

    /** Resolves once the resync handler has been REACHED, not once it has settled. */
    readonly resyncDipanggil = Promise.withResolvers<void>();

    /** What the resync handler answers with; the test resolves it. */
    readonly backfill = Promise.withResolvers<readonly KonsultasiPesan[]>();

    resyncCount = 0;

    constructor() {
        this.client.onMessage((message) => {
            this.diterima.push(message);
        });

        this.client.setResyncHandler(() => {
            this.resyncCount += 1;
            this.jejak.push('resync');
            this.resyncDipanggil.resolve();

            return this.backfill.promise;
        });
    }

    /** The transport reports a connection state, as pusher-js would. */
    sinyal(signal: RealtimeSignal): void {
        this.client.onSignal(signal);
    }

    /** The BROKER confirmed the subscribe, which is not the same as having asked. */
    konfirmasi(): void {
        this.client.onSubscriptionState(`konsultasi.${KONSULTASI}`, 'confirmed');
    }

    /** One `chat.pesan` frame, carrying the resource payload verbatim. */
    frame(row: KonsultasiPesan): void {
        this.client.onFrame({
            channelName: `konsultasi.${KONSULTASI}`,
            eventName: 'chat.pesan',
            data: row,
        });
    }

    /** Let every pending microtask and the `resume()` continuation settle. */
    async flush(): Promise<void> {
        for (let i = 0; i < 5; i += 1) {
            await new Promise((resolve) => {
                setImmediate(resolve);
            });
        }
    }
}

test('a row delivered by BOTH transports renders exactly once, in either arrival order', () => {
    // The REST history page holds 1 and 2; the socket then delivers 2 and 3.
    const rest = [pesan({ id: 1 }), pesan({ id: 2 })];
    const live = [pesan({ id: 2 }), pesan({ id: 3 })];

    // The refetch won the race: the history is rendered first.
    assert.deepEqual(id(gabungTranscript(rest, live)), [1, 2, 3]);

    // The socket frame won the race: the live list is rendered first.
    assert.deepEqual(id(gabungTranscript(live, rest)), [1, 2, 3]);
});

test('two genuinely different messages are both rendered, however identical their text', () => {
    // Two real rows one second apart with the same words. A key on `isi` or on
    // `terkirim_at` would swallow the second one, because `terkirim_at` is a
    // one-second-resolution TIMESTAMP and a burst ties.
    const sama = (over: Partial<KonsultasiPesan>) =>
        pesan({ isi: 'Baik, terima kasih.', terkirim_at: '2026-01-02T03:04:05.000Z', ...over });

    assert.deepEqual(
        id(gabungTranscript([sama({ id: 11 }), sama({ id: 12 })], [])),
        [11, 12],
    );
});

test('the frame beats the refetch: one delivery, the refetch is suppressed', () => {
    const harness = new Harness();

    harness.sinyal({ kind: 'connected' });

    // The author's own message comes back over the socket first...
    harness.frame(pesan({ id: 2 }));

    // ...and the refetch that follows the POST then carries the same row.
    harness.client.seedHistory([pesan({ id: 1 }), pesan({ id: 2 })]);

    assert.deepEqual(id(harness.diterima), [2], 'the frame is delivered exactly once');

    // The dedupe set now knows id 2, so the seeded history cannot re-deliver it.
    assert.equal(harness.client.hasSeen(2), true);
    assert.equal(
        harness.client.getStats().duplicateSuppressedCount,
        0,
        'seeding is not a delivery, so it suppresses nothing yet',
    );
});

test('reconnect neither loses nor duplicates: subscribe, messages, drop, recover, messages', async () => {
    const harness = new Harness();
    const { client, socket } = harness;

    harness.sinyal({ kind: 'connected' });
    await client.subscribeKonsultasi(KONSULTASI);
    harness.konfirmasi();

    assert.equal(socket.jejak.filter((b) => b.startsWith('subscribe')).length, 1);

    // The REST history page is on screen: ids 1 and 2. Seeded, not delivered.
    client.seedHistory([pesan({ id: 1 }), pesan({ id: 2 })]);

    // Live delivery works before the outage.
    harness.frame(pesan({ id: 3 }));

    assert.deepEqual(id(harness.diterima), [3]);

    // --- the broker goes away -----------------------------------------------------
    harness.sinyal({ kind: 'disconnected' });

    assert.equal(
        client.getSubscription(KONSULTASI)?.state,
        'pending',
        'a drop invalidates every subscription, so it cannot stay `confirmed`',
    );
    assert.equal(client.getStats().connected, false);

    // While it is down, ids 4 and 5 are written. Nobody is subscribed, so no frame
    // arrives for them - which is the whole reason a resync is needed.
    assert.deepEqual(id(harness.diterima), [3], 'nothing new arrives while down');

    // --- the broker returns -------------------------------------------------------
    harness.sinyal({ kind: 'reconnected' });

    // The resync handler is reached only after every channel has been re-subscribed.
    await harness.resyncDipanggil.promise;
    await harness.flush();

    const posisiSubscribe = harness.jejak.lastIndexOf(
        `subscribe konsultasi.${KONSULTASI}`,
    );
    const posisiResync = harness.jejak.indexOf('resync');

    assert.ok(posisiSubscribe >= 0, 'the channel was re-subscribed');
    assert.ok(
        posisiSubscribe < posisiResync,
        're-subscribe MUST come first: the broker starts delivering on confirmation, ' +
            'so a resync that ran first would let a live message overtake its own ' +
            'history page',
    );

    assert.equal(
        socket.jejak.filter((b) => b.startsWith('subscribe')).length,
        2,
        'exactly one re-subscribe, which is also the re-read of the bearer',
    );
    assert.equal(client.getSubscription(KONSULTASI)?.attempts, 2);

    // --- the gap closes over REST, because the broker replayed nothing -------------
    harness.backfill.resolve([
        pesan({ id: 1 }),
        pesan({ id: 2 }),
        pesan({ id: 3 }),
        pesan({ id: 4 }),
        pesan({ id: 5 }),
    ]);

    await harness.flush();

    // NO LOSS: 4 and 5 were only ever in the database, and they are now delivered.
    // NO DUPLICATION: 1, 2 and 3 were already on screen, and they are not re-delivered.
    assert.deepEqual(
        id(harness.diterima),
        [3, 4, 5],
        'the gap is delivered and the overlap is suppressed',
    );

    assert.equal(harness.resyncCount, 1);
    assert.equal(client.getStats().resyncCount, 1);
    assert.equal(
        client.getStats().duplicateSuppressedCount,
        3,
        'ids 1, 2 and 3 came back through the backfill and were withheld',
    );

    // The rendered transcript: each row once, in `(terkirim_at, id)` order.
    const dirender = gabungTranscript(
        [pesan({ id: 1 }), pesan({ id: 2 })],
        harness.diterima,
    );

    assert.deepEqual(
        id(dirender),
        [1, 2, 3, 4, 5],
        'every message is on screen exactly once, nothing lost and nothing doubled',
    );

    // --- and live delivery really is restored --------------------------------------
    harness.frame(pesan({ id: 6 }));

    assert.deepEqual(id(harness.diterima), [3, 4, 5, 6]);
    assert.equal(client.getStats().connected, true);
});

test('a resync backfill is DELIVERED while the history page is SEEDED', async () => {
    const harness = new Harness();
    const { client } = harness;

    harness.sinyal({ kind: 'connected' });
    await client.subscribeKonsultasi(KONSULTASI);
    harness.konfirmasi();

    // Already on screen.
    client.seedHistory([pesan({ id: 1 })]);

    harness.sinyal({ kind: 'disconnected' });
    harness.sinyal({ kind: 'reconnected' });

    await harness.resyncDipanggil.promise;
    harness.backfill.resolve([pesan({ id: 1 }), pesan({ id: 2 })]);
    await harness.flush();

    assert.deepEqual(
        id(harness.diterima),
        [2],
        'seeding marks a rendered row known; a backfill must still deliver the gap',
    );
});

test('a refused subscribe is surfaced, and the subscription is marked refused', async () => {
    const harness = new Harness();
    const { client, socket } = harness;

    harness.sinyal({ kind: 'connected' });

    socket.gagalkan = new Error('403 Forbidden');

    await assert.rejects(
        client.subscribeKonsultasi(KONSULTASI),
        /403/,
        'the transport failure propagates rather than being swallowed',
    );

    assert.equal(client.getSubscription(KONSULTASI)?.state, 'refused');
    assert.match(client.getStats().lastFailure ?? '', /gagal/);
});

test('disconnect keeps the subscription registry and the dedupe set', async () => {
    const harness = new Harness();
    const { client } = harness;

    harness.sinyal({ kind: 'connected' });
    await client.subscribeKonsultasi(KONSULTASI);
    harness.frame(pesan({ id: 9 }));

    client.disconnect();

    assert.equal(
        client.getSubscription(KONSULTASI)?.channelName,
        `konsultasi.${KONSULTASI}`,
        'a route change must not throw away the subscription',
    );
    assert.equal(
        client.hasSeen(9),
        true,
        'a route change must not throw away the dedupe set, or the next frame ' +
            'for the same row would render a second time',
    );
});
