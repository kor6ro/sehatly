<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Support\NikMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the account directory: `GET /admin/pengguna` and the confirmation
 * of the two writes.
 *
 * ## The phone is masked here for the same reason it is masked in the audit log
 *
 * The list is the widest projection of `users.no_telepon` in the product - every
 * account, every row - so it publishes {@see NikMasker} output and never the raw
 * number. The operator who needs to confirm a number reads enough of it to
 * recognise ("0812***3456, that's the one"), and no screenshot, proxy log or
 * response body of this endpoint carries the credential the login OTP is sent
 * to. Search still matches the STORED value (see
 * {@see \App\Services\Admin\AdminPenggunaService}), so typing the real number
 * finds the row the masked column then displays.
 *
 * ## What is deliberately absent
 *
 * - `kata_sandi_hash` - a digest is a credential; the audit policy denies it
 *   outright and so does this allow-list, for the same reason.
 * - `uuid`, `bahasa`, `email_terverifikasi`, `foto_profil` - nothing on this
 *   surface reads them, and an allow-list that publishes a column because it
 *   exists is how a projection grows a field nobody meant to expose. `email` IS
 *   published because it is one of the two login identifiers
 *   (`AuthRequest` accepts either), and an operator asking "which accounts have
 *   no email on file" is a support question with no other answer.
 *
 * `last_login_at` is `null` for an account that has never signed in - a
 * different fact from "signed in long ago", and the UI renders it as such.
 */
class PenggunaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->getKey(),
            'nama_lengkap' => $user->nama_lengkap,
            'no_telepon' => NikMasker::mask((string) $user->no_telepon),
            'email' => $user->email,
            'tipe' => $user->tipe,
            'status' => $user->status,
            'telepon_terverifikasi' => (bool) $user->telepon_terverifikasi,
            'last_login_at' => $user->last_login_at?->toISOString(),
            'dibuat_at' => $user->dibuat_at?->toISOString(),
        ];
    }
}
