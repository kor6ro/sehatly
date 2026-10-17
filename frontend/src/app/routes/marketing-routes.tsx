import { Navigate } from 'react-router';
import { RootLayout } from '@/app/root-layout';
import { RouteErrorBoundary } from '@/app/error-boundary';
import { RequirePortalAuth } from '@/app/guards';
import {
    PortalDalamPengerjaan,
    PortalTidakDitemukanPage,
} from '@/app/portal-states';
import { AppShell } from '@/app/app-shell';
import { PortalLoginPage } from '@/pages/portal-login-page';
import { ProfilEditPage } from '@/pages/profil-edit-page';
import { KebijakanPrivasiPage } from '@/pages/kebijakan-privasi-page';
import { SyaratKetentuanPage } from '@/pages/syarat-ketentuan-page';

/**
 * The marketing portal's route table, served from `marketing.sehatly.test` (plan
 * section 2.3).
 *
 * Same shape and same reasoning as `kurir-routes`: the door (`/login`) and the guard
 * are complete for `tipe:marketing`, while the workspace - `/marketing/dashboard`,
 * the artikel and promo screens - is Step 16. Until then the portal home is one
 * honest placeholder leaf, deleted when its pages arrive.
 */
export const marketingRoutes = [
    {
        element: <RootLayout />,
        errorElement: <RouteErrorBoundary />,
        children: [
            {
                path: '/login',
                element: <PortalLoginPage portal="marketing" />,
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
                element: <RequirePortalAuth portal="marketing" />,
                errorElement: <RouteErrorBoundary />,
                children: [
                    {
                        path: '/',
                        element: <Navigate to="/marketing/dashboard" replace />,
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
                                path: '/marketing/dashboard',
                                element: <PortalDalamPengerjaan portal="marketing" />,
                                errorElement: <RouteErrorBoundary />,
                            },
                        ],
                    },
                ],
            },

            {
                path: '*',
                element: <PortalTidakDitemukanPage portal="marketing" />,
                errorElement: <RouteErrorBoundary />,
            },
        ],
    },
];
