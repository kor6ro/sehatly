import { useQuery } from '@tanstack/react-query';
import { Link, Navigate, Outlet, useLocation, useSearchParams } from 'react-router';
import { meOptions } from '@/lib/api/me';
import { getAccessToken } from '@/lib/token';
import { PORTAL_TIPE, type Portal } from '@/lib/portal';
import { PortalSalahPage } from '@/app/portal-states';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState } from '@/components/states/error-state';
import { LoadingState, SkeletonRows } from '@/components/states/loading-state';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';

/**
 * `RequireAuth` - the gate for the seven patient screens.
 *
 * ## Why it checks the *token*, not a `/me` round trip
 *
 * The token's presence is the cheapest question that can be answered synchronously, and it
 * is the right one: a missing token is a fact the client already knows. Validating it would
 * mean an extra request on every navigation, and it would answer the wrong question
 * anyway - "may this session act on a patient record?" is a **403** from the server, not a
 * 401, and a client that turned that into "sign in again" would send a correctly signed-in
 * patient to the login screen.
 *
 * So the real check happens where it belongs: each screen renders `ForbiddenState` on a
 * 403, and the transport's 401 path refreshes or signs out on its own.
 *
 * ## Why the doctor directory is NOT behind this
 *
 * `DokterController` is public by the plan's instruction, and gating a pre-authentication
 * browse page behind a session is the mistake the controller's own docblock warns about.
 * The directory now lives on `/`, which is a sibling of this guard rather than among its
 * children, so it never reaches here in the first place - which is also what keeps its 404
 * behaviour demonstrable with no session involved at all. `/dokter/:id`, a doctor's public
 * profile, is registered the same way for the same reason.
 */
export function RequireAuth() {
    const location = useLocation();

    if (getAccessToken() === null) {
        /**
         * `/?masuk=1`, the address that replaced the `/masuk` page.
         *
         * The visitor did not choose to sign in - they asked for a screen that needs a
         * session and have none - so arriving at a landing page they would then have to
         * hunt for a button on would be the half-answer `/masuk` was there to avoid. The
         * flag opens the dialog on arrival; see `LandingPage`.
         */
        return (
            <Navigate
                to="/?masuk=1"
                replace
                state={{ from: location.pathname + location.search }}
            />
        );
    }

    return <Outlet />;
}

/**
 * `/dokter` - the address of the page the doctor screen used to be, now a door into the
 * landing page that answers the same question.
 *
 * The screen moved to `/` because the whole thing - the heading, the count, the cards - is
 * the ANSWER to a pick made in the header, and an answer belongs on the page that was
 * asked. This address keeps working because it is the one this app's own navigation wrote
 * for years: it sits in history, in bookmarks and in links people already sent, and a link
 * that 404'd would be the worst possible answer to a working question.
 *
 * Four kinds of parameter, handled in the order they matter:
 *
 * | parameter | what happens | why |
 * | --- | --- | --- |
 * | `dokter` | goes to `/dokter/{id}` instead | a profile is a page of its own now, and forwarding it into the list would show the list with the question the visitor asked dropped on the floor |
 * | `spesialisasi` | carried across | the only filter the answer still reads, and it means the same thing on both addresses |
 * | `search` | carried across | F03 §9 forbids this app from WRITING a free-text query into an address; dropping one an older build already wrote would trade that privacy rule for a wrong answer |
 * | `tipe`, `telemedisin` | dropped, not carried | nothing offers them and `DirektoriDokter` no longer reads them, so carrying them would produce `/?tipe=…`, an address whose parameter narrows nothing and which this page therefore renders as the marketing sections - a redirect that answers half a question |
 *
 * With no constraint left, `?semua=1` is added so the visitor still lands on the answer
 * rather than on the marketing sections.
 *
 * `replace` is used so the old address is not left in history as a stop that bounces.
 */
export function AlihHalamanDokter() {
    const [params] = useSearchParams();

    const dokter = params.get('dokter');

    if (dokter !== null && dokter !== '') {
        return <Navigate to={`/dokter/${dokter}`} replace />;
    }

    const berikut = new URLSearchParams(params);

    berikut.delete('dokter');
    berikut.delete('tipe');
    berikut.delete('telemedisin');

    if (!berikut.has('spesialisasi')) {
        berikut.set('semua', '1');
    }

    return <Navigate to={`/?${berikut.toString()}`} replace />;
}

/**
 * `*` - an unknown path.
 *
 * A named not-found screen rather than a bare `404`, because this router is client-side and
 * a mistyped URL is the most likely way to reach it.
 */
export function NotFoundPage() {
    return (
        <main className="flex min-h-screen flex-col gap-6 p-4 md:p-6">
            <PageHeader title="Halaman tidak ditemukan" />

            <EmptyState
                title="Alamat ini tidak dikenal"
                description="Periksa kembali tautan yang Anda buka, atau kembali ke halaman utama."
                action={
                    <Button asChild variant="outline">
                        <Link to="/">Kembali ke beranda</Link>
                    </Button>
                }
            />
        </main>
    );
}

/**
 * `RequirePortalAuth` - the gate for the five staff portals (plan section 2.4), and the
 * reason the two guards can coexist rather than one replacing the other.
 *
 * ## Why the staff portals ask `/me` and the patient portal does not
 *
 * `RequireAuth` above answers "is there a session" from local state, deliberately. That
 * is the right question on the patient portal, where every `tipe` may legitimately land
 * (`/dashboard` reads the account's own profile; a fresh shell account is mid-sign-up)
 * and the per-screen 403s already gate the patient-record endpoints.
 *
 * A staff portal is the opposite: its ENTIRE route table is one role's workspace, so
 * "which `tipe` is this session" is not a server-side question to defer - it is the
 * question that decides whether this bundle should render at all. The answer can only
 * come from the server (`GET /me`, cached 60s by `meOptions`), never from a flag stored
 * in localStorage: a client that trusted its own copy would show the admin workspace to
 * anybody who edited it, and the real guard remains the `tipe:`/`permission:` middleware
 * on every API route behind these screens.
 *
 * ## Why a guest is sent to `/login` and not shown the error page
 *
 * Plan section 2.4's redirect, and the right call: `/login` is a PUBLIC route of every
 * portal by design (that is where a wrong-portal visitor gets the chance to sign in
 * with the right account), so forwarding a guest there leaks nothing. The Halodoc-style
 * page below is for the one case a redirect cannot answer - the session is valid and
 * STILL refused.
 *
 * ## Why the refusal says "not found" and offers a way home
 *
 * The plan's `ForbiddenState` spelled out the portal's allowed `tipe`. That wording
 * turns a refusal into a hint about which accounts the portal wants; the version the
 * user chose instead - `PortalSalahPage` - answers as a 404 would, while still giving
 * the signed-in visitor a working "Kembali ke beranda" to their own portal. The tipe is
 * read from `/me` here and passed along only to compute that exit.
 *
 * `/profil/edit/:userId` sits inside this guard but OUTSIDE `AppShell`, exactly as on
 * the patient portal: a staff account that also wants to be a patient completes the
 * one-door form, and the dialog's post-verify navigation (`/` for a finished account)
 * resolves to `PORTAL_HOME` on every portal - so no path through here dead-ends.
 */
export function RequirePortalAuth({ portal }: { portal: Portal }) {
    const location = useLocation();
    const me = useQuery(meOptions());

    if (getAccessToken() === null) {
        /**
         * The deep link is carried in `state.from` for the same reason `RequireAuth`
         * carries it, even though today's single-door dialog always resumes at `/`:
         * the state is the router's own record of where the visitor was headed, and
         * dropping it here would make the guard the one screen that forgets.
         */
        return (
            <Navigate
                to="/login"
                replace
                state={{ from: location.pathname + location.search }}
            />
        );
    }

    if (me.isPending) {
        /**
         * A named, announced wait rather than a blank frame - the same reasoning as
         * every `LoadingState` in the app, applied to the one screen that can be
         * reached before a single portal request has answered.
         */
        return (
            <LoadingState label="Memeriksa akun...">
                <SkeletonRows rows={4} />
            </LoadingState>
        );
    }

    if (me.isError) {
        /**
         * A transport-level failure, not a refusal: the 401 path in `lib/http.ts`
         * refreshes or signs out before react-query sees it, so what reaches here is a
         * network or server error - which gets the same status words and retry as
         * anywhere else rather than a portal answer it has no basis to give.
         */
        return <ErrorState error={me.error} />;
    }

    const tipe = me.data?.data.user?.tipe;

    if (!PORTAL_TIPE[portal].includes(tipe ?? '')) {
        return <PortalSalahPage portal={portal} tipe={tipe} />;
    }

    return <Outlet />;
}
