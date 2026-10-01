import { expect, test, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F06's acceptance criteria, each in a deterministic mocked scenario.
 *
 * ## Why this file mocks and `pesanan-obat.spec.ts` does not
 *
 * The live spec proves the real contract end to end, but it cannot produce a 404 invoice, a
 * duplicate-payment 422, an offline transition or a status that changes on the second poll
 * on demand. This file intercepts every `/api/v1` call, so each branch of the screen is
 * exercised on any machine with no gateway, no database and no real health data.
 *
 * ## Every scenario runs at both widths
 *
 * `web/ux/patterns/F06.md` §11 requires identical assertions at `390x844` and `1280x900`.
 * The loop re-registers the route mock per viewport so request counters restart with the
 * scenario rather than accumulating across it.
 *
 * ## The session is seeded, not registered
 *
 * `RequireAuth` checks `sessionStorage` for an access token, so an init script writes a
 * pair before the first navigation. Nothing validates it: every API call is mocked.
 */

const VIEWPORTS = [
    { width: 390, height: 844 },
    { width: 1280, height: 900 },
] as const;

test.use({ timezoneId: 'Asia/Jakarta' });

const USER = {
    id: 1,
    uuid: '00000000-0000-4000-8000-000000000001',
    nama_lengkap: 'Sari Dewi',
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

const PESANAN_MENUNGGU = {
    id: 42,
    nomor_pesanan: 'PSN-202610-0042',
    resep_id: 7,
    pasien_id: 1,
    apotek_id: 3,
    tipe: 'resep_dokter',
    alamat_kirim: 'Jl. Melati No. 10, Bandung',
    kurir: 'internal',
    no_resi: null,
    subtotal: '84000.00',
    biaya_kirim: '10000.00',
    total: '94000.00',
    status: 'menunggu_pembayaran',
    dibuat_at: '2026-10-01T04:00:00.000000Z',
    diubah_at: '2026-10-01T04:00:00.000000Z',
    tracking: [],
};

const PESANAN_DIPROSES = {
    ...PESANAN_MENUNGGU,
    status: 'diproses',
};

/** Fourteen methods across all nine `master_metode_pembayaran.tipe` members. */
const METODE = [
    { id: 1, kode: 'bca_va', nama: 'BCA Virtual Account', tipe: 'va_bank', penyedia: 'Bank Central Asia', biaya_admin_flat: 2500, biaya_admin_persen: 0, status_aktif: true },
    { id: 2, kode: 'bni_va', nama: 'BNI Virtual Account', tipe: 'va_bank', penyedia: 'Bank Negara Indonesia', biaya_admin_flat: 2500, biaya_admin_persen: 0, status_aktif: true },
    { id: 3, kode: 'mandiri_va', nama: 'Mandiri Virtual Account', tipe: 'va_bank', penyedia: 'Bank Mandiri', biaya_admin_flat: 3000, biaya_admin_persen: 0, status_aktif: true },
    { id: 4, kode: 'gopay', nama: 'GoPay', tipe: 'e_wallet', penyedia: 'Gojek', biaya_admin_flat: 0, biaya_admin_persen: 1.5, status_aktif: true },
    { id: 5, kode: 'ovo', nama: 'OVO', tipe: 'e_wallet', penyedia: 'OVO', biaya_admin_flat: 0, biaya_admin_persen: 1.5, status_aktif: true },
    { id: 6, kode: 'qris_nusantara', nama: 'QRIS Nusantara', tipe: 'qris', penyedia: 'Nusantara', biaya_admin_flat: 0, biaya_admin_persen: 0.7, status_aktif: true },
    { id: 7, kode: 'qris_bank', nama: 'QRIS Bank', tipe: 'qris', penyedia: 'Himbara', biaya_admin_flat: 1000, biaya_admin_persen: 0, status_aktif: true },
    { id: 8, kode: 'kartu_visa', nama: 'Kartu kredit Visa', tipe: 'kartu_kredit', penyedia: 'Visa', biaya_admin_flat: 2000, biaya_admin_persen: 1, status_aktif: true },
    { id: 9, kode: 'alfamart', nama: 'Alfamart', tipe: 'gerai_retail', penyedia: 'Alfamart', biaya_admin_flat: 2500, biaya_admin_persen: 0, status_aktif: true },
    { id: 10, kode: 'indomaret', nama: 'Indomaret', tipe: 'gerai_retail', penyedia: 'Indomaret', biaya_admin_flat: 2500, biaya_admin_persen: 0, status_aktif: true },
    { id: 11, kode: 'cod', nama: 'Bayar di tempat', tipe: 'cod', penyedia: null, biaya_admin_flat: 0, biaya_admin_persen: 0, status_aktif: true },
    { id: 12, kode: 'tunai', nama: 'Tunai di apotek', tipe: 'tunai', penyedia: null, biaya_admin_flat: 0, biaya_admin_persen: 0, status_aktif: true },
    { id: 13, kode: 'bpjs', nama: 'BPJS Kesehatan', tipe: 'bpjs', penyedia: 'BPJS', biaya_admin_flat: 0, biaya_admin_persen: 0, status_aktif: true },
    { id: 14, kode: 'asuransi', nama: 'Asuransi kesehatan', tipe: 'asuransi', penyedia: null, biaya_admin_flat: 0, biaya_admin_persen: 0, status_aktif: true },
];

const PEMBAYARAN_PENDING = {
    id: 21,
    invoice_id: 7,
    metode_id: 1,
    jumlah: '94500.00',
    nomor_referensi: '8826100123',
    gateway: 'midtrans',
    va_number: '8081210045590',
    status: 'pending',
    dibayar_at: null,
    kadaluwarsa_at: '2026-10-02T04:00:00.000000Z',
    terminal: false,
};

function invoiceFixture(pembayaran: unknown[] = []) {
    return {
        id: 7,
        nomor_invoice: 'INV-202610-0042',
        referensi_tipe: 'pesanan_obat',
        referensi_id: 42,
        subtotal: '84000.00',
        diskon: '0.00',
        biaya_admin: '500.00',
        biaya_pengiriman: '10000.00',
        total: '94500.00',
        status: 'menunggu_pembayaran',
        jatuh_tempo: '2026-10-02T04:00:00.000000Z',
        lunas_at: null,
        dibuat_at: '2026-10-01T04:00:00.000000Z',
        pembayaran,
    };
}

const MULAI_VA = {
    invoice: {
        id: 7,
        nomor_invoice: 'INV-202610-0042',
        status: 'menunggu_pembayaran',
        total: '94500.00',
    },
    pembayaran: { ...PEMBAYARAN_PENDING },
    gateway: { nama: 'midtrans', nomor_referensi: '8826100123' },
    toko: {
        va_number: '8081210045590',
        nama_bank: 'BCA',
        nama_pemilik: 'Sari Dewi',
    },
    instruksi: [
        'Buka aplikasi m-BCA',
        'Pilih m-Transfer lalu BCA Virtual Account',
        'Masukkan nomor virtual account dan nominal tagihan',
    ],
};

const MULAI_QR = {
    invoice: MULAI_VA.invoice,
    pembayaran: { ...PEMBAYARAN_PENDING, metode_id: 6, va_number: null },
    gateway: { nama: 'midtrans', nomor_referensi: '8826100123' },
    toko: {
        qr_string: '00020101021226610014ID.CO.QRIS.WWW01189360000900000000000215',
        nama_penyedia: 'QRIS Nusantara',
    },
    instruksi: [
        'Buka aplikasi dompet atau m-banking Anda',
        'Pindai kode QR di atas',
        'Periksa nominal lalu konfirmasi',
    ],
};

const PROMO_TOLAK = {
    promo: { kode: 'HEMAT10', nama: 'Diskon Hemat', tipe_diskon: 'persen' },
    invoice: { id: 7, nomor_invoice: 'INV-202610-0042' },
    valid: false,
    nilai_diskon: '0.00',
    total: '94500.00',
    rincian: {
        subtotal: '84000.00',
        diskon: '1000.00',
        biaya_admin: '500.00',
        biaya_pengiriman: '10000.00',
        total: '93500.00',
    },
    alasan: [
        { kode: 'jendela_waktu', kolom: 'jendela_waktu', pesan: 'Promo sudah berakhir pada 30 September 2026.' },
        { kode: 'min_transaksi', kolom: 'min_transaksi', pesan: 'Belanja minimum Rp 100.000 belum terpenuhi.' },
    ],
};

type ModeMetode = 'ok' | 'lambat' | 'gagal';

type OpsiMock = {
    modeMetode?: ModeMetode;
    urutanStatusPesanan?: string[];
    bayarStatus?: number;
    bayarErrors?: Record<string, string[]>;
    invoiceStatus?: number;
    invoice?: ReturnType<typeof invoiceFixture>;
};

type PeganganMock = {
    jumlahPesanan: () => number;
};

async function balasJson(route: Route, status: number, body: unknown): Promise<void> {
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
 * Intercept every `/api/v1` call the payment screen and its shell make.
 *
 * The catch-all is load-bearing: an unmocked request would reach the real API, answer 401,
 * and send the transport down its session-expiry path - redirecting the test to `/login`
 * for a reason unrelated to the assertion.
 */
async function pasangMock(page: Page, opsi: OpsiMock = {}): Promise<PeganganMock> {
    const urutanStatus = opsi.urutanStatusPesanan ?? ['menunggu_pembayaran'];
    const statusPesananKe = { n: 0 };
    const jumlahPesanan = { n: 0 };

    await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;
        const method = request.method();
        // Read per request, not once: a phase change after registration must be visible.
        const modeMetode = opsi.modeMetode ?? 'ok';

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

        if (path === '/api/v1/referensi/metode-pembayaran') {
            if (modeMetode === 'lambat') {
                await new Promise((selesai) => setTimeout(selesai, 600));
            }

            if (modeMetode === 'gagal') {
                return balasJson(route, 500, {
                    success: false,
                    message: 'Terjadi kesalahan pada server. Coba lagi nanti.',
                    errors: {},
                });
            }

            return balasJson(route, 200, {
                success: true,
                message: 'Referensi metode pembayaran berhasil dimuat.',
                data: { metode_pembayaran: METODE },
                meta: meta(METODE.length, METODE.length),
            });
        }

        if (/^\/api\/v1\/pesanan-obat\/\d+$/.test(path) && method === 'GET') {
            const indeks = Math.min(statusPesananKe.n, urutanStatus.length - 1);
            const status = urutanStatus[indeks];

            statusPesananKe.n += 1;
            jumlahPesanan.n += 1;

            return balasJson(route, 200, {
                success: true,
                message: 'Detail pesanan berhasil dimuat.',
                data: {
                    pesanan: {
                        ...(status === 'diproses' ? PESANAN_DIPROSES : PESANAN_MENUNGGU),
                        status,
                    },
                },
            });
        }

        if (/^\/api\/v1\/invoice\/\d+$/.test(path) && method === 'GET') {
            if (opsi.invoiceStatus !== undefined && opsi.invoiceStatus !== 200) {
                return balasJson(route, opsi.invoiceStatus, {
                    success: false,
                    message: 'Resource not found.',
                    errors: {},
                });
            }

            return balasJson(route, 200, {
                success: true,
                message: 'Tagihan berhasil dimuat.',
                data: { invoice: opsi.invoice ?? invoiceFixture() },
            });
        }

        if (/^\/api\/v1\/invoice\/\d+\/bayar$/.test(path) && method === 'POST') {
            if (opsi.bayarStatus === 422) {
                return balasJson(route, 422, {
                    success: false,
                    message: 'The given data was invalid.',
                    errors: opsi.bayarErrors ?? {},
                });
            }

            const body = JSON.parse(request.postData() ?? '{}') as {
                metode_id?: number;
            };
            const qr = body.metode_id === 6 || body.metode_id === 7;

            return balasJson(route, 201, {
                success: true,
                message: 'Pembayaran berhasil dimulai.',
                data: qr ? MULAI_QR : MULAI_VA,
            });
        }

        if (path === '/api/v1/promo/validasi' && method === 'POST') {
            return balasJson(route, 200, {
                success: true,
                message: 'Kode promo berhasil diperiksa.',
                data: PROMO_TOLAK,
            });
        }

        return balasJson(route, 200, {
            success: true,
            message: 'Berhasil.',
            data: {},
        });
    });

    return { jumlahPesanan: () => jumlahPesanan.n };
}

async function masukPalsu(page: Page): Promise<void> {
    await page.addInitScript(() => {
        sessionStorage.setItem('sehatly.access_token', 'token-uji-f06');
        sessionStorage.setItem('sehatly.refresh_token', 'refresh-uji-f06');
    });
}

async function bukaLayar(page: Page): Promise<void> {
    await page.goto('/pembayaran/42');
    await expect(page.locator('[data-slot="pembayaran-menunggu"]')).toBeVisible({
        timeout: 30_000,
    });
}

async function pilihMetode(page: Page, nama: RegExp): Promise<void> {
    await page.getByRole('combobox', { name: 'Metode pembayaran' }).click();

    const konten = page.locator('[data-slot="select-content"]');

    await konten.waitFor();

    // The content animates in with a zoom transform; clicking mid-animation can miss the
    // item entirely, which leaves the listbox open and the trigger unchanged.
    await page.waitForTimeout(250);

    await konten.getByRole('option', { name: nama }).first().click({ force: true });

    // Radix keeps the portal mounted through its exit animation and holds the page under
    // `aria-hidden` while it does; waiting for detachment keeps axe and Tab deterministic.
    await expect(konten).toHaveCount(0);
}

/** The touch-target and focus test needs one stable wrapper per viewport. */
async function siapkanLayarSiapBayar(page: Page): Promise<void> {
    await bukaLayar(page);
    await page.locator('[data-slot="payment-invoice-id"]').fill('7');
    await expect(page.locator('[data-slot="invoice-ringkas"]')).toBeVisible();
    await pilihMetode(page, /BCA Virtual Account/);
    await expect(page.locator('[data-slot="payment-submit"]')).toBeEnabled();
}

test.describe('F06 AC-1: order summary renders the order verbatim', () => {
    test('shows number, status badge and money, and claims nothing paid', async ({ page }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');
            await pasangMock(page);

            await bukaLayar(page);

            const ringkas = page.locator('[data-slot="pesanan-ringkas"]');

            await expect(ringkas.getByText('PSN-202610-0042')).toBeVisible();
            await expect(page.locator('[data-slot="pesanan-total"]')).toHaveText('Rp 94.000');
            await expect(ringkas.getByText('Rp 84.000')).toBeVisible();
            await expect(ringkas.getByText('Rp 10.000')).toBeVisible();

            const status = page.locator('[data-slot="pesanan-status"]');

            await expect(status).toHaveText('Menunggu pembayaran');
            await expect(status.locator('svg')).toHaveCount(1);
            await expect(status).toHaveAttribute('data-status', 'menunggu_pembayaran');

            await expect(page.locator('[data-slot="pesanan-sudah-terbayar"]')).toHaveCount(0);
            await expect(page.locator('[data-slot="pembayaran-menunggu"]')).not.toContainText(
                /lunas/i,
            );
        }
    });
});

test.describe('F06 AC-2: the submit stays locked until both inputs are usable', () => {
    test('disabled at empty and 0, enabled after a positive id and a method', async ({ page }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');
            await pasangMock(page);

            await bukaLayar(page);

            const id = page.locator('[data-slot="payment-invoice-id"]');
            const kirim = page.locator('[data-slot="payment-submit"]');

            await expect(kirim).toBeDisabled();

            await id.fill('0');
            await expect(kirim).toBeDisabled();

            await id.fill('7');
            await expect(kirim).toBeDisabled();

            await pilihMetode(page, /BCA Virtual Account/);
            await expect(kirim).toBeEnabled();

            await id.fill('7.5');
            await expect(kirim).toBeDisabled();

            await id.fill('7');
            await expect(kirim).toBeEnabled();
        }
    });
});

test.describe('F06 AC-3: the method list is grouped, complete and retryable', () => {
    test('skeleton, then nine groups of fourteen priced options, then a retry state', async ({
        page,
    }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');

            const opsi: OpsiMock = { modeMetode: 'lambat' };

            await pasangMock(page, opsi);
            await bukaLayar(page);

            await expect(page.locator('[data-slot="metode-loading"]')).toBeVisible();
            await expect(page.locator('[data-slot="metode-pembayaran"]')).toBeVisible();

            await page.getByRole('combobox', { name: 'Metode pembayaran' }).click();

            const grup = page.locator('[data-slot="metode-grup"]');
            const pilihan = page.locator('[data-slot="metode-pilihan"]');

            await expect(grup).toHaveCount(9);
            await expect(pilihan).toHaveCount(14);

            for (let i = 0; i < 14; i += 1) {
                await expect(pilihan.nth(i)).toContainText(/Rp/);
                await expect(pilihan.nth(i)).toContainText(/perkiraan/);
            }

            await page.keyboard.press('Escape');

            opsi.modeMetode = 'gagal';
            await page.reload();

            await expect(page.locator('[data-slot="error-state"]')).toBeVisible({
                timeout: 30_000,
            });
            await expect(
                page.getByRole('button', { name: 'Coba lagi' }),
            ).toBeVisible();
        }
    });
});

test.describe('F06 AC-4: the initiation renders VA or QR with its expiry', () => {
    test('a VA block, then a QR block after a QR method', async ({ page }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');
            await pasangMock(page);

            await bukaLayar(page);
            await page.locator('[data-slot="payment-invoice-id"]').fill('7');
            await pilihMetode(page, /BCA Virtual Account/);

            const [va] = await Promise.all([
                page.waitForResponse(
                    (r) => r.url().includes('/bayar') && r.request().method() === 'POST',
                ),
                page.locator('[data-slot="payment-submit"]').click(),
            ]);

            expect(va.status()).toBe(201);

            const instruksi = page.locator('[data-slot="payment-instructions"]');

            await expect(page.locator('[data-slot="payment-va"]')).toHaveText(
                '8081210045590',
            );
            await expect(instruksi).toContainText('Virtual account BCA atas nama Sari Dewi');
            await expect(page.locator('[data-slot="payment-total"]')).toHaveText('Rp 94.500');
            await expect(instruksi).toContainText('INV-202610-0042');
            await expect(page.locator('[data-slot="payment-referensi"]')).toHaveText(
                '8826100123',
            );
            await expect(page.locator('[data-slot="payment-status"]')).toHaveText(
                'Menunggu konfirmasi gateway',
            );
            await expect(instruksi).toContainText('2 Okt 2026, 11.00 WIB');
            await expect(
                page.locator('[data-slot="payment-instruksi"] li'),
            ).toHaveCount(3);

            await page.reload();
            await expect(page.locator('[data-slot="pembayaran-menunggu"]')).toBeVisible();
            await page.locator('[data-slot="payment-invoice-id"]').fill('7');
            await pilihMetode(page, /QRIS Nusantara/);

            await page.locator('[data-slot="payment-submit"]').click();

            await expect(page.locator('[data-slot="payment-qr"]')).toContainText(
                'ID.CO.QRIS.WWW',
            );
            await expect(page.locator('[data-slot="payment-toko"]')).toHaveAttribute(
                'data-jenis',
                'qr',
            );
            await expect(page.locator('[data-slot="payment-total"]')).toHaveText('Rp 94.500');
        }
    });
});

test.describe('F06 AC-5: a refused promo renders every server reason verbatim', () => {
    test('two reasons with distinct columns, and server money unchanged', async ({ page }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');
            await pasangMock(page);

            await bukaLayar(page);
            await page.locator('[data-slot="payment-invoice-id"]').fill('7');
            await pilihMetode(page, /BCA Virtual Account/);
            await page.locator('[data-slot="payment-submit"]').click();
            await expect(page.locator('[data-slot="payment-instructions"]')).toBeVisible();

            const kotakCek = await page.locator('[data-slot="promo-cek"]').boundingBox();

            expect(kotakCek?.height ?? 0).toBeGreaterThanOrEqual(44);

            await page.locator('[data-slot="promo-kode"]').fill('HEMAT10');
            await page.locator('[data-slot="promo-cek"]').click();

            const hasil = page.locator('[data-slot="promo-hasil"][data-valid="false"]');

            await expect(hasil).toBeVisible();

            const alasan = page.locator('[data-slot="promo-alasan"]');

            await expect(alasan).toHaveCount(2);
            await expect(alasan.nth(0)).toHaveAttribute('data-kolom', 'jendela_waktu');
            await expect(alasan.nth(1)).toHaveAttribute('data-kolom', 'min_transaksi');
            await expect(alasan.nth(0)).toHaveText(
                'Promo sudah berakhir pada 30 September 2026.',
            );

            await expect(page.locator('[data-slot="promo-rincian-subtotal"]')).toHaveText(
                'Rp 84.000',
            );
            await expect(page.locator('[data-slot="promo-rincian-diskon"]')).toHaveText(
                'Rp 1.000',
            );
            await expect(page.locator('[data-slot="promo-rincian-admin"]')).toHaveText(
                'Rp 500',
            );
            await expect(page.locator('[data-slot="promo-rincian-kirim"]')).toHaveText(
                'Rp 10.000',
            );
        }
    });
});

test.describe('F06 AC-6: polling disarms the moment the order stops waiting', () => {
    test('call two settles, the success alert shows, and no fourth call arrives', async ({
        page,
    }) => {
        test.setTimeout(180_000);

        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');

            const mock = await pasangMock(page, {
                urutanStatusPesanan: ['menunggu_pembayaran', 'diproses'],
            });

            await bukaLayar(page);

            const sukses = page.locator('[data-slot="pesanan-sudah-terbayar"]');

            await expect(sukses).toBeVisible({ timeout: 30_000 });
            await expect(page.locator('[data-slot="pesanan-status"]')).toHaveText(
                'Diproses apotek',
            );
            await expect(page.locator('[data-slot="payment-mulai"]')).toHaveCount(0);

            const setelahSettle = mock.jumlahPesanan();

            expect(setelahSettle).toBeLessThanOrEqual(3);

            // 45 s with no fourth request: an implementation that kept polling would fire
            // at t+15, t+30 and t+45.
            await page.waitForTimeout(45_000);

            expect(mock.jumlahPesanan()).toBe(setelahSettle);
        }
    });
});

test.describe('F06-AC-7: a duplicate 422 keeps the form and shows the server sentence', () => {
    test('renders the field message, keeps both values, and claims nothing paid', async ({
        page,
    }) => {
        await masukPalsu(page);

        const pesan =
            'Pembayaran untuk invoice ini sudah dibuat dengan nomor referensi 8826100123.';

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');
            await pasangMock(page, {
                bayarStatus: 422,
                bayarErrors: { metode_id: [pesan] },
                invoice: invoiceFixture([PEMBAYARAN_PENDING]),
            });

            await bukaLayar(page);
            await page.locator('[data-slot="payment-invoice-id"]').fill('7');
            await expect(page.locator('[data-slot="invoice-ringkas"]')).toBeVisible();
            await expect(
                page.locator('[data-slot="invoice-pembayaran-row"]'),
            ).toHaveCount(1);

            await pilihMetode(page, /BCA Virtual Account/);
            await page.locator('[data-slot="payment-submit"]').click();

            await expect(page.locator('[data-slot="payment-server-refused"]')).toHaveText(
                pesan,
            );
            await expect(
                page.getByRole('alert').filter({ hasText: '1 field perlu diperbaiki' }),
            ).toBeVisible();

            await expect(page.locator('[data-slot="payment-invoice-id"]')).toHaveValue('7');
            await expect(
                page.getByRole('combobox', { name: 'Metode pembayaran' }),
            ).toContainText('BCA Virtual Account');

            await expect(page.locator('[data-slot="payment-instructions"]')).toHaveCount(0);
            await expect(page.locator('[data-slot="pesanan-sudah-terbayar"]')).toHaveCount(0);
        }
    });
});

test.describe('F06 AC-8: offline blocks the payment, then a retry restarts the read', () => {
    test('banner + disabled submit while offline, then Coba lagi refetches', async ({
        page,
        context,
    }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');

            const mock = await pasangMock(page);

            await siapkanLayarSiapBayar(page);

            await context.setOffline(true);

            const banner = page.getByTestId('offline-banner');

            await expect(banner).toBeVisible();
            await expect(banner).toHaveAttribute('role', 'status');
            await expect(banner).toContainText('Anda sedang luring');
            await expect(page.locator('[data-slot="payment-submit"]')).toBeDisabled();
            await expect(page.locator('[data-slot="payment-invoice-id"]')).toHaveValue('7');

            const sebelum = mock.jumlahPesanan();

            await context.setOffline(false);

            const cobaLagi = page.locator('[data-slot="payment-cobaLagi"]');

            await expect(cobaLagi).toBeVisible();
            await expect(
                page.locator('[data-slot="payment-coba-lagi"]'),
            ).toContainText('Koneksi kembali');

            await cobaLagi.click();

            await expect
                .poll(() => mock.jumlahPesanan(), { timeout: 15_000 })
                .toBeGreaterThan(sebelum);

            await expect(page.locator('[data-slot="payment-submit"]')).toBeEnabled();
        }
    });
});

test.describe('F06 AC-9: touch targets, labels, focus and status at both widths', () => {
    test('all controls are 44 px, labelled, keyboard-focusable, and axe-clean', async ({
        page,
    }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');
            await pasangMock(page);

            await siapkanLayarSiapBayar(page);

            const kontrol = page.locator(
                '[data-slot="pembayaran-menunggu"] button, [data-slot="pembayaran-menunggu"] [role="combobox"]',
            );

            const jumlah = await kontrol.count();

            expect(jumlah).toBeGreaterThanOrEqual(2);

            for (let i = 0; i < jumlah; i += 1) {
                const kotak = await kontrol.nth(i).boundingBox();

                expect(kotak, 'kontrol harus punya bounding box').not.toBeNull();
                expect(kotak?.height ?? 0).toBeGreaterThanOrEqual(44);
            }

            await expect(page.getByLabel('Id invoice')).toBeVisible();
            await expect(page.getByLabel('Metode pembayaran')).toBeVisible();

            const status = page.locator('[data-slot="pesanan-status"]');

            await expect(status.locator('svg')).toHaveCount(1);
            await expect(status).toHaveText('Menunggu pembayaran');

            await page.locator('[data-slot="payment-invoice-id"]').focus();

            await page.keyboard.press('Tab');

            const fokus = page.locator(
                '[data-slot="metode-pembayaran"] [role="combobox"]:focus',
            );

            await expect(fokus).toBeVisible();
            expect(await fokus.evaluate((el) => el.matches(':focus-visible'))).toBe(true);
            expect(
                await fokus.evaluate((el) => getComputedStyle(el).boxShadow),
            ).not.toBe('none');

            await expectNoA11yViolations(page);
        }
    });
});

test.describe('F06 AC-10: the title stays generic and nothing leaks into the URL', () => {
    test('title is Pembayaran, URL has no query, and the toast stays generic', async ({
        page,
    }) => {
        await masukPalsu(page);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.unroute('**/api/v1/**');
            await pasangMock(page);

            await bukaLayar(page);

            await expect(page).toHaveTitle('Pembayaran');

            const url = new URL(page.url());

            expect(url.pathname).toBe('/pembayaran/42');
            expect(url.search).toBe('');

            await page.locator('[data-slot="payment-invoice-id"]').fill('7');
            await pilihMetode(page, /BCA Virtual Account/);
            await page.locator('[data-slot="payment-submit"]').click();

            const toast = page.locator('[data-sonner-toast]');

            await expect(toast).toBeVisible();
            await expect(toast).toContainText('Pembayaran berhasil dimulai.');

            for (const kata of ['Amoxicillin', 'dosis', 'diagnosa', 'resep', 'Sari Dewi']) {
                await expect(toast).not.toContainText(kata);
            }

            await expect(page).toHaveTitle('Pembayaran');
            expect(new URL(page.url()).search).toBe('');
        }
    });
});
