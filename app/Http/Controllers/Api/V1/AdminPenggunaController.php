<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BuatPenggunaRequest;
use App\Http\Requests\Admin\IndexPenggunaRequest;
use App\Http\Requests\Admin\ResetSandiPenggunaRequest;
use App\Http\Requests\Admin\UbahStatusPenggunaRequest;
use App\Http\Resources\PenggunaResource;
use App\Models\User;
use App\Services\Admin\AdminPenggunaService;
use App\Support\ApiResponse;
use App\Support\Rbac\RbacCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The account-management surface: `GET /admin/pengguna`,
 * `POST /admin/pengguna`, `PUT /admin/pengguna/{id}/status` and
 * `POST /admin/pengguna/{id}/reset-sandi`.
 *
 * ## This is the account directory the doctor directory is NOT
 *
 * `/admin/dokter` answers credential questions (STR, SIP, verification,
 * schedule) about ONE account type. This one answers the login question about
 * every other row in `users`: who can sign in, with which number, when they
 * last did, and the three operator decisions over that state (create,
 * activate/deactivate, reset a forgotten password). A doctor appears in BOTH
 * surfaces for different questions and neither borrows the other's columns -
 * creating a doctor stays on `POST /admin/dokter`, which owns the `dokter`
 * credential rows this endpoint never writes.
 *
 * ## The guards: party gate + permission, then two actor-vs-target rules
 *
 * | route | `permission:` | extra |
 * | --- | --- | --- |
 * | `GET /admin/pengguna` | `pengguna.lihat` | - |
 * | `POST /admin/pengguna` | `pengguna.kelola` | creating `admin` requires a `superadmin` actor |
 * | `PUT /admin/pengguna/{id}/status` | `pengguna.kelola` | acting on `admin`/`superadmin` rows requires `superadmin`; self -> 422 |
 * | `POST /admin/pengguna/{id}/reset-sandi` | `pengguna.kelola` | acting on `admin`/`superadmin` rows requires `superadmin` |
 *
 * The permission codes are the owner-approved 34th and 35th in
 * {@see RbacCatalog}, granted to `admin` and `superadmin` - the two types that
 * reach `/admin`. The two extra rules are CROSS-FIELD: they compare the
 * caller's `users.tipe` against the target row's, so neither can live in a
 * FormRequest rule (a rule validates the BODY, and neither actor nor `{id}` is
 * in the body). Both are answered here as 403 with an empty `errors` object,
 * the project's uniform refusal shape.
 *
 * Why `admin` is not allowed to mint or demote its own kind: an `admin` that
 * could create `admin`s (or switch one off) would be a privilege escalation
 * path with no second party to it. `superadmin` is seed-only for CREATION -
 * `TIPE_BUAT` refuses it before this rule is even reached - and the
 * actor-vs-target rule additionally stops an `admin` from touching the one
 * account type that can revoke its own grants.
 *
 * ## Self-deactivation is a 422, not a 403
 *
 * The caller may not move THEIR OWN account between `aktif` and `nonaktif`:
 * locking yourself out is always a mistake, and doing it while holding a
 * refresh token you cannot spend would strand a session. It is a 422 on
 * `status` (the field the body asked to change) rather than a 403 because the
 * request is well-formed and authorised - the VALUE is what is refused -
 * which is the same reasoning `IndexAdminDokterRequest` uses for a bad filter.
 *
 * ## `{id}` is a `users.id` and an absent one is the uniform 404
 *
 * The admin UI's rows come from `PenggunaResource`, which publishes `users.id`
 * (not `uuid`), so the number the client sends back is the number this
 * controller resolves. `whereNumber` keeps a non-numeric id out of the query
 * entirely, and a soft-deleted or unknown id is the project's standard
 * `{"success":false,"message":"Resource not found.","errors":{}}`.
 *
 * ## What each write answers
 *
 * All three answer `data.pengguna` through the SAME {@see PenggunaResource}
 * the list publishes (the F14 blast-radius rule: a response is never a
 * differently-shaped row than the list), and `reset-sandi` adds
 * `data.password_baru` - the plaintext exactly once, because the operator must
 * hand it to the holder out of band and the server never stores it. The
 * `users` audit observer fires automatically on every save; `kata_sandi_hash`
 * is in `AuditColumnPolicy`'s credential vocabulary, so the reset is audited as
 * an event without either digest reaching `audit_log`.
 */
class AdminPenggunaController extends Controller
{
    public function __construct(private readonly AdminPenggunaService $pengguna) {}

    /**
     * `GET /api/v1/admin/pengguna` - the filtered directory page.
     *
     * Body: `{tipe?, status?, q?, dibuat_dari?, page?, per_page?}` - `tipe=staf`
     * is the alias the service expands. Answers 200 with `data.pengguna` (the
     * masked projection) and `meta` page fields.
     */
    public function index(IndexPenggunaRequest $request): JsonResponse
    {
        $paginator = $this->pengguna->list($request->validated());

        return ApiResponse::success(
            ['pengguna' => PenggunaResource::collection($paginator->getCollection())],
            'Daftar pengguna berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::pageMeta($paginator),
        );
    }

    /**
     * `POST /api/v1/admin/pengguna` - create a staff account with its role.
     *
     * Body: `{nama_lengkap, no_telepon, password, tipe}`. Answers 201 with
     * `data.pengguna`, 403 when an `admin` tries to create an `admin`, and 422
     * on `no_telepon` for a duplicate - from the rule OR from the race the rule
     * cannot close (MySQL 1062), indistinguishably.
     */
    public function store(BuatPenggunaRequest $request): JsonResponse
    {
        $valid = $request->validated();

        if ($this->butuhSuperadminUntukTipe((string) $valid['tipe'], $request)) {
            return ApiResponse::error(
                'Hanya superadmin yang boleh membuat akun admin.',
                [],
                Response::HTTP_FORBIDDEN,
            );
        }

        try {
            $user = $this->pengguna->buat($valid);
        } catch (QueryException $exception) {
            return $this->teleponBentrok($exception);
        }

        return ApiResponse::success(
            ['pengguna' => new PenggunaResource($user)],
            'Akun pengguna berhasil dibuat.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `PUT /api/v1/admin/pengguna/{id}/status` - activate or deactivate.
     *
     * Body: `{status: aktif|nonaktif}`. Answers 200 with `data.pengguna`,
     * 404 for an unknown id, 403 when an `admin` targets an `admin`/`superadmin`
     * row, and 422 on `status` when the caller targets their OWN account.
     * Deactivating revokes the target's refresh tokens (see
     * {@see AdminPenggunaService::ubahStatus()}).
     */
    public function ubahStatus(UbahStatusPenggunaRequest $request, int $id): JsonResponse
    {
        $user = $this->pengguna->find($id);

        if ($user === null) {
            return ApiResponse::error('Resource not found.', [], Response::HTTP_NOT_FOUND);
        }

        // The SELF refusal runs before the actor-vs-target rule: an `admin`
        // moving their own status is a mistake with a precise message ("do not
        // deactivate yourself"), not an authorisation question about the row's
        // type - and answering it second would hide the real fix behind the
        // superadmin 403.
        if ((int) $user->getKey() === (int) $request->user()?->getAuthIdentifier()) {
            return ApiResponse::error(
                'The given data was invalid.',
                ['status' => ['Anda tidak dapat mengubah status akun sendiri.']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($this->ditolakUntukAktor($user, $request)) {
            return ApiResponse::error(
                'Hanya superadmin yang boleh mengubah akun admin.',
                [],
                Response::HTTP_FORBIDDEN,
            );
        }

        $status = (string) $request->validated('status');

        $this->pengguna->ubahStatus($user, $status);

        return ApiResponse::success(
            ['pengguna' => new PenggunaResource($user)],
            'Status pengguna berhasil diperbarui.',
        );
    }

    /**
     * `POST /api/v1/admin/pengguna/{id}/reset-sandi` - replace the password
     * with a fresh random one.
     *
     * No body. Answers 200 with `data.pengguna` and `data.password_baru` (the
     * plaintext ONCE), 404 for an unknown id, and 403 when an `admin` targets
     * an `admin`/`superadmin` row. The target's refresh tokens are NOT revoked
     * here: a reset is for a holder who lost the password, not one under
     * suspicion, and the next refresh still proves possession of a token the
     * server issued.
     */
    public function resetSandi(ResetSandiPenggunaRequest $request, int $id): JsonResponse
    {
        $user = $this->pengguna->find($id);

        if ($user === null) {
            return ApiResponse::error('Resource not found.', [], Response::HTTP_NOT_FOUND);
        }

        if ($this->ditolakUntukAktor($user, $request)) {
            return ApiResponse::error(
                'Hanya superadmin yang boleh mengatur ulang sandi akun admin.',
                [],
                Response::HTTP_FORBIDDEN,
            );
        }

        $password = $this->pengguna->resetSandi($user);

        return ApiResponse::success([
            'pengguna' => new PenggunaResource($user),
            // Shown once, never retrievable again - the operator copies it to
            // the holder out of band, exactly like the local OTP the login
            // flow prints.
            'password_baru' => $password,
        ], 'Kata sandi berhasil diatur ulang.');
    }

    /**
     * The creation half of the actor-vs-target rule: minting an `admin` needs a
     * `superadmin` caller. `TIPE_BUAT` has already refused every other
     * supervised type, so `admin` is the only value that reaches here.
     */
    private function butuhSuperadminUntukTipe(string $tipe, Request $request): bool
    {
        return $tipe === 'admin'
            && $request->user()?->tipe !== 'superadmin';
    }

    /**
     * The mutation half: an `admin` caller may not act on an `admin` or
     * `superadmin` row (see the class docblock for why).
     */
    private function ditolakUntukAktor(User $user, Request $request): bool
    {
        if (! in_array((string) $user->tipe, AdminPenggunaService::TIPE_PENGAWASAN, true)) {
            return false;
        }

        return $request->user()?->tipe !== 'superadmin';
    }

    /**
     * Turn a unique-key violation on `users.no_telepon` into the same 422 the
     * validation rule produces, so the race and the check are indistinguishable
     * - the shape `AdminPasienController::teleponBentrok()` established for the
     * support path, and `AuthController::duplicateIdentityResponse()` before it.
     *
     * A 1062 the message does not attribute to `users.no_telepon` (the `email`
     * unique index, say) is re-thrown rather than mis-reported as a phone
     * collision.
     */
    private function teleponBentrok(QueryException $exception): JsonResponse
    {
        $message = $exception->getMessage();

        if ($exception->getCode() !== '23000' || ! str_contains($message, 'Duplicate entry')) {
            throw $exception;
        }

        if (! str_contains($message, 'users.no_telepon')) {
            throw $exception;
        }

        return ApiResponse::error(
            'The given data was invalid.',
            ['no_telepon' => ['Nilai sudah terdaftar.']],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
