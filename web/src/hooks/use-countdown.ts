import { useEffect, useMemo, useState } from 'react';

/**
 * Seconds until the code expires, counted down from the server's own
 * `kedaluwarsa_at`.
 *
 * The countdown is a **read** of the deadline, not a decision: the submit button is not
 * disabled on zero. A browser clock is not a trusted clock, and refusing to send a request
 * on the strength of a local countdown would reject a code the server would still accept -
 * which is the worse of the two failures.
 *
 * A resend replaces `kedaluwarsaAt` with the new code's own deadline, and the effect below
 * re-runs against it, so one screen never shows two timers.
 *
 * Extracted from the OTP page because the sign-in dialog counts down against exactly the
 * same deadline and must not disagree with it about how long is left.
 */
export function useCountdown(kedaluwarsaAt: string | null, ttlDetik: number): number {
    const fallback = useMemo(
        () => new Date(Date.now() + ttlDetik * 1000),
        [ttlDetik],
    );

    const deadline = useMemo(
        () => (kedaluwarsaAt === null ? fallback : new Date(kedaluwarsaAt)),
        [fallback, kedaluwarsaAt],
    );

    const [remaining, setRemaining] = useState(() =>
        Math.max(0, Math.ceil((deadline.getTime() - Date.now()) / 1000)),
    );

    useEffect(() => {
        if (Number.isNaN(deadline.getTime())) {
            return;
        }

        const tick = (): void => {
            setRemaining(
                Math.max(0, Math.ceil((deadline.getTime() - Date.now()) / 1000)),
            );
        };

        tick();

        const timer = globalThis.setInterval(tick, 1000);

        return () => {
            globalThis.clearInterval(timer);
        };
    }, [deadline]);

    return remaining;
}
