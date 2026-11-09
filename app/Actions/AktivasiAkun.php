<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\Admin\UserUndangan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Activates an invited account: consumes the invitation token, sets the
 * invitee's chosen password, and moves the account from `diundang` to `aktif`.
 *
 * Called from `POST /auth/undangan/{token}/aktivasi` after OTP verification.
 * The token lookup and expiry check happen BEFORE this action is called; this
 * action only performs the state transition.
 */
final class AktivasiAkun
{
    public function jalankan(User $user, UserUndangan $undangan, string $passwordBaru): void
    {
        if (UserStatus::from((string) $user->status) !== UserStatus::Diundang) {
            throw new \DomainException(
                "Akun [{$user->getKey()}] berstatus [{$user->status}], bukan diundang."
            );
        }

        DB::transaction(function () use ($user, $undangan, $passwordBaru): void {
            $undangan->dipakai_pada = now();
            $undangan->save();

            $user->kata_sandi_hash = Hash::make($passwordBaru);
            $user->status = UserStatus::Aktif->value;
            $user->telepon_terverifikasi = true;
            $user->save();
        });
    }
}
