import { expect, test, type Locator, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F02's acceptance criteria, mocked end to end.
 *
 * Every `/api/v1` request is intercepted, so the file pins the CLIENT's branches - the
 * empty radio group, the exact write body, the same-version withdrawal, the 422 without
 * an automatic retry, offline, the keyboard order, the desktop columns - without needing
 * a live Laravel API and without touching a real consent record. The fixtures are
 * synthetic and the IP address is TEST-NET-2 (`198.51.100.0/24`, RFC 5737).
 *
 * Two viewports for every scenario: 390x844 and 1280x900. `timezoneId` is pinned to
 * `Asia/Jakarta` so the WIB colophon assertion is the same on any machine.
 */

const VIEWPORTS = [
    { nama: 'mobile', width: 390, height: 844 },
    { nama: 'desktop', width: 1280, height: 900 },
] as const;

const JENIS = [
    'syarat_ketentuan',
    'kebijakan_privasi',
    'berbagi_data_medis',
    'pemasaran',
    'komunikasi_tindak_lanjut',
] as const;

type Jenis = (typeof JENIS)[number];

const IP_UJI = '198.51.100.8';
const WAKTU_UJI = '2026-10-01T02:30:00.000000Z';

type EntriPdp = {
    jenis: string;
    efektif: boolean | null;
    versi_dokumen: string | null;
    disetujui_at: string | null;
    ip_address: string | null;
};

type DokumenPdp = {
    jenis: string;
    versi_dokumen: string;
    berlaku_sejak: string;
};

type MockState = {
    persetujuan: EntriPdp[];
    dokumen: DokumenPdp[];
    posts: Array<Record<string, unknown>>;
    getPersetujuan: number;
    getDokumen: number;
};

type HasilPost = { status: number; body: unknown };

type OpsiMock = {
    persetujuan?: EntriPdp[];
    dokumen?: DokumenPdp[];
    onPost?: (body: Record<string, unknown>, state: MockState) => HasilPost;
};

const USER = {
    id: 1,
    uuid: '00000000-0000-4000-8000-000000000002',
    nama_lengkap: 'Pasien Uji F02',
    no_telepon: '081200000002',
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

const DOKTER = {
    id: 1,
    nama_lengkap: 'dr. Andi Prasetyo, Sp.PD',
    foto_profil: null,
    tipe: 'dokter_spesialis',
    pengalaman_tahun: 12,
    bio: null,
    biaya_konsultasi_online: '350000.00',
    biaya_luar_jam: '500000.00',
    durasi_default_menit: 30,
    rating_rata_rata: '4.80',
    jumlah_ulasan: 120,
    jumlah_konsultasi: 340,
    tersedia_telemedisin: true,
    status_verifikasi: 'terverifikasi',
    spesialisasi: [],
    pendidikan: [],
    faskes: [],
    dibuat_at: '2026-01-01T00:00:00.000000Z',
};

const PESANAN = {
    id: 7,
    nomor_pesanan: 'PSN20261001007',
    status: 'menunggu_pembayaran',
    subtotal: '50000.00',
    biaya_kirim: '10000.00',
    total: '60000.00',
};

const DOKUMEN_V01: DokumenPdp[] = JENIS.map((jenis) => ({
    jenis,
    versi_dokumen: 'v01',
    berlaku_sejak: '2026-01-01',
}));

function baris(jenis: Jenis, efektif: boolean | null): EntriPdp {
    if (efektif === null) {
        return {
            jenis,
            efektif: null,
            versi_dokumen: null,
            disetujui_at: null,
            ip_address: null,
        };
    }

    return {
        jenis,
        efektif,
        versi_dokumen: 'v01',
        disetujui_at: WAKTU_UJI,
        ip_address: IP_UJI,
    };
}

function daftar(nilai: Partial<Record<Jenis, boolean | null>>): EntriPdp[] {
    return JENIS.map((jenis) => baris(jenis, nilai[jenis] ?? null));
}

const NOL = daftar({});

const CAMPUR = daftar({
    syarat_ketentuan: true,
    kebijakan_privasi: null,
    berbagi_data_medis: false,
    pemasaran: null,
    komunikasi_tindak_lanjut: null,
});

const SEMUA_WAJIB = daftar({
    syarat_ketentuan: true,
    kebijakan_privasi: true,
    berbagi_data_medis: true,
});

async function balasJson(route: Route, status: number, body: unknown): Promise<void> {
    await route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

function meta(total: number, perPage = total): Record<string, unknown> {
    return {
        current_page: 1,
        last_page: 1,
        per_page: perPage,
        total,
        from: total === 0 ? null : 1,
        to: total === 0 ? null : total,
    };
}

function postBawaan(body: Record<string, unknown>, state: MockState): HasilPost {
    const row: EntriPdp = {
        jenis: String(body.jenis),
        efektif: body.disetujui === true,
        versi_dokumen: String(body.versi_dokumen),
        disetujui_at: WAKTU_UJI,
        ip_address: IP_UJI,
    };

    state.persetujuan = state.persetujuan.map((entri) =>
        entri.jenis === row.jenis ? row : entri,
    );

    return {
        status: 201,
        body: {
            success: true,
            message: 'Persetujuan PDP berhasil dicatat.',
            data: { persetujuan: row },
        },
    };
}

async function pasangMock(page: Page, opsi: OpsiMock = {}): Promise<MockState> {
    const state: MockState = {
        persetujuan: opsi.persetujuan ?? NOL,
        dokumen: opsi.dokumen ?? DOKUMEN_V01,
        posts: [],
        getPersetujuan: 0,
        getDokumen: 0,
    };

    const onPost = opsi.onPost ?? postBawaan;

    await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;
        const method = request.method();

        if (path === '/api/v1/pdp/dokumen' && method === 'GET') {
            state.getDokumen += 1;

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar dokumen PDP berhasil dimuat.',
                data: { dokumen: state.dokumen },
                meta: meta(state.dokumen.length),
            });
        }

        if (path === '/api/v1/pdp/persetujuan' && method === 'GET') {
            state.getPersetujuan += 1;

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar persetujuan PDP berhasil dimuat.',
                data: { persetujuan: state.persetujuan },
                meta: meta(state.persetujuan.length),
            });
        }

        if (path === '/api/v1/pdp/persetujuan' && method === 'POST') {
            const body = JSON.parse(request.postData() ?? '{}') as Record<string, unknown>;

            state.posts.push(body);

            const hasil = onPost(body, state);

            return balasJson(route, hasil.status, hasil.body);
        }

        if (path === '/api/v1/me') {
            return balasJson(route, 200, {
                success: true,
                message: 'Akun berhasil dimuat.',
                data: { user: USER },
            });
        }

        if (path === '/api/v1/notifikasi') {
            return balasJson(route, 200, {
                success: true,
                message: 'Daftar notifikasi berhasil dimuat.',
                data: { notifikasi: [] },
                meta: { ...meta(0, 5), unread: 0 },
            });
        }

        if (/^\/api\/v1\/dokter\/[^/]+\/slot$/.test(path)) {
            return balasJson(route, 200, {
                success: true,
                message: 'Ketersediaan jam berhasil dimuat.',
                data: {
                    tanggal: url.searchParams.get('tanggal'),
                    timezone: 'Asia/Jakarta',
                    slots: [],
                },
                meta: meta(0, 0),
            });
        }

        if (/^\/api\/v1\/dokter\/[^/]+$/.test(path) && method === 'GET') {
            return balasJson(route, 200, {
                success: true,
                message: 'Detail dokter berhasil dimuat.',
                data: { dokter: DOKTER },
            });
        }

        if (path === '/api/v1/dokter' && method === 'GET') {
            return balasJson(route, 200, {
                success: true,
                message: 'Direktori dokter berhasil dimuat.',
                data: { dokter: [] },
                meta: meta(0),
            });
        }

        if (path === '/api/v1/pasien/booking') {
            return balasJson(route, 200, {
                success: true,
                message: 'Daftar booking berhasil dimuat.',
                data: { booking: [] },
                meta: meta(0, 10),
            });
        }

        if (path === '/api/v1/pasien/anggota-keluarga') {
            return balasJson(route, 200, {
                success: true,
                message: 'Daftar anggota keluarga berhasil dimuat.',
                data: { anggota_keluarga: [] },
                meta: meta(0, 100),
            });
        }

        if (path === '/api/v1/referensi/metode-pembayaran') {
            return balasJson(route, 200, {
                success: true,
                message: 'Daftar metode pembayaran berhasil dimuat.',
                data: { metode_pembayaran: [] },
                meta: meta(0),
            });
        }

        if (path === '/api/v1/pesanan-obat/7') {
            return balasJson(route, 200, {
                success: true,
                message: 'Pesanan berhasil dimuat.',
                data: { pesanan: PESANAN },
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

async function masukPalsu(page: Page): Promise<void> {
    await page.addInitScript(() => {
        sessionStorage.setItem('sehatly.access_token', 'token-uji-f02');
        sessionStorage.setItem('sehatly.refresh_token', 'refresh-uji-f02');
    });
}

function slot(page: Page, jenis: Jenis): Locator {
    return page.locator(`[data-slot="pdp-slot"][data-jenis="${jenis}"]`);
}

type RGB = [number, number, number];

function oklchKeSrgb(l: number, c: number, h: number): RGB {
    const rad = (h * Math.PI) / 180;
    const a = c * Math.cos(rad);
    const b = c * Math.sin(rad);

    const lp = l + 0.3963377774 * a + 0.2158037573 * b;
    const mp = l - 0.1055613458 * a - 0.0638541728 * b;
    const sp = l - 0.0894841775 * a - 1.291485548 * b;

    const l3 = lp ** 3;
    const m3 = mp ** 3;
    const s3 = sp ** 3;

    const linear: [number, number, number] = [
        4.0767416621 * l3 - 3.3077115913 * m3 + 0.2309699292 * s3,
        -1.2684380046 * l3 + 2.6097574011 * m3 - 0.3413193965 * s3,
        -0.0041960863 * l3 - 0.7034186147 * m3 + 1.707614701 * s3,
    ];

    const koreksi = (nila: number): number => {
        const dikoreksi = nila <= 0.0031308 ? 12.92 * nila : 1.055 * nila ** (1 / 2.4) - 0.055;

        return Math.min(1, Math.max(0, dikoreksi)) * 255;
    };

    return [koreksi(linear[0]), koreksi(linear[1]), koreksi(linear[2])];
}

function parseWarna(css: string): RGB | null {
    const teks = css.trim();

    const oklch = /^oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\)$/.exec(teks);

    if (oklch !== null) {
        return oklchKeSrgb(Number(oklch[1]), Number(oklch[2]), Number(oklch[3]));
    }

    const rgb = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/.exec(teks);

    if (rgb !== null) {
        return [Number(rgb[1]), Number(rgb[2]), Number(rgb[3])];
    }

    const hex = /^#([0-9a-f]{6})$/i.exec(teks);

    if (hex !== null) {
        const nilai = hex[1];

        return [
            Number.parseInt(nilai.slice(0, 2), 16),
            Number.parseInt(nilai.slice(2, 4), 16),
            Number.parseInt(nilai.slice(4, 6), 16),
        ];
    }

    return null;
}

function luminance(warna: RGB): number {
    const linier = warna.map((nila) => {
        const x = nila / 255;

        return x <= 0.04045 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * linier[0] + 0.7152 * linier[1] + 0.0722 * linier[2];
}

function rasioKontras(a: RGB, b: RGB): number {
    const la = luminance(a);
    const lb = luminance(b);

    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

async function kontrasJudulVsKartu(page: Page, judul: Locator): Promise<number> {
    const warna = await judul.evaluate((el) => ({
        teks: getComputedStyle(el).color,
        kartu: getComputedStyle(document.documentElement).getPropertyValue('--card'),
    }));

    const depan = parseWarna(warna.teks);
    const belakang = parseWarna(warna.kartu);

    expect(depan, `warna judul tidak terbaca: ${warna.teks}`).not.toBeNull();
    expect(belakang, `token --card tidak terbaca: ${warna.kartu}`).not.toBeNull();

    return rasioKontras(depan as RGB, belakang as RGB);
}

for (const vp of VIEWPORTS) {
    test.describe(`F02 consent ${vp.nama} ${vp.width}x${vp.height}`, () => {
        test.use({
            viewport: { width: vp.width, height: vp.height },
            timezoneId: 'Asia/Jakarta',
        });

        test('f02-ac1-pintu-masuk', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, { persetujuan: NOL });

            await page.goto('/profil/privasi');

            await expect(
                page.getByRole('heading', { name: 'Privasi dan data', level: 1 }),
            ).toBeVisible();

            await expect(
                page.getByText(
                    'Ada 5 hal yang perlu Anda putuskan. Tidak ada pilihan yang sudah ditentukan untuk Anda.',
                ),
            ).toBeVisible();

            const pertama = slot(page, 'syarat_ketentuan');

            await expect(pertama).toBeVisible();
            await expect(pertama).toContainText('Aturan pemakaian layanan Sehatly');

            const kontrol = pertama.locator('[data-slot="toggle-group-item"]').first();
            const kotak = await kontrol.boundingBox();

            expect(kotak, 'kontrol keputusan slot 1 harus punya bounding box').not.toBeNull();
            expect((kotak as { y: number; height: number }).y + (kotak as { y: number; height: number }).height).toBeLessThanOrEqual(vp.height);

            if (vp.width >= 768) {
                await expect(
                    page.getByRole('link', { name: 'Privasi dan data' }).first(),
                ).toBeVisible();
            }

            await expectNoA11yViolations(page);
        });

        test('f02-ac2-lima-slot-berurutan', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, { persetujuan: CAMPUR });

            await page.goto('/profil/privasi');

            const semua = page.locator('[data-slot="pdp-slot"]');

            await expect(semua).toHaveCount(5);
            expect(
                await semua.evaluateAll((elemen) =>
                    elemen.map((el) => el.getAttribute('data-jenis')),
                ),
            ).toEqual([...JENIS]);

            await expectNoA11yViolations(page);
        });

        test('f02-ac3-tanpa-pra-centang', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, { persetujuan: NOL });

            await page.goto('/profil/privasi');

            await expect(
                page.locator('[data-slot="toggle-group-item"][data-state="on"]'),
            ).toHaveCount(0);

            const badge = page.locator('[data-slot="pdp-status"][data-status="null"]');

            await expect(badge).toHaveCount(5);
            await expect(badge.first()).toContainText('Belum dijawab');
            await expect(badge.first().locator('svg')).toHaveCount(1);

            await expectNoA11yViolations(page);
        });

        test('f02-ac4-simpan-satu-ketukan', async ({ page }) => {
            await masukPalsu(page);
            const state = await pasangMock(page, { persetujuan: NOL });

            await page.goto('/profil/privasi');

            const target = slot(page, 'kebijakan_privasi');

            await target
                .getByRole('radio', { name: 'Setujui Kebijakan privasi', exact: true })
                .click();

            await expect.poll(() => state.posts.length).toBe(1);
            expect(state.posts[0]).toEqual({
                jenis: 'kebijakan_privasi',
                versi_dokumen: 'v01',
                disetujui: true,
            });

            await expect(
                target.locator('[data-slot="pdp-status"][data-status="true"]'),
            ).toContainText('Disetujui');

            await expect(
                target.getByText(/Versi dokumen v01 • Dicatat .* WIB/),
            ).toBeVisible();

            await expect(
                page.getByRole('status').filter({ hasText: 'Persetujuan dicatat.' }),
            ).toBeVisible();

            await expectNoA11yViolations(page);
        });

        test('f02-ac5-tarik-dengan-dialog', async ({ page }) => {
            await masukPalsu(page);
            const state = await pasangMock(page, {
                persetujuan: daftar({ kebijakan_privasi: true }),
            });

            await page.goto('/profil/privasi');

            const target = slot(page, 'kebijakan_privasi');
            const pemicu = target.getByRole('button', { name: 'Tarik persetujuan' });

            await pemicu.click();

            const dialog = page.locator('[data-slot="dialog-content"]');

            await expect(dialog).toBeVisible();
            await expect(
                dialog.getByRole('heading', { name: 'Tarik persetujuan?' }),
            ).toBeVisible();
            await expect(dialog.getByRole('button', { name: 'Batal' })).toBeVisible();

            await dialog.getByRole('button', { name: 'Batal' }).click();

            await expect(dialog).toHaveCount(0);
            await expect(pemicu).toBeFocused();

            await pemicu.click();
            await dialog.getByRole('button', { name: 'Ya, tarik persetujuan' }).click();

            await expect.poll(() => state.posts.length).toBe(1);
            expect(state.posts[0]).toEqual({
                jenis: 'kebijakan_privasi',
                versi_dokumen: 'v01',
                disetujui: false,
            });

            await expect(
                target.locator('[data-slot="pdp-status"][data-status="false"]'),
            ).toContainText('Tidak disetujui');

            await expectNoA11yViolations(page);
        });

        test('f02-ac6-422-tanpa-retry', async ({ page }) => {
            await masukPalsu(page);

            const pesan = [
                'Versi dokumen yang dikirim bukan versi aktif.',
                'Versi aktif saat ini adalah v01. Muat ulang GET /api/v1/pdp/dokumen lalu kirim ulang.',
            ];

            const state = await pasangMock(page, {
                persetujuan: NOL,
                onPost: (_body, mockState) => {
                    mockState.persetujuan = mockState.persetujuan.map((entri) =>
                        entri.jenis === 'kebijakan_privasi'
                            ? {
                                  jenis: 'kebijakan_privasi',
                                  efektif: false,
                                  versi_dokumen: 'v01',
                                  disetujui_at: WAKTU_UJI,
                                  ip_address: IP_UJI,
                              }
                            : entri,
                    );

                    return {
                        status: 422,
                        body: {
                            success: false,
                            message: 'The given data was invalid.',
                            errors: { versi_dokumen: pesan },
                        },
                    };
                },
            });

            await page.goto('/profil/privasi');

            await slot(page, 'kebijakan_privasi')
                .getByRole('radio', { name: 'Setujui Kebijakan privasi', exact: true })
                .click();

            const peringatan = page.locator('[data-slot="pdp-versi-bentrok"]');

            await expect(peringatan).toBeVisible();
            await expect(peringatan).toHaveAttribute('role', 'alert');
            await expect(peringatan).toContainText(pesan[0]);
            await expect(peringatan).toContainText('Menampilkan data terbaru dari server.');

            await expect.poll(() => state.getPersetujuan).toBeGreaterThanOrEqual(2);

            await expect(
                slot(page, 'kebijakan_privasi').locator(
                    '[data-slot="pdp-status"][data-status="false"]',
                ),
            ).toContainText('Tidak disetujui');

            expect(state.posts.length).toBe(1);

            await expectNoA11yViolations(page);
        });

        test('f02-ac7-target-44-dan-kontras', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, { persetujuan: CAMPUR });

            await page.goto('/profil/privasi');

            const kontrol = [
                ...(await page.locator('[data-slot="toggle-group-item"]').all()),
                ...(await page.locator('[data-slot="pdp-keputusan"]').all()),
                ...(await page.locator('[data-slot="collapsible-trigger"]').all()),
            ];

            expect(kontrol.length).toBeGreaterThan(0);

            for (const elemen of kontrol) {
                const kotak = await elemen.boundingBox();

                expect(kotak, 'kontrol harus punya bounding box').not.toBeNull();
                expect(Math.round((kotak as { height: number }).height)).toBeGreaterThanOrEqual(44);
            }

            const rasio = await kontrasJudulVsKartu(
                page,
                slot(page, 'syarat_ketentuan').locator('[data-slot="card-title"]'),
            );

            expect(rasio).toBeGreaterThanOrEqual(4.5);

            await expectNoA11yViolations(page);
        });

        test('f02-ac8-offline', async ({ page, context }) => {
            await masukPalsu(page);
            await pasangMock(page, { persetujuan: CAMPUR });

            await page.goto('/profil/privasi');

            await context.setOffline(true);

            const banner = page.getByTestId('offline-banner');

            await expect(banner).toBeVisible();
            await expect(banner).toHaveAttribute('role', 'status');

            const keputusan = page.locator(
                '[data-slot="toggle-group-item"], [data-slot="pdp-keputusan"]',
            );

            const jumlah = await keputusan.count();

            expect(jumlah).toBeGreaterThan(0);

            for (let i = 0; i < jumlah; i += 1) {
                await expect(keputusan.nth(i)).toBeDisabled();
            }

            await context.setOffline(false);

            await expect(banner).toHaveCount(0);

            for (let i = 0; i < jumlah; i += 1) {
                await expect(keputusan.nth(i)).toBeEnabled();
            }

            await expectNoA11yViolations(page);
        });

        test('f02-ac9-a11y-dan-fokus', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, { persetujuan: CAMPUR });

            await page.goto('/profil/privasi');

            await expectNoA11yViolations(page);

            const urutan: string[] = [];
            let terakhir: string | null = null;
            let fokusTerlihat: { visible: boolean; boxShadow: string } | null = null;

            for (let i = 0; i < 160 && urutan.length < JENIS.length; i += 1) {
                await page.keyboard.press('Tab');

                const posisi = await page.evaluate(() => {
                    const aktif = document.activeElement;

                    if (aktif === null) {
                        return null;
                    }

                    const induk = aktif.closest('[data-slot="pdp-slot"]');

                    return {
                        jenis: induk?.getAttribute('data-jenis') ?? null,
                        visible: aktif.matches(':focus-visible'),
                        boxShadow: getComputedStyle(aktif).boxShadow,
                    };
                });

                if (posisi === null || posisi.jenis === null) {
                    continue;
                }

                if (posisi.jenis !== terakhir) {
                    urutan.push(posisi.jenis);
                    terakhir = posisi.jenis;

                    if (posisi.jenis === 'syarat_ketentuan') {
                        fokusTerlihat = {
                            visible: posisi.visible,
                            boxShadow: posisi.boxShadow,
                        };
                    }
                }
            }

            expect(urutan).toEqual([...JENIS]);
            expect(fokusTerlihat, 'fokus harus mencapai slot 1').not.toBeNull();
            expect((fokusTerlihat as { visible: boolean }).visible).toBe(true);
            expect((fokusTerlihat as { boxShadow: string }).boxShadow).not.toBe('none');
        });

        test('f02-ac10-gate-403', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, { persetujuan: CAMPUR });

            await page.goto('/booking/1');

            const gate = page.locator('[data-slot="forbidden-state"]');

            await expect(gate).toBeVisible();
            await expect(gate).toContainText('Persetujuan diperlukan');
            await expect(gate).toContainText('Berbagi data medis');

            const tautan = gate.getByRole('link', { name: 'Buka Privasi dan data' });

            await expect(tautan).toBeVisible();
            await expect(tautan).toHaveAttribute(
                'href',
                '/profil/privasi?kembali=%2Fbooking%2F1',
            );

            await expect(page.getByRole('button', { name: 'Coba lagi' })).toHaveCount(0);
            await expect(page.getByRole('button', { name: /Kirim booking/ })).toHaveCount(0);

            await expectNoA11yViolations(page);

            await page.goto('/dokter');

            await expect(page.locator('[data-slot="forbidden-state"]')).toHaveCount(0);
        });

        test('f02-ac11-desktop-dua-kolom', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, { persetujuan: CAMPUR });

            await page.goto('/profil/privasi');

            await expect(page).toHaveTitle('Privasi dan data');

            expect(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= window.innerWidth + 1,
                ),
            ).toBe(true);

            const hak = page.locator('[data-slot="pdp-hak"]');
            const pertama = slot(page, 'syarat_ketentuan');

            await expect(hak).toBeVisible();

            const kotakKiri = await pertama.boundingBox();
            const kotakKanan = await hak.boundingBox();

            expect(kotakKiri).not.toBeNull();
            expect(kotakKanan).not.toBeNull();

            if (vp.width >= 1024) {
                expect((kotakKanan as { x: number }).x).toBeGreaterThanOrEqual(
                    (kotakKiri as { x: number; width: number }).x +
                        (kotakKiri as { x: number; width: number }).width -
                        2,
                );
                expect(
                    Math.abs(
                        (kotakKanan as { y: number }).y -
                            (kotakKiri as { y: number }).y,
                    ),
                ).toBeLessThan(48);
            } else {
                expect((kotakKanan as { y: number }).y).toBeGreaterThan(
                    (kotakKiri as { y: number }).y,
                );
            }

            await expectNoA11yViolations(page);
        });

        test('f02-ac12-tanpa-data-sensitif', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, {
                persetujuan: daftar({ kebijakan_privasi: true }),
            });

            await page.goto('/profil/privasi');

            const target = slot(page, 'kebijakan_privasi');

            await target.getByRole('button', { name: 'Tarik persetujuan' }).click();
            await page
                .locator('[data-slot="dialog-content"]')
                .getByRole('button', { name: 'Ya, tarik persetujuan' })
                .click();

            await expect(
                target.locator('[data-slot="pdp-status"][data-status="false"]'),
            ).toBeVisible();

            await expect(page.getByText(IP_UJI)).toHaveCount(0);
            expect(await page.locator('body').innerText()).not.toContain(IP_UJI);

            await expect(page.getByRole('status').filter({ hasText: 'Persetujuan dicatat.' })).not.toContainText(IP_UJI);

            expect(page.url()).not.toContain('disetujui');
            expect(await page.title()).not.toContain('Disetujui');
            await expect(page.locator('[data-sonner-toast]')).toHaveCount(0);

            await target.getByRole('button', { name: 'Baca selengkapnya' }).click();

            const detailIp = target.locator('[data-slot="pdp-ip-detail"]');

            await expect(detailIp).toBeVisible();
            await expect(detailIp).toContainText(IP_UJI);
            await expect(page.getByText(IP_UJI)).toHaveCount(1);

            await expectNoA11yViolations(page);
        });

        test('f02-gate-pembayaran-blokir', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, { persetujuan: CAMPUR });

            await page.goto('/pembayaran/7');

            const gate = page.locator('[data-slot="forbidden-state"]');

            await expect(gate).toBeVisible();
            await expect(
                gate.getByRole('link', { name: 'Buka Privasi dan data' }),
            ).toHaveAttribute('href', '/profil/privasi?kembali=%2Fpembayaran%2F7');
            await expect(page.locator('[data-slot="payment-submit"]')).toHaveCount(0);

            await expectNoA11yViolations(page);
        });

        test('f02-gate-pembayaran-lolos', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, { persetujuan: SEMUA_WAJIB });

            await page.goto('/pembayaran/7');

            await expect(page.locator('[data-slot="payment-submit"]')).toBeVisible();
            await expect(page.locator('[data-slot="forbidden-state"]')).toHaveCount(0);
        });

        test('f02-gate-booking-lolos', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, { persetujuan: SEMUA_WAJIB });

            await page.goto('/booking/1');

            await expect(page.getByRole('button', { name: /Kirim booking/ })).toBeVisible();
            await expect(page.locator('[data-slot="forbidden-state"]')).toHaveCount(0);
        });

        test('f02-register-notice', async ({ page }) => {
            await pasangMock(page);
            await masukPalsu(page);

            await page.goto('/profil/edit/1?sign_up=true');

            /**
             * Owner option (a) for F01 §12 #1: the two mandatory consents are taken on
             * the account-creation screen, and `POST /auth/sign-up/lengkapi` writes both
             * ledger rows when it turns the phone-verified shell into a patient. The F02
             * screen owns only the three optional consents afterwards.
             *
             * This is no longer `/register`: one door, and the form that finishes a new
             * account is `/profil/edit/{id}?sign_up=true`. Two controls rather than one,
             * because the ledger records two decisions and a single box ticking both
             * would record both from one act.
             */
            const kotakSyarat = page.getByRole('checkbox', {
                name: /Syarat dan Ketentuan/,
            });
            const kotakPrivasi = page.getByRole('checkbox', {
                name: /Kebijakan Privasi/,
            });

            await expect(kotakSyarat).toBeVisible();
            await expect(kotakPrivasi).toBeVisible();

            // Scoped to the form: the left column carries its OWN privacy notice, so a
            // page-level locator for that link name resolves to two.
            const formulir = page.locator('form');

            await expect(
                formulir.getByRole('link', { name: 'Syarat dan Ketentuan' }),
            ).toHaveAttribute('href', '/syarat-ketentuan');

            await expect(
                formulir.getByRole('link', { name: 'Kebijakan Privasi' }),
            ).toHaveAttribute('href', '/kebijakan-privasi');

            await expect(page.getByRole('checkbox')).toHaveCount(2);

            await expectNoA11yViolations(page);
        });

        test('f02-halaman-statis', async ({ page }) => {
            await pasangMock(page);

            for (const [jalur, judul] of [
                ['/kebijakan-privasi', 'Kebijakan privasi'],
                ['/syarat-ketentuan', 'Syarat dan ketentuan'],
            ] as const) {
                await page.goto(jalur);

                await expect(
                    page.getByRole('heading', { name: judul, level: 1 }),
                ).toBeVisible();

                await expectNoA11yViolations(page);
            }
        });
    });
}
