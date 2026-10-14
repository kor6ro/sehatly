/**
 * The typing indicator, as two pure rules and no transport.
 *
 * `chat.mengetik` is a CLIENT-ONLY whisper: it is sent on the already-authorized
 * `private-konsultasi.{id}` channel by Echo's `.whisper()`, relayed by Reverb to
 * the other channel members (the sender is excluded), and it never touches a
 * route, a database row or a queue. It is therefore lossy by design, which is why
 * the receiving side must expire it on its own clock instead of trusting the
 * sender's `at`.
 *
 * The throttle is what keeps a fast typist from turning keystrokes into a
 * message storm; the expiry is what keeps the indicator from hanging when the
 * last whisper arrives before a send, a disconnect or a tab switch.
 */

/** The whisper payload: who is typing, and when they said so. */
export type RealtimeMengetik = {
    user_id: number;
    at: string;
};

/** Minimum gap between two outgoing whispers, from the same composer. */
export const MENGETIK_THROTTLE_MS = 2_500;

/** How long a received whisper keeps the indicator on screen. */
export const MENGETIK_KEDALUWARSA_MS = 6_000;

/** Validate one inbound whisper payload; `null` for anything malformed. */
export function mengetikDariPayload(data: unknown): RealtimeMengetik | null {
    if (typeof data !== 'object' || data === null) {
        return null;
    }

    const { user_id: userId, at } = data as {
        user_id?: unknown;
        at?: unknown;
    };

    if (typeof userId !== 'number' || !Number.isFinite(userId)) {
        return null;
    }

    if (typeof at !== 'string' || at === '') {
        return null;
    }

    return { user_id: userId, at };
}

/** May a whisper go out now, given the previous send? */
export function bolehKirimMengetik(
    terakhir: number | null,
    sekarang: number,
    throttleMs: number = MENGETIK_THROTTLE_MS,
): boolean {
    if (terakhir === null || !Number.isFinite(terakhir)) {
        return true;
    }

    if (!Number.isFinite(sekarang)) {
        return false;
    }

    return sekarang - terakhir >= throttleMs;
}

/** Is a received whisper old enough that the indicator must disappear? */
export function mengetikKedaluwarsa(
    diterimaPada: number | null,
    sekarang: number,
    kedaluwarsaMs: number = MENGETIK_KEDALUWARSA_MS,
): boolean {
    if (diterimaPada === null || !Number.isFinite(diterimaPada)) {
        return true;
    }

    if (!Number.isFinite(sekarang)) {
        return true;
    }

    return sekarang - diterimaPada >= kedaluwarsaMs;
}
