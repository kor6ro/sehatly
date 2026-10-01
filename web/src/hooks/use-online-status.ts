import { useSyncExternalStore } from 'react';
import { bacaStatusOnline, langgananKoneksi } from '@/lib/koneksi';

/**
 * The device's connectivity, as a React value.
 *
 * ## Why `useSyncExternalStore` and not `useState` + `useEffect`
 *
 * `navigator.onLine` is an external store, and this is the hook React ships for exactly
 * that. The effect version has a real gap: the initial `useState` snapshot is taken during
 * render, and a connection change between that render and the effect's subscription would
 * be missed until the next event. `useSyncExternalStore` re-reads the snapshot after
 * subscribing, so the value cannot be stale.
 *
 * ## Why the server snapshot is `true`
 *
 * There is no server render in this SPA, but the third argument is required and the honest
 * value is "assume online": a banner rendered during a hypothetical hydration pass would
 * flash for every user, and the banner's own copy is what tells a genuinely offline user
 * what to check.
 */
export function useOnlineStatus(): boolean {
    return useSyncExternalStore(
        (listener) => langgananKoneksi(window, listener),
        () => bacaStatusOnline(globalThis.navigator),
        () => true,
    );
}
