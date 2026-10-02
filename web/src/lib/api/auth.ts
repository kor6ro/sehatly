import { mutationOptions } from '@tanstack/react-query';
import { request, type IssuedToken } from '@/lib/http';
import type { OtpChallenge, OtpTujuan, User } from '@/lib/api/types';

/**
 * The Module 1 auth endpoints.
 *
 * ## The two-step state machine, and why the UI has to mirror it
 *
 * ```
 *   register  -> 201, { user, otp }   NO token
 *   login     -> 200, { otp }         NO token
 *   otp/verify-> 200, { user, token } the ONLY token issuer
 * ```
 *
 * `AuthController::login` returns no token on purpose: a phone-proved second factor is
 * only a control while the second step still requires the phone. So a login form that
 * expects a token from `/login` is not merely incomplete, it is wrong - and the screen
 * has to carry the identifier forward to the verify call, because `VerifyOtpRequest`
 * identifies the account by `no_telepon` **or** `email` and by nothing else.
 */

/**
 * How an account is named to `/login` and `/auth/otp/verify`.
 *
 * `AuthRequest` requires **at least one** of the two and prefers `no_telepon` when both
 * are sent, and it attaches the same "Isi no_telepon atau email." message to both keys
 * when neither is. The union below is the whole of that contract, and the screens build
 * it from one field the user typed rather than asking which kind it was.
 */
export type Identifier =
    | { no_telepon: string; email?: never }
    | { email: string; no_telepon?: never };

export type LoginInput = Identifier & {
    password: string;
};

export type RegisterInput = {
    nama_lengkap: string;
    no_telepon: string;
    email?: string;
    password: string;
    jenis_kelamin: 'L' | 'P';
    /** `Y-m-d`. The API validates `date_format:Y-m-d` and refuses today-relative strings. */
    tanggal_lahir: string;
    tempat_lahir?: string;
    alamat_lengkap: string;
    bahasa?: 'id' | 'en';
    /**
     * The two mandatory UU PDP consents (owner option (a), F01 §12 #1).
     *
     * `RegisterRequest` validates both with `accepted`, so a missing or falsy value
     * is a 422 on that field; this screen only ever sends `true`, and its checkbox
     * blocks the submit otherwise. The optional consents stay on the F02 screen.
     */
    persetujuan_syarat_ketentuan: boolean;
    persetujuan_kebijakan_privasi: boolean;
};

export type VerifyOtpInput = Identifier & {
    /** Six digits as a **string**: `OtpService` zero-pads, so a code may begin with `0`. */
    kode: string;
    tujuan: OtpTujuan;
    /** Labels the issued Sanctum token; the only thing it does. Omitted when unavailable. */
    device_id?: string;
};

/**
 * `POST /api/v1/auth/login`
 *
 * Returns the OTP challenge and **no token**. The caller must go on to
 * {@link verifyOtp} to obtain a pair.
 */
export function login(input: LoginInput) {
    return request<{ otp: OtpChallenge }>('auth/login', {
        method: 'POST',
        json: input,
        // A 401 here is a wrong password, and the transport must not try to recover from
        // it: `afterResponse` would attempt a refresh, find no refresh token, and sign
        // the (already anonymous) visitor out.
        retry: 0,
    });
}

/**
 * `POST /api/v1/auth/register`
 *
 * Answers 201 with the created `user` and an OTP challenge, and again no token. The
 * `pasien` row and the `pasien` role grant are created in the same transaction, so the
 * account is immediately usable once the phone is proved.
 */
export function register(input: RegisterInput) {
    return request<{ user: User; otp: OtpChallenge }>('auth/register', {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

/**
 * `POST /api/v1/auth/otp/verify`
 *
 * The only endpoint in `AuthController` that issues a token. Reached by both flows, and
 * the `tujuan` has to be the one the code was minted for: a `login` code will not verify
 * a `verifikasi_telepon` request.
 *
 * Every rejection is a 422 with the reason in `errors.kode`, which is why the OTP screen
 * renders that key as a field error rather than a banner: "resend" and "start over" are
 * different recoveries and the message is what tells them apart.
 */
export function verifyOtp(input: VerifyOtpInput) {
    return request<{ user: User; token: IssuedToken }>('auth/otp/verify', {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

/** The `otp` block `POST /auth/otp/resend` returns; no `tujuan`, no code. */
export type OtpResendResult = {
    otp: {
        kedaluwarsa_at: string;
        ttl_detik: number;
        /** The channel that actually sent (`whatsapp`, `log`), read server-side. */
        kanal: string;
    };
};

export type ResendOtpInput = Identifier & {
    tujuan: OtpTujuan;
    device_id?: string;
};

/**
 * `POST /api/v1/auth/otp/resend`
 *
 * Re-mints the code without a password. The response is deliberately generic
 * ("Jika akun terdaftar..."), so the client cannot learn whether the identifier
 * exists and must not try: it reuses the same identifier the verify step will send.
 * Success closes the previous code server-side (`OtpService::issue()`), which is why
 * the OTP screen replaces its countdown from the returned window rather than keeping
 * two.
 */
export function resendOtp(input: ResendOtpInput) {
    return request<OtpResendResult>('auth/otp/resend', {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

export type LogoutResult = {
    refresh_token: { dicabut: boolean };
    access_token: { dihapus: boolean };
    /**
     * Always `0` on the per-device logout: `user_refresh_tokens` carries no
     * `device_id`, so the server cannot map this session to a device row and
     * deactivates none. See `AuthController::logout`.
     */
    perangkat: { dimatikan: number };
};

export type LogoutAllResult = {
    refresh_token: { dicabut: boolean; jumlah: number };
    access_token: { dihapus: boolean };
    perangkat: { dimatikan: number };
};

/**
 * `POST /api/v1/auth/logout`
 *
 * Per-device since commit `aacb7e8`: it revokes only the presented refresh token and
 * deletes this request's access token, leaving other devices signed in. The
 * `refresh_token` body field is still **required**; omitting it is a 422, so the
 * caller reads the stored pair first.
 */
export function logout(refreshToken: string) {
    return request<LogoutResult>('auth/logout', {
        method: 'POST',
        json: { refresh_token: refreshToken },
        retry: 0,
    });
}

/**
 * `POST /api/v1/auth/logout-all`
 *
 * The broad action: every live refresh token for the account is revoked, the access
 * token this request arrived on is deleted, and every `user_devices` row is
 * deactivated. `LogoutAllRequest` declares an empty rule set, so the body is `{}` and
 * the account is the authenticated one.
 */
export function logoutAll() {
    return request<LogoutAllResult>('auth/logout-all', {
        method: 'POST',
        json: {},
        retry: 0,
    });
}

/**
 * The mutations, wrapped so a screen gets `useMutation` without restating the paths.
 *
 * The query keys are version-first (`['v1', ...]`) and the auth ones are deliberately
 * `null`: there is no cached auth *query*, only a cached identity fetched by
 * `meOptions`, and a mutation here must never be mistaken for one. What the mutations do
 * invalidate is `['v1','me']` - see `me.ts`, which owns the account cache.
 */
export const authMutations = {
    login: () =>
        mutationOptions({
            mutationFn: login,
        }),

    register: () =>
        mutationOptions({
            mutationFn: register,
        }),

    verifyOtp: () =>
        mutationOptions({
            mutationFn: verifyOtp,
        }),

    logout: () =>
        mutationOptions({
            mutationFn: logout,
        }),
};
