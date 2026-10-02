<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Support\Telepon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The one place that derives the `auth-otp-verify` rate-limiter key, so the
 * endpoint can report how many attempts a code has left.
 *
 * ## Why this class exists
 *
 * `user_otp` has **no attempt-counter column**, so the only bound on guessing a
 * six-digit code is the named limiter `auth-otp-verify` in
 * `AppServiceProvider` - five attempts per issued code, keyed on the code's own
 * row id, burning a `login` code on the sixth. The controller cannot read a
 * counter off a row, so `meta.sisa_percobaan` has to be read back out of the
 * limiter - and reading it back means reproducing the key the middleware will
 * use, which is the kind of arithmetic that rots silently if it lives in two
 * files.
 *
 * So the key derivation lives here, once, and both the limiter closure and the
 * controller call it. `PercobaanOtpTest` pins the two facts that make the
 * coupling safe: the key is the code's row id (not anything the caller can
 * rotate), and it does not move when the code is consumed.
 *
 * ## The cache-key arithmetic is the middleware's, reproduced
 *
 * `ThrottleRequests::handleRequestUsingNamedLimiter()` hashes each limit as
 * `md5($limiterName.$limit->key)` when `$shouldHashKeys` is true, which it is
 * in this framework version. `AppServiceProvider::throttleCacheKey()` already
 * reproduces that for its own refusal path; this class does the same for the
 * read path, and `RateLimitingTest` is what turns a future framework change
 * into a red build rather than a silently unread counter.
 *
 * ## Why `no_telepon` is normalised here too
 *
 * The route middleware runs **before** the `FormRequest`, so the limiter sees
 * the raw request while the controller sees the normalised one. If the two
 * disagreed, `+62812…` would look up no code (falling into the anonymous
 * fallback bucket) while the controller looked up the real code's bucket, and
 * `sisa_percobaan` would be a different number from the one being spent. Both
 * sides therefore go through {@see Telepon::normalisasi()}.
 */
final class PercobaanOtp
{
    /** The named limiter whose bucket holds the attempts. */
    public const LIMITER = 'auth-otp-verify';

    /** Guesses allowed against one issued code before the sixth is refused. */
    public const MAKS_PER_KODE = 5;

    /**
     * The `user_otp.id` of the code this request is trying to spend, or null.
     *
     * Null for anything that is not a shaped verify body - a synthetic request
     * from the OpenAPI builder, a call that failed validation, an identifier
     * that matches no account. The caller falls back to an identifier-plus-IP
     * bucket in that case, which is the correct bound: there is no code to
     * count attempts against.
     *
     * Deliberately NOT filtered on `sudah_dipakai`: the key must not move when
     * the code is consumed, or an attacker would get a fresh budget the moment
     * the code stopped being useful. `dihapus_at` is honoured because
     * `AuthController::resolveUser()` honours it through `SoftDeletes`, and the
     * two must agree about which accounts exist.
     */
    public static function idKodeAktif(Request $request): ?int
    {
        $kolom = match (true) {
            $request->filled('no_telepon') => 'no_telepon',
            $request->filled('email') => 'email',
            default => null,
        };

        if ($kolom === null || ! $request->isMethod('POST')) {
            return null;
        }

        $tujuan = (string) $request->input('tujuan', '');

        if ($tujuan === '' || ! in_array($tujuan, OtpService::TUJUAN, true)) {
            return null;
        }

        $nilai = $kolom === 'no_telepon'
            ? Telepon::normalisasi((string) $request->input($kolom))
            : (string) $request->input($kolom);

        $id = DB::table('user_otp')
            ->join('users', 'users.id', '=', 'user_otp.user_id')
            ->where('users.'.$kolom, $nilai)
            ->whereNull('users.dihapus_at')
            ->where('user_otp.tujuan', $tujuan)
            ->orderByDesc('user_otp.id')
            ->value('user_otp.id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The `Limit::by()` key for this request, given an already-resolved code id.
     *
     * Split from {@see kunci()} so the limiter closure can resolve the id once
     * and pass it in rather than paying a second query.
     */
    public static function kunciDari(?int $otpId, Request $request): string
    {
        return self::LIMITER.'|'.($otpId === null
            ? 'tanpa-kode|'.self::kunciIdentifier($request)
            : 'otp-'.$otpId);
    }

    /**
     * The `Limit::by()` key for this request, resolving the code id itself.
     */
    public static function kunci(Request $request): string
    {
        return self::kunciDari(self::idKodeAktif($request), $request);
    }

    /**
     * The cache key `ThrottleRequests` counts this limiter's attempts under.
     */
    public static function kunciCache(Request $request): string
    {
        return md5(self::LIMITER.self::kunci($request));
    }

    /**
     * How many guesses this code has left AFTER the current request.
     *
     * Called from the controller once the middleware has already counted the
     * attempt (`hit()` runs before `$next`), so a first wrong code answers 4 and
     * the fifth answers 0. The sixth never reaches the controller: the limiter
     * refuses it with a 429 whose `meta.sisa_percobaan` is also 0.
     *
     * This is exposed ONLY on `POST /auth/otp/verify`. `login` and every
     * account-lookup response stay generic, because a remaining-attempt counter
     * on those would be a shape an attacker could watch to learn something about
     * an account without holding its code.
     */
    public static function sisa(Request $request): int
    {
        return RateLimiter::remaining(self::kunciCache($request), self::MAKS_PER_KODE);
    }

    /**
     * The identifier half of the fallback key: the account the request names,
     * transliterated and lower-cased, plus the caller's address.
     *
     * Identical in shape to `AppServiceProvider::throttleKey()` for this
     * limiter, which is what keeps the two keys byte-equal. `no_telepon` is
     * normalised for the reason the class docblock gives.
     */
    private static function kunciIdentifier(Request $request): string
    {
        $nomor = $request->input('no_telepon');

        if (is_string($nomor) && $nomor !== '') {
            $identifier = (string) Telepon::normalisasi($nomor);
        } else {
            $identifier = (string) ($request->input('email') ?? '');
        }

        $identifier = Str::transliterate(Str::lower($identifier));

        return self::LIMITER.'|'.$identifier.'|'.$request->ip();
    }
}
