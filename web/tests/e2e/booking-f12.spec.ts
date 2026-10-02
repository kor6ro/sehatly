import { expect, test, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F12's acceptance criteria, mocked end to end at both required viewports.
 *
 * The `[SIAP]` group (AC-1..AC-8) covers the cancel dialog, its reason vocabulary, its
 * privacy invariants and its accessibility. The AC-R group covers the surfaces the
 * backend unblocked on 2026-10-02: the server-computed cancellation policy (AC-R1 /
 * AC-9), the same-row reschedule (AC-R2, AC-R3), and the refund card matched to its
 * cancelled booking (AC-R4..AC-R6, including the doctor-cancelled automatic refund).
 * AC-R7 is a server-ledger invariant and stays in the PHP suite.
 *
 * Every request is intercepted, so the same assertions hold on any machine and no real
 * health data is involved.
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

/**
 * The slot row the reschedule picker offers, a different hour from the booking's own
 * 09:00 so the assertions can tell "the old schedule" from "the new schedule" by text.
 */
const SLOT_BARU = {
    jadwal_id: 7,
    jam_mulai: '10:30:00',
    jam_selesai: '10:45:00',
    tipe_layanan: 'online',
    faskes_id: null,
    tersedia: true,
    alasan: null,
};

const KEBIJAKAN_GRATIS = {
    gratis: true,
    biaya: '0.00',
    jumlah_refund: '0.00',
    tujuan: null,
    sla: null,
};

const KEBIJAKAN_DIBAYAR = {
    gratis: true,
    biaya: '0.00',
    jumlah_refund: '150000.00',
    tujuan: { metode_id: 2, label: 'GoPay', tipe: 'e_wallet' },
    sla: null,
};

type KebijakanMock = {
    status: number;
    data?: Record<string, unknown>;
    delayMs?: number;
};

type JadwalMock = {
    status: number;
    errors?: Record<string, string[]>;
};

function refund(overrides: Record<string, unknown>): Record<string, unknown> {
    return {
        id: 1,
        booking_id: 1,
        jumlah: '150000.00',
        metode: { id: 2, label: 'GoPay' },
        status: 'diajukan',
        dibuat_at: '2026-10-01T00:00:00.000000Z',
        ...overrides,
    };
}

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
    kebijakan?: Record<number, KebijakanMock>;
    slots?: Array<Record<string, unknown>>;
    jadwal?: Record<number, JadwalMock>;
    refunds?: Array<Record<string, unknown>>;
    refundStatus?: number;
};

type MockState = {
    putCount: number;
    putBodies: unknown[];
    putPaths: string[];
    putJadwalCount: number;
    putJadwalBodies: Array<Record<string, unknown>>;
    putJadwalPaths: string[];
    postBookingCount: number;
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
        putJadwalCount: 0,
        putJadwalBodies: [],
        putJadwalPaths: [],
        postBookingCount: 0,
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

        if (/^\/api\/v1\/dokter\/[^/]+\/slot$/.test(path) && method === 'GET') {
            const slots = opsi.slots ?? [SLOT_BARU];

            return balasJson(route, 200, {
                success: true,
                message: 'Ketersediaan jam berhasil dimuat.',
                data: {
                    tanggal: url.searchParams.get('tanggal'),
                    timezone: 'Asia/Jakarta',
                    slots,
                },
                meta: meta(slots.length, slots.length),
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

        if (/^\/api\/v1\/booking\/\d+\/kebijakan$/.test(path) && method === 'GET') {
            const id = Number(
                /\/booking\/(\d+)\/kebijakan$/.exec(path)?.[1] ?? '0',
            );
            const fixture = opsi.kebijakan?.[id];

            if (fixture?.delayMs !== undefined) {
                await new Promise((selesai) => {
                    setTimeout(selesai, fixture.delayMs);
                });
            }

            if (fixture !== undefined && fixture.status !== 200) {
                return balasJson(route, fixture.status, {
                    success: false,
                    message: 'Terjadi kesalahan pada server. Coba lagi nanti.',
                    errors: {},
                });
            }

            return balasJson(route, 200, {
                success: true,
                message: 'Kebijakan pembatalan berhasil dimuat.',
                data: { kebijakan: fixture?.data ?? KEBIJAKAN_GRATIS },
            });
        }

        if (path === '/api/v1/pasien/refund' && method === 'GET') {
            if (
                opsi.refundStatus !== undefined &&
                opsi.refundStatus !== 200
            ) {
                return balasJson(route, opsi.refundStatus, {
                    success: false,
                    message: 'Terjadi kesalahan pada server. Coba lagi nanti.',
                    errors: {},
                });
            }

            const refunds = opsi.refunds ?? [];

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar refund berhasil dimuat.',
                data: { refund: refunds },
                meta: meta(refunds.length, 15),
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

        if (
            /^\/api\/v1\/booking\/\d+\/jadwal-ulang$/.test(path) &&
            method === 'PUT'
        ) {
            state.putJadwalCount += 1;

            const body = request.postDataJSON() as Record<string, unknown>;

            state.putJadwalBodies.push(body);
            state.putJadwalPaths.push(path);

            const id = Number(
                /\/booking\/(\d+)\/jadwal-ulang$/.exec(path)?.[1] ?? '0',
            );
            const fixture = opsi.jadwal?.[id];

            if (fixture !== undefined && fixture.status !== 200) {
                return balasJson(route, fixture.status, {
                    success: false,
                    message:
                        fixture.status >= 500
                            ? 'Terjadi kesalahan pada server. Coba lagi nanti.'
                            : 'Data yang dikirim tidak valid.',
                    errors: fixture.errors ?? {},
                });
            }

            const index = state.rows.findIndex((row) => row.id === id);

            const diperbarui = {
                ...state.rows[index],
                tanggal_kunjungan: body.tanggal_kunjungan,
                slot_mulai: body.slot_mulai,
                slot_selesai:
                    body.slot_selesai ?? state.rows[index]?.slot_selesai,
            };

            state.rows[index] = diperbarui;

            return balasJson(route, 200, {
                success: true,
                message: 'Jadwal booking berhasil dipindahkan.',
                data: { booking: diperbarui },
            });
        }

        if (path === '/api/v1/booking' && method === 'POST') {
            state.postBookingCount += 1;

            return balasJson(route, 201, {
                success: true,
                message: 'Booking berhasil dibuat.',
                data: {},
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
 *
 * Infinite animations are filtered out: the policy skeleton pulses with
 * `animate-pulse` while the fetch is in flight, and its `finished` promise never settles,
 * so waiting on it would hang the dialog helper instead of the two finite enter
 * animations it is meant to wait for.
 */
async function tungguDialogTenang(page: Page): Promise<void> {
    await page.locator('[data-slot="dialog-content"]').evaluate(async (element) => {
        await Promise.all(
            element
                .getAnimations({ subtree: true })
                .filter((animation) => {
                    const timing = animation.effect?.getTiming();

                    return timing?.iterations !== Infinity;
                })
                .map((animation) => animation.finished),
        );
    });
}

function dialog(page: Page) {
    return page.locator('[data-slot="dialog-content"]');
}

async function tutupDialog(page: Page): Promise<void> {
    await dialog(page)
        .getByRole('button', { name: 'Batal', exact: true })
        .click();

    await expect(dialog(page)).toHaveCount(0);
}

async function bukaJadwalDialog(page: Page, nomor: string): Promise<void> {
    await baris(page, nomor)
        .getByRole('button', { name: 'Jadwal ulang', exact: true })
        .click();

    await expect(dialog(page)).toBeVisible();
    await tungguDialogTenang(page);
}

async function pilihSlotBaru(page: Page): Promise<void> {
    await dialog(page)
        .locator('[data-slot="slot-option"]:not([disabled])')
        .first()
        .click();
}

function kartuRefund(page: Page, nomor: string) {
    return baris(page, nomor).locator('[data-slot="refund-kartu"]');
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

        test('f12-ac10-reschedule-tersedia', async ({ page }) => {
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
                slots: [SLOT_BARU],
            });

            await page.goto('/booking');

            const reschedule = baris(
                page,
                'BK-F12-ELIGIBLE',
            ).locator('[data-slot="reschedule-booking"]');

            await expect(reschedule).toBeVisible();
            await expect(reschedule).toBeEnabled();
            await expect(
                page.getByText('Jadwal ulang belum tersedia.'),
            ).toHaveCount(0);

            await reschedule.click();

            await expect(dialog(page)).toBeVisible();
            await expect(
                dialog(page).getByRole('heading', { name: 'Jadwal ulang' }),
            ).toBeVisible();

            expect(state.putJadwalCount).toBe(0);
            expect(state.postBookingCount).toBe(0);

            await expect(
                baris(page, 'BK-F12-SELESAI').locator(
                    '[data-slot="reschedule-booking"]',
                ),
            ).toHaveCount(0);
        });

        test('f12-acr1-kebijakan-server', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, {
                rows: [
                    booking({ id: 21, nomor_booking: 'BK-F12-K-PAID' }),
                    booking({ id: 22, nomor_booking: 'BK-F12-K-UNPAID' }),
                    booking({ id: 23, nomor_booking: 'BK-F12-K-ERROR' }),
                    booking({ id: 24, nomor_booking: 'BK-F12-K-SLOW' }),
                ],
                kebijakan: {
                    21: { status: 200, data: KEBIJAKAN_DIBAYAR },
                    22: { status: 200, data: KEBIJAKAN_GRATIS },
                    23: { status: 500 },
                    24: {
                        status: 200,
                        data: KEBIJAKAN_GRATIS,
                        delayMs: 1500,
                    },
                },
            });

            await page.goto('/booking');

            /**
             * Paid booking: the exact server figures, rendered as sentences. The
             * destination and the amount are read off the fixture, not computed.
             */
            await bukaDialog(page, 'BK-F12-K-PAID');

            const dibayar = page.locator('[data-slot="cancel-policy-detail"]');

            await expect(dibayar).toContainText('Pembatalan ini gratis.');
            await expect(dibayar).toContainText('Dana yang kembali');
            await expect(dibayar).toContainText('Rp 150.000');
            await expect(dibayar).toContainText('GoPay');
            await expect(dibayar).not.toContainText('Tidak ada dana');
            await expect(
                dialog(page).locator('[data-slot="confirm-cancel"]'),
            ).toBeEnabled();

            await page.screenshot({
                path: `ux/refs/f12/f12-acr1-kebijakan-${viewport.nama}.png`,
                fullPage: true,
            });

            await tutupDialog(page);

            /** Unpaid booking: the server sent `tujuan: null`; no destination is shown. */
            await bukaDialog(page, 'BK-F12-K-UNPAID');

            const belumBayar = page.locator(
                '[data-slot="cancel-policy-detail"]',
            );

            await expect(belumBayar).toContainText('Pembatalan ini gratis.');
            await expect(belumBayar).toContainText(
                'Tidak ada dana yang perlu dikembalikan.',
            );
            await expect(
                dialog(page).locator('[data-slot="confirm-cancel"]'),
            ).toBeEnabled();

            await tutupDialog(page);

            /**
             * Policy 500: the alert is on screen and the confirm is natively disabled,
             * so a DOM click still leaves without a single PUT (AC-9).
             */
            await bukaDialog(page, 'BK-F12-K-ERROR');

            const galat = page.locator('[data-slot="cancel-policy-error"]');

            await expect(galat).toBeVisible();
            await expect(galat).toContainText(
                'Rincian kebijakan belum dapat dimuat. Coba lagi.',
            );

            const konfirmasi = dialog(page).locator(
                '[data-slot="confirm-cancel"]',
            );

            await expect(konfirmasi).toBeDisabled();

            await konfirmasi.evaluate((element) => {
                (element as HTMLButtonElement).click();
            });
            await page.waitForTimeout(150);

            expect(state.putCount).toBe(0);

            await expectNoA11yViolations(page);

            await page.screenshot({
                path: `ux/refs/f12/f12-acr1-kebijakan-gagal-${viewport.nama}.png`,
                fullPage: true,
            });

            await tutupDialog(page);

            /**
             * Slow policy: the skeleton is on screen and the confirm is disabled until
             * the server answers, which is the other half of "no CTA before the data".
             */
            await bukaDialog(page, 'BK-F12-K-SLOW');

            await expect(
                page.locator('[data-slot="cancel-policy-skeleton"]'),
            ).toBeVisible();
            await expect(
                dialog(page).locator('[data-slot="confirm-cancel"]'),
            ).toBeDisabled();

            await expect(
                page.locator('[data-slot="cancel-policy-detail"]'),
            ).toBeVisible({ timeout: 5000 });
            await expect(
                dialog(page).locator('[data-slot="confirm-cancel"]'),
            ).toBeEnabled();

            await tutupDialog(page);
        });

        test('f12-acr2-reschedule', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, {
                rows: [
                    booking({ id: 31, nomor_booking: 'BK-F12-R2-A' }),
                    booking({ id: 32, nomor_booking: 'BK-F12-R2-B' }),
                    booking({ id: 33, nomor_booking: 'BK-F12-R2-C' }),
                ],
                slots: [SLOT_BARU],
                jadwal: {
                    32: {
                        status: 422,
                        errors: {
                            slot: ['Slot sudah penuh untuk waktu ini.'],
                        },
                    },
                    33: { status: 500 },
                },
            });

            await page.goto('/booking');

            /** Success: <= 6 taps from the row, one PUT, the same row moved. */
            let ketukan = 0;

            await baris(page, 'BK-F12-R2-A')
                .getByRole('button', { name: 'Jadwal ulang', exact: true })
                .click();
            ketukan += 1;

            await expect(dialog(page)).toBeVisible();
            await tungguDialogTenang(page);

            await expect(
                dialog(page).locator('[data-slot="reschedule-lama"]'),
            ).toContainText('09.00–09.15 WIB');

            await pilihSlotBaru(page);
            ketukan += 1;

            await expect(
                dialog(page).locator('[data-slot="reschedule-baru"]'),
            ).toContainText('10.30 WIB');

            await page.screenshot({
                path: `ux/refs/f12/f12-acr2-dialog-${viewport.nama}.png`,
            });

            await expect(
                dialog(page).locator('[data-slot="reschedule-safety"]'),
            ).toContainText(
                'Jadwal lama Anda tetap berlaku sampai jadwal baru berhasil disimpan.',
            );

            await expect(
                dialog(page).locator('[data-slot="reschedule-pembayaran"]'),
            ).toContainText('Harga sama. Tidak ada pembayaran tambahan.');

            await dialog(page).evaluate((element) => {
                element.scrollTop = element.scrollHeight;
            });

            await expect(
                dialog(page).locator('[data-slot="reschedule-pembayaran"]'),
            ).toBeVisible();

            await page.screenshot({
                path: `ux/refs/f12/f12-acr2-dialog-bawah-${viewport.nama}.png`,
            });

            await dialog(page)
                .locator('[data-slot="confirm-reschedule"]')
                .click();
            ketukan += 1;

            expect(ketukan).toBeLessThanOrEqual(6);

            const sukses = page.locator('[data-slot="reschedule-success"]');

            await expect(sukses).toHaveAttribute('role', 'status');
            await expect(sukses).toContainText('Jadwal berhasil dipindahkan.');
            await expect(sukses).toContainText('10.30 WIB');

            expect(state.putJadwalCount).toBe(1);
            expect(state.putJadwalBodies[0]).toEqual({
                jadwal_id: 7,
                tanggal_kunjungan: TANGGAL,
                slot_mulai: '10:30:00',
                slot_selesai: '10:45:00',
            });
            expect(state.postBookingCount).toBe(0);

            await expect(baris(page, 'BK-F12-R2-A')).toContainText(
                '10.30–10.45 WIB',
            );

            /** 422 `slot`: the fixed copy, and the old schedule is visibly unchanged. */
            await bukaJadwalDialog(page, 'BK-F12-R2-B');
            await pilihSlotBaru(page);
            await dialog(page)
                .locator('[data-slot="confirm-reschedule"]')
                .click();

            const slotGalat = page.locator(
                '[data-slot="reschedule-error"][data-error="slot"]',
            );

            await expect(slotGalat).toBeVisible();
            await expect(slotGalat).toContainText(
                'Slot baru sudah terisi. Pilih jam lain.',
            );
            await expect(dialog(page)).toBeVisible();
            await expect(
                dialog(page).locator('[data-slot="reschedule-lama"]'),
            ).toContainText('09.00–09.15 WIB');

            expect(state.putJadwalCount).toBe(2);

            await tutupDialog(page);
            await expect(baris(page, 'BK-F12-R2-B')).toContainText(
                '09.00–09.15 WIB',
            );

            /** 500: the honest copy, and the old schedule is still the one on screen. */
            await bukaJadwalDialog(page, 'BK-F12-R2-C');
            await pilihSlotBaru(page);
            await dialog(page)
                .locator('[data-slot="confirm-reschedule"]')
                .click();

            const gagal = page.locator(
                '[data-slot="reschedule-error"][data-error="gagal"]',
            );

            await expect(gagal).toContainText(
                'Gagal menyimpan jadwal baru. Jadwal lama Anda tidak berubah. Coba lagi.',
            );

            expect(state.putJadwalCount).toBe(3);

            await tutupDialog(page);
            await expect(baris(page, 'BK-F12-R2-C')).toContainText(
                '09.00–09.15 WIB',
            );

            await page.screenshot({
                path: `ux/refs/f12/f12-acr2-reschedule-${viewport.nama}.png`,
                fullPage: true,
            });

            await expectNoA11yViolations(page);
        });

        test('f12-acr3-selisih', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, {
                rows: [booking({ id: 34, nomor_booking: 'BK-F12-R3' })],
                slots: [SLOT_BARU],
            });

            await page.goto('/booking');

            await bukaJadwalDialog(page, 'BK-F12-R3');

            await expect(
                page.locator('[data-slot="reschedule-pembayaran"]'),
            ).toHaveText('Harga sama. Tidak ada pembayaran tambahan.');

            /** No payment step exists anywhere in the reschedule surface. */
            await expect(
                dialog(page).locator('[data-slot="payment"], [data-slot="pembayaran"]'),
            ).toHaveCount(0);
            await expect(
                dialog(page).getByRole('button', {
                    name: /Bayar|Lanjut ke pembayaran/,
                }),
            ).toHaveCount(0);

            await pilihSlotBaru(page);
            await dialog(page)
                .locator('[data-slot="confirm-reschedule"]')
                .click();

            await expect(
                page.locator('[data-slot="reschedule-success"]'),
            ).toBeVisible();

            expect(state.putJadwalCount).toBe(1);
            expect(state.postBookingCount).toBe(0);

            await expect(
                page.locator('li', { hasText: 'BK-F12-R3' }),
            ).toHaveCount(1);
        });

        test('f12-acr4-refund-status', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, {
                rows: [
                    booking({
                        id: 41,
                        nomor_booking: 'BK-F12-R4-A',
                        status: 'dibatalkan',
                        dibatalkan_oleh: 'pasien',
                    }),
                    booking({
                        id: 42,
                        nomor_booking: 'BK-F12-R4-B',
                        status: 'dibatalkan',
                        dibatalkan_oleh: 'pasien',
                    }),
                    booking({
                        id: 43,
                        nomor_booking: 'BK-F12-R4-C',
                        status: 'dibatalkan',
                        dibatalkan_oleh: 'pasien',
                    }),
                    booking({
                        id: 44,
                        nomor_booking: 'BK-F12-R4-D',
                        status: 'dibatalkan',
                        dibatalkan_oleh: 'pasien',
                    }),
                ],
                refunds: [
                    refund({
                        id: 1,
                        booking_id: 41,
                        jumlah: '150000.00',
                        metode: { id: 2, label: 'GoPay' },
                        status: 'diajukan',
                    }),
                    refund({
                        id: 2,
                        booking_id: 42,
                        jumlah: '250000.00',
                        metode: { id: 1, label: 'VA BCA' },
                        status: 'diproses',
                    }),
                    refund({
                        id: 3,
                        booking_id: 43,
                        jumlah: '90000.00',
                        metode: { id: 1, label: 'VA BCA' },
                        status: 'berhasil',
                    }),
                    refund({
                        id: 4,
                        booking_id: 44,
                        jumlah: '75000.00',
                        metode: { id: 2, label: 'GoPay' },
                        status: 'ditolak',
                    }),
                ],
            });

            await page.goto('/booking');

            await expect(page.locator('[data-slot="refund-kartu"]')).toHaveCount(
                4,
            );

            const fixture = [
                {
                    nomor: 'BK-F12-R4-A',
                    status: 'diajukan',
                    label: 'Diajukan',
                    jumlah: 'Rp 150.000',
                    warna: 'bg-muted',
                },
                {
                    nomor: 'BK-F12-R4-B',
                    status: 'diproses',
                    label: 'Diproses',
                    jumlah: 'Rp 250.000',
                    warna: 'bg-warning',
                },
                {
                    nomor: 'BK-F12-R4-C',
                    status: 'berhasil',
                    label: 'Dikembalikan',
                    jumlah: 'Rp 90.000',
                    warna: 'bg-success',
                },
                {
                    nomor: 'BK-F12-R4-D',
                    status: 'ditolak',
                    label: 'Ditolak',
                    jumlah: 'Rp 75.000',
                    warna: 'bg-destructive',
                },
            ];

            for (const item of fixture) {
                const kartu = kartuRefund(page, item.nomor);

                await expect(kartu).toHaveAttribute(
                    'data-status',
                    item.status,
                );
                await expect(kartu).toContainText(item.label);
                await expect(kartu).toContainText(item.jumlah);
                await expect(kartu).toContainText('Diajukan');

                const badge = kartu
                    .locator(`[data-status="${item.status}"]`)
                    .first();

                await expect(badge.locator('svg')).toHaveCount(1);
                expect(await badge.getAttribute('class')).toContain(
                    item.warna,
                );
            }

            await expect(kartuRefund(page, 'BK-F12-R4-B')).toContainText(
                'VA BCA',
            );
            await expect(kartuRefund(page, 'BK-F12-R4-A')).toContainText(
                '1 Okt 2026',
            );

            await expectNoA11yViolations(page);

            await page.screenshot({
                path: `ux/refs/f12/f12-acr4-refund-${viewport.nama}.png`,
                fullPage: true,
            });
        });

        test('f12-acr5-lambat', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, {
                rows: [
                    booking({
                        id: 51,
                        nomor_booking: 'BK-F12-R5-A',
                        status: 'dibatalkan',
                        dibatalkan_oleh: 'pasien',
                    }),
                    booking({
                        id: 52,
                        nomor_booking: 'BK-F12-R5-B',
                        status: 'dibatalkan',
                        dibatalkan_oleh: 'pasien',
                    }),
                ],
                refunds: [
                    refund({
                        id: 1,
                        booking_id: 51,
                        jumlah: '150000.00',
                        metode: { id: 1, label: 'VA BCA' },
                        status: 'diproses',
                    }),
                    refund({
                        id: 2,
                        booking_id: 52,
                        jumlah: '150000.00',
                        metode: { id: 2, label: 'GoPay' },
                        status: 'berhasil',
                    }),
                ],
            });

            await page.goto('/booking');

            await expect(kartuRefund(page, 'BK-F12-R5-A')).toContainText(
                'Diproses',
            );
            await expect(kartuRefund(page, 'BK-F12-R5-B')).toContainText(
                'Dikembalikan',
            );

            /** No invented completion date, no estimate, no progress bar. */
            for (const nomor of ['BK-F12-R5-A', 'BK-F12-R5-B']) {
                const kartu = kartuRefund(page, nomor);

                await expect(kartu).not.toContainText('Selesai');
                await expect(kartu).not.toContainText('Perkiraan');
                await expect(kartu).not.toContainText('Dana dikirim');
                await expect(kartu).toContainText('Diajukan');
                await expect(kartu).toContainText('1 Okt 2026');
            }

            await expect(page.locator('[role="progressbar"]')).toHaveCount(0);
            await expect(
                page.getByText(/Perkiraan|estimasi/i),
            ).toHaveCount(0);
        });

        test('f12-acr6-dokter-batal', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, {
                rows: [
                    booking({
                        id: 61,
                        nomor_booking: 'BK-F12-R6',
                        status: 'dibatalkan',
                        dibatalkan_oleh: 'dokter',
                    }),
                ],
                refunds: [
                    refund({
                        id: 1,
                        booking_id: 61,
                        jumlah: '350000.00',
                        metode: { id: 1, label: 'VA BCA' },
                        status: 'diajukan',
                    }),
                ],
            });

            await page.goto('/booking');

            const kartu = kartuRefund(page, 'BK-F12-R6');

            /** The backend wrote the refund; the patient did nothing to get it. */
            await expect(kartu).toBeVisible();
            await expect(kartu).toHaveAttribute('data-status', 'diajukan');
            await expect(kartu).toContainText('Diajukan');
            await expect(kartu).toContainText('Rp 350.000');
            await expect(kartu).toContainText('VA BCA');
            await expect(kartu.getByRole('button')).toHaveCount(0);

            const row = baris(page, 'BK-F12-R6');

            await expect(row).toContainText('Dibatalkan oleh dokter');
            await expect(
                row.locator('[data-slot="cancel-booking"]'),
            ).toHaveCount(0);
            await expect(
                row.locator('[data-slot="reschedule-booking"]'),
            ).toHaveCount(0);

            const tautan = row.locator('[data-slot="refund-link"]');

            await expect(tautan).toBeVisible();
            expect(await tautan.getAttribute('href')).toBe('#refund-61');

            await page.screenshot({
                path: `ux/refs/f12/f12-acr6-dokter-batal-${viewport.nama}.png`,
                fullPage: true,
            });

            await tautan.click();

            expect(page.url()).toContain('#refund-61');
            await expect(kartu).toBeVisible();

            await expectNoA11yViolations(page);
        });
    });
}
