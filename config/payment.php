<?php

use App\Services\Payment\MockPaymentGatewayService;

return [

    /*
    |--------------------------------------------------------------------------
    | Payment gateway
    |--------------------------------------------------------------------------
    |
    | Two keys, and they are NOT the same thing - which is the single most
    | confusing fact in this file, so it is stated rather than implied.
    |
    | `gateway` is the IMPLEMENTATION. It names an entry of `implementasi` below
    | and is what `AppServiceProvider` binds `App\Services\Payment\PaymentGatewayService`
    | to. Change it and the class behind the interface changes; nothing else in
    | the application knows which one is in use.
    |
    | `gateway_pembayaran` is the ENUM VALUE written into `pembayaran.gateway`
    | (`telemedicine_test.sql:965`, a four-value ENUM). It is what the
    | `pembayaran` row records and what `POST /api/v1/webhook/payment/{gateway}`
    | has to name for a delivery to match the row it settles.
    |
    | They are separate because a deployment can run the mock implementation
    | while still minting `midtrans` references - which is exactly what the test
    | suite does, and what makes "swap in real Midtrans" a one-line config
    | change rather than a data migration.
    |
    | ## `metode_tipe_qr` is the only method-to-channel mapping in the codebase,
    | and it is a CONVENTION
    |
    | The schema maps no payment method to a gateway and no payment method to a
    | channel: `master_metode_pembayaran` carries a free-text `penyedia`
    | (`:930`) with no foreign key to anything resembling a gateway table. So a
    | method's channel is decided HERE, from its `tipe` ENUM (`:929`), and the
    | decision is a deployment policy rather than a schema fact - which is why
    | it is config and not code, and why a test asserts the two channel
    | branches actually differ (a `qris` method gets a QR string and NO VA
    | number, because `pembayaran` has exactly one `va_number VARCHAR(30)`
    | column at `:964` and no `qr_string` column at all).
    |
    */

    'gateway' => env('PAYMENT_GATEWAY', 'mock'),

    'gateway_pembayaran' => env('PAYMENT_GATEWAY_PEMBAYARAN', 'midtrans'),

    'implementasi' => [
        'mock' => MockPaymentGatewayService::class,
    ],

    /*
    | The `master_metode_pembayaran.tipe` (:929) values that carry a QR code
    | rather than a virtual account.
    |
    | The nine members are `va_bank, e_wallet, qris, kartu_kredit, gerai_retail,
    | cod, tunai, bpjs, asuransi`. Of those, only `qris` and `gerai_retail` are
    | settled by scanning something, and `kartu_kredit` settles through a hosted
    | form whose "number to pay" is a card - so it is deliberately absent, and a
    | test asserts `va_bank` and `qris` land on opposite branches.
    */
    'metode_tipe_qr' => ['qris', 'gerai_retail'],

    /*
    | The `master_metode_pembayaran.tipe` (:929) values whose refunds the
    | gateway can execute through its own refund API, and it is the F12
    | cancellation policy's ONE branch point:
    |
    | - a `tipe` listed here gets an AUTOMATIC refund: `RefundService` calls
    |   `PaymentGatewayService::refund()` when a paid booking is cancelled, and
    |   the `refund` row lands in `berhasil` when the gateway answers success;
    | - every other `tipe` gets a MANUAL refund: the row is written as
    |   `diajukan` for an admin to process later (F14), because a bank transfer,
    |   a cash payment, an insurance claim or a BPJS settlement has no
    |   machine-executable reversal on our side.
    |
    | The three members are the nine-value ENUM's "money can be pushed back
    | electronically, immediately" set. `va_bank` is deliberately ABSENT even
    | though it is electronic: closing a virtual account does not move money to
    | a destination we hold, so the refund needs an admin with a beneficiary
    | instruction. `gerai_retail` is a cash-equivalent counter payment for the
    | same reason. This is a DEPLOYMENT POLICY, not a schema fact - the schema
    | maps no method to any refund capability, exactly as it maps no method to a
    | channel (`metode_tipe_qr` above) - which is why it lives here and not in a
    | conditional in the service. A test asserts the service reads this key
    | rather than hardcoding the list.
    */
    'metode_tipe_refund_otomatis' => ['e_wallet', 'qris', 'kartu_kredit'],

    /*
    | How many seconds a `pembayaran` row waits for a decision before the
    | patient is told to start again.
    |
    | `pembayaran` has no expiry column - the five statuses at `:966` are the
    | whole vocabulary, and a stale `pending` row is not one of them - so this
    | is a value the instructions carry rather than a fact the schema stores.
    | The webhook is what actually moves the row off `pending`; this number only
    | tells the client when to stop showing a virtual account that nobody is
    | going to pay.
    */
    'kedaluwarsa_detik' => (int) env('PAYMENT_KEDALUWARSA_DETIK', 86400),

];
