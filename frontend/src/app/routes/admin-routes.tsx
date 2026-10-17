import { Navigate } from 'react-router';
import { RootLayout } from '@/app/root-layout';
import { RouteErrorBoundary } from '@/app/error-boundary';
import { RequirePortalAuth } from '@/app/guards';
import { PortalTidakDitemukanPage } from '@/app/portal-states';
import { AppShell } from '@/app/app-shell';
import { PortalLoginPage } from '@/pages/portal-login-page';
import { ProfilEditPage } from '@/pages/profil-edit-page';
import { AdminDokterPage } from '@/pages/admin-dokter-page';
import { AdminDokterDetailPage } from '@/pages/admin-dokter-detail-page';
import { AdminLaporanPage } from '@/pages/admin-laporan-page';
import { AdminAuditLogPage } from '@/pages/admin-audit-log-page';
import { AdminPersetujuanPdpPage } from '@/pages/admin-persetujuan-pdp-page';
import { AdminHeroPage } from '@/pages/admin-hero-page';
import { KebijakanPrivasiPage } from '@/pages/kebijakan-privasi-page';
import { SyaratKetentuanPage } from '@/pages/syarat-ketentuan-page';

/**
 * The admin portal's route table, served from `admin.sehatly.test` (plan section 2.3).
 *
 * ## The shape every staff table shares
 *
 * `/login` is public - the one-door sign-in (`PortalLoginPage`), the only address a
 * guest is ever sent to. Everything else sits under `RequirePortalAuth`, which answers
 * "may this account enter this portal" from `GET /me` and shows the wrong-portal page
 * otherwise; `/` redirects to `PORTAL_HOME` so the portal's bare address has one
 * honest destination. `/profil/edit/:userId` sits inside the guard but outside
 * `AppShell` exactly as on the patient portal, because the sign-in dialog sends a
 * shell account there whatever portal verified it. The two F02 documents are public
 * because `MasukDialog` links them from the consent checkboxes. `*` is the
 * Halodoc-style "Halaman tidak ditemukan" with its way home.
 *
 * ## Why these seven leaves are no longer on the patient portal
 *
 * They answered exactly the `tipe:admin,superadmin` API routes and gated themselves
 * in-page (F13's split) because one SPA served every account. With a door of their own,
 * the in-page gate becomes the route-level guard - the pages keep their own
 * `AdminGate` as defence in depth, but a non-admin no longer reaches them at all.
 *
 * `/admin/dokter/:id` and `/admin/dokter/:id/jadwal` are two-segment and three-segment
 * paths, and `/admin/dokter` is a literal that cannot be swallowed by the parameter
 * because its segment is the last one. The `:id/jadwal` element is the same detail page
 * with the schedule tab preselected, so the F14 contract's path and the pattern's
 * `?tab=jadwal` both address one screen.
 */
export const adminRoutes = [
    {
        element: <RootLayout />,
        errorElement: <RouteErrorBoundary />,
        children: [
            {
                path: '/login',
                element: <PortalLoginPage portal="admin" />,
                errorElement: <RouteErrorBoundary />,
            },

            /**
             * F02's documents, public on this portal for the reason they are public on
             * the patient one: the shared dialog's consent section links them, and a
             * document needs no session.
             */
            {
                path: '/kebijakan-privasi',
                element: <KebijakanPrivasiPage />,
                errorElement: <RouteErrorBoundary />,
            },
            {
                path: '/syarat-ketentuan',
                element: <SyaratKetentuanPage />,
                errorElement: <RouteErrorBoundary />,
            },

            {
                element: <RequirePortalAuth portal="admin" />,
                errorElement: <RouteErrorBoundary />,
                children: [
                    {
                        path: '/',
                        element: <Navigate to="/admin/dokter" replace />,
                        errorElement: <RouteErrorBoundary />,
                    },
                    {
                        path: '/profil/edit/:userId',
                        element: <ProfilEditPage />,
                        errorElement: <RouteErrorBoundary />,
                    },
                    {
                        element: <AppShell />,
                        /**
                         * Deliberately NOT an `errorElement`, same as the patient
                         * table: a page failure must replace one leaf, never the shell.
                         */
                        children: [
                            {
                                path: '/admin/dokter',
                                element: <AdminDokterPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/admin/dokter/:id',
                                element: <AdminDokterDetailPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/admin/dokter/:id/jadwal',
                                element: <AdminDokterDetailPage tabAwal="jadwal" />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/admin/laporan',
                                element: <AdminLaporanPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/admin/audit-log',
                                element: <AdminAuditLogPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/admin/persetujuan-pdp',
                                element: <AdminPersetujuanPdpPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                /**
                                 * The seventh admin leaf, and the only one whose subject
                                 * is PUBLIC content: `/admin/hero` edits the carousel a
                                 * signed-out visitor reads at `GET /hero`. The API route
                                 * is `auth:sanctum` + `tipe:admin,superadmin` (plus its
                                 * own `hero.kelola`), and `AdminGate` still gates the
                                 * page itself as defence in depth.
                                 */
                                path: '/admin/hero',
                                element: <AdminHeroPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                        ],
                    },
                ],
            },

            {
                path: '*',
                element: <PortalTidakDitemukanPage portal="admin" />,
                errorElement: <RouteErrorBoundary />,
            },
        ],
    },
];
