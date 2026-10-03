import { createBrowserRouter } from 'react-router';
import { AppShell } from '@/app/app-shell';
import { RouteErrorBoundary } from '@/app/error-boundary';
import { NotFoundPage, RequireAuth, RootPage } from '@/app/guards';
import { RootLayout } from '@/app/root-layout';
import { LoginPage } from '@/pages/login-page';
import { ProfilEditPage } from '@/pages/profil-edit-page';
import { OtpPage } from '@/pages/otp-page';
import { DashboardPage } from '@/pages/dashboard-page';
import { ProfilePage } from '@/pages/profile-page';
import { DevicesPage } from '@/pages/devices-page';
import { FamilyPage } from '@/pages/family-page';
import { AllergyPage } from '@/pages/allergy-page';
import { DoctorDirectoryPage } from '@/pages/doctor-directory-page';
import { DoctorDetailPage } from '@/pages/doctor-detail-page';
import { MyBookingsPage } from '@/pages/my-bookings-page';
import { BookingCreatePage } from '@/pages/booking-create-page';
import { DoctorBookingsPage } from '@/pages/doctor-bookings-page';
import { DokterDashboardPage } from '@/pages/dokter-dashboard-page';
import { KonsultasiPage } from '@/pages/konsultasi-page';
import { KonsultasiUlasanPage } from '@/pages/konsultasi-ulasan-page';
import {
    KonsultasiIndexPage,
} from '@/pages/rekam-dan-konsultasi-index-page';
import { RekamMedisPage } from '@/pages/rekam-medis-page';
import { RiwayatRekamMedisPage } from '@/pages/riwayat-rekam-medis-page';
import { ResepComposePage } from '@/pages/resep-compose-page';
import { ResepDetailPage } from '@/pages/resep-detail-page';
import { ApotekQueuePage } from '@/pages/apotek-queue-page';
import { PasienRiwayatResepPage } from '@/pages/pasien-resep-page';
import { CheckoutIndexPage } from '@/pages/checkout-index-page';
import { CheckoutPage } from '@/pages/checkout-page';
import {
    PembayaranIndexPage,
    PesananIndexPage,
} from '@/pages/pesanan-dan-pembayaran-index-page';
import { PesananPage } from '@/pages/pesanan-page';
import { PembayaranPage } from '@/pages/pembayaran-page';
import { NotifikasiPage } from '@/pages/notifikasi-page';
import { ProfilNotifikasiPage } from '@/pages/profil-notifikasi-page';
import { PengingatPage } from '@/pages/pengingat-page';
import { AdminDokterPage } from '@/pages/admin-dokter-page';
import { AdminDokterDetailPage } from '@/pages/admin-dokter-detail-page';
import { AdminLaporanPage } from '@/pages/admin-laporan-page';
import { AdminAuditLogPage } from '@/pages/admin-audit-log-page';
import { AdminPersetujuanPdpPage } from '@/pages/admin-persetujuan-pdp-page';
import { PrivasiPage } from '@/pages/privasi-page';
import { KebijakanPrivasiPage } from '@/pages/kebijakan-privasi-page';
import { SyaratKetentuanPage } from '@/pages/syarat-ketentuan-page';

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
 * ## Every route carries its own `errorElement`, and that is not boilerplate
 *
 * F3-01: `/konsultasi/:id` threw during render and React replaced the whole document with
 * a stack trace, taking the sidebar - the only way out of a signed-in screen - with it.
 *
 * The fix is per-leaf on purpose. `errorElement` catches the error of the route it is
 * declared on and of that route's children, and an error that no leaf claims bubbles to the
 * nearest ancestor that does - REPLACING that ancestor's element. So an `errorElement` on
 * the `AppShell` route, which looks like the tidier single place to put it, would destroy
 * the sidebar on every page failure and reproduce the defect exactly. Declaring it on all
 * twenty-eight leaves means a page failure replaces one `<Outlet />`'s content and nothing
 * else. The full two-boundary argument is in `app/error-boundary.tsx`.
 *
 * `web/tests/e2e/app-shell-robustness.spec.ts` walks this table in a real browser and fails
 * if any route takes the shell with it, so a route added later without an `errorElement`
 * is a failing test rather than a silent regression.
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
        /**
         * The backstop for `RootLayout` itself. It is the parent of every route below, so
         * it only ever sees an error that no leaf claimed - which, with twenty-eight leaves
         * carrying an `errorElement`, means an error in the layout rather than in a page.
         */
        errorElement: <RouteErrorBoundary />,
        children: [
            {
                path: '/',
                element: <RootPage />,
                errorElement: <RouteErrorBoundary />,
            },

            {
                path: '/login',
                element: <LoginPage />,
                errorElement: <RouteErrorBoundary />,
            },
            /**
             * There is deliberately NO `/register`.
             *
             * Registration is the same door as sign-in: the dialog takes the number,
             * `POST /auth/login` mints an account for one that has none, `otp/verify`
             * proves it, and `/profil/edit/{id}?sign_up=true` finishes it. A second
             * route would be a second door to the same room, and every link that
             * pointed at it would be pointing at a page that half the visitors to it
             * had already been through.
             */
            {
                path: '/otp',
                element: <OtpPage />,
                errorElement: <RouteErrorBoundary />,
            },

            // Public, because `DokterController` is public by the plan's instruction.
            {
                path: '/dokter',
                element: <DoctorDirectoryPage />,
                errorElement: <RouteErrorBoundary />,
            },
            {
                path: '/dokter/:id',
                element: <DoctorDetailPage />,
                errorElement: <RouteErrorBoundary />,
            },

            /**
             * F02's two static documents. They are public for the same reason `/dokter`
             * is: registration links to them, and a prospective patient has no session
             * yet. No endpoint serves document text, so there is no fetch to fail and
             * nothing to gate.
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

            // Everything a patient record belongs behind.
            {
                element: <RequireAuth />,
                errorElement: <RouteErrorBoundary />,
                children: [
                    /**
                     * The one-door flow's finishing screen, and the only authenticated
                     * route that does NOT sit inside `AppShell`.
                     *
                     * The account reaching it was minted minutes ago and owns no
                     * `pasien` row yet, so every sidebar item would resolve to a patient
                     * screen with nothing to render - six dead ends beside the form that
                     * would fix it. It is inside `RequireAuth` because the token is real
                     * and required; it is outside `AppShell` because the workspace is not
                     * open to this account yet. Completing the form navigates to
                     * `/dashboard`, which is where the shell starts.
                     */
                    {
                        path: '/profil/edit/:userId',
                        element: <ProfilEditPage />,
                        errorElement: <RouteErrorBoundary />,
                    },
                    {
                        element: <AppShell />,
                        /**
                         * Deliberately NOT an `errorElement`. A page failure is caught by
                         * the page's own leaf, so the failure never reaches this route and
                         * this element is never replaced. Declaring one here would be the
                         * tidy-looking mistake that recreates F3-01.
                         */
                        children: [
                            {
                                path: '/dashboard',
                                element: <DashboardPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/profil',
                                element: <ProfilePage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/profil/keluarga',
                                element: <FamilyPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                /**
                                 * F01 §4.3/§10: the device list. Inside `RequireAuth`
                                 * because `GET|DELETE /auth/devices` are the caller's own
                                 * sessions and carry `auth:sanctum`.
                                 */
                                path: '/profil/perangkat',
                                element: <DevicesPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/profil/alergi',
                                element: <AllergyPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/profil/privasi',
                                element: <PrivasiPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                /**
                                 * F11's preference surface. `GET|PUT /profil/notifikasi`
                                 * carry `auth:sanctum` + `permission:notifikasi.lihat`,
                                 * so it sits inside `RequireAuth` like every other
                                 * profile leaf.
                                 */
                                path: '/profil/notifikasi',
                                element: <ProfilNotifikasiPage />,
                                errorElement: <RouteErrorBoundary />,
                            },

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
                            {
                                path: '/booking',
                                element: <MyBookingsPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/booking/:dokterId',
                                element: <BookingCreatePage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/dokter/booking',
                                element: <DoctorBookingsPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                /**
                                 * F13's dashboard. A literal, not a parameter, so it
                                 * is a distinct route from `/dokter/:id` above - the
                                 * dashboard sits INSIDE `RequireAuth`/`AppShell`
                                 * because every endpoint behind it is authenticated,
                                 * while `/dokter/:id` is the public directory.
                                 */
                                path: '/dokter/dashboard',
                                element: <DokterDashboardPage />,
                                errorElement: <RouteErrorBoundary />,
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
                             *
                             * The two id-free index routes below it are the fix for F3-06.
                             * The sidebar used to link `/konsultasi/1` and
                             * `/rekam-medis/1`, which is an error page for every account
                             * that does not own row 1; each index resolves the caller's own
                             * real ids and says so plainly when there are none.
                             */
                            {
                                path: '/konsultasi',
                                element: <KonsultasiIndexPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/konsultasi/:id',
                                element: <KonsultasiPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                /**
                                 * F04's patient write surface, and the SPA destination
                                 * of `NotificationService::ulasanDiminta()`'s
                                 * `/api/v1/konsultasi/{id}/ulasan` link (see
                                 * `features/notifikasi/deep-link.ts`). A three-segment
                                 * path, so it is a distinct route from `/konsultasi/:id`
                                 * and no ordering constraint is relied on.
                                 */
                                path: '/konsultasi/:id/ulasan',
                                element: <KonsultasiUlasanPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/rekam-medis',
                                element: <RiwayatRekamMedisPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/rekam-medis/:id',
                                element: <RekamMedisPage />,
                                errorElement: <RouteErrorBoundary />,
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
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/resep/:id',
                                element: <ResepDetailPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/apotek/resep',
                                element: <ApotekQueuePage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/pasien/resep',
                                element: <PasienRiwayatResepPage />,
                                errorElement: <RouteErrorBoundary />,
                            },

                            /**
                             * Module 5. Three two-segment parameterised paths and one
                             * literal, so no ordering constraint is needed between them and
                             * none is relied on: `/notifikasi` can never be swallowed by
                             * `/pesanan/:id` because the segments differ.
                             *
                             * They are all inside `RequireAuth` because every endpoint
                             * behind them carries `auth:sanctum`, and each is gated on
                             * `user.tipe` inside the page - the same split the four
                             * Module 4 screens above use.
                             *
                             * `/checkout/:resepId` and `/pesanan/:id` are two-segment
                             * paths for two different nouns, and neither is a prefix of
                             * the other, so both are distinct routes rather than one
                             * shadowing the other. Their id-free siblings
                             * (`/checkout`, `/pesanan`, `/pembayaran`) are the other half
                             * of the F3-06 fix: the sidebar used to point every account at
                             * id `1`, and a literal and a parameter at the same position
                             * are two distinct routes, so the destination the nav can
                             * honestly offer is a separate page.
                             */
                            {
                                path: '/checkout',
                                element: <CheckoutIndexPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/checkout/:resepId',
                                element: <CheckoutPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/pesanan',
                                element: <PesananIndexPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/pesanan/:id',
                                element: <PesananPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/pembayaran',
                                element: <PembayaranIndexPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/pembayaran/:pesananId',
                                element: <PembayaranPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/notifikasi',
                                element: <NotifikasiPage />,
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                /**
                                 * F11's reminder CRUD. All four routes carry the same
                                 * `permission:notifikasi.lihat` guard as the inbox, so
                                 * the page lives in the same shell group.
                                 */
                                path: '/pengingat',
                                element: <PengingatPage />,
                                errorElement: <RouteErrorBoundary />,
                            },

                            /**
                             * F14's admin surfaces. Six leaves, all behind
                             * `RequireAuth`/`AppShell` because every
                             * `/api/v1/admin/*` route is `auth:sanctum` +
                             * `tipe:admin,superadmin`; each page gates on the
                             * account type itself (F13's split) and renders
                             * `ForbiddenState` for a non-admin, so a shared
                             * admin guard component is not needed at the route
                             * level and a non-admin still gets the shell's way
                             * out.
                             *
                             * `/admin/dokter/:id` and `/admin/dokter/:id/jadwal`
                             * are two-segment and three-segment paths, and
                             * `/admin/dokter` is a literal that cannot be
                             * swallowed by the parameter because its segment is
                             * the last one. The `:id/jadwal` element is the same
                             * detail page with the schedule tab preselected, so
                             * the F14 contract's path and the pattern's
                             * `?tab=jadwal` both address one screen.
                             */
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
                        ],
                    },
                ],
            },

            {
                path: '*',
                element: <NotFoundPage />,
                errorElement: <RouteErrorBoundary />,
            },
        ],
    },
]);
