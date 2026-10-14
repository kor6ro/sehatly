import { expect, test, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F11's `[SIAP]` acceptance criteria, mocked end to end at 390x844 and 1280x900.
 *
 * Every request is intercepted with `page.route` and every fixture is invented, so the
 * assertions are identical on any machine and no real health data is involved. The
 * session is seeded through an init script, the same style `booking-f05.spec.ts` uses.
 *
 * The fixtures are adversarial on purpose: `tautan` values include an absolute URL, a
 * `javascript:` payload and a protocol-relative host, each of which must produce a
 * non-clickable row rather than a navigation. Medical words appear in `isi` - which the
 * owner is entitled to read in their own inbox - and must not escape into the tab title,
 * the URL, a toast or the bell's accessible name.
 */

const VIEWPORTS = [
    { nama: 'mobile', width: 390, height: 844 },
    { nama: 'desktop', width: 1280, height: 900 },
] as const;

type Viewport = (typeof VIEWPORTS)[number];

const KATA_UJI = 'Amoxicillin 500 mg';
const ALASAN_UJI = 'suspect demam berdarah';
const DIBACA_AT = '2026-10-04T01:00:00.000000Z';

const USER_PASIEN = {
    id: 5,
    uuid: '00000000-0000-4000-8000-000000000005',
    nama_lengkap: 'Siti Aminah',
    no_telepon: '081200000005',
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

const USER_DOKTER = {
    ...USER_PASIEN,
    id: 9,
    uuid: '00000000-0000-4000-8000-000000000009',
    nama_lengkap: 'dr. Rina Wulandari, Sp.PD',
    no_telepon: '081200000009',
    tipe: 'dokter',
};

type TipeBaris = 'booking' | 'pembayaran' | 'resep' | 'chat' | 'lab' | 'promo' | 'sistem';

type BarisNotif = {
    id: number;
    judul: string;
    isi: string;
    tipe: TipeBaris;
    tautan: string | null;
    payload: Record<string, unknown> | null;
    dibaca_at: string | null;
    dibuat_at: string;
};

const BARIS_RESE_42: BarisNotif = {
    id: 101,
    judul: 'Resep Anda sudah diverifikasi.',
    isi: 'Resep Anda sudah diverifikasi dan dapat ditebus di apotek.',
    tipe: 'resep',
    tautan: '/api/v1/resep/42',
    payload: { resep_id: 42 },
    dibaca_at: null,
    dibuat_at: '2026-10-03T07:02:00.000000Z',
};

const BARIS_BOOKING: BarisNotif = {
    id: 102,
    judul: 'Booking berhasil dibuat.',
    isi: 'Booking Anda untuk 4 Okt 2026 09.30 WIB sudah terkonfirmasi.',
    tipe: 'booking',
    tautan: '/api/v1/booking/77',
    payload: { booking_id: 77 },
    dibaca_at: null,
    dibuat_at: '2026-10-03T06:30:00.000000Z',
};

const BARIS_INVOICE: BarisNotif = {
    id: 103,
    judul: 'Pembayaran Anda telah diterima.',
    isi: 'Pembayaran tagihan Anda sudah kami terima.',
    tipe: 'pembayaran',
    tautan: '/api/v1/invoice/9',
    payload: { invoice_id: 9 },
    dibaca_at: null,
    dibuat_at: '2026-10-03T05:15:00.000000Z',
};

const BARIS_KONSULTASI: BarisNotif = {
    id: 104,
    judul: 'Ada pesan baru di konsultasi Anda.',
    isi: 'Pesan baru dari dokter Anda di ruang konsultasi.',
    tipe: 'chat',
    tautan: '/api/v1/konsultasi/5/chat',
    payload: { konsultasi_id: 5, pengirim_user_id: 9 },
    dibaca_at: null,
    dibuat_at: '2026-10-03T04:45:00.000000Z',
};

const BARIS_SISTEM: BarisNotif = {
    id: 105,
    judul: 'Pemeliharaan selesai.',
    isi: 'Layanan Sehatly kembali normal.',
    tipe: 'sistem',
    tautan: null,
    payload: null,
    dibaca_at: null,
    dibuat_at: '2026-10-02T01:00:00.000000Z',
};

/** `n` rows with ids starting at `mulai`, all unread unless `ubah` says otherwise. */
function deret(n: number, mulai: number, ubah: Partial<BarisNotif> = {}): BarisNotif[] {
    return Array.from({ length: n }, (_unused, index) => ({
        id: mulai + index,
        judul: `Pembaruan nomor ${mulai + index}`,
        isi: `Ringkasan pembaruan nomor ${mulai + index}.`,
        tipe: 'sistem' as TipeBaris,
        tautan: null,
        payload: null,
        dibaca_at: null,
        dibuat_at: '2026-10-03T07:02:00.000000Z',
        ...ubah,
    }));
}

const RESEP_42 = {
    id: 42,
    nomor_resep: 'RSP-2026-0042',
    konsultasi_id: 5,
    rekam_medis_id: null,
    pasien_id: 5,
    dokter_id: 9,
    apotek_id: null,
    tipe: 'digital',
    status: 'siap',
    catatan_dokter: null,
    tanggal_resep: '2026-10-03T07:00:00.000000Z',
    berlaku_sampai: '2026-11-03',
    is_kedaluwarsa: false,
    terminal: false,
    is_iter: false,
    jumlah_iter: 0,
    qr_token: 'qr-uji-f11',
    dibuat_at: '2026-10-03T07:00:00.000000Z',
    items: [],
};

type OpsiMock = {
    baris?: BarisNotif[];
    unread?: number;
    statusList?: number;
    delayMs?: number;
    delayPutMs?: number;
    /** Fixed `data.ditandai`; `null` answers the count of rows actually changed. */
    ditandai?: number | null;
    userTipe?: 'pasien' | 'dokter';
    filterTrueKosong?: boolean;
};

type State = {
    baris: BarisNotif[];
    unread: number;
    statusList: number;
    delayMs: number;
    delayPutMs: number;
    ditandai: number | null;
    userTipe: 'pasien' | 'dokter';
    filterTrueKosong: boolean;
    counts: Record<string, number>;
    putIds: number[];
    putSemua: number;
};

async function balasJson(route: Route, status: number, body: unknown): Promise<void> {
    await route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

function meta(
    total: number,
    perPage: number,
    halaman = 1,
    lastPage = 1,
): Record<string, unknown> {
    return {
        current_page: halaman,
        last_page: lastPage,
        per_page: perPage,
        total,
        from: null,
        to: null,
    };
}

function galat(status: number, message: string): Record<string, unknown> {
    return { success: false, message, errors: {} };
}

/**
 * Intercept every `/api/v1` call the notification surface makes.
 *
 * The catch-all at the bottom is load-bearing: an unmocked request would reach the real
 * API - or the Vite proxy's 401 - and send the transport down its session-expiry path,
 * which redirects to `/login` for a reason that has nothing to do with the assertion.
 */
async function pasangMock(page: Page, opsi: OpsiMock = {}): Promise<State> {
    const state: State = {
        baris: opsi.baris === undefined ? [] : [...opsi.baris],
        unread: opsi.unread ?? 0,
        statusList: opsi.statusList ?? 200,
        delayMs: opsi.delayMs ?? 0,
        delayPutMs: opsi.delayPutMs ?? 0,
        ditandai: opsi.ditandai ?? null,
        userTipe: opsi.userTipe ?? 'pasien',
        filterTrueKosong: opsi.filterTrueKosong ?? false,
        counts: {},
        putIds: [],
        putSemua: 0,
    };

    await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;
        const method = request.method();
        const kunci = `${method} ${path}`;

        state.counts[kunci] = (state.counts[kunci] ?? 0) + 1;

        if (state.delayMs > 0) {
            await new Promise((resolve) => setTimeout(resolve, state.delayMs));
        }

        if (path === '/api/v1/me') {
            return balasJson(route, 200, {
                success: true,
                message: 'Akun berhasil dimuat.',
                data: { user: state.userTipe === 'dokter' ? USER_DOKTER : USER_PASIEN },
            });
        }

        if (path === '/api/v1/notifikasi' && method === 'GET') {
            if (state.statusList !== 200) {
                return balasJson(
                    route,
                    state.statusList,
                    galat(
                        state.statusList,
                        state.statusList === 403
                            ? 'This action is unauthorized.'
                            : 'Terjadi kesalahan pada server.',
                    ),
                );
            }

            const halaman = Number(url.searchParams.get('page') ?? '1');
            const perPage = Number(url.searchParams.get('per_page') ?? '10');
            const hanyaBelumDibaca = url.searchParams.get('unread') === 'true';

            let rows = state.baris;

            if (hanyaBelumDibaca && state.filterTrueKosong) {
                rows = [];
            } else if (hanyaBelumDibaca) {
                rows = rows.filter((row) => row.dibaca_at === null);
            }

            const mulai = (halaman - 1) * perPage;
            const halamanIni = rows.slice(mulai, mulai + perPage);

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar notifikasi berhasil dimuat.',
                data: { notifikasi: halamanIni },
                meta: {
                    ...meta(
                        rows.length,
                        perPage,
                        halaman,
                        Math.max(1, Math.ceil(rows.length / perPage)),
                    ),
                    unread: state.unread,
                },
            });
        }

        const bacaSatu = /^\/api\/v1\/notifikasi\/(\d+)\/baca$/.exec(path);

        if (bacaSatu !== null && method === 'PUT') {
            const id = Number(bacaSatu[1]);

            state.putIds.push(id);

            if (state.delayPutMs > 0) {
                await new Promise((resolve) => setTimeout(resolve, state.delayPutMs));
            }

            state.baris = state.baris.map((row) =>
                row.id === id ? { ...row, dibaca_at: DIBACA_AT } : row,
            );
            state.unread = Math.max(0, state.unread - 1);

            return balasJson(route, 200, {
                success: true,
                message: 'Notifikasi ditandai dibaca.',
                data: { notifikasi: state.baris.find((row) => row.id === id) ?? null },
            });
        }

        if (path === '/api/v1/notifikasi/baca-semua' && method === 'PUT') {
            state.putSemua += 1;

            const berubah = state.baris.filter((row) => row.dibaca_at === null).length;
            const jawab = state.ditandai ?? berubah;

            state.baris = state.baris.map((row) => ({
                ...row,
                dibaca_at: row.dibaca_at ?? DIBACA_AT,
            }));
            state.unread = 0;

            return balasJson(route, 200, {
                success: true,
                message: 'Semua notifikasi ditandai dibaca.',
                data: { ditandai: jawab },
            });
        }

        if (path === '/api/v1/resep/42' && method === 'GET') {
            return balasJson(route, 200, {
                success: true,
                message: 'Resep berhasil dimuat.',
                data: {
                    resep: RESEP_42,
                    verifikasi: [],
                    warning_grup: { antar_item: [], riwayat_resep: [], alergi: [] },
                },
            });
        }

        if (path === '/api/v1/pasien/booking' && method === 'GET') {
            return balasJson(route, 200, {
                success: true,
                message: 'Daftar booking berhasil dimuat.',
                data: { booking: [] },
                meta: meta(0, 15),
            });
        }

        if (path === '/api/v1/dokter/booking' && method === 'GET') {
            return balasJson(route, 200, {
                success: true,
                message: 'Daftar booking berhasil dimuat.',
                data: { booking: [] },
                meta: meta(0, 15),
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
        sessionStorage.setItem('sehatly.access_token', 'token-uji-f11');
        sessionStorage.setItem('sehatly.refresh_token', 'refresh-uji-f11');
    });
}

async function simpanGambar(
    page: Page,
    vp: Viewport,
    keadaan: string,
): Promise<void> {
    await page.screenshot({
        path: `ux/refs/f11/f11-${keadaan}-${vp.nama}.png`,
        fullPage: true,
        animations: 'disabled',
    });
}

/**
 * On a phone the whole sidebar - the bell included - is mounted inside the Radix
 * `Sheet`, which does not render its content until it is opened. Every bell assertion
 * therefore opens the drawer first on mobile and leaves it open for the duration.
 */
async function bukaDrawer(page: Page, vp: Viewport): Promise<void> {
    if (vp.width >= 768) {
        return;
    }

    await page
        .getByRole('button', { name: 'Buka atau tutup menu navigasi' })
        .click();
    await expect(page.locator('[data-slot="sidebar"][data-mobile="true"]')).toBeVisible();
}

async function tutupDrawer(page: Page, vp: Viewport): Promise<void> {
    if (vp.width >= 768) {
        return;
    }

    await page.keyboard.press('Escape');
    await expect(page.locator('[data-slot="sidebar"][data-mobile="true"]')).toHaveCount(0);
}

async function bukaBell(page: Page, vp: Viewport): Promise<void> {
    await bukaDrawer(page, vp);
    await page.locator('[data-slot="notifikasi-bell"]').click();
}

async function teksBadge(page: Page, vp: Viewport): Promise<string> {
    await bukaDrawer(page, vp);

    const teks = await page.locator('[data-slot="notifikasi-badge"]').innerText();

    await tutupDrawer(page, vp);

    return teks;
}

/** The contrast ratio of an element's computed colour against its opaque backdrop. */
async function kontrasTerhadapLatar(
    locator: ReturnType<Page['locator']>,
): Promise<number> {
    return await locator.first().evaluate((elemen) => {
        const kanvas = document.createElement('canvas');
        kanvas.width = 1;
        kanvas.height = 1;

        const konteks = kanvas.getContext('2d');

        if (konteks === null) {
            throw new Error('Canvas 2D tidak tersedia.');
        }

        const rgba = (warna: string): [number, number, number, number] => {
            konteks.clearRect(0, 0, 1, 1);
            konteks.fillStyle = warna;
            konteks.fillRect(0, 0, 1, 1);

            const data = konteks.getImageData(0, 0, 1, 1).data;

            return [data[0] ?? 0, data[1] ?? 0, data[2] ?? 0, data[3] ?? 0];
        };

        const luminansi = ([r, g, b]: [number, number, number]): number => {
            const linier = (nilai: number): number => {
                const s = nilai / 255;

                return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * linier(r) + 0.7152 * linier(g) + 0.0722 * linier(b);
        };

        const latar = (mulai: Element): [number, number, number] => {
            let simpul: Element | null = mulai;

            while (simpul !== null) {
                const [r, g, b, a] = rgba(getComputedStyle(simpul).backgroundColor);

                if (a > 0) {
                    return [r, g, b];
                }

                simpul = simpul.parentElement;
            }

            return [255, 255, 255];
        };

        const [r, g, b] = rgba(getComputedStyle(elemen).color);
        const l1 = luminansi([r, g, b]);
        const l2 = luminansi(latar(elemen));
        const terang = Math.max(l1, l2);
        const gelap = Math.min(l1, l2);

        return (terang + 0.05) / (gelap + 0.05);
    });
}

function ukuran(view: Viewport): { width: number; height: number } {
    return { width: view.width, height: view.height };
}

for (const vp of VIEWPORTS) {
    test.describe(`F11 notifikasi ${vp.nama} ${vp.width}x${vp.height}`, () => {
        test.use({
            viewport: ukuran(vp),
            timezoneId: 'Asia/Jakarta',
        });

        test('f11-ac1-dua-ketukan', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, {
                baris: [BARIS_RESE_42],
                unread: 1,
            });

            await page.goto('/notifikasi');

            const tautan = page.locator('[data-slot="notifikasi-item-link"]').first();

            await expect(tautan).toBeVisible();
            await expect(tautan).toHaveAttribute('href', '/resep/42');

            await simpanGambar(page, vp, 'inbox');

            await tautan.click();

            await expect(page).toHaveURL(/\/resep\/42$/);
            await expect.poll(() => state.putIds).toEqual([BARIS_RESE_42.id]);

            // From the closed bell: one tap opens the dropdown, one tap follows the row.
            await page.goto('/notifikasi');
            await expect(page.locator('[data-slot="notifikasi-item"]').first()).toBeVisible();

            await bukaBell(page, vp);

            const barisDropdown = page
                .locator('[data-slot="notifikasi-dropdown-link"]')
                .first();

            await expect(barisDropdown).toBeVisible();

            await simpanGambar(page, vp, 'bell-dropdown');

            await barisDropdown.click();

            await expect(page).toHaveURL(/\/resep\/42$/);
        });

        test('f11-ac1-booking-peran', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, {
                baris: [{ ...BARIS_BOOKING, id: 301 }],
                unread: 1,
                userTipe: 'pasien',
            });

            await page.goto('/notifikasi');

            const tautan = page.locator('[data-slot="notifikasi-item-link"]').first();

            await expect(tautan).toHaveAttribute('href', '/booking');

            await tautan.click();

            await expect(page).toHaveURL(/\/booking$/);

            state.userTipe = 'dokter';
            state.baris = [{ ...BARIS_BOOKING, id: 302, dibaca_at: null }];
            state.unread = 1;

            await page.goto('/notifikasi');

            await expect(page.locator('[data-slot="notifikasi-item-link"]').first()).toHaveAttribute(
                'href',
                '/dokter/booking',
            );

            await page.locator('[data-slot="notifikasi-item-link"]').first().click();

            await expect(page).toHaveURL(/\/dokter\/booking$/);
        });

        test('f11-ac2-badge-akun', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, { baris: deret(12, 401), unread: 7 });

            await page.goto('/notifikasi');
            await expect(page.locator('[data-slot="notifikasi-item"]')).toHaveCount(10);

            expect(await teksBadge(page, vp)).toBe('7');

            await page.getByRole('button', { name: 'Berikutnya' }).click();
            await expect(page.locator('[data-slot="notifikasi-item"]')).toHaveCount(2);

            expect(await teksBadge(page, vp)).toBe('7');
        });

        test('f11-ac2-badge-99', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, { baris: deret(3, 501), unread: 150 });

            await page.goto('/notifikasi');
            await expect(page.locator('[data-slot="notifikasi-item"]').first()).toBeVisible();

            expect(await teksBadge(page, vp)).toBe('99+');
        });

        test('f11-ac3-deep-link-aman', async ({ page }) => {
            await masukPalsu(page);

            let dialogMuncul = false;
            const keluarJahat: string[] = [];

            page.on('dialog', async (dialog) => {
                dialogMuncul = true;
                await dialog.dismiss();
            });
            page.on('request', (request) => {
                if (request.url().includes('jahat.example')) {
                    keluarJahat.push(request.url());
                }
            });

            await pasangMock(page, {
                unread: 4,
                baris: [
                    BARIS_RESE_42,
                    { ...BARIS_SISTEM, id: 601, tautan: 'https://jahat.example' },
                    { ...BARIS_SISTEM, id: 602, tautan: 'javascript:alert(1)' },
                    {
                        ...BARIS_SISTEM,
                        id: 603,
                        tipe: 'booking',
                        tautan: '/api/v1/notifikasi/1',
                        isi: 'Pembaruan booking tanpa tautan yang dikenal.',
                    },
                ],
            });

            await page.goto('/notifikasi');
            await expect(page.locator('[data-slot="notifikasi-item"]')).toHaveCount(4);

            const tautan = page.locator('[data-slot="notifikasi-item"] a');

            await expect(tautan).toHaveCount(1);
            await expect(tautan).toHaveAttribute('href', '/resep/42');

            const teks = await page.locator('body').innerText();

            expect(teks).not.toContain('jahat.example');
            expect(teks).not.toContain('javascript:');
            expect(teks).not.toContain('/api/v1/notifikasi/1');

            await page.locator('[data-slot="notifikasi-item-isi"]').nth(1).click();
            await page.waitForTimeout(300);

            await expect(page).toHaveURL(/\/notifikasi$/);
            expect(dialogMuncul).toBe(false);
            expect(keluarJahat).toEqual([]);
        });

        test('f11-ac4-tandai-satu-idempoten', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, {
                baris: [{ ...BARIS_RESE_42, id: 701 }],
                unread: 1,
                delayPutMs: 400,
            });

            await page.goto('/notifikasi');

            const tombol = page.locator('[data-slot="notifikasi-baca"]').first();

            await expect(tombol).toBeVisible();

            await tombol.evaluate((el: HTMLButtonElement) => {
                el.click();
                el.click();
            });

            await expect(page.locator('[data-slot="notifikasi-dibaca"]').first()).toBeVisible({
                timeout: 10_000,
            });

            expect(state.putIds).toEqual([701]);
            await expect(page.locator('[data-slot="notifikasi-unread"]')).toHaveText('0');
        });

        test('f11-ac5-tandai-semua', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, { baris: deret(3, 801), unread: 3 });

            await page.goto('/notifikasi');

            const tombol = page.locator('[data-slot="notifikasi-baca-semua"]');

            await expect(page.locator('[data-slot="notifikasi-unread"]')).toHaveText('3');
            await expect(tombol).toBeEnabled();

            const getSebelum = state.counts['GET /api/v1/notifikasi'] ?? 0;

            await tombol.click();

            await expect(page.locator('[data-slot="notifikasi-unread"]')).toHaveText('0');
            await expect(tombol).toBeDisabled();
            expect(state.counts['GET /api/v1/notifikasi'] ?? 0).toBeGreaterThan(getSebelum);
            expect(state.putSemua).toBe(1);

            // `{ditandai: 0}` is the "already read" success: another device read them
            // between the click and the refetch. No error, and the badge comes from the
            // refetch, not from a local write.
            state.ditandai = 0;
            state.baris = deret(2, 901);
            state.unread = 2;

            await page.goto('/notifikasi');
            await expect(page.locator('[data-slot="notifikasi-unread"]')).toHaveText('2');

            await tombol.click();

            await expect(page.locator('[data-slot="notifikasi-unread"]')).toHaveText('0');
            await expect(page.locator('[data-slot="error-state"]')).toHaveCount(0);
            await expect(page.locator('[data-sonner-toast]')).toHaveCount(0);
            expect(state.putSemua).toBe(2);

            await simpanGambar(page, vp, 'tandai-semua');
        });

        test('f11-ac6-privasi-teks', async ({ page }) => {
            await masukPalsu(page);

            const isi = `Pembaruan resep: ${KATA_UJI}. Catatan: ${ALASAN_UJI}.`;

            await pasangMock(page, {
                unread: 4,
                baris: [
                    { ...BARIS_BOOKING, id: 1001, isi },
                    { ...BARIS_INVOICE, id: 1002, isi },
                    { ...BARIS_RESE_42, id: 1003, isi },
                    { ...BARIS_KONSULTASI, id: 1004, isi },
                ],
            });

            await page.goto('/notifikasi');
            await expect(page.locator('[data-slot="notifikasi-item"]')).toHaveCount(4);

            expect(await page.title()).toBe('Notifikasi | Sehatly');
            expect(page.url()).not.toContain(KATA_UJI);

            // The medical text IS visible in the owner's own inbox...
            expect(await page.locator('body').innerText()).toContain(KATA_UJI);

            // ...and the bell's accessible name stays clean.
            await bukaDrawer(page, vp);
            expect(
                await page.locator('[data-slot="notifikasi-bell"]').getAttribute('aria-label'),
            ).not.toContain(KATA_UJI);
            await tutupDrawer(page, vp);

            await page.locator('[data-slot="notifikasi-baca"]').first().click();
            await expect(page.locator('[data-slot="notifikasi-dibaca"]').first()).toBeVisible();

            expect(await page.title()).toBe('Notifikasi | Sehatly');
            expect(page.url()).not.toContain(KATA_UJI);

            const toast = (await page.locator('[data-sonner-toast]').allTextContents()).join(' ');

            expect(toast).not.toContain(KATA_UJI);
            expect(toast).not.toContain(ALASAN_UJI);

            // A valid deep link lands on an id-only URL.
            await page.locator('[data-slot="notifikasi-item-link"][href="/resep/42"]').click();

            await expect(page).toHaveURL(/\/resep\/42$/);
            expect(page.url()).not.toContain(KATA_UJI);
            expect(page.url()).not.toContain(ALASAN_UJI);
        });

        test('f11-ac7-loading', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, { baris: [BARIS_RESE_42], unread: 1, delayMs: 2000 });

            await page.goto('/notifikasi');

            await expect(page.locator('[data-slot="notifikasi-loading"]')).toBeVisible();

            await bukaBell(page, vp);
            await expect(page.locator('[data-slot="notifikasi-dropdown-loading"]')).toBeVisible();

            await expect(page.locator('[data-slot="notifikasi-item"]').first()).toBeVisible({
                timeout: 10_000,
            });
        });

        test('f11-ac7-kosong-akun', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, { baris: [], unread: 0 });

            await page.goto('/notifikasi');

            await expect(page.getByText('Belum ada notifikasi.')).toBeVisible();
            await expect(
                page.getByText(/Notifikasi muncul saat janji temu dibuat/),
            ).toBeVisible();

            await simpanGambar(page, vp, 'kosong');
        });

        test('f11-ac7-kosong-filter', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, {
                baris: deret(2, 1101),
                unread: 2,
                filterTrueKosong: true,
            });

            await page.goto('/notifikasi');
            await expect(page.locator('[data-slot="notifikasi-item"]')).toHaveCount(2);

            await page.locator('[data-slot="notifikasi-filter-unread"]').click();

            await expect(page.getByText('Tidak ada notifikasi baru.')).toBeVisible();

            const keluar = page.locator('[data-slot="notifikasi-tampilkan-semua"]');

            await expect(keluar).toBeVisible();

            await simpanGambar(page, vp, 'kosong-filter');

            state.filterTrueKosong = false;

            await keluar.click();

            await expect(page.locator('[data-slot="notifikasi-item"]')).toHaveCount(2);
        });

        test('f11-ac7-error-500', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, {
                baris: [BARIS_RESE_42],
                unread: 1,
                statusList: 500,
            });

            await page.goto('/notifikasi');

            const galat = page.locator('[data-slot="error-state"]');

            await expect(galat).toBeVisible();
            await expect(galat).toContainText('Gagal memuat notifikasi.');

            await simpanGambar(page, vp, 'galat');

            state.statusList = 200;

            const sebelum = state.counts['GET /api/v1/notifikasi'] ?? 0;

            await galat.getByRole('button', { name: 'Coba lagi' }).click();

            await expect(page.locator('[data-slot="notifikasi-item"]').first()).toBeVisible();

            expect(state.counts['GET /api/v1/notifikasi'] ?? 0).toBe(sebelum + 1);
        });

        test('f11-ac7-offline', async ({ page, context }) => {
            await masukPalsu(page);

            await pasangMock(page, { baris: [BARIS_RESE_42], unread: 1 });

            await page.goto('/notifikasi');
            await expect(page.locator('[data-slot="notifikasi-item"]').first()).toBeVisible();

            await context.setOffline(true);

            const banner = page.getByTestId('offline-banner');

            await expect(banner).toBeVisible();
            await expect(banner).toContainText(
                'Anda sedang offline. Notifikasi yang tampil adalah data terakhir yang tersimpan.',
            );

            await context.setOffline(false);

            await expect(banner).toHaveCount(0);
        });

        test('f11-ac8-a11y', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, {
                unread: 4,
                baris: [BARIS_BOOKING, BARIS_INVOICE, BARIS_RESE_42, BARIS_SISTEM],
            });

            await page.goto('/notifikasi');
            await expect(page.locator('[data-slot="notifikasi-item"]')).toHaveCount(4);

            await expectNoA11yViolations(page);

            const status = page.locator('[data-slot="notifikasi-status"]');

            await expect(status).toHaveAttribute('role', 'status');
            await expect(status).toContainText('belum dibaca');

            await bukaDrawer(page, vp);
            await expect(page.locator('[data-slot="notifikasi-bell"]')).toHaveAttribute(
                'aria-label',
                /belum dibaca/,
            );
            await tutupDrawer(page, vp);

            await page.locator('[data-slot="notifikasi-filter-unread"]').click();
            await expectNoA11yViolations(page);

            for (const sasaran of [
                '[data-slot="notifikasi-item"] a',
                '[data-slot="notifikasi-item"] button',
                '[data-slot="notifikasi-filter"]',
                '[data-slot="notifikasi-filter-unread"]',
                '[data-slot="notifikasi-baca-semua"]',
            ]) {
                for (const elemen of await page.locator(sasaran).all()) {
                    const kotak = await elemen.boundingBox();

                    expect(kotak, sasaran).not.toBeNull();
                    expect(
                        (kotak as { height: number }).height,
                        sasaran,
                    ).toBeGreaterThanOrEqual(44);
                }
            }

            for (const sasaran of [
                '[data-slot="notifikasi-judul"]',
                '[data-slot="notifikasi-isi"]',
                '[data-slot="notifikasi-waktu"]',
            ]) {
                expect(
                    await kontrasTerhadapLatar(page.locator(sasaran)),
                    sasaran,
                ).toBeGreaterThanOrEqual(4.5);
            }
        });

        test('f11-ac9-offline-tulis', async ({ page, context }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, {
                baris: [{ ...BARIS_RESE_42, id: 1201 }],
                unread: 1,
            });

            await page.goto('/notifikasi');

            const tombol = page.locator('[data-slot="notifikasi-baca"]').first();

            await expect(tombol).toBeVisible();

            await context.setOffline(true);

            const banner = page.getByTestId('offline-banner');

            await expect(banner).toBeVisible();
            await expect(page.locator('[data-slot="notifikasi-offline-alasan"]')).toBeVisible();
            await expect(tombol).toHaveAttribute('aria-disabled', 'true');

            await simpanGambar(page, vp, 'offline-tulis');

            const putSebelum = state.putIds.length;

            // `force` because the control is aria-disabled, which Playwright's
            // actionability refuses. The point is that the attempt reaches the handler
            // and the handler still withholds the request.
            await tombol.click({ force: true });
            await page.waitForTimeout(400);

            expect(state.putIds.length).toBe(putSebelum);

            const getSebelum = state.counts['GET /api/v1/notifikasi'] ?? 0;

            await context.setOffline(false);

            await expect(banner).toHaveCount(0);
            await expect
                .poll(() => state.counts['GET /api/v1/notifikasi'] ?? 0)
                .toBeGreaterThan(getSebelum);
        });

        test('f11-ac10-403', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, { statusList: 403 });

            await page.goto('/notifikasi');

            const terlarang = page.locator('[data-slot="forbidden-state"]');

            await expect(terlarang).toBeVisible();
            await expect(terlarang).toContainText('This action is unauthorized.');
            await expect(page.locator('[data-slot="notifikasi-item"]')).toHaveCount(0);

            await bukaDrawer(page, vp);
            await expect(page.locator('[data-slot="notifikasi-badge"]')).toHaveCount(0);
            await tutupDrawer(page, vp);

            await simpanGambar(page, vp, 'terlarang');

            const total = state.counts['GET /api/v1/notifikasi'] ?? 0;

            await page.waitForTimeout(1200);

            expect(state.counts['GET /api/v1/notifikasi'] ?? 0).toBe(total);
        });
    });
}
