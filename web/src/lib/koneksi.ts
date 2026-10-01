/**
 * The connectivity boundary, in one file, because `navigator.onLine` is a browser global
 * and the F15 thin slice has to be testable without one.
 *
 * ## What this module is, and what it deliberately is not
 *
 * It is the **detection** half of the offline slice: read the current answer, and subscribe
 * to the two events that change it. It is not a queue. `_global.md` §7 #1 forbids queueing
 * booking or payment mutations while offline - a replayed `POST /booking` is a second
 * booking, not a duplicate read - so the UI blocks the submit and keeps the form, and
 * nothing here buffers a write.
 *
 * ## Why the target is a structural type
 *
 * `window` satisfies {@link TargetKoneksi} in a browser, and a two-method fake satisfies it
 * in a unit test. Typing the parameter as `Window` would make the module untestable under
 * `node --test`, which is exactly the constraint `tests/unit/alias-hooks.mjs` exists to
 * avoid.
 */

/** The two events `navigator.onLine` changes on. */
export type PeristiwaKoneksi = 'online' | 'offline';

/**
 * The minimal event-target surface this module needs.
 *
 * `addEventListener`/`removeEventListener` are declared with the two literal event names
 * rather than `string`, so a typo is a compile error instead of a listener that never
 * fires.
 */
export type TargetKoneksi = {
    addEventListener(type: PeristiwaKoneksi, listener: () => void): void;
    removeEventListener(type: PeristiwaKoneksi, listener: () => void): void;
};

/**
 * The current answer, with the browser's own default made explicit.
 *
 * `navigator.onLine` is `true` in every browser that implements it, and `undefined` only
 * in a non-DOM context. Treating "cannot tell" as online is the honest default: the
 * alternative would show an offline banner to every server-rendered or test render, and
 * the banner's own copy tells the user what to check if the guess is wrong.
 */
export function bacaStatusOnline(
    nav: Pick<Navigator, 'onLine'> | undefined,
): boolean {
    return nav?.onLine ?? true;
}

/**
 * Subscribe to both connectivity events, returning the unsubscribe.
 *
 * Both events are listened to because `navigator.onLine` is a level, not an edge: the
 * `online` event fires when the browser regains a connection and `offline` when it loses
 * one, and a listener that only watched one of them would miss half the transitions.
 */
export function langgananKoneksi(
    target: TargetKoneksi,
    listener: () => void,
): () => void {
    target.addEventListener('online', listener);
    target.addEventListener('offline', listener);

    return () => {
        target.removeEventListener('online', listener);
        target.removeEventListener('offline', listener);
    };
}
