<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\AktivasiAkun;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AktivasiUndanganRequest;
use App\Http\Requests\Auth\DaftarMarketingRequest;
use App\Http\Resources\PenggunaResource;
use App\Models\User;
use App\Services\Admin\UndanganService;
use App\Services\Auth\OtpRejected;
use App\Services\Auth\OtpService;
use App\Support\ApiResponse;
use App\Support\Rbac\RoleAssigner;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anonymous endpoints for the invitation and self-registration flows.
 *
 * | route | auth | what it does |
 * | --- | --- | --- |
 * | `POST /auth/daftar/marketing` | none | self-register, status `menunggu_persetujuan` |
 * | `POST /auth/undangan/{token}/aktivasi` | none | set password, status `diundang` -> `aktif` |
 */
class UndanganAktivasiController extends Controller
{
    public function __construct(
        private readonly UndanganService $undanganService,
        private readonly OtpService $otp,
        private readonly AktivasiAkun $aktivasiAction,
        private readonly RoleAssigner $roles,
    ) {}

    /**
     * `POST /api/v1/auth/daftar/marketing`
     *
     * Creates a `marketing` account with status `menunggu_persetujuan`.
     * The account cannot log in until an admin approves it.
     * Answers 201 with `data.pengguna`.
     */
    public function daftarMarketing(DaftarMarketingRequest $request): JsonResponse
    {
        $valid = $request->validated();

        try {
            $user = DB::transaction(function () use ($valid): User {
                $user = new User;
                $user->nama_lengkap = (string) $valid['nama_lengkap'];
                $user->no_telepon = (string) $valid['no_telepon'];
                $user->email = isset($valid['email']) ? (string) $valid['email'] : null;
                $user->kata_sandi_hash = Hash::make((string) $valid['password']);
                $user->tipe = 'marketing';
                $user->status = UserStatus::MenungguPersetujuan;
                $user->telepon_terverifikasi = false;
                $user->save();

                $this->roles->assign((int) $user->getKey(), 'marketing');

                return $user;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'users.no_telepon')) {
                return ApiResponse::error(
                    'The given data was invalid.',
                    ['no_telepon' => ['Nilai sudah terdaftar.']],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            throw $e;
        }

        return ApiResponse::success(
            ['pengguna' => new PenggunaResource($user)],
            'Pendaftaran berhasil. Akun Anda sedang menunggu persetujuan admin.',
            Response::HTTP_CREATED,
        );
    }

    /**
     * `POST /api/v1/auth/undangan/{token}/aktivasi`
     *
     * Validates the invitation token, verifies the OTP, then activates the account.
     * Answers 200 with `data.pengguna`.
     *
     * Error responses:
     * - 404: token unknown or expired
     * - 422 on `otp`: code wrong, already used, or expired
     */
    public function aktivasi(AktivasiUndanganRequest $request, string $token): JsonResponse
    {
        $undangan = $this->undanganService->cariToken($token);

        if ($undangan === null) {
            return ApiResponse::error(
                'Token undangan tidak valid atau sudah kedaluwarsa.',
                [],
                Response::HTTP_NOT_FOUND,
            );
        }

        /** @var User $user */
        $user = $undangan->user;

        $valid = $request->validated();

        try {
            $this->otp->consume($user, OtpService::TUJUAN_VERIFIKASI_TELEPON, (string) $valid['otp']);
        } catch (OtpRejected $e) {
            return ApiResponse::error(
                'The given data was invalid.',
                ['otp' => [$e->getMessage()]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->aktivasiAction->jalankan($user, $undangan, (string) $valid['password']);

        return ApiResponse::success(
            ['pengguna' => new PenggunaResource($user->fresh())],
            'Akun berhasil diaktivasi.',
        );
    }
}
