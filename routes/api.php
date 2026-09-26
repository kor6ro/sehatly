<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Registered by `php artisan install:api` and mounted under the `api/v1` prefix
| declared in `bootstrap/app.php` via `apiPrefix`. The generated `/user`
| scaffold was removed instead of being left registered: it is an auth endpoint
| owned by todo 20, it depends on the `HasApiTokens` trait that todo 19 adds to
| the `User` model, and every route registered here has to be reconciled against
| the generated OpenAPI document by todo 53.
|
| Module routes are added by todos 20-45. Each one must return through
| `App\Support\ApiResponse` (or the `Response::apiSuccess()` / `Response::apiError()`
| macros registered in `bootstrap/app.php`) so that every endpoint emits the same
| envelope, and every failure is rendered by the `withExceptions` callback there.
|
| This file intentionally registers no routes. It is kept on disk, rather than
| deleted, so that re-running `php artisan install:api` sees an existing API
| routes file and leaves it untouched.
|
*/
