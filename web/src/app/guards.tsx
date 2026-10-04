import { Link, Navigate, Outlet, useLocation } from 'react-router';
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
