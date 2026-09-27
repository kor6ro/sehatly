<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserOtp;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Mints, supersedes and consumes the `user_otp` rows behind the token + OTP login.
 *
 * ## What the DDL gives and what it withholds
 *
 * `telemedicine_test.sql:179-188`:
 *
 * ```
 * user_otp (
 *   id BIGINT UNSIGNED AUTO_INCREMENT,
 *   user_id BIGINT UNSIGNED NOT NULL,      -- FK users(id) ON DELETE CASCADE
 *   kode_hash VARCHAR(255) NOT NULL,       -- COMMENT 'Simpan hash, bukan OTP asli'
 *   tujuan ENUM('verifikasi_telepon','verifikasi_email','reset_kata_sandi','login') NOT NULL,
 *   kedaluwarsa_at DATETIME NOT NULL,
 *   sudah_dipakai TINYINT(1) NOT NULL DEFAULT 0,
 *   dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
 * )
 * ```
 *
 * Three consequences shape every method below.
 *
 * 1. **`kode_hash` stores a hash, never the plaintext.** The column is 255 characters
 *    and its own comment says so. The plaintext never reaches the database, so this
 *    class is the only place that holds it, and {@see IssuedOtp} is what keeps it
 *    from being published.
 * 2. **There is no `dibatalkan_at`, no `percobaan` and no `ip`.** Superseding a code
 *    therefore has exactly one available representation: closing its validity window.
 *    There is also no attempt counter, so OTP brute-force protection is
 *    application-only and lives in the `throttle:auth-otp-verify` rate limiter. Both
 *    facts are recorded here so a later todo does not go looking for a column that
 *    does not exist.
 * 3. **There is no unique index at all** -- not on `(user_id, tujuan)`, not on
 *    `kode_hash`. So nothing in the database stops two live codes for the same purpose,
 *    and nothing stops a hash collision being inserted twice. Both are handled in
 *    application code, and both are stated here rather than assumed.
 *
 * ## Why the hash is SHA-256 and not bcrypt
 *
 * A six-digit code has 10^6 possible values, so a slow KDF buys nothing an attacker
 * does not already have: a hash of the entire code space is computed in milliseconds
 * on one GPU, and the row is fetchable by anyone with read access to `user_otp`.
 * Fast hashing is used for a different reason -- {@see consume()} has to compare the
 * presented code against every candidate row inside a `SELECT ... FOR UPDATE`, and a
 * per-row KDF would turn one request into a second of CPU work. What protects the
 * code is therefore the five-minute window plus the five-per-minute rate limit, and
 * this class says so rather than implying the hash is a defence on its own.
 *
 * Passwords are a different problem and are hashed with bcrypt: `users.kata_sandi_hash`
 * is a `password_hash()` digest and is never compared by this class.
 */
final class OtpService
{
    /** `user_otp.tujuan` ENUM value: the phone number is being proved. */
    public const TUJUAN_VERIFIKASI_TELEPON = 'verifikasi_telepon';

    /** `user_otp.tujuan` ENUM value: the email address is being proved. */
    public const TUJUAN_VERIFIKASI_EMAIL = 'verifikasi_email';

    /** `user_otp.tujuan` ENUM value: a password reset code. */
    public const TUJUAN_RESET_KATA_SANDI = 'reset_kata_sandi';

    /** `user_otp.tujuan` ENUM value: second factor of the login flow. */
    public const TUJUAN_LOGIN = 'login';

    /**
     * The four `user_otp.tujuan` values, verbatim from `telemedicine_test.sql:183`
     * and in the DDL's order.
     *
     * This is the single definition; the `VerifyOtpRequest` validation rule reads it
     * rather than restating the list, so a value can never be accepted by an endpoint
     * and rejected by the column.
     *
     * @var list<string>
     */
    public const TUJUAN = [
        self::TUJUAN_VERIFIKASI_TELEPON,
        self::TUJUAN_VERIFIKASI_EMAIL,
        self::TUJUAN_RESET_KATA_SANDI,
        self::TUJUAN_LOGIN,
    ];

    /** The code is exactly this many digits, zero-padded. */
    public const KODE_DIGIT = 6;

    /** Minutes from issue until `kedaluwarsa_at`. */
    public const TTL_MENIT = 5;

    /**
     * The two purposes this todo issues codes for.
     *
     * `verifikasi_email` and `reset_kata_sandi` are in {@see TUJUAN} because the DDL
     * declares them, so a FormRequest can accept them and future todos can use them
     * without touching the schema. This todo mints neither: there is no email-verified
     * endpoint (the column is the boolean `email_terverifikasi`, not a timestamp) and
     * no password-reset endpoint in the Modul 1 endpoint table.
     *
     * @var list<string>
     */
    public const TUJUAN_DI_TERBITKAN = [
        self::TUJUAN_VERIFIKASI_TELEPON,
        self::TUJUAN_LOGIN,
    ];

    public function __construct(
        private readonly OtpSender $sender,
    ) {}

    /**
     * Mint a code for `$user`, supersede any prior unused code for the same purpose,
     * and hand the plaintext to the sender.
     *
     * ## Supersession is by closing the validity window
     *
     * The DDL has no `dibatalkan_at`, so a prior code cannot be flagged as cancelled.
     * Setting its `kedaluwarsa_at` to *now* is the honest representation of "this code
     * is no longer valid": {@see consume()} rejects it through the same expiry branch
     * that a genuinely timed-out code takes, so there is one expiry rule rather than
     * two. It is done with a single statement through the query builder rather than
     * through the model because `UserOtp::UPDATED_AT` is `null`; a bulk model update
     * would have to be proven not to add a `diubah_at` column, and the query builder
     * cannot.
     *
     * The supersede and the insert share a transaction, so a crash between them leaves
     * either the old code or the new one, never neither.
     */
    public function issue(User $user, string $tujuan, ?string $penerima = null): IssuedOtp
    {
        if (! in_array($tujuan, self::TUJUAN_DI_TERBITKAN, true)) {
            throw new LogicException(
                "OtpService::issue() was asked for a `tujuan` of [{$tujuan}], which this todo does not "
                .'issue. The full DDL enum is: '.implode(', ', self::TUJUAN).'.'
            );
        }

        $kode = $this->randomKode();
        $kedaluwarsaAt = now()->addMinutes(self::TTL_MENIT);

        DB::transaction(function () use ($user, $tujuan, $kode, $kedaluwarsaAt): void {
            DB::table('user_otp')
                ->where('user_id', $user->getKey())
                ->where('tujuan', $tujuan)
                ->where('sudah_dipakai', false)
                ->update(['kedaluwarsa_at' => now()]);

            $otp = new UserOtp;
            $otp->user_id = (int) $user->getKey();
            $otp->kode_hash = $this->hash($kode);
            $otp->tujuan = $tujuan;
            $otp->kedaluwarsa_at = $kedaluwarsaAt;
            $otp->sudah_dipakai = false;
            $otp->save();
        });

        $this->sender->send($penerima ?? (string) $user->no_telepon, $kode, $tujuan);

        return new IssuedOtp((int) $user->getKey(), $tujuan, $kode, $kedaluwarsaAt);
    }

    /**
     * Accept `$kode` for `$user` and `$tujuan`, marking the row used, or throw
     * {@see OtpRejected}.
     *
     * ## Why the candidate rows are locked before `sudah_dipakai` is read
     *
     * The single-use guarantee is the whole security value of a one-time code: a code
     * that verifies a login twice is two passwords' worth of exposure. Reading
     * `sudah_dipakai` without a lock lets two concurrent requests both observe `0` and
     * both mint a token pair, which is the same defect the plan calls out for refresh
     * rotation and for the same reason. So the candidates for `(user_id, tujuan)` are
     * selected `FOR UPDATE` inside a transaction, and the flip to `sudah_dipakai = 1`
     * happens under that lock. The set is bounded by the number of codes ever issued
     * for one purpose and one user, which the supersession rule keeps at one live row.
     *
     * ## Why the search is "newest row whose hash matches", not "newest unused row"
     *
     * A candidate set filtered to `sudah_dipakai = 0` cannot tell a replayed code from
     * a wrong one: both simply fail to match. Matching across every row for the pair
     * lets the three rejections stay distinct, which is what the client needs in order
     * to say "this code already worked" instead of "that code is wrong".
     *
     * ## The check order is load-bearing
     *
     * `sudah_dipakai` is tested before `kedaluwarsa_at`. A replay inside the five
     * minute window is then reported as a replay rather than as an expiry, which is
     * the more urgent of the two signals. Both orders reject, so this is a diagnostic
     * ordering, not a security ordering.
     */
    public function consume(User $user, string $tujuan, string $kode): UserOtp
    {
        return DB::transaction(function () use ($user, $tujuan, $kode): UserOtp {
            $candidates = UserOtp::query()
                ->where('user_id', $user->getKey())
                ->where('tujuan', $tujuan)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            $hash = $this->hash($kode);
            $match = null;

            foreach ($candidates as $candidate) {
                if (hash_equals((string) $candidate->kode_hash, $hash)) {
                    $match = $candidate;

                    break;
                }
            }

            if ($match === null) {
                throw OtpRejected::tidakDiketahui();
            }

            if ((bool) $match->sudah_dipakai) {
                throw OtpRejected::sudahDipakai();
            }

            if ($match->kedaluwarsa_at->lte(now())) {
                throw OtpRejected::kedaluwarsa();
            }

            $match->sudah_dipakai = true;
            $match->save();

            return $match;
        });
    }

    /**
     * How many unused, unexpired codes `$user` currently holds for `$tujuan`.
     *
     * Supersession is expected to hold this at one. It is exposed so a test can assert
     * that property directly instead of inferring it from a 422.
     */
    public function liveCodeCount(User $user, string $tujuan): int
    {
        return UserOtp::query()
            ->where('user_id', $user->getKey())
            ->where('tujuan', $tujuan)
            ->where('sudah_dipakai', false)
            ->where('kedaluwarsa_at', '>', now())
            ->count();
    }

    /**
     * The stored representation of a code. The only place the hash is computed.
     */
    public function hash(string $kode): string
    {
        return hash('sha256', $kode);
    }

    /**
     * A cryptographically random, zero-padded, exactly {@see KODE_DIGIT}-digit code.
     *
     * `random_int` is the CSPRNG and is used rather than `random_int($min, $max)`
     * directly because it needs no bias handling: the range is exactly
     * 1,000,000 = 2^6 x 5^6, and `str_pad` restores the leading zeros that an integer
     * draw would have dropped. A code may therefore start with `0`, and a client that
     * treated the code as an integer would send a five-digit code that can never match.
     */
    private function randomKode(): string
    {
        $upper = (10 ** self::KODE_DIGIT) - 1;

        return str_pad((string) random_int(0, $upper), self::KODE_DIGIT, '0', STR_PAD_LEFT);
    }
}
