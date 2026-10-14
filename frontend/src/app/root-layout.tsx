import { useEffect } from 'react';
import { Outlet, useNavigate } from 'react-router';
import { onSessionExpired } from '@/lib/http';

/**
 * The layout every route renders inside, and the home of the session-expiry redirect.
 *
 * ## Why the subscriber has to be here rather than in `App`
 *
 * `lib/http.ts` announces "the session just died" through `onSessionExpired` rather than
 * calling `router.navigate()` directly, because the router imports the pages, the pages
 * import the transport, and a direct import would close that cycle. But the handler needs
 * `useNavigate`, which only works **inside** a router. A `QueryClientProvider` is outside
 * the `RouterProvider` by construction, so this component is the earliest point in the tree
 * that is both inside the router and mounted for every route - including the public doctor
 * directory, which is exactly where a stale token is most likely to produce a 401.
 */
export function RootLayout() {
    const navigate = useNavigate();

    useEffect(
        () =>
            onSessionExpired(() => {
                void navigate('/login', { replace: true });
            }),
        [navigate],
    );

    return <Outlet />;
}
