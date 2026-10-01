import assert from 'node:assert/strict';
import test from 'node:test';
import {
    bolehKirimMengetik,
    mengetikDariPayload,
    mengetikKedaluwarsa,
    MENGETIK_KEDALUWARSA_MS,
    MENGETIK_THROTTLE_MS,
} from '@/lib/realtime/mengetik';

const AT = '2026-10-01T02:30:00.000Z';

test('the whisper throttle allows the first signal and then one per window', () => {
    assert.equal(bolehKirimMengetik(null, 1_000), true);

    assert.equal(bolehKirimMengetik(1_000, 1_000 + MENGETIK_THROTTLE_MS - 1), false);
    assert.equal(bolehKirimMengetik(1_000, 1_000 + MENGETIK_THROTTLE_MS), true);

    // A non-finite "last sent" cannot throttle anything; a non-finite "now" must
    // never be mistaken for permission.
    assert.equal(bolehKirimMengetik(Number.NaN, 5_000), true);
    assert.equal(bolehKirimMengetik(1_000, Number.NaN), false);

    // The window is injectable, which is what lets a fast test prove the rule.
    assert.equal(bolehKirimMengetik(1_000, 1_050, 50), true);
});

test('a received whisper expires on the watcher clock', () => {
    assert.equal(mengetikKedaluwarsa(null, 1_000), true);
    assert.equal(mengetikKedaluwarsa(1_000, 1_000 + MENGETIK_KEDALUWARSA_MS - 1), false);
    assert.equal(mengetikKedaluwarsa(1_000, 1_000 + MENGETIK_KEDALUWARSA_MS), true);

    // A missing receive time is expired, so a bug cannot pin the indicator on.
    assert.equal(mengetikKedaluwarsa(Number.NaN, 1_000), true);
    assert.equal(mengetikKedaluwarsa(1_000, Number.NaN), true);
});

test('a chat.mengetik whisper payload is accepted only in its exact documented shape', () => {
    assert.deepEqual(mengetikDariPayload({ user_id: 22, at: AT }), {
        user_id: 22,
        at: AT,
    });

    assert.equal(mengetikDariPayload(null), null);
    assert.equal(mengetikDariPayload({ user_id: 22 }), null);
    assert.equal(mengetikDariPayload({ user_id: 22, at: '' }), null);
    assert.equal(mengetikDariPayload({ user_id: '22', at: AT }), null);
    assert.equal(mengetikDariPayload({ user_id: Number.NaN, at: AT }), null);
});
