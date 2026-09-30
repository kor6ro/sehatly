<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OTP delivery driver (F-005)
    |--------------------------------------------------------------------------
    |
    | `log` writes the code to the application log and is the only driver permitted
    | in `local`/`testing`; `PenjagaPengirimanProduksi` refuses to boot with it
    | anywhere else, because no account holder can receive a code from a log line.
    |
    | `fonnte` posts to the Fonnte WhatsApp gateway. Fonnte is an UNOFFICIAL
    | prototype channel - it drives WhatsApp Web on the operator's own number, has
    | no SLA, and can be blocked by WhatsApp. `docs/otp-push-prototype.md` states
    | that in full and names the production path. The token comes from
    | `FONNTE_TOKEN` and is never committed; the guard refuses a non-local `fonnte`
    | deployment with no token.
    */

    'driver' => env('OTP_DRIVER', 'log'),

    'fonnte' => [
        'token' => env('FONNTE_TOKEN'),
        'url' => env('FONNTE_URL', 'https://api.fonnte.com/send'),
        'timeout' => (int) env('FONNTE_TIMEOUT', 10),
    ],

];
