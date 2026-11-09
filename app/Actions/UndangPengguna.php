<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\Admin\UserUndangan;
use App\Support\Rbac\RoleAssigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates an invited account (status `diundang`) with its role row and an
 * invitation token, all in one transaction.
 *
 * The plaintext token is returned ONCE to the caller so it can be dispatched
 * via the chosen channel (WhatsApp / SMS / email). Only the SHA-256 hash is
 * stored, so a database leak cannot be replayed.
 *
 * Profile rows (dokter, pasien, etc.) are NOT written here. The invitation
 * activation step (`POST /auth/undangan/{token}/aktivasi`) is where the
 * invitee sets their password; profile data is collected on first login.
 * For roles that need credential rows at creation time (e.g. dokter with
 * STR/SIP), the caller should pass a `$profilCallback` that runs inside the
 * same transaction.
 */
final class UndangPengguna
{
    /** Token TTL in hours. */
    public const TTL_JAM = 72;

    /** Roles that may be invited through this action. */
    public const ROLE_DIIZINKAN = ['dokter', 'apoteker', 'kurir', 'admin'];

    public function __construct(private readonly RoleAssigner $roles) {}

    /**
     * @param  array<string, mixed>  $data  Must contain: nama_lengkap, no_telepon, tipe, kanal.
     *                                       Optional: email.
     * @param  callable(User): void|null  $profilCallback  Runs inside the transaction after the
     *                                                      user row is saved, for role-specific
     *                                                      profile rows.
     */
    public function jalankan(array $data, User $dibuatOleh, ?callable $profilCallback = null): UndanganDibuat
    {
        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);

        $user = DB::transaction(function () use ($data, $dibuatOleh, $tokenHash, $profilCallback): User {
            $user = new User;
            $user->nama_lengkap = (string) $data['nama_lengkap'];
            $user->no_telepon = (string) $data['no_telepon'];
            $user->email = isset($data['email']) ? (string) $data['email'] : null;
            $user->kata_sandi_hash = Hash::make(Str::random(32)); // placeholder, replaced at activation
            $user->tipe = (string) $data['tipe'];
            $user->status = UserStatus::Diundang->value;
            $user->telepon_terverifikasi = false;
            $user->save();

            $this->roles->assign((int) $user->getKey(), (string) $data['tipe']);

            if ($profilCallback !== null) {
                $profilCallback($user);
            }

            UserUndangan::create([
                'user_id' => $user->getKey(),
                'token_hash' => $tokenHash,
                'kanal' => (string) $data['kanal'],
                'dibuat_oleh' => $dibuatOleh->getKey(),
                'kedaluwarsa_pada' => now()->addHours(self::TTL_JAM),
            ]);

            return $user;
        });

        return new UndanganDibuat($user, $token);
    }

    /**
     * Re-issue a fresh token for an existing `diundang` account, cancelling
     * the previous one.
     */
    public function kirimUlang(UserUndangan $undangan, User $dibuatOleh): UndanganDibuat
    {
        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);

        DB::transaction(function () use ($undangan, $dibuatOleh, $tokenHash): void {
            // Cancel the old token.
            $undangan->dibatalkan_pada = now();
            $undangan->save();

            UserUndangan::create([
                'user_id' => $undangan->user_id,
                'token_hash' => $tokenHash,
                'kanal' => $undangan->kanal,
                'dibuat_oleh' => $dibuatOleh->getKey(),
                'kedaluwarsa_pada' => now()->addHours(self::TTL_JAM),
            ]);
        });

        /** @var User $user */
        $user = $undangan->user;

        return new UndanganDibuat($user, $token);
    }
}
