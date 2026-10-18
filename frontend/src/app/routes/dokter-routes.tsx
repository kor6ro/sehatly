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

const DokterDashboardPage = lazy(() =>
  import('@/pages/dokter-dashboard-page').then((mod) => ({ default: mod.DokterDashboardPage }))
);
const DoctorBookingsPage = lazy(() =>
  import('@/pages/doctor-bookings-page').then((mod) => ({ default: mod.DoctorBookingsPage }))
);
const KonsultasiPage = lazy(() =>
  import('@/pages/konsultasi-page').then((mod) => ({ default: mod.KonsultasiPage }))
);
const ResepComposePage = lazy(() =>
  import('@/pages/resep-compose-page').then((mod) => ({ default: mod.ResepComposePage }))
);

/**
 * The doctor portal's route table, served from `dokter.sehatly.test` (plan section 2.3).
 *
 * ## What moved here, and what deliberately did not
 *
 * `/dokter/dashboard` and `/dokter/booking` left the patient table with the rest of the
 * practitioner workspace. The two consultation screens join them because a consultation
 * has exactly two parties: the doctor opens the same room at `/konsultasi/:id` a patient
 * opens, and composes the resep at `/konsultasi/:id/resep` - so both addresses are
 * registered on BOTH portals, each behind its own portal guard.
 *
 * The id-free index screens (`/konsultasi`, `/rekam-medis`) are NOT here. They are the
 * patient's own lists - they resolve ids from `GET /pasien/resep` and render
 * `ForbiddenState` for any other `tipe` - so a doctor reaches a consultation from the
 * dashboard or a notification, never from an index that cannot answer for them. A
 * doctor-side consultation list is a screen of its own when it is built; until then a
 * menu item pointing at the patient's would be a link that 403s.
 */
export const dokterRoutes = [
    {
        element: <RootLayout />,
        errorElement: <RouteErrorBoundary />,
        children: [
            {
                path: '/login',
                element: <PortalLoginPage portal="dokter" />,
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
                element: <RequirePortalAuth portal="dokter" />,
                errorElement: <RouteErrorBoundary />,
                children: [
                    {
                        path: '/',
                        element: <Navigate to="/dokter/dashboard" replace />,
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
                                /**
                                 * F13's dashboard - a literal, not a parameter, so it is
                                 * a distinct route from `/dokter/:id` (which lives on the
                                 * patient portal as the public profile).
                                 */
                                path: '/dokter/dashboard',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat dashboard dokter..." />}>
                                        <DokterDashboardPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/dokter/booking',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat booking dokter..." />}>
                                        <DoctorBookingsPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                /**
                                 * The consultation room, registered on both portals: the
                                 * patient opens it from `/konsultasi` on the patient
                                 * portal, the doctor from the dashboard or a
                                 * notification here. The in-page `tipe` gate still
                                 * decides which side of the SOAP form the account sees.
                                 */
                                path: '/konsultasi/:id',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat konsultasi..." />}>
                                        <KonsultasiPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                /**
                                 * The doctor's write surface - `tipe:dokter` in the page
                                 * and `permission:` on the API. Three segments, so it is
                                 * a distinct route from `/konsultasi/:id` above and no
                                 * ordering constraint is relied on.
                                 */
                                path: '/konsultasi/:id/resep',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat komposisi resep..." />}>
                                        <ResepComposePage />
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
                element: <PortalTidakDitemukanPage portal="dokter" />,
                errorElement: <RouteErrorBoundary />,
            },
        ],
    },
];
