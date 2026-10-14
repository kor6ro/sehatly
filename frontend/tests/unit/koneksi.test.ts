import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    bacaStatusOnline,
    langgananKoneksi,
    type PeristiwaKoneksi,
    type TargetKoneksi,
} from '@/lib/koneksi';

/**
 * A two-method fake standing in for `window`.
 *
 * It records every listener per event so a test can fire `online` / `offline` itself and
 * assert both that the listener ran and that the unsubscribe removed it.
 */
function targetPalsu(): TargetKoneksi & {
    jumlah: (peristiwa: PeristiwaKoneksi) => number;
    picu: (peristiwa: PeristiwaKoneksi) => void;
} {
    const pendengar: Record<PeristiwaKoneksi, Set<() => void>> = {
        online: new Set(),
        offline: new Set(),
    };

    return {
        addEventListener(peristiwa, listener) {
            pendengar[peristiwa].add(listener);
        },
        removeEventListener(peristiwa, listener) {
            pendengar[peristiwa].delete(listener);
        },
        jumlah(peristiwa) {
            return pendengar[peristiwa].size;
        },
        picu(peristiwa) {
            for (const listener of pendengar[peristiwa]) {
                listener();
            }
        },
    };
}

describe('bacaStatusOnline', () => {
    it('reads the browser answer', () => {
        assert.equal(bacaStatusOnline({ onLine: true }), true);
        assert.equal(bacaStatusOnline({ onLine: false }), false);
    });

    it('treats a missing navigator as online', () => {
        assert.equal(bacaStatusOnline(undefined), true);
    });
});

describe('langgananKoneksi', () => {
    it('subscribes to both events and fires the listener for each', () => {
        const target = targetPalsu();
        let panggilan = 0;

        langgananKoneksi(target, () => {
            panggilan += 1;
        });

        assert.equal(target.jumlah('online'), 1);
        assert.equal(target.jumlah('offline'), 1);

        target.picu('offline');
        target.picu('online');

        assert.equal(panggilan, 2);
    });

    it('removes both listeners on unsubscribe', () => {
        const target = targetPalsu();
        let panggilan = 0;

        const lepas = langgananKoneksi(target, () => {
            panggilan += 1;
        });

        lepas();

        assert.equal(target.jumlah('online'), 0);
        assert.equal(target.jumlah('offline'), 0);

        target.picu('offline');

        assert.equal(panggilan, 0);
    });
});
