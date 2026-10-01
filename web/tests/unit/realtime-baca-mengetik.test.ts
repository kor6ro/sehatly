import assert from 'node:assert/strict';
import test from 'node:test';
import type { KonsultasiPesan } from '@/lib/api/types';
import {
    CHAT_DIBACA_EVENT,
    CHAT_MENGETIK_EVENT,
    KonsultasiRealtime,
} from '@/lib/realtime/konsultasi-realtime';
import type { RealtimeDibaca } from '@/lib/realtime/read-receipt';
import type { RealtimeMengetik } from '@/lib/realtime/mengetik';
import type {
    RealtimeSocket,
    RealtimeSubscribeRequest,
    SubscriptionState,
} from '@/lib/realtime/socket';

/**
 * F08's two additional realtime streams, proved against the same fake transport
 * `realtime-reconnect.test.ts` uses: no port, no broker, no server.
 *
 * What is worth pinning here is the CLIENT's rules - the whisper is refused before
 * the broker confirms the subscription, a malformed marker changes nothing, and a
 * `chat.pesan` frame still goes through the dedupe gate unchanged.
 */

const KONSULTASI = 47;
const CHANNEL = `konsultasi.${KONSULTASI}`;

class FakeTransport implements RealtimeSocket {
    readonly whispers: Array<{
        channelName: string;
        eventName: string;
        data: Record<string, unknown>;
    }> = [];

    terakhir: RealtimeSubscribeRequest | null = null;

    connect(): void {}

    disconnect(): void {}

    async subscribe(request: RealtimeSubscribeRequest): Promise<void> {
        this.terakhir = request;
    }

    unsubscribe(): void {}

    whisper(
        channelName: string,
        eventName: string,
        data: Record<string, unknown>,
    ): void {
        this.whispers.push({ channelName, eventName, data });
    }

    subscriptionState(): SubscriptionState {
        return 'idle';
    }
}

function pesan(over: Partial<KonsultasiPesan> = {}): KonsultasiPesan {
    return {
        id: 1,
        konsultasi_id: KONSULTASI,
        pengirim_user_id: 11,
        pengirim_tipe: 'pasien',
        tipe_pesan: 'teks',
        isi: 'Demam sejak kemarin.',
        file_url: null,
        file_nama: null,
        file_ukuran_kb: null,
        dibaca_at: null,
        terkirim_at: '2026-10-01T02:00:00.000Z',
        ...over,
    };
}

test('the subscribe binds both server events and the typing whisper', async () => {
    const socket = new FakeTransport();
    const client = new KonsultasiRealtime(socket);

    await client.subscribeKonsultasi(KONSULTASI);

    assert.deepEqual(socket.terakhir?.serverEvents, ['chat.pesan', CHAT_DIBACA_EVENT]);
    assert.deepEqual(socket.terakhir?.whisperEvents, [CHAT_MENGETIK_EVENT]);
});

test('a typing whisper is sent only after the broker confirms the subscription', async () => {
    const socket = new FakeTransport();
    const client = new KonsultasiRealtime(socket);

    await client.subscribeKonsultasi(KONSULTASI);

    client.kirimMengetik(KONSULTASI, { user_id: 11, at: '2026-10-01T02:00:00.000Z' });

    assert.equal(socket.whispers.length, 0, 'pending is not a licence to whisper');

    client.onSubscriptionState(CHANNEL, 'confirmed');
    client.kirimMengetik(KONSULTASI, { user_id: 11, at: '2026-10-01T02:00:01.000Z' });

    assert.deepEqual(socket.whispers, [
        {
            channelName: CHANNEL,
            eventName: CHAT_MENGETIK_EVENT,
            data: { user_id: 11, at: '2026-10-01T02:00:01.000Z' },
        },
    ]);
});

test('chat.dibaca reaches its listener, and a malformed one changes nothing', async () => {
    const socket = new FakeTransport();
    const client = new KonsultasiRealtime(socket);
    const diterima: RealtimeDibaca[] = [];

    client.onDibaca((marker) => {
        diterima.push(marker);
    });

    client.onFrame({
        channelName: CHANNEL,
        eventName: CHAT_DIBACA_EVENT,
        data: { user_id: 22, last_read_at: '2026-10-01T02:30:00.000Z' },
    });

    assert.deepEqual(diterima, [
        { user_id: 22, last_read_at: '2026-10-01T02:30:00.000Z' },
    ]);

    client.onFrame({
        channelName: CHANNEL,
        eventName: CHAT_DIBACA_EVENT,
        data: { user_id: 22, last_read_at: 'kemarin' },
    });

    assert.equal(diterima.length, 1, 'a malformed marker is not a marker');
});

test('an inbound whisper reaches its listener only under the mengetik name', () => {
    const client = new KonsultasiRealtime(new FakeTransport());
    const diterima: RealtimeMengetik[] = [];

    client.onMengetik((mengetik) => {
        diterima.push(mengetik);
    });

    client.onWhisper({
        channelName: CHANNEL,
        eventName: CHAT_MENGETIK_EVENT,
        data: { user_id: 22, at: '2026-10-01T02:00:00.000Z' },
    });

    client.onWhisper({
        channelName: CHANNEL,
        eventName: 'chat.lain',
        data: { user_id: 22, at: '2026-10-01T02:00:00.000Z' },
    });

    client.onWhisper({
        channelName: CHANNEL,
        eventName: CHAT_MENGETIK_EVENT,
        data: { user_id: '22' },
    });

    assert.deepEqual(diterima, [
        { user_id: 22, at: '2026-10-01T02:00:00.000Z' },
    ]);
});

test('a chat.pesan frame still goes through the dedupe gate alongside the new events', () => {
    const client = new KonsultasiRealtime(new FakeTransport());
    const diterima: KonsultasiPesan[] = [];

    client.onMessage((message) => {
        diterima.push(message);
    });

    client.onFrame({ channelName: CHANNEL, eventName: 'chat.pesan', data: pesan({ id: 7 }) });
    client.onFrame({ channelName: CHANNEL, eventName: 'chat.pesan', data: pesan({ id: 7 }) });

    assert.deepEqual(diterima.map((row) => row.id), [7]);
    assert.equal(client.getStats().duplicateSuppressedCount, 1);
});
