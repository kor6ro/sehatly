<?php

declare(strict_types=1);

namespace App\Http\Requests\Pasien;

use App\Models\Pasien;
use App\Models\User;
use App\Services\Pasien\PasienRecordAccess;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Base for every `PUT /api/v1/pasien/...` and `POST /api/v1/pasien/...` request.
 *
 * ## Why `authorize()` is `true` here
 *
 * The question these routes actually ask is "does the body belong to this account?", and
 * it is not a question about the request - it is a question about a `pasien` row the
 * request never names. The answer lives in
 * {@see PasienRecordAccess}, which every one of these routes calls
 * before it reads or writes anything, and whose docblock carries the full rule.
 *
 * Returning `false` from `authorize()` would be the framework's supported way to refuse a
 * request, and it would also be wrong here: it produces a bare 403 before the FormRequest
 * validates, so a caller who sent both an unauthorised body *and* a bad enum would be
 * told only that it was unauthorised, and a caller who sent a well-formed body for a row
 * it does not own would be told the same thing as a caller with no `pasien` row at all.
 * The service draws that distinction deliberately - 403 for the caller, 404 for the row -
 * and a gate in front of it would flatten both into one status.
 *
 * `App\Http\Requests\Auth\AuthRequest` reaches the same conclusion by a different route
 * and says so in its own docblock: nobody is authorised by the request itself.
 */
abstract class PasienRequest extends FormRequest
{
    /**
     * Nobody is authorised by the request itself; see the class docblock.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The caller's own `pasien` row, or a 403.
     *
     * Exposed on the request so a controller reads `$request->ownPasien()` rather than
     * injecting the service as a second dependency, and so the ownership check cannot be
     * forgotten at one call site and present at another: the two are the same statement.
     *
     * @throws AccessDeniedHttpException
     */
    protected function ownPasien(): Pasien
    {
        /** @var User $user */
        $user = $this->user();

        return app(PasienRecordAccess::class)->ownPasien($user);
    }
}
