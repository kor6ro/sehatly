import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { expect, test, type Locator, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F01 `/login`, `/profil/edit/{id}?sign_up=true`, `/otp` and `/profil/perangkat`, mocked
 * end to end.
 *
 * Every `/api/v1/**` request is intercepted, so the file pins the CLIENT's branches -
 * both accepted phone spellings, the two consent gates, the optional-field grouping, the
 * OTP slot behaviour, the inline 422 with `meta.sisa_percobaan`, the 429 wait from
 * `meta.retry_after`, resend with a 60-second cooldown and exactly one request, the
 * device revoke, and both logout actions - without a live Laravel API and without
 * touching real data. Fixtures are synthetic.
 *
 * `/register` is gone from this list because it is gone from the router: there is one
 * door, it takes a phone number, and the screen that finishes the account is
 * `/profil/edit/{id}?sign_up=true`.
 *
 * Two viewports for every scenario: 390x844 and 1280x900, pinned to `Asia/Jakarta`.
 */

const VIEWPORTS = [
    { nama: 'mobile', width: 390, height: 844 },
    { nama: 'desktop', width: 1280, height: 900 },
] as const;

type HasilPost = {
    status: number;
    body: unknown;
    headers?: Record<string, string>;
};

type DeviceUji = {
    device_id: string;
    platform: string;
    fcm_token: string | null;
    app_versi: string | null;
    aktif: boolean;
    last_active_at: string | null;
    dibuat_at: string;
};

type MockState = {
    login: Array<Record<string, unknown>>;
    register: Array<Record<string, unknown>>;
    lengkapi: Array<Record<string, unknown>>;
    verify: Array<Record<string, unknown>>;
    resend: Array<Record<string, unknown>>;
    logout: Array<Record<string, unknown>>;
    logoutAll: number;
    devices: DeviceUji[];
    deletes: string[];
    getDevices: number;
};

type OpsiMock = {
    onLogin?: (body: Record<string, unknown>, state: MockState) => HasilPost;
    onRegister?: (body: Record<string, unknown>, state: MockState) => HasilPost;
    onLengkapi?: (body: Record<string, unknown>, state: MockState) => HasilPost;
    onVerify?: (body: Record<string, unknown>, state: MockState) => HasilPost;
    onResend?: (body: Record<string, unknown>, state: MockState) => HasilPost;
    onDelete?: (deviceId: string, state: MockState) => HasilPost;
    devices?: DeviceUji[];
};

const USER_F01 = {
    id: 1,
    uuid: '00000000-0000-4000-8000-000000000001',
    nama_lengkap: 'Pasien Uji F01',
    no_telepon: '081299998888',
    email: null,
    tipe: 'pasien',
    status: 'aktif',
    bahasa: 'id',
    foto_profil: null,
    telepon_terverifikasi: true,
    email_terverifikasi: false,
    last_login_at: null,
    dibuat_at: '2026-01-01T00:00:00.000000Z',
};

const DEVICE_INI: DeviceUji = {
    device_id: 'dev-uji-ini',
    platform: 'android',
    fcm_token: null,
    app_versi: '2.1.0',
    aktif: true,
    last_active_at: '2026-10-01T02:30:00.000000Z',
    dibuat_at: '2026-09-01T00:00:00.000000Z',
};

const DEVICE_LAIN: DeviceUji = {
    device_id: 'dev-uji-lain',
    platform: 'ios',
    fcm_token: null,
    app_versi: null,
    aktif: true,
    last_active_at: '2026-09-28T10:00:00.000000Z',
    dibuat_at: '2026-09-01T00:00:00.000000Z',
};

/**
 * The same account BEFORE it is an account: a `users` row minted for a number, an empty
 * name, and no `pasien` relation at all.
 *
 * This is what `POST /auth/login` writes for a passwordless call naming a number it has
 * never seen, and it is the only shape `ProfilEditPage` renders - it redirects to
 * `/dashboard` the moment `nama_lengkap !== ''` and a `pasien` row exists. A fixture that
 * kept the finished profile would therefore not test the completion form at all; it would
 * test the redirect that avoids it.
 */
const CANGKANG_F01 = {
    ...USER_F01,
    nama_lengkap: '',
    telepon_terverifikasi: false,
};

/**
 * The refusal each consent box shows on its own.
 *
 * Two strings rather than one, because the screen asks two questions with two controls.
 * A combined message would be a single line of text standing between two independent
 * checkboxes, and the user could not tell which box the sentence is about.
 */
const PESAN_SYARAT_WAJIB = 'Anda harus menyetujui Syarat dan Ketentuan.';
const PESAN_PRIVASI_WAJIB = 'Anda harus menyetujui Kebijakan Privasi.';

function otpChallenge(tujuan: 'login' | 'verifikasi_telepon'): Record<string, unknown> {
    return {
        tujuan,
        kedaluwarsa_at: new Date(Date.now() + 300_000).toISOString(),
        ttl_detik: 300,
    };
}

async function balas(route: Route, hasil: HasilPost): Promise<void> {
    await route.fulfill({
        status: hasil.status,
        contentType: 'application/json',
        ...(hasil.headers === undefined ? {} : { headers: hasil.headers }),
        body: JSON.stringify(hasil.body),
    });
}

function envelope(data: unknown, message = 'Berhasil.'): Record<string, unknown> {
    return { success: true, message, data };
}

async function pasangMock(page: Page, opsi: OpsiMock = {}): Promise<MockState> {
    const state: MockState = {
        login: [],
        register: [],
        lengkapi: [],
        verify: [],
        resend: [],
        logout: [],
        logoutAll: 0,
        devices: opsi.devices === undefined ? [] : [...opsi.devices],
        deletes: [],
        getDevices: 0,
    };

    /**
     * Registered FIRST, therefore lowest priority: Playwright gives the most recently
     * added route the first refusal, so the three specific handlers below still win and
     * this one only catches what none of them name.
     *
     * It is what makes the file's "mocked end to end" true. Without it a screen the
     * mocks do not cover - `/dashboard` after a completed sign-up, say - reaches the real
     * server with `token-uji-f01`, is answered 401, and the transport tears the session
     * down and redirects to `/login`. A test asserting "we navigated to `/dashboard`"
     * would then fail on a round trip it never intended to make, and the failure would
     * point at the assertion rather than at the missing mock.
     */
    await page.route('**/api/v1/**', async (route) => {
        await balas(route, {
            status: 500,
            body: { success: false, message: 'Rute mock tidak dikenal.', errors: {} },
        });
    });

    await page.route('**/api/v1/auth/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const jalur = url.pathname;
        const metode = request.method();

        if (jalur === '/api/v1/auth/login' && metode === 'POST') {
            const body = JSON.parse(request.postData() ?? '{}') as Record<string, unknown>;

            state.login.push(body);

            const hasil = opsi.onLogin?.(body, state) ?? {
                status: 200,
                body: envelope({ otp: otpChallenge('login') }, 'Kode OTP berhasil dikirim.'),
            };

            return balas(route, hasil);
        }

        if (jalur === '/api/v1/auth/sign-up' && metode === 'POST') {
            const body = JSON.parse(request.postData() ?? '{}') as Record<string, unknown>;

            state.register.push(body);

            const hasil = opsi.onRegister?.(body, state) ?? {
                status: 201,
                body: envelope(
                    { user: USER_F01, otp: otpChallenge('verifikasi_telepon') },
                    'Akun berhasil dibuat.',
                ),
            };

            return balas(route, hasil);
        }

        /**
         * The tail of the one-door flow: the shell, having proved its number, turns
         * itself into a patient. Authenticated, so the mock answers it like any other
         * signed-in route - the request carries a bearer the page was given at
         * `pasangSesi`, and the assertion this test makes is on the BODY that bearer
         * dragged here.
         */
        if (jalur === '/api/v1/auth/sign-up/lengkapi' && metode === 'POST') {
            const body = JSON.parse(request.postData() ?? '{}') as Record<string, unknown>;

            state.lengkapi.push(body);

            const hasil = opsi.onLengkapi?.(body, state) ?? {
                status: 201,
                body: envelope({ profil_lengkap: true }, 'Akun kamu sudah jadi.'),
            };

            return balas(route, hasil);
        }

        if (jalur === '/api/v1/auth/otp/verify' && metode === 'POST') {
            const body = JSON.parse(request.postData() ?? '{}') as Record<string, unknown>;

            state.verify.push(body);

            const hasil = opsi.onVerify?.(body, state) ?? {
                status: 200,
                body: envelope(
                    {
                        user: USER_F01,
                        token: {
                            token_type: 'Bearer',
                            access_token: 'token-uji-f01',
                            expires_in: 3600,
                            access_token_expires_at: new Date(
                                Date.now() + 3_600_000,
                            ).toISOString(),
                            refresh_token: 'refresh-uji-f01',
                            refresh_token_expires_at: new Date(
                                Date.now() + 2_592_000_000,
                            ).toISOString(),
                        },
                    },
                    'Verifikasi berhasil.',
                ),
            };

            return balas(route, hasil);
        }

        if (jalur === '/api/v1/auth/otp/resend' && metode === 'POST') {
            const body = JSON.parse(request.postData() ?? '{}') as Record<string, unknown>;

            state.resend.push(body);

            const hasil = opsi.onResend?.(body, state) ?? {
                status: 200,
                body: envelope(
                    {
                        otp: {
                            kedaluwarsa_at: new Date(Date.now() + 300_000).toISOString(),
                            ttl_detik: 300,
                            kanal: 'whatsapp',
                        },
                    },
                    'Jika akun terdaftar, kode OTP baru telah dikirim.',
                ),
            };

            return balas(route, hasil);
        }

        if (jalur === '/api/v1/auth/logout' && metode === 'POST') {
            const body = JSON.parse(request.postData() ?? '{}') as Record<string, unknown>;

            state.logout.push(body);

            return balas(route, {
                status: 200,
                body: envelope(
                    {
                        refresh_token: { dicabut: true },
                        access_token: { dihapus: true },
                        perangkat: { dimatikan: 0 },
                    },
                    'Logout berhasil.',
                ),
            });
        }

        if (jalur === '/api/v1/auth/logout-all' && metode === 'POST') {
            state.logoutAll += 1;

            return balas(route, {
                status: 200,
                body: envelope(
                    {
                        refresh_token: { dicabut: true, jumlah: 2 },
                        access_token: { dihapus: true },
                        perangkat: { dimatikan: 2 },
                    },
                    'Logout dari semua perangkat berhasil.',
                ),
            });
        }

        if (jalur === '/api/v1/auth/devices' && metode === 'GET') {
            state.getDevices += 1;

            return balas(route, {
                status: 200,
                body: {
                    ...envelope({ devices: state.devices }, 'Daftar perangkat berhasil dimuat.'),
                    meta: {
                        current_page: 1,
                        last_page: 1,
                        per_page: state.devices.length,
                        total: state.devices.length,
                        from: state.devices.length === 0 ? null : 1,
                        to: state.devices.length === 0 ? null : state.devices.length,
                    },
                },
            });
        }

        const cocokHapus = /^\/api\/v1\/auth\/devices\/([^/]+)$/.exec(jalur);

        if (cocokHapus !== null && metode === 'DELETE') {
            const deviceId = decodeURIComponent(cocokHapus[1] ?? '');

            state.deletes.push(deviceId);

            const hasil = opsi.onDelete?.(deviceId, state) ?? {
                status: 200,
                body: envelope(
                    {
                        device: state.devices.find((row) => row.device_id === deviceId) ?? null,
                    },
                    'Perangkat berhasil dicabut.',
                ),
            };

            // A refused revoke (404) leaves the row in the fixture, exactly as the server
            // does: nothing was deactivated.
            if (hasil.status < 400) {
                state.devices = state.devices.filter((row) => row.device_id !== deviceId);
            }

            return balas(route, hasil);
        }

        return balas(route, { status: 500, body: { success: false, message: 'Rute mock tidak dikenal.', errors: {} } });
    });

    await page.route('**/api/v1/me', async (route) => {
        await balas(route, {
            status: 200,
            body: envelope({ user: USER_F01 }, 'Akun berhasil dimuat.'),
        });
    });

    await page.route('**/api/v1/notifikasi**', async (route) => {
        await balas(route, {
            status: 200,
            body: {
                ...envelope({ notifikasi: [] }, 'Daftar notifikasi berhasil dimuat.'),
                meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: 5,
                    total: 0,
                    from: null,
                    to: null,
                    unread: 0,
                },
            },
        });
    });

    return state;
}

async function pasangSesi(page: Page, deviceId?: string): Promise<void> {
    await page.addInitScript((nilai: { deviceId: string | null }) => {
        sessionStorage.setItem('sehatly.access_token', 'token-uji-f01');
        sessionStorage.setItem('sehatly.refresh_token', 'refresh-uji-f01');

        if (nilai.deviceId !== null) {
            sessionStorage.setItem('sehatly.device_id', nilai.deviceId);
        }
    }, { deviceId: deviceId ?? null });
}

/**
 * A session whose account is still a SHELL, plus the `/me` that says so.
 *
 * Registered AFTER `pasangMock`, because Playwright gives the most recently registered
 * route the first refusal - so this one wins for `/me` and `pasangMock` keeps winning
 * for everything else. That matters here: `ProfilEditPage` reads `/me`, and if it saw a
 * finished account it would redirect to the landing page instead of rendering the form
 * this file is about.
 */
async function pasangSesiCangkang(page: Page): Promise<void> {
    await pasangSesi(page);

    await page.route('**/api/v1/me', async (route) => {
        await balas(route, {
            status: 200,
            body: envelope({ user: CANGKANG_F01 }, 'Akun berhasil dimuat.'),
        });
    });
}

/**
 * The completion screen's own form, one field per line.
 *
 * There is no phone field and no password: the number was proved over OTP a minute ago
 * and the endpoint refuses to take it back, and this door admits on the code alone.
 * `isiRegistrasi` is gone with `/register`.
 */
async function isiLengkapi(page: Page): Promise<void> {
    await page.getByLabel('Nama Lengkap').fill('Siti Rahma');
    await page.getByLabel('Tanggal Lahir').fill('1990-05-17');
    await page.getByLabel('Alamat Lengkap').fill('Jl. Merdeka No. 10, Bandung');
}

/**
 * Record every `sehatly:flash` the SPA dispatches.
 *
 * The sonner toast itself auto-dismisses after a few seconds, so asserting on the
 * rendered toast makes a sign-out test fail whenever the preceding steps were slow.
 * The dispatched event is the app's own contract and does not expire.
 */
async function pasangPerekamFlash(page: Page): Promise<void> {
    await page.addInitScript(() => {
        const w = window as unknown as { __flashF01?: string[] };

        w.__flashF01 = [];

        window.addEventListener('sehatly:flash', (event) => {
            const detail = (event as CustomEvent<{ message?: string }>).detail;

            if (typeof detail?.message === 'string') {
                w.__flashF01?.push(detail.message);
            }
        });
    });
}

async function pesanFlash(page: Page): Promise<string[]> {
    return await page.evaluate(() => {
        const w = window as unknown as { __flashF01?: string[] };

        return w.__flashF01 ?? [];
    });
}

async function pasangPendingOtp(
    page: Page,
    opsi: { kedaluwarsaMs?: number; noTelepon?: string } = {},
): Promise<void> {
    await page.addInitScript(
        (nilai: { kedaluwarsaMs: number; noTelepon: string }) => {
            sessionStorage.setItem('sehatly.device_id', 'dev-uji-otp');
            sessionStorage.setItem(
                'sehatly.pending_otp',
                JSON.stringify({
                    identifier: { no_telepon: nilai.noTelepon },
                    tujuan: 'login',
                    kedaluwarsa_at: new Date(Date.now() + nilai.kedaluwarsaMs).toISOString(),
                    ttl_detik: Math.round(nilai.kedaluwarsaMs / 1000),
                }),
            );
        },
        { kedaluwarsaMs: opsi.kedaluwarsaMs ?? 300_000, noTelepon: opsi.noTelepon ?? '081299998888' },
    );
}

async function ukuranTarget(locator: Locator): Promise<void> {
    const kotak = await locator.boundingBox();

    expect(kotak, 'target harus punya bounding box').not.toBeNull();
    expect(Math.round((kotak as { height: number }).height)).toBeGreaterThanOrEqual(44);
}

/**
 * The login screen sends a number and nothing else.
 *
 * There is no password field on it, so this helper cannot fill one - a helper typing into
 * a control the screen does not have would fail before the first assertion rather than at
 * it. What the request carries is asserted directly on `state.login`, below.
 */
async function isiLogin(page: Page, identitas: string): Promise<void> {
    await page.getByLabel('Nomor telepon').fill(identitas);
}

async function isiOtp(page: Page, kode: string): Promise<void> {
    await page.locator('[data-input-otp]').pressSequentially(kode);
}

/**
 * One consent box each, never the pair.
 *
 * `/Saya menyetujui/` matches two controls now, and Playwright resolves a role locator to
 * exactly one element - so the shared regex would throw a strict-mode violation instead
 * of ticking anything. Two functions rather than an index, because the two boxes are two
 * different decisions and a test that names the one it means cannot tick the other by
 * accident.
 */
function kotakSyarat(page: Page): Locator {
    return page.getByRole('checkbox', { name: /Syarat dan Ketentuan/ });
}

function kotakPrivasi(page: Page): Locator {
    return page.getByRole('checkbox', { name: /Kebijakan Privasi/ });
}

for (const vp of VIEWPORTS) {
    test.describe(`F01 auth ${vp.nama} ${vp.width}x${vp.height}`, () => {
        test.use({
            viewport: { width: vp.width, height: vp.height },
            timezoneId: 'Asia/Jakarta',
        });

        test('f01-ac1-nomor-dua-bentuk', async ({ page }) => {
            const state = await pasangMock(page);

            await page.goto('/login');

            await expect(page.getByLabel('Nomor telepon')).toBeVisible();
            await expect(
                page.getByText(
                    'Gunakan nomor yang aktif di WhatsApp/SMS. Format 08xx atau +628xx sama-sama diterima.',
                ),
            ).toBeVisible();
            await expect(page.getByPlaceholder('08xx xxxx xxxx')).toBeVisible();
            await expect(
                page.getByText(
                    'Kami akan mengirim kode 6 digit ke nomor ini untuk memastikan akun Anda aman.',
                ),
            ).toBeVisible();

            // The international spelling is accepted now that the server normalises it,
            // and it is sent as typed: the client does not canonicalise.
            await isiLogin(page, '+6281234567890');
            await page.getByRole('button', { name: 'Lanjutkan' }).click();

            /**
             * Exactly one key, on purpose.
             *
             * `toEqual` fails on an EXTRA property, so this is what proves the door is
             * the only one: a `password` reappearing in the request would fail here even
             * though the field it came from is gone from the screen.
             */
            await expect.poll(() => state.login.length).toBe(1);
            expect(state.login[0]).toEqual({ no_telepon: '+6281234567890' });
            await expect(page).toHaveURL(/\/otp$/);

            // The local spelling still works and is sent unchanged too.
            await page.goto('/login');
            await isiLogin(page, '081234567890');
            await page.getByRole('button', { name: 'Lanjutkan' }).click();

            await expect.poll(() => state.login.length).toBe(2);
            expect(state.login[1]).toEqual({ no_telepon: '081234567890' });
        });

        test('f01-ac2-opsional', async ({ page }) => {
            const state = await pasangMock(page);

            await pasangSesiCangkang(page);
            await page.goto('/profil/edit/1?sign_up=true');

            await expect(page.getByLabel('Nama Lengkap')).toBeVisible();
            await expect(page.getByLabel('Alamat Lengkap')).toBeVisible();

            await expect(page.getByLabel('Email (Opsional)')).toBeVisible();
            await expect(page.getByLabel('Tempat Lahir (Opsional)')).toBeVisible();

            /**
             * Four marked fields, not six.
             *
             * The two that used to be required here - the phone number and the password -
             * are no longer asked by anyone: the number was proved over OTP before this
             * page loaded and the endpoint refuses to take it back, and the door admits on
             * the code alone. What remains required is the four columns `pasien` cannot be
             * written without, and only those four carry a `*`.
             */
            await expect(
                page.locator('[aria-hidden="true"]', { hasText: '*' }),
            ).toHaveCount(4);

            await isiLengkapi(page);
            /**
             * Clicked on the CARD, not on the radio.
             *
             * The input is `sr-only`: it has a one-pixel box the label sits on top of, so
             * `.check()` aims at the middle of the input and finds the `<label>` intercepting
             * the pointer - forever, because that is by design. The card is what a user
             * touches, and clicking it is what activates the control underneath; asserting
             * `toBeChecked` afterwards is what proves the click was not swallowed.
             */
            await page.getByText('Perempuan', { exact: true }).click();
            await expect(
                page.getByRole('radio', { name: 'Perempuan' }),
            ).toBeChecked();

            await kotakSyarat(page).check();
            await kotakPrivasi(page).check();

            await page.getByRole('button', { name: 'Buat Akun' }).click();

            await expect.poll(() => state.lengkapi.length).toBe(1);

            // The landing page, not the dashboard: this is the one destination every
            // door in the app shares now, and the hero strip is what says so.
            await expect(page).toHaveURL(/\/$/);
            await expect(page.locator('[data-slot="hero-carousel"]')).toBeVisible();

            const body = state.lengkapi[0] as Record<string, unknown>;

            expect('email' in body).toBe(false);
            expect('tempat_lahir' in body).toBe(false);

            /**
             * And neither of the two facts this door already proved travels with it.
             * `no_telepon` is resolved from the bearer server-side and the endpoint has no
             * rule for it; `password` no longer exists in this flow at all. Both are
             * asserted by their ABSENCE, so a regression that re-added either key would
             * fail here rather than on the server's 422.
             */
            expect(body).not.toHaveProperty('no_telepon');
            expect(body).not.toHaveProperty('password');

            expect(body.nama_lengkap).toBe('Siti Rahma');
            expect(body.jenis_kelamin).toBe('P');
            expect(body.tanggal_lahir).toBe('1990-05-17');
            expect(body.alamat_lengkap).toBe('Jl. Merdeka No. 10, Bandung');
        });

        test('f01-ac2b-persetujuan-wajib', async ({ page }) => {
            const state = await pasangMock(page);

            await pasangSesiCangkang(page);
            await page.goto('/profil/edit/1?sign_up=true');

            // Two boxes, two sentences, two links. A visitor has to be able to reach the
            // document each one is about without ticking either.
            await expect(kotakSyarat(page)).toBeVisible();
            await expect(kotakPrivasi(page)).toBeVisible();

            // Scoped to the form: the left column carries its OWN privacy notice, and a
            // page-level locator for that link name resolves to two and throws a
            // strict-mode violation instead of asserting anything.
            const formulir = page.locator('form');

            await expect(
                formulir.getByRole('link', { name: 'Syarat dan Ketentuan' }),
            ).toHaveAttribute('href', '/syarat-ketentuan');

            await expect(
                formulir.getByRole('link', { name: 'Kebijakan Privasi' }),
            ).toHaveAttribute('href', '/kebijakan-privasi');

            await isiLengkapi(page);

            /**
             * Unchecked: the submit is blocked client-side, the server is never asked, and
             * each refusal names its OWN box.
             *
             * Two messages rather than one is the assertion, not a detail of it: a single
             * combined sentence under two independent checkboxes cannot tell the visitor
             * which of the two decisions they have not made.
             */
            await page.getByRole('button', { name: 'Buat Akun' }).click();

            await expect(page.getByText(PESAN_SYARAT_WAJIB)).toBeVisible();
            await expect(page.getByText(PESAN_PRIVASI_WAJIB)).toBeVisible();
            expect(state.lengkapi.length).toBe(0);

            // One ticked: the other still refuses, because the ledger records two
            // decisions and neither one can be taken on the other's behalf.
            await kotakSyarat(page).check();
            await page.getByRole('button', { name: 'Buat Akun' }).click();

            await expect(page.getByText(PESAN_SYARAT_WAJIB)).toHaveCount(0);
            await expect(page.getByText(PESAN_PRIVASI_WAJIB)).toBeVisible();
            expect(state.lengkapi.length).toBe(0);

            // Both: one request, and both mandatory booleans are `true`.
            await kotakPrivasi(page).check();
            await page.getByRole('button', { name: 'Buat Akun' }).click();

            await expect.poll(() => state.lengkapi.length).toBe(1);
            await expect(page).toHaveURL(/\/$/);
            await expect(page.locator('[data-slot="hero-carousel"]')).toBeVisible();

            const body = state.lengkapi[0] as Record<string, unknown>;

            expect(body.persetujuan_syarat_ketentuan).toBe(true);
            expect(body.persetujuan_kebijakan_privasi).toBe(true);
        });

        test('f01-ac3-otp-input', async ({ page }) => {
            await pasangMock(page);
            await pasangPendingOtp(page);

            await page.goto('/otp');

            const input = page.locator('[data-input-otp]');

            await expect(input).toHaveAttribute('autocomplete', 'one-time-code');
            await expect(input).toHaveAttribute('inputmode', 'numeric');
            await expect(input).toBeFocused();

            const slots = page.locator('[data-input-otp-container] div.h-11');

            await expect(slots).toHaveCount(6);

            // `insertText` is the paste-shaped insertion: one input event carrying all six.
            await page.keyboard.insertText('123456');
            await expect(input).toHaveValue('123456');

            await input.press('Backspace');
            await expect(input).toHaveValue('12345');

            await input.press('Backspace');
            await expect(input).toHaveValue('1234');
            await expect(input).toBeFocused();

            expect(await page.evaluate(() => window.scrollY)).toBe(0);
        });

        test('f01-ac4-kedaluwarsa-kirim-ulang', async ({ page }) => {
            const state = await pasangMock(page);

            await pasangPendingOtp(page, { kedaluwarsaMs: -2_000 });
            await page.goto('/otp');

            await expect(page.getByText('Kode sudah kedaluwarsa.')).toBeVisible();
            await expect(
                page.getByText('Kirim ulang dengan masuk kembali'),
            ).toHaveCount(0);

            const resend = page.locator('[data-slot="otp-resend"]');

            await expect(resend).toBeVisible();
            await expect(resend).toBeEnabled();
            await expect(resend).toHaveText('Kirim ulang kode');

            // One click, one request, addressed to the same identifier the verify call
            // will use, and with the locally stored device id.
            await resend.click();

            await expect.poll(() => state.resend.length).toBe(1);
            expect(state.resend[0]).toEqual({
                no_telepon: '081299998888',
                tujuan: 'login',
                device_id: 'dev-uji-otp',
            });

            // AC-4: the countdown is replaced from the response, not restarted beside it.
            await expect(
                page.getByText(/Kode baru telah dikirim ke nomor 0812\*+88\./),
            ).toBeVisible();
            await expect(page.getByText('Kode sudah kedaluwarsa.')).toHaveCount(0);
            await expect(page.locator('[data-slot="otp-countdown"]')).toHaveCount(1);
            await expect(page.locator('[data-slot="otp-countdown"]')).toContainText(
                /Kode berlaku \d+ menit \d+ detik lagi\./,
            );

            // The cooldown is visible on the button itself.
            await expect(resend).toBeDisabled();
            await expect(resend).toContainText(/Kirim ulang kode \(\d{1,2} dtk\)/);
        });

        test('f01-ac5-422-kedaluwarsa', async ({ page }) => {
            const state = await pasangMock(page, {
                onVerify: () => ({
                    status: 422,
                    body: {
                        success: false,
                        message: 'The given data was invalid.',
                        errors: {
                            kode: ['Kode OTP sudah kedaluwarsa. Silakan minta kode baru.'],
                        },
                    },
                }),
            });

            await pasangPendingOtp(page);
            await page.goto('/otp');

            await isiOtp(page, '123456');
            await page.getByRole('button', { name: 'Verifikasi' }).click();

            await expect(
                page.getByText('Kode OTP sudah kedaluwarsa. Silakan minta kode baru.'),
            ).toBeVisible();
            await expect(page.locator('[data-input-otp]')).toHaveValue('123456');
            await expect(page.locator('[data-input-otp]')).toBeFocused();
            await expect(page.locator('[data-sonner-toast]')).toHaveCount(0);
            expect(state.verify.length).toBe(1);
        });

        test('f01-ac5-422-sudah-dipakai', async ({ page }) => {
            await pasangMock(page, {
                onVerify: () => ({
                    status: 422,
                    body: {
                        success: false,
                        message: 'The given data was invalid.',
                        errors: { kode: ['Kode OTP sudah pernah dipakai.'] },
                    },
                }),
            });

            await pasangPendingOtp(page);
            await page.goto('/otp');

            await isiOtp(page, '123456');
            await page.getByRole('button', { name: 'Verifikasi' }).click();

            await expect(
                page.getByText('Kode ini sudah dipakai. Minta kode baru.'),
            ).toBeVisible();
        });

        test('f01-ac6-429', async ({ page }) => {
            const state = await pasangMock(page, {
                onVerify: () => ({
                    status: 429,
                    body: {
                        success: false,
                        message: 'Terlalu banyak percobaan.',
                        errors: {},
                        // The wait now comes from `meta.retry_after`, not from a header.
                        // The real limiter also publishes `sisa_percobaan: 0`, which must
                        // not render beside the wait message.
                        meta: { retry_after: 45, sisa_percobaan: 0 },
                    },
                }),
            });

            await pasangPendingOtp(page);
            await page.goto('/otp');

            await isiOtp(page, '123456');
            await page.getByRole('button', { name: 'Verifikasi' }).click();

            await expect(
                page.getByText('Terlalu banyak percobaan. Coba lagi dalam 45 detik.'),
            ).toBeVisible();
            await expect(page.locator('[data-slot="otp-attempts"]')).toHaveCount(0);

            // One click, one request; the disabled while in-flight button cannot stack a
            // second one.
            expect(state.verify.length).toBe(1);
        });

        test('f01-ac6-429-tanpa-waktu', async ({ page }) => {
            await pasangMock(page, {
                onVerify: () => ({
                    status: 429,
                    body: {
                        success: false,
                        message: 'Terlalu banyak percobaan. Coba lagi nanti.',
                        errors: {},
                    },
                }),
            });

            await pasangPendingOtp(page);
            await page.goto('/otp');

            await isiOtp(page, '123456');
            await page.getByRole('button', { name: 'Verifikasi' }).click();

            // No `meta.retry_after` and no header: the server's own message is shown.
            await expect(
                page.getByText('Terlalu banyak percobaan. Coba lagi nanti.'),
            ).toBeVisible();
        });

        test('f01-ac6b-sisa-percobaan', async ({ page }) => {
            const state = await pasangMock(page, {
                onVerify: () => ({
                    status: 422,
                    body: {
                        success: false,
                        message: 'The given data was invalid.',
                        errors: { kode: ['Kode OTP tidak valid.'] },
                        meta: { sisa_percobaan: 3 },
                    },
                }),
            });

            await pasangPendingOtp(page);
            await page.goto('/otp');

            await isiOtp(page, '123456');
            await page.getByRole('button', { name: 'Verifikasi' }).click();

            await expect(
                page.getByText('Sisa 3 percobaan. Setelah habis, minta kode baru.'),
            ).toBeVisible();
            expect(state.verify.length).toBe(1);
        });

        test('f01-ac7-resend-cooldown', async ({ page }) => {
            const state = await pasangMock(page);

            await pasangPendingOtp(page);
            await page.goto('/otp');

            const resend = page.locator('[data-slot="otp-resend"]');

            await expect(resend).toBeEnabled();

            // Two clicks in the same tick, before React can re-render the disabled
            // state: the synchronous ref latch is what keeps this to one request.
            await resend.evaluate((el) => {
                const tombol = el as HTMLButtonElement;
                tombol.click();
                tombol.click();
            });

            await expect.poll(() => state.resend.length).toBe(1);

            await page.waitForTimeout(300);

            expect(state.resend.length).toBe(1);
            await expect(resend).toBeDisabled();
            await expect(resend).toContainText(/Kirim ulang kode \(\d{1,2} dtk\)/);

            // One deadline, not two.
            await expect(page.locator('[data-slot="otp-countdown"]')).toHaveCount(1);
            await expect(page.locator('[data-slot="otp-countdown"]')).toContainText(
                /Kode berlaku \d+ menit \d+ detik lagi\./,
            );
        });

        test('f01-ac8-offline', async ({ page, context }) => {
            const state = await pasangMock(page);

            await pasangPendingOtp(page);
            await page.goto('/otp');

            const input = page.locator('[data-input-otp]');

            await isiOtp(page, '123456');

            let permintaanAuth = 0;

            page.on('request', (request) => {
                if (request.url().includes('/api/v1/auth')) {
                    permintaanAuth += 1;
                }
            });

            await context.setOffline(true);

            await expect(page.getByTestId('offline-banner')).toBeVisible();

            const verify = page.locator('[data-slot="otp-verify"]');
            const resend = page.locator('[data-slot="otp-resend"]');

            await expect(verify).toHaveAttribute('aria-disabled', 'true');
            await expect(resend).toHaveAttribute('aria-disabled', 'true');
            await expect(
                page.getByText(
                    'Anda sedang luring. Verifikasi dan kirim ulang tidak tersedia sampai koneksi kembali.',
                ),
            ).toBeVisible();

            const sebelum = permintaanAuth;

            await verify.click({ force: true });
            await resend.click({ force: true });
            await page.waitForTimeout(300);

            expect(permintaanAuth).toBe(sebelum);
            expect(state.verify.length).toBe(0);
            expect(state.resend.length).toBe(0);
            await expect(input).toHaveValue('123456');

            await context.setOffline(false);

            await expect(page.getByTestId('offline-banner')).toHaveCount(0);
        });

        test('f01-ac9-500-login', async ({ page }) => {
            const state = await pasangMock(page, {
                onLogin: () => ({
                    status: 500,
                    body: {
                        success: false,
                        message: 'Terjadi kesalahan pada server. Coba lagi nanti.',
                        errors: {},
                    },
                }),
            });

            await page.goto('/login');
            await isiLogin(page, '081299998888');
            await page.getByRole('button', { name: 'Lanjutkan' }).click();

            await expect(page.getByLabel('Nomor telepon')).toHaveValue('081299998888');
            await expect(page.getByRole('button', { name: 'Coba lagi' })).toBeVisible();
            expect(state.login.length).toBe(1);
        });

        test('f01-ac10-perangkat', async ({ page }) => {
            const state = await pasangMock(page, {
                devices: [DEVICE_INI, DEVICE_LAIN],
            });

            await pasangSesi(page, 'dev-uji-ini');
            await page.goto('/profil/perangkat');

            await expect(
                page.getByRole('heading', { name: 'Perangkat yang masuk', level: 1 }),
            ).toBeVisible();
            await expect(page.getByText('Cabut perangkat yang tidak Anda kenali.')).toBeVisible();
            await expect(page.locator('[data-slot="device-row"]')).toHaveCount(2);
            await expect(page.getByText('Perangkat Android 2.1.0')).toBeVisible();
            await expect(page.getByText('Perangkat iOS')).toBeVisible();
            await expect(
                page.getByText('Perangkat ini', { exact: true }),
            ).toHaveCount(1);
            await expect(page.getByText(/Android • Terakhir aktif/)).toBeVisible();

            const barisIos = page.locator('[data-slot="device-row"][data-platform="ios"]');
            const dialog = page.locator('[data-slot="device-revoke-dialog"]');

            await barisIos.getByRole('button', { name: 'Cabut' }).click();

            await expect(
                dialog.getByRole('heading', { name: 'Cabut perangkat ini?' }),
            ).toBeVisible();

            // The dialog does NOT claim the other device's session ends; it states the
            // real effect and the deferred token-to-device mapping.
            await expect(
                dialog.getByText(/Perangkat ini dihapus dari daftar\./),
            ).toBeVisible();
            await expect(
                dialog.getByText(/Mengakhiri sesi perangkat lain dari daftar ini belum tersedia/),
            ).toBeVisible();
            await expect(dialog.getByRole('button', { name: 'Batal' })).toBeVisible();

            await dialog.getByRole('button', { name: 'Batal' }).click();

            await expect(dialog).toHaveCount(0);
            expect(state.deletes.length).toBe(0);

            await barisIos.getByRole('button', { name: 'Cabut' }).click();
            await dialog.getByRole('button', { name: 'Ya, cabut' }).click();

            await expect.poll(() => state.deletes.length).toBe(1);
            expect(state.deletes[0]).toBe('dev-uji-lain');
            await expect(page.getByText('Perangkat telah dicabut.')).toBeVisible();
            await expect(dialog).toHaveCount(0);
        });

        test('f01-ac10-perangkat-404', async ({ page }) => {
            const state = await pasangMock(page, {
                devices: [DEVICE_LAIN],
                onDelete: () => ({
                    status: 404,
                    body: {
                        success: false,
                        message: 'Resource not found.',
                        errors: {},
                    },
                }),
            });

            await pasangSesi(page, 'dev-uji-ini');
            await page.goto('/profil/perangkat');

            await page
                .locator('[data-slot="device-row"]')
                .getByRole('button', { name: 'Cabut' })
                .click();
            await page
                .locator('[data-slot="device-revoke-dialog"]')
                .getByRole('button', { name: 'Ya, cabut' })
                .click();

            await expect(page.getByText('Perangkat tidak ditemukan.')).toBeVisible();
            expect(state.deletes.length).toBe(1);
        });

        test('f01-ac10b-logout-perangkat-ini', async ({ page }) => {
            const state = await pasangMock(page, {
                devices: [DEVICE_INI, DEVICE_LAIN],
            });

            await pasangPerekamFlash(page);
            await pasangSesi(page, 'dev-uji-ini');
            await page.goto('/profil/perangkat');

            await page.locator('[data-slot="device-logout"]').click();

            await expect.poll(() => state.logout.length).toBe(1);
            expect(state.logout[0]).toEqual({ refresh_token: 'refresh-uji-f01' });

            await expect(page).toHaveURL(/\/login$/);
            await expect.poll(() => pesanFlash(page)).toContain('Anda telah keluar.');

            // The local pair is gone: a reload of a guarded page cannot resurrect it.
            const tersimpan = await page.evaluate(() =>
                sessionStorage.getItem('sehatly.refresh_token'),
            );

            expect(tersimpan).toBeNull();
        });

        test('f01-ac10c-logout-semua-perangkat', async ({ page }) => {
            const state = await pasangMock(page, {
                devices: [DEVICE_INI, DEVICE_LAIN],
            });

            await pasangPerekamFlash(page);
            await pasangSesi(page, 'dev-uji-ini');
            await page.goto('/profil/perangkat');

            await page.locator('[data-slot="device-logout-all"]').click();

            const dialog = page.locator('[data-slot="device-logout-all-dialog"]');

            await expect(
                dialog.getByRole('heading', { name: 'Keluar dari semua perangkat?' }),
            ).toBeVisible();
            await expect(dialog.getByText(/termasuk perangkat ini/)).toBeVisible();
            await expect(dialog.getByText(/Anda perlu masuk kembali/)).toBeVisible();

            // Cancel first: nothing is sent.
            await dialog.getByRole('button', { name: 'Batal' }).click();

            await expect(dialog).toHaveCount(0);
            expect(state.logoutAll).toBe(0);

            await page.locator('[data-slot="device-logout-all"]').click();
            await page.locator('[data-slot="device-logout-all-confirm"]').click();

            await expect.poll(() => state.logoutAll).toBe(1);
            await expect(page).toHaveURL(/\/login$/);
            await expect
                .poll(() => pesanFlash(page))
                .toContain('Anda telah keluar dari semua perangkat.');
        });

        test('f01-ac11-privasi', async ({ page }) => {
            await pasangMock(page);

            const pesanKonsol: string[] = [];

            page.on('console', (pesan) => {
                pesanKonsol.push(pesan.text());
            });

            await page.goto('/login');

            await expect(page).toHaveTitle('Masuk | Sehatly');

            await isiLogin(page, '081299998888');
            await page.getByRole('button', { name: 'Lanjutkan' }).click();

            await expect(page).toHaveURL(/\/otp$/);
            await expect(page).toHaveTitle('Kode OTP | Sehatly');

            expect(page.url()).not.toContain('081299998888');
            expect(page.url()).not.toContain('123456');

            const ariaLabels = await page
                .locator('[aria-label]')
                .evaluateAll((elemen) =>
                    elemen.map((el) => el.getAttribute('aria-label') ?? '').join(' | '),
                );

            expect(ariaLabels).not.toContain('081299998888');
            expect(ariaLabels).not.toContain('0812');

            // The masked form is what the OTP screen shows, and the full number is nowhere.
            await expect(page.getByText(/0812\*+88/)).toBeVisible();
            await expect(page.getByText('081299998888')).toHaveCount(0);
            await expect(page.locator('[data-sonner-toast]')).toHaveCount(0);
            expect(pesanKonsol.join(' ')).not.toContain('081299998888');
        });

        test('f01-ac12-a11y', async ({ page }) => {
            await pasangMock(page);

            await page.goto('/login');
            await expectNoA11yViolations(page);
            await ukuranTarget(page.getByRole('button', { name: 'Lanjutkan' }));
            await ukuranTarget(page.getByRole('button', { name: 'Telepon' }));

            /**
             * The session is installed only NOW, not before the `/login` pass above.
             *
             * A signed-in visitor has no business on the sign-in screen, so the route
             * sends them elsewhere and the "Lanjutkan" button never renders - `addInitScript`
             * would have made the first half of this test assert against a page the app
             * deliberately no longer shows them. `page.route` for `/me` is likewise
             * registered after `pasangMock` so this shell, not the finished fixture, is
             * what the completion screen reads.
             */
            await pasangSesiCangkang(page);

            // The account-creation screen, which is now this path and no longer
            // `/register`: one door, and this is where the door finishes its work.
            await page.goto('/profil/edit/1?sign_up=true');
            await expectNoA11yViolations(page);
            await ukuranTarget(page.getByRole('button', { name: 'Buat Akun' }));

            const kotakPersetujuan = await kotakSyarat(page).boundingBox();

            expect(kotakPersetujuan, 'checkbox consent harus punya bounding box').not.toBeNull();
            expect(
                Math.round((kotakPersetujuan as { width: number }).width),
            ).toBeGreaterThanOrEqual(24);
            expect(
                Math.round((kotakPersetujuan as { height: number }).height),
            ).toBeGreaterThanOrEqual(24);

            await pasangPendingOtp(page);
            await page.goto('/otp');
            await expectNoA11yViolations(page);
            await ukuranTarget(page.locator('[data-slot="otp-verify"]'));
            await ukuranTarget(page.locator('[data-slot="otp-resend"]'));

            const slots = page.locator('[data-input-otp-container] div.h-11');
            const jumlah = await slots.count();

            expect(jumlah).toBe(6);

            for (let i = 0; i < jumlah; i += 1) {
                const kotak = await slots.nth(i).boundingBox();

                expect(kotak, 'slot harus punya bounding box').not.toBeNull();
                expect(Math.round((kotak as { width: number }).width)).toBeGreaterThanOrEqual(44);
                expect(Math.round((kotak as { height: number }).height)).toBeGreaterThanOrEqual(44);
            }

            const pertama = await slots.nth(0).boundingBox();
            const kedua = await slots.nth(1).boundingBox();
            const jarak =
                (kedua as { x: number }).x -
                ((pertama as { x: number }).x + (pertama as { width: number }).width);

            expect(Math.round(jarak)).toBeGreaterThanOrEqual(8);
        });
    });
}

/**
 * Evidence capture only. `SEHATLY_F01_SHOTS=1` writes the 390 and 1280 screenshots under
 * `web/ux/refs/f01/` (gitignored); without it every capture test is skipped, so the normal
 * suite writes nothing to disk. One test per page per width, because each `page` fixture
 * gets a fresh context and the session/boot scripts must not leak between captures.
 */
async function simpanBukti(page: Page, nama: string, lebar: number): Promise<void> {
    const berkas = path.resolve(process.cwd(), `ux/refs/f01/${nama}-${lebar}.png`);

    mkdirSync(path.dirname(berkas), { recursive: true });

    await page.screenshot({ path: berkas, fullPage: true });
}

for (const lebar of [390, 1280] as const) {
    const tinggi = lebar === 390 ? 844 : 900;
    const rekam = process.env.SEHATLY_F01_SHOTS === '1';

    test(`f01-bukti-login-${lebar}`, async ({ page }) => {
        test.skip(!rekam, 'capture only on demand');

        await pasangMock(page);
        await page.setViewportSize({ width: lebar, height: tinggi });
        await page.goto('/login');
        await page.waitForLoadState('networkidle');

        await simpanBukti(page, 'login', lebar);
    });

    /**
     * Evidence for the account-creation screen, which is `/profil/edit/{id}?sign_up=true`
     * and no longer `/register`. Renamed with the screen: a screenshot whose filename
     * points at a route the router no longer has would be evidence of nothing.
     */
    test(`f01-bukti-daftar-${lebar}`, async ({ page }) => {
        test.skip(!rekam, 'capture only on demand');

        await pasangMock(page);
        await pasangSesiCangkang(page);
        await page.setViewportSize({ width: lebar, height: tinggi });
        await page.goto('/profil/edit/1?sign_up=true');
        await page.waitForLoadState('networkidle');

        await simpanBukti(page, 'daftar', lebar);
    });

    test(`f01-bukti-otp-${lebar}`, async ({ page }) => {
        test.skip(!rekam, 'capture only on demand');

        await pasangMock(page);
        await pasangPendingOtp(page);
        await page.setViewportSize({ width: lebar, height: tinggi });
        await page.goto('/otp');
        await page.waitForLoadState('networkidle');

        await simpanBukti(page, 'otp', lebar);
    });

    test(`f01-bukti-otp-kedaluwarsa-${lebar}`, async ({ page }) => {
        test.skip(!rekam, 'capture only on demand');

        await pasangMock(page);
        await pasangPendingOtp(page, { kedaluwarsaMs: -2_000 });
        await page.setViewportSize({ width: lebar, height: tinggi });
        await page.goto('/otp');
        await page.waitForLoadState('networkidle');

        await simpanBukti(page, 'otp-kedaluwarsa', lebar);
    });

    test(`f01-bukti-perangkat-${lebar}`, async ({ page }) => {
        test.skip(!rekam, 'capture only on demand');

        await pasangMock(page, { devices: [DEVICE_INI, DEVICE_LAIN] });
        await pasangSesi(page, 'dev-uji-ini');
        await page.setViewportSize({ width: lebar, height: tinggi });
        await page.goto('/profil/perangkat');
        await page.waitForLoadState('networkidle');

        await simpanBukti(page, 'perangkat', lebar);
    });

    test(`f01-bukti-perangkat-kosong-${lebar}`, async ({ page }) => {
        test.skip(!rekam, 'capture only on demand');

        await pasangMock(page, { devices: [] });
        await pasangSesi(page, 'dev-uji-ini');
        await page.setViewportSize({ width: lebar, height: tinggi });
        await page.goto('/profil/perangkat');
        await page.waitForLoadState('networkidle');

        await simpanBukti(page, 'perangkat-kosong', lebar);
    });

    test(`f01-bukti-perangkat-dialog-${lebar}`, async ({ page }) => {
        test.skip(!rekam, 'capture only on demand');

        await pasangMock(page, { devices: [DEVICE_INI, DEVICE_LAIN] });
        await pasangSesi(page, 'dev-uji-ini');
        await page.setViewportSize({ width: lebar, height: tinggi });
        await page.goto('/profil/perangkat');
        await page
            .locator('[data-slot="device-row"][data-platform="ios"]')
            .getByRole('button', { name: 'Cabut' })
            .click();

        await simpanBukti(page, 'perangkat-dialog', lebar);
    });

    test(`f01-bukti-perangkat-logout-all-${lebar}`, async ({ page }) => {
        test.skip(!rekam, 'capture only on demand');

        await pasangMock(page, { devices: [DEVICE_INI, DEVICE_LAIN] });
        await pasangSesi(page, 'dev-uji-ini');
        await page.setViewportSize({ width: lebar, height: tinggi });
        await page.goto('/profil/perangkat');
        await page.locator('[data-slot="device-logout-all"]').click();

        await simpanBukti(page, 'perangkat-logout-all', lebar);
    });
}
