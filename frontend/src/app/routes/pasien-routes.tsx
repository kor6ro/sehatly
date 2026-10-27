import { lazy, Suspense } from 'react';
import { AlihHalamanDokter, NotFoundPage, RequireAuth } from '@/app/guards';
import { RootLayout } from '@/app/root-layout';
import { RouteErrorBoundary } from '@/app/error-boundary';
import { AppShell } from '@/app/app-shell';
import { ProfilEditPage } from '@/shared/pages/profil-edit-page';
import { KebijakanPrivasiPage } from '@/shared/pages/kebijakan-privasi-page';
import { SyaratKetentuanPage } from '@/shared/pages/syarat-ketentuan-page';
import { LoadingState } from '@/shared/components/states/loading-state';

const LandingPage = lazy(() =>
  import('@/portals/pasien/pages/landing-page').then((mod) => ({ default: mod.LandingPage }))
);
const DoctorDetailPage = lazy(() =>
  import('@/portals/pasien/pages/doctor-detail-page').then((mod) => ({ default: mod.DoctorDetailPage }))
);

const DashboardPage = lazy(() =>
  import('@/portals/pasien/pages/dashboard-page').then((mod) => ({ default: mod.DashboardPage }))
);
const ProfilePage = lazy(() =>
  import('@/portals/pasien/pages/profile-page').then((mod) => ({ default: mod.ProfilePage }))
);
const DevicesPage = lazy(() =>
  import('@/portals/pasien/pages/devices-page').then((mod) => ({ default: mod.DevicesPage }))
);
const AllergyPage = lazy(() =>
  import('@/portals/pasien/pages/allergy-page').then((mod) => ({ default: mod.AllergyPage }))
);
const MyBookingsPage = lazy(() =>
  import('@/portals/pasien/pages/my-bookings-page').then((mod) => ({ default: mod.MyBookingsPage }))
);
const BookingCreatePage = lazy(() =>
  import('@/portals/pasien/pages/booking-create-page').then((mod) => ({ default: mod.BookingCreatePage }))
);
const KonsultasiIndexPage = lazy(() =>
  import('@/portals/pasien/pages/rekam-dan-konsultasi-index-page').then((mod) => ({
    default: mod.KonsultasiIndexPage,
  }))
);
const KonsultasiPage = lazy(() =>
  import('@/shared/pages/konsultasi-page').then((mod) => ({ default: mod.KonsultasiPage }))
);
const KonsultasiUlasanPage = lazy(() =>
  import('@/portals/pasien/pages/konsultasi-ulasan-page').then((mod) => ({
    default: mod.KonsultasiUlasanPage,
  }))
);
const RiwayatRekamMedisPage = lazy(() =>
  import('@/portals/pasien/pages/riwayat-rekam-medis-page').then((mod) => ({
    default: mod.RiwayatRekamMedisPage,
  }))
);
const RekamMedisPage = lazy(() =>
  import('@/portals/pasien/pages/rekam-medis-page').then((mod) => ({ default: mod.RekamMedisPage }))
);
const PasienRiwayatResepPage = lazy(() =>
  import('@/portals/pasien/pages/pasien-resep-page').then((mod) => ({
    default: mod.PasienRiwayatResepPage,
  }))
);
const ResepComposePage = lazy(() =>
  import('@/shared/pages/resep-compose-page').then((mod) => ({ default: mod.ResepComposePage }))
);
const ResepDetailPage = lazy(() =>
  import('@/shared/pages/resep-detail-page').then((mod) => ({ default: mod.ResepDetailPage }))
);
const CheckoutIndexPage = lazy(() =>
  import('@/portals/pasien/pages/checkout-index-page').then((mod) => ({ default: mod.CheckoutIndexPage }))
);
const CheckoutPage = lazy(() =>
  import('@/portals/pasien/pages/checkout-page').then((mod) => ({ default: mod.CheckoutPage }))
);
const PembayaranIndexPage = lazy(() =>
  import('@/portals/pasien/pages/pesanan-dan-pembayaran-index-page').then((mod) => ({
    default: mod.PembayaranIndexPage,
  }))
);
const PesananIndexPage = lazy(() =>
  import('@/portals/pasien/pages/pesanan-dan-pembayaran-index-page').then((mod) => ({
    default: mod.PesananIndexPage,
  }))
);
const PesananPage = lazy(() =>
  import('@/portals/pasien/pages/pesanan-page').then((mod) => ({ default: mod.PesananPage }))
);
const PembayaranPage = lazy(() =>
  import('@/portals/pasien/pages/pembayaran-page').then((mod) => ({ default: mod.PembayaranPage }))
);
const NotifikasiPage = lazy(() =>
  import('@/portals/pasien/pages/notifikasi-page').then((mod) => ({ default: mod.NotifikasiPage }))
);
const ProfilNotifikasiPage = lazy(() =>
  import('@/portals/pasien/pages/profil-notifikasi-page').then((mod) => ({
    default: mod.ProfilNotifikasiPage,
  }))
);
const PengingatPage = lazy(() =>
  import('@/portals/pasien/pages/pengingat-page').then((mod) => ({ default: mod.PengingatPage }))
);
const PrivasiPage = lazy(() =>
  import('@/portals/pasien/pages/privasi-page').then((mod) => ({ default: mod.PrivasiPage }))
);

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
                element: (
                    <Suspense fallback={<LoadingState label="Memuat profil dokter..." />}>
                        <DoctorDetailPage />
                    </Suspense>
                ),
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
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat dashboard..." />}>
                                        <DashboardPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/profil',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat profil..." />}>
                                        <ProfilePage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/profil/perangkat',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat perangkat..." />}>
                                        <DevicesPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/profil/alergi',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat alergi..." />}>
                                        <AllergyPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/profil/privasi',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat privasi..." />}>
                                        <PrivasiPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/profil/notifikasi',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat notifikasi..." />}>
                                        <ProfilNotifikasiPage />
                                    </Suspense>
                                ),
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
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat booking Anda..." />}>
                                        <MyBookingsPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/booking/:dokterId',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat buat booking..." />}>
                                        <BookingCreatePage />
                                    </Suspense>
                                ),
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
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat daftar konsultasi..." />}>
                                        <KonsultasiIndexPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/konsultasi/:id',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat ruangan konsultasi..." />}>
                                        <KonsultasiPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/konsultasi/:id/ulasan',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat ulasan konsultasi..." />}>
                                        <KonsultasiUlasanPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/rekam-medis',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat rekam medis..." />}>
                                        <RiwayatRekamMedisPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/rekam-medis/:id',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat detail rekam medis..." />}>
                                        <RekamMedisPage />
                                    </Suspense>
                                ),
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
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat komposisi resep..." />}>
                                        <ResepComposePage />
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
                            {
                                path: '/pasien/resep',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat riwayat resep..." />}>
                                        <PasienRiwayatResepPage />
                                    </Suspense>
                                ),
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
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat checkout..." />}>
                                        <CheckoutIndexPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/checkout/:resepId',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat halaman checkout..." />}>
                                        <CheckoutPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/pesanan',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat pesanan Anda..." />}>
                                        <PesananIndexPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/pesanan/:id',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat detail pesanan..." />}>
                                        <PesananPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/pembayaran',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat pembayaran..." />}>
                                        <PembayaranIndexPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/pembayaran/:pesananId',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat halaman pembayaran..." />}>
                                        <PembayaranPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/notifikasi',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat notifikasi..." />}>
                                        <NotifikasiPage />
                                    </Suspense>
                                ),
                                errorElement: <RouteErrorBoundary />,
                            },
                            {
                                path: '/pengingat',
                                element: (
                                    <Suspense fallback={<LoadingState label="Memuat pengingat..." />}>
                                        <PengingatPage />
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
                element: <NotFoundPage />,
                errorElement: <RouteErrorBoundary />,
            },
        ],
    },
];
