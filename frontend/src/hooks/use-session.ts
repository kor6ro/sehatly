import { useSyncExternalStore } from 'react';
import { getAccessToken, subscribeSession } from '@/lib/token';

/**
 * "Is a session present in this tab?" as reactive state.
 *
 * ## Why the plain read is not enough
 *
 * `getAccessToken()` answers from `sessionStorage` at the moment it is called, which
 * makes it correct and permanently stale inside a component: the sign-in dialog writes
 * the pair without rendering anything, and `clearTokens()` deletes it the same way. A
 * header built on the plain read therefore shows "Masuk" to somebody who just signed
 * in, and - the failure that prompted this hook - keeps a `/me` query subscribed after
 * sign-out, so its refetch fires with no credentials and the transport answers by
 * bouncing the visitor to `/login`.
 *
 * ## Why `useSyncExternalStore` and not `useState` + `useEffect`
 *
 * The store lives outside React, so an effect would subscribe after the first paint and
 * could miss a write made in the same tick the component mounts. This hook reads the
 * snapshot during render, re-reads it whenever `subscribeSession` fires, and re-checks
 * against the live value if React ever replays a render - the same reasoning
 * `use-online-status.ts` gives for `navigator.onLine`.
 *
 * The third argument is React's server snapshot: this app is a client bundle with no
 * SSR, so it is only consulted during a hydration that never happens.
 */
export function useSession(): boolean {
    return useSyncExternalStore(
        subscribeSession,
        () => getAccessToken() !== null,
        () => false,
    );
}
