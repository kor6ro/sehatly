import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    PESAN_TELEPON_INTERIM,
    apakahTeleponInterimValid,
} from '@/lib/telepon';

describe('apakahTeleponInterimValid', () => {
    it('accepts a plain 08 mobile number', () => {
        assert.equal(apakahTeleponInterimValid('081234567890'), true);
        assert.equal(apakahTeleponInterimValid('081299998888'), true);
    });

    it('rejects the +62 spelling until the backend normalises', () => {
        assert.equal(apakahTeleponInterimValid('+6281234567890'), false);
    });

    it('rejects a 62 prefix without the plus', () => {
        assert.equal(apakahTeleponInterimValid('6281234567890'), false);
    });

    it('rejects spaces and non-digits', () => {
        assert.equal(apakahTeleponInterimValid('0812 3456 7890'), false);
        assert.equal(apakahTeleponInterimValid('0812-3456-7890'), false);
    });

    it('rejects numbers that are too short', () => {
        assert.equal(apakahTeleponInterimValid('0812345'), false);
    });

    it('names the accepted format in the message', () => {
        assert.match(PESAN_TELEPON_INTERIM, /08xx/);
        assert.match(PESAN_TELEPON_INTERIM, /\+62/);
    });
});
