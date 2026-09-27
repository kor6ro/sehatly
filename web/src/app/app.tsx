import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router';
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
 */
export function App() {
    return (
        <QueryClientProvider client={queryClient}>
            <RouterProvider router={router} />
        </QueryClientProvider>
    );
}
