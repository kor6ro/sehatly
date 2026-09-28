import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { getAccessToken } from '@/lib/token';

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

/**
 * Where a private channel is authorised.
 *
 * ## The path is derived from `bootstrap/app.php`, not from the Pusher docs
 *
 * `withBroadcasting(channels: ..., attributes: ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']])`
 * expands to `Broadcast::routes($attributes)`, and the framework's own
 * `BroadcastManager::routes()` registers `GET|POST /broadcasting/auth` **under the
 * given prefix** and with the given middleware. So the registered path is
 * `/api/broadcasting/auth` and not `/api/v1/broadcasting/auth` and not the
 * web-group `/broadcasting/auth`.
 *
 * Three consequences, each of them a distinct failure mode:
 *
 * | path | what happens | looks like |
 * | --- | --- | --- |
 * | `/broadcasting/auth` (Echo's default) | hits the `web` group, which has no session to authenticate | a subscription that never confirms, with no error anywhere |
 * | `/api/v1/broadcasting/auth` | no route is registered there | a 404 in the network log, and the same silent subscribe failure |
 * | `/api/broadcasting/auth` | 200 with `{auth: "key:signature"}` | working |
 *
 * ## CSRF or bearer? **Bearer, and there is no CSRF token to send**
 *
 * The route's middleware is `['api', 'auth:sanctum']`. The `api` group is the
 * stateless one `withRouting(apiPrefix: 'api/v1')` builds - it has no
 * `VerifyCsrfToken` and therefore no `X-CSRF-TOKEN` requirement - and
 * `auth:sanctum` is satisfied by `Authorization: Bearer <token>` alone.
 * `PusherBroadcaster::auth()` reads exactly two inputs, `$request->channel_name`
 * and `$request->socket_id`, and returns `json_decode($response, true)`, i.e.
 * `{auth: "..."}`. `pusher-js` sends the first as a form field and puts
 * `auth.headers` on the request.
 *
 * A CSRF token would be a no-op that is actively harmful to send: Echo reads one
 * from `<meta name="csrf-token">` when it is present, and this SPA has no Blade
 * layout to put one in.
 */
export const BROADCAST_AUTH_ENDPOINT = '/api/broadcasting/auth';

/**
 * `KonsultasiMessageSent::broadcastAs()`.
 *
 * Bound with a LEADING DOT, because the Pusher protocol delivers an event name
 * prefixed with one and `EventFormatter.format()` strips it before binding
 * `pusher:<name>`. `chat.pesan` without the dot binds `pusher:App.Events.chat.pesan`
 * (Echo's default namespace) and the transcript never updates.
 */
export const CHAT_PESAN_EVENT = '.chat.pesan';

function requireEnv(name: keyof ImportMetaEnv): string {
    const value = import.meta.env[name];

    if (typeof value !== 'string' || value === '') {
        throw new Error(
            `Reverb is not configured: ${name} is missing from the web build.`,
        );
    }

    return value;
}

let connection: Echo<'reverb'> | null = null;

/**
 * The one Echo instance, or the existing one with a refreshed token.
 *
 * ## Why the token is written into `options.auth.headers` and not into
 * `options.bearerToken`
 *
 * `bearerToken` is the documented v2 option and it is read in exactly one place -
 * `Connector.setOptions()` (`laravel-echo/src/connector/connector.ts:80`), which runs
 * from the `Connector` **constructor** and never again. Assigning
 * `connection.options.bearerToken = x` therefore does nothing to the headers, and a
 * client that did only that would authorise its first channel correctly and then
 * fail every channel after the access token rotates.
 *
 * The headers have to be mutated on the object pusher-js actually reads. It does
 * read it late: `buildChannelAuth()` copies the reference - `channelAuthorization.headers = opts.auth.headers`,
 * no clone - and the ajax authenticator iterates `authOptions.headers` inside the
 * per-request closure (`pusher-js/dist/web/pusher.js`, the `xhr.setRequestHeader`
 * loop). Mutating that object is therefore per-authorisation, which is the property
 * this needs.
 *
 * `packages/sehatly_api_client`'s `RealtimeClient` reaches the same property from
 * the other side, by resolving its `authHeaders` on every `_dispatchSubscribe`
 * rather than once at construction.
 */
export function connectEcho(): Echo<'reverb'> {
    if (connection === null) {
        globalThis.window.Pusher = Pusher;

        connection = new Echo({
            broadcaster: 'reverb',
            key: requireEnv('VITE_REVERB_APP_KEY'),
            wsHost: requireEnv('VITE_REVERB_HOST'),
            wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? '80'),
            wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? '443'),
            forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
            enabledTransports: ['ws', 'wss'],
            authEndpoint: BROADCAST_AUTH_ENDPOINT,
        });
    }

    applyAccessToken();

    return connection;
}

/**
 * Re-read the access token into the live connector's auth headers.
 *
 * Called by `connectEcho()` and again immediately before every subscribe, because
 * a subscribe is the moment the credential is spent and the token rotates.
 *
 * With no token the header is **deleted** rather than set to an empty `Bearer`:
 * an empty credential is guaranteed to be refused and it turns "you are signed out"
 * into an opaque 403 that reads like a channel-authorisation bug. The Dart client
 * makes the same choice in `RealtimeClient._storeBackedHeaders()`.
 */
export function applyAccessToken(): void {
    if (connection === null) {
        return;
    }

    const headers = connection.connector.options.auth.headers;
    const token = getAccessToken();

    if (token === null || token === '') {
        delete headers.Authorization;

        return;
    }

    headers.Authorization = `Bearer ${token}`;
}

export function disconnectEcho(): void {
    connection?.disconnect();
    connection = null;
}
