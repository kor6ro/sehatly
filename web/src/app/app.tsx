import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router';
import { AppErrorBoundary } from '@/app/error-boundary';
import { router } from '@/app/router';
import { queryClient } from '@/lib/query-client';

/**
 * The provider tree.
 *
 * ## Why the `QueryClient` moved out of this file
 *
 * It now lives in `lib/query-client.ts` and is imported by the API modules, which need it
 * for `invalidateQueries` in their mutation options. A `QueryClient` constructed inline here
 * would have been a second, unrelated instance the moment the first `invalidateQueries` was
 * written - and the symptom would be a cache that never refreshes, which looks like a
 * server bug and is not one.
 *
 * The session-expiry redirect is deliberately **not** here. `lib/http.ts` announces the event
 * rather than calling `router.navigate()` (the router imports the pages, the pages import
 * the transport, and that would close an import cycle), but the subscriber has to run
 * *inside* the router to use `useNavigate`, so it lives in `@/app/root-layout.tsx` as the
 * parent of every route.
 *
 * ## Why `AppErrorBoundary` is the OUTERMOST node, and not inside the shell
 *
 * It is the backstop for a failure with no route to blame: a router that cannot
 * initialise, a provider that throws while mounting. Mounted inside `AppShell` it could not
 * be - `AppShell` is a route element, so anything wrapping it would have to wrap the
 * router too, and this is the one place that can.
 *
 * It is deliberately NOT the boundary a page failure reaches. Every route in
 * `router.tsx` carries its own `errorElement`, because an error caught one level too high
 * replaces the failing parent's element and takes the sidebar with it - which is F3-01
 * itself. See `app/error-boundary.tsx` for the two-boundary argument.
 */
export function App() {
    return (
        <AppErrorBoundary>
            <QueryClientProvider client={queryClient}>
                <RouterProvider router={router} />
            </QueryClientProvider>
        </AppErrorBoundary>
    );
}
