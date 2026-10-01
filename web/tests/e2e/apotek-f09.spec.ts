import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { expect, test, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F09's pharmacist queue (`/apotek/resep`), each acceptance criterion in a deterministic
 * mocked scenario.
 *
 * ## Why this file mocks instead of driving the live API
 *
 * `resep.spec.ts` proves the real contract end to end with a seeded pharmacist. It cannot
 * produce an empty queue, a 403, a 404, or a 500 on demand, and it cannot assert the exact
 * query string a filter chip sends without racing a real dataset. This file intercepts
 * every `/api/v1` call, so each branch is exercised on any machine with no database.
 *
 * ## What is asserted about privacy
 *
 * `GET /resep` answers `ResepAntreanResource`, which publishes neither drug names nor
 * patient identity. The queue fixtures below deliberately ADD those keys (`items`,
 * `pasien`) to prove the screen does not render them even if a response carried them: the
 * list is asserted clean of `NAMA_OBAT` and `NAMA_PASIEN`, while the detail read - a
 * separate, per-row-gated endpoint - is where the drug name is allowed to appear.
 *
 * ## Every scenario runs at both widths
 *
 * `web/ux/patterns/F09.md` §11 requires identical assertions at `390x844` and `1280x900`,
 * so each test loops the two viewports and re-registers its route mock per viewport.
 */

const VIEWPORTS = [
    { width: 390, height: 844 },
    { width: 1280, height: 900 },
] as const;

test.use({ timezoneId: 'Asia/Jakarta' });

const NAMA_OBAT = 'Amoxicillin Trihydrate 500 mg';
const NAMA_PASIEN = 'Budi Santoso';

const APOTEKER = {
    id: 9,
    uuid: '00000000-0000-4000-8000-000000000009',
    nama_lengkap: 'Ns. Rina Kusuma',
    no_telepon: '081200000009',
    email: null,
    tipe: 'apoteker',
    status: 'aktif',
    bahasa: 'id',
    foto_profil: null,
    telepon_terverifikasi: true,
    email_terverifikasi: false,
    last_login_at: null,
    dibuat_at: '2026-01-01T00:00:00.000000Z',
};

/**
 * A queue row as the resource publishes it - plus the two fields it explicitly withholds,
 * so a UI that leaked them would be caught.
 */
const RESEP_AKTIF = {
    id: 42,
    nomor_resep: 'RSP-2026-0042',
    tipe: 'digital',
    status: 'aktif',
    tanggal_resep: '2026-10-01T02:15:00.000000Z',
    berlaku_sampai: '2026-10-08',
    is_kedaluwarsa: false,
    terminal: false,
    jumlah_item: 3,
    // Not part of `ResepAntreanResource`; the UI must not render these.
    items: [{ id: 1, nama_obat: NAMA_OBAT }],
    pasien: { id: 1, nama_lengkap: NAMA_PASIEN },
};

const RESEP_DIPROSES = {
    ...RESEP_AKTIF,
    id: 43,
    nomor_resep: 'RSP-2026-0043',
    status: 'diproses',
    tanggal_resep: '2026-09-30T03:40:00.000000Z',
    berlaku_sampai: '2026-09-28',
    is_kedaluwarsa: true,
    jumlah_item: 1,
};

const DETAIL_RESEP = {
    id: 42,
    nomor_resep: 'RSP-2026-0042',
    konsultasi_id: null,
    rekam_medis_id: null,
    pasien_id: 1,
    dokter_id: 2,
    apotek_id: null,
    tipe: 'digital',
    status: 'aktif',
    catatan_dokter: null,
    tanggal_resep: '2026-10-01T02:15:00.000000Z',
    berlaku_sampai: '2026-10-08',
    is_kedaluwarsa: false,
    terminal: false,
    is_iter: false,
    jumlah_iter: 0,
    qr_token: '7F3A-9K2D',
    dibuat_at: '2026-10-01T02:15:00.000000Z',
    items: [
        {
            id: 1,
            obat_id: 1,
            nama_obat: 'Amoxicillin Trihydrate',
            kekuatan: '500 mg',
            aturan_pakai: '3 kali sehari 1 tablet setelah makan',
            jumlah: 21,
            satuan: 'tablet',
            is_racikan: false,
            racikan_nama: null,
            harga_satuan: '45000.00',
            subtotal: '945000.00',
            catatan_apoteker: null,
        },
    ],
};

const GRUP_KOSONG = {
    antar_item: [],
    riwayat_resep: [],
    alergi: [],
};

function meta(
    currentPage: number,
    lastPage: number,
    total: number,
    from: number | null,
    to: number | null,
): Record<string, unknown> {
    return {
        current_page: currentPage,
        last_page: lastPage,
        per_page: 15,
        total,
        from,
        to,
    };
}

type ModeAntrean = 'ok' | 'kosong' | 'gagal' | 'lambat' | 'terlarang' | 'hilang';

type OpsiMock = {
    /** Read per request, so a test can flip a failing queue to a healthy one mid-scenario. */
    mode?: ModeAntrean;
};

type PeganganMock = {
    /** Every `GET /api/v1/resep` URL, in order, for query-string assertions. */
    permintaan: string[];
};

async function balasJson(route: Route, status: number, body: unknown): Promise<void> {
    await route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

/**
 * Intercept every `/api/v1` call the shell and the queue make.
 *
 * The catch-all is load-bearing: an unmocked request would reach the real API and take the
 * transport down its session-expiry path, redirecting the test to `/login` for a reason
 * unrelated to the assertion.
 */
async function pasangMock(page: Page, opsi: OpsiMock = {}): Promise<PeganganMock> {
    const permintaan: string[] = [];

    await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const jalur = url.pathname;
        const metode = request.method();
        const mode = opsi.mode ?? 'ok';

        if (jalur === '/api/v1/me') {
            return balasJson(route, 200, {
                success: true,
                message: 'Akun berhasil dimuat.',
                data: { user: APOTEKER },
            });
        }

        if (jalur === '/api/v1/notifikasi') {
            return balasJson(route, 200, {
                success: true,
                message: 'Daftar notifikasi berhasil dimuat.',
                data: { notifikasi: [] },
                meta: { ...meta(1, 1, 0, null, null), unread: 0 },
            });
        }

        if (jalur === '/api/v1/resep' && metode === 'GET') {
            permintaan.push(url.toString());

            if (mode === 'lambat') {
                await new Promise((selesai) => setTimeout(selesai, 700));
            }

            if (mode === 'terlarang') {
                return balasJson(route, 403, {
                    success: false,
                    message: 'Akun ini tidak berhak mengakses antrean verifikasi.',
                    errors: {},
                });
            }

            if (mode === 'hilang') {
                return balasJson(route, 404, {
                    success: false,
                    message: 'Antrean tidak ditemukan.',
                    errors: {},
                });
            }

            if (mode === 'gagal') {
                return balasJson(route, 500, {
                    success: false,
                    message: 'Terjadi kesalahan pada server. Coba lagi nanti.',
                    errors: {},
                });
            }

            if (mode === 'kosong') {
                return balasJson(route, 200, {
                    success: true,
                    message: 'Antrean verifikasi resep berhasil dimuat.',
                    data: { resep: [] },
                    meta: meta(1, 1, 0, null, null),
                });
            }

            const halaman = Number(url.searchParams.get('page') ?? '1');
            const status = url.searchParams.get('status');
            const baris =
                halaman >= 2
                    ? [RESEP_AKTIF]
                    : status === 'diproses'
                      ? [RESEP_DIPROSES]
                      : [RESEP_AKTIF, RESEP_DIPROSES];

            return balasJson(route, 200, {
                success: true,
                message: 'Antrean verifikasi resep berhasil dimuat.',
                data: { resep: baris },
                meta:
                    halaman >= 2
                        ? meta(2, 2, 18, 16, 16)
                        : meta(1, 2, 18, 1, 2),
            });
        }

        if (/^\/api\/v1\/resep\/\d+$/.test(jalur) && metode === 'GET') {
            return balasJson(route, 200, {
                success: true,
                message: 'Detail resep berhasil dimuat.',
                data: {
                    resep: DETAIL_RESEP,
                    verifikasi: null,
                    warning: [],
                    warning_grup: GRUP_KOSONG,
                },
            });
        }

        if (/^\/api\/v1\/resep\/\d+\/cek-interaksi$/.test(jalur)) {
            return balasJson(route, 200, {
                success: true,
                message: 'Peringatan interaksi berhasil diperiksa.',
                data: {
                    resep_id: 42,
                    status: 'aktif',
                    warning: [],
                    warning_grup: GRUP_KOSONG,
                    wajib_catatan: false,
                },
            });
        }

        return balasJson(route, 200, {
            success: true,
            message: 'Berhasil.',
            data: {},
        });
    });

    return { permintaan };
}

async function masukPalsu(page: Page): Promise<void> {
    await page.addInitScript(() => {
        sessionStorage.setItem('sehatly.access_token', 'token-uji-f09-queue');
        sessionStorage.setItem('sehatly.refresh_token', 'refresh-uji-f09-queue');
    });
}

async function ukuranTarget(page: Page, pemilih: string): Promise<void> {
    for (const elemen of await page.locator(pemilih).all()) {
        const kotak = await elemen.boundingBox();

        expect(kotak?.height ?? 0, `${pemilih} harus >= 44 px`).toBeGreaterThanOrEqual(44);
    }
}

test.describe('F09 antrean apoteker: daftar', () => {
    test('menampilkan baris, badge status berikon, kedaluwarsa, tanggal WIB, dan paginasi', async ({
        page,
    }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');
            await pasangMock(page);

            await page.goto('/apotek/resep');

            const baris = page.locator('[data-slot="antrean-resep-item"]');

            await expect(baris).toHaveCount(2);

            const aktif = baris.filter({ hasText: 'RSP-2026-0042' });
            const diproses = baris.filter({ hasText: 'RSP-2026-0043' });

            const badge = aktif.locator('[data-slot="status-resep-badge"]');

            await expect(badge).toHaveAttribute('data-status', 'aktif');
            await expect(badge).toContainText('Aktif');
            await expect(badge.locator('svg')).toHaveCount(1);

            await expect(
                diproses.locator('[data-slot="status-resep-badge"]'),
            ).toContainText('Diproses');
            await expect(
                diproses.locator('[data-slot="kedaluwarsa-badge"]'),
            ).toBeVisible();

            await expect(aktif).toContainText('1 Okt 2026, 09.15 WIB');
            await expect(aktif).toContainText('8 Oktober 2026');
            await expect(aktif).toContainText('3 obat');
            await expect(diproses).toContainText('1 obat');

            await expect(page.locator('[data-slot="pagination"]')).toContainText(
                '1 / 2',
            );

            await ukuranTarget(
                page,
                '[data-slot="antrean-filter"] button, [data-slot="antrean-buka"]',
            );

            await expectNoA11yViolations(page);

            await page.getByRole('button', { name: 'Berikutnya' }).click();

            await expect(page.locator('[data-slot="pagination"]')).toContainText(
                '2 / 2',
            );
            await expect(baris).toHaveCount(1);
        }
    });

    test('filter status mengirim kuery yang benar dan kembali ke semua', async ({
        page,
    }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');
            const pegangan = await pasangMock(page);

            await page.goto('/apotek/resep');

            await expect(page.locator('[data-slot="antrean-resep-item"]')).toHaveCount(
                2,
            );

            const chipDiproses = page.locator(
                '[data-slot="antrean-filter-status"][data-status="diproses"]',
            );

            await chipDiproses.click();

            await expect(chipDiproses).toHaveAttribute('aria-pressed', 'true');
            await expect(page.locator('[data-slot="antrean-resep-item"]')).toHaveCount(
                1,
            );

            const kuery = new URL(pegangan.permintaan.at(-1) ?? '').searchParams;

            expect(kuery.get('status')).toBe('diproses');
            expect(kuery.get('page')).toBe('1');
            expect(kuery.get('per_page')).toBe('15');

            await page.locator('[data-slot="antrean-filter-semua"]').click();

            await expect(
                page.locator('[data-slot="antrean-filter-semua"]'),
            ).toHaveAttribute('aria-pressed', 'true');
            await expect(page.locator('[data-slot="antrean-resep-item"]')).toHaveCount(
                2,
            );

            // Clearing can be served from the query cache - the unfiltered key is still
            // fresh - so the guarantee is that the unfiltered REQUEST carried no status,
            // not that clearing issued another one.
            expect(
                new URL(pegangan.permintaan[0] ?? '').searchParams.has('status'),
            ).toBe(false);
            expect(
                pegangan.permintaan.some(
                    (u) => new URL(u).searchParams.get('status') === 'diproses',
                ),
            ).toBe(true);
        }
    });

    test('tidak ada input id ketik dan tidak ada data klinis di daftar', async ({
        page,
    }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');
            await pasangMock(page);

            await page.goto('/apotek/resep');

            await expect(page.locator('[data-slot="antrean-resep-item"]')).toHaveCount(
                2,
            );

            await expect(page.locator('[data-slot="apotek-id-resep"]')).toHaveCount(0);
            await expect(page.getByLabel('Id resep')).toHaveCount(0);
            await expect(page.getByText('Masukkan id resep')).toHaveCount(0);
            await expect(page.locator('input[type="number"]')).toHaveCount(0);

            const daftar = page.locator('[data-slot="antrean-verifikasi"]');

            await expect(daftar).not.toContainText(NAMA_OBAT);
            await expect(daftar).not.toContainText(NAMA_PASIEN);
            await expect(daftar).not.toContainText('7F3A-9K2D');

            await expect(page).toHaveURL(/\/apotek\/resep$/);
            await expect(page).not.toHaveTitle(/RSP-|Amoxicillin/);
        }
    });
});

test.describe('F09 antrean apoteker: state', () => {
    test('skeleton tampil selama pemuatan', async ({ page }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');
            await pasangMock(page, { mode: 'lambat' });

            await page.goto('/apotek/resep');

            await expect(
                page.locator('[data-slot="antrean-verifikasi"] .animate-pulse').first(),
            ).toBeVisible({ timeout: 15_000 });

            await expect(page.locator('[data-slot="antrean-resep-item"]')).toHaveCount(
                2,
                { timeout: 15_000 },
            );
        }
    });

    test('kosong menampilkan tindakan lanjut', async ({ page }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');
            const pegangan = await pasangMock(page, { mode: 'kosong' });

            await page.goto('/apotek/resep');

            const kosong = page.locator('[data-slot="empty-state"]');

            await expect(kosong).toContainText(
                'Tidak ada resep yang menunggu verifikasi',
            );
            await expect(kosong).toContainText('Semua resep yang masuk sudah ditangani');

            const sebelum = pegangan.permintaan.length;

            await page.locator('[data-slot="antrean-muat-ulang"]').click();

            await expect
                .poll(() => pegangan.permintaan.length)
                .toBeGreaterThan(sebelum);

            // With a filter active, the way out is to clear the filter.
            await page
                .locator('[data-slot="antrean-filter-status"][data-status="diproses"]')
                .click();

            await expect(kosong).toContainText('Tidak ada resep berstatus "Diproses"');
            await page.locator('[data-slot="antrean-tampilkan-semua"]').click();

            await expect(
                page.locator('[data-slot="antrean-filter-semua"]'),
            ).toHaveAttribute('aria-pressed', 'true');
            // The unfiltered empty page is restored from cache, so the copy - not a second
            // request - is what proves the filter was cleared.
            await expect(kosong).toContainText('Semua resep yang masuk sudah ditangani');
        }
    });

    test('gagal menampilkan Coba lagi yang memuat ulang', async ({ page }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');
            const opsi: OpsiMock = { mode: 'gagal' };
            const pegangan = await pasangMock(page, opsi);

            await page.goto('/apotek/resep');

            await expect(page.locator('[data-slot="error-state"]')).toBeVisible({
                timeout: 15_000,
            });
            await expect(
                page.getByRole('button', { name: 'Coba lagi' }),
            ).toBeVisible();

            const sebelum = pegangan.permintaan.length;

            opsi.mode = 'ok';

            await page.getByRole('button', { name: 'Coba lagi' }).click();

            await expect(page.locator('[data-slot="antrean-resep-item"]')).toHaveCount(
                2,
                { timeout: 15_000 },
            );
            await expect
                .poll(() => pegangan.permintaan.length)
                .toBeGreaterThan(sebelum);

            await expectNoA11yViolations(page);
        }
    });

    test('403 ke ForbiddenState dan 404 ke NotFoundState tanpa retry', async ({
        page,
    }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);

            await page.unroute('**/api/v1/**');
            await pasangMock(page, { mode: 'terlarang' });
            await page.goto('/apotek/resep');

            await expect(page.locator('[data-slot="forbidden-state"]')).toBeVisible();
            await expect(
                page.getByRole('button', { name: 'Coba lagi' }),
            ).toHaveCount(0);

            await page.unroute('**/api/v1/**');
            await pasangMock(page, { mode: 'hilang' });
            await page.goto('/apotek/resep');

            await expect(page.locator('[data-slot="not-found-state"]')).toBeVisible();
            await expect(page.locator('[data-slot="not-found-state"]')).toContainText(
                'Antrean tidak ditemukan',
            );
        }
    });

    test('offline menampilkan banner tanpa tindakan tulis', async ({ page }) => {
        await masukPalsu(page);

        await page.setViewportSize(VIEWPORTS[0]);
        await page.unroute('**/api/v1/**');
        await pasangMock(page);

        await page.goto('/apotek/resep');

        await expect(page.locator('[data-slot="antrean-resep-item"]')).toHaveCount(2);

        await page.context().setOffline(true);
        await page.evaluate(() => {
            window.dispatchEvent(new Event('offline'));
        });

        await expect(page.getByTestId('offline-banner')).toBeVisible();

        await page.context().setOffline(false);
        await page.evaluate(() => {
            window.dispatchEvent(new Event('online'));
        });

        await expect(page.getByTestId('offline-banner')).toHaveCount(0);
    });
});

test.describe('F09 antrean apoteker: baris ke detail', () => {
    test('satu baris membuka detail verifikasi dan kembali ke antrean', async ({
        page,
    }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');
            await pasangMock(page);

            await page.goto('/apotek/resep');

            const baris = page
                .locator('[data-slot="antrean-resep-item"]')
                .filter({ hasText: 'RSP-2026-0042' });

            await baris.locator('[data-slot="antrean-buka"]').click();

            await expect(page.locator('[data-slot="resep-detail"]')).toBeVisible();
            await expect(page.locator('[data-slot="verifikasi-form"]')).toBeVisible();

            // The clinical read happens HERE, not in the list.
            await expect(page.locator('[data-slot="resep-detail"]')).toContainText(
                NAMA_OBAT,
            );

            // The id stays out of the URL.
            await expect(page).toHaveURL(/\/apotek\/resep$/);

            await expectNoA11yViolations(page);

            await page.locator('[data-slot="antrean-kembali"]').click();

            await expect(page.locator('[data-slot="antrean-resep-item"]')).toHaveCount(
                2,
            );
        }
    });
});

/**
 * The visual loop's evidence, written only when `SEHATLY_F09_REFS=1` so a normal test run
 * touches no files. Output: `web/ux/refs/f09/{daftar,kosong,galat,detail}-{390,1280}.png`,
 * the directory `web/AGENTS.md` marks as non-committable.
 */
const REKAM_GAMBAR = process.env.SEHATLY_F09_REFS === '1';

test.describe('F09 antrean apoteker: rekaman visual', () => {
    test.skip(!REKAM_GAMBAR, 'set SEHATLY_F09_REFS=1 untuk menulis web/ux/refs/f09/');

    test('menyimpan tangkapan 390 px dan 1280 px', async ({ page }) => {
        const direktori = path.resolve(process.cwd(), 'ux/refs/f09');

        await mkdir(direktori, { recursive: true });

        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            const lebar = String(viewport.width);

            await page.setViewportSize(viewport);

            await page.unroute('**/api/v1/**');
            await pasangMock(page);
            await page.goto('/apotek/resep');
            await expect(page.locator('[data-slot="antrean-resep-item"]')).toHaveCount(
                2,
            );
            await page.screenshot({
                path: path.join(direktori, `daftar-${lebar}.png`),
                fullPage: true,
            });

            await page
                .locator('[data-slot="antrean-resep-item"]')
                .filter({ hasText: 'RSP-2026-0042' })
                .locator('[data-slot="antrean-buka"]')
                .click();
            await expect(page.locator('[data-slot="resep-detail"]')).toBeVisible();
            await page.screenshot({
                path: path.join(direktori, `detail-${lebar}.png`),
                fullPage: true,
            });

            await page.unroute('**/api/v1/**');
            await pasangMock(page, { mode: 'kosong' });
            await page.goto('/apotek/resep');
            await expect(page.locator('[data-slot="empty-state"]')).toBeVisible();
            await page.screenshot({
                path: path.join(direktori, `kosong-${lebar}.png`),
                fullPage: true,
            });

            await page.unroute('**/api/v1/**');
            await pasangMock(page, { mode: 'gagal' });
            await page.goto('/apotek/resep');
            await expect(page.locator('[data-slot="error-state"]')).toBeVisible({
                timeout: 15_000,
            });
            await page.screenshot({
                path: path.join(direktori, `galat-${lebar}.png`),
                fullPage: true,
            });
        }
    });
});
