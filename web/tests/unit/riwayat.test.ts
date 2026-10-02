import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    cocokCari,
    kelompokkanBulan,
    kunciBulanInstan,
    kunciBulanTanggal,
    labelBulan,
    normalisasiCari,
    urutkanInstanMenurun,
    waktuMs,
} from '@/lib/riwayat';

/**
 * F10's grouping and search helpers, pinned with explicit zones so the assertions do
 * not depend on the machine's clock.
 */
describe('kunciBulanInstan', () => {
    it('reads the month in the requested zone', () => {
        assert.equal(
            kunciBulanInstan('2026-10-31T18:00:00.000Z', 'Asia/Jakarta'),
            '2026-11',
        );
        assert.equal(
            kunciBulanInstan('2026-10-31T18:00:00.000Z', 'UTC'),
            '2026-10',
        );
    });

    it('returns null for a missing or malformed value', () => {
        assert.equal(kunciBulanInstan(null, 'Asia/Jakarta'), null);
        assert.equal(kunciBulanInstan('', 'Asia/Jakarta'), null);
        assert.equal(kunciBulanInstan('bukan-tanggal', 'Asia/Jakarta'), null);
    });
});

describe('kunciBulanTanggal', () => {
    it('reads a wall-clock day without a timezone shift', () => {
        assert.equal(kunciBulanTanggal('2026-10-05'), '2026-10');
    });

    it('returns null for a missing or malformed value', () => {
        assert.equal(kunciBulanTanggal(null), null);
        assert.equal(kunciBulanTanggal('05/10/2026'), null);
    });
});

describe('labelBulan', () => {
    it('names the month in Indonesian', () => {
        assert.equal(labelBulan('2026-10'), 'Oktober 2026');
        assert.equal(labelBulan('2026-01'), 'Januari 2026');
        assert.equal(labelBulan('2026-12'), 'Desember 2026');
    });

    it('returns an unknown key unchanged', () => {
        assert.equal(labelBulan('tanpa-bulan'), 'tanpa-bulan');
        assert.equal(labelBulan('2026-13'), '2026-13');
    });
});

describe('kelompokkanBulan', () => {
    it('preserves the caller order between and inside groups', () => {
        const baris = [
            { id: 1, tanggal: '2026-10-04T02:00:00.000Z' },
            { id: 2, tanggal: '2026-10-02T02:00:00.000Z' },
            { id: 3, tanggal: '2026-09-30T02:00:00.000Z' },
        ];

        const grup = kelompokkanBulan(baris, (row) =>
            kunciBulanInstan(row.tanggal, 'Asia/Jakarta'),
        );

        assert.deepEqual(
            grup.map((g) => [g.label, g.baris.map((r) => r.id)]),
            [
                ['Oktober 2026', [1, 2]],
                ['September 2026', [3]],
            ],
        );
    });

    it('drops a row whose date cannot be read', () => {
        const grup = kelompokkanBulan(
            [{ tanggal: 'bukan-tanggal' }, { tanggal: '2026-10-04T02:00:00.000Z' }],
            (row) => kunciBulanInstan(row.tanggal, 'Asia/Jakarta'),
        );

        assert.equal(grup.length, 1);
        assert.equal(grup[0]?.baris.length, 1);
    });
});

describe('urutkanInstanMenurun', () => {
    it('puts the newest instant first and leaves the input untouched', () => {
        const baris = [
            { id: 1, waktu: '2026-10-02T02:00:00.000Z' },
            { id: 2, waktu: '2026-10-04T02:00:00.000Z' },
        ];

        const urut = urutkanInstanMenurun(baris, (row) => row.waktu);

        assert.deepEqual(
            urut.map((row) => row.id),
            [2, 1],
        );
        assert.deepEqual(
            baris.map((row) => row.id),
            [1, 2],
        );
    });

    it('sorts an unparseable instant last', () => {
        assert.equal(waktuMs('bukan-tanggal'), 0);

        const baris = [
            { id: 1, waktu: 'bukan-tanggal' },
            { id: 2, waktu: '2026-10-04T02:00:00.000Z' },
        ];

        const urut = urutkanInstanMenurun(baris, (row) => row.waktu);

        assert.deepEqual(
            urut.map((row) => row.id),
            [2, 1],
        );
    });
});

describe('cocokCari', () => {
    it('matches an empty query against anything', () => {
        assert.equal(cocokCari('', 'apa pun'), true);
        assert.equal(cocokCari('   ', null), true);
    });

    it('matches case-insensitively across the given fields', () => {
        assert.equal(cocokCari('DEMAM', 'Batuk kering, demam ringan'), true);
        assert.equal(cocokCari('rina', null, 'dr. Rina Wulandari'), true);
        assert.equal(cocokCari('tidak-ada', 'Batuk', null), false);
    });

    it('normalises the query the same way', () => {
        assert.equal(normalisasiCari('  Rina '), 'rina');
    });
});
