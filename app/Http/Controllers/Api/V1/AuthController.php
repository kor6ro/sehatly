<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\PersetujuanPdpJenis;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\LogoutAllRequest;
use App\Http\Requests\Auth\LogoutRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\StoreDeviceRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\AuthTokenResource;
use App\Http\Resources\UserDeviceResource;
use App\Http\Resources\UserResource;
use App\Models\Pasien;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Audit\AuditLogWriter;
use App\Services\Auth\OtpRejected;
use App\Services\Auth\OtpService;
use App\Services\Auth\PemilihPengirimOtp;
use App\Services\Auth\PercobaanOtp;
use App\Services\Auth\RefreshTokenRejected;
use App\Services\Auth\TokenService;
use App\Services\Pdp\PdpConsentService;
use App\Support\ApiResponse;
use App\Support\Rbac\RoleAssigner;
use App\Support\Telepon;
use App\Support\WaktuIndonesia;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 1's authentication surface: register, login, OTP verify, refresh, logout and
 * device management.
 *
 * ## This is a token + OTP API, not a session API
 *
 * There is no redirect, no form, no cookie session and no `Illuminate\Auth\Events\*`.
 * `users` has no `password`, no `email_verified_at`, no `remember_token` and no
 * `two_factor_*` column (`telemedicine_test.sql:132-149`), and every route here returns
 * the `{success,data,message}` / `{success,message,errors}` envelope from
 * {@see ApiResponse}. The Fortify/Inertia web scaffold that shares this repository is
 * todo 30's to remove; nothing in this class depends on it, and nothing in it is
 * reachable from an `api/*` path.
 *
 * ## The state machine, and where each transition is allowed
 *
 * ```
 *   register          status = pending_verifikasi, telepon_terverifikasi = 0
 *        |            the two mandatory PDP consent rows are written in the SAME
 *        |            transaction, and an OTP with tujuan = verifikasi_telepon
 *        |            is minted
 *        v
 *   otp/verify        --> status = aktif, telepon_terverifikasi = 1, last_login_at = now
 *        |            (a token is issued here and only here)
 *        |
 *   login             password is checked, then an OTP with tujuan = login is minted
 *        |            and the response carries NO token
 *        v
 *   otp/verify        --> a token is issued
 *
 *   otp/resend        closes the previous code for the same purpose and mints a
 *                     fresh one; answers the same generic envelope whether or not
 *                     the account exists
 *
 *   refresh           rotates the pair; replaying a spent token revokes every
 *                     live refresh token for the account and answers 401
 *
 *   logout            PER-DEVICE: revokes only the presented refresh token and
 *                     deletes the Sanctum access token; every device row stays
 *                     active, because `user_refresh_tokens` has no `device_id`
 *                     and the server cannot tell which row the token belongs to
 *
 *   logout-all        revokes every live refresh token, deletes the access token
 *                     and deactivates every `user_devices` row
 * ```
 *
 * Two transitions are refused rather than performed: a `nonaktif` or `ditangguhkan`
 * account cannot mint an OTP at all, and a `status` that is neither of those is never
 * moved to `aktif` by the verify step, so a suspended account cannot be resurrected by
 * replaying a code it already holds.
 *
 * ## No `permission:` and no `tipe:` on any route in this controller
 *
 * This is a deliberate decision with a catalogue behind it, not an omission.
 * `RbacCatalog::PERMISSIONS` holds 24 codes and **none of them names an auth, session,
 * token, device or notification-registration action**; the closest names are
 * `notifikasi.lihat` (view notifications) and `dokter.lihat`. Writing
 * `permission:notifikasi.lihat` on `POST /auth/devices` would be wrong twice over: it
 * would make a push-registration call a read-permission check, and `RbacCatalog`
 * documents that `perawat` and `kurir` are real `users.tipe` values that hold **no**
 * role and therefore no grant at all -- so that gate would make those two account types
 * permanently unable to register a device. A `tipe:` gate on `POST /auth/logout` would
 * be worse: every account type must be able to end its own session.
 *
 * Adding a code is a policy change, and todo 4's brief forbids "inventing permissions
 * that no module route consumes". So every authenticated route here uses
 * `auth:sanctum` plus the ownership scoping inside this class
 * (`user_id` equality on every device query), which is the check a "your own account"
 * endpoint actually needs. `AuthFlowTest` asserts that every `permission:` and `tipe:`
 * string appearing in `routes/api.php` resolves against `RbacCatalog`, so a later todo
 * that does need a code cannot introduce an unknown one by accident.
 */
class AuthController extends Controller
{
    /**
     * The two `users.status` ENUM values that may not authenticate.
     *
     * `telemedicine_test.sql:140` is
     * `status ENUM('pending_verifikasi','aktif','nonaktif','ditangguhkan')`. A
     * `pending_verifikasi` account is allowed through, because registering and then
     * logging in before verifying the phone is a real user journey and the login OTP
     * proves the phone; the verify step is what promotes it.
     *
     * @var list<string>
     */
    private const STATUS_DITOLAK = ['nonaktif', 'ditangguhkan'];

    /**
     * A bcrypt digest of a random throwaway string, computed once per process.
     *
     * Used to spend the same CPU on a login for an account that does not exist as on a
     * login for one that does, so the response time does not disclose whether a phone
     * number is registered. It is never written to a row and never compared against a
     * submitted password that would match it.
     */
    private static ?string $decoyHash = null;

    public function __construct(
        private readonly OtpService $otp,
        private readonly TokenService $tokens,
        private readonly RoleAssigner $roles,
        private readonly AuditLogWriter $audit,
        private readonly PdpConsentService $konsen,
    ) {}

    /**
     * `POST /api/v1/auth/register`
     *
     * Creates the `users` row as an unverified patient, the `pasien` row it cannot do
     * without, and the `pasien` role grant, then mints the `verifikasi_telepon` OTP.
     *
     * **The password is hashed before the transaction opens.** `Hash::make()` at the
     * production bcrypt cost is a quarter of a second or more, and holding a row lock
     * across that is how a registration endpoint becomes a denial-of-service lever.
     *
     * The three rows are written in one transaction because they are one account: a
     * `users` row with no `pasien` row cannot book, and a `pasien` row with no `users`
     * row is impossible (the FK forbids it). Partial registration would leave an
     * account that authenticates and then 500s on every downstream query.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $input = $request->validated();

        // See above: outside the transaction, deliberately.
        $kataSandiHash = Hash::make($input['password']);

        try {
            $user = DB::transaction(function () use ($input, $kataSandiHash, $request): User {
                $user = new User;
                $user->nama_lengkap = $input['nama_lengkap'];
                // Already canonical `08…`: `RegisterRequest::prepareForValidation()`
                // folded `+62…`/`62…` through `App\Support\Telepon` before any rule
                // ran, so the `unique` check above and this write cannot disagree
                // about which number an account has.
                $user->no_telepon = $input['no_telepon'];
                $user->email = $input['email'] ?? null;
                $user->kata_sandi_hash = $kataSandiHash;
                // Both are literals, never client input. `tipe` is a public endpoint's
                // most dangerous field to accept, and `status` is the state machine this
                // controller advances. Neither is in RegisterRequest's rules, so a
                // payload carrying them is dropped by `validated()` before this line.
                $user->tipe = 'pasien';
                $user->status = 'pending_verifikasi';
                $user->bahasa = $input['bahasa'] ?? 'id';
                $user->telepon_terverifikasi = false;
                $user->email_terverifikasi = false;
                $user->save();

                $pasien = new Pasien;
                $pasien->user_id = (int) $user->getKey();
                $pasien->nomor_rm = $this->nomorRekamMedis($user);
                $pasien->jenis_kelamin = $input['jenis_kelamin'];
                $pasien->tanggal_lahir = $input['tanggal_lahir'];
                $pasien->tempat_lahir = $input['tempat_lahir'] ?? null;
                $pasien->alamat_lengkap = $input['alamat_lengkap'];
                $pasien->save();

                // RbacSeeder writes no `user_roles` row on purpose and names todo 20 as
                // the place that assigns roles to real accounts. Without this grant the
                // new patient would authenticate and then be refused by every
                // `permission:`-gated route in todos 21, 27 and 47.
                $this->roles->assign((int) $user->getKey(), 'pasien');

                // The owner's option (a) for F01 §12 #1: the two MANDATORY UU PDP
                // consents are taken in the registration request and recorded here,
                // in the same transaction as the account. If either write fails the
                // whole account rolls back, so a `users` row can never exist without
                // the ledger rows that authorise it. The optional consents
                // (`pemasaran`, `berbagi_data_medis`, `komunikasi_tindak_lanjut`)
                // stay on the F02 screen and are not touched here.
                //
                // `catatVersiAktif()` is the F02 service's own write path, so the
                // version authority, the append-only ledger rule and the audit
                // observer all apply exactly as they do to
                // `POST /api/v1/pdp/persetujuan` - no rule is restated here.
                $this->konsen->catatVersiAktif(
                    $user,
                    PersetujuanPdpJenis::SyaratKetentuan->value,
                    true,
                    $request->ip(),
                );
                $this->konsen->catatVersiAktif(
                    $user,
                    PersetujuanPdpJenis::KebijakanPrivasi->value,
                    true,
                    $request->ip(),
                );

                return $user;
            });
        } catch (QueryException $exception) {
            return $this->duplicateIdentityResponse($exception);
        }

        $issued = $this->otp->issue($user, OtpService::TUJUAN_VERIFIKASI_TELEPON, (string) $user->no_telepon);

        return ApiResponse::success([
            'user' => new UserResource($user),
            'otp' => [
                'tujuan' => $issued->tujuan,
                'kedaluwarsa_at' => $issued->kedaluwarsaAt->toISOString(),
                'ttl_detik' => OtpService::TTL_MENIT * 60,
                'kode' => $issued->plainTextForClient(),
            ],
        ], 'Pendaftaran berhasil. Kode OTP telah dikirim.', Response::HTTP_CREATED);
    }

    /**
     * `POST /api/v1/auth/login`
     *
     * Checks the password and mints a `login` OTP. **Returns no token**, on purpose:
     * a phone-proved second factor is only a control while the second step still
     * requires the phone.
     *
     * An unknown identifier and a wrong password produce the same 401 body. The decoy
     * hash in {@see passwordMatches()} makes the two also take the same time, so the
     * endpoint is not a phone-number oracle.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $input = $request->validated();

        $user = $this->resolveUser($request);

        if ($user === null) {
            // Spend the same bcrypt time as a real verification, then answer exactly
            // as a wrong password does. Without the call, this branch returns in the
            // time of one indexed lookup and the endpoint becomes a timing oracle.
            $this->passwordMatches((string) $input['password'], null);

            return ApiResponse::error(
                'Nomor telepon, email, atau kata sandi salah.',
                [],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        if (! $this->passwordMatches((string) $input['password'], $user)) {
            return ApiResponse::error(
                'Nomor telepon, email, atau kata sandi salah.',
                [],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        if (in_array((string) $user->status, self::STATUS_DITOLAK, true)) {
            return ApiResponse::error(
                'Akun ini sedang dinonaktifkan. Hubungi administrator.',
                [],
                Response::HTTP_FORBIDDEN,
            );
        }

        $issued = $this->otp->issue($user, OtpService::TUJUAN_LOGIN, (string) $user->no_telepon);

        return ApiResponse::success([
            'otp' => [
                'tujuan' => $issued->tujuan,
                'kedaluwarsa_at' => $issued->kedaluwarsaAt->toISOString(),
                'ttl_detik' => OtpService::TTL_MENIT * 60,
                'kode' => $issued->plainTextForClient(),
            ],
        ], 'Kode OTP telah dikirim.');
    }

    /**
     * `POST /api/v1/auth/otp/resend`
     *
     * Re-mints the code for `$tujuan` without a password. It exists because the
     * only other way to reopen a login code is `POST /auth/login`, which makes a
     * client hold the password in memory on the OTP screen - the risk F01 §4.4
     * names - and because a registration code has no second path at all.
     *
     * ## The response is generic, and that is the whole point
     *
     * An unknown identifier, a soft-deleted account and a `nonaktif`/`ditangguhkan`
     * account all receive the SAME 200 body as a real one:
     * `{otp: {kedaluwarsa_at, ttl_detik, kanal}}` with the same message. Nothing is
     * minted for them, so the endpoint cannot be used to enumerate accounts. The
     * two branches are kept byte-identical on purpose, and `OtpResendTest` asserts
     * it under a frozen clock.
     *
     * ## It closes the previous code, because it goes through `issue()`
     *
     * `OtpService::issue()` supersedes any prior unused code for the same purpose
     * by closing its validity window, in one transaction with the insert. Resend
     * therefore cannot leave two live codes behind, which is what makes "the code
     * in the SMS is the only code" true after a resend.
     *
     * ## `kanal` is derived from the driver that actually sends
     *
     * {@see PemilihPengirimOtp::kanalAktif()} reads the container binding's channel
     * (`whatsapp` for `fonnte`, `log` for the local stand-in), so the response
     * reports where the message really went. WhatsApp is one option among several;
     * see `config/otp.php` and {@see PemilihPengirimOtp}.
     *
     * ## `device_id` is accepted and deliberately unused
     *
     * It is part of the published body for symmetry with `otp/verify`, but it is
     * NOT folded into the rate-limit key: a caller controls `device_id` and could
     * rotate it to buy fresh resend attempts against one number.
     */
    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $input = $request->validated();

        $user = $this->resolveUser($request);

        // The one message both branches answer with. It says "if registered"
        // rather than "sent" because the caller is not entitled to know which.
        $pesan = 'Jika akun terdaftar, kode OTP baru telah dikirim.';

        if ($user !== null && ! in_array((string) $user->status, self::STATUS_DITOLAK, true)) {
            $issued = $this->otp->issue($user, $input['tujuan'], (string) $user->no_telepon);

            return ApiResponse::success([
                'otp' => [
                    'kedaluwarsa_at' => $issued->kedaluwarsaAt->toISOString(),
                    'ttl_detik' => OtpService::TTL_MENIT * 60,
                    'kanal' => PemilihPengirimOtp::kanalAktif(),
                ],
            ], $pesan);
        }

        // No account (or one that may not authenticate): the same envelope with a
        // plausible window, and no row, no delivery and no audit write. The status
        // is 200 rather than a 404 or a 403 so the two cases are indistinguishable.
        return ApiResponse::success([
            'otp' => [
                'kedaluwarsa_at' => now()->addMinutes(OtpService::TTL_MENIT)->toISOString(),
                'ttl_detik' => OtpService::TTL_MENIT * 60,
                'kanal' => PemilihPengirimOtp::kanalAktif(),
            ],
        ], $pesan);
    }

    /**
     * `POST /api/v1/auth/otp/verify`
     *
     * The only endpoint in this controller that issues a token, and the only place
     * `user_otp.sudah_dipakai` is flipped. It is reached by both flows: registration
     * (`tujuan = verifikasi_telepon`) and login (`tujuan = login`).
     *
     * Every rejection is a 422 with the reason in `errors.kode`, so a client can tell
     * "resend" from "start over". An unknown identifier is reported with the *unknown
     * code* text rather than a 404, because the row not existing is not the caller's
     * business and a distinct status would enumerate accounts.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $input = $request->validated();

        $user = $this->resolveUser($request);

        if ($user === null) {
            return $this->otpRejection(OtpRejected::tidakDiketahui(), $request);
        }

        try {
            $this->otp->consume($user, $input['tujuan'], $input['kode']);
        } catch (OtpRejected $rejected) {
            return $this->otpRejection($rejected, $request);
        }

        DB::transaction(function () use ($user): void {
            $user->last_login_at = now();
            $user->telepon_terverifikasi = true;

            // Never moved out of `nonaktif` or `ditangguhkan`. Those cannot reach this
            // line through `login`, which refuses them, and through registration a new
            // row is always `pending_verifikasi` -- so in practice this is only ever the
            // first promotion. It is written as a guard rather than an unconditional
            // `status = 'aktif'` so that a code replayed against a suspended account
            // cannot undo the suspension.
            if ($user->status === 'pending_verifikasi') {
                $user->status = 'aktif';
            }

            $user->save();
        });

        $pair = $this->tokens->issue($user, $input['device_id'] ?? null);

        // The session starts here for BOTH flows (registration verify and
        // login verify): this endpoint is the only token issuer. The write
        // goes through the audit service, never a direct row write - the
        // token API has no session event to hook, so the call is explicit.
        $this->audit->login($user);

        return ApiResponse::success([
            'user' => new UserResource($user->refresh()),
            'token' => new AuthTokenResource($pair),
        ], 'Verifikasi berhasil.');
    }

    /**
     * `POST /api/v1/auth/refresh`
     *
     * Rotates the pair. A 401 here is terminal for the client's session by design: the
     * server revokes the presented token on every use, so a 401 means the token was
     * already spent -- which is either a client bug or a stolen token -- and either way
     * the honest answer is to sign in again. The Dart client's single-flight refresh
     * interceptor (todo 24) is built around exactly that.
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        try {
            $pair = $this->tokens->rotate((string) $request->validated('refresh_token'));
        } catch (RefreshTokenRejected $rejected) {
            return ApiResponse::error($rejected->getMessage(), [], Response::HTTP_UNAUTHORIZED);
        }

        return ApiResponse::success(
            ['token' => new AuthTokenResource($pair)],
            'Token berhasil diperbarui.',
        );
    }

    /**
     * `POST /api/v1/auth/logout` - the PER-DEVICE logout (owner decision F01 §12 #3).
     *
     * Revokes **only the presented refresh token** and deletes the Sanctum access
     * token this request arrived on. It deliberately does NOT touch `user_devices`:
     * a per-device logout must leave the other signed-in devices working, and
     * `user_refresh_tokens` has **no `device_id` column** (`:204-212`), so the
     * server cannot tell which device row this session belongs to. Deactivating
     * "the current device" is therefore not expressible, and deactivating all of
     * them is exactly what {@see logoutAll()} exists for.
     *
     * ## The schema is NOT changed to make this narrower
     *
     * A `device_id` column on `user_refresh_tokens` would let the server map a
     * session to a row, but the reference SQL is read-only law and the owner's
     * decision explicitly defers the mapping. So revoking *another* device from
     * the device list is not built, and the response reports
     * `perangkat.dimatikan: 0` - a truthful zero rather than a removed key, kept
     * so existing clients that read the field do not break.
     *
     * A device that was deactivated by a previous `logout-all` re-activates itself
     * by calling `POST /api/v1/auth/devices`, which sets `aktif = 1`.
     */
    public function logout(LogoutRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $refreshRevoked = $this->tokens->revoke((string) $request->validated('refresh_token'));
        $accessRevoked = $this->tokens->revokeCurrentAccessToken($request);

        // The session ends here, after the revocation above. Same shape as
        // the login write: an explicit call into the audit service, which is
        // the only producer of log rows.
        $this->audit->logout($user);

        return ApiResponse::success([
            'refresh_token' => ['dicabut' => $refreshRevoked],
            'access_token' => ['dihapus' => $accessRevoked],
            'perangkat' => ['dimatikan' => 0],
        ], 'Logout berhasil.');
    }

    /**
     * `POST /api/v1/auth/logout-all` - "keluar dari semua perangkat".
     *
     * Revokes every live refresh token for the account, deletes the access token
     * this request arrived on, and deactivates every `user_devices` row. This is
     * the endpoint that owns the broad action the old `POST /auth/logout` used to
     * perform; separating them is what makes the device list meaningful, because
     * "sign out here" no longer signs out everywhere.
     *
     * The deactivation is deliberately part of this action: `user_devices.aktif`
     * is what stops a signed-out installation from continuing to receive that
     * account's medical push notifications, and only a caller who asked to leave
     * every device has agreed to that.
     *
     * The rows are deactivated, never deleted - `user_devices` is the only record
     * of which installations hold a push registration.
     */
    public function logoutAll(LogoutAllRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $refreshRevoked = $this->tokens->revokeAllForUser((int) $user->getKey());
        $accessRevoked = $this->tokens->revokeCurrentAccessToken($request);

        $devicesDeactivated = DB::table('user_devices')
            ->where('user_id', $user->getKey())
            ->where('aktif', true)
            ->update(['aktif' => false]);

        $this->audit->logout($user);

        return ApiResponse::success([
            'refresh_token' => [
                'dicabut' => $refreshRevoked > 0,
                'jumlah' => $refreshRevoked,
            ],
            'access_token' => ['dihapus' => $accessRevoked],
            'perangkat' => ['dimatikan' => $devicesDeactivated],
        ], 'Logout dari semua perangkat berhasil.');
    }

    /**
     * `GET /api/v1/auth/devices`
     *
     * The caller's own rows, most recently active first. Scoped by `user_id` in the
     * query rather than filtered in PHP, so a row belonging to another account is never
     * loaded and then discarded.
     *
     * ## The count moved from `data.total` to the project-wide `meta` block
     *
     * This response used to answer `data: {devices: [...], total: N}` because
     * {@see ApiResponse} had no `meta` key. Todo 21 widened it, so the count now lives
     * in `meta` where every other list endpoint puts it:
     *
     * ```
     * data: {devices: [...]}
     * meta: {current_page: 1, last_page: 1, per_page: N, total: N, from: 1, to: N}
     * ```
     *
     * The list is still **not** paginated - an account has a handful of devices, so
     * there is nothing to page - but it is reported as the degenerate single page it is
     * rather than as a special case, so a client parses one list envelope for every
     * list endpoint in this API. `AuthFlowTest` asserts both the migrated `meta.total`
     * and the absence of the old `data.total`.
     */
    public function devicesIndex(Request $request): JsonResponse
    {
        $devices = UserDevice::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->orderByDesc('last_active_at')
            ->orderBy('id')
            ->get();

        $total = $devices->count();

        return ApiResponse::success(
            ['devices' => UserDeviceResource::collection($devices)],
            'Daftar perangkat berhasil dimuat.',
            Response::HTTP_OK,
            ApiResponse::singlePageMeta($total),
        );
    }

    /**
     * `POST /api/v1/auth/devices`
     *
     * Upserts on `uq_device (user_id, device_id)` (`:201`) and sets `aktif = 1`, which
     * is what makes a device that was deactivated by a logout usable again.
     *
     * The lookup is `lockForUpdate()` inside a transaction because the unique key is the
     * only thing preventing a duplicate, and two installs reporting in at the same
     * moment would otherwise race: both find nothing, both insert, and the second one
     * dies with MySQL 1062. The lock is on the `(user_id, device_id)` slice, so two
     * *different* devices registering at the same time do not block each other.
     */
    public function devicesStore(StoreDeviceRequest $request): JsonResponse
    {
        $input = $request->validated();
        $userId = (int) $request->user()->getAuthIdentifier();

        [$device, $created] = DB::transaction(function () use ($input, $userId): array {
            $device = UserDevice::query()
                ->where('user_id', $userId)
                ->where('device_id', $input['device_id'])
                ->lockForUpdate()
                ->first();

            if ($device === null) {
                $device = new UserDevice;
                $device->user_id = $userId;
                $device->device_id = $input['device_id'];
                $device->platform = $input['platform'];
                $device->fcm_token = $input['fcm_token'] ?? null;
                $device->app_versi = $input['app_versi'] ?? null;
                $device->aktif = true;
                $device->last_active_at = now();
                $device->save();

                return [$device, true];
            }

            $device->platform = $input['platform'];
            $device->fcm_token = $input['fcm_token'] ?? null;
            $device->app_versi = $input['app_versi'] ?? null;
            $device->aktif = true;
            $device->last_active_at = now();
            $device->save();

            return [$device, false];
        });

        return ApiResponse::success(
            ['device' => new UserDeviceResource($device)],
            $created ? 'Perangkat berhasil didaftarkan.' : 'Perangkat berhasil diperbarui.',
            $created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /**
     * `DELETE /api/v1/auth/devices/{deviceId}`
     *
     * Deactivates one device, scoped to the caller. A `device_id` belonging to another
     * account is **404, not 403**: a 403 would confirm the device exists, and the
     * `device_id` is a client-supplied installation identifier that a caller may
     * legitimately be holding for a device it no longer owns.
     *
     * The row is deactivated, never deleted. `user_devices` is the only record of which
     * installations have a push registration, and a hard delete would lose the audit
     * trail while `ON DELETE CASCADE` from `users` (`:200`) is the only removal path the
     * schema offers anyway.
     */
    public function devicesDestroy(Request $request, string $deviceId): JsonResponse
    {
        $device = UserDevice::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('device_id', $deviceId)
            ->first();

        if ($device === null) {
            return ApiResponse::error('Resource not found.', [], Response::HTTP_NOT_FOUND);
        }

        $device->aktif = false;
        $device->save();

        return ApiResponse::success(
            ['device' => new UserDeviceResource($device)],
            'Perangkat berhasil dicabut.',
        );
    }

    /**
     * Look the caller up by phone number, else by email address.
     *
     * The `SoftDeletes` global scope on `User` already excludes a row whose `dihapus_at`
     * is set, so a soft-deleted account cannot authenticate and is reported here
     * exactly like one that never existed.
     *
     * `no_telepon` wins when both are sent. It is the DDL's `NOT NULL UNIQUE` column
     * (`:137`) and therefore the one that identifies an account for sure; `email` is
     * `NULL UNIQUE` (`:136`) and two accounts may both have `NULL` there.
     */
    private function resolveUser(Request $request): ?User
    {
        if ($request->filled('no_telepon')) {
            // The `FormRequest` already normalised this before validation, so this
            // is normally a no-op. It is repeated because `resolveUser()` is also
            // the lookup every caller goes through, and a future route that forgets
            // `prepareForValidation()` must still find the account the canonical
            // form belongs to - {@see Telepon::normalisasi()} is idempotent, so
            // running it twice is free rather than wrong.
            return User::query()
                ->where('no_telepon', Telepon::normalisasi((string) $request->input('no_telepon')))
                ->first();
        }

        if ($request->filled('email')) {
            return User::query()->where('email', $request->input('email'))->first();
        }

        return null;
    }

    /**
     * Verify a password against a row, or against a decoy when there is no row.
     *
     * The decoy is what stops the endpoint from being a timing oracle: without it, a
     * request for an unregistered number returns in the time it takes to run one indexed
     * lookup, and a request for a registered one additionally pays a bcrypt verification.
     * Comparing the two response times enumerates every account in the system.
     */
    private function passwordMatches(string $plain, ?User $user): bool
    {
        if ($user === null) {
            self::$decoyHash ??= Hash::make(Str::random(40));

            Hash::check($plain, self::$decoyHash);

            return false;
        }

        return Hash::check($plain, (string) $user->kata_sandi_hash);
    }

    /**
     * Render an {@see OtpRejected} as the task-3 failure envelope.
     *
     * The envelope's `message` stays the fixed validation string the kernel already uses
     * for every 422 on this API; the specific reason lives in `errors.kode`, which is
     * where field-level detail belongs. The status is always 422: a bad code is bad
     * input, and a 401 here would tell the client its *session* had expired when its
     * session has not started yet.
     *
     * ## `meta.sisa_percobaan` is exposed HERE and nowhere else
     *
     * The owner's decision (F01 §12 #6) is that a client may see how many guesses a
     * code has left so it can warn "sisa N percobaan" before the sixth burns the
     * code. The number comes from {@see PercobaanOtp::sisa()}, which reads back the
     * same limiter bucket the middleware just counted. `login` and every
     * account-lookup response stay generic: a remaining-attempt counter there would
     * be a probe an attacker could watch without holding the account's code.
     */
    private function otpRejection(OtpRejected $rejected, Request $request): JsonResponse
    {
        return ApiResponse::error(
            'The given data was invalid.',
            ['kode' => [$rejected->getMessage()]],
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['sisa_percobaan' => PercobaanOtp::sisa($request)],
        );
    }

    /**
     * `pasien.nomor_rm`, derived from the account's own primary key.
     *
     * `telemedicine_test.sql:221` documents the format in the column's own comment:
     * `nomor_rm VARCHAR(20) NULL UNIQUE COMMENT 'Nomor rekam medis aplikasi:
     * RM-YYYYMM-XXXXXX'`.
     *
     * Two properties make deriving it from `users.id` the right call rather than a
     * random draw: it cannot collide, because the source is already unique, so no retry
     * loop is needed; and it is 17 characters, inside the `VARCHAR(20)`. The alternative
     * -- leaving it `NULL`, which the column permits -- leaves every downstream medical
     * record with no patient identifier, and the column is the one the spec's own
     * "rekam medis aplikasi" comment is about.
     *
     * The `YYYYMM` prefix is read at write time, so a record's month is the month it was
     * registered. That is stated rather than assumed, because it makes `nomor_rm`
     * non-reproducible from the id alone after the fact.
     *
     * The month is the **clinic's**, via {@see WaktuIndonesia::tanggal('Ym')}.
     * `now()` is a UTC instant (`config/app.php` is `UTC`) and WIB is +07:00, so a
     * UTC basis stamped every record registered between 00:00 and 07:00 WIB with
     * the previous month. `WaktuIndonesia::tanggal()` honours
     * `Carbon::setTestNow()`, so the number is still reproducible under a frozen
     * clock.
     */
    private function nomorRekamMedis(User $user): string
    {
        return sprintf('RM-%s-%06d', WaktuIndonesia::tanggal('Ym'), (int) $user->getKey());
    }

    /**
     * Turn a unique-key violation on `users` into a 422 rather than a 500.
     *
     * `RegisterRequest` already carries `unique:users,no_telepon` and
     * `unique:users,email`, which is the fast path and covers every realistic
     * registration. This exists for the race the rule cannot close: two requests for the
     * same number that both pass validation, and the second one fails on the index.
     *
     * SQLSTATE 23000 is MySQL's integrity-constraint class, of which 1062 is the
     * duplicate-key case. The key name is read out of the message because the two
     * columns are not symmetric: `email`'s index is auto-named after its column while
     * `no_telepon`'s is named for it too, but the message is the only place either name
     * appears, and guessing would attach the error to the wrong field. A 1062 the message
     * does not identify is re-thrown rather than mis-reported.
     */
    private function duplicateIdentityResponse(QueryException $exception): JsonResponse
    {
        $message = $exception->getMessage();

        if ($exception->getCode() !== '23000' || ! str_contains($message, 'Duplicate entry')) {
            throw $exception;
        }

        foreach (['no_telepon', 'email'] as $column) {
            if (str_contains($message, 'users.'.$column)) {
                return ApiResponse::error(
                    'The given data was invalid.',
                    [$column => ['Nilai sudah terdaftar.']],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        throw $exception;
    }
}
