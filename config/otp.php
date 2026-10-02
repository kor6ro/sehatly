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
    | prototype channel - it drives WhatsApp Web (a session-based gateway, not the
    | WhatsApp Business Cloud API and not a BSP), has no SLA, and can be blocked by
    | WhatsApp. `docs/otp-push-prototype.md` states that in full and names the
    | production path. The token comes from `FONNTE_TOKEN` and is never committed;
    | the guard refuses a non-local `fonnte` deployment with no token.
    |
    | ## `channel` is a declaration, not a second driver switch
    |
    | `channel` names the delivery channel this deployment BELIEVES it is using
    | (`whatsapp`, `sms`, `email` or the `log` stand-in), and `fallback_channel`
    | names the one to move to if the preferred channel is unavailable. WhatsApp is
    | an option, never the only one: a deployment that sets `OTP_CHANNEL=sms` and
    | points `OTP_DRIVER` at an SMS `OtpSender` needs no change here, and one that
    | declares `sms` while actually delivering over `fonnte` is refused at boot by
    | `PenjagaPengirimanProduksi` rather than silently sending WhatsApp messages a
    | patient was told would arrive by SMS.
    |
    | The API reports the channel a code was ACTUALLY delivered over
    | (`POST /auth/otp/resend`'s `otp.kanal`), derived from `driver` through
    | `PemilihPengirimOtp::kanal()`, so the response cannot claim a channel the
    | sender does not use.
    */

    'driver' => env('OTP_DRIVER', 'log'),

    'channel' => env('OTP_CHANNEL', 'whatsapp'),

    'fallback_channel' => env('OTP_FALLBACK_CHANNEL', 'sms'),

    'fonnte' => [
        'token' => env('FONNTE_TOKEN'),
        'url' => env('FONNTE_URL', 'https://api.fonnte.com/send'),
        'timeout' => (int) env('FONNTE_TIMEOUT', 10),
    ],

];
