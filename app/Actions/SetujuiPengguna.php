<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\UserStatus;
use App\Models\UserPersetujuan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Approves a `menunggu_persetujuan` account, moving it to `aktif`.
 *
 * Intended for marketing self-registrations. The approval is recorded in
 * `user_persetujuan` (append-only ledger). The audit row on `users` is
 * written automatically by `AuditObserver`.
 */
final class SetujuiPengguna
{
    public function jalankan(User $user, User $ditinjauOleh): void
    {
        if (UserStatus::from((string) $user->status) !== UserStatus::MenungguPersetujuan) {
            throw new \DomainException(
                "Akun [{$user->getKey()}] berstatus [{$user->status}], bukan menunggu_persetujuan."
            );
        }

        DB::transaction(function () use ($user, $ditinjauOleh): void {
            $user->status = UserStatus::Aktif->value;
            $user->telepon_terverifikasi = true;
            $user->save();

            UserPersetujuan::create([
                'user_id'      => $user->getKey(),
                'keputusan'    => 'disetujui',
                'alasan'       => null,
                'ditinjau_oleh' => $ditinjauOleh->getKey(),
            ]);
        });
    }
}
