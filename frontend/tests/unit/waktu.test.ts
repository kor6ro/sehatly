import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    formatJamZona,
    formatJamZonaGanda,
    formatRentangJamZona,
    formatWaktuZona,
    labelZona,
    zonaPerangkat,
} from '@/lib/waktu';

/**
 * The display conversion, pinned to exact strings.
 *
 * Every case passes an explicit `zona` rather than relying on the machine's own zone, so
 * the assertions are the same on a CI runner in UTC and on a laptop in Jakarta. The one
 * test that does read the device zone asserts only that it is a non-empty string.
 */
describe('formatJamZona', () => {
    it('renders a Jakarta wall clock in WIB with a dot separator', () => {
        assert.equal(
            formatJamZona('09:00:00', '2026-10-05', 'Asia/Jakarta'),
            '09.00 WIB',
        );
    });

    it('converts WIB to WITA (+1) and labels it', () => {
        assert.equal(
            formatJamZona('09:00:00', '2026-10-05', 'Asia/Makassar'),
            '10.00 WITA',
        );
    });

    it('converts WIB to WIT (+2) and labels it', () => {
        assert.equal(
            formatJamZona('09:00:00', '2026-10-05', 'Asia/Jayapura'),
            '11.00 WIT',
        );
    });

    it('accepts H:i as well as H:i:s', () => {
        assert.equal(
            formatJamZona('09:00', '2026-10-05', 'Asia/Jakarta'),
            '09.00 WIB',
        );
    });

    it('crosses midnight without inventing a date (documented caveat)', () => {
        assert.equal(
            formatJamZona('23:30:00', '2026-10-05', 'Asia/Jayapura'),
            '01.30 WIT',
        );
    });

    it('falls back to the raw time when the input is malformed', () => {
        assert.equal(
            formatJamZona('bukan-jam', '2026-10-05', 'Asia/Jakarta'),
            'bukan-jam',
        );

        assert.equal(
            formatJamZona('09:00:00', 'bukan-tanggal', 'Asia/Jakarta'),
            '09:00',
        );
    });
});

describe('formatRentangJamZona', () => {
    it('labels a start-end pair once', () => {
        assert.equal(
            formatRentangJamZona(
                '09:00:00',
                '09:15:00',
                '2026-10-05',
                'Asia/Jakarta',
            ),
            '09.00–09.15 WIB',
        );
    });

    it('converts both ends together', () => {
        assert.equal(
            formatRentangJamZona(
                '09:00:00',
                '09:15:00',
                '2026-10-05',
                'Asia/Makassar',
            ),
            '10.00–10.15 WITA',
        );
    });

    it('falls back to raw times when the date is malformed', () => {
        assert.equal(
            formatRentangJamZona('09:00:00', '09:15:00', '', 'Asia/Jakarta'),
            '09:00–09:15',
        );
    });
});

describe('formatJamZonaGanda', () => {
    it('omits the parenthetical when the device is on the schedule clock', () => {
        assert.equal(
            formatJamZonaGanda('09:00:00', '2026-10-05', 'Asia/Jakarta'),
            '09.00 WIB',
        );
    });

    it('shows both zones when the device differs', () => {
        assert.equal(
            formatJamZonaGanda('09:00:00', '2026-10-05', 'Asia/Makassar'),
            '10.00 WITA (09.00 WIB)',
        );
    });
});

describe('labelZona', () => {
    it('names the three Indonesian zones', () => {
        assert.equal(labelZona('Asia/Jakarta'), 'WIB');
        assert.equal(labelZona('Asia/Pontianak'), 'WIB');
        assert.equal(labelZona('Asia/Makassar'), 'WITA');
        assert.equal(labelZona('Asia/Ujung_Pandang'), 'WITA');
        assert.equal(labelZona('Asia/Jayapura'), 'WIT');
    });

    it('falls back to Intl for a zone outside Indonesia', () => {
        assert.match(labelZona('Europe/London'), /GMT|BST/);
    });
});

describe('zonaPerangkat', () => {
    it('always answers with a non-empty zone name', () => {
        assert.equal(typeof zonaPerangkat(), 'string');
        assert.notEqual(zonaPerangkat(), '');
    });
});

describe('formatWaktuZona', () => {
    it('converts an instant to the named zone and labels it', () => {
        assert.equal(
            formatWaktuZona('2026-10-01T16:59:00Z', 'Asia/Jakarta'),
            '1 Okt 2026, 23.59 WIB',
        );
    });

    it('converts across midnight and labels the destination zone', () => {
        assert.equal(
            formatWaktuZona('2026-10-01T16:59:00Z', 'Asia/Jayapura'),
            '2 Okt 2026, 01.59 WIT',
        );
    });

    it('keeps the placeholder free of a zone label', () => {
        assert.equal(formatWaktuZona(null, 'Asia/Jakarta'), '-');
        assert.equal(formatWaktuZona('', 'Asia/Jakarta'), '-');
    });

    it('returns a malformed value unchanged, without inventing a zone', () => {
        assert.equal(
            formatWaktuZona('bukan-waktu', 'Asia/Jakarta'),
            'bukan-waktu',
        );
    });
});
