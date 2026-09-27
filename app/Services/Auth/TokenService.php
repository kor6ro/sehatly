<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserRefreshToken;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issues, rotates and revokes the token pair behind the API's authentication.
 *
 * ## The two tokens come from two different tables, and only one of them is Sanctum's
 *
 * | | Table | Written by | Expiry source |
 * | --- | --- | --- | --- |
 * | access | `personal_access_tokens` (registered extra, not in the DDL) | Sanctum, via `$user->createToken()` | `config('sanctum.expiration')` |
 * | refresh | `user_refresh_tokens` (`telemedicine_test.sql:204-212`) | this class | {@see REFRESH_TTL_HARI} |
 *
 * The access token is Sanctum's because `auth:sanctum` is what the routes use and the
 * guard has to recognise it. The refresh token is the contract's own because
 * `user_refresh_tokens` is in the DDL and Sanctum has no second-token concept.
 *
 * ## `user_refresh_tokens` has no `device_id`, and that shapes two methods
 *
 * `:204-212` is `(id, user_id, token_hash, kedaluwarsa_at, dicabut, dibuat_at)`. There
 * is **no `device_id` column and no index and no unique on `token_hash`**, so:
 *
 * - **Per-device revocation is impossible.** `revoke()` can only address a token by its
 *   own secret, and a caller who has lost that secret can revoke nothing. Recorded here
 *   rather than worked around, because a `device_id` column cannot be added: the SQL
 *   file is read-only law.
 * - **Every lookup is a full table scan.** With one row per issued session that is
 *   fine at this scale, but it is a known cost, and the column list is quoted above so
 *   the next reader does not assume an index exists.
 *
 * ## Rotation, and why the lock comes before the read
 *
 * `rotate()` opens a transaction, selects the matching row `FOR UPDATE`, and only then
 * reads `dicabut` -- see {@see rotateWithinTransaction()} for why the order is the
 * design, and {@see rotate()} for why the exception is raised outside the transaction.
 * The row is locked by hash rather than by `user_id` because the presented secret is
 * the only thing that identifies the row, and `token_hash` is not indexed.
 */
final class TokenService
{
    /**
     * Length in characters of the generated refresh token.
     *
     * `Str::random()` draws from a 62-character alphabet, so 80 characters is about 476
     * bits -- far past the point where the secret is the weak link. The length is a
     * literal rather than a config key so that a missing or blank env var cannot
     * silently produce a short token, for the same reason `config/sanctum.php` pins
     * `expiration` to a literal.
     */
    public const REFRESH_TOKEN_PANJANG = 80;

    /**
     * Days a refresh token stays valid.
     *
     * Thirty days, not forever and not for a day. The access token lives
     * `config('sanctum.expiration')` = 1440 minutes, so this is the window in which a
     * user who has not opened the app still gets a silent token refresh rather than a
     * login prompt; it is bounded because the token is a long-lived credential.
     */
    public const REFRESH_TTL_HARI = 30;

    /**
     * Abilities granted to a first-party token.
     *
     * `['*']`: this build has exactly one class of token, issued only after an OTP has
     * been verified, and every authorisation decision is made by `permission:` /
     * `tipe:` from the database rather than from the token. A narrower ability list
     * would have to be kept in step with the RBAC catalogue and would buy nothing,
     * because Sanctum's `tokenCan()` is not consulted by either middleware.
     */
    public const ACCESS_ABILITIES = ['*'];

    /**
     * The Sanctum token name recorded for an issued access token.
     *
     * `user_devices.device_id` is the device string the client sends, so
     * `personal_access_tokens.name` doubles as the device label an administrator reads
     * when deciding which session to revoke.
     */
    public function accessTokenName(?string $deviceId): string
    {
        return $deviceId === null || $deviceId === ''
            ? 'api'
            : 'api:'.$deviceId;
    }

    /**
     * Mint a fresh access + refresh pair for `$user`.
     *
     * Not transactional on purpose: the two writes are independent, and a refresh token
     * whose access-token twin failed would strand the client on a token it cannot use.
     * The refresh row is written first so the failure mode is "the client has a refresh
     * token and no access token" -- recoverable by refreshing -- rather than "the
     * client has a live access token the server has no record of".
     */
    public function issue(User $user, ?string $deviceId = null): AuthTokenPair
    {
        $accessExpiresAt = now()->addMinutes($this->accessTokenMinutes());
        $refreshExpiresAt = now()->addDays(self::REFRESH_TTL_HARI);

        $refresh = $this->storeRefreshToken($user, $refreshExpiresAt);

        $access = $user->createToken(
            $this->accessTokenName($deviceId),
            self::ACCESS_ABILITIES,
            $accessExpiresAt,
        );

        return new AuthTokenPair(
            $access->plainTextToken,
            $accessExpiresAt,
            $refresh,
            $refreshExpiresAt,
        );
    }

    /**
     * Exchange a live refresh token for a new pair, revoking the presented one.
     *
     * ## Why the outcome is returned and the exception is thrown outside the transaction
     *
     * The reuse-detection branch writes -- it revokes every live refresh token for the
     * account -- and then has to fail. Throwing inside `DB::transaction()` rolls the
     * write back, so the one response that must be *accompanied* by a revocation would
     * be the one response that silently un-does it: the stolen token stays live and the
     * client is told nothing. This was a real bug, caught by
     * `AuthFlowTest`'s replay case, which is why the shape is a returned array of
     * `['reason' => ...]` or `['pair' => ...]` and the throw sits below.
     *
     * @throws RefreshTokenRejected
     */
    public function rotate(string $presented): AuthTokenPair
    {
        $outcome = DB::transaction(fn (): array => $this->rotateWithinTransaction($this->hash($presented)));

        if (isset($outcome['reason'])) {
            throw match ($outcome['reason']) {
                RefreshTokenRejected::SUDAH_DICABUT => RefreshTokenRejected::sudahDicabut(),
                RefreshTokenRejected::KEDALUWARSA => RefreshTokenRejected::kedaluwarsa(),
                default => RefreshTokenRejected::tidakDiketahui(),
            };
        }

        return $outcome['pair'];
    }

    /**
     * The body of {@see rotate()}, run inside its transaction.
     *
     * The lock is taken **before** `dicabut` is read. Without it, two concurrent
     * refreshes both observe `dicabut = 0`, both mint a pair, and the reuse detector
     * never fires -- which defeats the entire point of rotation, because the signal that
     * would have caught the theft is the one thing the race suppresses. So the order in
     * this method is the design; do not hoist the `dicabut` read above the lock.
     *
     * The row is locked by hash rather than by `user_id` because the presented secret is
     * the only thing that identifies the row, and `token_hash` carries no index
     * (`:207`).
     *
     * @return array{reason: string}|array{pair: AuthTokenPair}
     */
    private function rotateWithinTransaction(string $hash): array
    {
        $row = UserRefreshToken::query()
            ->where('token_hash', $hash)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            return ['reason' => RefreshTokenRejected::TIDAK_DIKETAHUI];
        }

        if ((bool) $row->dicabut) {
            // Reuse of a spent token. Revoke every live token for this account, because
            // the schema cannot tell which device the presented token belonged to, so
            // there is no narrower correct action available.
            $this->revokeAllForUser((int) $row->user_id);

            return ['reason' => RefreshTokenRejected::SUDAH_DICABUT];
        }

        if ($row->kedaluwarsa_at->lte(now())) {
            return ['reason' => RefreshTokenRejected::KEDALUWARSA];
        }

        $row->dicabut = true;
        $row->save();

        $user = User::query()->findOrFail($row->user_id);

        // The new access token is named `api` rather than the previous device label:
        // `user_refresh_tokens` has no `device_id` column, so there is nothing to read
        // the old name back from. Same schema limitation as the revocation scope above,
        // stated here so nobody reads the reset as an oversight.
        return ['pair' => $this->issue($user)];
    }

    /**
     * Mark the row matching `$presented` as revoked, if there is one.
     *
     * Idempotent and silent on a miss: logout is not an authorisation-sensitive
     * operation, and a client whose refresh token was already invalidated (by a
     * concurrent reuse detection, say) must still be able to end its session.
     */
    public function revoke(string $presented): bool
    {
        return DB::table('user_refresh_tokens')
            ->where('token_hash', $this->hash($presented))
            ->update(['dicabut' => true]) > 0;
    }

    /**
     * Revoke every live refresh token belonging to `$userId`.
     *
     * @return int the number of rows revoked
     */
    public function revokeAllForUser(int $userId): int
    {
        return DB::table('user_refresh_tokens')
            ->where('user_id', $userId)
            ->where('dicabut', false)
            ->update(['dicabut' => true]);
    }

    /**
     * Delete the Sanctum access token the current request is authenticated with.
     *
     * The `instanceof PersonalAccessToken` guard is required, not defensive. Under
     * `Sanctum::actingAs()` the principal's `currentAccessToken()` is a
     * `Laravel\Sanctum\TransientToken`, which is not a model and has no `delete()`; the
     * guard is what lets the test suite exercise every logout assertion with the real
     * controller instead of a stubbed one.
     */
    public function revokeCurrentAccessToken(Request $request): bool
    {
        $accessToken = $request->user()?->currentAccessToken();

        if (! $accessToken instanceof PersonalAccessToken) {
            return false;
        }

        return (bool) $accessToken->delete();
    }

    /**
     * `config('sanctum.expiration')`, in minutes, with the never-expire hole closed.
     *
     * `config/sanctum.php` already pins this to 1440 and explains why a `null` is
     * fatal, so this only refuses to propagate a falsy value if a future edit undoes
     * that. A fallback is used rather than an exception because losing the ability to
     * log in is a worse outcome than a shorter-lived token.
     */
    private function accessTokenMinutes(): int
    {
        $minutes = config('sanctum.expiration');

        return is_int($minutes) && $minutes > 0 ? $minutes : 1440;
    }

    /**
     * Mint a refresh secret and persist only its SHA-256 hash.
     *
     * Returns the plaintext, which the caller must put in exactly one response and
     * nowhere else.
     */
    private function storeRefreshToken(User $user, CarbonInterface $kedaluwarsaAt): string
    {
        $plain = Str::random(self::REFRESH_TOKEN_PANJANG);

        $row = new UserRefreshToken;
        $row->user_id = (int) $user->getKey();
        $row->token_hash = $this->hash($plain);
        $row->kedaluwarsa_at = $kedaluwarsaAt;
        $row->dicabut = false;
        $row->save();

        return $plain;
    }

    /**
     * The stored representation of a refresh token, matching `telemedicine_test.sql`'s
     * "never the plaintext" rule for `kode_hash` (`:182`) applied to `token_hash`.
     */
    private function hash(string $presented): string
    {
        return hash('sha256', $presented);
    }
}
