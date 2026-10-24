import { lazy, Suspense } from 'react';
import { Navigate } from 'react-router';
import { RootLayout } from '@/app/root-layout';
import { RouteErrorBoundary } from '@/app/error-boundary';
import { RequirePortalAuth } from '@/app/guards';
import { PortalTidakDitemukanPage } from '@/app/portal-states';
import { AppShell } from '@/app/app-shell';
import { PortalLoginPage } from '@/pages/portal-login-page';
import { ProfilEditPage } from '@/pages/profil-edit-page';
import { KebijakanPrivasiPage } from '@/pages/kebijakan-privasi-page';
import { SyaratKetentuanPage } from '@/pages/syarat-ketentuan-page';
import { LoadingState } from '@/components/states/loading-state';

const ApotekDashboardPage = lazy(() =>
  import('@/pages/apotek-dashboard-page').then((mod) => ({ default: mod.ApotekDashboardPage }))
);
const ApotekQueuePage = lazy(() =>
  import('@/pages/apotek-queue-page').then((mod) => ({ default: mod.ApotekQueuePage }))
);
const ResepDetailPage = lazy(() =>
  import('@/pages/resep-detail-page').then((mod) => ({ default: mod.ResepDetailPage }))
);

/**
 * The apotek portal's route table, served from `apotek.sehatly.test` (plan section 2.3).
 *
 * The queue (`/apotek/resep`) moved here from the patient table, and `/resep/:id` is
 * registered beside it because the apoteker's verification continues from a queue row
 * into the prescription detail - the same address the patient opens for their own
 * resep, gated per-`tipe` in the page and by `permission:` on the API.
 *
 * The patient's checkout and order screens stay on the patient portal: they are the
 * patient's exchange with the pharmacy, and a queue that answers `tipe:apoteker` is
 * the whole of the apoteker's own surface today.
 */
export const apotekRoutes = [
    {
        element: <RootLayout />,
        errorElement: <RouteErrorBoundary />,
        children: [
            {
                path: '/login',
                element: <PortalLoginPage portal="apotek" />,
                errorElement: <RouteErrorBoundary />,
            },

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
                element: <RequirePortalAuth portal="apotek" />,
                errorElement: <RouteErrorBoundary />,
                children: [
                    {
                        path: '/',
                        element: <Navigate to="/apotek/dashboard" replace />,
                        errorElement: <RouteErrorBoundary />,
                    },
                    {
                        path: '/profil/edit/:userId',
                        element: <ProfilEditPage />,
                        errorElement: <RouteErrorBoundary />,
                    },
                    {
                        element: <AppShell />,
                        children: [
                            {
                                path: '/apotek/dashboard',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat dashboard apotek..." />}>
                                        <ApotekDashboardPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/apotek/resep',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat antrian resep..." />}>
                                        <ApotekQueuePage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/resep/:id',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat detail resep..." />}>
                                        <ResepDetailPage />
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
                element: <PortalTidakDitemukanPage portal="apotek" />,
                errorElement: <RouteErrorBoundary />,
            },
        ],
    },
];
