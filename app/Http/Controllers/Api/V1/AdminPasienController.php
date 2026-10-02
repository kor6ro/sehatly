<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UbahTeleponPasienRequest;
use App\Models\Pasien;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\NikMasker;
use App\Support\Rbac\RbacCatalog;
use App\Support\Telepon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * F01's owner-approved support path for a patient's registered phone number:
 * `PUT /api/v1/admin/pasien/{id}/telepon`.
 *
 * ## Why this endpoint exists, and what it is NOT
 *
 * F01's pattern records the phone-change flow as an open question (section 12 item 4)
 * and the owner settled it for this phase as **support only**: there is no
 * self-service change, no OTP-to-old-number + OTP-to-new-number flow, and this
 * controller deliberately does not grow one. A patient who changed handsets is
 * helped by an admin who has verified their identity out of band; the number then
 * works for the next password + OTP login because login sends the code to
 * `users.no_telepon`.
 *
 * The endpoint is guarded by the route's `auth:sanctum`, `tipe:admin,superadmin` and
 * `permission:pasien.kelola`. The permission code is F01's owner-approved addition
 * to {@see RbacCatalog}: `pdp.kelola` was the closest existing
 * grant but its surface is documented as the read-only consent ledger, and reusing a
 * read-shaped grant on an identity mutation is the silent widening the catalogue
 * avoids. `pasien.kelola` is granted to `admin` and `superadmin` only.
 *
 * ## Two identifiers, one row
 *
 * The route addresses a PATIENT (`pasien.id`) because that is what an admin sees in
 * a support ticket, while the phone lives on `users` (`:137`); this class resolves
 * one to the other and writes only the user's `no_telepon`. Both lookups respect
 * their models' soft-delete scopes, so a soft-deleted patient or account is the same
 * 404 as an unknown id - the project's standard
 * `{"success":false,"message":"Resource not found.","errors":{}}` body.
 *
 * ## Normalisation, uniqueness and the race
 *
 * `UbahTeleponPasienRequest` folds `+62…`/`62…` through {@see Telepon}
 * before validation and refuses a number held by a different account with a 422 on
 * `no_telepon`. The `unique` rule is a check, so the write additionally catches the
 * MySQL 1062 the rule cannot close (two requests for the same number passing
 * validation together) and answers the SAME 422 rather than a 500 - the shape
 * `AuthController::duplicateIdentityResponse()` established for registration.
 *
 * ## What is written, and what is deliberately not
 *
 * - `no_telepon` is replaced with the canonical value only.
 * - `telepon_terverifikasi` is set to `true`: the support path IS the identity check,
 *   and leaving the flag as the OLD number's state would assert something about the
 *   new one that is no longer true. Clearing it would mark a number that support just
 *   verified as unverified until the next OTP login; setting it records the fact that
 *   a human verified the holder before the change.
 * - The audit trail is AUTOMATIC: `users` is in `AuditScope`, so the `save()` above
 *   fires the global observer, which writes an `update` row through
 *   `AuditLogWriter`. `AuditColumnPolicy` masks `no_telepon` (four digits, bullets,
 *   four digits) and never stores the full number in `audit_log`.
 * - **No notification row is written.** `NotificationService`'s producer vocabulary
 *   is the four transactional events F3 wired and `NotifikasiTipe::nilaiYangDipakai()`
 *   is asserted as exactly those, so inventing a fifth producer here would widen that
 *   contract. The confirmation the caller receives is the response below, and the
 *   patient's next login OTP goes to the new number.
 * - **No full number is returned.** The response publishes the new number MASKED, so
 *   even the operator who typed it gets the same redaction an audit reader gets, and
 *   no log, message or toast in this path ever carries the full value.
 */
class AdminPasienController extends Controller
{
    /**
     * `PUT /api/v1/admin/pasien/{id}/telepon`
     *
     * Body: `{no_telepon: string}` - `+62…`/`62…` accepted and folded to `08…`.
     * Answers 200 with the masked number, 404 for an unknown or soft-deleted patient,
     * and 422 when the number is malformed or already belongs to another account.
     */
    public function ubahTelepon(UbahTeleponPasienRequest $request, int $id): JsonResponse
    {
        $pasien = Pasien::query()->find($id);

        if ($pasien === null) {
            return ApiResponse::error('Resource not found.', [], Response::HTTP_NOT_FOUND);
        }

        $user = User::query()->find($pasien->user_id);

        if ($user === null) {
            // A soft-deleted account resolves to no row under the model's global
            // scope; correcting the phone of an account the holder asked to be gone
            // is not this endpoint's business, so it is the same 404.
            return ApiResponse::error('Resource not found.', [], Response::HTTP_NOT_FOUND);
        }

        $nomor = (string) $request->validated('no_telepon');

        try {
            DB::transaction(function () use ($user, $nomor): void {
                $user->no_telepon = $nomor;
                $user->telepon_terverifikasi = true;
                $user->save();
            });
        } catch (QueryException $exception) {
            return $this->teleponBentrok($exception);
        }

        return ApiResponse::success([
            'pasien' => [
                'id' => (int) $pasien->getKey(),
                'user_id' => (int) $user->getKey(),
                // The full number was the caller's own input; echoing it back adds
                // nothing and would put it in one more response body. The same masker
                // the audit policy uses keeps the confirmation and the trail
                // consistent.
                'no_telepon' => NikMasker::mask($nomor),
            ],
        ], 'Nomor telepon berhasil diperbarui.');
    }

    /**
     * Turn a unique-key violation on `users.no_telepon` into the same 422 the
     * validation rule produces, so the race and the check are indistinguishable.
     *
     * SQLSTATE 23000 with "Duplicate entry" is MySQL's 1062 on a unique index. A
     * 1062 the message does not attribute to `users.no_telepon` is re-thrown rather
     * than mis-reported as a phone collision - same reasoning as
     * `AuthController::duplicateIdentityResponse()`.
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
