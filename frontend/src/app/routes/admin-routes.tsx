import { lazy, Suspense } from 'react';
import { Navigate } from 'react-router';
import { RootLayout } from '@/app/root-layout';
import { RouteErrorBoundary } from '@/app/error-boundary';
import { RequirePortalAuth } from '@/app/guards';
import { PortalTidakDitemukanPage } from '@/app/portal-states';
import { AppShell } from '@/app/app-shell';
import { AdminLoginPage } from '@/pages/admin-login-page';
import { ProfilEditPage } from '@/pages/profil-edit-page';
import { KebijakanPrivasiPage } from '@/pages/kebijakan-privasi-page';
import { SyaratKetentuanPage } from '@/pages/syarat-ketentuan-page';
import { LoadingState } from '@/components/states/loading-state';

const AdminDokterBuatPage = lazy(() =>
  import('@/pages/admin-dokter-buat-page').then((mod) => ({ default: mod.AdminDokterBuatPage }))
);
const AdminDokterPage = lazy(() =>
  import('@/pages/admin-dokter-page').then((mod) => ({ default: mod.AdminDokterPage }))
);
const AdminDokterDetailPage = lazy(() =>
  import('@/pages/admin-dokter-detail-page').then((mod) => ({
    default: mod.AdminDokterDetailPage,
  }))
);
const AdminLaporanPage = lazy(() =>
  import('@/pages/admin-laporan-page').then((mod) => ({ default: mod.AdminLaporanPage }))
);
const AdminAuditLogPage = lazy(() =>
  import('@/pages/admin-audit-log-page').then((mod) => ({ default: mod.AdminAuditLogPage }))
);
const AdminPersetujuanPdpPage = lazy(() =>
  import('@/pages/admin-persetujuan-pdp-page').then((mod) => ({
    default: mod.AdminPersetujuanPdpPage,
  }))
);
const AdminHeroPage = lazy(() =>
  import('@/pages/admin-hero-page').then((mod) => ({ default: mod.AdminHeroPage }))
);

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
                element: <AdminLoginPage />,
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
                                path: '/admin/dokter/buat',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat buat dokter..." />}>
                                        <AdminDokterBuatPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/admin/dokter',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat daftar dokter..." />}>
                                        <AdminDokterPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/admin/dokter/:id',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat detail dokter..." />}>
                                        <AdminDokterDetailPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/admin/dokter/:id/jadwal',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat jadwal dokter..." />}>
                                        <AdminDokterDetailPage tabAwal="jadwal" />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/admin/laporan',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat laporan..." />}>
                                        <AdminLaporanPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/admin/audit-log',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat audit log..." />}>
                                        <AdminAuditLogPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/admin/persetujuan-pdp',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat persetujuan PDP..." />}>
                                        <AdminPersetujuanPdpPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/admin/hero',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat hero..." />}>
                                        <AdminHeroPage />
                                    </Suspense>
                                ),
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
