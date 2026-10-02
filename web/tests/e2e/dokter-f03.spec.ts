import { mkdirSync } from 'node:fs';
import { expect, test, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F03 (cari dokter dan filter) mocked end to end at 390x844 and 1280x900.
 *
 * ## Why every call is mocked
 *
 * Every criterion needs a response the live seed cannot produce on demand: a doctor named
 * "Rina", eight results for one specialisation, zero results for a combined filter, a 500
 * on page three, and a count that changes while the request is still in flight. Mocking also
 * keeps the privacy fixture ("kanker") out of any real server.
 *
 * ## What is deliberately NOT here
 *
 * AC-5 (`Urutkan`, needs `?sort=`) and AC-11 (`{n} ulasan` on the card, needs
 * `jumlah_ulasan` in `DokterResource`) are `[TERBLOKIR backend]` per `patterns/F03.md`.
 * They are marked `test.fixme` below rather than faked: a passing test against a mock that
 * publishes fields the API does not would be a lie.
 *
 * ## The free-text query never reaches the URL
 *
 * The fixture word is a medical condition, which is the point of the assertion: after
 * typing it and submitting, the address bar, the title, every `aria-label` and every toast
 * must be clean (AC-13). The input itself keeps the term, and `sessionStorage` carries it
 * across the reload AC-1 requires.
 */

const VIEWPORTS = [
    { nama: 'mobile', width: 390, height: 844 },
    { nama: 'desktop', width: 1280, height: 900 },
] as const;

type Vp = (typeof VIEWPORTS)[number];

const KATA_UJI = 'kanker';

mkdirSync('ux/refs/f03', { recursive: true });

type DokterUji = {
    id: number;
    nama_lengkap: string;
    tipe: string;
    spesialisasi: string | null;
    biaya_konsultasi_online: string;
    rating_rata_rata: string;
    jumlah_konsultasi: number;
    status_verifikasi: string;
    /** Test-only handle for `?spesialisasi=`; not part of the published resource. */
    kode: string;
};

type SpesialisasiUji = {
    id: number;
    kode: string;
    nama: string;
    tipe: string;
};

const MASTER_SPESIALISASI: SpesialisasiUji[] = [
    { id: 1, kode: 'UMUM', nama: 'Dokter Umum', tipe: 'dokter_umum' },
    { id: 2, kode: 'SP.PD', nama: 'Spesialis Penyakit Dalam', tipe: 'spesialis' },
    { id: 3, kode: 'SP.A', nama: 'Spesialis Anak', tipe: 'spesialis' },
    { id: 4, kode: 'SP.OG', nama: 'Spesialis Obstetri & Ginekologi', tipe: 'spesialis' },
    { id: 16, kode: 'GIGI', nama: 'Dokter Gigi', tipe: 'spesialis' },
];

function dokterUji(
    id: number,
    nama: string,
    tipe: string,
    kode: string,
    spesialisasi: string,
    biaya: string,
    rating: string,
    konsultasi: number,
): DokterUji {
    return {
        id,
        nama_lengkap: nama,
        tipe,
        kode,
        spesialisasi,
        biaya_konsultasi_online: biaya,
        rating_rata_rata: rating,
        jumlah_konsultasi: konsultasi,
        status_verifikasi: 'terverifikasi',
    };
}

/** 8 Anak, 2 Penyakit Dalam, 2 Gigi: the fixture every criterion below starts from. */
function dokterDefault(): DokterUji[] {
    const anak = Array.from({ length: 8 }, (_unused, index) =>
        dokterUji(
            100 + index,
            index === 0
                ? 'dr. Rina Wulandari, Sp.A'
                : `dr. Sari Kusuma ${String(index + 1)}, Sp.A`,
            'dokter_spesialis',
            'SP.A',
            'Spesialis Anak',
            '85000.00',
            '4.9',
            128 - index,
        ),
    );

    return [
        ...anak,
        dokterUji(
            200,
            'dr. Andi Pratama, Sp.PD',
            'dokter_spesialis',
            'SP.PD',
            'Spesialis Penyakit Dalam',
            '120000.00',
            '4.7',
            210,
        ),
        dokterUji(
            201,
            'dr. Sri Handayani, Sp.PD',
            'dokter_spesialis',
            'SP.PD',
            'Spesialis Penyakit Dalam',
            '110000.00',
            '4.8',
            180,
        ),
        dokterUji(
            300,
            'drg. Maya Sari',
            'dokter_gigi',
            'GIGI',
            'Dokter Gigi',
            '90000.00',
            '4.8',
            64,
        ),
        dokterUji(
            301,
            'drg. Bagus Nugroho',
            'dokter_gigi',
            'GIGI',
            'Dokter Gigi',
            '95000.00',
            '4.6',
            52,
        ),
    ];
}

/** `n` Sp.A doctors whose names all contain "Anak", for pagination and failure tests. */
function banyakDokter(n: number): DokterUji[] {
    return Array.from({ length: n }, (_unused, index) =>
        dokterUji(
            400 + index,
            `dr. Anak Contoh ${String(index + 1)}, Sp.A`,
            'dokter_spesialis',
            'SP.A',
            'Spesialis Anak',
            '85000.00',
            '4.7',
            40 + index,
        ),
    );
}

type State = {
    dokter: DokterUji[];
    gagal: boolean;
    tundaMs: number;
    halamanKosong: boolean;
    permintaanDokter: string[];
    permintaanMaster: number;
};

type OpsiMock = {
    dokter?: DokterUji[];
    tundaMs?: number;
};

async function balasJson(route: Route, status: number, body: unknown): Promise<void> {
    await route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

async function pasangMock(page: Page, opsi: OpsiMock = {}): Promise<State> {
    const state: State = {
        dokter: opsi.dokter ?? dokterDefault(),
        gagal: false,
        tundaMs: opsi.tundaMs ?? 0,
        halamanKosong: false,
        permintaanDokter: [],
        permintaanMaster: 0,
    };

    await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;

        if (path === '/api/v1/master-spesialisasi') {
            state.permintaanMaster += 1;

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar spesialisasi berhasil dimuat.',
                data: { spesialisasi: MASTER_SPESIALISASI },
                meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: MASTER_SPESIALISASI.length,
                    total: MASTER_SPESIALISASI.length,
                    from: 1,
                    to: MASTER_SPESIALISASI.length,
                },
            });
        }

        if (path === '/api/v1/dokter' && request.method() === 'GET') {
            state.permintaanDokter.push(request.url());

            if (state.tundaMs > 0) {
                await new Promise((resolve) => {
                    setTimeout(resolve, state.tundaMs);
                });
            }

            if (state.gagal) {
                return balasJson(route, 500, {
                    success: false,
                    message: 'Terjadi kesalahan pada server.',
                    errors: {},
                });
            }

            const search = url.searchParams.get('search');
            const spesialisasi = url.searchParams.get('spesialisasi');
            const tipe = url.searchParams.get('tipe');
            const telemedisin = url.searchParams.get('tersedia_telemedisin');
            const page = Number(url.searchParams.get('page') ?? '1');
            const perPage = Number(url.searchParams.get('per_page') ?? '15');

            const tersaring = state.dokter.filter((row) => {
                if (
                    search !== null &&
                    !row.nama_lengkap.toLowerCase().includes(search.toLowerCase())
                ) {
                    return false;
                }

                if (spesialisasi !== null && row.kode !== spesialisasi) {
                    return false;
                }

                if (tipe !== null && row.tipe !== tipe) {
                    return false;
                }

                if (telemedisin === '1' || telemedisin === 'true') {
                    return true;
                }

                return true;
            });

            const total = tersaring.length;
            const mulai = (page - 1) * perPage;
            const kosongPaksa = state.halamanKosong && page > 1;
            const halamanTerakhir = kosongPaksa
                ? 1
                : Math.max(1, Math.ceil(total / perPage));
            const baris = kosongPaksa
                ? []
                : tersaring.slice(mulai, mulai + perPage);

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar dokter berhasil dimuat.',
                data: { dokter: baris },
                meta: {
                    current_page: page,
                    last_page: halamanTerakhir,
                    per_page: perPage,
                    total,
                    from: baris.length === 0 ? null : mulai + 1,
                    to: baris.length === 0 ? null : mulai + baris.length,
                },
            });
        }

        return balasJson(route, 200, {
            success: true,
            message: 'Berhasil.',
            data: {},
        });
    });

    return state;
}

function punyaPermintaan(
    state: State,
    cek: (params: URLSearchParams) => boolean,
): boolean {
    return state.permintaanDokter.some((url) => cek(new URL(url).searchParams));
}

async function bukaDirektori(page: Page, opsi: OpsiMock = {}): Promise<State> {
    const state = await pasangMock(page, opsi);

    await page.goto('/dokter');

    await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
        'dokter ditemukan.',
        { timeout: 15_000 },
    );

    return state;
}

async function cariDokter(page: Page, kueri: string): Promise<void> {
    const input = page.locator('[data-slot="dokter-cari"]');

    await input.fill(kueri);
    await input.press('Enter');
}

async function pilihSpesialisasi(page: Page, vp: Vp, nama: string): Promise<void> {
    if (vp.nama === 'mobile') {
        await page.locator('[data-slot="dokter-filter-button"]').click();

        await page
            .locator('[data-slot="sheet-content"]')
            .getByRole('radio', { name: nama })
            .click();

        const tampilkan = page.getByRole('button', { name: /^Tampilkan \d+ hasil$/ });

        await expect(tampilkan).toBeEnabled();
        await tampilkan.click();

        return;
    }

    await page
        .locator('[data-slot="dokter-panel-filter"]')
        .getByRole('radio', { name: nama })
        .click();
}

async function pilihTipe(page: Page, vp: Vp, nama: string): Promise<void> {
    if (vp.nama === 'mobile') {
        await page.locator('[data-slot="dokter-filter-button"]').click();

        await page
            .locator('[data-slot="sheet-content"]')
            .getByRole('radio', { name: nama })
            .click();

        const tampilkan = page.getByRole('button', { name: /^Tampilkan \d+ hasil$/ });

        await expect(tampilkan).toBeEnabled();
        await tampilkan.click();

        return;
    }

    await page
        .locator('[data-slot="dokter-panel-filter"]')
        .getByRole('radio', { name: nama })
        .click();
}

async function pilihTelemedisin(page: Page, vp: Vp): Promise<void> {
    if (vp.nama === 'mobile') {
        await page.locator('[data-slot="dokter-filter-button"]').click();

        await page
            .locator('[data-slot="sheet-content"]')
            .getByRole('checkbox', { name: 'Telemedisin' })
            .click();

        const tampilkan = page.getByRole('button', { name: /^Tampilkan \d+ hasil$/ });

        await expect(tampilkan).toBeEnabled();
        await tampilkan.click();

        return;
    }

    await page
        .locator('[data-slot="dokter-panel-filter"]')
        .getByRole('checkbox', { name: 'Telemedisin' })
        .click();
}

async function simpanGambar(
    page: Page,
    vp: Vp,
    keadaan: string,
    fullPage = true,
): Promise<void> {
    await page.screenshot({
        path: `ux/refs/f03/f03-${keadaan}-${vp.nama}.png`,
        fullPage,
        animations: 'disabled',
    });
}

/**
 * Blocked, not faked. `IndexDokterRequest` has no `sort` key and `DokterResource` publishes
 * no `jumlah_ulasan`/`pengalaman_tahun`, so both criteria need a backend change first.
 */
test.fixme('f03-ac5-sort [TERBLOKIR backend parameter sort]', async () => {});
test.fixme('f03-ac11-kartu-ulasan [TERBLOKIR backend jumlah_ulasan]', async () => {});

for (const vp of VIEWPORTS) {
    test.describe(`F03 direktori dokter ${vp.nama} ${vp.width}x${vp.height}`, () => {
        test.use({
            viewport: { width: vp.width, height: vp.height },
            timezoneId: 'Asia/Jakarta',
        });

        test.setTimeout(60_000);

        test('f03-ac1-cari-submit', async ({ page }) => {
            const state = await bukaDirektori(page);

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '12 dokter ditemukan.',
            );

            await expect(page.getByText('Sering dicari:')).toBeVisible();
            await expect(page.getByRole('button', { name: 'Dokter Anak' })).toBeVisible();
            await expect(
                page.getByRole('button', { name: 'Dokter Kandungan' }),
            ).toBeVisible();
            await expect(
                page.getByRole('button', { name: 'Dokter Penyakit Dalam' }),
            ).toBeVisible();
            await expect(page.getByRole('button', { name: 'Dokter Gigi' })).toBeVisible();

            await expect(page.locator('[data-slot="dokter-form-cari"]')).toBeInViewport();
            await expect(page.locator('[data-slot="dokter-cari-submit"]')).toBeInViewport();

            await cariDokter(page, 'Rina');

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '1 dokter ditemukan.',
            );
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(1);
            await expect(
                page.locator('[data-slot="dokter-kartu"]').first(),
            ).toContainText('dr. Rina Wulandari, Sp.A');

            const pencarian = state.permintaanDokter.filter((url) =>
                new URL(url).searchParams.get('search') === 'Rina',
            );

            expect(pencarian.length).toBe(1);

            await simpanGambar(page, vp, 'hasil');

            // AC-1: the query survives a reload through state, never through the URL.
            expect(page.url()).not.toContain('Rina');

            await page.reload();

            await expect(page.locator('[data-slot="dokter-cari"]')).toHaveValue('Rina');
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '1 dokter ditemukan.',
            );
            expect(page.url()).not.toContain('Rina');

            await expectNoA11yViolations(page);
        });

        test('f03-ac2-filter-sheet', async ({ page }) => {
            const state = await bukaDirektori(page);

            if (vp.nama === 'mobile') {
                const tombol = page.locator('[data-slot="dokter-filter-button"]');

                await expect(tombol).toBeVisible();
                await expect(tombol).toContainText('Filter');

                // Tap 1: open the sheet.
                await tombol.click();

                const sheet = page.locator('[data-slot="sheet-content"]');

                await expect(sheet).toBeVisible();
                await expect(
                    sheet.getByRole('heading', { name: 'Filter' }),
                ).toBeVisible();

                // The results behind the sheet stay rendered and visible.
                await expect(
                    page.locator('[data-slot="dokter-kartu"]').first(),
                ).toBeVisible();

                // Tap 2: choose one value.
                await sheet.getByRole('radio', { name: 'Spesialis Anak' }).click();

                const tampilkan = page.getByRole('button', {
                    name: /^Tampilkan \d+ hasil$/,
                });

                await expect(tampilkan).toHaveText('Tampilkan 8 hasil');

                await simpanGambar(page, vp, 'sheet');

                // Tap 3: apply.
                await tampilkan.click();

                await expect(tombol).toContainText('Filter · 1');
            } else {
                const panel = page.locator('[data-slot="dokter-panel-filter"]');

                await expect(panel).toBeVisible();
                await expect(panel.getByText('Filter', { exact: true })).toBeVisible();

                await panel.getByRole('radio', { name: 'Spesialis Anak' }).click();

                await expect(page.locator('[data-slot="dokter-chip"]')).toHaveCount(1);

                await simpanGambar(page, vp, 'terfilter');
            }

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '8 dokter ditemukan.',
            );
            await expect(page.getByRole('button', { name: 'Hapus filter Spesialis Anak' })).toBeVisible();

            expect(
                punyaPermintaan(
                    state,
                    (params) => params.get('spesialisasi') === 'SP.A',
                ),
            ).toBe(true);

            await expectNoA11yViolations(page);
        });

        test('f03-ac3-chip-hapus', async ({ page }) => {
            await bukaDirektori(page);

            await pilihSpesialisasi(page, vp, 'Spesialis Anak');
            await pilihTelemedisin(page, vp);

            const chips = page.locator('[data-slot="dokter-chip"]');

            await expect(chips).toHaveCount(2);
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '8 dokter ditemukan.',
            );

            await page.evaluate(() => {
                (window as unknown as Record<string, unknown>).__f03_tanpaReload = true;
                window.scrollTo(0, 320);
            });

            const sebelum = await page.evaluate(() => window.scrollY);

            expect(sebelum).toBeGreaterThan(0);

            // A JS click keeps the scroll position under the test's control; a real click
            // would auto-scroll the chip into view first and hide the behaviour under test.
            await page
                .getByRole('button', { name: 'Hapus filter Telemedisin' })
                .evaluate((element) => {
                    (element as HTMLButtonElement).click();
                });

            await expect(chips).toHaveCount(1);
            await expect(chips).toContainText('Spesialis Anak');
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '8 dokter ditemukan.',
            );

            const sesudah = await page.evaluate(() => window.scrollY);

            expect(Math.abs(sesudah - sebelum)).toBeLessThanOrEqual(4);

            expect(
                await page.evaluate(
                    () =>
                        (window as unknown as Record<string, unknown>)
                            .__f03_tanpaReload === true,
                ),
            ).toBe(true);

            await expectNoA11yViolations(page);
        });

        test('f03-ac4-reset', async ({ page }) => {
            const state = await bukaDirektori(page);

            await pilihSpesialisasi(page, vp, 'Spesialis Anak');
            await pilihTelemedisin(page, vp);

            const chips = page.locator('[data-slot="dokter-chip"]');

            await expect(chips).toHaveCount(2);

            await page
                .getByRole('button', { name: 'Hapus semua filter' })
                .first()
                .click();

            await expect(chips).toHaveCount(0);
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '12 dokter ditemukan.',
            );

            await expect
                .poll(() =>
                    punyaPermintaan(
                        state,
                        (params) =>
                            params.get('spesialisasi') === null &&
                            params.get('tersedia_telemedisin') === null,
                    ),
                )
                .toBe(true);

            await expectNoA11yViolations(page);
        });

        test('f03-ac6-count', async ({ page }) => {
            await bukaDirektori(page, { tundaMs: 600 });

            const count = page.locator('[data-slot="dokter-count"]');

            await expect(count).toHaveAttribute('role', 'status');
            await expect(count).toContainText('12 dokter ditemukan.');

            await cariDokter(page, 'tidakada');

            // While the filter request is in flight the count must not show the old number.
            await expect(count).toContainText('Menghitung hasil…');
            await expect(count).toContainText('0 dokter ditemukan.');
            await expect(page.getByText('Tidak ada dokter yang cocok.')).toBeVisible();

            await expectNoA11yViolations(page);
        });

        test('f03-ac7-zero-result', async ({ page }) => {
            await bukaDirektori(page);

            await pilihSpesialisasi(page, vp, 'Spesialis Anak');
            await pilihTipe(page, vp, 'Psikolog');

            await expect(page.getByText('Tidak ada dokter yang cocok.')).toBeVisible();
            await expect(
                page.getByText('Coba hapus satu filter atau gunakan kata kunci lain.'),
            ).toBeVisible();

            const pemulihan = [
                page.getByRole('button', { name: 'Hapus filter Spesialis Anak' }).last(),
                page.getByRole('button', { name: 'Hapus semua filter' }).first(),
                page.getByRole('button', { name: 'Cari "Dokter Anak"' }),
                page.getByRole('button', { name: 'Lihat semua dokter' }),
            ];

            for (const aksi of pemulihan) {
                await expect(aksi).toBeVisible();
            }

            await simpanGambar(page, vp, 'nol-hasil');

            // Removing ONE chip leaves the other filter in place.
            await page
                .getByRole('button', { name: 'Hapus filter Spesialis Anak' })
                .last()
                .click();

            const chips = page.locator('[data-slot="dokter-chip"]');

            await expect(chips).toHaveCount(1);
            await expect(chips).toContainText('Psikolog');
            await expect(page.getByText('Tidak ada dokter yang cocok.')).toBeVisible();

            // `Lihat semua dokter` clears everything and restores the default list.
            await page.getByRole('button', { name: 'Lihat semua dokter' }).click();

            await expect(chips).toHaveCount(0);
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '12 dokter ditemukan.',
            );

            await expectNoA11yViolations(page);
        });

        test('f03-ac8-500', async ({ page }) => {
            const state = await bukaDirektori(page, { dokter: banyakDokter(30) });

            await cariDokter(page, 'Anak');
            await pilihSpesialisasi(page, vp, 'Spesialis Anak');

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '30 dokter ditemukan.',
            );

            await page.getByRole('button', { name: 'Halaman 2' }).click();
            await expect(page.locator('[data-slot="dokter-count"]')).toBeFocused();

            // The next page change fails.
            state.gagal = true;

            const galat = page.locator('[data-slot="error-state"]');

            await page.getByRole('button', { name: 'Halaman 3' }).click();

            await expect(galat).toBeVisible({ timeout: 25_000 });
            await expect(galat).toContainText(
                'Gagal memuat daftar dokter. Periksa koneksi lalu coba lagi.',
            );
            await expect(galat.getByRole('button', { name: 'Coba lagi' })).toBeVisible();

            // Chips, query and current page survive the failure.
            await expect(page.locator('[data-slot="dokter-chip"]')).toHaveCount(1);
            await expect(page.locator('[data-slot="dokter-chip"]')).toContainText(
                'Spesialis Anak',
            );
            await expect(page.locator('[data-slot="dokter-cari"]')).toHaveValue('Anak');

            const sebelumRetry = state.permintaanDokter.length;

            state.gagal = false;

            await galat.getByRole('button', { name: 'Coba lagi' }).click();

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '30 dokter ditemukan.',
                { timeout: 15_000 },
            );

            const setelahRetry = state.permintaanDokter.slice(sebelumRetry);

            expect(
                setelahRetry.some((url) => {
                    const params = new URL(url).searchParams;

                    return (
                        params.get('page') === '3' &&
                        params.get('spesialisasi') === 'SP.A' &&
                        params.get('search') === 'Anak'
                    );
                }),
            ).toBe(true);

            await expectNoA11yViolations(page);
        });

        test('f03-ac9-offline', async ({ page, context }) => {
            const state = await bukaDirektori(page);

            await page.locator('[data-slot="dokter-cari"]').fill('Rina');

            await context.setOffline(true);

            await expect(page.getByTestId('offline-banner')).toBeVisible();

            const sebelum = state.permintaanDokter.length;
            const cari = page.locator('[data-slot="dokter-cari-submit"]');

            await expect(cari).toHaveAttribute('aria-disabled', 'true');
            await expect(cari).toHaveAttribute(
                'aria-describedby',
                'dokter-alasan-offline',
            );

            // Playwright treats `aria-disabled` as non-actionable, but the criterion is
            // exactly that the handler is reachable and still sends nothing.
            await cari.click({ force: true });

            if (vp.nama === 'mobile') {
                const tombol = page.locator('[data-slot="dokter-filter-button"]');

                await expect(tombol).toHaveAttribute('aria-disabled', 'true');
                await tombol.click({ force: true });

                await expect(page.locator('[data-slot="sheet-content"]')).toHaveCount(0);
            }

            await page.waitForTimeout(500);

            expect(state.permintaanDokter.length).toBe(sebelum);
            await expect(page.locator('[data-slot="dokter-cari"]')).toHaveValue('Rina');

            await simpanGambar(page, vp, 'offline');

            await context.setOffline(false);

            await expect(page.getByTestId('offline-banner')).toHaveCount(0);
            await expect(cari).not.toHaveAttribute('aria-disabled', 'true');

            await cari.click();

            await expect
                .poll(
                    () =>
                        state.permintaanDokter.some(
                            (url) => new URL(url).searchParams.get('search') === 'Rina',
                        ),
                    { timeout: 10_000 },
                )
                .toBe(true);

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '1 dokter ditemukan.',
            );

            await expectNoA11yViolations(page);
        });

        test('f03-ac10-pagination', async ({ page }) => {
            const state = await bukaDirektori(page, { dokter: banyakDokter(14) });

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '14 dokter ditemukan.',
            );

            expect(
                punyaPermintaan(
                    state,
                    (params) =>
                        params.get('per_page') === '12' && params.get('page') === '1',
                ),
            ).toBe(true);

            await pilihSpesialisasi(page, vp, 'Spesialis Anak');

            await page.getByRole('button', { name: 'Halaman 2' }).click();

            await expect
                .poll(() =>
                    punyaPermintaan(
                        state,
                        (params) =>
                            params.get('page') === '2' &&
                            params.get('per_page') === '12' &&
                            params.get('spesialisasi') === 'SP.A',
                    ),
                )
                .toBe(true);

            // AC-10: focus returns to the list heading after a page change.
            await expect(page.locator('[data-slot="dokter-count"]')).toBeFocused();
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(2);

            // Past the last page: the server reports a page beyond `last_page`.
            await page.getByRole('button', { name: 'Halaman 1' }).click();
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(12);

            state.halamanKosong = true;

            // A different query key, because page 2 of the previous filter set is still
            // fresh in the React Query cache and would not refetch.
            await cariDokter(page, 'Contoh');

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '14 dokter ditemukan.',
            );
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(12);

            await page.getByRole('button', { name: 'Halaman 2' }).click();

            await expect(page.getByText('Halaman ini kosong')).toBeVisible();
            await expect(page.getByText('Ada 14 dokter yang cocok.')).toBeVisible();

            await page
                .getByRole('button', { name: 'Kembali ke halaman pertama' })
                .click();

            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(12);
            await expect(page.locator('[data-slot="dokter-chip"]')).toContainText(
                'Spesialis Anak',
            );

            await expectNoA11yViolations(page);
        });

        test('f03-ac12-a11y', async ({ page }) => {
            await bukaDirektori(page);

            for (const ukuran of [
                { width: 390, height: 844 },
                { width: 768, height: 1024 },
                { width: 1280, height: 900 },
            ]) {
                await page.setViewportSize(ukuran);

                if (ukuran.width === 390) {
                    const tombol = page.locator('[data-slot="dokter-filter-button"]');
                    const kotakTombol = await tombol.boundingBox();
                    const kotakCari = await page
                        .locator('[data-slot="dokter-cari-submit"]')
                        .boundingBox();

                    expect(kotakTombol?.height ?? 0).toBeGreaterThanOrEqual(44);
                    expect(kotakCari?.height ?? 0).toBeGreaterThanOrEqual(44);

                    await tombol.click();

                    const sheet = page.locator('[data-slot="sheet-content"]');

                    await expect(sheet).toBeVisible();
                    await expect(sheet.locator(':focus')).toHaveCount(1);

                    for (let index = 0; index < 8; index += 1) {
                        await page.keyboard.press('Tab');
                    }

                    expect(
                        await page.evaluate(() => {
                            const isi = document.querySelector(
                                '[data-slot="sheet-content"]',
                            );

                            return (
                                isi !== null &&
                                document.activeElement !== null &&
                                isi.contains(document.activeElement)
                            );
                        }),
                    ).toBe(true);

                    await expectNoA11yViolations(page);

                    await page.keyboard.press('Escape');

                    await expect(sheet).toHaveCount(0);
                    await expect(tombol).toBeFocused();
                }

                await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                    'dokter ditemukan.',
                );
                await expectNoA11yViolations(page);
            }

            await page.setViewportSize({ width: 1280, height: 900 });

            await pilihSpesialisasi(page, { nama: 'desktop', width: 1280, height: 900 }, 'Spesialis Anak');

            const kotakChip = await page
                .locator('[data-slot="dokter-chip"]')
                .first()
                .boundingBox();

            expect(kotakChip?.height ?? 0).toBeGreaterThanOrEqual(44);
        });

        test('f03-ac13-privasi', async ({ page }) => {
            await bukaDirektori(page);

            await cariDokter(page, KATA_UJI);

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '0 dokter ditemukan.',
            );

            expect(await page.title()).toBe('Direktori dokter | Sehatly');
            expect(page.url()).not.toContain(KATA_UJI);

            const label = await page.evaluate(() =>
                Array.from(document.querySelectorAll('[aria-label]')).map((element) =>
                    (element.getAttribute('aria-label') ?? '').toLowerCase(),
                ),
            );

            expect(label.some((teks) => teks.includes(KATA_UJI))).toBe(false);

            await expect(page.locator('[data-sonner-toast]')).toHaveCount(0);
            await expect(page.getByText('Tidak ada dokter yang cocok.')).toBeVisible();

            await expectNoA11yViolations(page);
        });
    });
}
