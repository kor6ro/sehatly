import type { OtpTujuan } from '@/lib/api/types';
import type { Identifier } from '@/lib/api/auth';

/**
 * The OTP challenge: which account a code was sent to, and how long it stays valid.
 *
 * ## Why nothing in here is persisted any more
 *
 * This was `stores/pending-otp.ts`, and it wrote itself into `sessionStorage`, because
 * the exchange it belonged to was spread over two routes: the identifier had to survive
 * `/masuk` -> `/otp` AND a **reload** of the second, since router `location.state` is
 * lost on reload. Both routes are gone. The dialog on `/` holds the whole exchange -
 * `nomor` -> `kanal` -> `kode` - in one component, so there is no navigation to survive
 * and nothing to read back: the challenge is the dialog's own `pending` state.
 *
 * That removal is not a lost capability. The dialog never DID read this back; it wrote
 * the value and the page it used to hand over to consumed it. With no second page there
 * is no reader, and a write with no reader is the thing being removed.
 *
 * The two rejected storage options are recorded because they are the reasons the shape
 * has not been re-opened:
 *
 * - **Query parameters** would put a phone number into browser history, into the
 *   `Referer` of anything the page links to, and into every server access log between
 *   here and the deployment target.
 * - **A module variable** is `sessionStorage` minus persistence, so it fails a reload
 *   for no benefit - and there is no reload case left to fail.
 *
 * ## What is deliberately absent
 *
 * No OTP code, obviously: the plaintext is only in the response at all in the `local`
 * environment, and a value this module could hold would be a value a stale tab could
 * replay past the expiry.
 */

export type PendingOtp = {
    identifier: Identifier;
    tujuan: OtpTujuan;
    /** ISO-8601; drives the countdown on the dialog's code step. */
    kedaluwarsa_at: string | null;
    ttl_detik: number;
};

/**
 * A human description of which account the code was sent to.
 *
 * The phone number is **masked** here: this string is rendered on a surface that may be
 * shoulder-surfed, and a full number is more than the step needs. It is a presentation
 * mask, applied on the client, and it is unrelated to the server-side `NikMasker`.
 */
function describeIdentifier(identifier: Identifier): string {
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
 * with a word in front of it.
 *
 * The `email` half is kept even though the dialog collects phone numbers only, because
 * `Identifier` describes what `POST /auth/otp/verify` accepts and an account finished
 * through the sign-up form can still carry an address.
 */
export function sebutanIdentifier(identifier: Identifier): string {
    const tersamar = describeIdentifier(identifier);

    return 'no_telepon' in identifier ? `nomor ${tersamar}` : `email ${tersamar}`;
}
