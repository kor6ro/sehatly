<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| This project is an API. `/api/v1` is the whole of it, and the stateless `api`
| middleware group in `routes/api.php` is the only group that carries
| application behaviour. What is left here is two things: a health check, which
| `bootstrap/app.php` registers through its `health: '/up'` argument and which
| therefore does not appear in this file at all, and a single catch-all that
| hands the browser to the single-page application.
|
| ## What the catch-all is, and what it deliberately is not
|
| The SPA is a **separate front-end project that happens to live in this
| repository**: `web/`, with its own `package.json`, its own Vite 8 config, its
| own `index.html` and its own `src/`. Todo 5 relocated the UI kit out of
| `resources/js` into `web/src`, and todo 23 built Module 1 against it. It mounts
| on `#root` from `web/src/main.tsx`, it proxies `/api` to this server from
| `web/vite.config.ts:49-54`, and `web/dist` is its build output (gitignored, like
| `public/build`).
|
| So the catch-all does not *build* anything and does not *own* a bundle. It
| serves the bytes `npm run build` in `web/` already produced. That is the whole
| design, and it is the reason the root Vite project could be deleted: there was
| no second bundle to keep alive, and the one that did exist
| (`public/build/manifest.json`, an entry named `resources/js/app.tsx`) had been
| stale since todo 5 - it pointed at `resources/css/app.css` and at twelve
| `resources/js/pages/*.tsx` modules, none of which still exist. Serving that
| would have handed the browser a bundle calling `createInertiaApp()` against an
| Inertia backend that is no longer installed, which is exactly the "the SPA never
| mounts" failure the plan's own note describes.
|
| ## Why the two failures are different statuses
|
| Built: 200 with the real `web/dist/index.html`, so a deep link such as
| `/dashboard/anything` boots the router. Not built: **503**, with the exact
| command to run. A 200 carrying a placeholder page would be a lie a client
| cannot detect; a 503 says the deployment is incomplete, which is what it is.
|
| ## The negative lookahead, and what it protects
|
| `^(?!api|broadcasting|sanctum|up|build|storage|assets).*$` keeps the catch-all
| away from everything the application actually serves. `api` protects the whole
| `/api/v1` tree, so an unknown API path still reaches the 404 JSON envelope in
| `bootstrap/app.php` rather than returning an HTML page - the plan's mandated
| failure scenario for this todo. `up` protects the health check, `build` and
| `storage` protect the two `public/` directories Laravel publishes, and
| `assets` is added to the plan's list because the route below needs to reach
| `web/dist/assets`, which the built `index.html` addresses as absolute
| `/assets/...` URLs.
|
| `broadcasting` and `sanctum` are kept even though neither is registered yet.
| Todo 31 adds `routes/channels.php` and a broadcasting auth endpoint, and
| Sanctum's `sanctum/csrf-cookie` is already listed in `config/cors.php`; a
| catch-all that swallowed either would break the day they arrive.
|
| ## Why the asset route exists at all
|
| Only for the same-origin deployment this file implements. In development the
| SPA is served by `web/`'s own dev server on its own port, and in production a
| static host serves `web/dist` directly - neither involves this application. When
| Laravel does serve the shell, the absolute `/assets/...` URLs in the built HTML
| have to resolve, and `public/` does not contain them. The route is deliberately
| narrow: a single segment of the build, no `..`, no symlink traversal, and a
| 404 for anything that is not a regular file inside `web/dist/assets`.
|
*/

$spaBuild = base_path('web/dist');

Route::get('assets/{path}', function (string $path) use ($spaBuild): Response {
    if ($path === '' || str_contains($path, '..') || str_contains($path, '/') || str_contains($path, '\\')) {
        abort(Response::HTTP_NOT_FOUND);
    }

    $file = $spaBuild.'/assets/'.$path;

    if (! is_file($file)) {
        abort(Response::HTTP_NOT_FOUND);
    }

    return response()->file($file);
})->where('path', '[^/]+')->name('spa.asset');

Route::any('/{any?}', function () use ($spaBuild) {
    $index = $spaBuild.'/index.html';

    if (! is_file($index)) {
        return response()->view('app', [
            'spaBuilt' => false,
            'spaIndex' => $index,
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    $response = response()->file($index);

    // `BinaryFileResponse` derives an ETag and a `Last-Modified` header from the
    // file by default. For a shell that is replaced wholesale by every build, a
    // client that cached the ETag would be told its copy is still current, so both
    // are turned off and the caching instruction is explicit.
    $response->setAutoEtag(false);
    $response->setAutoLastModified(false);
    $response->headers->set('Cache-Control', 'no-store');

    return $response;
})->where('any', '^(?!api|broadcasting|sanctum|up|build|storage|assets).*$')->name('spa.shell');
