<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | `BROADCAST_CONNECTION` is `reverb` in `.env` and `.env.example`, and stays
    | `null` in `phpunit.xml:24` so the suite never needs a running broker.
    |
    | The `null` default here is the second half of the same arrangement: a
    | fresh checkout that never ran `reverb:install` still boots, and any
    | broadcast it does attempt is written to the log rather than opened over a
    | socket that is not there.
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Only three connections are declared, and the two non-Reverb ones are there
    | to be switched to rather than to be used.
    |
    | - `reverb` is the production driver. It is a first-class connection: see
    |   the note below on why it has no `cluster` key.
    | - `log` writes payloads to the log channel. It is what a developer sets
    |   when they want to see what would have gone out over the wire without
    |   standing up a server.
    | - `null` discards them. It is the `phpunit.xml` value.
    |
    | ## There is deliberately no `pusher` connection
    |
    | Reverb speaks the Pusher protocol, so `BroadcastManager::createReverbDriver()`
    | is a one-line delegation to `createPusherDriver()`
    | (`vendor/laravel/framework/src/Illuminate/Broadcasting/BroadcastManager.php`),
    | which is why the `reverb` block below is spelled in Pusher terms. That is
    | an implementation detail of the driver factory, not a second supported
    | destination: declaring a `pusher` connection would advertise a hosted
    | service this application has no credentials for and no plan to use.
    |
    | ## There is deliberately no `cluster` key
    |
    | `pusher-js` and `laravel-echo` make `cluster` optional for the Reverb
    | broadcaster, and Pusher's own clusters are a Pusher-side routing feature
    | that has no Reverb equivalent. Sending one would be rejected rather than
    | ignored, so it is absent rather than defaulted to an empty string.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                'path' => env('REVERB_SERVER_PATH', ''),
            ],
            'client_options' => [
                // Guzzle request options, forwarded to the Pusher HTTP client:
                // https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
