<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profil\UpdatePreferensiNotifikasiRequest;
use App\Models\User;
use App\Services\Notifikasi\PreferensiNotifikasiService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The F11 preference surface: read and write the caller's own matrix and quiet
 * hours. Two routes and no create/delete, because a preference row is a
 * singleton per user - an absent row IS the default, so "delete" would mean
 * "restore defaults", which is a value a PUT can already express.
 *
 * `GET` never writes. `PUT` is a lazy upsert through
 * {@see PreferensiNotifikasiService}: only the blocks the caller sent are
 * materialised, and the response is the resulting effective state - so the
 * client can bind its form to the answer instead of assuming the merge.
 *
 * The response body is `data.preferensi`:
 *
 * ```json
 * {
 *   "jam_tenang_aktif": true, "jam_tenang_mode": "setiap_hari",
 *   "jam_tenang_mulai": "21:00", "jam_tenang_selesai": "06:00",
 *   "zona_waktu": "Asia/Jakarta",
 *   "push": {"booking": true, "pembayaran": true, "resep": false, "chat": true}
 * }
 * ```
 *
 * In-app delivery has no field here, deliberately: it is unconditional and the
 * UI explains that rather than offering a switch the server would ignore.
 */
class PreferensiNotifikasiController extends Controller
{
    public function __construct(
        private readonly PreferensiNotifikasiService $service,
    ) {}

    /**
     * `GET /api/v1/profil/notifikasi` - the effective preferences, defaults
     * included when no row exists.
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ['preferensi' => $this->service->efektif($this->user($request))],
            'Preferensi notifikasi berhasil dimuat.',
            Response::HTTP_OK,
        );
    }

    /**
     * `PUT /api/v1/profil/notifikasi` - upsert, then answer the new effective
     * state.
     */
    public function update(UpdatePreferensiNotifikasiRequest $request): JsonResponse
    {
        $preferensi = $this->service->simpan($this->user($request), $request->validated());

        return ApiResponse::success(
            ['preferensi' => $preferensi],
            'Preferensi notifikasi berhasil disimpan.',
            Response::HTTP_OK,
        );
    }

    /**
     * The authenticated `User`, narrowed for the static analyser.
     */
    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
