import { expect, test, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F11's newly unblocked UI, mocked end to end at 390x844 and 1280x900.
 *
 * The backend commit `50ad14e` added `GET|PUT /profil/notifikasi` and
 * `GET|POST /pengingat`, `PUT|DELETE /pengingat/{id}`; this spec covers the two screens
 * those routes unblock, their nav entries and the reminder-notification deep link. Every
 * request is intercepted and every fixture is invented, so no real health data is
 * involved.
 *
 * The preferences matrix renders the four PRODUCED types only. The spec asserts the
 * absence of `lab`/`promo`/`sistem` rows explicitly, because "do not render an invented
 * control" is an acceptance criterion and not something a screenshot alone can prove.
 */

const VIEWPORTS = [
    { nama: 'mobile', width: 390, height: 844 },
    { nama: 'desktop', width: 1280, height: 900 },
] as const;

type Viewport = (typeof VIEWPORTS)[number];

const KATA_MEDIS = 'Metformin 500 mg';
const FCM_RAHASIA = 'FCM-TOKEN-RAHASIA-JANGAN-TAMPIL';
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

type TipePreferensi = 'booking' | 'pembayaran' | 'resep' | 'chat';

type PreferensiMock = {
    jam_tenang_aktif: boolean;
    jam_tenang_mode: 'setiap_hari' | 'hari_kerja' | 'kustom';
    jam_tenang_mulai: string;
    jam_tenang_selesai: string;
    zona_waktu: string;
    push: Record<TipePreferensi, boolean>;
};

const PREFERENSI_AWAL: PreferensiMock = {
    jam_tenang_aktif: false,
    jam_tenang_mode: 'setiap_hari',
    jam_tenang_mulai: '21:00',
    jam_tenang_selesai: '06:00',
    zona_waktu: 'Asia/Jakarta',
    push: { booking: true, pembayaran: true, resep: true, chat: true },
};

type DeviceMock = {
    device_id: string;
    platform: string;
    fcm_token: string | null;
    app_versi: string | null;
    aktif: boolean;
    last_active_at: string | null;
    dibuat_at: string;
};

const DEVICE_ANDROID: DeviceMock = {
    device_id: 'uji-android-01',
    platform: 'android',
    fcm_token: FCM_RAHASIA,
    app_versi: '1.2.0',
    aktif: true,
    last_active_at: '2026-10-02T02:12:00.000000Z',
    dibuat_at: '2026-09-01T00:00:00.000000Z',
};

type JenisPengingat = 'obat' | 'janji_temu';
type StatusPengingat = 'aktif' | 'nonaktif' | 'selesai';

type PengingatMock = {
    id: number;
    jenis: JenisPengingat;
    judul: string;
    keterangan: string | null;
    obat_id: number | null;
    booking_id: number | null;
    dosis: string | null;
    jumlah_per_hari: number | null;
    tanggal_mulai: string | null;
    lama_hari: number | null;
    waktu: string[];
    zona_waktu: string;
    zona_label: string | null;
    status: StatusPengingat;
    dibuat_at: string | null;
    diubah_at: string | null;
};

const ZONA_LABEL: Record<string, string> = {
    'Asia/Jakarta': 'WIB',
    'Asia/Makassar': 'WITA',
    'Asia/Jayapura': 'WIT',
};

function pengingat(ubah: Partial<PengingatMock>): PengingatMock {
    return {
        id: 1,
        jenis: 'obat',
        judul: KATA_MEDIS,
        keterangan: null,
        obat_id: null,
        booking_id: null,
        dosis: '1 tablet',
        jumlah_per_hari: 3,
        tanggal_mulai: '2026-01-01',
        lama_hari: null,
        waktu: ['08:00', '13:00', '20:00'],
        zona_waktu: 'Asia/Jakarta',
        zona_label: 'WIB',
        status: 'aktif',
        dibuat_at: '2026-10-01T00:00:00.000000Z',
        diubah_at: null,
        ...ubah,
    };
}

type BarisNotif = {
    id: number;
    judul: string;
    isi: string;
    tipe: 'booking' | 'pembayaran' | 'resep' | 'chat' | 'lab' | 'promo' | 'sistem';
    tautan: string | null;
    payload: unknown;
    dibaca_at: string | null;
    dibuat_at: string;
};

const BARIS_PENGINGAT_OBAT: BarisNotif = {
    id: 9001,
    judul: 'Pengingat minum obat.',
    isi: 'Waktunya minum obat sesuai jadwal Anda.',
    tipe: 'sistem',
    tautan: null,
    payload: { pengingat_id: 7 },
    dibaca_at: null,
    dibuat_at: '2026-10-03T07:00:00.000000Z',
};

const BARIS_PENGINGAT_JANJI: BarisNotif = {
    id: 9002,
    judul: 'Pengingat janji temu.',
    isi: 'Anda punya janji temu yang sudah dekat.',
    tipe: 'booking',
    tautan: '/api/v1/booking/77',
    payload: { pengingat_id: 8, booking_id: 77 },
    dibaca_at: null,
    dibuat_at: '2026-10-03T06:00:00.000000Z',
};

type OpsiMock = {
    preferensi?: Partial<PreferensiMock>;
    preferensiStatus?: number;
    /** When set, the PUT answers 422 with these field errors. */
    preferensiValidasi?: Record<string, string[]> | null;
    devices?: DeviceMock[];
    devicesStatus?: number;
    pengingat?: PengingatMock[];
    pengingatStatus?: number;
    delayMs?: number;
    notifikasi?: BarisNotif[];
    unread?: number;
};

type State = {
    preferensi: PreferensiMock;
    preferensiStatus: number;
    preferensiValidasi: Record<string, string[]> | null;
    preferensiBodies: unknown[];
    devices: DeviceMock[];
    devicesStatus: number;
    pengingat: PengingatMock[];
    pengingatStatus: number;
    posts: PengingatMock[];
    puts: { id: number; body: Record<string, unknown> }[];
    deletes: number[];
    notifikasi: BarisNotif[];
    unread: number;
    delayMs: number;
    counts: Record<string, number>;
};

async function balasJson(route: Route, status: number, body: unknown): Promise<void> {
    await route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

function galat(status: number, message: string, errors: Record<string, string[]> = {}) {
    return { success: false, message, errors };
}

function meta(total: number, perPage: number, halaman = 1, lastPage = 1) {
    return {
        current_page: halaman,
        last_page: lastPage,
        per_page: perPage,
        total,
        from: null,
        to: null,
    };
}

/**
 * Intercept every `/api/v1` call both screens make. The catch-all at the bottom is
 * load-bearing: an unmocked call would reach the Vite proxy and send the transport down
 * its session-expiry path, redirecting to `/login` for a reason unrelated to the
 * assertion.
 */
async function pasangMock(page: Page, opsi: OpsiMock = {}): Promise<State> {
    const state: State = {
        preferensi: {
            ...PREFERENSI_AWAL,
            ...opsi.preferensi,
            push: { ...PREFERENSI_AWAL.push, ...(opsi.preferensi?.push ?? {}) },
        },
        preferensiStatus: opsi.preferensiStatus ?? 200,
        preferensiValidasi: opsi.preferensiValidasi ?? null,
        preferensiBodies: [],
        devices: opsi.devices ?? [DEVICE_ANDROID],
        devicesStatus: opsi.devicesStatus ?? 200,
        pengingat: opsi.pengingat === undefined ? [] : [...opsi.pengingat],
        pengingatStatus: opsi.pengingatStatus ?? 200,
        posts: [],
        puts: [],
        deletes: [],
        notifikasi: opsi.notifikasi ?? [],
        unread: opsi.unread ?? 0,
        delayMs: opsi.delayMs ?? 0,
        counts: {},
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
                data: { user: USER_PASIEN },
            });
        }

        if (path === '/api/v1/profil/notifikasi' && method === 'GET') {
            if (state.preferensiStatus !== 200) {
                return balasJson(
                    route,
                    state.preferensiStatus,
                    galat(
                        state.preferensiStatus,
                        state.preferensiStatus === 403
                            ? 'This action is unauthorized.'
                            : 'Terjadi kesalahan pada server.',
                    ),
                );
            }

            return balasJson(route, 200, {
                success: true,
                message: 'Preferensi notifikasi berhasil dimuat.',
                data: { preferensi: state.preferensi },
            });
        }

        if (path === '/api/v1/profil/notifikasi' && method === 'PUT') {
            const body = (await request.postDataJSON()) as Partial<PreferensiMock>;

            state.preferensiBodies.push(body);

            if (state.preferensiValidasi !== null) {
                return balasJson(
                    route,
                    422,
                    galat(422, 'Data yang dikirim tidak valid.', state.preferensiValidasi),
                );
            }

            state.preferensi = {
                ...state.preferensi,
                ...body,
                push: { ...state.preferensi.push, ...(body.push ?? {}) },
            };

            return balasJson(route, 200, {
                success: true,
                message: 'Preferensi notifikasi berhasil disimpan.',
                data: { preferensi: state.preferensi },
            });
        }

        if (path === '/api/v1/auth/devices' && method === 'GET') {
            if (state.devicesStatus !== 200) {
                return balasJson(
                    route,
                    state.devicesStatus,
                    galat(state.devicesStatus, 'Terjadi kesalahan pada server.'),
                );
            }

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar perangkat berhasil dimuat.',
                data: { devices: state.devices },
            });
        }

        if (path === '/api/v1/pengingat' && method === 'GET') {
            if (state.pengingatStatus !== 200) {
                return balasJson(
                    route,
                    state.pengingatStatus,
                    galat(
                        state.pengingatStatus,
                        state.pengingatStatus === 403
                            ? 'This action is unauthorized.'
                            : 'Terjadi kesalahan pada server.',
                    ),
                );
            }

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar pengingat berhasil dimuat.',
                data: { pengingat: state.pengingat },
                meta: meta(state.pengingat.length, 50),
            });
        }

        if (path === '/api/v1/pengingat' && method === 'POST') {
            const body = (await request.postDataJSON()) as Partial<PengingatMock>;
            const baru: PengingatMock = {
                ...pengingat({ id: 0 }),
                ...body,
                id: 500 + state.posts.length + 1,
                status: 'aktif',
                zona_label: ZONA_LABEL[String(body.zona_waktu)] ?? null,
                dibuat_at: '2026-10-03T08:00:00.000000Z',
            };

            state.posts.push(baru);
            state.pengingat = [baru, ...state.pengingat];

            return balasJson(route, 201, {
                success: true,
                message: 'Pengingat berhasil dibuat.',
                data: { pengingat: baru },
            });
        }

        const ubahPengingat = /^\/api\/v1\/pengingat\/(\d+)$/.exec(path);

        if (ubahPengingat !== null && method === 'PUT') {
            const id = Number(ubahPengingat[1]);
            const body = (await request.postDataJSON()) as Record<string, unknown>;

            state.puts.push({ id, body });
            state.pengingat = state.pengingat.map((row) =>
                row.id === id ? { ...row, ...body, zona_label: ZONA_LABEL[String(body.zona_waktu ?? row.zona_waktu)] ?? row.zona_label } : row,
            );

            const hasil = state.pengingat.find((row) => row.id === id);

            return balasJson(route, 200, {
                success: true,
                message: body.status === undefined ? 'Pengingat berhasil diperbarui.' : 'Pengingat berhasil diperbarui.',
                data: { pengingat: hasil },
            });
        }

        if (ubahPengingat !== null && method === 'DELETE') {
            const id = Number(ubahPengingat[1]);

            state.deletes.push(id);
            state.pengingat = state.pengingat.filter((row) => row.id !== id);

            return balasJson(route, 200, {
                success: true,
                message: 'Pengingat berhasil dihapus.',
                data: { dihapus: true },
            });
        }

        if (path === '/api/v1/notifikasi' && method === 'GET') {
            const perPage = Number(url.searchParams.get('per_page') ?? '10');

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar notifikasi berhasil dimuat.',
                data: { notifikasi: state.notifikasi.slice(0, perPage) },
                meta: meta(state.notifikasi.length, perPage),
            });
        }

        const bacaSatu = /^\/api\/v1\/notifikasi\/(\d+)\/baca$/.exec(path);

        if (bacaSatu !== null && method === 'PUT') {
            return balasJson(route, 200, {
                success: true,
                message: 'Notifikasi ditandai dibaca.',
                data: { notifikasi: null },
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
        sessionStorage.setItem('sehatly.access_token', 'token-uji-f11-ui');
        sessionStorage.setItem('sehatly.refresh_token', 'refresh-uji-f11-ui');
    });
}

async function simpanGambar(page: Page, vp: Viewport, keadaan: string): Promise<void> {
    await page.screenshot({
        path: `ux/refs/f11/f11-ui-${keadaan}-${vp.nama}.png`,
        fullPage: true,
        animations: 'disabled',
    });
}

async function bukaDrawer(page: Page, vp: Viewport): Promise<void> {
    if (vp.width >= 768) {
        return;
    }

    await page.getByRole('button', { name: 'Buka atau tutup menu navigasi' }).click();
    await expect(page.locator('[data-slot="sidebar"][data-mobile="true"]')).toBeVisible();
}

async function tutupDrawer(page: Page, vp: Viewport): Promise<void> {
    if (vp.width >= 768) {
        return;
    }

    await page.keyboard.press('Escape');
    await expect(page.locator('[data-slot="sidebar"][data-mobile="true"]')).toHaveCount(0);
}

/** The contrast ratio of an element's computed colour against its opaque backdrop. */
async function kontrasTerhadapLatar(locator: ReturnType<Page['locator']>): Promise<number> {
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
    test.describe(`F11 UI ${vp.nama} ${vp.width}x${vp.height}`, () => {
        test.use({
            viewport: ukuran(vp),
            timezoneId: 'Asia/Jakarta',
        });

        test('f11-ui-acr1-preferensi-matriks', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page);

            await page.goto('/profil/notifikasi');

            await expect(page.locator('[data-slot="preferensi-baris"]')).toHaveCount(4);

            for (const tipe of ['booking', 'pembayaran', 'resep', 'chat']) {
                await expect(
                    page.locator(`[data-slot="preferensi-baris"][data-tipe="${tipe}"]`),
                ).toBeVisible();

                const inapp = page.locator(
                    `[data-slot="preferensi-inapp"][data-tipe="${tipe}"]`,
                );

                await expect(inapp).toBeDisabled();
                await expect(inapp).toHaveAttribute('aria-checked', 'true');

                await expect(
                    page.locator(`[data-slot="preferensi-push"][data-tipe="${tipe}"]`),
                ).toBeEnabled();
            }

            // Inactive types are not invented controls.
            for (const tipe of ['lab', 'promo', 'sistem']) {
                await expect(
                    page.locator(`[data-slot="preferensi-baris"][data-tipe="${tipe}"]`),
                ).toHaveCount(0);
            }

            await expect(
                page.getByText(
                    'Dalam aplikasi: selalu tersimpan di kotak masuk; tidak dapat dimatikan.',
                ),
            ).toBeVisible();

            await expect(
                page.getByText(
                    'Saat jam tenang, push ditahan. Notifikasi tetap masuk ke kotak masuk aplikasi.',
                ),
            ).toBeVisible();

            await expect(
                page.getByText(
                    'Setelah jam tenang berakhir, pembaruan dikirim sebagai satu ringkasan.',
                ),
            ).toBeVisible();

            await expect(page.locator('[data-slot="jam-tenang-aktif"]')).toHaveAttribute(
                'aria-checked',
                'false',
            );

            // Flip one push channel and turn quiet hours on with a workday window.
            await page.locator('[data-slot="preferensi-push"][data-tipe="chat"]').click();
            await expect(
                page.locator('[data-slot="preferensi-push"][data-tipe="chat"]'),
            ).toHaveAttribute('aria-checked', 'false');

            await page.locator('[data-slot="jam-tenang-aktif"]').click();
            await page
                .locator('[data-slot="jam-tenang-mode"]', { hasText: 'Hari kerja' })
                .click();

            await page
                .locator('[data-slot="jam-tenang-mulai"] [data-slot="select-trigger"]')
                .click();
            await page.getByRole('option', { name: '22.00' }).click();

            await page
                .locator('[data-slot="jam-tenang-selesai"] [data-slot="select-trigger"]')
                .click();
            await page.getByRole('option', { name: '05.30' }).click();

            await simpanGambar(page, vp, 'preferensi');

            await page.locator('[data-slot="preferensi-simpan"]').click();

            await expect(page.locator('[data-slot="preferensi-sukses"]')).toBeVisible();

            expect(state.preferensiBodies).toHaveLength(1);
            expect(state.preferensiBodies[0]).toMatchObject({
                jam_tenang_aktif: true,
                jam_tenang_mode: 'hari_kerja',
                jam_tenang_mulai: '22:00',
                jam_tenang_selesai: '05:30',
                push: { booking: true, pembayaran: true, resep: true, chat: false },
            });

            // Reload: the saved values persist because the server answer is the state.
            await page.reload();

            await expect(
                page.locator('[data-slot="preferensi-push"][data-tipe="chat"]'),
            ).toHaveAttribute('aria-checked', 'false');
            await expect(page.locator('[data-slot="jam-tenang-aktif"]')).toHaveAttribute(
                'aria-checked',
                'true',
            );

            await expect(
                page.locator('[data-slot="jam-tenang-mulai"] [data-slot="select-trigger"]'),
            ).toContainText('22.00');
            await expect(
                page
                    .locator('[data-slot="jam-tenang-mode"]', { hasText: 'Hari kerja' })
                    .first(),
            ).toHaveAttribute('data-state', 'on');
        });

        test('f11-ui-acr1-validasi-inline', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, {
                preferensiValidasi: {
                    jam_tenang_mulai: ['Jam mulai harus berformat HH:MM.'],
                    'push.chat': ['Nilai push harus benar atau salah.'],
                },
            });

            await page.goto('/profil/notifikasi');

            await page.locator('[data-slot="preferensi-simpan"]').click();

            await expect(
                page.getByText('Jam mulai harus berformat HH:MM.'),
            ).toBeVisible();
            await expect(
                page.getByText('Nilai push harus benar atau salah.'),
            ).toBeVisible();

            // A 422 is inline, never a toast.
            await expect(page.locator('[data-sonner-toast]')).toHaveCount(0);
            await expect(page.locator('[data-slot="preferensi-simpan"]')).toBeEnabled();
        });

        test('f11-ui-acr1-perangkat', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page);

            await page.goto('/profil/notifikasi');

            const perangkat = page.locator('[data-slot="preferensi-perangkat-item"]');

            await expect(perangkat).toHaveCount(1);
            await expect(perangkat).toContainText('Android');
            await expect(perangkat).toContainText('1.2.0');
            await expect(perangkat).toContainText('Terakhir aktif');

            const teks = await page.locator('body').innerText();

            expect(teks).not.toContain(FCM_RAHASIA);
            expect(teks).not.toContain('fcm_token');
            expect(teks).not.toContain('uji-android-01');
        });

        test('f11-ui-acr1-tautan-pengingat', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page);

            await page.goto('/profil/notifikasi');

            const tautan = page.locator('[data-slot="preferensi-pengingat-link"]');

            await expect(tautan).toHaveAttribute('href', '/pengingat');

            await tautan.click();

            await expect(page).toHaveURL(/\/pengingat$/);
            await expect(page.getByText('Belum ada pengingat.')).toBeVisible();
        });

        test('f11-ui-acr1-error', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, { preferensiStatus: 500 });

            await page.goto('/profil/notifikasi');

            const galat = page.locator('[data-slot="error-state"]');

            await expect(galat).toBeVisible();
            await expect(galat).toContainText('Gagal memuat preferensi notifikasi.');

            state.preferensiStatus = 200;

            const sebelum = state.counts['GET /api/v1/profil/notifikasi'] ?? 0;

            await galat.getByRole('button', { name: 'Coba lagi' }).click();

            await expect(page.locator('[data-slot="preferensi-form"]')).toBeVisible();

            expect(state.counts['GET /api/v1/profil/notifikasi'] ?? 0).toBe(sebelum + 1);
        });

        test('f11-ui-acr1-forbidden', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, { preferensiStatus: 403 });

            await page.goto('/profil/notifikasi');

            const terlarang = page.locator('[data-slot="forbidden-state"]');

            await expect(terlarang).toBeVisible();
            await expect(terlarang).toContainText('This action is unauthorized.');
            await expect(page.locator('[data-slot="preferensi-form"]')).toHaveCount(0);
        });

        test('f11-ui-acr1-offline', async ({ page, context }) => {
            await masukPalsu(page);

            const state = await pasangMock(page);

            await page.goto('/profil/notifikasi');
            await expect(page.locator('[data-slot="preferensi-form"]')).toBeVisible();

            await context.setOffline(true);

            const banner = page.getByTestId('offline-banner');

            await expect(banner).toBeVisible();
            await expect(banner).toContainText(
                'Anda sedang offline. Preferensi yang tampil adalah data terakhir yang tersimpan.',
            );
            await expect(page.locator('[data-slot="preferensi-offline-alasan"]')).toBeVisible();
            await expect(page.locator('[data-slot="preferensi-simpan"]')).toHaveAttribute(
                'aria-disabled',
                'true',
            );

            await page.locator('[data-slot="preferensi-simpan"]').click({ force: true });
            await page.waitForTimeout(300);

            expect(state.preferensiBodies).toHaveLength(0);

            await context.setOffline(false);

            await expect(banner).toHaveCount(0);
        });

        test('f11-ui-acr1-a11y', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page);

            await page.goto('/profil/notifikasi');
            await expect(page.locator('[data-slot="preferensi-form"]')).toBeVisible();

            await expectNoA11yViolations(page);

            await page.locator('[data-slot="jam-tenang-aktif"]').click();
            await expectNoA11yViolations(page);

            for (const sasaran of [
                '[data-slot="preferensi-simpan"]',
                '[data-slot="preferensi-pengingat-link"]',
            ]) {
                for (const elemen of await page.locator(sasaran).all()) {
                    const kotak = await elemen.boundingBox();

                    expect(kotak, sasaran).not.toBeNull();
                    expect((kotak as { height: number }).height, sasaran).toBeGreaterThanOrEqual(
                        44,
                    );
                }
            }

            for (const sasaran of [
                '[data-slot="preferensi-baris"] th',
                '[data-slot="preferensi-perangkat-item"] span',
            ]) {
                expect(
                    await kontrasTerhadapLatar(page.locator(sasaran)),
                    sasaran,
                ).toBeGreaterThanOrEqual(4.5);
            }
        });

        test('f11-ui-acr3-daftar', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, {
                pengingat: [
                    pengingat({ id: 11 }),
                    pengingat({
                        id: 12,
                        jenis: 'obat',
                        judul: 'Amlodipine 10 mg',
                        dosis: '1 tablet',
                        jumlah_per_hari: 1,
                        waktu: ['07:00'],
                        status: 'nonaktif',
                    }),
                    pengingat({
                        id: 13,
                        jenis: 'janji_temu',
                        judul: 'Kontrol ulang dokter',
                        dosis: null,
                        jumlah_per_hari: null,
                        waktu: ['19:00'],
                        tanggal_mulai: '2099-01-01',
                        zona_waktu: 'Asia/Makassar',
                        zona_label: 'WITA',
                        booking_id: 77,
                    }),
                ],
            });

            await page.goto('/pengingat');

            await expect(page.locator('[data-slot="pengingat-item"]')).toHaveCount(3);

            const grup = page.locator('[data-slot="pengingat-grup"]');

            await expect(grup).toHaveCount(2);
            await expect(grup.first().locator('[data-slot="pengingat-hari"]')).toHaveText(
                'Hari ini',
            );
            await expect(grup.nth(1).locator('[data-slot="pengingat-hari"]')).toHaveText(
                '1 Januari 2099',
            );

            const barisMetformin = page.locator('[data-slot="pengingat-item"]', {
                hasText: KATA_MEDIS,
            });

            await expect(barisMetformin).toContainText(KATA_MEDIS);
            await expect(
                barisMetformin.locator('[data-slot="pengingat-dosis"]'),
            ).toContainText('1 tablet');
            await expect(barisMetformin.locator('[data-slot="pengingat-waktu"]')).toHaveText(
                '08.00, 13.00, 20.00 WIB',
            );

            const barisAmlodipine = page.locator('[data-slot="pengingat-item"]', {
                hasText: 'Amlodipine 10 mg',
            });

            await expect(
                barisAmlodipine.locator('[data-slot="pengingat-waktu"]'),
            ).toHaveText('07.00 WIB');

            const barisJanji = page.locator(
                '[data-slot="pengingat-item"][data-jenis="janji_temu"]',
            );

            await expect(barisJanji).toContainText('Janji temu');
            await expect(barisJanji.locator('[data-slot="pengingat-waktu"]')).toHaveText(
                '19.00 WITA',
            );
            await expect(barisJanji.locator('[data-slot="pengingat-status"]')).toContainText(
                'Aktif',
            );

            await expect(
                page.locator(
                    '[data-slot="pengingat-item"][data-status="nonaktif"] [data-slot="pengingat-status"]',
                ),
            ).toContainText('Nonaktif');

            await simpanGambar(page, vp, 'pengingat');
        });

        test('f11-ui-acr3-buat', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page);

            await page.goto('/pengingat');

            await expect(page.getByText('Belum ada pengingat.')).toBeVisible();

            await page.locator('[data-slot="pengingat-buat"]').click();

            const dialog = page.locator('[data-slot="pengingat-dialog"]');

            await expect(dialog).toBeVisible();

            await page.getByLabel('Nama obat').fill(KATA_MEDIS);
            await page.getByLabel('Dosis').fill('1 tablet');
            await page.getByLabel('Berapa kali sehari').fill('2');
            await page.getByLabel('Tanggal mulai').fill('2026-10-10');
            await page.getByLabel('Lama konsumsi (hari)').fill('14');

            const waktu = page.locator('[data-slot="pengingat-waktu-input"]');

            await expect(waktu).toHaveCount(1);
            await waktu.first().fill('08:00');

            await page.locator('[data-slot="pengingat-waktu-tambah"]').click();
            await expect(waktu).toHaveCount(2);
            await waktu.nth(1).fill('20:00');

            await dialog.locator('[data-slot="select-trigger"]').nth(1).click();
            await page.getByRole('option', { name: /WITA/ }).click();

            await page.locator('[data-slot="pengingat-simpan"]').click();

            await expect(dialog).toHaveCount(0);
            await expect(page.locator('[data-slot="pengingat-sukses"]')).toContainText(
                'Pengingat berhasil dibuat.',
            );

            expect(state.posts).toHaveLength(1);
            expect(state.posts[0]).toMatchObject({
                jenis: 'obat',
                judul: KATA_MEDIS,
                dosis: '1 tablet',
                jumlah_per_hari: 2,
                tanggal_mulai: '2026-10-10',
                lama_hari: 14,
                waktu: ['08:00', '20:00'],
                zona_waktu: 'Asia/Makassar',
            });

            await expect(
                page.locator('[data-slot="pengingat-item"]'),
            ).toContainText(KATA_MEDIS);
        });

        test('f11-ui-acr3-ubah', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, { pengingat: [pengingat({ id: 21 })] });

            await page.goto('/pengingat');

            await page.locator('[data-slot="pengingat-ubah"]').click();

            const dialog = page.locator('[data-slot="pengingat-dialog"]');

            await expect(dialog).toBeVisible();

            await page.getByLabel('Nama obat').fill('Metformin 850 mg');
            await page.getByLabel('Berapa kali sehari').fill('2');

            await page.locator('[data-slot="pengingat-simpan"]').click();

            await expect(dialog).toHaveCount(0);
            await expect(page.locator('[data-slot="pengingat-sukses"]')).toContainText(
                'Pengingat berhasil diperbarui.',
            );

            expect(state.puts).toHaveLength(1);
            expect(state.puts[0]?.id).toBe(21);
            expect(state.puts[0]?.body).toMatchObject({
                judul: 'Metformin 850 mg',
                jumlah_per_hari: 2,
            });
            expect(state.puts[0]?.body.status).toBeUndefined();

            await expect(page.locator('[data-slot="pengingat-judul"]').first()).toHaveText(
                'Metformin 850 mg',
            );
        });

        test('f11-ui-acr3-toggle-status', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, { pengingat: [pengingat({ id: 31 })] });

            await page.goto('/pengingat');

            const status = page.locator('[data-slot="pengingat-status"]').first();

            await expect(status).toContainText('Aktif');

            await page.locator('[data-slot="pengingat-toggle-status"]').click();

            await expect(status).toContainText('Nonaktif');

            expect(state.puts).toHaveLength(1);
            expect(state.puts[0]).toMatchObject({ id: 31, body: { status: 'nonaktif' } });

            await page.locator('[data-slot="pengingat-toggle-status"]').click();

            await expect(status).toContainText('Aktif');

            expect(state.puts).toHaveLength(2);
            expect(state.puts[1]).toMatchObject({ id: 31, body: { status: 'aktif' } });
        });

        test('f11-ui-acr3-hapus', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, { pengingat: [pengingat({ id: 41 })] });

            await page.goto('/pengingat');

            await page.locator('[data-slot="pengingat-hapus"]').click();

            const konfirmasi = page.locator('[data-slot="pengingat-hapus-dialog"]');

            await expect(konfirmasi).toBeVisible();
            await expect(konfirmasi).toContainText('Hapus pengingat?');

            await page.locator('[data-slot="pengingat-hapus-konfirmasi"]').click();

            await expect(konfirmasi).toHaveCount(0);
            await expect(page.locator('[data-slot="pengingat-sukses"]')).toContainText(
                'Pengingat berhasil dihapus.',
            );
            await expect(page.getByText('Belum ada pengingat.')).toBeVisible();

            expect(state.deletes).toEqual([41]);
        });

        test('f11-ui-acr3-kosong', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page);

            await page.goto('/pengingat');

            await expect(page.getByText('Belum ada pengingat.')).toBeVisible();
            await expect(
                page.getByText(
                    'Buat pengingat obat atau janji temu agar Anda tidak melewatkan jadwal.',
                ),
            ).toBeVisible();
            await expect(page.locator('[data-slot="pengingat-buat-kosong"]')).toBeVisible();

            await simpanGambar(page, vp, 'pengingat-kosong');
        });

        test('f11-ui-acr3-loading', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, { pengingat: [pengingat({ id: 51 })], delayMs: 1500 });

            await page.goto('/pengingat');

            await expect(page.locator('[data-slot="pengingat-loading"]')).toBeVisible();

            await expect(page.locator('[data-slot="pengingat-item"]')).toBeVisible({
                timeout: 10_000,
            });
        });

        test('f11-ui-acr3-error', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, {
                pengingat: [pengingat({ id: 61 })],
                pengingatStatus: 500,
            });

            await page.goto('/pengingat');

            const galat = page.locator('[data-slot="error-state"]');

            await expect(galat).toBeVisible();
            await expect(galat).toContainText('Gagal memuat pengingat.');

            state.pengingatStatus = 200;

            const sebelum = state.counts['GET /api/v1/pengingat'] ?? 0;

            await galat.getByRole('button', { name: 'Coba lagi' }).click();

            await expect(page.locator('[data-slot="pengingat-item"]')).toBeVisible();

            expect(state.counts['GET /api/v1/pengingat'] ?? 0).toBe(sebelum + 1);
        });

        test('f11-ui-acr3-offline', async ({ page, context }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, { pengingat: [pengingat({ id: 71 })] });

            await page.goto('/pengingat');
            await expect(page.locator('[data-slot="pengingat-item"]')).toBeVisible();

            await context.setOffline(true);

            const banner = page.getByTestId('offline-banner');

            await expect(banner).toBeVisible();
            await expect(banner).toContainText(
                'Anda sedang offline. Pengingat yang tampil adalah data terakhir yang tersimpan.',
            );
            await expect(page.locator('[data-slot="pengingat-offline-alasan"]')).toBeVisible();
            await expect(page.locator('[data-slot="pengingat-buat"]')).toHaveAttribute(
                'aria-disabled',
                'true',
            );
            await expect(page.locator('[data-slot="pengingat-toggle-status"]')).toHaveAttribute(
                'aria-disabled',
                'true',
            );
            await expect(page.locator('[data-slot="pengingat-hapus"]')).toHaveAttribute(
                'aria-disabled',
                'true',
            );

            const sebelum = state.puts.length;

            // `force` because the control is aria-disabled, which Playwright's
            // actionability refuses; the handler must still withhold the request.
            await page.locator('[data-slot="pengingat-toggle-status"]').click({ force: true });
            await page.waitForTimeout(300);

            expect(state.puts.length).toBe(sebelum);

            await context.setOffline(false);

            await expect(banner).toHaveCount(0);
        });

        test('f11-ui-acr3-a11y', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, {
                pengingat: [
                    pengingat({ id: 81 }),
                    pengingat({
                        id: 82,
                        jenis: 'janji_temu',
                        judul: 'Kontrol ulang dokter',
                        dosis: null,
                        jumlah_per_hari: null,
                        waktu: ['19:00'],
                        zona_waktu: 'Asia/Makassar',
                        zona_label: 'WITA',
                        booking_id: 77,
                    }),
                ],
            });

            await page.goto('/pengingat');
            await expect(page.locator('[data-slot="pengingat-item"]')).toHaveCount(2);

            await expectNoA11yViolations(page);

            for (const elemen of await page
                .locator('[data-slot="pengingat-item"] button')
                .all()) {
                const kotak = await elemen.boundingBox();

                expect((kotak as { height: number }).height).toBeGreaterThanOrEqual(44);
            }

            for (const sasaran of [
                '[data-slot="pengingat-judul"]',
                '[data-slot="pengingat-waktu"]',
                '[data-slot="pengingat-dosis"]',
                '[data-slot="pengingat-status"]',
            ]) {
                expect(
                    await kontrasTerhadapLatar(page.locator(sasaran)),
                    sasaran,
                ).toBeGreaterThanOrEqual(4.5);
            }

            await page.locator('[data-slot="pengingat-buat"]').click();
            await expect(page.locator('[data-slot="pengingat-dialog"]')).toBeVisible();

            // The dialog's enter animation fades its text, which axe reads as a lower
            // contrast; wait for the animation to settle before measuring.
            await page.waitForTimeout(400);

            await expectNoA11yViolations(page);

            for (const sasaran of [
                '[data-slot="pengingat-simpan"]',
                '[data-slot="pengingat-waktu-tambah"]',
                '[data-slot="pengingat-waktu-hapus"]',
            ]) {
                const kotak = await page.locator(sasaran).boundingBox();

                expect((kotak as { height: number }).height, sasaran).toBeGreaterThanOrEqual(44);
            }
        });

        test('f11-ui-nav-deep-link', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, {
                notifikasi: [BARIS_PENGINGAT_OBAT, BARIS_PENGINGAT_JANJI],
                unread: 2,
            });

            await page.goto('/notifikasi');

            // Nav entries: sidebar on desktop, the same nav inside the drawer on mobile.
            await bukaDrawer(page, vp);

            const akarNav =
                vp.width >= 768
                    ? page.locator('[data-slot="sidebar"]').first()
                    : page.locator('[data-slot="sidebar"][data-mobile="true"]');
            const navNotifikasi = akarNav.locator('a[href="/profil/notifikasi"]').first();
            const navPengingat = akarNav.locator('a[href="/pengingat"]').first();

            await expect(navNotifikasi).toBeVisible();
            await expect(navPengingat).toBeVisible();

            await navPengingat.click();

            await expect(page).toHaveURL(/\/pengingat$/);
            await expect(page.getByText('Belum ada pengingat.')).toBeVisible();

            await bukaDrawer(page, vp);
            await akarNav.locator('a[href="/profil/notifikasi"]').first().click();

            await expect(page).toHaveURL(/\/profil\/notifikasi$/);
            await expect(page.locator('[data-slot="preferensi-form"]')).toBeVisible();

            // Deep link: a reminder notification opens /pengingat, whether its `tautan`
            // is null (drug reminder) or the booking path (appointment reminder).
            await page.goto('/notifikasi');

            const tautan = page.locator('[data-slot="notifikasi-item-link"]');

            await expect(tautan).toHaveCount(2);
            await expect(tautan.first()).toHaveAttribute('href', '/pengingat');
            await expect(tautan.nth(1)).toHaveAttribute('href', '/pengingat');

            // The shell dropdown resolves through the same whitelist.
            await bukaDrawer(page, vp);
            await page.locator('[data-slot="notifikasi-bell"]').click();

            const dropdownLinks = page.locator('[data-slot="notifikasi-dropdown-link"]');

            await expect(dropdownLinks).toHaveCount(2);
            await expect(dropdownLinks.first()).toHaveAttribute('href', '/pengingat');

            // Escape closes the dropdown first, then the drawer.
            await page.keyboard.press('Escape');
            await expect(dropdownLinks).toHaveCount(0);

            await page.keyboard.press('Escape');
            await expect(
                page.locator('[data-slot="sidebar"][data-mobile="true"]'),
            ).toHaveCount(0);

            await tautan.first().click();

            await expect(page).toHaveURL(/\/pengingat$/);
        });
    });
}
