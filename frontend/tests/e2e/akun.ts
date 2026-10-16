import { randomInt } from 'node:crypto';
import { expect, type Page } from '@playwright/test';

/**
 * The shared account-creation step, done against the API instead of through `/register`.
 *
 * ## Why it stopped driving a form
 *
 * `/register` no longer exists. Sehatly admits through ONE door - the phone number in
 * `masuk-dialog.tsx` - and finishes a new account at `/profil/edit/{id}?sign_up=true`,
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
 * Sign in through the ONE surface the product has: the dialog on `/`.
 *
 * ## Why this drives a dialog instead of pasting a pair
 *
 * `POST /auth/masuk` answers `{otp}` and **no token**, and `POST /auth/otp/verify` is the
 * only endpoint in `AuthController` that mints one. A helper that pasted a token into
 * `sessionStorage` would be testing a session no visitor can obtain, so the two real
 * requests are made here and the code is read out of the first response - never from a
 * constant.
 *
 * ## Why `?masuk=1` rather than a click on the header
 *
 * It is the address every guard sends a visitor with no session to, which is exactly what
 * replaced the `/masuk` page, and arriving with the dialog already open saves this helper
 * from a selector whose only job is pressing a button. Pressing that button is covered by
 * `landing.spec.ts`, which is where the door itself belongs.
 *
 * ## Why the digits lose their leading `0`
 *
 * The field sits after a fixed `+62`, so `0896…` would fail the dialog's `NASIONAL` rule
 * (`/^8\d{7,11}$/`) before anything was sent. `+62896…`, `62896…` and `0896…` are one
 * number to the server, which folds all three into the canonical `08…` anyway.
 *
 * ## Why the send button is found by being enabled
 *
 * Under `whatsapp` the step renders two channel buttons, "Kirim Kode melalui WhatsApp"
 * and a permanently disabled "Kirim Kode melalui SMS". Both start with the same words, so
 * a name alone is ambiguous between them and Playwright's strict mode would refuse to
 * choose. Disambiguating on `:not([disabled])` picks the one that can be pressed whatever
 * the deployment reports, and under `log` there is a single button to find.
 */
export async function masukLewatDialog(page: Page, telepon: string): Promise<void> {
    await page.goto('/?masuk=1');

    const kolom = page.getByRole('textbox', { name: 'Nomor ponsel', exact: true });

    await expect(kolom).toBeVisible({ timeout: 60_000 });
    await kolom.fill(nasionalDari(telepon));

    /**
     * "Lanjut" sends nothing - it only walks `nomor` -> `kanal`. The request is made by
     * the channel button, which is why the response is awaited against THAT click.
     */
    await page.getByRole('button', { name: 'Lanjut', exact: true }).click();

    const [respons] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/api/v1/auth/masuk'), {
            timeout: 60_000,
        }),
        page
            .locator('form button:not([disabled])', { hasText: 'Kirim Kode' })
            .click(),
    ]);

    expect(respons.status(), `POST /auth/masuk harus menjawab 200 - ${respons.status()}`).toBe(
        200,
    );

    const body = (await respons.json()) as {
        data: { otp: { kode: string | null } };
    };

    expect(body.data.otp.kode, 'OTP harus terbit pada APP_ENV=local').not.toBeNull();

    /**
     * Scoped to the form because at this step the dialog renders the code field and
     * nothing else that takes digits - the phone input is not on screen in `kode`.
     */
    await page
        .locator('form input[inputmode="numeric"]')
        .fill(body.data.otp.kode as string);

    await page.getByRole('button', { name: 'Verifikasi', exact: true }).click();

    /**
     * A finished account goes to `/` (the dialog's own destination), and an unfinished
     * one to `/profil/edit/{id}?sign_up=true`. Both are asserted by the callers that
     * care; this helper is for the seeded, finished rows and stops at the landing page.
     */
    await expect(page).toHaveURL(/\/$/, { timeout: 30_000 });
}

/**
 * The digits the dialog's field wants: no prefix, no separator, leading `0` stripped.
 *
 * Returns the input unchanged when it is already in that shape, so a fixture that
 * pre-strips one keeps working. It throws rather than sending a value the dialog would
 * silently refuse - a number that never passes `NASIONAL` fails later as "the OTP never
 * arrived", which is four steps from the cause.
 */
function nasionalDari(telepon: string): string {
    const bersih = telepon.replace(/[\s-]/g, '').replace(/^\+?62/, '0').replace(/^0/, '');

    if (!/^8\d{7,11}$/.test(bersih)) {
        throw new Error(
            `Nomor "${telepon}" tidak dapat dipakai untuk masuk lewat dialog: ` +
                'kolomnya menerima 8-12 digit setelah +62.',
        );
    }

    return bersih;
}

/**
 * A unique `no_telepon`, unique across calls, workers, and previous runs.
 *
 * ## Why the counter was removed
 *
 * It used to end in a module-level `nomorBerturut`, and that counter is unique WITHIN one
 * process and meaningless ACROSS one. Playwright runs every worker in its own Node
 * process, so each worker started the counter at 0 - two workers reaching this function
 * in the same millisecond produced the identical number, and a third did too. The
 * docblock's own claim, "the counter makes two calls in the same tick distinct", was true
 * only of a single-worker run.
 *
 * `users.no_telepon` is `UNIQUE`, so the collision surfaced as
 * `UniqueConstraintViolationException` on the sign-up insert and a 500 back to the caller.
 * The failure landed on whichever test lost the race - four unrelated tests failed in one
 * run while the suite as a whole stayed green in another, which is the worst shape a
 * fixture bug can take: it looks like the feature under test.
 *
 * `Date.now()` was then left carrying the whole burden on its own, and its last seven
 * digits cycle every 10^7 ms - a little under 2.8 hours - so re-running a suite inside
 * that window collided with the accounts the previous run had left in the database.
 *
 * ## Why randomness and not a shared counter
 *
 * A genuinely shared counter would need shared state - a file, a table, a lock - and the
 * fixture helper is the one piece of this harness that must keep working when the server
 * is under load and the database is the thing being stressed. Six digits of `randomInt`
 * are per-process by construction, need no coordination, and put the chance of two calls
 * in the same millisecond colliding at 1 in 10^6, far below the rate at which this suite
 * creates accounts.
 *
 * Three time-based digits stay even though uniqueness no longer rests on them. They are a
 * second factor: an arbitrary pair of numbers must agree on them (1 in 10^3) as well as on
 * the six random ones, so the chance two rows collide drops from 1 in 10^6 to 1 in 10^9 -
 * and because they are the millisecond's own residue, two calls a second apart can never
 * share them either. What they no longer do is identify a run; the budget below took that
 * away.
 *
 * ## Why the budget is 4 + 3 + 6
 *
 * The field this number ends up typed into is the sign-in dialog's: a fixed `+62` followed
 * by at most 12 digits (`NASIONAL`, `/^8\d{7,11}$/`). `0896` + 3 + 6 is 13 written digits
 * - after the leading `0` is stripped, exactly the 12 the field accepts - so a generated
 * number can pass `nasionalDari` like a real one. Seven time digits used to sit here; the
 * dialog's ceiling is what shortened them, and the server's own `/^\+?[0-9]{8,20}$/` is
 * still satisfied with room to spare. The default prefix remains an Indonesian mobile
 * prefix, so the number is shaped like the thing it stands for rather than like a
 * synthetic key.
 */
export function nomorTeleponBaru(prefix = '0896'): string {
    const waktu = String(Date.now()).slice(-3);
    const acak = String(randomInt(0, 1_000_000)).padStart(6, '0');

    return `${prefix}${waktu}${acak}`;
}
