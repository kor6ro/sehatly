<?php

use App\Providers\AppServiceProvider;

/*
|--------------------------------------------------------------------------
| Application Service Providers
|--------------------------------------------------------------------------
|
| `App\Providers\FortifyServiceProvider` was removed here in todo 30 together
| with the `laravel/fortify` package it configured. Every feature it wired -
| session login, session logout, password reset by emailed link, email
| verification, two-factor authentication, passkeys - is a browser-session
| feature, and none of it is part of this contract:
|
| - `users` (`telemedicine_test.sql:132-149`) has no `password` column, no
|   `email_verified_at` and no `two_factor_*` column, so `Laravel\Fortify`'s
|   credential checks and its two-factor state had nothing to read or write.
| - The password broker it configured points at `password_reset_tokens`
|   (`config/auth.php:98`), which is not one of the 75 contract tables and not
|   one of the 7 registered extra tables either, so the table does not exist.
| - `/api/v1` authenticates with a Sanctum bearer token obtained from
|   `POST /api/v1/auth/otp/verify`, so there is no session to confirm and no
|   `auth` web route to redirect to.
|
| `config/auth.php` is deliberately left in place. It is stock Laravel
| configuration, it declares the `users` Eloquent provider that Sanctum and the
| `auth:sanctum` guard both resolve through, and removing it would replace the
| session guard with framework defaults rather than with anything better.
|
*/

return [
    AppServiceProvider::class,
];
