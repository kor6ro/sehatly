import { QueryClient } from '@tanstack/react-query';
import { ApiError } from '@/lib/http';

/**
 * The one `QueryClient` the SPA uses.
 *
 * ## Why `mutations: { retry: 0 }` is the load-bearing default
 *
 * A query is idempotent: replaying `GET /dokter?page=2` costs a request and changes
 * nothing. A mutation is not. The Module 2 endpoints this client will grow into make the
 * difference concrete - `POST /booking` on a retry after a response the client never saw
 * **double-books the patient**, and the plan names that exact failure. So retries are
 * enabled for reads and disabled for writes, and the write path is the only place a
 * caller may opt back in.
 *
 * ## Why `retry: 2` for queries, and why it is not a blanket retry
 *
 * A read that fails because the dev server was restarting is worth retrying, and two
 * attempts cover a transient blip without making a genuine error wait four seconds. A
 * 4xx is not transient, and replaying a request the server has already answered on its
 * merits only delays the error message. `retry` is therefore a function of the failure,
 * not a number.
 */
export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            gcTime: 300_000,
            retry: shouldRetryQuery,
            refetchOnWindowFocus: false,
        },
        mutations: {
            retry: 0,
        },
    },
});

/**
 * Retry a failed query, but only for failures that could plausibly succeed next time.
 *
 * 4xx is the server's considered answer: a 401 has already been through the single
 * refresh path in `afterResponse` by the time it surfaces here, a 403 will still be a 403,
 * a 404 will still be a 404, and a 422 is a rejected payload. `status === 0` is this
 * client's name for "the request never reached the server" (see `networkMessage` in
 * `lib/http.ts`), and that is the case worth replaying.
 */
function shouldRetryQuery(failureCount: number, error: unknown): boolean {
    if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
        return false;
    }

    return failureCount < 2;
}
