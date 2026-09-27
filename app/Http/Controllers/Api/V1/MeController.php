<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DokterAkunResource;
use App\Http\Resources\PasienResource;
use App\Http\Resources\UserResource;
use App\Models\Dokter;
use App\Models\Pasien;
use App\Models\User;
use App\Services\Pasien\PasienRecordAccess;
use App\Support\ApiResponse;
use App\Support\NikMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/me` - the authenticated account, and whichever of `pasien` or `dokter` it
 * owns.
 *
 * ## Why the relations are eager-loaded rather than left lazy
 *
 * The plan asks for `pasien` and `dokter` including `dokter_spesialisasi` and
 * `dokter_pendidikan`. Left lazy, one `/me` call would cost a query for the patient row, one
 * for the doctor row, one per specialisation and one per education row - five or six round
 * trips for a single screen, and an N+1 on a client's startup path where it is least
 * welcome on a phone. Everything is therefore loaded up front, in exactly three queries,
 * and {@see DokterAkunResource} publishes `null` for an array whose
 * relation was not loaded, so a future omission is visible rather than silently empty.
 *
 * The three queries are: the patient row with its `user` parent, the doctor row, and the
 * doctor's two child relations. The `users` row itself is already resolved by
 * `auth:sanctum` and costs nothing.
 *
 * ## `pasien` and `dokter` are mutually exclusive in practice, and both are optional
 *
 * `users.tipe` is a seven-value ENUM (`:139`) and only `pasien` and `dokter` own one of
 * these two rows, so a caller normally has exactly one. Nothing enforces that: an account
 * with neither - a `perawat`, a `kurir`, an `admin` - is a legitimate caller of `/me` and
 * gets `null` for both, and an account with both would get both. **`/me` therefore never
 * refuses on the strength of a missing relation.** The patient routes in
 * {@see PasienController} are where a missing `pasien` row is
 * a 403, because there the question is "may you act on a patient record" rather than
 * "what does your account look like".
 *
 * ## `pasien.nik` is masked, never raw
 *
 * Enforced by {@see PasienResource} through
 * {@see NikMasker}, which is where the rule is documented. The test asserts
 * the absence of `kata_sandi_hash` and the presence of a masked NIK in the **serialised
 * body**, not merely in the decoded array, because a field nested somewhere unexpected
 * would be just as much of a leak.
 */
class MeController extends Controller
{
    public function __construct(
        private readonly PasienRecordAccess $access,
    ) {}

    /**
     * `GET /api/v1/me`
     *
     * The relations are attached with `setRelation()` rather than `load()` on the user,
     * because the doctor row is fetched by {@see PasienRecordAccess::ownDokter()} and its
     * two child relations have to be eager-loaded separately. Attaching the result is
     * exactly what `load()` would have done, spelled out so the three queries are visible
     * in this file rather than implied by a nested array literal.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->setRelation('pasien', $this->ownPasien($user));
        $user->setRelation('dokter', $this->ownDokter($user));

        return ApiResponse::success(
            ['user' => new UserResource($user)],
            'Profil berhasil dimuat.',
        );
    }

    /**
     * The caller's own `pasien` row with its `user` parent loaded, or `null`.
     *
     * Reads through the soft-delete scope, so a soft-deleted patient profile reports as
     * "no patient row" rather than as a 500 on the missing relation.
     */
    private function ownPasien(User $user): ?Pasien
    {
        return Pasien::query()
            ->with('user')
            ->where('user_id', $user->getKey())
            ->first();
    }

    /**
     * The caller's own `dokter` row with both child relations loaded, or `null`.
     *
     * `dokter_spesialisasi` is ordered `is_utama` first and then by the master's name; the
     * ordering itself lives in {@see DokterAkunResource} so the
     * response shape and its order cannot disagree, and the query here only eager-loads.
     */
    private function ownDokter(User $user): ?Dokter
    {
        $dokter = $this->access->ownDokter($user);

        if ($dokter === null) {
            return null;
        }

        $dokter->load([
            'dokterSpesialisasi.spesialisasi',
            'dokterPendidikan',
        ]);

        return $dokter;
    }
}
