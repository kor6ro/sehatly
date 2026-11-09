<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\UserStatus;
use App\Models\UserPersetujuan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Rejects a `menunggu_persetujuan` account, moving it to `ditolak`.
 *
 * The rejection reason is written to `user_persetujuan` (append-only ledger)
 * rather than onto `users` directly, so the history is preserved and the
 * `users` table stays within its DDL contract.
 */
final class TolakPengguna
{
    public function jalankan(User $user, string $alasan, User $ditinjauOleh): void
    {
        if (UserStatus::from((string) $user->status) !== UserStatus::MenungguPersetujuan) {
            throw new \DomainException(
                "Akun [{$user->getKey()}] berstatus [{$user->status}], bukan menunggu_persetujuan."
            );
        }

        DB::transaction(function () use ($user, $alasan, $ditinjauOleh): void {
            $user->status = UserStatus::Ditolak->value;
            $user->save();

            UserPersetujuan::create([
                'user_id'      => $user->getKey(),
                'keputusan'    => 'ditolak',
                'alasan'       => $alasan,
                'ditinjau_oleh' => $ditinjauOleh->getKey(),
            ]);
        });
    }
}
