import ky, { type HTTPError, type Options } from 'ky';
import { dispatchFlash } from '@/lib/flash';
import {
    clearTokens,
    getAccessToken,
    getRefreshToken,
    hasRefreshableSession,
    setTokens,
} from '@/lib/token';

const origin = import.meta.env.VITE_API_ORIGIN ?? '';

/**
 * A pristine copy of every request that carries a body.
 *
 * A `Request` whose body has been handed to `fetch` cannot be cloned afterwards - the
 * stream is locked - so the copy has to be taken in `beforeRequest`, before the body is
 * read. The `afterResponse` hook then has something to replay a 401 with. Keyed weakly by
 * the request object, so a response that is never replayed costs nothing to collect.
 */
const pristineRequests = new WeakMap<Request, Request>();

/**
 * The single transport for the whole SPA. `VITE_API_ORIGIN` is empty in
 * production, where Laravel serves this bundle from `public/` and `/api/v1` is
 * same-origin; in development `vite.config.ts` proxies `/api` to
 * `SEHATLY_API_TARGET` (default `http://localhost:8000`). No endpoint is declared
 * here on purpose: the route table lives in `src/lib/api/*.ts`, so this file is the
 * transport and nothing else.
 *
 * Call it with a path *relative* to this base (`api('dokter')`), never with a
 * leading slash: ky resolves a leading-slash input against the origin and
 * would drop the `/api/v1` segment.
 *
 * ## ky 2.1.0's hook signature
 *
 * Every hook receives **one state object** - `{ request, options, retryCount }` for
 * `beforeRequest`, `{ request, options, response, retryCount }` for `afterResponse` - not
 * the positional `(request, options, response)` of ky 1.x. `afterResponse` also receives a
 * **clone** of the response, and whatever `Response` it returns replaces the one the
 * caller sees. Both are load-bearing for the 401 replay below.
 */
export const api = ky.create({
    baseUrl: `${origin}/api/v1/`,
    credentials: 'include',
    retry: { limit: 1, methods: ['get', 'head'] },
    hooks: {
        beforeRequest: [
            ({ request }) => {
                request.headers.set('Accept', 'application/json');
                request.headers.set('X-Requested-With', 'XMLHttpRequest');

                const token = getAccessToken();

                if (token !== null) {
                    request.headers.set('Authorization', `Bearer ${token}`);
                }

                if (request.body !== null) {
                    pristineRequests.set(request, request.clone());
                }
            },
        ],
        afterResponse: [
            async ({ request, response, retryCount }) => {
                if (response.status !== 401) {
                    return response;
                }

                // A 401 *from the refresh endpoint itself* is terminal by the server's
                // own contract: `AuthController::refresh` revokes the presented token on
                // every use, so a 401 there means it was already spent. Refreshing again
                // would present an already-spent token and be refused again.
                if (request.url.includes('/auth/refresh')) {
                    endSession('Sesi tidak dapat diperpanjang. Silakan masuk kembali.');

                    return response;
                }

                // Asserted, not assumed: a future `retry.statusCodes` that included 401
                // would otherwise turn the replay below into a loop.
                if (retryCount > 0) {
                    endSession('Sesi berakhir. Silakan masuk kembali.');

                    return response;
                }

                // No refresh token means there is nothing to refresh *with*. This is
                // reached by an anonymous caller hitting a guarded endpoint, and by a
                // caller whose pair was never established.
                if (!hasRefreshableSession()) {
                    endSession(null);

                    return response;
                }

                // A consumed body cannot be replayed, and rebuilding one from `options`
                // would mean re-implementing ky's input handling. The original 401 stands.
                if (request.bodyUsed && !pristineRequests.has(request)) {
                    endSession('Sesi berakhir. Silakan masuk kembali.');

                    return response;
                }

                const replayInput = pristineRequests.get(request) ?? request;

                /**
                 * A refresh for this session is **already running**. This is the
                 * concurrent-401 case, and it is why the latch exists: two requests 401ing
                 * in the same tick must share one `POST /auth/refresh`, because the server
                 * revokes the presented refresh token on every use, so a second call would
                 * present an already-spent token and destroy the session by its own
                 * recovery path.
                 *
                 * It must NOT be folded into the `refreshAttempts > 0` check below. A
                 * concurrent 401 is not a loop; treating it as one signs the user out
                 * during a recovery that would have succeeded.
                 */
                const inFlight = refreshInFlight;

                if (inFlight !== null) {
                    const joined = await inFlight;

                    if (!joined) {
                        return response;
                    }

                    return await replay(replayInput, response);
                }

                /**
                 * The loop bound. `refreshAttempts` is reset only by `establishSession`,
                 * which runs when a token pair is minted, so a signed-in session gets
                 * exactly one *completed* refresh in its whole life. A 401 arriving after
                 * one has already completed means the token the server just minted is
                 * itself rejected, and no number of further refreshes will change that, so
                 * the honest answer is to sign out rather than to spin.
                 */
                if (refreshAttempts > 0) {
                    endSession('Sesi berakhir. Silakan masuk kembali.');

                    return response;
                }

                refreshAttempts += 1;

                const refreshed = await refreshOnce();

                if (!refreshed) {
                    endSession('Sesi berakhir. Silakan masuk kembali.');

                    return response;
                }

                return await replay(replayInput, response);
            },
        ],
    },
});

/**
 * Re-send a 401'd request now that a fresh pair is in storage.
 *
 * `beforeRequest` runs again on the replay and attaches the new `Authorization`, and the
 * `afterResponse` hook runs again too - where `refreshAttempts > 0` now holds, so a second
 * rejection ends the session instead of refreshing a third time. That is the bound.
 */
async function replay(replayInput: Request, original: Response): Promise<Response> {
    try {
        return await api(replayInput);
    } catch {
        // A throw from an afterResponse hook is fatal to ky's retry handling, so the
        // replay's failure is reported as the original 401 instead.
        return original;
    }
}

// ============================================================================
// The envelope
// ============================================================================

/**
 * The project-wide pagination block, `App\Support\ApiResponse::pageMeta()`.
 *
 * `meta` is a **sibling** of `data`, not a wrapper around it, which is the whole reason
 * this type carries `meta?` next to `data` rather than a `Paginated<T>` with the rows
 * inside it. `from` and `to` are `null` on an empty page rather than `0`, because "no
 * rows" has no first row and no last row.
 */
export type ApiMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

/**
 * The unwrapped result of a successful call: the `data`, the `message`, and `meta` when
 * the endpoint paginates. The `success` discriminator is dropped because reaching this
 * type already means `success` was `true`.
 */
export type ApiResult<T> = {
    data: T;
    message: string;
    meta?: ApiMeta;
};

/**
 * The failure envelope, `App\Support\ApiResponse::error()`.
 *
 * `errors` is `(object) $errors` in PHP, so it encodes as `{}` when empty and as a
 * field-keyed map otherwise - never as a JSON array. {@link normaliseErrors} maps both
 * onto one type so a caller never has to check which one it got.
 */
export type ApiFailure = {
    success: false;
    message: string;
    errors: Record<string, string[]>;
};

/**
 * A non-2xx response, carrying the per-field detail the server sent.
 *
 * ## Why this is a class and not a parsed object
 *
 * Three different pieces of call-site code need to branch on the *status*, and a parsed
 * object gives them a field to branch on rather than a discriminant they can narrow
 * with. A `422` wants `errors` rendered per field; a `404` wants copy that says "not
 * found"; a `403` wants copy that says "not permitted", because the two are different
 * facts (see {@link isForbidden} and {@link isNotFound}). Making the status a real
 * discriminant is what lets each page say the right thing without re-deriving it.
 */
export class ApiError extends Error {
    readonly status: number;

    readonly errors: Record<string, string[]>;

    /**
     * The server's own `Retry-After`, in seconds, when it sent one.
     *
     * A 429 without a wait is a dead end: "too many attempts" tells the user nothing
     * about when trying again could work. Laravel's throttle middleware sends the header
     * on every limited endpoint, so the value is read once here rather than re-derived
     * from a message that may not contain it. `null` when absent or not a number of
     * seconds - the caller falls back to the server's prose.
     */
    readonly retryAfter: number | null;

    constructor(
        status: number,
        message: string,
        errors: Record<string, string[]> = {},
        retryAfter: number | null = null,
    ) {
        super(message);

        this.name = 'ApiError';

        this.status = status;

        this.errors = errors;

        this.retryAfter = retryAfter;
    }

    get isValidation(): boolean {
        return this.status === 422;
    }

    /**
     * A 422 reporting that the requested booking slot is gone.
     *
     * `SlotTakenException` has six factories and five of them key their message under
     * `slot`; the sixth, `nomorHabis()`, keys under `nomor_booking` instead and is
     * deliberately **not** matched here, because "the slot filled up" and "we could not
     * mint a document number" need different advice to the patient.
     *
     * The semantics are deliberately identical to `ApiException.isSlotTaken` in
     * `packages/sehatly_api_client`, which is the pure-Dart client the mobile team
     * imports: status 422 **and** a top-level `slot` key in `errors`, never a message
     * match. Two clients classifying the same failure differently is the defect this
     * prevents.
     */
    get isSlotTaken(): boolean {
        return this.isValidation && Object.hasOwn(this.errors, 'slot');
    }

    get isUnauthorized(): boolean {
        return this.status === 401;
    }

    /**
     * A caller that may not act on *its own* record at all.
     *
     * `PasienRecordAccess::ownPasien()` raises this when the authenticated account owns
     * no `pasien` row. It is about the caller and discloses nothing about anybody else's
     * data, which is exactly why it is distinct from a 404 and why the UI copy for it
     * must not say "not found".
     */
    get isForbidden(): boolean {
        return this.status === 403;
    }

    /**
     * A row that does not exist, or one that is not the caller's.
     *
     * `PasienController` deliberately answers 404 rather than 403 for another patient's
     * row, and `DokterController` answers 404 for a doctor who does not exist, is
     * unverified, is inactive, has opted out of telemedicine, or whose STR has expired.
     * Those cases share one status on purpose, so the copy must not narrow them.
     */
    get isNotFound(): boolean {
        return this.status === 404;
    }

    /**
     * The messages the server attached to one field, in order.
     *
     * `AuthRequest::requireAtLeastOneIdentifier()` puts the *same* message on two keys,
     * and `UpdatePasienProfileRequest` can add a second message to a key that already
     * failed a rule, so this returns a list and the caller renders all of it.
     */
    fieldErrors(field: string): string[] {
        return this.errors[field] ?? [];
    }

    /**
     * Every field that failed, for a summary line above a form.
     */
    get failedFields(): string[] {
        return Object.keys(this.errors);
    }
}

// ============================================================================
// The request wrapper
// ============================================================================

/**
 * Perform one API call and unwrap the envelope.
 *
 * This is the only place a response body is read and the only place an `HTTPError` is
 * turned into an `ApiError`. A component therefore never sees an `HTTPError`, never
 * calls `response.json()`, and never has to know that `meta` is a sibling of `data`.
 */
export async function request<T>(path: string, options: Options = {}): Promise<ApiResult<T>> {
    try {
        const response = await api(path, { ...options, throwHttpErrors: true });

        return unwrap<T>(await readJson(response));
    } catch (error) {
        throw await toApiError(error);
    }
}

/**
 * Read a JSON body defensively.
 *
 * A 500 rendered by a proxy, or a 404 from a web server in front of Laravel, is not JSON
 * at all. `response.json()` would throw a `SyntaxError` that says nothing useful, so a
 * non-JSON body falls through to the status-keyed fallback message and the real status
 * still reaches the UI.
 */
async function readJson(response: Response): Promise<unknown> {
    const text = await response.text();

    if (text === '') {
        return null;
    }

    try {
        return JSON.parse(text) as unknown;
    } catch {
        return null;
    }
}

function unwrap<T>(body: unknown): ApiResult<T> {
    const envelope = body as Partial<{
        data: T;
        message: string;
        meta: ApiMeta;
    }>;

    return {
        data: envelope.data as T,
        message: typeof envelope.message === 'string' ? envelope.message : '',
        ...(envelope.meta === undefined ? {} : { meta: envelope.meta }),
    };
}

/**
 * Convert whatever `request()` caught into an `ApiError`.
 *
 * ky 2.x pre-parses a JSON error body onto `HTTPError.data` and consumes the response
 * stream doing it, so `error.response.json()` throws. `data` is the only place the
 * failure envelope can be read from, and is `undefined` for a non-JSON body.
 */
async function toApiError(error: unknown): Promise<ApiError> {
    if (error instanceof ApiError) {
        return error;
    }

    if (error instanceof Error && 'response' in error) {
        const { response, data } = error as HTTPError;
        const failure = data as Partial<ApiFailure> | undefined;
        const retryAfter = retryAfterOf(response);

        if (failure !== undefined && failure !== null && failure.success === false) {
            const message =
                typeof failure.message === 'string' && failure.message !== ''
                    ? failure.message
                    : statusMessage(response.status);

            return new ApiError(
                response.status,
                message,
                normaliseErrors(failure.errors),
                retryAfter,
            );
        }

        return new ApiError(
            response.status,
            statusMessage(response.status),
            {},
            retryAfter,
        );
    }

    return new ApiError(0, networkMessage(error));
}

function retryAfterOf(response: Response): number | null {
    const raw = response.headers.get('retry-after');

    if (raw === null) {
        return null;
    }

    const seconds = Number.parseInt(raw.trim(), 10);

    return Number.isFinite(seconds) && seconds >= 0 ? seconds : null;
}

function normaliseErrors(errors: unknown): Record<string, string[]> {
    if (errors === null || typeof errors !== 'object' || Array.isArray(errors)) {
        return {};
    }

    const normalised: Record<string, string[]> = {};

    for (const [field, value] of Object.entries(errors as Record<string, unknown>)) {
        normalised[field] = Array.isArray(value)
            ? value.map((item) => String(item))
            : [String(value)];
    }

    return normalised;
}

function networkMessage(error: unknown): string {
    return `Tidak dapat menghubungi server. ${
        error instanceof Error ? error.message : String(error)
    }`;
}

/**
 * The copy for a status the server did not attach a message to.
 *
 * Every endpoint in `routes/api.php` answers the failure envelope, so this is the
 * fallback for the paths that bypass the controller: a 404 from the router itself
 * (`->whereNumber('id')` on a non-numeric segment), a 405, a 419, and the 500 that
 * `bootstrap/app.php` renders. A blank error box is the one outcome that is always wrong,
 * so each of these has to say something.
 */
function statusMessage(status: number): string {
    switch (status) {
        case 400:
            return 'Permintaan tidak valid.';
        case 401:
            return 'Sesi berakhir. Silakan masuk kembali.';
        case 403:
            return 'Akun ini tidak berhak mengakses data tersebut.';
        case 404:
            return 'Data tidak ditemukan.';
        case 405:
            return 'Metode tidak diizinkan untuk endpoint ini.';
        case 419:
            return 'Sesi halaman kedaluwarsa. Muat ulang halaman.';
        case 422:
            return 'Data yang dikirim tidak valid.';
        case 429:
            return 'Terlalu banyak permintaan. Coba lagi nanti.';
        default:
            return status >= 500
                ? 'Terjadi kesalahan pada server. Coba lagi nanti.'
                : `Permintaan gagal dengan status ${status}.`;
    }
}

// ============================================================================
// Refresh: one attempt, single flight, and a hard loop bound
// ============================================================================

/**
 * How many refreshes this signed-in session has already attempted.
 *
 * Reset by {@link establishSession} and by nothing else that mints a token. See the
 * `afterResponse` hook for why the bound is one per session rather than a loop detector.
 */
let refreshAttempts = 0;

/**
 * The refresh in progress, or `null`.
 *
 * This is the single-flight latch, and it is load-bearing rather than an optimisation:
 * the server *revokes the presented refresh token on every use*, so two requests 401ing
 * in the same tick would race, the second would present an already-spent token, and the
 * session would be destroyed by its own recovery path. One shared promise is the whole
 * mechanism.
 */
let refreshInFlight: Promise<boolean> | null = null;

function refreshOnce(): Promise<boolean> {
    refreshInFlight ??= performRefresh().finally(() => {
        refreshInFlight = null;
    });

    return refreshInFlight;
}

async function performRefresh(): Promise<boolean> {
    const refreshToken = getRefreshToken();

    if (refreshToken === null) {
        return false;
    }

    try {
        const result = await request<{ token: IssuedToken }>('auth/refresh', {
            method: 'POST',
            json: { refresh_token: refreshToken },
            // The refresh call must never itself trigger a refresh.
            retry: 0,
        });

        setTokens({
            accessToken: result.data.token.access_token,
            refreshToken: result.data.token.refresh_token,
            accessTokenExpiresAt: result.data.token.access_token_expires_at,
        });

        return true;
    } catch {
        return false;
    }
}

/**
 * The token pair as `App\Http\Resources\AuthTokenResource` publishes it.
 *
 * `token_type` and `expires_in` are read off the wire and deliberately unused: the
 * access token's absolute expiry is what a client schedules against, and
 * `AuthTokenResource` publishes both for exactly that reason.
 */
export type IssuedToken = {
    token_type: string;
    access_token: string;
    expires_in: number;
    access_token_expires_at: string;
    refresh_token: string;
    refresh_token_expires_at: string;
};

/**
 * Record a freshly issued pair and re-arm the single refresh attempt.
 *
 * Called by the OTP-verify step, which is the only endpoint in `AuthController` that
 * mints a token. A refresh deliberately does **not** call this: that is what keeps
 * `refreshAttempts` monotonic within one signed-in session.
 */
export function establishSession(token: IssuedToken): void {
    refreshAttempts = 0;

    setTokens({
        accessToken: token.access_token,
        refreshToken: token.refresh_token,
        accessTokenExpiresAt: token.access_token_expires_at,
    });
}

/**
 * Sign out locally: forget the pair and tell the application shell.
 *
 * The Laravel side is **not** called here. `POST /api/v1/auth/logout` needs a refresh
 * token in the body *and* a live access token on the request, and by definition neither
 * is available on this path - the access token is what just failed. The explicit sign-out
 * button calls the endpoint; this is the recovery path, and it clears client state only.
 *
 * A `notice` of `null` means "nobody was signed in to begin with", which is a silent
 * clean-up rather than an event worth a toast.
 */
function endSession(notice: string | null): void {
    clearTokens();

    refreshAttempts = 0;

    if (notice !== null) {
        dispatchFlash({ level: 'warning', message: notice });
    }

    for (const listener of sessionExpiredListeners) {
        listener();
    }
}

const sessionExpiredListeners = new Set<() => void>();

/**
 * Register the "the session just died" callback.
 *
 * An indirection rather than a direct `router.navigate()` because `@/app/router` imports
 * the pages, the pages import this module, and a direct import would close that cycle.
 * `app.tsx` owns the subscription.
 */
export function onSessionExpired(listener: () => void): () => void {
    sessionExpiredListeners.add(listener);

    return () => {
        sessionExpiredListeners.delete(listener);
    };
}
