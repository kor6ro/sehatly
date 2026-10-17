import { createBrowserRouter } from 'react-router';
import { detectPortal } from '@/lib/portal';
import { adminRoutes } from '@/app/routes/admin-routes';
import { apotekRoutes } from '@/app/routes/apotek-routes';
import { dokterRoutes } from '@/app/routes/dokter-routes';
import { kurirRoutes } from '@/app/routes/kurir-routes';
import { marketingRoutes } from '@/app/routes/marketing-routes';
import { pasienRoutes } from '@/app/routes/pasien-routes';

/**
 * The router, one table per portal (plan section 2.2).
 *
 * ## One bundle, six tables, chosen once
 *
 * Every subdomain serves the SAME build, so the portal question is asked exactly once -
 * `detectPortal()` reads the hostname at module load, before any route is registered -
 * and `createBrowserRouter` receives only that portal's table. The alternative, one
 * merged table with per-route guards, was what the app already had: an admin screen
 * registered beside a patient one, gated in-page, reachable by URL from every portal.
 * Separate tables are what makes an address exist on the portal that owns it and 404
 * (with the standard way home) everywhere else.
 *
 * `?portal=` is honoured for a plain host, which is what the end-to-end suite drives:
 * Playwright talks to one origin (`SEHATLY_BASE_URL`), so `admin-sehatly.test` is
 * stood in for by `sehatly.test/admin/dokter?portal=admin`. Because the portal is read
 * once, a client-side navigation away from that URL keeps the portal - the query
 * string is needed for the first document request only.
 *
 * The full argument for per-leaf `errorElement` lives in `routes/pasien-routes.tsx`
 * and every staff table follows the same rule.
 */
const portal = detectPortal();

export const router = createBrowserRouter(
    portal === 'admin'
        ? adminRoutes
        : portal === 'dokter'
          ? dokterRoutes
          : portal === 'apotek'
            ? apotekRoutes
            : portal === 'kurir'
              ? kurirRoutes
              : portal === 'marketing'
                ? marketingRoutes
                : pasienRoutes,
);
