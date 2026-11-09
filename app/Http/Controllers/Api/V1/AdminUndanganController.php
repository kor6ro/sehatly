<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\UndangPengguna;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BuatUndanganRequest;
use App\Http\Requests\Admin\KirimUlangUndanganRequest;
use App\Http\Resources\PenggunaResource;
use App\Models\Admin\UserUndangan;
use App\Services\Admin\UndanganService;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The invitation surface: create, resend and cancel invitation tokens.
 *
 * | route | `permission:` | extra |
 * | --- | --- | --- |
 * | `POST /admin/undangan` | `pengguna.kelola` | inviting `admin` requires `superadmin` actor |
 * | `POST /admin/undangan/{id}/kirim-ulang` | `pengguna.kelola` | - |
 * | `DELETE /admin/undangan/{id}` | `pengguna.kelola` | - |
 *
 * `{id}` is a `user_undangan.id`, not a `users.id`.
 */
class AdminUndanganController extends Controller
{
    public function __construct(
        private readonly UndangPengguna $undangAction,
        private readonly UndanganService $undanganService,
    ) {}

    /**
     * `POST /api/v1/admin/undangan` - create an invited account and issue a token.
     *
     * Answers 201 with `data.pengguna` and `data.token` (the plaintext token,
     * shown once). The token must be dispatched to the invitee via the chosen
     * `kanal`; a notification job should be queued by the caller.
     */
    public function store(BuatUndanganRequest $request): JsonResponse
    {
        $valid = $request->validated();

        if ($this->butuhSuperadminUntukTipe((string) $valid['tipe'], $request)) {
            return ApiResponse::error(
                'Hanya superadmin yang boleh mengundang akun admin.',
                [],
                Response::HTTP_FORBIDDEN,
            );
        }

        try {
            $hasil = $this->undangAction->jalankan($valid, $request->user());
        } catch (QueryException $e) {
            return $this->teleponBentrok($e);
        }

        return ApiResponse::success(
            [
                'pengguna' => new PenggunaResource($hasil->user),
                'token' => $hasil->token,
            ],
            'Undangan berhasil dikirim.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `POST /api/v1/admin/undangan/{id}/kirim-ulang` - cancel the old token and
     * issue a fresh one for the same account.
     *
     * Answers 200 with `data.pengguna` and `data.token`.
     * 404 when the invitation row is unknown.
     * 422 when the invitation has already been used or the account is no longer `diundang`.
     */
    public function kirimUlang(KirimUlangUndanganRequest $request, int $id): JsonResponse
    {
        $undangan = UserUndangan::query()->with('user')->find($id);

        if ($undangan === null) {
            return ApiResponse::error('Resource not found.', [], Response::HTTP_NOT_FOUND);
        }

        if (! $undangan->masihBerlaku()) {
            return ApiResponse::error(
                'The given data was invalid.',
                ['undangan' => ['Undangan sudah dipakai, dibatalkan, atau kedaluwarsa.']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $hasil = $this->undangAction->kirimUlang($undangan, $request->user());

        return ApiResponse::success(
            [
                'pengguna' => new PenggunaResource($hasil->user),
                'token' => $hasil->token,
            ],
            'Undangan berhasil dikirim ulang.',
        );
    }

    /**
     * `DELETE /api/v1/admin/undangan/{id}` - cancel an active invitation.
     *
     * Answers 200 on success, 404 when unknown, 422 when already used/cancelled.
     */
    public function destroy(int $id): JsonResponse
    {
        $undangan = UserUndangan::query()->find($id);

        if ($undangan === null) {
            return ApiResponse::error('Resource not found.', [], Response::HTTP_NOT_FOUND);
        }

        if ($undangan->dipakai_pada !== null) {
            return ApiResponse::error(
                'The given data was invalid.',
                ['undangan' => ['Undangan sudah dipakai dan tidak dapat dibatalkan.']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($undangan->dibatalkan_pada !== null) {
            return ApiResponse::error(
                'The given data was invalid.',
                ['undangan' => ['Undangan sudah dibatalkan sebelumnya.']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $undangan->dibatalkan_pada = now();
        $undangan->save();

        return ApiResponse::success([], 'Undangan berhasil dibatalkan.');
    }

    private function butuhSuperadminUntukTipe(string $tipe, Request $request): bool
    {
        return $tipe === 'admin' && $request->user()?->tipe !== 'superadmin';
    }

    private function teleponBentrok(QueryException $e): JsonResponse
    {
        if ($e->getCode() !== '23000' || ! str_contains($e->getMessage(), 'Duplicate entry')) {
            throw $e;
        }

        if (! str_contains($e->getMessage(), 'users.no_telepon')) {
            throw $e;
        }

        return ApiResponse::error(
            'The given data was invalid.',
            ['no_telepon' => ['Nilai sudah terdaftar.']],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
