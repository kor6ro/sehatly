import { expect, type Page } from '@playwright/test';

/**
 * The shared account-creation step, done against the API instead of through `/register`.
 *
 * ## Why it stopped driving a form
 *
 * `/register` no longer exists. Sehatly admits through ONE door - the phone number in
 * `login-dialog.tsx` - and finishes a new account at `/profil/edit/{id}?sign_up=true`,
 * the same path halodoc uses. That is the right product shape and the wrong shape for a
 * spec helper: six suites only want a row in `users` and a token in the tab, and
 * walking them through an OTP box they are not asserting anything about buys no
 * coverage while coupling every one of those suites to the exact labels of a screen
 * none of them care about.
 *
 * So the round trip moved behind this module. What did NOT move is the part that
 * matters: every call is a real `POST` to the running server, the code is read out of
 * the server's own response, and the pair is stored under the same three keys
 * `lib/token.ts` writes. Nothing is stubbed, and the session the tests use is a session
 * a human could have obtained.
 *
 * ## Why it is `POST /auth/sign-up` and not the one-door `login` flow
 *
 * Both doors are live. This one is used because of the ceilings in front of them:
 * `sign-up/lengkapi` reuses the `auth-otp-send` limiter, whose key is `identifier|ip`
 * and whose identifier field is empty for that route - so it is a **per-IP** bucket of
 * ten a minute, and a suite that creates more than ten accounts in a minute would start
 * failing for a reason nothing to do with what it asserts. `sign-up` keys on the phone
 * number, and the number here is unique per run, so each account gets its own budget.
 *
 * `POST /auth/sign-up` writes a COMPLETE account - name, `pasien` row, role, ledger -
 * so `otp/verify` answers `profil_lengkap: true` and the account lands on the landing
 * page rather than at the completion screen. That is asserted below rather than assumed,
 * because a regression there would silently move every spec onto a form page it has no
 * assertions for.
 *
 * The password is still sent. The endpoint's `RegisterRequest` validates it and the UI
 * no longer collects one, but the field exists for a client that already holds every
 * fact - which is precisely what a fixture-creating helper is.
 */
export type OpsiDaftar = {
    /** `users.nama_lengkap`; also the name the dashboard greets. */
    nama: string;
    /** Must be unique: `users.no_telepon` is `UNIQUE`. Generated when omitted. */
    telepon?: string;
    /** `Y-m-d`. Defaults to a fixed past date so runs are reproducible. */
    tanggalLahir?: string;
    alamat?: string;
};

/** The password the fixture accounts carry. Never used by the UI. */
const SANDI = 'RahasiaKuat123';

/**
 * Create the account, verify it, and leave the pair in the tab under `lib/token.ts`'s
 * own keys - so the caller can simply `page.goto(...)` a guarded route.
 *
 * The page has to be on the app's origin before anything can be written to its
 * `sessionStorage`; a fresh page is at `about:blank`, whose storage is not ours. So a
 * blank page gets one navigation first. When the caller already landed somewhere on the
 * origin that navigation is skipped, which keeps the helper from throwing away a page
 * the caller had already prepared.
 */
export async function daftarDanMasuk(
    page: Page,
    opsi: OpsiDaftar,
): Promise<{ telepon: string }> {
    let telepon = opsi.telepon ?? nomorTeleponBaru();
    const tanggalLahir = opsi.tanggalLahir ?? '1995-04-11';
    const alamat = opsi.alamat ?? 'Jl. Uji Coba No. 1, Bandung';

    type JawaabanDaftar = { data: { otp: { kode: string | null; tujuan: string } } };

    /**
     * Up to three attempts, each on a FRESH number, because the failure being retried is
     * a deadlock and a deadlock does not roll back the whole account.
     *
     * Ten Playwright workers POST this at the same instant and MySQL answers
     * `SQLSTATE[40001] 1213 Deadlock found when trying to get lock` to whichever one
     * loses. The two writes are NOT one transaction: `AuthController::register` commits
     * the `users` row, the `pasien` row, the role grant and both consent-ledger rows
     * before it reaches line 245, and only then does `OtpService::issue()` open its own
     * transaction for `user_otp` - which is exactly where the deadlock is reported. The
     * rollback throws away the code and KEEPS the account, so reusing the number comes
     * back 422 "sudah dipakai", which is what happened the first time this loop was
     * written to retry it unchanged. A new number per attempt is what makes the retry
     * start from nothing instead of from an orphan that can never be verified.
     *
     * A caller that pinned `telepon` keeps it, and so keeps the honest failure: if that
     * number is genuinely taken, waiting will not free it.
     *
     * Only a 500 is retried. A 422 means the fixture sent a body the endpoint refuses
     * and waiting would only delay the same message; a 429 is a throttle 250 ms will not
     * clear. Both keep their own status and their own body, which is what makes this a
     * retry rather than a swallow.
     */
    let isiDaftar: JawaabanDaftar | undefined;
    let statusDaftar = 0;
    let teksDaftar = '';

    for (let percobaan = 1; percobaan <= 3; percobaan += 1) {
        const daftar = await page.request.post('/api/v1/auth/sign-up', {
            data: {
                nama_lengkap: opsi.nama,
                no_telepon: telepon,
                password: SANDI,
                jenis_kelamin: 'L',
                tanggal_lahir: tanggalLahir,
                alamat_lengkap: alamat,
                persetujuan_syarat_ketentuan: true,
                persetujuan_kebijakan_privasi: true,
            },
        });

        statusDaftar = daftar.status();
        teksDaftar = await daftar.text();

        if (statusDaftar === 201) {
            isiDaftar = JSON.parse(teksDaftar) as JawaabanDaftar;
            break;
        }

        if (statusDaftar !== 500 || percobaan === 3) {
            break;
        }

        if (opsi.telepon === undefined) {
            telepon = nomorTeleponBaru();
        }

        await new Promise((tunggu) => setTimeout(tunggu, 250 * percobaan));
    }

    expect(statusDaftar, `POST /auth/sign-up harus menjawab 201 - ${teksDaftar}`).toBe(201);
    expect(isiDaftar, 'respons sign-up 201 harus membawa kode OTP').toBeDefined();

    const otpDaftar = (isiDaftar as JawaabanDaftar).data.otp;
    const kode = otpDaftar.kode;

    /**
     * Read out of the response, never from a constant. `AuthController` publishes the
     * plaintext only under `APP_ENV=local`, so a production deployment answers `kode:
     * null` and this read yields nothing - the correct outcome, not a silent fallback
     * to a code a real user would not have.
     */
    expect(kode, 'OTP harus terbit pada APP_ENV=local').not.toBeNull();

    const verifikasi = await page.request.post('/api/v1/auth/otp/verify', {
        data: {
            no_telepon: telepon,
            kode,
            tujuan: otpDaftar.tujuan,
        },
    });

    expect(verifikasi.status(), 'POST /auth/otp/verify harus menjawab 200').toBe(200);

    const isiVerifikasi = (await verifikasi.json()) as {
        data: {
            profil_lengkap: boolean;
            token: {
                access_token: string;
                refresh_token: string;
                access_token_expires_at: string;
            };
        };
    };

    /**
     * The account this door writes is finished, so the token it mints must not point at
     * `/profil/edit/{id}?sign_up=true`. Asserted rather than trusted: if `sign-up`
     * ever stops completing the profile, every spec below would silently start on the
     * sign-up form and fail somewhere far from the cause.
     */
    expect(
        isiVerifikasi.data.profil_lengkap,
        'akun dari POST /auth/sign-up harus sudah lengkap',
    ).toBe(true);

    await pasangSesi(page, isiVerifikasi.data.token);

    return { telepon };
}

/**
 * Put an issued pair into the tab exactly where `establishSession` would put it.
 *
 * Three keys, in the same shape: `setTokens` writes the access and refresh tokens
 * verbatim and the expiry as the ISO string the server sent, so an `expires_at` of
 * `null` means "no expiry recorded" and the key is removed rather than written as the
 * string `"null"`.
 */
async function pasangSesi(
    page: Page,
    token: {
        access_token: string;
        refresh_token: string;
        access_token_expires_at: string;
    },
): Promise<void> {
    const asal = page.url();

    if (!asal.startsWith('http://') && !asal.startsWith('https://')) {
        await page.goto('/');
    }

    await page.evaluate((pasangan) => {
        globalThis.sessionStorage.setItem('sehatly.access_token', pasangan.akses);
        globalThis.sessionStorage.setItem('sehatly.refresh_token', pasangan.segar);

        if (pasangan.kedaluwarsa === null) {
            globalThis.sessionStorage.removeItem('sehatly.access_expires_at');
        } else {
            globalThis.sessionStorage.setItem(
                'sehatly.access_expires_at',
                pasangan.kedaluwarsa,
            );
        }
    }, {
        akses: token.access_token,
        segar: token.refresh_token,
        kedaluwarsa: token.access_token_expires_at,
    });
}

/**
 * A unique `no_telepon`, unique per call rather than per run.
 *
 * `Date.now()` alone repeats when two workers start in the same millisecond, and
 * `users.no_telepon` is `UNIQUE` - so a collision would be a 422 that looks like a
 * flake. The counter makes two calls in the same tick distinct, and the digit budget
 * stays inside the server's `/^\+?[0-9]{8,20}$/`.
 */
let nomorBerturut = 0;

export function nomorTeleponBaru(prefix = '0896'): string {
    nomorBerturut += 1;

    const waktu = String(Date.now()).slice(-7);
    const urut = String(nomorBerturut % 10);

    return `${prefix}${waktu}${urut}`;
}
