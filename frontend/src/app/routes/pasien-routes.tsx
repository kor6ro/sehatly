import { AlihHalamanDokter, NotFoundPage, RequireAuth } from '@/app/guards';
import { RootLayout } from '@/app/root-layout';
import { RouteErrorBoundary } from '@/app/error-boundary';
import { AppShell } from '@/app/app-shell';
import { LandingPage } from '@/pages/landing-page';
import { ProfilEditPage } from '@/pages/profil-edit-page';
import { DashboardPage } from '@/pages/dashboard-page';
import { ProfilePage } from '@/pages/profile-page';
import { DevicesPage } from '@/pages/devices-page';
import { AllergyPage } from '@/pages/allergy-page';
import { DoctorDetailPage } from '@/pages/doctor-detail-page';
import { MyBookingsPage } from '@/pages/my-bookings-page';
import { BookingCreatePage } from '@/pages/booking-create-page';
import { KonsultasiPage } from '@/pages/konsultasi-page';
import { KonsultasiUlasanPage } from '@/pages/konsultasi-ulasan-page';
import {
    KonsultasiIndexPage,
} from '@/pages/rekam-dan-konsultasi-index-page';
import { RiwayatRekamMedisPage } from '@/pages/riwayat-rekam-medis-page';
import { RekamMedisPage } from '@/pages/rekam-medis-page';
import { PasienRiwayatResepPage } from '@/pages/pasien-resep-page';
import { ResepComposePage } from '@/pages/resep-compose-page';
import { ResepDetailPage } from '@/pages/resep-detail-page';
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
import { PrivasiPage } from '@/pages/privasi-page';
import { KebijakanPrivasiPage } from '@/pages/kebijakan-privasi-page';
import { SyaratKetentuanPage } from '@/pages/syarat-ketentuan-page';

/**
 * The patient portal's route table - the bundle served from the root domain.
 *
 * ## What moved out, and why this table did not grow a guard
 *
 * The multi-portal split (docs/update-plan-pilihan-a.md, Steps 9-13) moved the staff
 * entrances to their own subdomain tables: `/admin/*` (7 leaves), `/dokter/dashboard`,
 * `/dokter/booking` and `/apotek/resep` are registered in `admin-routes.tsx`,
 * `dokter-routes.tsx` and `apotek-routes.tsx` now, behind `RequirePortalAuth`. This
 * table keeps everything a PATIENT reaches, plus the two screens both parties to a
 * consultation open (`/konsultasi/:id`, `/konsultasi/:id/resep` - the compose screen
 * is doctor-only and gates itself in-page, as it always has).
 *
 * `RequireAuth` stays the gate here rather than `RequirePortalAuth`, and that is a
 * decision rather than an omission: the root domain is where every account legitimately
 * lands - the one-door flow finishes at `/profil/edit`, notification deep links point
 * here, and `/dashboard` reads the account's OWN profile for any `tipe` (which is what
 * makes "Kembali ke beranda" on the wrong-portal page a working exit for `perawat`).
 * A `/me`-roundtrip gate on this table would make every patient navigation pay for a
 * question whose per-screen 403s already answer it server-side. The staff portals are
 * the inverse - their whole table is one role's workspace - and that is where
 * `RequirePortalAuth` belongs.
 *
 * ## Every route carries its own `errorElement`, and that is not boilerplate
 *
 * F3-01: `/konsultasi/:id` threw during render and React replaced the whole document
 * with a stack trace, taking the sidebar - the only way out of a signed-in screen -
 * with it.
 *
 * The fix is per-leaf on purpose. `errorElement` catches the error of the route it is
 * declared on and of that route's children, and an error that no leaf claims bubbles
 * to the nearest ancestor that does - REPLACING that ancestor's element. So an
 * `errorElement` on the `AppShell` route, which looks like the tidier single place to
 * put it, would destroy the sidebar on every page failure and reproduce the defect
 * exactly. Declaring it on every leaf means a page failure replaces one `<Outlet />`'s
 * content and nothing else. The full two-boundary argument is in
 * `app/error-boundary.tsx`.
 *
 * `frontend/tests/e2e/app-shell-robustness.spec.ts` walks this table in a real browser
 * and fails if any route takes the shell with it, so a route added later without an
 * `errorElement` is a failing test rather than a silent regression.
 *
 * ## Inertia is not involved anywhere
 *
 * Todo 30 strips the Laravel-side Inertia scaffold. Nothing here reads a server-provided
 * page prop, calls `usePage`, or depends on a Blade view: `createBrowserRouter` is
 * `react-router` v8's client-side entry point (`react-router-dom` was removed in v8) and
 * every route resolves entirely in the browser. The only coupling to the Laravel root is
 * that `VITE_API_ORIGIN` is empty in production, where the same server serves both.
 */
export const pasienRoutes = [
    {
        element: <RootLayout />,
        /**
         * The backstop for `RootLayout` itself. It is the parent of every route below,
         * so it only ever sees an error that no leaf claimed - which, with every leaf
         * carrying an `errorElement`, means an error in the layout rather than in a
         * page.
         */
        errorElement: <RouteErrorBoundary />,
        children: [
            {
                path: '/',
                element: <LandingPage />,
                errorElement: <RouteErrorBoundary />,
            },

            /**
             * There is deliberately NO `/register`, NO `/masuk` and NO `/otp`.
             *
             * All three were the same door. Registration is the same exchange as sign-in:
             * the dialog on `/` takes the number, `POST /auth/masuk` mints an account for
             * one that has none, `otp/verify` proves it, and
             * `/profil/edit/{id}?sign_up=true` finishes it. A second route would be a
             * second door to the same room, and every link that pointed at it would be
             * pointing at a page that half the visitors to it had already been through.
             *
             * `/masuk` and `/otp` were the OTHER HALF of that same exchange - the phone
             * number on one screen, the code on the next - and a visitor was handed
             * between them mid-flow, which is the exact thing the dialog was written to
             * stop doing. There is one surface now, and it holds the whole exchange:
             * `nomor` -> `kanal` -> `kode`.
             *
             * Anything that used to send somebody to `/masuk` sends them to `/?masuk=1`
             * instead, which is `/` with the dialog already open - so an expired session
             * still lands on a sign-in prompt rather than on a marketing page the visitor
             * has to find the button for.
             *
             * A stale `/masuk` or `/otp` link is not redirected: it falls through to `*`
             * and answers "Halaman tidak ditemukan", the same answer `/register` gives.
             * A redirect would keep two addresses alive for one room.
             *
             * The staff portals answer `/login` instead (see `PortalLoginPage`), which is
             * the same door with a name every portal's links can point at - on a
             * subdomain there is no landing page whose `?masuk=1` flag to raise.
             */

            /**
             * The doctor screen's OLD address, and one doctor's public profile.
             *
             * `/dokter` is a redirect now: the answer - heading, count, cards - is the
             * response to a pick made in the header, and it lives on `/`, where the pick
             * was asked. The address stays registered because this app's own navigation
             * wrote it for years and a link that 404'd would be the worst answer to a
             * question that still has one.
             *
             * `/dokter/:id` below is the ONLY place a profile can open, and every card in
             * the answer links here. It used to be reachable only from outside the list -
             * a booking, a notification - because a card opened `/?dokter={id}` in place
             * instead; the October 2026 redesign gives the profile a page of its own, which
             * is what gives a patient something they can send to somebody.
             *
             * Both stay on the PATIENT portal even after the doctor workspace moves to
             * `dokter.`: the directory is the pre-authentication browse the API answers
             * publicly, and a doctor reading their own public profile is a patient-side
             * question.
             */
            {
                path: '/dokter',
                element: <AlihHalamanDokter />,
                errorElement: <RouteErrorBoundary />,
            },
            {
                path: '/dokter/:id',
                element: <DoctorDetailPage />,
                errorElement: <RouteErrorBoundary />,
            },

            /**
             * F02's two static documents. They are public for the same reason the
             * directory always was: registration links to them, and a prospective patient has no session
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
                     *
                     * The staff route tables register this same screen for the same
                     * reason: a staff account has no `pasien` row either, and the dialog
                     * sends a shell to this form no matter which portal verified it.
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
                             * Module 2. Both sit inside `RequireAuth` and therefore
                             * inside `AppShell`, because all four booking endpoints
                             * carry `auth:sanctum` and a `permission:` - unlike
                             * `/dokter/:id` above, which is deliberately public.
                             *
                             * The doctor's OWN list (`/dokter/booking`) moved to the
                             * doctor portal with the rest of the practitioner workspace;
                             * what stays here is the patient's side of the same exchange.
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
                             * reconnect cost. It is registered on BOTH portals - the
                             * doctor opens the same room from `dokter.` - because a
                             * consultation has exactly two parties and one address.
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
                             * Module 4. All three sit inside `RequireAuth` because every
                             * endpoint behind them carries `auth:sanctum`, and each one is
                             * gated on `user.tipe` inside the page rather than here - the
                             * same split the two screens above use. The apoteker's queue
                             * (`/apotek/resep`) moved to the apotek portal; the compose
                             * screen and the detail screen stay on this portal too
                             * because a patient reads their own resep here and a dokter
                             * composes it from the consultation room.
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
                             * `user.tipe` inside the page - the same split the three
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
];
