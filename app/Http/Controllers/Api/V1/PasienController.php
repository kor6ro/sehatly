<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pasien\IndexAlergiRequest;
use App\Http\Requests\Pasien\StoreAlergiRequest;
use App\Http\Requests\Pasien\UpdateAlergiRequest;
use App\Http\Requests\Pasien\UpdatePasienProfileRequest;
use App\Http\Resources\PasienAlergiResource;
use App\Http\Resources\PasienResource;
use App\Models\Pasien;
use App\Models\PasienAlergi;
use App\Models\User;
use App\Services\Pasien\PasienRecordAccess;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The six patient-self-service routes: the profile and allergies.
 *
 * ## Every route here is a "your own record" route, and the rule is one lookup
 *
 * ```
 * $pasien = $this->access->ownPasien($request->user());   // 403 if there is none
 * $rows   = $this->access->alergiQuery($pasien);          // whereBelongsTo($pasien)
 * $one    = $this->access->alergiOrFail($pasien, $id);    // 404 if it is not found
 * ```
 *
 * That is the whole authorisation model, and it lives in
 * {@see PasienRecordAccess} rather than in this file so that a
 * later todo adding a patient endpoint cannot get it subtly wrong by writing the query a
 * different way. The three lines above are repeated in every action on purpose: they are
 * the three places a mistake would be made, and each of them is one call whose docblock
 * states what it refuses.
 *
 * ## Why there is no `permission:` and no `tipe:` here
 *
 * The full justification is in {@see PasienRecordAccess}'s class docblock, and it is
 * summarised here because a reader looking at the route file deserves the short form:
 *
 * - `RbacCatalog::PERMISSIONS` holds 24 codes and **none** names a patient profile or an
 *   allergy. `EnsurePermission` throws a `LogicException` - a 500 -
 *   for a code that is not in the catalogue, and adding one is a policy change in
 *   `app/Support/Rbac/`, which is not this todo's to make.
 * - `tipe:pasien` would resolve, and is deliberately not used: it answers "which account
 *   type is this" rather than "is this row yours", and a `pasien`-typed account with no
 *   `pasien` row passes it and is then refused by {@see ownPasien()} anyway.
 * - `perawat` and `kurir` are real `users.tipe` values that hold **none** of the
 *   patient-data codes (`perawat` no role at all, `kurir` the delivery portal role),
 *   so any `permission:` code is a permanent lockout for those two account types.
 *
 * ## Deletes are HARD deletes, and the schema forces it
 *
 * `pasien_alergi` declares `dibuat_at` and nothing else (`telemedicine_test.sql:282`) - no
 * `dihapus_at`, no `diubah_at`. Only `users` (`:148`) and `pasien` (`:249`) are
 * soft-deletable, which is why `SoftDeletes` is on those two models and no other. So
 * `DELETE` here removes the row, and the response says exactly that rather than
 * pretending to a soft delete. `pasien_alergi` cascades from `pasien` (`:283`), so
 * deleting a patient profile removes them all - and that is the schema's behaviour,
 * recorded in `docs/schema-notes.md`, not a choice made here.
 *
 * `pasien_anggota_keluarga` (`:269`) is in the same position and has the same cascade
 * (`:270`), but no longer has any route: see the note where its endpoints used to be in
 * `routes/api.php`. Its table and both foreign keys into it are untouched, because
 * `telemedicine_test.sql` cannot be edited.
 *
 * Because `pasien_alergi` cannot record *when* a row went away, and because the API cannot
 * add a column (`telemedicine_test.sql` is read-only law), a delete leaves no trace in
 * these tables. `audit_log` (`:1118`) is where such an event belongs, and its write path is
 * not in this todo's scope; it is named here so the gap is a decision on the record rather
 * than an oversight.
 *
 * ## A 404 for a row that is not the caller's, never a 403
 *
 * The plan states it directly - "receives 404 (not 403, not 200) so existence is not
 * leaked across tenants" - and {@see PasienRecordAccess} carries the
 * full reason. A **403** is what a caller gets when it is not a patient account at all,
 * because that refusal is about the caller and discloses nothing about anyone else's data.
 * The tests cover both, on every mutating route.
 *
 * ## `{id}` is a numeric path segment, constrained in the route file
 *
 * `Route::resource`-style id parameters are not used; each route names `{id}` with
 * `->whereNumber('id')`, so a non-numeric segment is a 404 from the router rather than a
 * validation error, and the controllers take an `int`.
 */
class PasienController extends Controller
{
    public function __construct(
        private readonly PasienRecordAccess $access,
    ) {}

    // =================================================================
    // PUT /api/v1/pasien/profil
    // =================================================================

    /**
     * `GET /api/v1/pasien/profil`
     *
     * The caller's own `pasien` row plus `users.nama_lengkap`, which is why the `user`
     * relation is loaded here: `PasienResource` publishes the name from it, and a profile
     * screen that has to make a second call to `/me` to render a name is a second call.
     */
    public function profilShow(Request $request): JsonResponse
    {
        $pasien = $this->access->ownPasien($this->user($request))->load('user');

        return ApiResponse::success(
            ['profile' => new PasienResource($pasien)],
            'Profil pasien berhasil dimuat.',
        );
    }

    /**
     * `PUT /api/v1/pasien/profil`
     *
     * Two rows in one transaction, because the two halves of a profile edit are one edit:
     * `users.nama_lengkap` (`:135`) and a block of `pasien` columns. A failure between
     * them would leave a user whose display name does not match the patient record the
     * rest of the system books against, and there is no transaction boundary at either
     * table to reconcile them afterwards.
     *
     * The write set is exactly the keys `UpdatePasienProfileRequest::pasienKeys()` returns,
     * which is `validated()` minus the one key that belongs to `users`. Nothing else on
     * either row is touched, so `tipe`, `status`, `no_telepon`, `nik`, `jenis_kelamin`,
     * `tanggal_lahir`, `nomor_rm` and `user_id` cannot move however the payload is built -
     * the class docblock on that request lists all of them and why.
     */
    public function profilUpdate(UpdatePasienProfileRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validated();

        $pasien = DB::transaction(function () use ($request, $user, $validated): Pasien {
            $pasien = $this->access->ownPasien($user);

            if (array_key_exists(UpdatePasienProfileRequest::USERS_KEY, $validated)) {
                $user->nama_lengkap = $validated[UpdatePasienProfileRequest::USERS_KEY];
                $user->save();
            }

            foreach ($request->pasienKeys() as $key) {
                $pasien->{$key} = $validated[$key];
            }

            // `diubah_at` is a real `TIMESTAMP ... ON UPDATE CURRENT_TIMESTAMP` column
            // (`:248`), so the write stamps it; nothing here has to set it, and setting
            // it would be a second source for the same value.
            $pasien->save();

            return $pasien;
        });

        return ApiResponse::success(
            ['profile' => new PasienResource($pasien->load('user'))],
            'Profil berhasil diperbarui.',
        );
    }

    // =================================================================
    // /api/v1/pasien/alergi
    // =================================================================

    /**
     * `GET /api/v1/pasien/alergi`
     *
     * `?page=` / `?per_page=` with the 100 cap and the `meta` block. Ordered by
     * `dibuat_at` then `id`, which is stable and total because `dibuat_at` is a
     * `TIMESTAMP` with second resolution (`:282`) and two allergies recorded in the same
     * second would otherwise page in a non-deterministic order.
     */
    public function alergiIndex(IndexAlergiRequest $request): JsonResponse
    {
        $pasien = $this->access->ownPasien($this->user($request));

        $rows = $this->access
            ->alergiQuery($pasien)
            ->orderBy('dibuat_at')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return ApiResponse::success(
            ['alergi' => PasienAlergiResource::collection($rows->items())],
            'Daftar alergi berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($rows),
        );
    }

    /**
     * `POST /api/v1/pasien/alergi`
     *
     * `pasien_id` and `dicatat_oleh_user_id` are both written server-side. The second is
     * one of the DDL's 23 bare columns (`:281`, no foreign key), so it is read straight
     * from the authenticated account with no relation involved, and the resource publishes
     * the raw id for the reason given in {@see PasienAlergiResource}.
     */
    public function alergiStore(StoreAlergiRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $pasien = $this->access->ownPasien($user);
        $validated = $request->validated();

        $alergi = new PasienAlergi;
        $alergi->pasien_id = $pasien->getKey();
        $alergi->dicatat_oleh_user_id = $user->getKey();

        foreach ($request->alergiKeys() as $key) {
            $alergi->{$key} = $validated[$key];
        }

        $alergi->save();

        // `keparahan` is `NOT NULL DEFAULT 'ringan'` (`:280`) and is left out of the
        // insert entirely when the client omitted it, so the **database** supplies the
        // value - which means the in-memory model does not have it. Eloquent does not read
        // a column back after an insert, so without this `refresh()` a create that omitted
        // `keparahan` would answer `"keparahan": null` while the row says `ringan`. The
        // alternative, restating `'ringan'` in PHP, would make application code a second
        // source of truth for a DDL default.
        $alergi->refresh();

        return ApiResponse::success(
            ['alergi' => new PasienAlergiResource($alergi)],
            'Alergi berhasil ditambahkan.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `PUT /api/v1/pasien/alergi/{id}`
     *
     * Scoped lookup, so another patient's row is a 404 and is never written. A partial
     * update: only the keys present in the body are written, which is why this is a
     * `foreach` over `alergiKeys()` and not a `fill()` - a `PUT` that omitted
     * `keparahan` must leave the stored severity alone rather than nulling it.
     *
     * `dicatat_oleh_user_id` is **not** rewritten on update. It records who first recorded
     * the allergy; a later edit by the same patient is not a second observation, and
     * overwriting it would erase the only trace of who entered the data.
     */
    public function alergiUpdate(UpdateAlergiRequest $request, int $id): JsonResponse
    {
        $pasien = $this->access->ownPasien($this->user($request));
        $validated = $request->validated();

        $alergi = $this->access->alergiOrFail($pasien, $id);

        foreach ($request->alergiKeys() as $key) {
            $alergi->{$key} = $validated[$key];
        }

        $alergi->save();

        return ApiResponse::success(
            ['alergi' => new PasienAlergiResource($alergi)],
            'Alergi berhasil diperbarui.',
        );
    }

    /**
     * `DELETE /api/v1/pasien/alergi/{id}`
     *
     * Scoped lookup, so another patient's row is a 404 and is never deleted. A hard
     * delete, because `pasien_alergi` has no `dihapus_at` - see the class docblock.
     */
    public function alergiDestroy(Request $request, int $id): JsonResponse
    {
        $pasien = $this->access->ownPasien($this->user($request));

        $this->access->alergiOrFail($pasien, $id)->delete();

        return ApiResponse::success(
            ['deleted' => true, 'id' => $id],
            'Alergi berhasil dihapus.',
        );
    }

    /**
     * The authenticated `User`, narrowed for the static analyser.
     *
     * Every action here runs behind `auth:sanctum`, so `user()` is never null; the cast is
     * the framework's documented way to say so rather than an unchecked null.
     */
    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
