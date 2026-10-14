import { Link, Navigate, Outlet, useLocation, useSearchParams } from 'react-router';
import { getAccessToken } from '@/lib/token';
import { EmptyState } from '@/components/states/empty-state';
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
        return (
            <Navigate
                to="/login"
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
