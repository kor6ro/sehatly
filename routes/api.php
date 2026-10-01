<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
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
| Every limiter below is named `auth-*` rather than `login`, `two-factor` or
| `passkeys`. Those three names belonged to the Fortify scaffold, which
| `App\Providers\FortifyServiceProvider` registered and which read
| `$request->session()` -- a call that does not exist on the stateless `api`
| group. Registering the same name in both places would either overwrite the
| scaffold's (silently removing the web form's throttling) or be overwritten by
| it (leaving this endpoint unthrottled), depending on provider order. Todo 30
| deleted Fortify and with it the collision, so the prefix is now historical
| rather than load-bearing; the names are unchanged because `routes/api.php`
| already spells them and a rename would buy nothing but a second chance to
| mistype one. `auth-*` can collide with nothing in the framework, and todo 52
| widens this vocabulary for the rest of the sensitive surface.
|
| The three limits are the plan's: 10/min on the OTP-sending endpoint, 5/min on
| login, 5/min on OTP verify. `user_otp` has **no attempt-counter column**, so
| the verify limit is the only thing bounding a six-digit brute force, and the
| key includes the caller's identifier so one attacker cannot lock every account
| out by spending a shared budget.
|
| APPENDED by F-002: seven of the ten limiters that `AppServiceProvider` had
| registered but left unmounted are now mounted - `auth-login-ip` beside
| `auth-login` on `/auth/login`, `auth-refresh` on `/auth/refresh`, and one each on
| booking, chat, checkout, promo validation and the payment webhook. The remaining
| three are deliberately NOT mounted: `otp-kirim` (3/min per identifier) and
| `otp-kirim-jam` (10/hour) would lower `/auth/login`'s EFFECTIVE ceiling from the
| plan's 5/min to 3/min, and `auth-register` (3/hour per IP) would lower
| `/auth/register` from 10/min to 3/hour per address and punish every patient
| behind one shared NAT. Mounting any of the three is a ceiling decision rather
| than a wiring one, so it is recorded in `AppServiceProvider`'s inventory and
| asserted UNMOUNTED by `tests/Feature/Security/RouteThrottlingTest.php` instead of
| being made here.
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
        // `otp-kirim` and `otp-kirim-jam` are deliberately NOT mounted here: both are
        // keyed on the identifier with a 3/min and a 10/hour ceiling, so mounting them
        // lowers login's EFFECTIVE ceiling below the plan's 5/min. That is a ceiling
        // decision rather than a wiring one, and F-002 recorded it in
        // `AppServiceProvider`'s inventory instead of making it here.
        ->middleware(['throttle:auth-login', 'throttle:auth-login-ip'])
        ->name('login');

    Route::post('otp/verify', [AuthController::class, 'verifyOtp'])
        ->middleware('throttle:auth-otp-verify')
        ->name('otp.verify');

    Route::post('refresh', [AuthController::class, 'refresh'])
        ->middleware('throttle:auth-refresh')
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

        /*
        | The caller's own bookings. A single `GET` with the project meta block;
        | the tenant scope lives in `PasienRecordAccess::bookingQuery()`, so a
        | booking on another patient's row is simply absent rather than refused.
        */
        Route::get('booking', [BookingController::class, 'indexPasien'])
            ->middleware('permission:booking.lihat')
            ->name('booking.index');
    });

    /*
    | Bookings. `POST` creates through `BookingService` (the `dokter` row lock
    | is the whole double-booking story), `PUT .../batalkan` cancels, and
    | `GET dokter/booking` is the doctor-side list.
    |
    | `dokter/booking` is a literal segment registered BEFORE the public
    | `dokter/{dokter}` wildcard below, so the literal is never swallowed by
    | it. It also carries `tipe:dokter`: "which account type is this" is
    | exactly the question that list asks, and a patient account is refused
    | before the controller runs.
    */
    Route::post('booking', [BookingController::class, 'store'])
        ->middleware(['permission:booking.buat', 'throttle:booking'])
        ->name('booking.store');

    Route::put('booking/{id}/batalkan', [BookingController::class, 'batalkan'])
        ->whereNumber('id')
        ->middleware('permission:booking.batal')
        ->name('booking.batalkan');

    Route::get('dokter/booking', [BookingController::class, 'indexDokter'])
        ->middleware(['permission:booking.lihat', 'tipe:dokter'])
        ->name('dokter.booking.index');
});

use App\Http\Controllers\Api\V1\DokterController;
use App\Http\Controllers\Api\V1\KonsultasiController;

/*
|--------------------------------------------------------------------------
| Module 1 -- the public doctor directory. Three routes, and NONE of them is
| gated.
|--------------------------------------------------------------------------
|
| `GET /dokter` and `GET /dokter/{dokter}` are unauthenticated on purpose. The
| plan's todo 22 says so explicitly, and `RbacCatalog` is what makes it the only
| workable answer: `dokter.lihat` **is** a real permission code, but
| `permission:` resolves through `EnsurePermission`, which answers 401 for an
| anonymous caller - so the gate would 401 every visitor who has not registered
| yet, which is the opposite of a directory. And it would 403 `perawat` and
| `kurir`, which are real `users.tipe` ENUM values (telemedicine_test.sql:139)
| that hold no role in `RbacCatalog::ROLES` and therefore no grant at all.
|
| `dokter.lihat` is not dead vocabulary, it is the wrong vocabulary *here*: it
| belongs on an administrative directory that is supposed to list unverified,
| inactive and STR-expired doctors so an operator can renew or suspend them.
| That endpoint must be authenticated and must NOT reuse this controller's
| query, because its purpose is to bypass the two eligibility rules
| `DokterDirectoryService` exists to enforce.
|
| `{dokter}`, not `{id}` and not `{id}` bound to a model: todo 26 adds
| `{dokter}/jadwal` and `{dokter}/slot`, and a `Dokter $dokter` type-hint would
| make implicit route-model binding resolve the segment and **bypass both
| eligibility rules**, answering 200 with an unverified doctor's profile.
|
| `DokterDirectoryTest` registers these three routes in-process and only when
| they are ABSENT, so it passes both before and after this block is pasted, and
| after pasting it drives this real route table.
|
*/

Route::get('dokter', [DokterController::class, 'index'])
    ->name('dokter.index');

Route::get('dokter/{dokter}', [DokterController::class, 'show'])
    ->whereNumber('dokter')
    ->name('dokter.show');

/*
| The doctor's schedule and bookable slots, both PUBLIC and both ungated for the
| same reasons the three routes above are: a patient has to be able to see when a
| doctor is free before they have an account, and `dokter.lihat` resolves through
| `EnsurePermission`, which answers 401 for an anonymous caller and 403 for
| `perawat` and `kurir`.
|
| They are registered here, AFTER `dokter/{dokter}`, and that ordering is stated
| rather than assumed: a two-segment wildcard cannot swallow a three-segment path,
| so neither of these can be shadowed by `dokter.show` and no comment is needed to
| prove it. They DO have to come after `dokter/booking` above, which is a literal
| two-segment path and would otherwise be unreachable -- that is the one ordering
| constraint in this file and it is already satisfied.
|
| `whereNumber('dokter')` on both, so a non-numeric segment is a 404 from the
| router rather than a `TypeError`, and so the segment can never carry a
| non-identifier into a query. That 404 is the SAME body the controllers publish
| for an ineligible doctor, deliberately: see `DokterController::jadwal()`.
|
| Neither route is a write. The plan lists no write endpoint for `dokter_jadwal`,
| so windows are seeded, not POSTed, and there is deliberately no `Route::apiResource`
| or `Route::resource` for either table here.
*/
Route::get('dokter/{dokter}/jadwal', [DokterController::class, 'jadwal'])
    ->whereNumber('dokter')
    ->name('dokter.jadwal');

Route::get('dokter/{dokter}/slot', [DokterController::class, 'slot'])
    ->whereNumber('dokter')
    ->name('dokter.slot');

Route::get('master-spesialisasi', [DokterController::class, 'spesialisasiIndex'])
    ->name('master-spesialisasi.index');

/*
|--------------------------------------------------------------------------
| Module 3 -- the consultation lifecycle and its chat transcript
|--------------------------------------------------------------------------
|
| APPENDED by todo 32. Nothing above this line is touched: todo 20 owns the eight
| auth routes, todo 21 the eleven patient routes, todo 22 and the gap closure the
| five public `dokter` routes, and todo 27 the four booking routes. This block is
| last in the file, which is the only ordering constraint it has: no route above
| it can swallow a two- or three-segment `konsultasi` path, and no route in it can
| swallow a path above it.
|
| ## Seven routes, and the seventh is not in the plan
|
| The plan's acceptance criterion says this path "lists 6 routes". It lists 7
| because `PUT /konsultasi/{id}/terima` had to be added, and the reason is structural
| rather than a matter of taste:
|
| - The plan requires `PUT /konsultasi/{id}/selesai` to compute `total_durasi_detik`
|   from `mulai_at` and to answer 422 while `mulai_at` is null.
| - `mulai_at` is `DATETIME NULL` (`telemedicine_test.sql:545`) and NOTHING in
|   the plan's six endpoints writes it.
| - `berlangsung` is therefore unreachable, and a consultation that never reaches
|   `berlangsung` can never reach `selesai`, so the plan's own completion endpoint
|   could only ever answer 422.
|
| `RbacCatalog::ROLE_PERMISSIONS` is the corroborating evidence that the step was
| always intended: `konsultasi.mulai` ("Mulai Konsultasi") is granted to `dokter` and to
| `superadmin` and to nobody else, and before this route no endpoint consumed that
| code. It is a doctor-side start-of-session, and the plan lost the route rather
| than the code. Recorded as a finding in `.omo/evidence/task-32-sehatly.md`.
|
| ## The guards, and why three of the seven carry none
|
| `auth:sanctum` is named on the group, for the same reason it is on the auth
| group: an unauthenticated caller gets the guard's 401 envelope rather than this
| controller's 403, and a route added to this file later cannot be unprotected by
| omission.
|
| | route | `permission:` | `tipe:` | who is refused, and why |
| | --- | --- | --- | --- |
| | `POST /konsultasi/mulai` | - | - | any account with no `pasien` row, 403 from `ownPasien()` |
| | `GET /konsultasi/{id}` | - | - | a non-party, 404; `superadmin` is allowed by the plan |
| | `GET /konsultasi/{id}/chat` | - | - | a non-party, 404 |
| | `PUT /konsultasi/{id}/terima` | `konsultasi.mulai` | `dokter` | patient, apoteker, admin, perawat, kurir, superadmin |
| | `POST /konsultasi/{id}/chat` | `konsultasi.chat` | - | apoteker, admin, perawat, kurir, superadmin |
| | `POST /konsultasi/{id}/chat/baca` | `konsultasi.chat` | - | same |
| | `PUT /konsultasi/{id}/selesai` | `konsultasi.selesai` | `dokter` | same as `/terima` |
|
| All three consultation permission codes in `RbacCatalog::PERMISSIONS` are
| consumed here, and each is used where the plan names the action. The three
| ungated routes are a decision, not an omission:
|
| 1. No code names "read a consultation", so a `permission:` there would have to
|    be invented - and `EnsurePermission` answers an unknown code with a
|    **500**, not a 403, which is the failure mode `RbacCatalog`'s docblock and
|    `PasienRecordAccess`'s both cite as the reason not to.
| 2. The plan's `admin`/`superadmin` read allowance is a disjunction - "a party OR
|    an oversight account" - and a route gate can only express a conjunction.
|    `tipe:admin,superadmin` would exclude the patient and the doctor. It is in
|    `KonsultasiAccess::findForRead()` instead, which delegates the party half of the
|    disjunction to the ONE rule `routes/channels.php` uses.
| 3. `POST /konsultasi/mulai` is a patient action, and `PasienRecordAccess::ownPasien()`
|    already answers "does this account own a patient profile?" with a 403.
|    `PasienRecordAccess` argues at length against a `tipe:pasien` in front of it,
|    and this route follows that reasoning instead of restating it.
|
| `perawat` and `kurir` are real `users.tipe` values (`telemedicine_test.sql:139`)
| that hold no role, so ANY `permission:` would lock them out of that route
| permanently. They are refused on all seven anyway, and the reason is rows they do
| not own rather than a role they lack: 403 on the two chat writes and on
| `POST /mulai`, 404 on the three reads, and 403 at `tipe:dokter` on the two
| doctor-only writes.
|
| ## `whereNumber('id')` on every `{id}`
|
| `konsultasi.id` and `KonsultasiChat.id` are `BIGINT UNSIGNED AUTO_INCREMENT` primary keys
| (`:537`, `:564`). `whereNumber` makes a non-numeric segment a router 404, so the
| controllers take an `int` and no request can arrive with `abc` in a position the
| API treats as an identifier. `POST /mulai` is a one-segment path and needs none.
|
| ## No `Route::resource` and no `Route::apiResource`
|
| The six lifecycle operations have six distinct verbs and two distinct shapes
| (`/chat` is both a GET and a POST, `/chat/baca` is neither), so a resource
| route would publish methods this surface does not have.
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::prefix('konsultasi')->name('konsultasi.')->group(function (): void {
        Route::post('mulai', [KonsultasiController::class, 'mulai'])
            ->name('mulai');

        Route::get('{id}', [KonsultasiController::class, 'show'])
            ->whereNumber('id')
            ->name('show');

        Route::put('{id}/terima', [KonsultasiController::class, 'terima'])
            ->whereNumber('id')
            ->middleware(['tipe:dokter', 'permission:konsultasi.mulai'])
            ->name('terima');

        Route::get('{id}/chat', [KonsultasiController::class, 'chatIndex'])
            ->whereNumber('id')
            ->name('chat.index');

        Route::post('{id}/chat', [KonsultasiController::class, 'chatStore'])
            ->whereNumber('id')
            ->middleware(['permission:konsultasi.chat', 'throttle:chat'])
            ->name('chat.store');

        Route::post('{id}/chat/baca', [KonsultasiController::class, 'chatBaca'])
            ->whereNumber('id')
            ->middleware('permission:konsultasi.chat')
            ->name('chat.baca');

        Route::put('{id}/selesai', [KonsultasiController::class, 'selesai'])
            ->whereNumber('id')
            ->middleware(['tipe:dokter', 'permission:konsultasi.selesai'])
            ->name('selesai');
    });
});

use App\Http\Controllers\Api\V1\RekamMedisController;

/*
|--------------------------------------------------------------------------
| Module 3 -- the medical record, its amendment chain and its access log
|--------------------------------------------------------------------------
|
| APPENDED by todo 33. Nothing above this line is touched. This block is last in
| the file for the same reason todo 32's is: no route above it can swallow a two-
| or three-segment `rekam-medis` path, and no route in it can swallow a path above
| it. `konsultasi/{id}/rekam-medis` is registered here rather than inside the
| `konsultasi` group above because `POST api/v1/konsultasi/{id}` is already bound by
| todo 32's `GET`-style two-segment route and the create hangs a RECORD off a
| consultation rather than being a lifecycle step of one.
|
| ## Five routes, and the plan's count is right
|
| `php artisan route:list --path=api/v1/rekam-medis` answers 4 and the create hangs
| off `konsultasi`, so the FIVE this todo delivers are 4 here plus 1 in the
| consultation namespace - and the test asserts the closed set of all five by URI
| rather than by prefix, because a filter on `rekam-medis` alone would answer 4 and
| read like a missing route.
|
| There is deliberately NO list endpoint. The plan names five operations, and a sixth
| would break its own acceptance criterion; the cost is that a patient cannot page
| through their own records over HTTP, which is reported in
| `.omo/evidence/task-33-sehatly.md` rather than papered over.
|
| ## The guards, and why the read carries none
|
| | route | `permission:` | `tipe:` |
| | --- | --- | --- |
| | `POST /konsultasi/{id}/rekam-medis` | `rekam_medis.simpan` | `dokter` |
| | `PUT /rekam-medis/{id}` | `rekam_medis.simpan` | `dokter` |
| | `PUT /rekam-medis/{id}/final` | `rekam_medis.final` | `dokter` |
| | `POST /rekam-medis/{id}/amandemen` | `rekam_medis.final` | `dokter` |
| | `GET /rekam-medis/{id}` | - | - |
|
| `rekam_medis.lihat` IS a real code and is deliberately NOT used. It is granted to
| `pasien`, `dokter` and `superadmin` and NOT to `admin`
| (`RbacCatalog::ROLE_PERMISSIONS`), so it would 403 the `admin` the plan names as the
| `audit` reader. The plan's read audience is a DISJUNCTION - the patient themselves
| OR their doctor OR an oversight account - and a route gate can only express a
| conjunction. `KonsultasiController` makes exactly this argument for
| `GET /konsultasi/{id}`; the disjunction lives in `RekamMedisAccess::sisiUntukBaca()`.
|
| `/amandemen` is gated on `rekam_medis.final` and not on `rekam_medis.simpan`,
| because an amendment carries the same clinical authority as the signed record it
| supersedes. Both are held by the same two roles, so the choice is semantic rather
| than behavioural.
|
| `perawat` and `kurir` are real `users.tipe` values (`telemedicine_test.sql:139`)
| that hold no role, so any `permission:` locks them out of all four writes
| permanently. The DDL gives a nurse a clinical role of its own -
| `pasien_tanda_vital.sumber` is `ENUM('mandiri','dokter','perawat','iot_device')`
| at `:325` - so this is a real gap and it is reported: the fix is a data change in
| `app/Support/Rbac/` plus a re-seed, not a code change here.
|
| ## The read writes an access-log row and cannot be made not to
|
| The obligation is enforced by `App\Models\Concerns\GuardsMedicalRecordRead`, a model
| event on `rekam_medis` and its four children: hydrating one of those rows outside a
| `RekamMedisReadScope` throws, and the only thing that opens the scope is
| `RekamMedisAccessLogger::baca()`, which has already written the
| `akses_rekam_medis_log` row in the same transaction. This is why the read needs no
| `permission:`: a route gate is a CONVENTION, and a model event is a chokepoint.
|
| ## `whereNumber` on every `{id}`
|
| `rekam_medis.id` and `konsultasi.id` are `BIGINT UNSIGNED AUTO_INCREMENT` primary
| keys (`:622`, `:537`), so a non-numeric segment is a router 404 and no request can
| arrive with `abc` in a position the API treats as an identifier.
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('konsultasi/{id}/rekam-medis', [RekamMedisController::class, 'simpan'])
        ->whereNumber('id')
        ->middleware(['tipe:dokter', 'permission:rekam_medis.simpan'])
        ->name('konsultasi.rekam-medis.store');

    Route::prefix('rekam-medis')->name('rekam-medis.')->group(function (): void {
        Route::get('{id}', [RekamMedisController::class, 'show'])
            ->whereNumber('id')
            ->name('show');

        Route::put('{id}', [RekamMedisController::class, 'ubah'])
            ->whereNumber('id')
            ->middleware(['tipe:dokter', 'permission:rekam_medis.simpan'])
            ->name('update');

        Route::put('{id}/final', [RekamMedisController::class, 'finalisasi'])
            ->whereNumber('id')
            ->middleware(['tipe:dokter', 'permission:rekam_medis.final'])
            ->name('final');

        Route::post('{id}/amandemen', [RekamMedisController::class, 'amandemen'])
            ->whereNumber('id')
            ->middleware(['tipe:dokter', 'permission:rekam_medis.final'])
            ->name('amandemen');
    });
});

use App\Http\Controllers\Api\V1\SuratKeteranganController;

/*
|--------------------------------------------------------------------------
| Module 3 -- medical letters, referrals, and QR verification
|--------------------------------------------------------------------------
|
| APPENDED by todo 34. Nothing above this line is touched. The block is last in
| the file for the same reason todo 32's and todo 33's are: no route above it can
| swallow a two- or three-segment `surat-keterangan` path, and no route in it can
| swallow a path above it. `POST api/v1/konsultasi/{id}/surat-keterangan` is
| registered FIRST even though it hangs a letter off a consultation, because
| `konsultasi/{id}/rekam-medis` is already bound and the ordering is stated here
| rather than assumed.
|
| ## Three routes, and the plan's `route:list` count is 2
|
| The plan's acceptance criterion says
| `php artisan route:list --path=api/v1/surat-keterangan` lists 3 routes. It answers
| **2**, because the plan's own third route
| (`GET /api/v1/pasien/surat-keterangan`) is a PASIEN path. This is the same class
| of defect todo 33 found and reported for `POST /konsultasi/{id}/rekam-medis` and
| the one todo 32 found for `konsultasi`: a prefix filter does not see a route
| whose path lives under another resource. All three ship; the test asserts the
| closed set of all three by URI with a predicate covering both prefixes, so the
| count is right and the filter was not widened to hide it.
|
| ## The guards, and why the verifier carries NONE
|
| | route | `permission:` | `tipe:` | who is refused, and why |
| | --- | --- | --- | --- |
| | `POST /konsultasi/{id}/surat-keterangan` | `surat_keterangan.buat` | `dokter` | patient, apoteker, admin, perawat, kurir, superadmin; another doctor 404 |
| | `GET /pasien/surat-keterangan` | - | - | an account with no `pasien` row, 403; another patient's letters absent |
| | `GET /surat-keterangan/{nomor_surat}/verify` | - | - | NOTHING: it is public |
|
| **`surat_keterangan.buat` is a real code** in `RbacCatalog::PERMISSIONS`, granted to
| `dokter` and to `superadmin`. `tipe:dokter` is what excludes the `superadmin`, and it
| is there because issuing a clinical letter is not a thing an oversight account does.
|
| **`perawat` and `kurir` are real `users.tipe` values** (`telemedicine_test.sql:139`)
| that hold NO role in `RbacCatalog::ROLES`, so any `permission:` locks them out of the
| create permanently. The DDL makes the gap sharper rather than softer: the migrate
| side has no nurse role at all, so a nurse is a real account with no grant that could
| let her write a letter. Reported as a data change in `app/Support/Rbac/` plus a
| re-seed, and it is not this todo's to make. On the list route they are refused with a
| 403 from `PasienRecordAccess::ownPasien()` - a fact about rows they do not own rather
| than a role they lack.
|
| **THE VERIFIER IS PUBLIC, DELIBERATELY.** A QR code is a physical artifact: it is
| printed on a letter, handed to a patient, carried to another facility, photographed by
| whoever is standing there, and scanned by a receptionist who has no account and never
| will. A `permission:` gate would answer **401** for an anonymous caller and **403** for
| `perawat` and `kurir` - useless in the only situation the endpoint exists for. This is
| the same argument todo 22 makes for the five public `dokter` routes, and it is why
| `EnsurePermission`'s 401 for a guest is quoted there rather than here.
|
| The price of being public is that the service publishes the MINIMUM: the verdict, the
| document number, the letter TYPE, the signing doctor in full, the issue date, and the
| patient's name MASKED word by word. No NIK at all - not even masked - no letter body,
| no clinical period, no surrogate id, and the token is never echoed. An invalid token
| answers `valid: false` with every other field `null`, and a document number that does
| not exist is byte-for-byte the same response, so the endpoint is not an existence
| oracle. The service docblock states the whole disclosure surface and the test asserts
| it by byte search over the response body rather than by enumeration.
|
| ## `{nomor_surat}` is a string, deliberately
|
| `surat_keterangan.nomor_surat` is `VARCHAR(50) NOT NULL UNIQUE` (`:583`) - a document
| number a patient reads out to a receptionist, not a surrogate key - so the parameter
| takes a string and is NOT bound with `whereNumber()` or to a model. It is also not
| bound to `SuratKeterangan`, because implicit route-model binding would answer 404 for a
| number that does not exist, and the verifier must answer `valid: false` for both cases
| indistinguishably. `{id}` on the create IS `whereNumber`, because `konsultasi.id` is a
| `BIGINT UNSIGNED AUTO_INCREMENT` primary key (`:537`).
|
| ## No `Route::resource` and no `Route::apiResource`
|
| The three operations have three distinct verbs and three distinct shapes - a POST with
| a body, a paginated list, and a public query-string verification - so a resource route
| would publish methods this surface does not have.
*/

Route::post('konsultasi/{id}/surat-keterangan', [SuratKeteranganController::class, 'buat'])
    ->where('id', '(\d+)')
    ->middleware(['tipe:dokter', 'permission:surat_keterangan.buat', 'auth:sanctum'])
    ->name('konsultasi.surat-keterangan.store');

Route::get('pasien/surat-keterangan', [SuratKeteranganController::class, 'daftar'])
    ->middleware('auth:sanctum')
    ->name('pasien.surat-keterangan.index');

Route::get('surat-keterangan/{nomor_surat}/verify', [SuratKeteranganController::class, 'verifikasi'])
    ->name('surat-keterangan.verify');

use App\Http\Controllers\Api\V1\PromoController;
use App\Http\Controllers\Api\V1\ReferensiController;
use App\Support\Reference\ReferensiEndpoint;

/*
|--------------------------------------------------------------------------
| Module 1 -- the public reference lookups. Fourteen routes, and NONE of them
| is gated.
|--------------------------------------------------------------------------
|
| APPENDED by todo 42. Nothing above this line is touched. This block is last in
| the file, and it carries the only ordering constraint that matters: there is
| no wildcard route under `referensi/`, so no route above can swallow any of
| these and none of these can swallow a path above. Registration order inside
| the block is therefore free, which is why the loop below is safe.
|
| ## The arithmetic: 14 = 13 tables + the catalogue
|
| The plan's todo 42 names 13 master tables. Section `[1] MASTER DATA` of
| `telemedicine_test.sql` declares 11 of them; `master_spesialisasi` (:402) and
| `master_metode_pembayaran` are declared OUTSIDE that section and both are
| named by the plan, so plan and DDL agree once the two strays are counted. The
| 14th route is `referensi/enums`, which reads no table at all - it serves the
| generated `docs/enums.json` - so it is registered literally rather than from
| the loop.
|
| ## The 13 are registered IN A LOOP, and that is the safety property
|
| A hand-written list of 13 route lines is a second list of the 13 endpoints,
| and two lists drift: a table added to `ReferensiEndpoint::all()` gets a
| controller path and no route, and the feature test that asserts the closed set
| of 14 is the only thing that notices. Iterating the definition instead makes
| the route set and the definition set the SAME set by construction, so the
| question "is every defined endpoint routable" cannot have a false answer. The
| cost is that the 13 paths are not literally greppable in this file; the
| trade is made deliberately and `route:list` is the place to read them.
|
| ## The controllers are named, so `/referensi/enums` cannot be shadowed
|
| Every route is named `referensi.<slug>` with `<slug>` exactly the definition's
| slug, and `IndexReferensiRequest::endpoint()` reads that name back to find the
| definition. Naming them is therefore load-bearing, not decoration: it is the
| only channel through which a generic controller method learns which of the 13
| endpoints it is serving. `Route::defaults()` would have been the obvious
| alternative and is deliberately NOT used - Laravel 13's `Route::defaults()`
| writes into `$this->defaults` with no `replaceDefaults()` to fold that into the
| bound parameters, so a default is not reliably readable off the request. A route
| name is set by the registration itself and is always present.
|
| ## Why NONE of them carries `auth:sanctum`, `permission:` or `tipe:`
|
| Every value on this surface is chosen BEFORE the client has an account: a
| patient registering on a phone needs a province list, and they hold no token
| yet. So `auth:sanctum` would lock out the exact caller the endpoints exist for.
|
| `permission:` is the same argument todo 22 makes for the public `dokter` routes,
| and it is stronger here: `RbacCatalog` holds no code that names reference data,
| so a gate would mean inventing policy - and `EnsurePermission` answers an
| unknown code with a 500, not a 403. `tipe:` is worse than absent: `perawat` and
| `kurir` are real `users.tipe` ENUM values (:139) that hold no role in
| `RbacCatalog::ROLES` and therefore no grant at all, so ANY `tipe:` gate would
| lock those two account types out of a dropdown forever.
|
| The price of being public is paid deliberately: the responses carry the
| MINIMUM. All 13 tables are lookup vocabularies seeded from the DDL - labels,
| codes, geography, a fee schedule. Not one is a person's data, and none of the 14
| responses contains a `users`, `pasien` or `rekam_medis` column.
|
| ## All 14 are GET, and there is deliberately no write
|
| These are reference tables, not user data. The plan names no write endpoint for
| any of them, so there is no `Route::resource` or `Route::apiResource` here: a
| resource route would publish `store`, `update` and `destroy` for tables that
| are seeded from `telemedicine_test.sql` and never edited through the API. A
| `POST` to any of these 14 paths is a router 405, not a 403.
|
| ## `master_spesialisasi` is already public at `/master-spesialisasi`
|
| Todo 22 registered `GET /api/v1/master-spesialisasi`, and it stays. The
| `/referensi/spesialisasi` route below is not a second implementation - it is the
| same four columns through the same resource, reachable under the reference
| prefix so the 14-route surface is whole. The full reasoning is in
| `.omo/evidence/task-42-sehatly.md`.
*/

Route::get('referensi/enums', [ReferensiController::class, 'enums'])
    ->name('referensi.enums');

foreach (ReferensiEndpoint::all() as $referensiEndpoint) {
    Route::get('referensi/'.$referensiEndpoint->slug, [ReferensiController::class, 'index'])
        ->name('referensi.'.$referensiEndpoint->slug);
}

use App\Http\Controllers\Api\V1\ResepController;

/*
|--------------------------------------------------------------------------
| Module 4 -- medicine search and e-prescription creation
|--------------------------------------------------------------------------
|
| APPENDED by todo 39. Nothing above this line is touched. This block is last
| in the file: no route above it can swallow a two-segment `obat` path or a
| three-segment `konsultasi/{id}/resep` path, and neither route here can
| swallow a path above it.
|
| ## Two routes, and the plan's count is right
|
| `GET /api/v1/obat` searches `master_obat` on `nama_generik` and `nama_brand`
| with `status_aktif = 1`, paginated. `POST /api/v1/konsultasi/{id}/resep`
| writes one prescription off the consultation in the path. There is
| deliberately no other POST that writes a `resep`, which is what enforces the
| plan's "reject `requires_resep = 1` drugs outside a consultation" rule
| structurally: there is no outside.
|
| ## The guards, and why each half is there
|
| | route | `permission:` | `tipe:` | who is refused, and why |
| | --- | --- | --- | --- |
| | `GET /obat` | `obat.cari` | `dokter` | patient, apoteker, admin, perawat, kurir, superadmin |
| | `POST /konsultasi/{id}/resep` | `resep.buat` | `dokter` | same |
|
| `obat.cari` and `resep.buat` are both real codes in
| `RbacCatalog::PERMISSIONS`, granted to `dokter` (and, for both, to
| `superadmin`). `tipe:dokter` is what excludes the `superadmin`: issuing a
| prescription - and browsing the catalogue to write one - is not a thing an
| oversight account does. `apoteker` holds NEITHER code: a pharmacist
| verifying a prescription is todo 40's surface and the catalogue behind it is
| this todo's, and the plan's "doctor-only" is read narrowly and recorded in
| `RbacCatalog`'s own docblock.
|
| `perawat` and `kurir` are real `users.tipe` values (`telemedicine_test.sql:139`)
| that hold NO role in `RbacCatalog::ROLES`, so any `permission:` locks them out of
| both routes permanently. Reported as a data change in `app/Support/Rbac/` plus a
| re-seed, and not this todo's to make.
|
| ## `whereNumber('id')` on the create
|
| `konsultasi.id` is a `BIGINT UNSIGNED AUTO_INCREMENT` primary key (`:537`), so a
| non-numeric segment is a router 404 and no request can arrive with `abc` in a
| position the API treats as an identifier.
|
| ## No `Route::resource`
|
| The two operations have two distinct verbs and two distinct shapes, so a
| resource route would publish methods this surface does not have.
*/

/*
|--------------------------------------------------------------------------
| Module 5 -- promo validation. One route, and it is a PURE CALCULATION
|--------------------------------------------------------------------------
|
| APPENDED by todo 44. Nothing above this line is touched. This block is last in
| the file: no route above it can swallow a two-segment `promo` path, and this
| route cannot swallow a path above it.
|
| ## The plan's count is right: ONE route
|
| `php artisan route:list --path=api/v1/promo` answers 1. The write that applies
| a promo is not here either - it is `InvoiceService::buat()`, reached from
| todo 45's payment and todo 46's checkout, because a promo cannot be applied to
| an invoice that does not exist yet and no endpoint in Modules 1-5 creates one
| on demand. Inventing a second route to reach it would be inventing an invoice
| creation surface the plan does not describe.
|
| ## `auth:sanctum` and NOTHING else
|
| `promo.validasi` IS a real code in `RbacCatalog::PERMISSIONS` and it is
| deliberately NOT used as a `permission:` gate. `RbacCatalog::ROLE_PERMISSIONS`
| grants it to `admin` and `superadmin` and to **nobody else** - the `pasien`
| role's list (`:221-235`) does not contain it. A `permission:promo.validasi`
| gate would therefore 403 the ONE account type that owns a `pasien` row to
| validate against, and the endpoint would be reachable by exactly the callers
| who cannot use it. `EnsurePermission` also answers an unknown code with a
| 500, so a gate that could not resolve would be worse than no gate.
|
| `tipe:pasien` is refused for the reason `PasienRecordAccess` argues at length:
| it answers "which account type is this", which cannot express "is this invoice
| yours", and a `pasien`-typed account with no `pasien` row passes it and is
| refused by the service anyway - so it would be a second, strictly weaker gate
| answering one question. `PromoController` resolves the caller's own `pasien`
| row through `PasienRecordAccess::ownPasien()`, which raises the 403 for an
| account that owns no profile.
|
| `perawat` and `kurir` are real `users.tipe` ENUM values
| (`telemedicine_test.sql:139`) that hold NO role in `RbacCatalog::ROLES`, so
| ANY `permission:` would lock those two out permanently. On this route they are
| refused with a 403 from `ownPasien()` - a fact about rows they do not own
| rather than a role they lack, which is the honest shape of the refusal.
|
| ## It writes NOTHING, and the schema is why
|
| `promo_redemption.invoice_id` is `NOT NULL` (`:1004`) and foreign-keyed to
| `invoice(id)` (`:1009`). A validation call has no invoice to attach a
| redemption to, and minting one to hold the row is exactly the write the
| endpoint is defined not to make. So this route READS and the redemption is
| written by `InvoiceService` at the moment the promo is actually applied. The
| test asserts zero `promo_redemption` rows AND zero `invoice` rows afterwards.
|
| ## It answers 200 with `valid: false`, not 422
|
| A caller asking "would this code work for me?" is asking a question, and "no,
| and here is which of the five rules it breaks" is the answer. The five reasons
| arrive as `data.alasan` with machine-readable `kode` values, so a client
| branches on the code and not on Indonesian prose. The APPLY path is the
| opposite and deliberately so: there the caller asked for something to happen,
| so `PromoHitungan::tolak()` raises a 422 through the standard envelope.
|
| ## No `Route::resource`
|
| One operation, one verb, one shape, so a resource route would publish `show`,
| `update` and `destroy` for a table no endpoint in Modules 1-5 writes.
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('promo/validasi', [PromoController::class, 'validasi'])
        ->middleware('throttle:promo-validasi')
        ->name('promo.validasi');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('obat', [ResepController::class, 'search'])
        ->middleware(['tipe:dokter', 'permission:obat.cari'])
        ->name('obat.index');

    Route::post('konsultasi/{id}/resep', [ResepController::class, 'store'])
        ->whereNumber('id')
        ->middleware(['tipe:dokter', 'permission:resep.buat'])
        ->name('konsultasi.resep.store');
});

/*
|--------------------------------------------------------------------------
| Module 4 -- prescription detail, pharmacist verification, patient history
|--------------------------------------------------------------------------
|
| APPENDED by todo 40. Nothing above this line is touched. This block is last in
| the file, which is the only ordering constraint it has: `resep/{id}` is a
| two-segment path and `pasien/resep` is a two-segment `pasien` path, so no
| route above can swallow either and neither can swallow a path above.
|
| ## Five routes, and `route:list --path=api/v1/resep` answers FOUR
|
| The plan's acceptance criterion says that filter "lists 4 routes". It answers
| 3, because `api/v1/pasien/resep` is a `pasien` path and a prefix filter
| cannot see it. This is the same class of defect todo 32 found for
| `konsultasi`, todo 33 for `rekam-medis` and todo 34 for `surat_keterangan`;
| the count is recorded rather than satisfied by deleting an endpoint, and the
| test asserts the closed set of all four BY URI. F09 then appended the
| one-segment queue literal `GET /api/v1/resep`, whose path DOES start with the
| prefix: the filter now answers 4 (the queue, the detail, the interaction
| re-check and the verify write), the closed set is five (those four plus
| `pasien/resep`), and `ResepTodo40Test` moves its prefix count to 5.
|
| `GET /pasien/resep` sits on THIS controller rather than on `PasienController`
| for the reason todo 34 registered `GET /pasien/surat-keterangan` on
| `SuratKeteranganController`: the path prefix names the CALLER and the
| controller names the RESOURCE, and putting a prescription list on the patient
| profile controller would make that controller the owner of two modules.
|
| ## The guards, and why the verify write carries two
|
| | route | `permission:` | `tipe:` | who is refused, and why |
| | --- | --- | --- | --- |
| | `GET /resep` | `resep.verifikasi` | `apoteker` | patient, doctor (INCLUDING the prescriber), admin, perawat, kurir, superadmin; the queue exists to feed the verify write, so an account that cannot sign is not handed the worklist |
| | `GET /resep/{id}` | `resep.lihat` | - | `admin` (holds no `resep.lihat`), `perawat`, `kurir`; another patient's row 404 |
| | `GET /resep/{id}/cek-interaksi` | `resep.lihat` | - | same |
| | `POST /resep/{id}/verifikasi` | `resep.verifikasi` | `apoteker` | patient, doctor (INCLUDING the prescriber), admin, perawat, kurir, superadmin |
| | `GET /pasien/resep` | `resep.lihat` | - | a caller with no `pasien` row, 403 from `PasienRecordAccess` |
|
| The queue carries the SAME pair as the write it feeds, and that is the whole
| argument for it: `resep.lihat` would have admitted the patient, the prescriber
| and `superadmin`, so the queue would have become a second, wider read of every
| patient's pending prescriptions. The two gates do different jobs -
| `permission:resep.verifikasi` is the grant (held by `apoteker` and
| `superadmin`), `tipe:apoteker` is the account type (which excludes
| `superadmin` from signing) - and neither alone is the audience.
|
| `resep.lihat` and `resep.verifikasi` are both real codes in
| `RbacCatalog::PERMISSIONS` with Indonesian verbs - "Lihat Resep" and "Verifikasi
| Resep" - and `EnsurePermission` answers an UNKNOWN code with a 500, not a
| 403, so a route written as `resep.read` would build-break. The test resolves
| every `permission:` and `tipe:` string in this block against `RbacCatalog`.
|
| ## The three reads carry no `tipe:`, and the write carries no `permission:`
| alone
|
| The read audience is a DISJUNCTION - the prescribing doctor OR the patient OR
| a pharmacist -- and a route gate can only express a conjunction, so the
| per-row half of the rule lives in `ResepAccess`, which is where the
| 404-for-another-patient and 403-for-an-unowned-caller split is decided. The
| same argument `KonsultasiController` makes for `GET /konsultasi/{id}`.
|
| The write needs BOTH gates and the second is not redundant. `tipe:apoteker`
| is what excludes the prescribing doctor, because `resep.verifikasi` is
| granted to `apoteker` and `superadmin` and the plan does not want an
| oversight account signing clinical prescriptions - the asymmetry todo 34
| applies to `surat_keterangan.buat`. `resepAccess::untukVerifikasi()` then
| repeats the separation of duties for an account that is BOTH a pharmacist and
| the prescribing doctor, which `tipe:` cannot express because `dokter.user_id`
| and `users.tipe` are independent columns.
|
| `perawat` and `kurir` are real `users.tipe` values (`telemedicine_test.sql:139`)
| that hold NO role in `RbacCatalog::ROLES`, so any `permission:` locks them out
| of all four permanently. Reported as a data change in `app/Support/Rbac/`
| plus a re-seed, and not this todo's to make.
|
| ## `GET /resep` is a ONE-SEGMENT literal and is registered FIRST
|
| Inside the `resep` prefix group the queue is `Route::get('/', ...)`, whose
| URI is `api/v1/resep`. It cannot be swallowed by `resep/{id}` and cannot
| swallow it: one pattern is a single segment and the other is two, and the
| router compares whole paths, so no `whereNumber` is needed to tell them
| apart. It is registered BEFORE the wildcard anyway - "literals before
| wildcards" is the convention every block in this file states, and a reader
| comparing this block with `route:list` should not have to prove the two
| shapes disjoint to trust the order. `GET /resep/{id}` with an id that does
| not exist is still the 404 it was, and `GET /resep` still answers the queue,
| whichever order the table is read in.
|
| ## `whereNumber('id')` on every `{id}`
|
| `resep.id` and `resep_verifikasi.id` are `BIGINT UNSIGNED AUTO_INCREMENT`
| primary keys (`:743`, `:787`), so a non-numeric segment is a router 404 and
| no request can arrive with `abc` in a position the API treats as an
| identifier.
|
| ## No `Route::resource` and no `Route::apiResource`
|
| The four operations have four distinct verbs and four distinct shapes, and a
| resource route would publish `create`, `update` and `destroy` for a
| prescription, which the DDL makes impossible: `resep_verifikasi` holds one row
| per prescription for ever, and a prescription itself is corrected by writing
| ANOTHER one, not by editing this one.
|
| @see \App\Services\Resep\ResepVerifikasiService
| @see \App\Services\Resep\ResepStateMachine
| @see \App\Services\Resep\ResepAccess
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('pasien/resep', [ResepController::class, 'riwayat'])
        ->middleware('permission:resep.lihat')
        ->name('pasien.resep.index');

    Route::prefix('resep')->name('resep.')->group(function (): void {
        /*
        | F09: the pharmacist verification queue, a ONE-SEGMENT literal. The
        | `Route::get('/')` inside the prefix compiles to `api/v1/resep`; it
        | cannot collide with the two-segment `{id}` route below, and it is
        | registered first because literals precede wildcards in this file.
        | The guard pair is the SAME as the verify write's - a pharmacist
        | type AND the verification grant - so the patient, the doctor and
        | `superadmin` never reach the controller. The disclosure surface is
        | `ResepAntreanResource`, not `ResepResource`; see the block above.
        */
        Route::get('/', [ResepController::class, 'antrean'])
            ->middleware(['tipe:apoteker', 'permission:resep.verifikasi'])
            ->name('antrean');

        Route::get('{id}', [ResepController::class, 'show'])
            ->whereNumber('id')
            ->middleware('permission:resep.lihat')
            ->name('show');

        Route::get('{id}/cek-interaksi', [ResepController::class, 'cekInteraksi'])
            ->whereNumber('id')
            ->middleware('permission:resep.lihat')
            ->name('cek-interaksi');

        Route::post('{id}/verifikasi', [ResepController::class, 'verifikasi'])
            ->whereNumber('id')
            ->middleware(['tipe:apoteker', 'permission:resep.verifikasi'])
            ->name('verifikasi');
    });
});

use App\Http\Controllers\Api\V1\PesananObatController;

/*
|--------------------------------------------------------------------------
| Module 5 -- prescription checkout, pharmacy stock and order tracking
|--------------------------------------------------------------------------
|
| APPENDED by todo 46. Nothing above this line is touched. This block is last
| in the file, which is the only ordering constraint it has: `resep/{id}/checkout`
| is a three-segment path under a prefix that already has `resep/{id}` and
| `resep/{id}/verifikasi`, and a two-segment wildcard cannot swallow a
| three-segment path - so `checkout` is registered OUTSIDE the `resep` group
| above rather than inside it, and the reason is the same one todo 34 gives for
| putting `konsultasi/{id}/surat-keterangan` first.
|
| ## Three routes, and the plan's "both routes" is two
|
| The plan's acceptance criterion says this filter "includes both routes". It
| names THREE operations in its own prose - `POST /resep/{id}/checkout`,
| `GET /obat/{id}/stok?apotek_id=` and `GET /pesanan-obat/{id}` - so all three
| ship and the count is recorded rather than satisfied by deleting an endpoint.
| This is the same class of defect todo 32 found for `konsultasi`, todo 33 for
| `rekam-medis`, todo 34 for `surat_keterangan` and todo 40 for `resep`; the
| test asserts the closed set of all three BY URI.
|
| ## The guards, and why the checkout carries exactly one
|
| | route | `permission:` | `tipe:` | who is refused, and why |
| | --- | --- | --- | --- |
| | `POST /resep/{id}/checkout` | `pesanan.buat` | - | `dokter`, `apoteker`, `admin`, `perawat`, `kurir` |
| | `GET /obat/{id}/stok` | - | - | NOTHING: any signed-in account may read a shelf |
| | `GET /pesanan-obat/{id}` | `pesanan.lihat` | - | `dokter` (holds no `pesanan.lihat`), `perawat`, `kurir`; another patient's order 404 |
|
| **`pesanan.buat` IS a real code** in `RbacCatalog::PERMISSIONS` and
| `ROLE_PERMISSIONS` grants it to `pasien` and `superadmin` - the two account
| types that legitimately place an order. Both are READ against the catalogue
| here, because `EnsurePermission` answers an UNKNOWN code with a 500, not a
| 403, so a route written as `permission:pesanan.create` would be a
| build-breaking mistake.
|
| **No `tipe:` on the checkout, deliberately.** `tipe:pasien` would exclude
| `superadmin`, which HOLDS `pesanan.buat`, and it answers "which account type
| is this" rather than "is this order yours" - the argument
| `PasienRecordAccess` makes at length and that the whole `pasien` block in this
| file already follows. `PesananObatController::checkout()` resolves the
| caller's own `pasien` row through `PasienRecordAccess::ownPasien()`, which
| raises the 403 for an account that owns no profile.
|
| **The stock read is UNGATED, and that is a decision rather than an omission.**
| A shelf is not private data: it is a catalogue-adjacent fact about a drug at
| a facility, and no code in `RbacCatalog::PERMISSIONS` names reading one.
| `obat.cari` IS a real code but it is granted to `dokter` alone, so gating this
| on it would 403 the PATIENT - the one account type that has to be able to ask
| "is my prescription in stock before I pay for it", which is the entire
| purpose of the endpoint and of the spec's `cek stok` rule. `tipe:` is worse
| than absent here for the reason it is everywhere else in this file: `perawat`
| and `kurir` are real `users.tipe` values (`:139`) that hold NO role in
| `RbacCatalog::ROLES` and therefore no grant at all, so ANY gate would lock
| those two account types out of a read that concerns neither of them. The route
| DOES name `auth:sanctum`, because a stock figure for an arbitrary drug at an
| arbitrary facility is a query, not a public catalogue entry, and the
| alternatives list is a per-caller answer.
|
| **`GET /pesanan-obat/{id}` takes `pesanan.lihat` and no `tipe:`.** The read
| audience is a DISJUNCTION - the owning patient OR a pharmacist OR an oversight
| account - and a route gate can only express a conjunction, so the per-row half
| of the rule lives in `PesananObatService::untukBaca()`. `dokter` holds NO
| `pesanan.lihat` at all, so a prescriber is refused the parcel they prescribed;
| that follows `ROLE_PERMISSIONS` rather than this file's opinion, and it is why
| no `tipe:` is needed to exclude anyone.
|
| ## `whereNumber` on every `{id}`
|
| `resep.id` (`:743`), `master_obat.id` (`:709`) and `pesanan_obat.id` (`:798`)
| are all `BIGINT UNSIGNED AUTO_INCREMENT` primary keys, so `whereNumber` makes a
| non-numeric segment a router 404 and no request can arrive with `abc` in a
| position the API treats as an identifier.
|
| ## No `Route::resource` and no `Route::apiResource`
|
| The three operations are three distinct verbs and three distinct shapes - a
| POST with a body, a query-string read, and a row-addressed read - so a resource
| route would publish `create`, `update` and `destroy` for an order the DDL
| makes immutable in the ways that matter: there is no `pesanan_obat_item` table
| to update, and a cancelled order is a `dibatalkan` STATUS rather than a
| deletion.
|
| @see \App\Services\PesananObat\PesananObatService
| @see \App\Services\PesananObat\ApotekStokService
| @see \App\Services\PesananObat\PesananObatStateMachine
*/

Route::middleware('auth:sanctum')->group(function (): void {
    // Registered BEFORE the `GET obat` route above could shadow it? It cannot:
    // `obat/{id}/stok` is a three-segment path and `obat` is one, so the
    // ordering is free. Said here because the alternative reads as a
    // consideration that was made rather than one that was not.
    Route::get('obat/{id}/stok', [PesananObatController::class, 'stok'])
        ->whereNumber('id')
        ->name('obat.stok');

    Route::post('resep/{id}/checkout', [PesananObatController::class, 'checkout'])
        ->whereNumber('id')
        ->middleware(['permission:pesanan.buat', 'throttle:checkout'])
        ->name('resep.checkout');

    Route::get('pesanan-obat/{id}', [PesananObatController::class, 'show'])
        ->whereNumber('id')
        ->middleware('permission:pesanan.lihat')
        ->name('pesanan-obat.show');
});

use App\Enums\PembayaranGateway;
use App\Http\Controllers\Api\V1\PembayaranController;

/*
|--------------------------------------------------------------------------
| Module 5 -- payment initiation and the provider webhook
|--------------------------------------------------------------------------
|
| APPENDED by todo 45. Two routes, and the gap between them is the design:
| the first is a patient paying their own invoice, the second is a payment
| provider telling us the money arrived.
|
| | route | auth | guard |
| | --- | --- | --- |
| | `POST invoice/{id}/bayar` | `auth:sanctum` | `permission:pembayaran.bayar` |
| | `POST webhook/payment/{gateway}` | **NONE** | HMAC-SHA256, verified in the service |
|
| ## The webhook is unauthenticated, and that is not an omission
|
| A payment provider is not a user of this system. It holds no Sanctum token
| and cannot be given one, so `auth:sanctum` on this route would 401 every real
| delivery. What stands in for it is the HMAC over the raw body, keyed by the
| named gateway's secret in `config/services.php`, compared with
| `hash_equals`.
|
| The consequence is an ORDERING constraint, and it is why the two routes are
| in one file rather than the webhook being an afterthought: the route
| constrains `{gateway}` to the four `pembayaran.gateway` ENUM members
| (telemedicine_test.sql:965) so the code that reads a secret is unreachable
| for a segment outside them, and the controller verifies the signature before
| it issues any query against `pembayaran` or `invoice`. The test asserts that
| ordering by listening on `QueryExecuted` and requiring zero statements
| naming those tables on a forged delivery.
|
| ## `{gateway}` is CLIENT-SUPPLIED, which is why the constraint is on the ROUTE
|
| `pembayaran.gateway` is `ENUM('midtrans','xendit','doku','flip') NULL` and the
| segment arrives in a URL. Without the constraint, an attacker picks which
| secret gets used. With it, a segment outside the ENUM is a ROUTER 404 that
| never reaches the controller - proved by naming `midtrans2` and `MIDTRANS`,
| both of which 404, and each of the four legal values, none of which does.
|
| ## The initiation gate is `pembayaran.bayar`, and it is granted to `pasien`
|
| `RbacCatalog::PERMISSIONS` holds `pembayaran.bayar` and `ROLE_PERMISSIONS`
| grants it to `pasien` and `superadmin` and to nobody else. So the gate
| refuses `dokter`, `apoteker` and `admin` - none of whom may pay for a
| patient's invoice - without locking out the one account type that owns it.
| `tipe:pasien` is deliberately absent: it answers "which account type is
| this", which cannot express "is this invoice yours", and that is the question
| `PasienRecordAccess::ownPasien()` plus the tenant-scoped lookup answer.
|
| ## `whereNumber` on `{id}`
|
| `invoice.id` is a `BIGINT UNSIGNED AUTO_INCREMENT` primary key (:937), so a
| non-numeric segment is a router 404 rather than an id the API has to defend.
|
| ## No `Route::resource` and no `Route::apiResource`
|
| One initiation and one notification, two distinct shapes, and neither
| operation is CRUD - a payment row is never updated through a resource route
| and is never deleted at all (`pembayaran` has no `dihapus_at`).
|
| @see \App\Services\Payment\PaymentGatewayService the provider contract
| @see \App\Services\Payment\PaymentService the dedupe
*/

Route::post('invoice/{id}/bayar', [PembayaranController::class, 'bayar'])
    ->whereNumber('id')
    ->middleware(['auth:sanctum', 'permission:pembayaran.bayar'])
    ->name('invoice.bayar');

/*
| The read route, appended beside the initiation route it mirrors and carrying
| the SAME guard pair.
|
| ## Why `pembayaran.bayar` guards a READ
|
| `RbacCatalog::PERMISSIONS` holds exactly one payment code, `pembayaran.bayar`
| (:201), and `ROLE_PERMISSIONS` grants it to `pasien` and `superadmin` only
| (:231, :292). There is no `pembayaran.lihat`, and inventing one would be a
| policy change outside this surface: `EnsurePermission` throws a `LogicException`
| - a sanitised 500 - for a code the catalogue does not hold, so an invented code
| here would deny every caller instead of guarding them. Reusing the pay code
| admits exactly the account type that can own an invoice, and it refuses
| `dokter`, `apoteker` and `admin` before the controller runs.
|
| ## The permission answers "may this account pay", not "is this invoice yours"
|
| Ownership is a different question, and it is NOT answered here: it is
| `PasienRecordAccess::ownPasien()` (403 when the caller owns no `pasien` row)
| followed by the tenant-scoped `Invoice::whereBelongsTo($pasien)` fetch in
| `PembayaranController::show()` (404 when the row is not the caller's). The
| explicit `InvoicePolicy` is checked after that fetch as defence in depth, so a
| future edit that drops the scope still fails closed.
|
| ## `whereNumber` on `{id}`
|
| `invoice.id` is a `BIGINT UNSIGNED AUTO_INCREMENT` primary key (:937), so a
| non-numeric segment is a router 404 rather than an id the API has to defend.
|
| @see \App\Policies\InvoicePolicy the explicit rule, and why it is checked second
*/
Route::get('invoice/{id}', [PembayaranController::class, 'show'])
    ->whereNumber('id')
    ->middleware(['auth:sanctum', 'permission:pembayaran.bayar'])
    ->name('invoice.show');

/*
| The webhook is registered OUTSIDE every middleware group on purpose.
|
| It carries no `auth:sanctum` and no `permission:` and no `tipe:`, and the
| `AuthFlowTest` / `PasienProfileTest` route census names it in their
| `$anonymous` set for that reason. Its authentication is the HMAC, and the
| `whereIn` below is compiled by the router into a regex over the four
| `pembayaran.gateway` ENUM values - read from the enum rather than typed, so
| the constraint and the schema cannot drift.
*/
Route::post('webhook/payment/{gateway}', [PembayaranController::class, 'webhook'])
    ->whereIn('gateway', PembayaranGateway::untukRute())
    ->middleware('throttle:webhook-payment')
    ->name('webhook.pembayaran');

use App\Http\Controllers\Api\V1\NotifikasiController;
use App\Http\Controllers\Api\V1\PersetujuanPdpController;

/*
|--------------------------------------------------------------------------
| Module 5 -- PDP consent and the notification centre
|--------------------------------------------------------------------------
|
| APPENDED by todo 47, and extended by F02. Six routes in two groups, and the
| split is the whole design: a person DECIDES, and an inbox TELLS them about it.
|
| | route | auth | guard | writes |
| | --- | --- | --- | --- |
| | `GET pdp/dokumen` | `auth:sanctum` | - | nothing |
| | `POST pdp/persetujuan` | `auth:sanctum` | - | one `persetujuan_pdp` row |
| | `GET pdp/persetujuan` | `auth:sanctum` | - | nothing |
| | `GET notifikasi` | `auth:sanctum` | `permission:notifikasi.lihat` | nothing |
| | `PUT notifikasi/{id}/baca` | `auth:sanctum` | `permission:notifikasi.lihat` | one `dibaca_at` |
| | `PUT notifikasi/baca-semua` | `auth:sanctum` | `permission:notifikasi.lihat` | many `dibaca_at` |
|
| ## `GET pdp/dokumen` is the server's version authority (F02)
|
| The owner's decision: a client must not invent a `versi_dokumen`. This route
| publishes the ACTIVE version and `berlaku_sejak` of each of the five
| documents, read from `config/pdp.php` through `App\Support\Pdp\PdpDokumen`,
| and `POST pdp/persetujuan` refuses any other version with a 422 on
| `versi_dokumen`. `persetujuan_pdp` is an append-only ledger: the current
| status is the latest recorded row per `(user, jenis)`, a withdrawal is allowed
| anytime on the SAME version, and the same consecutive decision is idempotent.
| The `uq_consent` unique key was dropped for this (see `docs/schema-notes.md`).
|
| ## The three consent routes carry NO `permission:`, and the one obvious code is
| ## the one that must not be used here
|
| `RbacCatalog::PERMISSIONS` holds `pdp.kelola`, granted to `admin` and
| `superadmin` and to nobody else. Putting it on a route about the caller's OWN
| consent would lock out every patient, every doctor and every pharmacist - the
| only people whose consents these are. A route with no guard is safe here
| precisely because the service scopes every query to
| `$request->user()->getKey()`: there is no `user_id` in the path, in the body or
| in the query string, so there is no cross-tenant question for a permission to
| answer.
|
| `pdp.kelola` therefore has NO consumer after this todo, and that is deliberate
| rather than forgotten - `PdpNotificationTest` asserts it, so the claim cannot rot
| into a false one. The reason is a compliance one: a route letting an `admin`
| RECORD a data subject's consent would be a defect wearing a permission code,
| because UU PDP asks the person and not their employer. The code stays in the
| catalogue for the future admin READ surface, which is a different route and one
| this todo declines to invent.
|
| `perawat` and `kurir` are the flip side. They are real `users.tipe` values
| (:139) that hold NO role, so any `permission:` would lock them out of these
| routes permanently - so the consent routes are open to them, and the three
| notification routes are not. The asymmetry is asserted by the test, and its fix
| is a data change in `app/Support/Rbac/RbacCatalog.php` plus a re-seed, not a
| change here.
|
| ## The plan says FOUR new routes. There are FIVE, and F02 added a sixth.
|
| The plan's todo 47 acceptance criteria name four and its own prose names five
| (`POST pdp/persetujuan`, `GET pdp/persetujuan`, `GET notifikasi`,
| `PUT notifikasi/{id}/baca`, `PUT notifikasi/baca-semua`). The prose is right and
| the count is an undercount; all five are registered. Recorded rather than
| silently reconciled, because a plan whose arithmetic disagrees with itself is
| something a later executor needs to see rather than be shown a tidied-up
| version of. F02 then appended `GET pdp/dokumen` beside them, for six.
|
| ## `whereNumber` on `{id}`
|
| `notifikasi.id` is a `BIGINT UNSIGNED AUTO_INCREMENT` primary key (:1037), so a
| non-numeric segment is a ROUTER 404 - byte-identical to the 404 an id that does
| not exist, or one belonging to another account, produces, which is the point.
|
| ## `baca-semua` is registered before `{id}/baca` out of reading order, not out of
| ## necessity
|
| They cannot collide: one is two segments and the other three, and `{id}` is
| numeric. The registration order here follows the list order above so a reader
| comparing this block with the table above is not hunting.
|
| ## No `Route::resource`
|
| One decision, one checklist, one list, one stamp and one bulk stamp - five
| distinct verbs, and no destroy anywhere. `notifikasi` has no soft-delete column
| and a notification a client could delete is a record of an event that has not
| been read, which is the one thing this table exists to say.
|
| @see \App\Services\Pdp\PdpConsentService the version rule
| @see \App\Services\Pdp\PerubahanVersiException the revoked-same-version collision
| @see \App\Services\Notifikasi\NotificationService the only notification producer
*/

Route::post('pdp/persetujuan', [PersetujuanPdpController::class, 'store'])
    ->middleware(['auth:sanctum'])
    ->name('pdp.persetujuan.store');

Route::get('pdp/persetujuan', [PersetujuanPdpController::class, 'index'])
    ->middleware(['auth:sanctum'])
    ->name('pdp.persetujuan.index');

// F02's version authority, registered beside the two routes it serves. It is a
// GET with no body, so it needs no FormRequest - the DoD applies to writes.
Route::get('pdp/dokumen', [PersetujuanPdpController::class, 'dokumen'])
    ->middleware(['auth:sanctum'])
    ->name('pdp.dokumen.index');

Route::get('notifikasi', [NotifikasiController::class, 'index'])
    ->middleware(['auth:sanctum', 'permission:notifikasi.lihat'])
    ->name('notifikasi.index');

Route::put('notifikasi/{id}/baca', [NotifikasiController::class, 'baca'])
    ->whereNumber('id')
    ->middleware(['auth:sanctum', 'permission:notifikasi.lihat', 'throttle:notifikasi-baca'])
    ->name('notifikasi.baca');

Route::put('notifikasi/baca-semua', [NotifikasiController::class, 'bacaSemua'])
    ->middleware(['auth:sanctum', 'permission:notifikasi.lihat', 'throttle:notifikasi-baca'])
    ->name('notifikasi.baca-semua');
