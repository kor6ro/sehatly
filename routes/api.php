<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PasienController;
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

/*
|--------------------------------------------------------------------------
| Module 1 -- the caller's own patient records
|--------------------------------------------------------------------------
|
| APPENDED by todo 21. The eight auth routes above are untouched: this todo owns only
| the block below, and the plan's todo 20 acceptance criterion is a byte-level
| assertion about the eight it registered.
|
| Eleven routes, one ownership rule. Every one of them runs behind `auth:sanctum` and
| then resolves the caller's own `pasien` row through
| `App\Services\Pasien\PasienRecordAccess`, which is where the 403-for-the-caller and
| 404-for-another-patient's-row split is defined and explained.
|
| ## No `permission:` and no `tipe:` on any of them, and that is a decision
|
| `RbacCatalog::PERMISSIONS` holds 24 codes and none of them names a patient profile, a
| family member or an allergy, so a `permission:` here would be inventing policy - and
| `EnsurePermission` turns an unknown code into a 500, not a 403. `tipe:pasien` would
| resolve and is deliberately not used: it answers "which account type is this" rather
| than "is this row yours", and a `pasien`-typed account with no `pasien` row passes it and
| is refused by the service anyway. The full reasoning is in `PasienRecordAccess`'s
| class docblock, and the tripwire that keeps it honest is in
| `tests/Feature/Pasien/PasienProfileTest.php`, which re-parses this file and asserts
| every `permission:`/`tipe:` string resolves against `RbacCatalog`.
|
| ## `auth:sanctum` is named on the group, for the same reason it is on the auth group
|
| An unauthenticated caller gets the guard's 401 envelope rather than this controller's
| 403, and a route added to this file in a later todo cannot be unprotected by omission.
|
| ## `{id}` is constrained to a number
|
| `pasien_anggota_keluarga.id` and `pasien_alergi.id` are `BIGINT UNSIGNED AUTO_INCREMENT`
| primary keys (`:259`, `:274`). `whereNumber('id')` makes a non-numeric segment a 404
| from the router, so the controllers take an `int` and no request can arrive with
| `abc` in a position the API treats as an identifier.
|
| ## The plan's "8 routes" is unsatisfiable; 10 are registered here
|
| The plan's todo 21 acceptance criterion says
| `php artisan route:list --path=api/v1/pasien` lists 8 routes. Its own prose names 10:
| `GET`/`PUT /pasien/profil` (2), `GET`/`POST /pasien/anggota-keluarga` (2),
| `PUT`/`DELETE /pasien/anggota-keluarga/{id}` (2), `GET`/`POST /pasien/alergi` (2) and
| `PUT`/`DELETE /pasien/alergi/{id}` (2). All ten ship; the count is not met by dropping
| endpoints. `GET /api/v1/me` is the eleventh and sits outside the `pasien` path filter.
| This is the same class of defect as todo 20's "lists all 6 routes" for seven named
| operations, and it is recorded in `.omo/evidence/task-21-sehatly.md` rather than
| satisfied by omission.
*/

Route::middleware('auth:sanctum')->group(function (): void {
    /*
    | The account's own identity, with whichever of `pasien` or `dokter` it owns.
    */
    Route::get('me', [MeController::class, 'show'])->name('me');

    /*
    | The patient profile. `PUT` writes `users.nama_lengkap` and a block of `pasien`
    | columns in one transaction, and cannot write `tipe`, `status`, `no_telepon` or
    | `nik` - those are not in `UpdatePasienProfileRequest`'s validated keys.
    */
    Route::prefix('pasien')->name('pasien.')->group(function (): void {
        Route::get('profil', [PasienController::class, 'profilShow'])
            ->name('profil.show');
        Route::put('profil', [PasienController::class, 'profilUpdate'])
            ->name('profil.update');

        /*
        | Family members. `pasien_anggota_keluarga` has no `dihapus_at` (`:269`), so the
        | DELETE below is a hard delete - the schema allows nothing else.
        */
        Route::prefix('anggota-keluarga')->name('anggota-keluarga.')->group(function (): void {
            Route::get('/', [PasienController::class, 'anggotaKeluargaIndex'])
                ->name('index');
            Route::post('/', [PasienController::class, 'anggotaKeluargaStore'])
                ->name('store');
            Route::put('{id}', [PasienController::class, 'anggotaKeluargaUpdate'])
                ->whereNumber('id')
                ->name('update');
            Route::delete('{id}', [PasienController::class, 'anggotaKeluargaDestroy'])
                ->whereNumber('id')
                ->name('destroy');
        });

        /*
        | Allergies. `pasien_alergi` also has no `dihapus_at` (`:282`), so this DELETE is
        | a hard delete too, and `dicatat_oleh_user_id` (`:281`, no foreign key) is
        | written from the authenticated account rather than from the request.
        */
        Route::prefix('alergi')->name('alergi.')->group(function (): void {
            Route::get('/', [PasienController::class, 'alergiIndex'])
                ->name('index');
            Route::post('/', [PasienController::class, 'alergiStore'])
                ->name('store');
            Route::put('{id}', [PasienController::class, 'alergiUpdate'])
                ->whereNumber('id')
                ->name('update');
            Route::delete('{id}', [PasienController::class, 'alergiDestroy'])
                ->whereNumber('id')
                ->name('destroy');
        });
    });
});
