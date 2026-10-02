import { mkdirSync } from 'node:fs';
import { expect, test, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F04 (profil dokter dan kepercayaan) mocked end to end at 390x844 and 1280x900.
 *
 * ## Scope: the SIAP slice, and only it
 *
 * F04's review criteria (AC-4, AC-5, AC-6, AC-9) are `[TERBLOKIR backend ulasan]`:
 * `GET /dokter/{id}/ulasan` does not exist, the stored `rating_rata_rata`/`jumlah_ulasan`
 * aggregates are never recomputed from `ulasan_dokter` (F04 blocker #5), and there is no
 * write path. They are `test.fixme` below and never faked - a passing assertion against a
 * mocked review payload would publish a contract the API does not have. In fact this spec
 * asserts the opposite: no request whose path contains `ulasan` is ever made.
 *
 * ## Why every call is mocked
 *
 * Every criterion needs a response the live seed cannot produce on demand: a doctor with a
 * two-day schedule, an empty weekly schedule, a 404 detail, a 500 detail, a profile whose
 * bio/education/faskes are all null, and an offline transition. The reviews fixture word
 * ("Amoxicillin 500 mg") must never reach a real server, which mocking guarantees.
 *
 * ## The session for the one click-through
 *
 * AC-1 taps `Pesan jadwal` -> `/booking/5`, and `/booking/:dokterId` sits behind
 * `RequireAuth`. An init script seeds the token pair (`sessionStorage`, the storage
 * `lib/token.ts` uses) so the criterion measures the CTA's own destination rather than the
 * login redirect; every API call is still intercepted.
 */

const VIEWPORTS = [
    { nama: 'mobile', width: 390, height: 844 },
    { nama: 'desktop', width: 1280, height: 900 },
] as const;

type Vp = (typeof VIEWPORTS)[number];

mkdirSync('ux/refs/f04', { recursive: true });

const HARI_SINGKAT = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const BULAN_SINGKAT = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'Mei',
    'Jun',
    'Jul',
    'Agu',
    'Sep',
    'Okt',
    'Nov',
    'Des',
];

function tanggalOffset(offset: number): string {
    const sekarang = new Date();
    const tanggal = new Date(
        sekarang.getFullYear(),
        sekarang.getMonth(),
        sekarang.getDate() + offset,
    );

    const tahun = String(tanggal.getFullYear()).padStart(4, '0');
    const bulan = String(tanggal.getMonth() + 1).padStart(2, '0');
    const hari = String(tanggal.getDate()).padStart(2, '0');

    return `${tahun}-${bulan}-${hari}`;
}

function hariOffset(offset: number): number {
    const sekarang = new Date();

    return new Date(
        sekarang.getFullYear(),
        sekarang.getMonth(),
        sekarang.getDate() + offset,
    ).getDay();
}

/** Mirrors `labelHariTanggal`'s public copy: `Sen, 5 Okt`. */
function labelTanggal(tanggal: string): string {
    const bagian = tanggal.split('-');
    const date = new Date(
        Number(bagian[0]),
        Number(bagian[1]) - 1,
        Number(bagian[2]),
    );

    return `${HARI_SINGKAT[date.getDay()] ?? ''}, ${String(date.getDate())} ${
        BULAN_SINGKAT[date.getMonth()] ?? ''
    }`;
}

const TANGGAL_HARI_INI = tanggalOffset(0);
const TANGGAL_BESOK = tanggalOffset(1);
const HARI_INI = hariOffset(0);
const BESOK = hariOffset(1);

const NAMA_DOKTER = 'dr. Rina Wulandari, Sp.A';

const USER = {
    id: 1,
    uuid: '00000000-0000-4000-8000-000000000004',
    nama_lengkap: 'Pasien Uji F04',
    no_telepon: '081200000004',
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

/**
 * The complete profile: identity, a primary specialisation, one education row, a primary
 * facility plus an inactive second one, and both fees. Deliberately contains no `nomor_str`,
 * `file_*` or contact field - the resource does not publish them - and AC-3 injects those
 * keys anyway to prove the component ignores them.
 */
const DOKTER: Record<string, unknown> = {
    id: 5,
    nama_lengkap: NAMA_DOKTER,
    foto_profil: null,
    tipe: 'dokter_spesialis',
    pengalaman_tahun: 12,
    bio: 'Menangani kesehatan anak, imunisasi, dan tumbuh kembang.',
    biaya_konsultasi_online: '85000.00',
    biaya_luar_jam: '120000.00',
    durasi_default_menit: 15,
    rating_rata_rata: '4.90',
    jumlah_ulasan: 128,
    jumlah_konsultasi: 340,
    tersedia_telemedisin: true,
    status_verifikasi: 'terverifikasi',
    spesialisasi: [
        {
            id: 3,
            kode: 'SP.A',
            nama: 'Spesialis Anak',
            tipe: 'spesialis',
            is_utama: true,
        },
    ],
    pendidikan: [
        {
            id: 1,
            jenjang: 'S1 Kedokteran',
            institusi: 'Universitas Indonesia',
            tahun_lulus: 2012,
        },
    ],
    faskes: [
        {
            faskes_id: 1,
            is_utama: true,
            status_aktif: true,
            kode_faskes: 'FASKES-001',
            nama: 'Klinik Sehat Bunda',
            tipe: 'klinik',
            kelas_rs: null,
            alamat: 'Jl. Melati No. 3, Jakarta Selatan',
        },
        {
            faskes_id: 2,
            is_utama: false,
            status_aktif: false,
            kode_faskes: 'FASKES-002',
            nama: 'RS Harapan Ibu',
            tipe: 'rumah_sakit',
            kelas_rs: 'B',
            alamat: 'Jl. Kenanga No. 10, Depok',
        },
    ],
    dibuat_at: '2026-01-01T00:00:00.000000Z',
};

const RAHASIA = {
    nomor_str: 'STR-999-RAHASIA',
    nomor_sip: 'SIP-999-RAHASIA',
    file_str_url: 'https://files.example/str-rahasia.pdf',
    file_sip_url: 'https://files.example/sip-rahasia.pdf',
    no_telepon: '081299999999',
    email: 'rahasia@example.test',
};

function jendela(
    jadwalId: number,
    hari: number,
    mulai: string,
    selesai: string,
): Record<string, unknown> {
    return {
        jadwal_id: jadwalId,
        hari,
        tipe_layanan: 'online',
        faskes_id: null,
        jam_mulai: mulai,
        jam_selesai: selesai,
        durasi_slot_menit: 15,
        kuota_per_sesi: 8,
    };
}

/** Today's and tomorrow's weekday carry one window each, so exactly two rows render. */
function jadwalDefault(): Record<string, Array<Record<string, unknown>>> {
    const map: Record<string, Array<Record<string, unknown>>> = {
        '0': [],
        '1': [],
        '2': [],
        '3': [],
        '4': [],
        '5': [],
        '6': [],
    };

    map[String(HARI_INI)] = [jendela(7, HARI_INI, '09:00:00', '12:00:00')];
    map[String(BESOK)] = [jendela(8, BESOK, '13:00:00', '16:00:00')];

    return map;
}

/** An empty weekly schedule: every weekday key present, every value empty. */
function jadwalKosong(): Record<string, Array<Record<string, unknown>>> {
    return { '0': [], '1': [], '2': [], '3': [], '4': [], '5': [], '6': [] };
}

const SLOT = {
    jadwal_id: 7,
    jam_mulai: '09:00:00',
    jam_selesai: '09:15:00',
    tipe_layanan: 'online',
    faskes_id: null,
    tersedia: true,
    alasan: null,
};

type Permintaan = { method: string; path: string; url: string };

type State = {
    detailStatus: number;
    detail: Record<string, unknown>;
    jadwal: Record<string, Array<Record<string, unknown>>>;
    slotStatus: number;
    slots: Array<Record<string, unknown>>;
    permintaan: Permintaan[];
};

type OpsiMock = {
    detailStatus?: number;
    detail?: Record<string, unknown>;
    jadwal?: Record<string, Array<Record<string, unknown>>>;
    slotStatus?: number;
    slots?: Array<Record<string, unknown>>;
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
        from: total === 0 ? null : 1,
        to: total === 0 ? null : total,
    };
}

async function pasangMock(page: Page, opsi: OpsiMock = {}): Promise<State> {
    const state: State = {
        detailStatus: opsi.detailStatus ?? 200,
        detail: opsi.detail ?? { ...DOKTER },
        jadwal: opsi.jadwal ?? jadwalDefault(),
        slotStatus: opsi.slotStatus ?? 200,
        slots: opsi.slots ?? [{ ...SLOT }],
        permintaan: [],
    };

    await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;
        const method = request.method();

        state.permintaan.push({ method, path, url: request.url() });

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

        if (path === '/api/v1/pdp/persetujuan') {
            return balasJson(route, 200, {
                success: true,
                message: 'Daftar persetujuan PDP berhasil dimuat.',
                data: {
                    persetujuan: [
                        'syarat_ketentuan',
                        'kebijakan_privasi',
                        'berbagi_data_medis',
                        'pemasaran',
                        'komunikasi_tindak_lanjut',
                    ].map((jenis) => ({
                        jenis,
                        efektif: true,
                        versi_dokumen: 'v01',
                        disetujui_at: '2026-01-01T00:00:00.000000Z',
                        ip_address: null,
                    })),
                },
                meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: 5,
                    total: 5,
                    from: 1,
                    to: 5,
                },
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

        if (/^\/api\/v1\/dokter\/[^/]+$/.test(path) && method === 'GET') {
            if (state.detailStatus !== 200) {
                return balasJson(route, state.detailStatus, {
                    success: false,
                    message:
                        state.detailStatus === 404
                            ? 'Resource not found.'
                            : 'Terjadi kesalahan pada server.',
                    errors: {},
                });
            }

            return balasJson(route, 200, {
                success: true,
                message: 'Detail dokter berhasil dimuat.',
                data: { dokter: state.detail },
            });
        }

        if (/^\/api\/v1\/dokter\/[^/]+\/jadwal$/.test(path)) {
            return balasJson(route, 200, {
                success: true,
                message: 'Jadwal dokter berhasil dimuat.',
                data: { jadwal: state.jadwal },
            });
        }

        if (/^\/api\/v1\/dokter\/[^/]+\/slot$/.test(path)) {
            if (state.slotStatus !== 200) {
                return balasJson(route, state.slotStatus, {
                    success: false,
                    message: 'Terjadi kesalahan pada server.',
                    errors: {},
                });
            }

            return balasJson(route, 200, {
                success: true,
                message: 'Ketersediaan jam berhasil dimuat.',
                data: {
                    tanggal: url.searchParams.get('tanggal'),
                    timezone: 'Asia/Jakarta',
                    slots: state.slots,
                },
                meta: meta(state.slots.length, state.slots.length),
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
        sessionStorage.setItem('sehatly.access_token', 'token-uji-f04');
        sessionStorage.setItem('sehatly.refresh_token', 'refresh-uji-f04');
    });
}

async function bukaProfil(page: Page, opsi: OpsiMock = {}): Promise<State> {
    const state = await pasangMock(page, opsi);

    await page.goto('/dokter/5');

    await expect(page.getByRole('heading', { name: NAMA_DOKTER })).toBeVisible({
        timeout: 15_000,
    });

    return state;
}

async function simpanGambar(page: Page, vp: Vp, keadaan: string): Promise<void> {
    await page.screenshot({
        path: `ux/refs/f04/f04-${keadaan}-${vp.nama}.png`,
        fullPage: true,
        animations: 'disabled',
    });
}

/**
 * Blocked, not faked. `GET /dokter/{id}/ulasan` has no route, the stored aggregates are
 * never recomputed from `ulasan_dokter` (F04 blocker #5), and no write path exists, so all
 * four criteria need a backend change first.
 */
test.fixme('f04-ac4-ulasan-render [TERBLOKIR backend ulasan]', async () => {});
test.fixme('f04-ac5-rating-kecil [TERBLOKIR backend ulasan]', async () => {});
test.fixme('f04-ac6-kebijakan [TERBLOKIR backend ulasan]', async () => {});
test.fixme('f04-ac9-ulasan-500 [TERBLOKIR backend ulasan]', async () => {});

for (const vp of VIEWPORTS) {
    test.describe(`F04 profil dokter ${vp.nama} ${vp.width}x${vp.height}`, () => {
        test.use({
            viewport: { width: vp.width, height: vp.height },
            timezoneId: 'Asia/Jakarta',
        });

        test.setTimeout(60_000);

        test('f04-ac1-hero-cta', async ({ page }) => {
            await masukPalsu(page);

            const state = await bukaProfil(page);

            await expect(
                page.getByRole('heading', { name: 'Profil dokter' }),
            ).toBeVisible();

            await expect(page.getByText(NAMA_DOKTER)).toBeVisible();
            await expect(page.getByText('Terverifikasi', { exact: true })).toBeVisible();
            await expect(page.getByText('Spesialis Anak').first()).toBeVisible();
            await expect(page.getByText('12 tahun pengalaman')).toBeVisible();
            await expect(page.getByText('340 konsultasi')).toBeVisible();

            // The whole decision set is inside 390x844 without scrolling (AC-1).
            await expect(page.getByText('Konsultasi online')).toBeInViewport();
            await expect(page.getByText(/Rp\s?85\.000/)).toBeVisible();
            await expect(page.getByText(/Rp\s?120\.000/)).toBeVisible();
            await expect(page.getByText('15 menit')).toBeVisible();
            await expect(page.getByText('Klinik Sehat Bunda').first()).toBeInViewport();

            const cta = page.locator('[data-slot="cta-pesan-jadwal"]');

            await expect(cta).toBeVisible();
            await expect(cta).toBeInViewport();
            await expect(cta).toHaveAttribute('href', '/booking/5');

            await expect(
                page.locator('[data-slot="cta-jadwal-lengkap"]'),
            ).toBeInViewport();

            await simpanGambar(page, vp, 'profil');

            await expectNoA11yViolations(page);

            // The reviews block is not built: no `/ulasan` request may leave the page.
            expect(state.permintaan.some((row) => row.path.includes('ulasan'))).toBe(
                false,
            );

            await cta.click();

            await page.waitForURL((url) => url.pathname === '/booking/5', {
                timeout: 15_000,
            });

            await expect(
                page.getByRole('heading', {
                    name: /^Booking dengan dr\. Rina Wulandari/,
                }),
            ).toBeVisible({ timeout: 15_000 });
        });

        test('f04-ac2-jadwal', async ({ page }) => {
            const state = await bukaProfil(page);

            const baris = page.locator('[data-slot="schedule-day"]');

            await expect(baris).toHaveCount(2);

            await expect(baris.nth(0)).toContainText(labelTanggal(TANGGAL_HARI_INI));
            await expect(baris.nth(0)).toContainText('09.00–12.00 WIB');
            await expect(baris.nth(1)).toContainText(labelTanggal(TANGGAL_BESOK));
            await expect(baris.nth(1)).toContainText('13.00–16.00 WIB');

            await expect(page.getByText('Slot terdekat:')).toBeVisible();
            await expect(page.locator('[data-slot="slot-terdekat"]')).toContainText(
                'hari ini 09.00 WIB',
            );

            expect(
                state.permintaan.some((row) => /\/jadwal$/.test(row.path)),
            ).toBe(true);

            const slot = state.permintaan.filter((row) => /\/slot$/.test(row.path));

            expect(slot.length).toBe(1);
            expect(new URL(slot[0]?.url ?? '').searchParams.get('tanggal')).toBe(
                TANGGAL_HARI_INI,
            );

            await expectNoA11yViolations(page);

            // Empty schedule: every weekday clears; the CTA and recovery actions stay.
            state.jadwal = jadwalKosong();

            await page.reload();

            await expect(page.getByText('Belum ada jadwal tersedia.')).toBeVisible();
            await expect(
                page.getByRole('link', { name: 'Lihat profil lain' }),
            ).toBeVisible();
            await expect(
                page.getByRole('link', { name: 'Hubungi bantuan' }),
            ).toBeVisible();
            await expect(page.locator('[data-slot="cta-pesan-jadwal"]')).toBeVisible();

            const slotSebelumKosong = state.permintaan.filter((row) =>
                /\/slot$/.test(row.path),
            ).length;

            expect(slotSebelumKosong).toBe(slot.length);

            await simpanGambar(page, vp, 'jadwal-kosong');

            await expectNoA11yViolations(page);
        });

        test('f04-ac3-kredensial', async ({ page }) => {
            await bukaProfil(page, {
                detail: { ...DOKTER, ...RAHASIA },
            });

            await page.locator('[data-slot="credential-trigger"]').click();

            await expect(page.getByText('Status: Terverifikasi Sehatly')).toBeVisible();
            await expect(
                page.getByText(
                    'Kami memverifikasi STR dan SIP sebelum profil ini tayang.',
                ),
            ).toBeVisible();
            await expect(page.getByText('Spesialis Anak').first()).toBeVisible();
            await expect(page.getByText('RS Harapan Ibu')).toBeVisible();

            // The registration numbers and the document URLs are not rendered, and the
            // component never claims more than `Terverifikasi` plus what was checked.
            const html = await page.content();

            for (const rahasia of [
                RAHASIA.nomor_str,
                RAHASIA.nomor_sip,
                RAHASIA.file_str_url,
                RAHASIA.file_sip_url,
                RAHASIA.no_telepon,
                RAHASIA.email,
            ]) {
                expect(html).not.toContain(rahasia);
            }

            await expect(
                page.getByRole('link', { name: /Konsil Kesehatan/ }),
            ).toHaveCount(0);
            await expect(page.getByText('Dokter ini pasti aman')).toHaveCount(0);

            await simpanGambar(page, vp, 'kredensial');

            await expectNoA11yViolations(page);
        });

        test('f04-ac7-404-500', async ({ page }) => {
            const state = await pasangMock(page, { detailStatus: 404 });

            await page.goto('/dokter/5');

            const tidakDitemukan = page.locator('[data-slot="not-found-state"]');

            await expect(tidakDitemukan).toBeVisible();
            await expect(tidakDitemukan.getByText('Profil tidak tersedia')).toBeVisible();
            await expect(
                page.getByRole('link', { name: 'Kembali ke direktori' }),
            ).toBeVisible();
            await expect(page.getByRole('button', { name: 'Coba lagi' })).toHaveCount(0);

            state.detailStatus = 500;

            await page.reload();

            const galat = page.locator('[data-slot="error-state"]');

            await expect(galat).toBeVisible();
            await expect(
                galat.getByRole('button', { name: 'Coba lagi' }),
            ).toBeVisible();

            state.detailStatus = 200;

            await galat.getByRole('button', { name: 'Coba lagi' }).click();

            await expect(page.getByRole('heading', { name: NAMA_DOKTER })).toBeVisible({
                timeout: 15_000,
            });

            await expectNoA11yViolations(page);
        });

        test('f04-ac8-parsial', async ({ page }) => {
            await bukaProfil(page, {
                detail: {
                    ...DOKTER,
                    bio: null,
                    spesialisasi: [],
                    pendidikan: [],
                    faskes: [],
                },
            });

            await expect(page.getByText('Bio belum diisi')).toBeVisible();

            await page.locator('[data-slot="credential-trigger"]').click();

            await expect(
                page.getByText('Riwayat pendidikan belum dicatat'),
            ).toBeVisible();
            await expect(page.getByText('Belum ada afiliasi').first()).toBeVisible();
            await expect(
                page.getByText('Spesialisasi belum dicatat').first(),
            ).toBeVisible();

            await expectNoA11yViolations(page);
        });

        test('f04-ac10-offline', async ({ page, context }) => {
            const state = await bukaProfil(page);

            await expect(page.locator('[data-slot="slot-terdekat"]')).toBeVisible();

            const sebelum = state.permintaan.length;

            await context.setOffline(true);

            await expect(page.getByTestId('offline-banner')).toBeVisible();

            const cta = page.locator('[data-slot="cta-pesan-jadwal"]');

            await expect(cta).toHaveAttribute('aria-disabled', 'true');
            await expect(cta).toHaveAttribute(
                'aria-describedby',
                'dokter-alasan-offline',
            );
            await expect(
                page.getByText(
                    'Anda sedang offline. Jadwal dan pemesanan tidak dikirim sampai koneksi kembali.',
                ),
            ).toBeVisible();

            // Cached data stays readable while offline.
            await expect(
                page.getByRole('heading', { name: NAMA_DOKTER }),
            ).toBeVisible();
            await expect(page.getByText(/Rp\s?85\.000/)).toBeVisible();

            await cta.click({ force: true });

            await page.waitForTimeout(500);

            expect(new URL(page.url()).pathname).toBe('/dokter/5');
            expect(state.permintaan.length).toBe(sebelum);

            await simpanGambar(page, vp, 'offline');

            await context.setOffline(false);
        });

        test('f04-ac11-privasi', async ({ page }) => {
            const state = await bukaProfil(page, {
                detail: {
                    ...DOKTER,
                    ulasan_contoh: 'Amoxicillin 500 mg',
                    ...RAHASIA,
                },
            });

            expect(await page.title()).toBe(`${NAMA_DOKTER} | Sehatly`);

            const alamat = page.url();

            expect(alamat).not.toContain('Amoxicillin');
            expect(alamat).not.toContain('STR-999');
            expect(alamat).not.toContain('0812');

            const label = await page.evaluate(() =>
                Array.from(document.querySelectorAll('[aria-label]')).map(
                    (element) => element.getAttribute('aria-label') ?? '',
                ),
            );

            const gabunganLabel = label.join(' ').toLowerCase();

            for (const kata of [
                'amoxicillin',
                'str-999',
                'sip-999',
                '0812',
                'rahasia@example.test',
                'files.example',
            ]) {
                expect(gabunganLabel).not.toContain(kata);
            }

            const html = await page.content();

            for (const kata of [
                'Amoxicillin',
                'STR-999',
                'SIP-999',
                RAHASIA.file_str_url,
                RAHASIA.no_telepon,
                RAHASIA.email,
            ]) {
                expect(html).not.toContain(kata);
            }

            await expect(page.locator('[data-sonner-toast]')).toHaveCount(0);

            expect(state.permintaan.some((row) => row.path.includes('ulasan'))).toBe(
                false,
            );

            await expectNoA11yViolations(page);
        });

        test('f04-ac12-a11y', async ({ page }) => {
            await bukaProfil(page);

            for (const ukuran of [
                { width: 390, height: 844 },
                { width: 768, height: 1024 },
                { width: 1280, height: 900 },
            ]) {
                await page.setViewportSize(ukuran);

                await expectNoA11yViolations(page);

                const cta = await page
                    .locator('[data-slot="cta-pesan-jadwal"]')
                    .boundingBox();
                const kembali = await page
                    .getByRole('link', { name: 'Kembali ke direktori' })
                    .boundingBox();

                expect(cta?.height ?? 0).toBeGreaterThanOrEqual(44);
                expect(kembali?.height ?? 0).toBeGreaterThanOrEqual(44);

                const biaya = page.getByText('Konsultasi online');

                const gaya = await biaya.evaluate((element) => {
                    const computed = window.getComputedStyle(element);

                    return {
                        fontSize: Number.parseFloat(computed.fontSize),
                        kelas: element.className,
                        terpotong: element.scrollWidth > element.clientWidth + 1,
                    };
                });

                expect(gaya.fontSize).toBeGreaterThanOrEqual(16);
                expect(gaya.kelas).not.toMatch(/truncate|line-clamp/);
                expect(gaya.terpotong).toBe(false);
            }

            await page.setViewportSize({ width: 390, height: 844 });

            const fasilitas = await page
                .locator('[data-slot="facility-trigger"]')
                .boundingBox();

            expect(fasilitas?.height ?? 0).toBeGreaterThanOrEqual(44);

            await page.locator('[data-slot="credential-trigger"]').click();

            await expect(
                page.getByText('Status: Terverifikasi Sehatly'),
            ).toBeVisible();

            await expectNoA11yViolations(page);

            const kredensial = await page
                .locator('[data-slot="credential-trigger"]')
                .boundingBox();

            expect(kredensial?.height ?? 0).toBeGreaterThanOrEqual(44);
        });
    });
}
