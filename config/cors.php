<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | `frontend/vite.config.ts` proxies `/api` to http://localhost:8000, so a browser
    | loading the SPA never issues a cross-origin API call and this config is
    | not on the web dev path. It is still mandatory for two real clients: the
    | Flutter app (todo 45) hits http://localhost:8000 directly from a different
    | origin, and any `fetch` from a page served on the API's own origin during
    | testing must not be rejected.
    |
    | The dev origins this file used to carry as defaults are no longer here (D5,
    | docs/update-plan-pilihan-a.md §1.1): they are supplied through
    | `CORS_ALLOWED_ORIGINS` instead. They were read out of
    | `frontend/vite.config.ts`, not guessed. That file declares `server.proxy` for
    | `/api` but sets neither `server.port` nor `server.origin`, so Vite serves the
    | SPA on its default 5173. Because it also leaves `strictPort` off, Vite walks
    | forward to 5174 (and beyond) when 5173 is already taken, so the adjacent port
    | is allowed as well. Vite's `server.host` default is `localhost`, which
    | resolves to the `127.0.0.1` origin in Chrome, so both spellings are listed.
    | `.env.example` documents the list for developers; `phpunit.xml` pins it for
    | the suite.
    |
    */

    'paths' => ['api/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | The default below is the six production hosts and nothing else: a checkout
    | that ships with `CORS_ALLOWED_ORIGINS` unset answers production only, so an
    | empty or missing env var can never fall back to a dev origin. Development
    | origins - the four Vite spellings (`http://localhost:5173`, `http://localhost:5174`
    | and the matching `127.0.0.1` pair) and the six `http://*.sehatly.test` Laragon
    | hosts - are supplied through `CORS_ALLOWED_ORIGINS` instead: documented for
    | developers in `.env.example`, and pinned for the suite in `phpunit.xml`,
    | where the CORS assertions in `ApiKernelTest` and `ConsultationChannelTest`
    | depend on them.
    |
    | Overridable via CORS_ALLOWED_ORIGINS so production can be configured without
    | a code change. A comma-separated list, because `explode` is the only shape
    | an env var can carry. An explicit origin list is deliberate: a wildcard would
    | let any site read authenticated responses out of the mobile client's browser
    | session, and cookie-based Sanctum auth (`supports_credentials`, below) is the
    | reason this file exists.
    |
    */

    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', implode(',', [
        // Production, and only production (D5). The base `sehatly.com` is included
        // because the patient portal IS an origin of its own once the portals
        // split - `localhost:8000` never appears, so a page served from the API's
        // own origin is still covered by the same-origin rule, not by CORS. The
        // dev origins live in `CORS_ALLOWED_ORIGINS`, not here.
        'https://sehatly.com',
        'https://admin.sehatly.com',
        'https://dokter.sehatly.com',
        'https://apotek.sehatly.com',
        'https://kurir.sehatly.com',
        'https://marketing.sehatly.com',
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
