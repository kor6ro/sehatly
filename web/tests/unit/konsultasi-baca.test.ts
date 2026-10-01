import assert from 'node:assert/strict';
import test from 'node:test';
import type { KonsultasiBaca, KonsultasiPesan } from '@/lib/api/types';
import {
    bacaDariPayload,
    lawanDariBaca,
    pilihBacaTerbaru,
    sisiDariBaca,
    sudahDibaca,
} from '@/lib/realtime/read-receipt';

const PASIEN_USER = 11;
const DOKTER_USER = 22;

const BACA: KonsultasiBaca = {
    pasien_user_id: PASIEN_USER,
    pasien_last_read_at: '2026-10-01T01:00:00.000Z',
    dokter_user_id: DOKTER_USER,
    dokter_last_read_at: '2026-10-01T02:30:00.000Z',
};

function pesan(over: Partial<KonsultasiPesan> = {}): KonsultasiPesan {
    return {
        id: 1,
        konsultasi_id: 47,
        pengirim_user_id: PASIEN_USER,
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

test('the other party is mapped from the baca block by the caller id', () => {
    assert.equal(sisiDariBaca(BACA, PASIEN_USER), 'pasien');
    assert.equal(sisiDariBaca(BACA, DOKTER_USER), 'dokter');

    assert.deepEqual(lawanDariBaca(BACA, PASIEN_USER), {
        userId: DOKTER_USER,
        lastReadAt: '2026-10-01T02:30:00.000Z',
    });

    assert.deepEqual(lawanDariBaca(BACA, DOKTER_USER), {
        userId: PASIEN_USER,
        lastReadAt: '2026-10-01T01:00:00.000Z',
    });

    // An id in neither slot must not be guessed at.
    assert.equal(sisiDariBaca(BACA, 99), null);
    assert.deepEqual(lawanDariBaca(BACA, 99), {
        userId: null,
        lastReadAt: null,
    });
    assert.deepEqual(lawanDariBaca(undefined, PASIEN_USER), {
        userId: null,
        lastReadAt: null,
    });
});

test('"Dibaca" is the other marker at or after the message instant, for my own messages only', () => {
    const sayaKirim = pesan({ terkirim_at: '2026-10-01T02:00:00.000Z' });

    assert.equal(sudahDibaca(sayaKirim, PASIEN_USER, '2026-10-01T02:30:00.000Z'), true);

    // Equality counts: last_read_at is second-resolution, and a message sent in
    // the same second as the read must not be rendered "Terkirim" forever.
    assert.equal(sudahDibaca(sayaKirim, PASIEN_USER, '2026-10-01T02:00:00.000Z'), true);

    assert.equal(sudahDibaca(sayaKirim, PASIEN_USER, '2026-10-01T01:59:59.000Z'), false);
    assert.equal(sudahDibaca(sayaKirim, PASIEN_USER, null), false);

    // An incoming message is never given the caller's own receipt.
    const lawanKirim = pesan({
        pengirim_user_id: DOKTER_USER,
        pengirim_tipe: 'dokter',
    });

    assert.equal(sudahDibaca(lawanKirim, PASIEN_USER, '2026-10-01T03:00:00.000Z'), false);

    // Neither is a system line, and an unknown caller claims nothing.
    assert.equal(
        sudahDibaca(pesan({ pengirim_tipe: 'sistem' }), PASIEN_USER, '2026-10-01T03:00:00.000Z'),
        false,
    );
    assert.equal(sudahDibaca(sayaKirim, null, '2026-10-01T03:00:00.000Z'), false);
});

test('the live marker wins only when it is later, and only for the mapped participant', () => {
    const awal = '2026-10-01T02:30:00.000Z';

    assert.equal(
        pilihBacaTerbaru(awal, { user_id: DOKTER_USER, last_read_at: '2026-10-01T02:45:00.000Z' }, DOKTER_USER),
        '2026-10-01T02:45:00.000Z',
    );

    // An older event never walks the marker backwards.
    assert.equal(
        pilihBacaTerbaru(awal, { user_id: DOKTER_USER, last_read_at: '2026-10-01T01:00:00.000Z' }, DOKTER_USER),
        awal,
    );

    // Somebody else's event - including the caller's own echo - is ignored.
    assert.equal(
        pilihBacaTerbaru(awal, { user_id: PASIEN_USER, last_read_at: '2026-10-01T04:00:00.000Z' }, DOKTER_USER),
        awal,
    );

    assert.equal(pilihBacaTerbaru(null, null, DOKTER_USER), null);
    assert.equal(
        pilihBacaTerbaru(null, { user_id: DOKTER_USER, last_read_at: '2026-10-01T02:45:00.000Z' }, DOKTER_USER),
        '2026-10-01T02:45:00.000Z',
    );

    // A malformed timestamp loses to a usable seed rather than resetting it.
    assert.equal(
        pilihBacaTerbaru(awal, { user_id: DOKTER_USER, last_read_at: 'bukan-tanggal' }, DOKTER_USER),
        awal,
    );
});

test('a chat.dibaca payload is accepted only in its exact documented shape', () => {
    assert.deepEqual(
        bacaDariPayload({ user_id: 22, last_read_at: '2026-10-01T02:30:00.000Z' }),
        { user_id: 22, last_read_at: '2026-10-01T02:30:00.000Z' },
    );

    assert.equal(bacaDariPayload(null), null);
    assert.equal(bacaDariPayload('chat.dibaca'), null);
    assert.equal(bacaDariPayload({ user_id: '22', last_read_at: '2026-10-01T02:30:00.000Z' }), null);
    assert.equal(bacaDariPayload({ user_id: 22, last_read_at: '' }), null);
    assert.equal(bacaDariPayload({ user_id: 22, last_read_at: 'kemarin' }), null);
    assert.equal(bacaDariPayload({ user_id: Number.NaN, last_read_at: '2026-10-01T02:30:00.000Z' }), null);
});
