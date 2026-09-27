<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Konsultasi\KonsultasiChannelAccess;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channel Authorization
|--------------------------------------------------------------------------
|
| Registered by `ApplicationBuilder::withBroadcasting()` in `bootstrap/app.php`,
| which `require`s this file on boot and pairs it with `Broadcast::routes()` at
| `POST /api/broadcasting/auth`. The two halves have to be declared together:
| the route validates the caller and the pattern below decides the channel.
|
| ## The wire name and the pattern are different strings
|
| A client subscribes to `private-konsultasi.{id}`; the `private-` prefix is
| the client's, and the server strips it before matching
| (`UsePusherChannelConventions::normalizeChannelName`). The pattern is
| therefore written **without** the prefix, and a channel declared here as
| `private-konsultasi.{id}` would never match anything - it would be stored
| under a name no incoming request is ever normalised to, and every
| authorization would fall through to `AccessDeniedHttpException`.
|
| The pattern is namespaced on purpose. `konsultasi.{id}` is the only channel
| this application authorizes, and a `{id}`-only channel would authorize any
| authenticated user against any string in the app.
|
*/

/*
| The consultation transcript. Authorized for the consultation's patient (via
| `pasien.user_id`) and its doctor (via `dokter.user_id`) and nobody else.
|
| The parameter is `int|string`, not the `int` the plan snippet shows, and the
| reason is documented in `KonsyultationChannelAccess`: the wildcard arrives as a string,
| so an `int` parameter turns a malformed id into a `TypeError` and a 500 rather
| than a 403. Returning a bool rather than throwing is what lets the framework
| answer 403 itself.
|
*/
Broadcast::channel('konsultasi.{id}', function (User $user, int|string $id): bool {
    return app(KonsultasiChannelAccess::class)->allows($user, $id);
});
