import type { OtpTujuan } from '@/lib/api/types';
import type { Identifier } from '@/lib/api/auth';

/**
 * The half-finished auth attempt, carried between `/login` (or the sign-in dialog) and
 * `/otp`.
 *
 * ## Why this is `sessionStorage` and not router state
 *
 * `POST /auth/verifyOtp` identifies the account by `no_telepon` **or** `email` and by
 * nothing else - there is no session, no cookie and no pending-login token to read it
 * back from. So the identifier has to survive the navigation, and it has to survive a
 * **reload** of `/otp` too: a user who reloads the OTP screen must still be able to
 * verify, and router `location.state` is lost on reload.
 *
 * The alternatives were both rejected:
 *
 * - **Query parameters** would put a phone number or an email address into browser
 *   history, into the `Referer` of anything the page links to, and into every server
 *   access log between here and the deployment target.
 * - **A module variable** is exactly `sessionStorage` minus persistence, so it fails the
 *   reload case for no benefit.
 *
 * ## What is deliberately absent
 *
 * No OTP code, obviously: the plaintext is only in the response at all in the `local`
 * environment, and a value this module could hold would be a value a reload could replay
 * past the expiry. {@link clearPendingOtp} runs the moment a verify succeeds, so a
 * verified attempt cannot be replayed from a stale tab.
 */

const PENDING_KEY = 'sehatly.pending_otp';

export type PendingOtp = {
    identifier: Identifier;
    tujuan: OtpTujuan;
    /** ISO-8601; drives the countdown on the OTP screen. */
    kedaluwarsa_at: string | null;
    ttl_detik: number;
};

export function setPendingOtp(pending: PendingOtp): void {
    globalThis.sessionStorage?.setItem(PENDING_KEY, JSON.stringify(pending));
}

export function getPendingOtp(): PendingOtp | null {
    const raw = globalThis.sessionStorage?.getItem(PENDING_KEY) ?? null;

    if (raw === null) {
        return null;
    }

    try {
        const parsed = JSON.parse(raw) as Partial<PendingOtp>;

        // Re-validated rather than cast: the value round-tripped through JSON, and a
        // half-written or hand-edited entry must not reach `VerifyOtpRequest` as an
        // identifier the server will answer 422 to.
        if (
            typeof parsed.tujuan !== 'string' ||
            (parsed.tujuan !== 'login' && parsed.tujuan !== 'verifikasi_telepon')
        ) {
            return null;
        }

        const identifier = parsed.identifier;

        if (
            typeof identifier !== 'object' ||
            identifier === null ||
            (typeof identifier.no_telepon !== 'string' &&
                typeof identifier.email !== 'string')
        ) {
            return null;
        }

        return {
            identifier: (
                typeof identifier.no_telepon === 'string'
                    ? { no_telepon: identifier.no_telepon }
                    : { email: identifier.email as string }
            ) as Identifier,
            tujuan: parsed.tujuan,
            kedaluwarsa_at:
                typeof parsed.kedaluwarsa_at === 'string' ? parsed.kedaluwarsa_at : null,
            ttl_detik: typeof parsed.ttl_detik === 'number' ? parsed.ttl_detik : 300,
        };
    } catch {
        return null;
    }
}

export function clearPendingOtp(): void {
    globalThis.sessionStorage?.removeItem(PENDING_KEY);
}

/**
 * A human description of which account the code was sent to, for the OTP screen.
 *
 * The phone number is **masked** here: this string is rendered on a screen that may be
 * shoulder-surfed, and a full number is more than the step needs. It is a presentation
 * mask, applied on the client, and it is unrelated to the server-side `NikMasker`.
 */
export function describeIdentifier(identifier: Identifier): string {
    const telepon = 'no_telepon' in identifier ? identifier.no_telepon : null;

    if (telepon !== null && telepon !== undefined) {
        if (telepon.length <= 6) {
            return telepon;
        }

        return `${telepon.slice(0, 4)}${'*'.repeat(
            Math.max(telepon.length - 6, 1),
        )}${telepon.slice(-2)}`;
    }

    const email = 'email' in identifier ? identifier.email : null;

    if (email === null || email === undefined) {
        return '-';
    }

    const at = email.indexOf('@');

    if (at <= 1) {
        return email;
    }

    return `${email.slice(0, 2)}${'*'.repeat(Math.max(at - 2, 1))}${email.slice(at)}`;
}

/**
 * `nomor 0812****88` / `email na**@contoh.id`, masked, never the full identifier.
 *
 * The prefix says which kind of identifier it is, because the mask alone (`0812****88`)
 * does not and a screen that says only "we sent it to 0812****88" leaves the reader to
 * infer it. Lives here next to {@link describeIdentifier} because it is the same mask
 * with a word in front of it - the OTP page and the sign-in dialog both need it.
 */
export function sebutanIdentifier(identifier: Identifier): string {
    const tersamar = describeIdentifier(identifier);

    return 'no_telepon' in identifier ? `nomor ${tersamar}` : `email ${tersamar}`;
}
