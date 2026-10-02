import { expect, test, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F12's `[SIAP]` acceptance criteria, mocked end to end at both required viewports.
 *
 * Every request is intercepted, so the same assertions hold on any machine and no real
 * health data is involved. The blocked backend parts (policy, refund, reschedule endpoint)
 * are deliberately NOT tested here: AC-9 and AC-R1..R7 depend on endpoints that do not
 * exist, and this file refuses to fake them.
 *
 * Views: 390x844 (mobile-first) and 1280x900, as F12 §11 requires. The device zone is
 * pinned to Asia/Jakarta so the zone-labelled time is deterministic ("09.00 WIB").
 */

const TANGGAL = '2026-10-04';

const USER = {
    id: 1,
    uuid: '00000000-0000-4000-8000-000000000001',
    nama_lengkap: 'Pasien Uji F12',
    no_telepon: '081200000012',
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
    nama_lengkap: 'dr. Rina Wulandari, Sp.PD',
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

const HINT_PRIVASI =
    'Jangan tuliskan detail kondisi medis atau keluhan — cukup alasan umum.';

function booking(overrides: Record<string, unknown>): Record<string, unknown> {
    return {
        id: 1,
        nomor_booking: 'BK20261004F12001',
        pasien_id: 1,
        anggota_keluarga_id: null,
        dokter_id: 1,
        jadwal_id: null,
        faskes_id: null,
        tipe_layanan: 'video_call',
        tanggal_kunjungan: TANGGAL,
        slot_mulai: '09:00:00',
        slot_selesai: '09:15:00',
        nomor_antrian: null,
        keluhan: null,
        lampiran_keluhan: null,
        is_rujukan: false,
        is_konsultasi_lanjutan: false,
        status: 'terjadwal',
        dibatalkan_oleh: null,
        alasan_pembatalan: null,
        dibuat_oleh_user_id: 1,
        dibuat_at: '2026-10-01T00:00:00.000000Z',
        ...overrides,
    };
}

type OpsiMock = {
    rows: Array<Record<string, unknown>>;
    putStatus?: number;
    putErrors?: Record<string, string[]>;
};

type MockState = {
    putCount: number;
    putBodies: unknown[];
    putPaths: string[];
};

async function balasJson(
    route: Route,
    status: number,
    body: unknown,
): Promise<void> {
    await route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

function meta(total: number, perPage: number): Record<string, unknown> {
    return {
        current_page: 1,
        last_page: 1,
        per_page: perPage,
        total,
        from: null,
        to: null,
    };
}

/**
 * Intercept every `/api/v1` call the booking list makes.
 *
 * The list resolves doctor names through the public `GET /dokter/{id}`, so that route is
 * answered here as well; the catch-all keeps an unmocked path from reaching the real API
 * and turning into a session-expiry redirect unrelated to the assertion.
 */
async function pasangMock(page: Page, opsi: OpsiMock): Promise<MockState> {
    const state: MockState = {
        rows: opsi.rows.map((row) => ({ ...row })),
        putCount: 0,
        putBodies: [],
        putPaths: [],
    };

    await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;
        const method = request.method();

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

        if (/^\/api\/v1\/dokter\/[^/]+$/.test(path) && method === 'GET') {
            return balasJson(route, 200, {
                success: true,
                message: 'Detail dokter berhasil dimuat.',
                data: { dokter: DOKTER },
            });
        }

        if (path === '/api/v1/pasien/booking' && method === 'GET') {
            return balasJson(route, 200, {
                success: true,
                message: 'Daftar booking berhasil dimuat.',
                data: { booking: state.rows },
                meta: meta(state.rows.length, 15),
            });
        }

        if (
            /^\/api\/v1\/booking\/\d+\/batalkan$/.test(path) &&
            method === 'PUT'
        ) {
            state.putCount += 1;
            state.putBodies.push(request.postDataJSON());
            state.putPaths.push(path);

            if ((opsi.putStatus ?? 200) !== 200) {
                return balasJson(route, opsi.putStatus ?? 422, {
                    success: false,
                    message:
                        'Booking dengan status tersebut tidak dapat dibatalkan.',
                    errors: opsi.putErrors ?? {},
                });
            }

            const id = Number(
                /\/booking\/(\d+)\/batalkan$/.exec(path)?.[1] ?? '0',
            );
            const body = request.postDataJSON() as {
                alasan_pembatalan?: string;
            };
            const index = state.rows.findIndex((row) => row.id === id);

            const diperbarui = {
                ...state.rows[index],
                status: 'dibatalkan',
                dibatalkan_oleh: 'pasien',
                alasan_pembatalan: body.alasan_pembatalan ?? null,
            };

            state.rows[index] = diperbarui;

            return balasJson(route, 200, {
                success: true,
                message: 'Booking berhasil dibatalkan.',
                data: { booking: diperbarui },
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
        sessionStorage.setItem('sehatly.access_token', 'token-uji-f12');
        sessionStorage.setItem('sehatly.refresh_token', 'refresh-uji-f12');
    });
}

function baris(page: Page, nomor: string) {
    return page.locator('li', { hasText: nomor });
}

async function bukaDialog(page: Page, nomor: string): Promise<void> {
    await baris(page, nomor)
        .getByRole('button', { name: 'Batalkan', exact: true })
        .click();

    await expect(page.locator('[data-slot="dialog-content"]')).toBeVisible();
    await tungguDialogTenang(page);
}

/**
 * Let the dialog's 200 ms enter animation finish before asserting on it.
 *
 * Radix's content enters with `animate-in` + `fade-in-0`; while that opacity is mid-flight,
 * axe's colour-contrast rule composites the text against the still-translucent surface and
 * reports the two near-threshold pairs (muted text at ~4.7:1, white on `--destructive` at
 * ~4.76:1) as failures that do not exist in the settled state. Waiting on the animation's
 * own `finished` promise is deterministic; a timeout would not be.
 */
async function tungguDialogTenang(page: Page): Promise<void> {
    await page.locator('[data-slot="dialog-content"]').evaluate(async (element) => {
        await Promise.all(
            element
                .getAnimations({ subtree: true })
                .map((animation) => animation.finished),
        );
    });
}

function dialog(page: Page) {
    return page.locator('[data-slot="dialog-content"]');
}

test.use({ timezoneId: 'Asia/Jakarta' });

const VIEWPORTS = [
    { nama: '390x844', width: 390, height: 844 },
    { nama: '1280x900', width: 1280, height: 900 },
] as const;

for (const viewport of VIEWPORTS) {
    test.describe(`F12 di ${viewport.nama}`, () => {
        test.use({ viewport: { width: viewport.width, height: viewport.height } });

        test('f12-ac1-dialog-konsekuensi', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, { rows: [booking({})] });

            await page.goto('/booking');

            const tombol = baris(page, 'BK20261004F12001').getByRole(
                'button',
                { name: 'Batalkan', exact: true },
            );

            await expect(tombol).toBeVisible();

            let ketukan = 0;

            await tombol.click();
            ketukan += 1;

            const isi = dialog(page);

            await expect(isi).toBeVisible();
            expect(ketukan).toBeLessThanOrEqual(2);

            await expect(
                isi.getByRole('heading', { name: 'Batalkan janji temu?' }),
            ).toBeVisible();

            await expect(isi).toContainText('BK20261004F12001');
            await expect(isi).toContainText('dr. Rina Wulandari, Sp.PD');
            await expect(isi).toContainText('4 Oktober 2026');
            await expect(isi).toContainText('09.00 WIB');

            const konsekuensi = isi.locator(
                '[data-slot="cancel-consequences"] li',
            );

            await expect(konsekuensi).toHaveCount(3);
            await expect(konsekuensi.nth(0)).toContainText(
                'Slot ini akan dilepas dan dapat dipesan orang lain.',
            );
            await expect(konsekuensi.nth(1)).toContainText(
                'Setelah dikonfirmasi, pembatalan tidak dapat dipulihkan.',
            );
            await expect(konsekuensi.nth(2)).toContainText(
                'akan menerima pemberitahuan beserta alasan Anda.',
            );

            await expect(
                isi.getByRole('button', { name: 'Batal', exact: true }),
            ).toBeVisible();

            const konfirmasi = isi.locator('[data-slot="confirm-cancel"]');

            await expect(konfirmasi).toBeVisible();
            expect(await konfirmasi.getAttribute('class')).toContain(
                'bg-destructive',
            );

            const fokusDiDalam = await page.evaluate(() => {
                const konten = document.querySelector(
                    '[data-slot="dialog-content"]',
                );

                return (
                    konten !== null && konten.contains(document.activeElement)
                );
            });

            expect(fokusDiDalam).toBe(true);

            await expectNoA11yViolations(page);

            await page.screenshot({
                path: `ux/refs/f12/f12-ac1-dialog-${viewport.nama}.png`,
                fullPage: true,
            });
        });

        test('f12-ac2-status-guard-ui', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, {
                rows: [
                    booking({
                        id: 1,
                        status: 'menunggu_pembayaran',
                        nomor_booking: 'BK-F12-MENUNGGU',
                    }),
                    booking({
                        id: 2,
                        status: 'terjadwal',
                        nomor_booking: 'BK-F12-TERJADWAL',
                    }),
                    booking({
                        id: 3,
                        status: 'check_in',
                        nomor_booking: 'BK-F12-CHECKIN',
                    }),
                    booking({
                        id: 4,
                        status: 'berlangsung',
                        nomor_booking: 'BK-F12-BERLANGSUNG',
                    }),
                    booking({
                        id: 5,
                        status: 'selesai',
                        nomor_booking: 'BK-F12-SELESAI',
                    }),
                    booking({
                        id: 6,
                        status: 'dibatalkan',
                        nomor_booking: 'BK-F12-DIBATALKAN',
                    }),
                    booking({
                        id: 7,
                        status: 'no_show',
                        nomor_booking: 'BK-F12-NOSHOW',
                    }),
                    booking({
                        id: 8,
                        status: 'kadaluarsa',
                        nomor_booking: 'BK-F12-KADALUARSA',
                    }),
                ],
            });

            await page.goto('/booking');

            for (const nomor of [
                'BK-F12-MENUNGGU',
                'BK-F12-TERJADWAL',
            ]) {
                const eligible = baris(page, nomor);

                await expect(
                    eligible.getByRole('button', {
                        name: 'Batalkan',
                        exact: true,
                    }),
                ).toBeVisible();
                await expect(
                    eligible.getByRole('button', {
                        name: 'Jadwal ulang',
                        exact: true,
                    }),
                ).toBeVisible();
            }

            for (const nomor of [
                'BK-F12-CHECKIN',
                'BK-F12-BERLANGSUNG',
                'BK-F12-SELESAI',
                'BK-F12-DIBATALKAN',
                'BK-F12-NOSHOW',
                'BK-F12-KADALUARSA',
            ]) {
                const nonEligible = baris(page, nomor);

                await expect(
                    nonEligible.getByRole('button', {
                        name: 'Batalkan',
                        exact: true,
                    }),
                ).toHaveCount(0);
                await expect(
                    nonEligible.getByRole('button', {
                        name: 'Jadwal ulang',
                        exact: true,
                    }),
                ).toHaveCount(0);
                await expect(
                    nonEligible.locator('[data-slot="cancel-blocked-note"]'),
                ).toBeVisible();
            }
        });

        test('f12-ac3-alasan-opsional', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, {
                rows: [
                    booking({
                        id: 11,
                        nomor_booking: 'BK-F12-AC3-A',
                    }),
                    booking({
                        id: 12,
                        nomor_booking: 'BK-F12-AC3-B',
                    }),
                    booking({
                        id: 13,
                        nomor_booking: 'BK-F12-AC3-C',
                    }),
                ],
            });

            await page.goto('/booking');

            await bukaDialog(page, 'BK-F12-AC3-A');

            await expect(page.getByLabel('Catatan alasan')).toHaveCount(0);

            await dialog(page)
                .getByRole('radio', { name: 'Jadwal saya berubah' })
                .click();

            await dialog(page).locator('[data-slot="confirm-cancel"]').click();

            await expect(
                page.locator('[data-slot="cancel-success"]'),
            ).toBeVisible();

            expect(state.putBodies[0]).toEqual({
                alasan_pembatalan: 'Jadwal saya berubah',
            });
            expect(state.putCount).toBe(1);

            await bukaDialog(page, 'BK-F12-AC3-B');

            await expect(page.getByLabel('Catatan alasan')).toHaveCount(0);

            await dialog(page)
                .locator('[data-slot="confirm-cancel"]')
                .evaluate((element) => {
                    element.click();
                    element.click();
                });

            await expect(
                page.locator('[data-slot="cancel-success"]'),
            ).toBeVisible();

            expect(state.putBodies[1]).toEqual({});
            expect(state.putCount).toBe(2);

            await bukaDialog(page, 'BK-F12-AC3-C');

            await dialog(page)
                .getByRole('radio', { name: 'Lainnya' })
                .click();

            await expect(page.getByLabel('Catatan alasan')).toBeVisible();
            await expect(page.getByText(HINT_PRIVASI)).toBeVisible();
        });

        test('f12-ac4-sukses-inline', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, { rows: [booking({})] });

            await page.goto('/booking');

            await bukaDialog(page, 'BK20261004F12001');

            await dialog(page)
                .getByRole('radio', { name: 'Jadwal saya berubah' })
                .click();

            await dialog(page).locator('[data-slot="confirm-cancel"]').click();

            const kartu = page.locator('[data-slot="cancel-success"]');

            await expect(kartu).toBeVisible();
            await expect(kartu).toContainText('Janji temu dibatalkan.');

            const barisBatal = baris(page, 'BK20261004F12001');

            await expect(
                barisBatal.locator('[data-status="dibatalkan"]'),
            ).toBeVisible();
            await expect(barisBatal).toContainText('Dibatalkan oleh pasien');
            await expect(barisBatal).toContainText('Jadwal saya berubah');

            expect(page.url()).toContain('/booking');

            const toast = page.locator('[data-sonner-toast]');

            await expect(toast).toHaveCount(0);

            await page.screenshot({
                path: `ux/refs/f12/f12-ac4-sukses-${viewport.nama}.png`,
                fullPage: true,
            });
        });

        test('f12-ac5-offline', async ({ page, context }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, { rows: [booking({})] });

            await page.goto('/booking');

            await bukaDialog(page, 'BK20261004F12001');

            await dialog(page)
                .getByRole('radio', { name: 'Lainnya' })
                .click();
            await page.getByLabel('Catatan alasan').fill('Alasan tersimpan.');

            await context.setOffline(true);

            const banner = page.getByTestId('offline-banner');

            await expect(banner).toHaveCount(1);
            await expect(banner).toBeVisible();
            await expect(banner).toContainText('Anda sedang offline');

            const konfirmasi = dialog(page).locator(
                '[data-slot="confirm-cancel"]',
            );

            await expect(konfirmasi).toHaveAttribute('aria-disabled', 'true');
            await expect(page.getByLabel('Catatan alasan')).toHaveValue(
                'Alasan tersimpan.',
            );

            /**
             * `force: true` because Playwright treats `aria-disabled="true"` as disabled
             * and would otherwise wait forever. The DOM button is not natively disabled,
             * so the click really dispatches and the component's own `!online` guard is
             * what has to stop the request.
             */
            await konfirmasi.click({ force: true });
            await page.waitForTimeout(150);

            expect(state.putCount).toBe(0);

            await dialog(page)
                .getByRole('button', { name: 'Batal', exact: true })
                .click();

            const batalkanBaris = baris(
                page,
                'BK20261004F12001',
            ).locator('[data-slot="cancel-booking"]');

            await expect(batalkanBaris).toHaveAttribute(
                'aria-disabled',
                'true',
            );

            await batalkanBaris.click({ force: true });
            await page.waitForTimeout(100);

            await expect(dialog(page)).toHaveCount(0);
            expect(state.putCount).toBe(0);

            await context.setOffline(false);

            await expect(banner).toHaveCount(0);
            await expect(batalkanBaris).not.toHaveAttribute(
                'aria-disabled',
                'true',
            );
        });

        test('f12-ac6-422-status', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, {
                rows: [booking({})],
                putStatus: 422,
                putErrors: {
                    status: [
                        'Booking dengan status tersebut tidak dapat dibatalkan.',
                    ],
                },
            });

            await page.goto('/booking');

            await bukaDialog(page, 'BK20261004F12001');

            await dialog(page)
                .getByRole('radio', { name: 'Jadwal saya berubah' })
                .click();

            await dialog(page).locator('[data-slot="confirm-cancel"]').click();

            const galat = page.locator('[data-slot="cancel-error"]');

            await expect(galat).toBeVisible();
            await expect(galat).toContainText(
                'Janji dengan status ini tidak dapat dibatalkan.',
            );
            await expect(galat).toContainText('Muat ulang');

            await expect(dialog(page)).toBeVisible();
            await expect(
                dialog(page).getByRole('radio', {
                    name: 'Jadwal saya berubah',
                }),
            ).toHaveAttribute('aria-checked', 'true');

            const toast = page.locator('[data-sonner-toast]');

            await expect(toast).toHaveCount(0);
        });

        test('f12-ac7-a11y', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, { rows: [booking({})] });

            await page.goto('/booking');

            await expectNoA11yViolations(page);

            /**
             * Resolved before the dialog opens: Radix marks the rest of the app
             * `aria-hidden`, and Playwright's role engine stops matching the row controls
             * inside that subtree while the modal is up.
             */
            const kontrolBaris = [
                baris(page, 'BK20261004F12001').getByRole('button', {
                    name: 'Batalkan',
                    exact: true,
                }),
                baris(page, 'BK20261004F12001').getByRole('button', {
                    name: 'Jadwal ulang',
                    exact: true,
                }),
            ];

            for (const locator of kontrolBaris) {
                const box = await locator.boundingBox();

                expect(box, 'kontrol harus punya bounding box').not.toBeNull();
                expect(box?.height ?? 0).toBeGreaterThanOrEqual(43.5);
                expect(box?.width ?? 0).toBeGreaterThanOrEqual(43.5);
            }

            await bukaDialog(page, 'BK20261004F12001');

            await expectNoA11yViolations(page);

            const kontrolDialog = [
                dialog(page).getByRole('button', {
                    name: 'Batal',
                    exact: true,
                }),
                dialog(page).locator('[data-slot="confirm-cancel"]'),
                dialog(page)
                    .locator('[data-slot="cancel-reason-chips"] button')
                    .first(),
            ];

            for (const locator of kontrolDialog) {
                const box = await locator.boundingBox();

                expect(box, 'kontrol harus punya bounding box').not.toBeNull();
                expect(box?.height ?? 0).toBeGreaterThanOrEqual(43.5);
                expect(box?.width ?? 0).toBeGreaterThanOrEqual(43.5);
            }

            await dialog(page)
                .getByRole('radio', { name: 'Jadwal saya berubah' })
                .click();

            await dialog(page).locator('[data-slot="confirm-cancel"]').click();

            const hasil = page.locator('[data-slot="cancel-success"]');

            await expect(hasil).toHaveAttribute('role', 'status');
            await expect(hasil).toContainText('Janji temu dibatalkan.');

            /**
             * The closing dialog stays mounted for its 200 ms exit animation, and a scan
             * taken mid-fade composites every inside-the-dialog colour against a
             * translucent surface. Wait for the unmount, then scan the settled page.
             */
            await expect(dialog(page)).toHaveCount(0);

            await expectNoA11yViolations(page);
        });

        test('f12-ac8-privasi', async ({ page }) => {
            const rahasia = 'Amoxicillin 500 mg';

            await masukPalsu(page);
            await pasangMock(page, { rows: [booking({})] });

            await page.goto('/booking');

            await bukaDialog(page, 'BK20261004F12001');

            await dialog(page)
                .getByRole('radio', { name: 'Lainnya' })
                .click();
            await page.getByLabel('Catatan alasan').fill(rahasia);

            await dialog(page).locator('[data-slot="confirm-cancel"]').click();

            await expect(
                page.locator('[data-slot="cancel-success"]'),
            ).toBeVisible();

            expect(await page.title()).toBe('Janji temu | Sehatly');
            expect(page.url()).not.toContain('Amoxicillin');
            expect(page.url()).not.toContain('500');

            const toast = page.locator('[data-sonner-toast]');

            await expect(toast).toHaveCount(0);
            await expect(page.locator('[aria-label*="Amoxicillin"]')).toHaveCount(
                0,
            );
        });

        test('f12-ac10-reschedule-belum-ada', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, {
                rows: [
                    booking({ id: 1, nomor_booking: 'BK-F12-ELIGIBLE' }),
                    booking({
                        id: 2,
                        nomor_booking: 'BK-F12-SELESAI',
                        status: 'selesai',
                    }),
                ],
            });

            await page.goto('/booking');

            const reschedule = baris(
                page,
                'BK-F12-ELIGIBLE',
            ).locator('[data-slot="reschedule-booking"]');

            await expect(reschedule).toBeVisible();
            await expect(reschedule).toHaveAttribute('aria-disabled', 'true');
            await expect(reschedule).toBeDisabled();
            await expect(
                page.getByText('Jadwal ulang belum tersedia.'),
            ).toBeVisible();

            await reschedule.click({ force: true });
            await page.waitForTimeout(100);

            expect(page.url()).toContain('/booking');
            expect(page.url()).not.toContain('jadwal-ulang');
            expect(state.putCount).toBe(0);
            expect(state.putPaths).toEqual([]);

            await expect(
                dialog(page),
            ).toHaveCount(0);

            await expect(
                baris(page, 'BK-F12-SELESAI').locator(
                    '[data-slot="reschedule-booking"]',
                ),
            ).toHaveCount(0);
        });
    });
}
