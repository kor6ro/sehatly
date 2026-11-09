<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\User;
use App\Models\Admin\UserUndangan;
use Illuminate\Support\Facades\DB;

/**
 * Queries for the invitation surface: token lookup, active invitation list,
 * and the summary badge counts for the account directory tabs.
 */
final class UndanganService
{
    /**
     * Find a valid (unexpired, unused, uncancelled) invitation by plaintext token.
     * Returns null when the token is unknown or no longer valid.
     */
    public function cariToken(string $token): ?UserUndangan
    {
        $hash = hash('sha256', $token);

        return UserUndangan::query()
            ->with('user')
            ->where('token_hash', $hash)
            ->whereNull('dipakai_pada')
            ->whereNull('dibatalkan_pada')
            ->where('kedaluwarsa_pada', '>', now())
            ->first();
    }

    /**
     * The most recent active invitation for a user, or null.
     */
    public function undanganAktif(User $user): ?UserUndangan
    {
        return UserUndangan::query()
            ->where('user_id', $user->getKey())
            ->whereNull('dipakai_pada')
            ->whereNull('dibatalkan_pada')
            ->where('kedaluwarsa_pada', '>', now())
            ->latest()
            ->first();
    }

    /**
     * Cancel all active invitations for a user (e.g. when the account is
     * deactivated or rejected).
     */
    public function batalkanSemua(User $user): void
    {
        UserUndangan::query()
            ->where('user_id', $user->getKey())
            ->whereNull('dipakai_pada')
            ->whereNull('dibatalkan_pada')
            ->update(['dibatalkan_pada' => now()]);
    }

    /**
     * Summary counts for the directory tab badges.
     *
     * Returns an array keyed by `tipe`, each with `total`, `aktif`,
     * `menunggu_persetujuan`, `diundang`, `nonaktif`, `ditolak`.
     *
     * @return array<string, array<string, int>>
     */
    public function ringkasan(): array
    {
        $rows = DB::table('users')
            ->whereNull('dihapus_at')
            ->select('tipe', 'status', DB::raw('COUNT(*) as jumlah'))
            ->groupBy('tipe', 'status')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $tipe = (string) $row->tipe;
            $status = (string) $row->status;

            if (! isset($result[$tipe])) {
                $result[$tipe] = [
                    'total' => 0,
                    'aktif' => 0,
                    'menunggu_persetujuan' => 0,
                    'diundang' => 0,
                    'nonaktif' => 0,
                    'ditolak' => 0,
                    'pending_verifikasi' => 0,
                    'ditangguhkan' => 0,
                ];
            }

            $jumlah = (int) $row->jumlah;
            $result[$tipe]['total'] += $jumlah;

            if (array_key_exists($status, $result[$tipe])) {
                $result[$tipe][$status] += $jumlah;
            }
        }

        return $result;
    }
}
