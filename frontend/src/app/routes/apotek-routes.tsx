import { Navigate } from 'react-router';
import { RootLayout } from '@/app/root-layout';
import { RouteErrorBoundary } from '@/app/error-boundary';
import { RequirePortalAuth } from '@/app/guards';
import { PortalTidakDitemukanPage } from '@/app/portal-states';
import { AppShell } from '@/app/app-shell';
import { PortalLoginPage } from '@/pages/portal-login-page';
import { ProfilEditPage } from '@/pages/profil-edit-page';
import { ApotekDashboardPage } from '@/pages/apotek-dashboard-page';
import { ApotekQueuePage } from '@/pages/apotek-queue-page';
import { ResepDetailPage } from '@/pages/resep-detail-page';
import { KebijakanPrivasiPage } from '@/pages/kebijakan-privasi-page';
import { SyaratKetentuanPage } from '@/pages/syarat-ketentuan-page';

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
                                element: <ApotekDashboardPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/apotek/resep',
                                element: <ApotekQueuePage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/resep/:id',
                                element: <ResepDetailPage />,
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
