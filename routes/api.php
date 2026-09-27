<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Mounted under the `api/v1` prefix declared in `bootstrap/app.php` via
| `apiPrefix`, so every URI here is `/api/v1/...` and **no prefix is added in
| this file**. The stateless `api` middleware group comes from the same call.
|
| Every route returns through `App\Support\ApiResponse` (or the
| `Response::apiSuccess()` / `Response::apiError()` macros registered in
| `bootstrap/app.php`), so every response carries the same
| `{success,data,message}` / `{success,message,errors}` envelope, and every
| failure is rendered by the `withExceptions` callback there rather than by a
| controller. That is what makes a client able to parse one body shape
| regardless of which layer failed.
|
| Module 1 -- authentication. Eight routes, and the plan's todo 20 text names
| seven operations (register, login, otp/verify, refresh, logout, device
| registration, device revocation); the eighth is `GET /auth/devices`, which the
| device-management surface is useless without.
|
| ## The rate limiter names are prefixed, and that is load-bearing
|
| `FortifyServiceProvider` already registers a named limiter called `login`,
| which reads `$request->session()` -- a call that does not exist on the
| stateless `api` group. Registering `login` again here would either overwrite
| Fortify's (silently removing the web form's throttling) or be overwritten by it
| (leaving this endpoint unthrottled), depending on provider order. `auth-*`
| can collide with nothing in the framework, and todo 52 widens this vocabulary
| for the rest of the sensitive surface.
|
| The three limits are the plan's: 10/min on the OTP-sending endpoint, 5/min on
| login, 5/min on OTP verify. `user_otp` has **no attempt-counter column**, so
| the verify limit is the only thing bounding a six-digit brute force, and the
| key includes the caller's identifier so one attacker cannot lock every account
| out by spending a shared budget.
|
| ## Why no `permission:` or `tipe:` appears here
|
| `RbacCatalog::PERMISSIONS` holds 24 codes and none of them names an auth,
| session, token or device action, so there is no code that could be written
| here without inventing policy -- and todo 4's brief forbids exactly that.
| `tipe:` is worse than absent: `perawat` and `kurir` are real `users.tipe` ENUM
| values that hold no role and therefore no grant, so a `tipe:` gate on logout
| or on device registration would lock those two account types out of their own
| account. The full reasoning is in `AuthController`'s class docblock.
|
| `AuthFlowTest` asserts that every `permission:` and `tipe:` string appearing in
| this file resolves against `RbacCatalog`, so a later todo that does need a code
| cannot add an unknown one and get a 500 instead of a 403.
|
*/

Route::prefix('auth')->name('auth.')->group(function (): void {
    /*
    | Anonymous. Nothing here returns a token except `otp/verify`, and
    | `otp/verify` requires a code that only these two endpoints mint.
    */
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:auth-otp-send')
        ->name('register');

    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login')
        ->name('login');

    Route::post('otp/verify', [AuthController::class, 'verifyOtp'])
        ->middleware('throttle:auth-otp-verify')
        ->name('otp.verify');

    Route::post('refresh', [AuthController::class, 'refresh'])
        ->name('refresh');

    /*
    | Authenticated. `auth:sanctum` is named explicitly on the group rather than
    | applied globally, so "unauthenticated" is answered by the guard with a 401
    | envelope instead of by these two middlewares -- and so a route added to this
    | file in a later todo cannot be unprotected by omission.
    */
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('devices', [AuthController::class, 'devicesIndex'])->name('devices.index');
        Route::post('devices', [AuthController::class, 'devicesStore'])->name('devices.store');

        // `deviceId` is `user_devices.device_id`, a VARCHAR(255) client-supplied
        // installation identifier, not a surrogate key -- so the parameter is a
        // string and the controller answers 404 for one that is not the caller's.
        Route::delete('devices/{deviceId}', [AuthController::class, 'devicesDestroy'])
            ->name('devices.destroy');
    });
});
