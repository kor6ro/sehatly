import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { expect, test, type Locator, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F01 `/login`, `/register`, `/otp` and `/profil/perangkat`, mocked end to end.
 *
 * Every `/api/v1/auth/**` request is intercepted, so the file pins the CLIENT's branches -
 * the interim phone rule, the optional-field grouping, the OTP slot behaviour, the inline
 * 422, the 429 wait, offline gating, the device revoke - without a live Laravel API and
 * without touching real data. Fixtures are synthetic.
 *
 * AC-4 (resend after expiry) and AC-7 (resend cooldown with exactly one request) are
 * deliberately absent: `POST /auth/otp/resend` does not exist, so the resend control is
 * rendered disabled with a visible reason. Faking those tests would certify a request that
 * cannot be made. `f01-ac4-copy-kedaluwarsa` covers only the honest part that IS built:
 * the expired copy and the absence of the old "masuk kembali" sentence.
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
    verify: Array<Record<string, unknown>>;
    devices: DeviceUji[];
    deletes: string[];
    getDevices: number;
};

type OpsiMock = {
    onLogin?: (body: Record<string, unknown>, state: MockState) => HasilPost;
    onRegister?: (body: Record<string, unknown>, state: MockState) => HasilPost;
    onVerify?: (body: Record<string, unknown>, state: MockState) => HasilPost;
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
        verify: [],
        devices: opsi.devices === undefined ? [] : [...opsi.devices],
        deletes: [],
        getDevices: 0,
    };

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

        if (jalur === '/api/v1/auth/register' && metode === 'POST') {
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

async function pasangPendingOtp(
    page: Page,
    opsi: { kedaluwarsaMs?: number; noTelepon?: string } = {},
): Promise<void> {
    await page.addInitScript(
        (nilai: { kedaluwarsaMs: number; noTelepon: string }) => {
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

async function isiLogin(
    page: Page,
    nilai: { identitas: string; sandi: string },
): Promise<void> {
    await page.getByLabel('Nomor telepon').fill(nilai.identitas);
    await page.getByLabel('Kata sandi').fill(nilai.sandi);
}

async function isiOtp(page: Page, kode: string): Promise<void> {
    await page.locator('[data-input-otp]').pressSequentially(kode);
}

for (const vp of VIEWPORTS) {
    test.describe(`F01 auth ${vp.nama} ${vp.width}x${vp.height}`, () => {
        test.use({
            viewport: { width: vp.width, height: vp.height },
            timezoneId: 'Asia/Jakarta',
        });

        test('f01-ac1-nomor-telepon-interim', async ({ page }) => {
            const state = await pasangMock(page);

            await page.goto('/login');

            await expect(page.getByLabel('Nomor telepon')).toBeVisible();
            await expect(
                page.getByText('Gunakan nomor yang aktif di WhatsApp/SMS. Contoh: 0812 3456 7890.'),
            ).toBeVisible();
            await expect(page.getByPlaceholder('08xx xxxx xxxx')).toBeVisible();
            await expect(
                page.getByText(
                    'Kami akan mengirim kode 6 digit ke nomor ini untuk memastikan akun Anda aman.',
                ),
            ).toBeVisible();

            await isiLogin(page, { identitas: '+6281234567890', sandi: 'rahasia123' });
            await page.getByRole('button', { name: 'Lanjutkan' }).click();

            await expect(
                page.getByText('Gunakan format 08xx xxxx xxxx (tanpa awalan +62).'),
            ).toBeVisible();
            expect(state.login.length).toBe(0);

            await page.getByLabel('Nomor telepon').fill('081234567890');
            await page.getByRole('button', { name: 'Lanjutkan' }).click();

            await expect.poll(() => state.login.length).toBe(1);
            expect(state.login[0]).toEqual({
                no_telepon: '081234567890',
                password: 'rahasia123',
            });
            await expect(page).toHaveURL(/\/otp$/);
        });

        test('f01-ac2-opsional', async ({ page }) => {
            const state = await pasangMock(page);

            await page.goto('/register');

            await expect(page.getByRole('group', { name: 'Data akun' })).toBeVisible();
            await expect(page.getByRole('group', { name: 'Data diri' })).toBeVisible();

            await expect(page.getByLabel('Email (opsional)')).toBeVisible();
            await expect(page.getByLabel('Tempat lahir (opsional)')).toBeVisible();

            // The six required server fields are the only ones marked with `*`.
            await expect(
                page.locator('label span[aria-hidden="true"]', { hasText: '*' }),
            ).toHaveCount(6);

            await page.getByLabel('Nama lengkap').fill('Siti Rahma');
            await page.getByLabel('Nomor telepon').fill('081234567890');
            await page.getByLabel('Kata sandi').fill('rahasia123');
            await page.getByLabel('Tanggal lahir').fill('1990-05-17');
            await page.getByLabel('Alamat lengkap').fill('Jl. Merdeka No. 10, Bandung');

            await page.getByRole('button', { name: 'Daftar' }).click();

            await expect.poll(() => state.register.length).toBe(1);
            await expect(page).toHaveURL(/\/otp$/);

            const body = state.register[0] as Record<string, unknown>;

            expect('email' in body).toBe(false);
            expect('tempat_lahir' in body).toBe(false);
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

        test('f01-ac4-copy-kedaluwarsa', async ({ page }) => {
            await pasangMock(page);
            await pasangPendingOtp(page, { kedaluwarsaMs: -2_000 });

            await page.goto('/otp');

            await expect(page.getByText('Kode sudah kedaluwarsa.')).toBeVisible();
            await expect(
                page.getByText('Kirim ulang dengan masuk kembali'),
            ).toHaveCount(0);

            const resend = page.locator('[data-slot="otp-resend"]');

            await expect(resend).toBeVisible();
            await expect(resend).toBeDisabled();
            await expect(resend).toHaveAttribute('aria-disabled', 'true');
            await expect(
                page.getByText(
                    'Kirim ulang kode belum tersedia. Gunakan tombol Kembali untuk meminta kode baru.',
                ),
            ).toBeVisible();
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
                    headers: { 'Retry-After': '45' },
                    body: {
                        success: false,
                        message: 'Terlalu banyak percobaan.',
                        errors: {},
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

            // One click, one request; the disabled while in-flight button cannot stack a
            // second one.
            expect(state.verify.length).toBe(1);
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
            await page.waitForTimeout(300);

            expect(permintaanAuth).toBe(sebelum);
            expect(state.verify.length).toBe(0);
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
            await isiLogin(page, { identitas: '081299998888', sandi: 'rahasia123' });
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
            await expect(page.getByText('Perangkat ini')).toHaveCount(1);
            await expect(page.getByText(/Android • Terakhir aktif/)).toBeVisible();

            const barisIos = page.locator('[data-slot="device-row"][data-platform="ios"]');
            const dialog = page.locator('[data-slot="dialog-content"]');

            await barisIos.getByRole('button', { name: 'Cabut' }).click();

            await expect(
                dialog.getByRole('heading', { name: 'Cabut perangkat ini?' }),
            ).toBeVisible();
            await expect(
                dialog.getByText('Perangkat ini akan keluar dan harus masuk kembali.'),
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
                .locator('[data-slot="dialog-content"]')
                .getByRole('button', { name: 'Ya, cabut' })
                .click();

            await expect(page.getByText('Perangkat tidak ditemukan.')).toBeVisible();
            expect(state.deletes.length).toBe(1);
        });

        test('f01-ac11-privasi', async ({ page }) => {
            await pasangMock(page);

            const pesanKonsol: string[] = [];

            page.on('console', (pesan) => {
                pesanKonsol.push(pesan.text());
            });

            await page.goto('/login');

            await expect(page).toHaveTitle('Masuk | Sehatly');

            await isiLogin(page, { identitas: '081299998888', sandi: 'rahasia123' });
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

            await page.goto('/register');
            await expectNoA11yViolations(page);
            await ukuranTarget(page.getByRole('button', { name: 'Daftar' }));

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

    test(`f01-bukti-register-${lebar}`, async ({ page }) => {
        test.skip(!rekam, 'capture only on demand');

        await pasangMock(page);
        await page.setViewportSize({ width: lebar, height: tinggi });
        await page.goto('/register');
        await page.waitForLoadState('networkidle');

        await simpanBukti(page, 'register', lebar);
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
}
