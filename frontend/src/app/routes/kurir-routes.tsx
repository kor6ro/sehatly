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

const KurirPesananPage = lazy(() =>
  import('@/pages/kurir-pesanan-page').then((mod) => ({ default: mod.KurirPesananPage }))
);
const KurirPesananDetailPage = lazy(() =>
  import('@/pages/kurir-pesanan-detail-page').then((mod) => ({
    default: mod.KurirPesananDetailPage,
  }))
);

/**
 * The kurir portal's route table, served from `kurir.sehatly.test` (plan section 2.3).
 *
 * The sign-in door and the guard are complete: a `kurir` account can authenticate,
 * `RequirePortalAuth` admits exactly `tipe:kurir`, and the wrong account gets the
 * standard 404-with-way-home. What is NOT in this round is the delivery workspace
 * itself - `/kurir/pesanan` and `/kurir/pesanan/:id` are Step 15 - so the portal home
 * renders one honest placeholder leaf rather than a 404 that would tell a
 * just-confirmed kurir their own address does not exist. The leaf is deleted when its
 * page arrives.
 */
export const kurirRoutes = [
    {
        element: <RootLayout />,
        errorElement: <RouteErrorBoundary />,
        children: [
            {
                path: '/login',
                element: <PortalLoginPage portal="kurir" />,
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
                element: <RequirePortalAuth portal="kurir" />,
                errorElement: <RouteErrorBoundary />,
                children: [
                    {
                        path: '/',
                        element: <Navigate to="/kurir/pesanan" replace />,
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
                                path: '/kurir/pesanan',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat pesanan..." />}>
                                        <KurirPesananPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/kurir/pesanan/:id',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat detail pesanan..." />}>
                                        <KurirPesananDetailPage />
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
                element: <PortalTidakDitemukanPage portal="kurir" />,
                errorElement: <RouteErrorBoundary />,
            },
        ],
    },
];
