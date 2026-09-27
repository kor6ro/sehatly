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
