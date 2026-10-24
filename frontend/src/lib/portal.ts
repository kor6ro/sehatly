/**
 * Which portal this bundle is serving.
 *
 * ## One bundle, six doorways
 *
 * The multi-portal architecture (docs/update-plan-pilihan-a.md, Step 9) serves the same
 * built SPA from six hostnames: the root domain plus five staff subdomains. The hostname
 * is the only thing that differs between them - same code, same API origin, same
 * tokens - so the whole portal question collapses to one synchronous read of
 * `window.location` made once at module load, before the router is created.
 *
 * ## Why a query-string escape hatch
 *
 * `?portal=admin` lets anybody reproduce a staff portal without a subdomain entry in
 * `hosts`, and it is what the end-to-end suite runs on - Playwright talks to a single
 * origin (`SEHATLY_BASE_URL`). It is honoured ONLY when the hostname itself declares no
 * portal, so a staff subdomain stays authoritative: adding `?portal=pasien` to
 * `admin.sehatly.test` cannot smuggle a patient shell into the admin workspace (the
 * workspace is still gated by `RequirePortalAuth` against `/me`, but the routing answer
 * should never disagree with the address bar).
 */

export type Portal = 'pasien' | 'admin' | 'dokter' | 'apotek' | 'kurir' | 'marketing';

/**
 * Every portal in registration order - the staff portals first, `pasien` last because
 * the root domain is the fallback rather than a prefix match.
 */
export const PORTALS: readonly Portal[] = [
    'pasien',
    'admin',
    'dokter',
    'apotek',
    'kurir',
    'marketing',
];

/** Tipe akun that may enter each portal (`PORTAL_TIPE` in the plan, section 2.4). */
export const PORTAL_TIPE: Record<Portal, readonly string[]> = {
    pasien: ['pasien'],
    admin: ['admin', 'superadmin'],
    dokter: ['dokter'],
    apotek: ['apoteker'],
    kurir: ['kurir'],
    marketing: ['marketing'],
};

/**
 * Where `/` leads on each portal, and where the signed-out or wrong-portal visitor is
 * offered "Beranda" (plan section 2.5's post-login redirect target).
 */
export const PORTAL_HOME: Record<Portal, string> = {
    pasien: '/',
    admin: '/admin/dokter',
    dokter: '/dokter/dashboard',
    apotek: '/apotek/resep',
    kurir: '/kurir/pesanan',
    marketing: '/marketing/dashboard',
};

/** Display names for the login pages and error pages ("Portal Admin", ...). */
export const PORTAL_NAMA: Record<Portal, string> = {
    pasien: 'Sehatly',
    admin: 'Portal Admin',
    dokter: 'Portal Dokter',
    apotek: 'Portal Apotek',
    kurir: 'Portal Kurir',
    marketing: 'Portal Marketing',
};

/**
 * Staff prefixes in `detectPortal` order. Deriving the base domain from THESE rather
 * than from "everything before the first dot" matters: `sehatly.test` is two labels, so
 * a dot-count rule would strip it to `test` and send "Beranda" to `https://test`. A
 * prefix swap only ever peels a prefix this file itself defines, so it is correct on any
 * domain shape (`sehatly.test`, `sehatly.co.id`, `localhost`).
 */
const PREFIX_PORTAL: readonly (readonly [string, Portal])[] = [
    ['admin.', 'admin'],
    ['dokter.', 'dokter'],
    ['apotek.', 'apotek'],
    ['kurir.', 'kurir'],
    ['marketing.', 'marketing'],
];

function isPortal(value: string | null): value is Portal {
    return value !== null && (PORTALS as readonly string[]).includes(value);
}

/**
 * Menentukan apakah ?portal= override diizinkan.
 *
 * Production (import.meta.env.PROD === true) tidak pernah mengizinkan override
 * lewat query string — ini mencegah link jahat memaksa portal lain.
 *
 * Override diizinkan HANYA pada:
 * - mode development (import.meta.env.DEV === true)
 * - mode non-production dengan env var eksplisit VITE_E2E_PORTAL_OVERRIDE=true
 *   (untuk Playwright/CI yang menjalankan satu origin mewakili banyak portal)
 */
function overrideDiizinkan(): boolean {
    if (import.meta.env?.PROD) return false;
    if (import.meta.env?.DEV) return true;
    return import.meta.env?.VITE_E2E_PORTAL_OVERRIDE === 'true';
}

/**
 * The pure core of `detectPortal`, split out so it can be tested in plain node.
 *
 * @param host - `window.location.hostname`
 * @param search - `window.location.search`, including the leading `?`
 * @param allowOverride - eksplisit override untuk keperluan test (bypass env check)
 */
export function portalDariHost(
    host: string,
    search: string,
    allowOverride?: boolean,
): Portal {
    for (const [prefix, portal] of PREFIX_PORTAL) {
        if (host.startsWith(prefix)) return portal;
    }

    // No staff subdomain: honour ?portal= only when explicitly allowed.
    const bolehOverride = allowOverride ?? overrideDiizinkan();
    if (bolehOverride) {
        const override = new URLSearchParams(search).get('portal');
        if (isPortal(override)) return override;
    }

    return 'pasien';
}

/** Deteksi portal dari hostname saat ini (plan section 2.1). */
export function detectPortal(): Portal {
    return portalDariHost(
        window.location.hostname,
        window.location.search,
        // undefined -> pakai logika overrideDiizinkan() internal
        undefined,
    );
}

/**
 * Which portal an account's `tipe` belongs to, for the "Beranda" button on the
 * wrong-portal page.
 *
 * `perawat` (and any future `tipe` with no portal of its own) resolves to the patient
 * portal, which is where a bare account profile lives today - the same fallback the
 * app shell already makes for a non-patient account.
 */
export function portalTipe(tipe: string | null | undefined): Portal {
    if (tipe) {
        for (const portal of PORTALS) {
            if (PORTAL_TIPE[portal].includes(tipe)) return portal;
        }
    }
    return 'pasien';
}

/**
 * Absolute origin of a portal on the CURRENT base domain, so a signed-in visitor on the
 * wrong subdomain can be sent home with one click.
 *
 * `admin.sehatly.test` -> base `sehatly.test` -> `https://admin.sehatly.test`. The
 * prefix swap (rather than a map of hosts) keeps development and production identical
 * and needs no configuration, exactly like `detectPortal`.
 */
export function portalOrigin(portal: Portal): string {
    const { protocol, hostname } = window.location;
    const bare = hostname.startsWith('www.') ? hostname.slice(4) : hostname;
    const staff = PREFIX_PORTAL.find(([prefix]) => bare.startsWith(prefix));
    const base = staff ? bare.slice(staff[0].length) : bare;
    const host = portal === 'pasien' ? base : `${portal}.${base}`;
    return `${protocol}//${host}`;
}

/** The full URL of a portal's home on this base domain. */
export function portalBeranda(portal: Portal): string {
    return `${portalOrigin(portal)}${PORTAL_HOME[portal]}`;
}
