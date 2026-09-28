import assert from 'node:assert/strict';
import test from 'node:test';
import { MessageDedupe } from '../../src/lib/realtime/dedupe.ts';

/**
 * The dedupe rule, proved deterministically.
 *
 * ## Why this is a unit test and not only the end-to-end one
 *
 * The Playwright spec proves the rendered outcome - one row on screen - and that is
 * the requirement. It CANNOT prove which arrival won, because the socket frame and
 * the REST refetch race, and on any given run either may be first. Asserting a
 * counter in that spec would be asserting a race.
 *
 * So the property that makes the outcome true is asserted here instead, with the
 * ordering fixed: given the same row delivered over both transports, exactly one
 * delivery happens; and given a row whose `id` differs, two deliveries happen, because
 * two different rows are two different messages.
 *
 * That second half is the one that matters most. A dedupe key derived from a field
 * that differs between the two payloads - the text, the arrival time, the sender -
 * would suppress nothing, and the end-to-end spec would still pass whenever the frame
 * and the page happened to agree. Asserting that a *different id* is NOT suppressed
 * is what pins the key to `konsultasi_chat.id` and rules out every alternative.
 *
 * Run with `npm run test:unit`. No imports, no DOM, no broker.
 */

type Baris = {
    id: number;
    /** Every field the server also publishes, and every field a wrong key would use. */
    konsultasi_id: number;
    pengirim_user_id: number;
    pengirim_tipe: string;
    isi: string;
    terkirim_at: string;
};

function baris(over: Partial<Baris> = {}): Baris {
    return {
        id: 91,
        konsultasi_id: 5,
        pengirim_user_id: 7,
        pengirim_tipe: 'pasien',
        isi: 'Dokter, gejalanya sudah sejak kemarin.',
        terkirim_at: '2026-01-02T03:04:05.000000Z',
        ...over,
    };
}

test('the same id over both transports is delivered once', () => {
    const dedupe = new MessageDedupe();

    // 1. the socket frame
    assert.equal(dedupe.remember(baris().id), true, 'frame pertama harus delivered');

    // 2. the REST history page carrying the identical row
    assert.equal(
        dedupe.remember(baris().id),
        false,
        'baris yang sama lewat REST harus ditahan',
    );

    // 3. and a re-subscribe replaying it a third time
    assert.equal(dedupe.remember(baris().id), false, 'replay harus ditahan');

    assert.equal(dedupe.duplicateSuppressedCount, 2);
});

test('a DIFFERENT id is a different message, however identical the payload', () => {
    const dedupe = new MessageDedupe();

    // Two rows, byte-identical except for the id - which is exactly what happens when
    // somebody sends the same sentence twice in one second, because `terkirim_at` is
    // a one-second-resolution TIMESTAMP.
    assert.equal(dedupe.remember(101), true);
    assert.equal(dedupe.remember(102), true);

    assert.equal(
        dedupe.duplicateSuppressedCount,
        0,
        'dua id berbeda tidak boleh dianggap duplikat',
    );
});

test('every field a wrong key would use is pinned to the id', () => {
    // Each row differs in exactly one non-id field. A dedupe keyed on that field would
    // suppress this second row; keyed on `id` it must not.
    const pasangan: ReadonlyArray<readonly [string, Partial<Baris>]> = [
        ['konsultasi_id', { id: 201, konsultasi_id: 6 }],
        ['pengirim_user_id', { id: 202, pengirim_user_id: 8 }],
        ['pengirim_tipe', { id: 203, pengirim_tipe: 'dokter' }],
        ['isi', { id: 204, isi: 'Teks yang sama persis' }],
        ['terkirim_at', { id: 205, terkirim_at: '2026-01-02T03:04:05.000000Z' }],
    ];

    for (const [nama, lain] of pasangan) {
        const dedupe = new MessageDedupe();

        assert.equal(dedupe.remember(200), true, `basis untuk ${nama}`);
        assert.equal(
            dedupe.remember(lain.id as number),
            true,
            `beda pada ${nama} harus tetap dianggap pesan baru`,
        );
        assert.equal(dedupe.duplicateSuppressedCount, 0, `tidak ada duplikat di ${nama}`);
    }
});

test('seeding history suppresses the overlap without delivering it', () => {
    const dedupe = new MessageDedupe();
    const halaman = [baris({ id: 1 }), baris({ id: 2 }), baris({ id: 3 })];

    dedupe.seedHistory(halaman);

    for (const row of halaman) {
        assert.equal(
            dedupe.remember(row.id),
            false,
            'baris yang sudah tampil lewat REST tidak boleh dikirim ulang',
        );
    }

    assert.equal(dedupe.has(2), true);
});

test('a frame with no id is DELIVERED, never swallowed', () => {
    const dedupe = new MessageDedupe();

    // `0` is what a frame carrying no `konsultasi_chat.id` produces. Suppressing it
    // would silently drop every message from a server that stopped publishing the
    // key, which looks like a working client that has gone quiet.
    dedupe.countUnidentified();
    dedupe.countUnidentified();

    assert.equal(dedupe.unidentifiedEventCount, 2);
    assert.equal(dedupe.duplicateSuppressedCount, 0);

    // A negative id is not a row identity either, and must not poison the set.
    assert.equal(dedupe.remember(-1), true);
});

test('the set is bounded and evicts oldest first', () => {
    const dedupe = new MessageDedupe(3);

    for (const id of [1, 2, 3]) {
        assert.equal(dedupe.remember(id), true);
    }

    assert.equal(dedupe.size, 3);

    assert.equal(dedupe.remember(4), true);
    assert.equal(dedupe.size, 3, 'ukuran harus tetap pada batas');
    assert.equal(dedupe.has(1), false, 'entri tertua yang keluar');
    assert.equal(dedupe.has(2), true, 'entri yang lebih baru tetap ada');
    assert.equal(dedupe.has(4), true);
});

test('seeding respects the same bound as delivery', () => {
    const dedupe = new MessageDedupe(2);

    dedupe.seedHistory([{ id: 1 }, { id: 2 }, { id: 3 }]);

    assert.equal(dedupe.size, 2);
    assert.equal(dedupe.has(1), false);
    assert.equal(dedupe.has(3), true);
});
