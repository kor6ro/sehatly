import assert from 'node:assert/strict';
import { afterEach, describe, it } from 'node:test';
import {
    PORTAL_HOME,
    PORTAL_TIPE,
    detectPortal,
    portalBeranda,
    portalDariHost,
    portalOrigin,
    portalTipe,
} from '@/lib/portal';

/**
 * The portal answer, at the seam the whole router hangs from.
 *
 * ## What is being prevented
 *
 * `createBrowserRouter` picks its entire route table from `detectPortal()` once, at
 * module load. A wrong answer therefore does not mis-gate one screen - it serves six
 * routes of the wrong portal: a patient shell at `/admin/dokter`, an admin at
 * `/dashboard`. The properties below are the ones that make the split safe:
 *
 * 1. A staff subdomain is authoritative - no query string can re-map it, because
 *    `?portal=pasien` on `admin.sehatly.test` must not decide where the admin
 *    workspace's routes live.
 * 2. The root host is the escape hatch - `?portal=` is what Playwright drives (one
 *    origin, six portals), and without it every staff spec would need DNS entries.
 * 3. The fallback is `pasien`, never an unknown portal - `PORTAL_TIPE` and
 *    `PORTAL_HOME` are total records, so an unmapped value would be undefined at
 *    runtime rather than caught by the type checker (the host is a `string`).
 *
 * ## These run with no bundler
 *
 * `npm run test:unit` is `node --import ./tests/unit/alias-hooks.mjs --test`, which
 * strips types and rewrites the `@/` alias to `src/`. Nothing here touches the DOM
 * except `detectPortal` / `portalOrigin` / `portalBeranda`, which are given a minimal
 * `window` stub - the same way `perangkat.test.ts` fakes what it needs.
 */
describe('portalDariHost - subdomain dulu', () => {
    it('maps each staff prefix to its portal', () => {
        assert.equal(portalDariHost('admin.sehatly.test', ''), 'admin');
        assert.equal(portalDariHost('dokter.sehatly.com', ''), 'dokter');
        assert.equal(portalDariHost('apotek.sehatly.test', ''), 'apotek');
        assert.equal(portalDariHost('kurir.sehatly.test', ''), 'kurir');
        assert.equal(portalDariHost('marketing.sehatly.test', ''), 'marketing');
    });

    it('falls back to pasien on the root domain and on localhost', () => {
        assert.equal(portalDariHost('sehatly.test', ''), 'pasien');
        assert.equal(portalDariHost('www.sehatly.com', ''), 'pasien');
        assert.equal(portalDariHost('localhost', ''), 'pasien');
        assert.equal(portalDariHost('127.0.0.1', ''), 'pasien');
    });

    it('ignores a portal that is not one of the six', () => {
        assert.equal(portalDariHost('sehatly.test', '?portal=hantu'), 'pasien');
        assert.equal(portalDariHost('sehatly.test', '?portal='), 'pasien');
        assert.equal(portalDariHost('sehatly.test', '?x=1&portal='), 'pasien');
    });
});

describe('portalDariHost - production mengabaikan ?portal=', () => {
    it('ignores override when allowOverride is false', () => {
        assert.equal(portalDariHost('sehatly.test', '?portal=admin', false), 'pasien');
        assert.equal(portalDariHost('localhost', '?portal=kurir', false), 'pasien');
    });

    it('honours override when allowOverride is true', () => {
        assert.equal(portalDariHost('sehatly.test', '?portal=admin', true), 'admin');
    });
});

describe('portalDariHost - ?portal= hanya di host polos (override eksplisit)', () => {
    it('honours the override on a host that declares no portal', () => {
        assert.equal(portalDariHost('sehatly.test', '?portal=admin', true), 'admin');
        assert.equal(portalDariHost('localhost', '?portal=kurir', true), 'kurir');
        assert.equal(portalDariHost('127.0.0.1', '?x=1&portal=marketing', true), 'marketing');
    });

    it('lets the subdomain win over a contradicting query string', () => {
        assert.equal(portalDariHost('admin.sehatly.test', '?portal=pasien', true), 'admin');
        assert.equal(portalDariHost('dokter.sehatly.test', '?portal=apotek', true), 'dokter');
    });
});

describe('portalDariHost - tanpa override eksplisit fallback ke env (test env = tidak diizinkan)', () => {
    it('ignores ?portal= when allowOverride not passed (test env has no DEV/PROD)', () => {
        assert.equal(portalDariHost('sehatly.test', '?portal=admin'), 'pasien');
        assert.equal(portalDariHost('localhost', '?portal=kurir'), 'pasien');
    });

    it('still lets subdomain win', () => {
        assert.equal(portalDariHost('admin.sehatly.test', '?portal=pasien'), 'admin');
        assert.equal(portalDariHost('dokter.sehatly.test', '?portal=apotek'), 'dokter');
    });
});

describe('portalTipe - akun ke portalnya', () => {
    it('routes every seeded tipe to a portal', () => {
        assert.equal(portalTipe('pasien'), 'pasien');
        assert.equal(portalTipe('dokter'), 'dokter');
        assert.equal(portalTipe('apoteker'), 'apotek');
        assert.equal(portalTipe('kurir'), 'kurir');
        assert.equal(portalTipe('marketing'), 'marketing');
        assert.equal(portalTipe('admin'), 'admin');
        assert.equal(portalTipe('superadmin'), 'admin');
    });

    it('sends a portal-less tipe (perawat) home to the patient portal', () => {
        assert.equal(portalTipe('perawat'), 'pasien');
        assert.equal(portalTipe(null), 'pasien');
        assert.equal(portalTipe(undefined), 'pasien');
        assert.equal(portalTipe('robot'), 'pasien');
    });
});

describe('kontrak total - setiap portal punya tipe dan rumah', () => {
    it('PORTAL_TIPE and PORTAL_HOME cover all six portals non-empty', () => {
        for (const portal of ['pasien', 'admin', 'dokter', 'apotek', 'kurir', 'marketing']) {
            assert.ok(PORTAL_TIPE[portal].length >= 1, `${portal} has no tipe`);
            assert.ok(PORTAL_HOME[portal].startsWith('/'), `${portal} home is not a path`);
        }
    });

    it('every allowed tipe in PORTAL_TIPE round-trips through portalTipe', () => {
        for (const [portal, tipes] of Object.entries(PORTAL_TIPE)) {
            for (const tipe of tipes) {
                assert.equal(portalTipe(tipe), portal, `${tipe} -> ${portal}`);
            }
        }
    });
});

describe('portalOrigin / portalBeranda - tautan pulang lintas subdomain', () => {
    const as = (hostname: string) => {
        (globalThis as { window?: unknown }).window = {
            location: { protocol: 'https:', hostname, search: '' },
        };
    };

    afterEach(() => {
        delete (globalThis as { window?: unknown }).window;
    });

    it('swaps the prefix on the current base domain', () => {
        as('admin.sehatly.test');
        assert.equal(portalOrigin('admin'), 'https://admin.sehatly.test');
        assert.equal(portalOrigin('dokter'), 'https://dokter.sehatly.test');
        assert.equal(portalOrigin('pasien'), 'https://sehatly.test');
    });

    it('leaves a two-label root host intact (no dot-count guessing)', () => {
        as('sehatly.test');
        assert.equal(portalOrigin('pasien'), 'https://sehatly.test');
        assert.equal(portalOrigin('kurir'), 'https://kurir.sehatly.test');
    });

    it('builds a full home URL and honours www', () => {
        as('kurir.sehatly.com');
        assert.equal(portalOrigin('pasien'), 'https://sehatly.com');
        assert.equal(portalBeranda('apotek'), 'https://apotek.sehatly.com/apotek/resep');
        as('www.sehatly.test');
        assert.equal(portalOrigin('pasien'), 'https://sehatly.test');
    });

    it('detectPortal ignores ?portal= in test env (no import.meta.env)', () => {
        (globalThis as { window?: unknown }).window = {
            location: { protocol: 'http:', hostname: 'sehatly.test', search: '?portal=apotek' },
        };
        assert.equal(detectPortal(), 'pasien');
    });
});
