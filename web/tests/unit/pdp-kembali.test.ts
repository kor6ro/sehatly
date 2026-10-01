import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { jalurKembali } from '@/lib/pdp/kembali';

/**
 * The return-path sanitiser, at the seam where an open redirect would live.
 *
 * `?kembali=` is attacker-reachable by construction - anybody can write a link to
 * `/profil/privasi?kembali=...` - and the screen navigates to whatever survives this
 * function. So the control assertions are as important as the happy path: each rejection
 * below is a destination the browser would treat as a different origin.
 */

describe('lib/pdp/kembali - only an internal path is a return path', () => {
    it('accepts the internal destinations this app produces', () => {
        assert.equal(jalurKembali('/booking'), '/booking');
        assert.equal(jalurKembali('/booking/12'), '/booking/12');
        assert.equal(
            jalurKembali('/booking/12?tanggal=2026-10-05&jam=09:00:00'),
            '/booking/12?tanggal=2026-10-05&jam=09:00:00',
        );
        assert.equal(jalurKembali('/pembayaran/7'), '/pembayaran/7');
    });

    it('rejects every absolute or scheme-bearing destination', () => {
        assert.equal(jalurKembali('https://evil.example/path'), null);
        assert.equal(jalurKembali('http://evil.example'), null);
        assert.equal(jalurKembali('javascript:alert(1)'), null);
        assert.equal(jalurKembali('data:text/html,<script>'), null);
        assert.equal(jalurKembali('//evil.example/path'), null);
        assert.equal(jalurKembali('/\\evil.example/path'), null);
        assert.equal(jalurKembali('/path\\to'), null);
    });

    it('rejects nothing-to-return-to values', () => {
        assert.equal(jalurKembali(null), null);
        assert.equal(jalurKembali(''), null);
        assert.equal(jalurKembali('booking/12'), null);
        assert.equal(jalurKembali('/'), '/');
    });

    it('rejects control characters and over-long values', () => {
        assert.equal(jalurKembali('/booking\n/../evil'), null);
        assert.equal(jalurKembali('/booking\u0000'), null);
        assert.equal(jalurKembali(`/booking/${'a'.repeat(600)}`), null);
    });
});
