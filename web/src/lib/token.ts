/**
 * The token pair store.
 *
 * ## Why the pair is in `sessionStorage` and not in `localStorage`
 *
 * The plan's todo 23 says the pair belongs "in memory only (never `localStorage` - an
 * XSS-readable refresh token is the same class of problem as the mobile one in Q4)".
 * `localStorage` is indeed refused: it is origin-wide and permanent, so it outlives the
 * tab and is readable by any script on the origin for as long as the browser profile
 * lives.
 *
 * This module is the one place that documents the deviation the implementation makes,
 * and the reason is the acceptance criterion that the same todo states: "a reload does
 * not lose the session". In-memory-only cannot satisfy that, and `sessionStorage` is the
 * narrowest storage that can:
 *
 * | storage | survives reload | survives tab close | readable by other tabs | refused |
 * | --- | --- | --- | --- | --- |
 * | module variable | no | no | no | preferred, fails the reload criterion |
 * | `sessionStorage` | yes | no | no | **used here** |
 * | `localStorage` | yes | yes | yes | forbidden by the plan |
 * | cookie (`HttpOnly`) | yes | yes | no | not offered by the API |
 *
 * A refresh token in `sessionStorage` is destroyed when the tab closes and is not
 * shared with any other tab, which is the part of `localStorage`'s exposure the plan
 * was objecting to. It is not a substitute for `HttpOnly`: any XSS that reaches this
 * origin can still read it. The real fix is a `HttpOnly` cookie, and
 * `config/cors.php` already sets `supports_credentials: true` for the SPA, so the API
 * is willing - but `AuthTokenResource` returns `refresh_token` in the response body and
 * no cookie, so a client cannot obtain the cookie half without an API change. Recorded
 * as a gap rather than worked around; see the final report.
 *
 * ## Why every read is optional-chained
 *
 * `globalThis.sessionStorage` is absent in a non-DOM context, and every accessor here
 * has to be safe to call from a module-level initialiser. The original single-token
 * version of this file already did this and the style is kept.
 */

const ACCESS_TOKEN_KEY = 'sehatly.access_token';
const REFRESH_TOKEN_KEY = 'sehatly.refresh_token';
const ACCESS_EXPIRES_AT_KEY = 'sehatly.access_expires_at';

export type TokenPair = {
    accessToken: string;
    refreshToken: string;
    /**
     * Absolute expiry of the access token, ISO-8601, or `null` when the server did not
     * say. Used to label the session in the UI; never used to *authorise* a decision,
     * because a client clock is not a trusted clock and a wrong answer here would mean
     * either a needless refresh or a request with a known-dead token.
     */
    accessTokenExpiresAt: string | null;
};

function readItem(key: string): string | null {
    return globalThis.sessionStorage?.getItem(key) ?? null;
}

function writeItem(key: string, value: string): void {
    globalThis.sessionStorage?.setItem(key, value);
}

function removeItem(key: string): void {
    globalThis.sessionStorage?.removeItem(key);
}

export function getAccessToken(): string | null {
    return readItem(ACCESS_TOKEN_KEY);
}

export function getRefreshToken(): string | null {
    return readItem(REFRESH_TOKEN_KEY);
}

export function getAccessTokenExpiresAt(): string | null {
    return readItem(ACCESS_EXPIRES_AT_KEY);
}

export function setTokens(pair: TokenPair): void {
    writeItem(ACCESS_TOKEN_KEY, pair.accessToken);
    writeItem(REFRESH_TOKEN_KEY, pair.refreshToken);

    if (pair.accessTokenExpiresAt === null) {
        removeItem(ACCESS_EXPIRES_AT_KEY);

        return;
    }

    writeItem(ACCESS_EXPIRES_AT_KEY, pair.accessTokenExpiresAt);
}

export function setAccessToken(token: string): void {
    writeItem(ACCESS_TOKEN_KEY, token);
}

export function clearTokens(): void {
    removeItem(ACCESS_TOKEN_KEY);
    removeItem(REFRESH_TOKEN_KEY);
    removeItem(ACCESS_EXPIRES_AT_KEY);
}

/**
 * Is there enough state here to attempt a refresh at all?
 *
 * The answer is deliberately "is there a refresh token", not "is there an access token":
 * the QA scenario this transport has to survive is the access token being *removed
 * mid-session* while the refresh token survives, which is exactly the case where a
 * refresh is the correct recovery. A guard keyed on the access token would treat it as a
 * signed-out session and send the user to the login screen instead.
 */
export function hasRefreshableSession(): boolean {
    return getRefreshToken() !== null;
}

const DEVICE_ID_KEY = 'sehatly.device_id';

/**
 * A stable per-browser installation identifier.
 *
 * `VerifyOtpRequest::device_id` is optional and its only effect is to label the issued
 * Sanctum token (`personal_access_tokens.name`) so an administrator reading a token list
 * can tell a web session from a phone one. It is not a credential: it grants nothing, is
 * not compared against anything on the server, and cannot select whose tokens to touch.
 *
 * Generated rather than hard-coded, because a value committed to the repository would be
 * a value every installation shares. `crypto.randomUUID` is present in every browser
 * this client targets and is behind a fallback only so a missing implementation
 * degrades to "no device id" (the field is optional) instead of throwing during a
 * render.
 */
export function getDeviceId(): string | null {
    const existing = readItem(DEVICE_ID_KEY);

    if (existing !== null) {
        return existing;
    }

    const generated = globalThis.crypto?.randomUUID?.() ?? null;

    if (generated === null) {
        return null;
    }

    writeItem(DEVICE_ID_KEY, generated);

    return generated;
}
