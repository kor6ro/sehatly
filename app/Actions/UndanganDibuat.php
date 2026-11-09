<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

/**
 * Returned by {@see UndangPengguna}: the created/updated user and the
 * plaintext token that must be dispatched to the invitee.
 *
 * The token is available exactly once here; it is never stored in plaintext.
 */
final readonly class UndanganDibuat
{
    public function __construct(
        public User $user,
        public string $token,
    ) {}
}
