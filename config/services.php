<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment gateway webhook secrets
    |--------------------------------------------------------------------------
    |
    | Appended by todo 45. ONE KEY PER GATEWAY, and the four keys are four
    | different strings - which is the property the endpoint's security rests
    | on and the property a test asserts.
    |
    | ## Why the key is PER GATEWAY and not one application-wide secret
    |
    | `POST /api/v1/webhook/payment/{gateway}` is UNAUTHENTICATED: a payment
    | provider has no Sanctum token. The only thing standing between the
    | internet and "mark any invoice paid" is an HMAC keyed by one of these four
    | strings. With a single application-wide secret, compromising ANY one
    | gateway - the easiest of them, say - forges deliveries for all four, and
    | the `{gateway}` segment becomes decoration: an attacker picks the segment
    | whose secret they hold. Four keys make that impossible, and the test
    | `each_of_the_four_gateways_verifies_against_its_own_secret` proves both
    | halves - the four are pairwise different, and a body signed with `doku`'s
    | key is rejected on `midtrans`.
    |
    | ## The defaults below are DEVELOPMENT values and are labelled as such
    |
    | The plan puts real Midtrans and Xendit out of scope, so the only shipped
    | implementation is `MockPaymentGatewayService`. Shipping a mock behind an
    | unconfigured endpoint would be the open primitive the plan warns about, so
    | each key has a literal default - long enough to be unguessable, and named
    | `UBAH` so that grepping for a hard-coded webhook secret finds this comment.
    |
    | **A production deployment must override all four through the environment.**
    | There is no code path that refuses to start with a default in place,
    | because `APP_DEBUG` is a per-environment switch and a startup check that
    | only fires outside local would be untested in exactly the environment
    | where it matters. The values are in `.env`, not in the repository, and
    | `config/payment.php`'s docblock names the deployment step.
    |
    | ## The HMAC is over the RAW REQUEST BODY
    |
    | `hash_hmac('sha256', $request->getContent(), $secret)` - the bytes on the
    | wire, not a re-encoding of a decoded array. Re-encoding would make the
    | signature depend on this application's JSON serialiser: a key order or a
    | slash-escaping difference between two encoders would produce two different
    | signatures for one logical body, and the provider's signature would stop
    | matching. The test signs a body with one trailing space added and asserts
    | a 401, which is the failure this prevents.
    |
    | `hash_equals` compares them in constant time. A `==` on two hex digests
    | is a timing oracle, and this is the one comparison in the application an
    | attacker gets unlimited guesses at - the endpoint is unauthenticated, so
    | there is no rate limit on a session and no lockout to slow them down.
    |
    */
    'payment' => [
        'gateways' => [
            'midtrans' => [
                'webhook_secret' => env(
                    'PAYMENT_WEBHOOK_SECRET_MIDTRANS',
                    'UBAH-SEKRET-WEBHOOK-MIDTRANS-0000000000000001'
                ),
            ],
            'xendit' => [
                'webhook_secret' => env(
                    'PAYMENT_WEBHOOK_SECRET_XENDIT',
                    'UBAH-SEKRET-WEBHOOK-XENDIT-00000000000000002'
                ),
            ],
            'doku' => [
                'webhook_secret' => env(
                    'PAYMENT_WEBHOOK_SECRET_DOKU',
                    'UBAH-SEKRET-WEBHOOK-DOKU-0000000000000000003'
                ),
            ],
            'flip' => [
                'webhook_secret' => env(
                    'PAYMENT_WEBHOOK_SECRET_FLIP',
                    'UBAH-SEKRET-WEBHOOK-FLIP-00000000000000000004'
                ),
            ],
        ],
    ],

];
