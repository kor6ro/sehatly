<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | `web/vite.config.ts` proxies `/api` to http://localhost:8000, so a browser
    | loading the SPA never issues a cross-origin API call and this config is
    | not on the web dev path. It is still mandatory for two real clients: the
    | Flutter app (todo 45) hits http://localhost:8000 directly from a different
    | origin, and any `fetch` from a page served on the API's own origin during
    | testing must not be rejected.
    |
    | The dev origins below were read out of `web/vite.config.ts`, not guessed.
    | That file declares `server.proxy` for `/api` but sets neither `server.port`
    | nor `server.origin`, so Vite serves the SPA on its default 5173. Because it
    | also leaves `strictPort` off, Vite walks forward to 5174 (and beyond) when
    | 5173 is already taken, so the adjacent port is allowed as well. Vite's
    | `server.host` default is `localhost`, which resolves to the `127.0.0.1`
    | origin in Chrome, so both spellings are listed.
    |
    */

    'paths' => ['api/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | Overridable via CORS_ALLOWED_ORIGINS so production can be configured without
    | a code change. A comma-separated list, because `explode` is the only shape
    | an env var can carry. An explicit origin list is deliberate: a wildcard would
    | let any site read authenticated responses out of the mobile client's browser
    | session, and cookie-based Sanctum auth is the reason this file exists.
    |
    */

    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', implode(',', [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:5174',
        'http://127.0.0.1:5174',
    ]))),

    'allowed_origins_patterns' => [],

    'allowed_methods' => ['*'],

    'allowed_headers' => ['*'],

    /*
    | `Retry-After` is in the CORS-safelisted response-header set, so a browser can
    | already read it. `X-RateLimit-Limit` and `X-RateLimit-Remaining` are NOT
    | safelisted, and `AppServiceProvider`'s throttle refusal puts them on every 429
    | (F-011): without this list a browser client sees the status and the body but
    | not the numbers, so it cannot render "try again in 42 s" or back off smoothly.
    */
    'exposed_headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After'],

    'max_age' => 0,

    'supports_credentials' => true,

];
