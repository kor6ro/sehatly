import { createBrowserRouter } from 'react-router';
import { AppShell } from '@/app/app-shell';
import { NotFoundPage, RequireAuth, RootPage } from '@/app/guards';
import { RootLayout } from '@/app/root-layout';
import { LoginPage } from '@/pages/login-page';
import { RegisterPage } from '@/pages/register-page';
import { OtpPage } from '@/pages/otp-page';
import { DashboardPage } from '@/pages/dashboard-page';
import { ProfilePage } from '@/pages/profile-page';
import { FamilyPage } from '@/pages/family-page';
import { AllergyPage } from '@/pages/allergy-page';
import { DoctorDirectoryPage } from '@/pages/doctor-directory-page';
import { DoctorDetailPage } from '@/pages/doctor-detail-page';
import { MyBookingsPage } from '@/pages/my-bookings-page';
import { BookingCreatePage } from '@/pages/booking-create-page';
import { DoctorBookingsPage } from '@/pages/doctor-bookings-page';
import { KonsultasiPage } from '@/pages/konsultasi-page';
import { RekamMedisPage } from '@/pages/rekam-medis-page';
import { ResepComposePage } from '@/pages/resep-compose-page';
import { ResepDetailPage } from '@/pages/resep-detail-page';
import { ApotekQueuePage } from '@/pages/apotek-queue-page';
import { PasienRiwayatResepPage } from '@/pages/pasien-resep-page';

/**
 * The route table.
 *
 * ## Two layouts, and why
 *
 * `AppShell` renders the sidebar, which needs a signed-in account to mean anything, so the
 * seven patient screens live under `RequireAuth` -> `AppShell`. The three pre-session
 * screens render `AuthLayout` themselves, which is centred and carries no account
 * navigation - rendering a patient sidebar to someone who has just typed a wrong password
 * is worse than rendering none. The public doctor directory sits outside both, because the
 * API is public and a pre-authentication browse page is the case the controller's own
 * docblock names explicitly.
 *
 * ## Inertia is not involved anywhere
 *
 * Todo 30 strips the Laravel-side Inertia scaffold. Nothing here reads a server-provided
 * page prop, calls `usePage`, or depends on a Blade view: `createBrowserRouter` is
 * `react-router` v8's client-side entry point (`react-router-dom` was removed in v8) and
 * every route resolves entirely in the browser. The only coupling to the Laravel root is
 * that `VITE_API_ORIGIN` is empty in production, where the same server serves both.
 */
export const router = createBrowserRouter([
    {
        element: <RootLayout />,
        children: [
            {
                path: '/',
                element: <RootPage />,
            },

            {
                path: '/login',
                element: <LoginPage />,
            },
            {
                path: '/register',
                element: <RegisterPage />,
            },
            {
                path: '/otp',
                element: <OtpPage />,
            },

            // Public, because `DokterController` is public by the plan's instruction.
            {
                path: '/dokter',
                element: <DoctorDirectoryPage />,
            },
            {
                path: '/dokter/:id',
                element: <DoctorDetailPage />,
            },

            // Everything a patient record belongs behind.
            {
                element: <RequireAuth />,
                children: [
                    {
                        element: <AppShell />,
                        children: [
                            { path: '/dashboard', element: <DashboardPage /> },
                            { path: '/profil', element: <ProfilePage /> },
                            { path: '/profil/keluarga', element: <FamilyPage /> },
                            { path: '/profil/alergi', element: <AllergyPage /> },

                            /**
                             * Module 2. All three sit inside `RequireAuth` and therefore
                             * inside `AppShell`, because all four booking endpoints
                             * carry `auth:sanctum` and a `permission:` - unlike `/dokter`
                             * above, which is deliberately public.
                             *
                             * `/booking/:dokterId` carries the doctor's id as a `string`,
                             * not a number, so a non-numeric segment produces the API's own
                             * 404 envelope rather than a `NaN` reaching `dokter_id`. The
                             * order matters and is not cosmetic: `/booking` is a literal and
                             * `/booking/:dokterId` has a parameter, and the literal is
                             * registered first so it is never swallowed by the parameter.
                             */
                            { path: '/booking', element: <MyBookingsPage /> },
                            {
                                path: '/booking/:dokterId',
                                element: <BookingCreatePage />,
                            },
                            {
                                path: '/dokter/booking',
                                element: <DoctorBookingsPage />,
                            },

                            /**
                             * Module 3. Both are behind `RequireAuth` because every
                             * endpoint they read carries `auth:sanctum`, and the chat
                             * additionally needs a Sanctum bearer on
                             * `POST /api/broadcasting/auth` - there is no session cookie
                             * to fall back on, which is why the realtime auth endpoint
                             * is registered under the API group rather than the web one.
                             *
                             * `KonsultasiPage` is the only screen that opens a
                             * WebSocket, so it is also the only one that pays the
                             * reconnect cost.
                             */
                            {
                                path: '/konsultasi/:id',
                                element: <KonsultasiPage />,
                            },
                            {
                                path: '/rekam-medis/:id',
                                element: <RekamMedisPage />,
                            },

                            /**
                             * Module 4. All four sit inside `RequireAuth` because every
                             * endpoint behind them carries `auth:sanctum`, and each one is
                             * gated on `user.tipe` inside the page rather than here - the
                             * same split the two screens above use.
                             *
                             * `/konsultasi/:id/resep` is a three-segment path and
                             * `/konsultasi/:id` is a two-segment one, so the longer literal is
                             * a distinct route rather than a parameter: no ordering
                             * constraint is needed and none is relied on.
                             */
                            {
                                path: '/konsultasi/:id/resep',
                                element: <ResepComposePage />,
                            },
                            {
                                path: '/resep/:id',
                                element: <ResepDetailPage />,
                            },
                            {
                                path: '/apotek/resep',
                                element: <ApotekQueuePage />,
                            },
                            {
                                path: '/pasien/resep',
                                element: <PasienRiwayatResepPage />,
                            },
                        ],
                    },
                ],
            },

            {
                path: '*',
                element: <NotFoundPage />,
            },
        ],
    },
]);
