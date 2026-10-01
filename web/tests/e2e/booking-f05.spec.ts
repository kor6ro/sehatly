import { expect, test, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F05's acceptance criteria that the live spec cannot pin deterministically.
 *
 * ## Why these are mocked and `booking.spec.ts` is not
 *
 * `booking.spec.ts` proves the real contract against a live Laravel API: a 201, a refetch,
 * a 422. This file proves the **client's** branches - a doctor 404, an empty slot list, a
 * device zone that is not WIB, an offline transition, a privacy invariant - and each of
 * those needs a response the live fixture cannot produce on demand. Every request is
 * intercepted, so the assertions are the same on any machine and no real health data is
 * involved.
 *
 * ## The session is seeded, not registered
 *
 * `RequireAuth` checks `sessionStorage` for an access token, so an init script writes a
 * pair before the first navigation. Nothing validates it: every API call is mocked, and
 * the transport's 401 path is never reached.
 */

const TANGGAL_UJI = (() => {
    const d = new Date();
    d.setDate(d.getDate() + 30);

    const y = String(d.getFullYear()).padStart(4, '0');
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const h = String(d.getDate()).padStart(2, '0');

    return `${y}-${m}-${h}`;
})();

const USER = {
    id: 1,
    uuid: '00000000-0000-4000-8000-000000000001',
    nama_lengkap: 'Pasien Uji F05',
    no_telepon: '081200000001',
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

const SLOT = {
    jadwal_id: 7,
    jam_mulai: '09:00:00',
    jam_selesai: '09:15:00',
    tipe_layanan: 'online',
    faskes_id: null,
    tersedia: true,
    alasan: null,
};

const SLOT_PENUH = {
    jadwal_id: 7,
    jam_mulai: '09:15:00',
    jam_selesai: '09:30:00',
    tipe_layanan: 'online',
    faskes_id: null,
    tersedia: false,
    alasan: 'penuh',
};

const BOOKING = {
    id: 1,
    nomor_booking: 'BK20261110UJI001',
    pasien_id: 1,
    anggota_keluarga_id: null,
    dokter_id: 1,
    jadwal_id: null,
    faskes_id: null,
    tipe_layanan: 'video_call',
    tanggal_kunjungan: TANGGAL_UJI,
    slot_mulai: '09:00:00',
    slot_selesai: '09:15:00',
    nomor_antrian: null,
    keluhan: null,
    lampiran_keluhan: null,
    is_rujukan: false,
    is_konsultasi_lanjutan: false,
    status: 'menunggu_pembayaran',
    dibatalkan_oleh: null,
    alasan_pembatalan: null,
    dibuat_oleh_user_id: 1,
    dibuat_at: '2026-01-01T00:00:00.000000Z',
};

type OpsiMock = {
    dokterStatus?: number;
    slots?: Array<Record<string, unknown>>;
    postStatus?: number;
    postErrors?: Record<string, string[]>;
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
 * Intercept every `/api/v1` call the booking page makes.
 *
 * The catch-all at the bottom is load-bearing: an unmocked request would reach the real
 * API, answer 401, and send the transport down its session-expiry path - which would
 * redirect the test to `/login` for a reason that has nothing to do with the assertion.
 */
async function pasangMock(page: Page, opsi: OpsiMock = {}): Promise<void> {
    const slots = opsi.slots ?? [SLOT];
    const dokterStatus = opsi.dokterStatus ?? 200;
    const postStatus = opsi.postStatus ?? 201;

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

        if (/^\/api\/v1\/dokter\/[^/]+$/.test(path) && method === 'GET') {
            if (dokterStatus !== 200) {
                return balasJson(route, dokterStatus, {
                    success: false,
                    message: 'Resource not found.',
                    errors: {},
                });
            }

            return balasJson(route, 200, {
                success: true,
                message: 'Detail dokter berhasil dimuat.',
                data: { dokter: DOKTER },
            });
        }

        if (/^\/api\/v1\/dokter\/[^/]+\/slot$/.test(path)) {
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

        if (path === '/api/v1/notifikasi') {
            return balasJson(route, 200, {
                success: true,
                message: 'Daftar notifikasi berhasil dimuat.',
                data: { notifikasi: [] },
                meta: { ...meta(0, 5), unread: 0 },
            });
        }

        if (path === '/api/v1/booking' && method === 'POST') {
            if (postStatus !== 201) {
                return balasJson(route, postStatus, {
                    success: false,
                    message: 'Data yang dikirim tidak valid.',
                    errors: opsi.postErrors ?? {},
                });
            }

            return balasJson(route, 201, {
                success: true,
                message: 'Booking berhasil dibuat.',
                data: { booking: BOOKING },
            });
        }

        return balasJson(route, 200, {
            success: true,
            message: 'Berhasil.',
            data: {},
        });
    });
}

async function masukPalsu(page: Page): Promise<void> {
    await page.addInitScript(() => {
        sessionStorage.setItem('sehatly.access_token', 'token-uji-f05');
        sessionStorage.setItem('sehatly.refresh_token', 'refresh-uji-f05');
    });
}

test.describe('F05 AC-4: the two remaining 404/empty outcomes', () => {
    test('a 404 doctor renders the not-bookable state, not a retry loop', async ({
        page,
    }) => {
        await masukPalsu(page);
        await pasangMock(page, { dokterStatus: 404 });

        await page.goto('/booking/999');

        await expect(
            page.getByText('Dokter tidak dapat dipesan'),
        ).toBeVisible();

        await expect(
            page.getByRole('link', { name: /Kembali ke direktori/ }),
        ).toBeVisible();

        await expect(
            page.getByRole('button', { name: 'Coba lagi' }),
        ).toHaveCount(0);

        await expectNoA11yViolations(page);
    });

    test('an empty slot list renders the empty state with its reasons', async ({
        page,
    }) => {
        await masukPalsu(page);
        await pasangMock(page, { slots: [] });

        await page.goto(`/booking/1?tanggal=${TANGGAL_UJI}`);

        await expect(page.getByText('Tidak ada jam tersedia')).toBeVisible();

        await expect(
            page.getByText(/Server tidak memublikasikan jam yang dapat dipesan/),
        ).toBeVisible();

        await expectNoA11yViolations(page);
    });
});

test.describe('F05 AC-3: an unavailable slot stays visible with its reason', () => {
    test('renders the disabled slot and the correct available count', async ({
        page,
    }) => {
        await masukPalsu(page);
        await pasangMock(page, { slots: [SLOT, SLOT_PENUH] });

        await page.goto(`/booking/1?tanggal=${TANGGAL_UJI}`);

        const penuh = page.locator('[data-slot="slot-option"][disabled]');

        await expect(penuh).toHaveCount(1);
        await expect(penuh).toContainText('09.15–09.30 WIB');
        await expect(penuh).toContainText('Slot sudah penuh.');

        await expect(
            page.getByText('1 dari 2 jam dapat dipesan.'),
        ).toBeVisible();

        await expectNoA11yViolations(page);
    });
});

test.describe('F05 AC-8: the zone label is mandatory', () => {
    test.use({ timezoneId: 'Asia/Jakarta' });

    test('a WIB device reads the slot range with a dot separator and a label', async ({
        page,
    }) => {
        await masukPalsu(page);
        await pasangMock(page);

        await page.goto(`/booking/1?tanggal=${TANGGAL_UJI}&jam=09:00:00`);

        await expect(page.getByText('09.00–09.15 WIB')).toBeVisible();
        await expect(page.getByText('Jam: 09.00 WIB')).toBeVisible();

        await expectNoA11yViolations(page);
    });
});

test.describe('F05 AC-8: a device zone that differs from the schedule', () => {
    test.use({ timezoneId: 'Asia/Makassar' });

    test('converts to WITA and shows both zones in the order summary', async ({
        page,
    }) => {
        await masukPalsu(page);
        await pasangMock(page);

        await page.goto(`/booking/1?tanggal=${TANGGAL_UJI}&jam=09:00:00`);

        await expect(page.getByText('10.00–10.15 WITA')).toBeVisible();
        await expect(
            page.getByText('Jam: 10.00 WITA (09.00 WIB)'),
        ).toBeVisible();
    });
});

test.describe('F05 AC-6: a slot 422 preserves the form', () => {
    test('keeps the complaint and the attachment row', async ({ page }) => {
        await masukPalsu(page);
        await pasangMock(page, {
            postStatus: 422,
            postErrors: { slot: ['Slot sudah penuh untuk waktu ini.'] },
        });

        await page.goto(`/booking/1?tanggal=${TANGGAL_UJI}&jam=09:00:00`);

        await page.getByLabel('Keluhan').fill('Demam tiga hari.');
        await page.getByRole('button', { name: /Tambah lampiran/ }).click();
        await page.getByLabel('Nama berkas 1').fill('hasil-lab.pdf');
        await page
            .getByLabel('URL berkas 1')
            .fill('https://contoh.example/hasil-lab.pdf');

        await page.getByRole('button', { name: /Kirim booking/ }).click();

        const notice = page.locator('[data-slot="slot-taken-notice"]');

        await expect(notice).toBeVisible();
        await expect(notice).toHaveAttribute('role', 'alert');
        await expect(
            notice.getByText('Jam yang dipilih tidak dapat dipesan'),
        ).toBeVisible();
        await expect(
            notice.getByText('Slot sudah penuh untuk waktu ini.'),
        ).toBeVisible();

        await expect(page.getByLabel('Keluhan')).toHaveValue('Demam tiga hari.');
        await expect(page.getByLabel('Nama berkas 1')).toHaveValue(
            'hasil-lab.pdf',
        );
        await expect(page.getByLabel('URL berkas 1')).toHaveValue(
            'https://contoh.example/hasil-lab.pdf',
        );
    });
});

test.describe('F05 AC-10: offline blocks the write and keeps the form', () => {
    test('shows the banner, disables submit, and recovers without a reload', async ({
        page,
        context,
    }) => {
        await masukPalsu(page);
        await pasangMock(page);

        await page.goto(`/booking/1?tanggal=${TANGGAL_UJI}&jam=09:00:00`);

        await page.getByLabel('Keluhan').fill('Demam tiga hari.');

        const kirim = page.getByRole('button', { name: /Kirim booking/ });

        await expect(kirim).toBeEnabled();

        await context.setOffline(true);

        const banner = page.getByTestId('offline-banner');

        await expect(banner).toBeVisible();
        await expect(banner).toHaveAttribute('role', 'status');
        await expect(
            banner.getByText('Anda sedang luring. Periksa koneksi internet Anda.'),
        ).toBeVisible();
        await expect(kirim).toBeDisabled();
        await expect(page.getByLabel('Keluhan')).toHaveValue('Demam tiga hari.');

        await context.setOffline(false);

        await expect(banner).toHaveCount(0);
        await expect(kirim).toBeEnabled();
        await expect(page.getByLabel('Keluhan')).toHaveValue('Demam tiga hari.');
    });
});

test.describe('F05 AC-11: no medical data in the URL, the title, or the toast', () => {
    test('the complaint stays out of every addressable surface', async ({
        page,
    }) => {
        await masukPalsu(page);
        await pasangMock(page);

        const rahasia = 'Keluhan rahasia uji 12345';

        await page.goto(`/booking/1?tanggal=${TANGGAL_UJI}&jam=09:00:00`);

        await page.getByLabel('Keluhan').fill(rahasia);
        await page.getByRole('button', { name: /Kirim booking/ }).click();

        await expect(
            page.getByText('Booking BK20261110UJI001 berhasil dibuat.'),
        ).toBeVisible();

        expect(page.url()).not.toContain('rahasia');
        expect(await page.title()).not.toContain('rahasia');

        const toast = page.locator('[data-sonner-toast]');

        await expect(toast).toBeVisible();
        await expect(toast).toContainText('Booking berhasil dibuat.');
        await expect(toast).not.toContainText('rahasia');
    });
});

test.describe('F05 AC-9: touch targets, labels, and visible focus at 390 px', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('primary controls are at least 44 px and keyboard focus is visible', async ({
        page,
    }) => {
        await masukPalsu(page);
        await pasangMock(page);

        await page.goto(`/booking/1?tanggal=${TANGGAL_UJI}&jam=09:00:00`);

        const ukuran = async (
            locator: ReturnType<Page['locator']>,
        ): Promise<{ width: number; height: number }> => {
            const box = await locator.boundingBox();

            expect(box, 'elemen harus punya bounding box').not.toBeNull();

            return box as { width: number; height: number };
        };

        const slot = await ukuran(
            page.locator('[data-slot="slot-option"]').first(),
        );

        expect(slot.height).toBeGreaterThanOrEqual(43);
        expect(slot.width).toBeGreaterThanOrEqual(43);

        const kirim = await ukuran(
            page.getByRole('button', { name: /Kirim booking/ }),
        );

        expect(kirim.height).toBeGreaterThanOrEqual(43);

        const kalender = page.getByLabel('Pilih tanggal kunjungan');
        const panah = kalender.locator('button').first();
        const panahBox = await ukuran(panah);

        expect(panahBox.width).toBeGreaterThanOrEqual(43);
        expect(panahBox.height).toBeGreaterThanOrEqual(43);

        const kalenderBox = await ukuran(kalender);

        expect(kalenderBox.width).toBeGreaterThanOrEqual(43);
        expect(kalenderBox.height).toBeGreaterThanOrEqual(43);

        for (const label of [
            'Jam mulai terpilih',
            'Tipe layanan',
            'Didaftarkan atas nama',
            'Keluhan',
        ]) {
            await expect(page.getByLabel(label)).toBeVisible();
        }

        await expect(
            page.getByRole('textbox', { name: 'Tanggal kunjungan' }),
        ).toBeVisible();

        /**
         * Keyboard focus, not a programmatic `.focus()`: the readonly date input is the
         * tabbable immediately before the slot grid, so one `Tab` lands on the first slot
         * button with the keyboard modality that makes `:focus-visible` match.
         */
        await page.locator('input[readonly]').first().focus();
        await page.keyboard.press('Tab');

        const fokus = page.locator('[data-slot="slot-option"]:focus');

        await expect(fokus).toBeVisible();
        expect(
            await fokus.evaluate((el) => el.matches(':focus-visible')),
        ).toBe(true);
        expect(
            await fokus.evaluate((el) => getComputedStyle(el).boxShadow),
        ).not.toBe('none');
    });
});
