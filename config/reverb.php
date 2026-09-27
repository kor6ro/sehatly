<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reverb Server Configuration
|--------------------------------------------------------------------------
|
| This file configures the Reverb **server**, which is a separate process from
| the HTTP API: `php artisan reverb:start` runs it, and it is what the mobile
| and web clients open a WebSocket against. Nothing in `config/broadcasting.php`
| reads this file - that one configures the Laravel **client** side, the
| credentials this application signs broadcast requests with.
|
| The key names are fixed by `vendor/laravel/reverb/config/reverb.php`, which
| `Laravel\Reverb\ReverbServiceProvider` merges as the fallback for any key
| omitted here. Renaming one would not be caught by any type checker: the
| server reads the merged array with `config('reverb....')` and a misspelled
| key silently returns `null`, which for `apps.0.key` surfaces much later as an
| authentication failure on the first connection attempt. The structure below
| therefore mirrors the vendor stub key for key, and the sections it does not
| need are left to the merge.
|
| The credentials live in the environment, never in this file. `REVERB_APP_SECRET`
| is a shared secret: anything holding it can sign a request that Laravel's
| broadcast authorization accepts, so committing one would let a third party
| mint auth tokens for private channels. `.env.example` ships placeholders.
|
*/

return [

    /*
    | The default server. Reverb supports exactly one at a time today, so this is
    | not a pool selector - it is the name the rest of this file is keyed by.
    */
    'default' => env('REVERB_SERVER', 'reverb'),

    'servers' => [

        'reverb' => [
            /*
            | `0.0.0.0` is deliberate for a container or tunnel deployment, where
            | the server must be reachable from outside the host. On a workstation
            | it also means the server is reachable from the local network, so a
            | production deployment should set `REVERB_SERVER_HOST` explicitly to
            | the interface it should bind rather than inheriting this default.
            */
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),

            /*
            | The public hostname clients dial. Distinct from `host` above because
            | the socket is often terminated by a proxy in front of this server,
            | in which case the two are different hosts on purpose.
            */
            'hostname' => env('REVERB_HOST'),

            'options' => [
                'tls' => [],
            ],

            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),

            /*
            | Horizontal scaling. Disabled by default, and left disabled on
            | purpose: with it off, a single process is the whole cluster and
            | there is no ordering or delivery guarantee to reason about. Turning
            | it on is a deployment decision that needs a `REDIS_URL` and a
            | reverse proxy in front, neither of which this task adds.
            */
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],

            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | `provider => config` means the application list below **is** the list of
    | clients this server will accept credentials for. There is no database or
    | remote lookup: an app id that is not in this array cannot connect, which
    | is what makes "remove the key to revoke the client" a one-line change.
    |
    | `allowed_origins => ['*']` is the vendor default and is a real exposure,
    | not a placeholder. Reverb authenticates the *connection* with the app
    | key, but the browser sends the websocket handshake with an `Origin` the
    | server does not check, so any page on any origin can attempt a
    | connection. It is not a data leak on its own - a connection still has to
    | pass `/api/broadcasting/auth` before it joins a private channel - but it
    | does let an arbitrary site spend a client's connection budget. Narrowing
    | it is a deployment concern and is called out in the todo 31 evidence
    | rather than silently changed here, because the correct list depends on
    | the deployed SPA origin and is not knowable from the repository.
    |
    */
    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                'allowed_origins' => ['*'],
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),

                /*
                | `members` means the server accepts client events (whisper,
                | presence) only from channels whose members it has authenticated.
                | The looser `all` setting would let any connected client publish
                | into any channel, so `members` stays.
                */
                'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'members'),

                'rate_limiting' => [
                    'enabled' => env('REVERB_APP_RATE_LIMITING_ENABLED', false),
                    'max_attempts' => env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
                    'decay_seconds' => env('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60),
                    'terminate_on_limit' => env('REVERB_APP_RATE_LIMIT_TERMINATE', false),
                ],
            ],
        ],

    ],

];
