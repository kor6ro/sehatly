import { queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import type { User } from '@/lib/api/types';

/**
 * `GET /api/v1/me`
 *
 * The authenticated account, plus whichever of `pasien` or `dokter` it owns. Both
 * relations are eager-loaded in exactly three queries, so this is one round trip for a
 * whole screen.
 *
 * ## Why it never refuses
 *
 * `MeController` documents this: an account with neither relation - a `perawat`, a
 * `kurir`, an `admin` - is a legitimate caller and gets `null` for both, and an account
 * with both would get both. A missing `pasien` row is a **403 on the patient routes**,
 * not a failure here, because the question `/me` answers is "what does this account look
 * like" and the question the patient routes answer is "may you act on a patient record".
 *
 * The consequence for this client is that `User.pasien` being absent is a real,
 * reachable state on a patient screen, and the profile page has to say so rather than
 * showing an empty form for a record that does not exist.
 */
export async function fetchMe() {
    return request<{ user: User }>('me');
}

/**
 * The account query, version-first.
 *
 * The cache key is `['v1','me']` and nothing else: it is the one query whose result is
 * "who am I", so it must not vary on a parameter no screen can change. Everything that
 * renders an account reads this one, which is what makes `queryClient.clear()` on sign-out
 * a complete answer to "a role switch must not leak the previous role's cached data".
 */
export const meQueryKey = ['v1', 'me'] as const;

export function meOptions() {
    return queryOptions({
        queryKey: meQueryKey,
        queryFn: fetchMe,
        /**
         * The session's identity, and the cheapest possible tripwire for a token that has
         * gone bad: the 401 path in `lib/http.ts` runs before react-query is involved at
         * all, so a rejected session never even reaches the cache.
         */
        staleTime: 60_000,
    });
}
