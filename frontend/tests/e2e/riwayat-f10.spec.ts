import { expect, test, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F10's acceptance criteria, mocked end to end at 390x844 and 1280x900.
 *
 * Every request is intercepted (`page.route`) and every fixture is invented, so the
 * assertions are identical on any machine and no real health data is involved. The
 * session is seeded through an init script, the same style `booking-f05.spec.ts` uses.
 *
 * The fixtures exercise the contract's own edge cases: a prescription whose
 * `rekam_medis_id` is `null` (non-clickable), a `ran` chain with the schema's
 * permitted tie at the top version, and an access log whose response carries an
 * actor-name field the client must ignore.
 */

const VIEWPORTS = [
    { nama: 'mobile', width: 390, height: 844 },
    { nama: 'desktop', width: 1280, height: 900 },
] as const;

const KELUHAN =
    'Batuk kering tiga hari disertai demam ringan pada malam hari dan nyeri tenggorokan.';
const DIAGNOSIS = 'Demam berdarah dengue';
const DIAGNOSIS_INDEX = 'ISPA (infeksi saluran pernapasan akut)';
const NAMA_DOKTER = 'dr. Rina Wulandari, Sp.PD';
const NAMA_PASIEN = 'Siti Aminah';
const NAMA_PELAKU_RAHASIA = 'Budi Rahasia';

const USER = {
    id: 5,
    uuid: '00000000-0000-4000-8000-000000000005',
    nama_lengkap: NAMA_PASIEN,
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

const REKAM_INDEX = {
    id: 42,
    uuid: '00000000-0000-4000-8000-000000000042',
    tanggal_periksa: '2026-10-04T02:30:00.000000Z',
    keluhan_utama: KELUHAN,
    diagnosis_kerja: DIAGNOSIS_INDEX,
    status_dokumen: 'final',
    versi: 2,
    adalah_versi_terkini: true,
    dokter: { id: 9, nama_lengkap: NAMA_DOKTER },
};

const RESEP_TANPA_REKAM_MEDIS = {
    id: 139,
    nomor_resep: 'RSP-2026-0139',
    konsultasi_id: 7,
    rekam_medis_id: null,
    pasien_id: 5,
    dokter_id: 9,
    apotek_id: null,
    tipe: 'digital',
    status: 'kedaluwarsa',
    catatan_dokter: null,
    tanggal_resep: '2026-10-02T03:00:00.000000Z',
    berlaku_sampai: '2026-10-05',
    is_kedaluwarsa: true,
    terminal: true,
    is_iter: false,
    jumlah_iter: 0,
    qr_token: 'qr-uji-f10',
    dibuat_at: '2026-10-02T03:00:00.000000Z',
    items: [],
};

const SURAT = {
    id: 12,
    nomor_surat: 'SK/2026/000123',
    konsultasi_id: 7,
    tipe: 'surat_sakit',
    pasien_id: 5,
    dokter_id: 9,
    tanggal_mulai: '2026-10-01',
    tanggal_selesai: '2026-10-03',
    jumlah_hari: 3,
    isi: null,
    qr_token: 'qr-surat-uji',
    file_url: null,
    dibuat_at: '2026-10-01T04:00:00.000000Z',
    dokter: { id: 9, nama_lengkap: NAMA_DOKTER },
};

const BOOKING = {
    id: 21,
    nomor_booking: 'BK20261001AAA001',
    pasien_id: 5,
    anggota_keluarga_id: null,
    dokter_id: 9,
    jadwal_id: null,
    faskes_id: null,
    tipe_layanan: 'chat',
    tanggal_kunjungan: '2026-10-01',
    slot_mulai: '09:00:00',
    slot_selesai: '09:15:00',
    nomor_antrian: null,
    keluhan: 'Demam dan batuk sejak tiga hari.',
    lampiran_keluhan: null,
    is_rujukan: false,
    is_konsultasi_lanjutan: false,
    status: 'selesai',
    dibatalkan_oleh: null,
    alasan_pembatalan: null,
    dibuat_oleh_user_id: 5,
    dibuat_at: '2026-09-30T02:00:00.000000Z',
};

type AksesRow = {
    waktu: string;
    peran: string;
    tujuan_akses: string;
    /** Not published by the API; present here to prove the client ignores it. */
    nama_pengakses: string;
};

const AKSES_PAGE_1: AksesRow[] = [
    {
        waktu: '2026-10-04T02:31:00.000000Z',
        peran: 'dokter',
        tujuan_akses: 'perawatan',
        nama_pengakses: NAMA_PELAKU_RAHASIA,
    },
    {
        waktu: '2026-10-04T02:30:30.000000Z',
        peran: 'pasien',
        tujuan_akses: 'pasien_sendiri',
        nama_pengakses: NAMA_PASIEN,
    },
];

const AKSES_PAGE_2: AksesRow[] = [
    {
        waktu: '2026-10-02T02:05:00.000000Z',
        peran: 'perawat',
        tujuan_akses: 'klaim',
        nama_pengakses: NAMA_PELAKU_RAHASIA,
    },
];

function rantai(
    id: number,
    versi: number,
    status: string,
    keluhan: string,
    diagnosis: string,
    dibuat: string,
) {
    return {
        id,
        uuid: `00000000-0000-4000-8000-0000000000${id}`,
        versi,
        status_dokumen: status,
        keluhan_utama: keluhan,
        diagnosis_kerja: diagnosis,
        ditandatangani_at: dibuat,
        dibuat_at: dibuat,
    };
}

/**
 * The chain carries the schema's permitted TIE at the top version: three entries with
 * versions 1, 2, 2. `RekamMedisResource` derives `adalah_versi_terkini` as
 * `max(versi) === versi`, so a v2 record is current and the header legitimately shows
 * "v2", "dari 3 versi" and "terkini" at once - which is the exact combination AC-8
 * asks for. A strictly-increasing chain would make that combination impossible.
 */
function detailRekam(id: number): Record<string, unknown> {
    const dasar = {
        id,
        uuid: `00000000-0000-4000-8000-0000000000${id}`,
        pasien_id: 5,
        faskes_id: null,
        dokter_id: 9,
        konsultasi_id: 7,
        satusehat_encounter_id: null,
        tipe_kunjungan: 'telemedisin',
        tanggal_periksa: '2026-10-04T02:30:00.000000Z',
        keluhan_utama: KELUHAN,
        riwayat_penyakit_sekarang:
            'Batuk berdahak sejak tiga hari, demam naik-turun, tidak ada sesak napas.',
        riwayat_penyakit_dahulu: 'Tidak ada riwayat penyakit kronis.',
        riwayat_keluarga: 'Tidak ada riwayat keluarga yang relevan.',
        riwayat_psikososial: 'Tinggal bersama keluarga, tidak merokok.',
        hasil_pemeriksaan_fisik:
            'Suhu 38,1 C; faring hiperemis; auskultasi paru vesikuler; tidak ada pembesaran kelenjar.',
        subjektif:
            'Pasien mengeluh batuk kering tiga hari disertai demam ringan pada malam hari.',
        objektif: 'Keadaan umum baik, compos mentis, tanda vital dalam batas wajar.',
        asesmen:
            'Kemungkinan infeksi saluran pernapasan atas; trombosit perlu dipantau.',
        plan: 'Pemeriksaan darah lengkap, istirahat cukup, cairan adekuat, parasetamol bila demam.',
        diagnosis_kerja: DIAGNOSIS,
        instruksi_tindak_lanjut:
            'Istirahat, cairan cukup, parasetamol bila demam. Kontrol bila keluhan memberat.',
        status_tindak_lanjut: 'kontrol',
        jadwal_kontrol: '2026-10-11',
        status_dokumen: 'final',
        versi: 2,
        ditandatangani_at: '2026-10-04T02:45:00.000000Z',
        dibuat_at: '2026-10-04T02:30:00.000000Z',
        diubah_at: '2026-10-04T02:45:00.000000Z',
        adalah_versi_terkini: true,
        pasien: { id: 5, nik: '3174••••••••0042', nama_lengkap: NAMA_PASIEN },
        dokter: { id: 9, nama_lengkap: NAMA_DOKTER },
        diagnosa: [],
        tindakan: [],
        lampiran: [],
        persetujuan: [],
    };

    if (id === 44) {
        return {
            ...dasar,
            versi: 1,
            adalah_versi_terkini: true,
            ran: [
                rantai(
                    44,
                    1,
                    'final',
                    'Kontrol tekanan darah rutin.',
                    'Hipertensi terkontrol',
                    '2026-09-20T02:00:00.000000Z',
                ),
            ],
        };
    }

    if (id === 40) {
        return {
            ...dasar,
            versi: 1,
            adalah_versi_terkini: false,
            ran: [
                rantai(
                    40,
                    1,
                    'diamendemen',
                    'Batuk kering sejak dua hari.',
                    'ISPA',
                    '2026-10-02T02:00:00.000000Z',
                ),
            ],
        };
    }

    return {
        ...dasar,
        ran: [
            rantai(
                40,
                1,
                'diamendemen',
                'Batuk kering sejak dua hari.',
                'ISPA',
                '2026-10-02T02:00:00.000000Z',
            ),
            rantai(42, 2, 'final', KELUHAN, DIAGNOSIS, '2026-10-04T02:30:00.000000Z'),
            rantai(43, 2, 'final', KELUHAN, DIAGNOSIS, '2026-10-04T02:47:00.000000Z'),
        ],
    };
}

type OpsiMock = {
    index?: typeof REKAM_INDEX[];
    resep?: typeof RESEP_TANPA_REKAM_MEDIS[];
    surat?: typeof SURAT[];
    booking?: typeof BOOKING[];
    indexStatus?: number;
    resepStatus?: number;
    suratStatus?: number;
    bookingStatus?: number;
    detailStatus?: number;
    aksesStatus?: number;
    akses?: AksesRow[] | 'empty';
    delayMs?: number;
};

type State = {
    index: typeof REKAM_INDEX[];
    resep: typeof RESEP_TANPA_REKAM_MEDIS[];
    surat: typeof SURAT[];
    booking: typeof BOOKING[];
    indexStatus: number;
    resepStatus: number;
    suratStatus: number;
    bookingStatus: number;
    detailStatus: number;
    aksesStatus: number;
    akses: AksesRow[] | 'empty';
    delayMs: number;
    counts: Record<string, number>;
    detailRequests: string[];
    aksesUrls: string[];
    resepUrls: string[];
};

function meta(
    total: number,
    perPage: number,
    page = 1,
    lastPage = 1,
): Record<string, unknown> {
    return {
        current_page: page,
        last_page: lastPage,
        per_page: perPage,
        total,
        from: total === 0 ? null : (page - 1) * perPage + 1,
        to: total === 0 ? null : Math.min(page * perPage, total),
    };
}

async function balasJson(route: Route, status: number, body: unknown): Promise<void> {
    await route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

function galat(status: number, message: string): Record<string, unknown> {
    return { success: false, message, errors: {} };
}

async function pasangMock(page: Page, opsi: OpsiMock = {}): Promise<State> {
    const state: State = {
        index: opsi.index ?? [REKAM_INDEX],
        resep: opsi.resep ?? [RESEP_TANPA_REKAM_MEDIS],
        surat: opsi.surat ?? [SURAT],
        booking: opsi.booking ?? [BOOKING],
        indexStatus: opsi.indexStatus ?? 200,
        resepStatus: opsi.resepStatus ?? 200,
        suratStatus: opsi.suratStatus ?? 200,
        bookingStatus: opsi.bookingStatus ?? 200,
        detailStatus: opsi.detailStatus ?? 200,
        aksesStatus: opsi.aksesStatus ?? 200,
        akses: opsi.akses ?? AKSES_PAGE_1,
        delayMs: opsi.delayMs ?? 0,
        counts: {},
        detailRequests: [],
        aksesUrls: [],
        resepUrls: [],
    };

    await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;
        const method = request.method();

        state.counts[`${method} ${path}`] =
            (state.counts[`${method} ${path}`] ?? 0) + 1;

        if (state.delayMs > 0) {
            await new Promise((resolve) => setTimeout(resolve, state.delayMs));
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

        if (path === '/api/v1/rekam-medis' && method === 'GET') {
            if (state.indexStatus !== 200) {
                return balasJson(
                    route,
                    state.indexStatus,
                    galat(state.indexStatus, 'Terjadi kesalahan pada server.'),
                );
            }

            const halaman = Number(url.searchParams.get('page') ?? '1');
            const rows = halaman > 1 ? [] : state.index;

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar rekam medis berhasil dimuat.',
                data: { rekam_medis: rows },
                meta: meta(
                    state.index.length + 50,
                    50,
                    halaman,
                    2,
                ),
            });
        }

        const akses = /^\/api\/v1\/rekam-medis\/(\d+)\/akses$/.exec(path);

        if (akses !== null && method === 'GET') {
            state.aksesUrls.push(request.url());

            if (state.aksesStatus !== 200) {
                return balasJson(
                    route,
                    state.aksesStatus,
                    galat(state.aksesStatus, 'Resource not found.'),
                );
            }

            const halaman = Number(url.searchParams.get('page') ?? '1');

            if (state.akses === 'empty') {
                return balasJson(route, 200, {
                    success: true,
                    message: 'Riwayat akses berhasil dimuat.',
                    data: { akses: [] },
                    meta: meta(0, 15),
                });
            }

            const rows = halaman === 1 ? AKSES_PAGE_1 : AKSES_PAGE_2;

            return balasJson(route, 200, {
                success: true,
                message: 'Riwayat akses berhasil dimuat.',
                data: { akses: rows },
                meta: meta(3, 15, halaman, 2),
            });
        }

        const detail = /^\/api\/v1\/rekam-medis\/(\d+)$/.exec(path);

        if (detail !== null && method === 'GET') {
            state.detailRequests.push(path);

            if (state.detailStatus !== 200) {
                return balasJson(
                    route,
                    state.detailStatus,
                    galat(state.detailStatus, 'Resource not found.'),
                );
            }

            return balasJson(route, 200, {
                success: true,
                message: 'Rekam medis berhasil dimuat.',
                data: { rekam_medis: detailRekam(Number(detail[1])) },
            });
        }

        if (path === '/api/v1/pasien/resep' && method === 'GET') {
            state.resepUrls.push(request.url());

            if (state.resepStatus !== 200) {
                return balasJson(
                    route,
                    state.resepStatus,
                    galat(state.resepStatus, 'Terjadi kesalahan pada server.'),
                );
            }

            const status = url.searchParams.get('status');
            const rows =
                status === null
                    ? state.resep
                    : state.resep.filter((row) => row.status === status);

            return balasJson(route, 200, {
                success: true,
                message: 'Riwayat resep berhasil dimuat.',
                data: { resep: rows },
                meta: meta(rows.length, 100),
            });
        }

        if (path === '/api/v1/pasien/surat-keterangan' && method === 'GET') {
            if (state.suratStatus !== 200) {
                return balasJson(
                    route,
                    state.suratStatus,
                    galat(state.suratStatus, 'Terjadi kesalahan pada server.'),
                );
            }

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar surat keterangan berhasil dimuat.',
                data: { surat_keterangan: state.surat },
                meta: meta(state.surat.length, 15),
            });
        }

        if (path === '/api/v1/pasien/booking' && method === 'GET') {
            if (state.bookingStatus !== 200) {
                return balasJson(
                    route,
                    state.bookingStatus,
                    galat(state.bookingStatus, 'Terjadi kesalahan pada server.'),
                );
            }

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar booking berhasil dimuat.',
                data: { booking: state.booking },
                meta: meta(state.booking.length, 15),
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
        sessionStorage.setItem('sehatly.access_token', 'token-uji-f10');
        sessionStorage.setItem('sehatly.refresh_token', 'refresh-uji-f10');
    });
}

async function simpanGambar(
    page: Page,
    vp: { nama: string },
    keadaan: string,
): Promise<void> {
    await page.screenshot({
        path: `ux/refs/f10/f10-${keadaan}-${vp.nama}.png`,
        fullPage: true,
        animations: 'disabled',
    });
}

async function ukurTinggi(
    locator: ReturnType<Page['locator']>,
): Promise<number> {
    const kotak = await locator.boundingBox();

    expect(kotak, 'elemen harus punya bounding box').not.toBeNull();

    return (kotak as { height: number }).height;
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

function assertUrutanHeading(levels: number[]): void {
    let sebelumnya = 0;

    for (const level of levels) {
        expect(
            level,
            `urutan heading melompat dari h${sebelumnya} ke h${level}`,
        ).toBeLessThanOrEqual(sebelumnya + 1);

        sebelumnya = level;
    }
}

for (const vp of VIEWPORTS) {
    test.describe(`F10 riwayat ${vp.nama} ${vp.width}x${vp.height}`, () => {
        test.use({
            viewport: { width: vp.width, height: vp.height },
            timezoneId: 'Asia/Jakarta',
        });

        test('f10-ac1-dua-ketukan-ke-detail', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page);

            await page.goto('/rekam-medis');

            await expect(
                page.locator('[data-slot="riwayat-baris"]').first(),
            ).toBeVisible();

            await simpanGambar(page, vp, 'hub');

            await page.getByRole('link', { name: 'Buka rekam medis' }).first().click();

            await expect(page).toHaveURL(/\/rekam-medis\/42$/);

            const view = page.locator('[data-slot="rekam-medis-view"]');

            await expect(view.getByText(/aksesnya dicatat/)).toBeVisible();

            const keluhan = page.getByRole('heading', { name: 'Keluhan utama' });

            await expect(keluhan).toBeVisible();

            const kotak = await keluhan.boundingBox();

            expect(kotak).not.toBeNull();
            expect((kotak as { y: number; height: number }).y + (kotak as { height: number }).height).toBeLessThanOrEqual(
                vp.height + 1,
            );

            expect(state.detailRequests.length).toBe(1);

            await simpanGambar(page, vp, 'detail');
        });

        test('f10-ac2-daftar-tanpa-prefetch-detail', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page);

            await page.goto('/rekam-medis');

            await expect(
                page.locator('[data-slot="riwayat-baris"]').first(),
            ).toBeVisible();

            await expect
                .poll(() => state.counts['GET /api/v1/rekam-medis'] ?? 0)
                .toBeGreaterThanOrEqual(1);

            expect(state.counts['GET /api/v1/pasien/resep'] ?? 0).toBeGreaterThanOrEqual(1);
            expect(
                state.counts['GET /api/v1/pasien/surat-keterangan'] ?? 0,
            ).toBeGreaterThanOrEqual(1);
            expect(state.counts['GET /api/v1/pasien/booking'] ?? 0).toBeGreaterThanOrEqual(1);
            expect(state.counts['GET /api/v1/me'] ?? 0).toBeGreaterThanOrEqual(1);

            expect(state.detailRequests).toEqual([]);

            await page.getByRole('link', { name: 'Buka rekam medis' }).first().click();

            await expect(page).toHaveURL(/\/rekam-medis\/42$/);

            await expect
                .poll(() => state.detailRequests)
                .toEqual(['/api/v1/rekam-medis/42']);
        });

        test('f10-ac3-teks-medis-tanpa-ellipsis', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page);

            await page.goto('/rekam-medis/42');

            await expect(
                page.locator('[data-slot="rekam-medis-view"]'),
            ).toBeVisible();

            const masalah = await page
                .locator('[data-slot="rekam-medis-view"]')
                .evaluate((akar) => {
                    const daftar: string[] = [];

                    for (const elemen of akar.querySelectorAll('*')) {
                        if (elemen.closest('.sr-only') !== null) {
                            continue;
                        }

                        const gaya = getComputedStyle(elemen);

                        if (gaya.overflowX === 'hidden' || gaya.overflowY === 'hidden') {
                            continue;
                        }

                        const kelas = elemen.getAttribute('class') ?? '';

                        if (/(^|\s)(truncate|line-clamp-\d+)(\s|$)/.test(kelas)) {
                            daftar.push(`kelas terpotong: ${kelas}`);
                        }

                        if (elemen.scrollWidth > elemen.clientWidth + 1) {
                            daftar.push(
                                `overflow: ${elemen.tagName.toLowerCase()} (${kelas})`,
                            );
                        }
                    }

                    return daftar;
                });

            expect(masalah).toEqual([]);
        });

        test('f10-ac4-target-sentuh-44px', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page);

            await page.goto('/rekam-medis');

            await expect(
                page.locator('[data-slot="riwayat-baris"]').first(),
            ).toBeVisible();

            for (const item of await page.locator('[data-slot="riwayat-segmen"]').all()) {
                expect(await ukurTinggi(item)).toBeGreaterThanOrEqual(44);
            }

            for (const chip of await page.locator('[data-slot="riwayat-filter"]').all()) {
                expect(await ukurTinggi(chip)).toBeGreaterThanOrEqual(44);
            }

            expect(
                await ukurTinggi(page.locator('[data-slot="riwayat-baris"]').first()),
            ).toBeGreaterThanOrEqual(44);

            expect(
                await ukurTinggi(
                    page.getByRole('link', { name: 'Buka rekam medis' }).first(),
                ),
            ).toBeGreaterThanOrEqual(44);

            expect(
                await ukurTinggi(
                    page.locator('[data-slot="pagination"]').getByRole('button', {
                        name: 'Berikutnya',
                    }),
                ),
            ).toBeGreaterThanOrEqual(44);

            state.indexStatus = 500;

            await page.reload();

            const galat = page.locator('[data-slot="error-state"]');

            await expect(galat).toBeVisible();

            expect(
                await ukurTinggi(galat.getByRole('button', { name: 'Coba lagi' })),
            ).toBeGreaterThanOrEqual(44);

            state.indexStatus = 200;

            await page.goto('/rekam-medis/42');

            await expect(
                page.locator('[data-slot="cetak-button"]'),
            ).toBeVisible();

            expect(
                await ukurTinggi(page.locator('[data-slot="cetak-button"]')),
            ).toBeGreaterThanOrEqual(44);

            expect(
                await ukurTinggi(page.locator('[data-slot="riwayat-akses-aksi"]')),
            ).toBeGreaterThanOrEqual(44);
        });

        test('f10-ac5-kontras-45', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page);

            await page.goto('/rekam-medis');

            await expect(
                page.locator('[data-slot="riwayat-baris"]').first(),
            ).toBeVisible();

            for (const sasaran of [
                '[data-slot="riwayat-judul-segmen"]',
                '[data-slot="riwayat-keluhan"]',
                '[data-slot="riwayat-dokter"]',
            ]) {
                expect(
                    await kontrasTerhadapLatar(page.locator(sasaran)),
                    sasaran,
                ).toBeGreaterThanOrEqual(4.5);
            }

            await page.goto('/rekam-medis/42');

            await expect(
                page.locator('[data-slot="rekam-medis-view"]'),
            ).toBeVisible();

            for (const sasaran of [
                '[data-slot="rekam-medis-view"] h3',
                '[data-slot="rekam-medis-soap"] p',
                '[data-slot="rekam-medis-identitas"] span:last-child',
            ]) {
                expect(
                    await kontrasTerhadapLatar(page.locator(sasaran)),
                    sasaran,
                ).toBeGreaterThanOrEqual(4.5);
            }
        });

        test('f10-ac6-enam-state', async ({ page, context }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, { delayMs: 700 });

            await page.goto('/rekam-medis');

            await expect(
                page.locator('[data-slot="riwayat-loading"]'),
            ).toBeVisible();

            await expect(
                page.locator('[data-slot="riwayat-baris"]').first(),
            ).toBeVisible({ timeout: 10_000 });

            state.index = [];
            state.resep = [];
            state.surat = [];
            state.booking = [];

            await page.reload();

            await expect(page.getByText('Belum ada rekam medis.')).toBeVisible();

            await expect(
                page.getByRole('link', { name: 'Cari dokter' }),
            ).toBeVisible();

            await page
                .locator('[data-slot="riwayat-filter"][data-status="kedaluwarsa"]')
                .click();

            const kosongPenyaring = page.locator(
                '[data-slot="riwayat-filter-kosong"]',
            );

            await expect(kosongPenyaring).toBeVisible();
            await expect(kosongPenyaring).toContainText('Tidak ada data yang cocok.');
            await expect(
                kosongPenyaring.getByRole('button', { name: 'Hapus penyaring' }),
            ).toBeVisible();

            state.index = [REKAM_INDEX];
            state.indexStatus = 500;

            await page.reload();

            const galat = page.locator('[data-slot="error-state"]');

            await expect(galat).toBeVisible({ timeout: 15_000 });
            await expect(galat).toContainText('Gagal memuat riwayat.');

            const sebelum = state.counts['GET /api/v1/rekam-medis'] ?? 0;

            state.indexStatus = 200;

            await galat.getByRole('button', { name: 'Coba lagi' }).click();

            await expect(
                page.locator('[data-slot="riwayat-baris"]').first(),
            ).toBeVisible({ timeout: 10_000 });

            expect(state.counts['GET /api/v1/rekam-medis'] ?? 0).toBeGreaterThan(
                sebelum,
            );

            await context.setOffline(true);

            const banner = page.getByTestId('offline-banner');

            await expect(banner).toBeVisible();
            await expect(banner).toContainText(
                'Anda sedang offline. Menampilkan data terakhir yang tersimpan.',
            );

            await simpanGambar(page, vp, 'offline');

            await context.setOffline(false);

            await expect(banner).toHaveCount(0);
        });

        test('f10-ac7-404-bukan-milik', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, { detailStatus: 404 });

            await page.goto('/rekam-medis/999');

            const tidakAda = page.locator('[data-slot="not-found-state"]');

            await expect(tidakAda).toBeVisible();
            await expect(tidakAda).toContainText('tidak ada atau bukan milik akun ini');

            const teks = await page.locator('body').innerText();

            expect(teks).not.toContain(DIAGNOSIS);
            expect(teks).not.toContain(KELUHAN);

            await tidakAda
                .getByRole('link', { name: 'Daftar rekam medis saya' })
                .click();

            await expect(page).toHaveURL(/\/rekam-medis$/);
        });

        test('f10-ac8-rantai-amandemen', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page);

            await page.goto('/rekam-medis/42');

            const rantai = page.locator('[data-slot="rekam-medis-rantai"]');

            await expect(rantai).toBeVisible();
            await expect(
                page.locator('[data-slot="versi-badge"][data-versi="2"]').first(),
            ).toBeVisible();
            await expect(page.getByText('dari 3 versi')).toBeVisible();
            await expect(page.getByText('terkini').first()).toBeVisible();

            const entri = page.locator('[data-slot="rekam-medis-rantai-entri"]');

            await expect(entri).toHaveCount(3);
            await expect(entri.nth(0)).toHaveAttribute('data-versi', '1');
            await expect(entri.nth(1)).toHaveAttribute('data-versi', '2');
            await expect(entri.nth(2)).toHaveAttribute('data-versi', '2');

            await entri
                .nth(0)
                .getByRole('link', { name: /Versi 1/ })
                .click();

            await expect(page).toHaveURL(/\/rekam-medis\/40$/);

            await page.goto('/rekam-medis/44');

            await expect(
                page.locator('[data-slot="rekam-medis-view"]'),
            ).toBeVisible();

            await expect(
                page.locator('[data-slot="rekam-medis-rantai"]'),
            ).toHaveCount(0);
        });

        test('f10-ac9-kedaluwarsa-tetap-terbaca', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page);

            await page.goto('/rekam-medis');

            const barisResep = page.locator(
                '[data-slot="riwayat-baris"][data-jenis="resep"]',
            );

            await expect(barisResep).toBeVisible();
            await expect(barisResep).toContainText(
                'Belum ada rekam medis untuk resep ini.',
            );
            await expect(
                barisResep.locator('[data-slot="kedaluwarsa-badge"]'),
            ).toBeVisible();

            await expect(barisResep.locator('a, button')).toHaveCount(0);

            await page.getByRole('link', { name: 'Buka rekam medis' }).first().click();

            await expect(page).toHaveURL(/\/rekam-medis\/42$/);
            await expect(
                page.locator('[data-slot="rekam-medis-view"]'),
            ).toBeVisible();
        });

        test('f10-ac10-privasi-judul-url-toast', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page);

            await page.goto('/rekam-medis/42');

            await expect(
                page.locator('[data-slot="rekam-medis-view"]'),
            ).toBeVisible();

            const judul = await page.title();

            expect(judul).toContain('Rekam medis');
            expect(judul).not.toContain(DIAGNOSIS);
            expect(judul).not.toContain(KELUHAN);
            expect(judul).not.toContain(NAMA_DOKTER);

            expect(new URL(page.url()).pathname).toBe('/rekam-medis/42');
            expect(page.url()).not.toContain('?');

            await expect(
                page
                    .locator('[data-slot="rekam-medis-view"]')
                    .getByText(/aksesnya dicatat/),
            ).toBeVisible();

            await expect(page.locator('[data-sonner-toast]')).toHaveCount(0);
        });

        test('f10-ac11-a11y-dan-pengumuman', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page);

            await page.goto('/rekam-medis');

            await expect(
                page.locator('[data-slot="riwayat-baris"]').first(),
            ).toBeVisible();

            expect(await page.locator('[aria-pressed]').count()).toBeGreaterThanOrEqual(1);

            const jumlah = page.locator('[data-slot="riwayat-jumlah"]');

            await expect(jumlah).toContainText('data ditemukan');

            await page.locator('[data-slot="riwayat-cari"]').fill('zzz-tidak-ada');

            await expect(jumlah).toContainText('Tidak ada data yang cocok');

            assertUrutanHeading(
                await page
                    .locator('main')
                    .locator('h1, h2, h3, h4, h5, h6')
                    .evaluateAll((elemen) =>
                        elemen.map((item) => Number(item.tagName.slice(1))),
                    ),
            );

            await expectNoA11yViolations(page);

            await page.goto('/rekam-medis/42');

            await expect(
                page.locator('[data-slot="rekam-medis-view"]'),
            ).toBeVisible();

            assertUrutanHeading(
                await page
                    .locator('main')
                    .locator('h1, h2, h3, h4, h5, h6')
                    .evaluateAll((elemen) =>
                        elemen.map((item) => Number(item.tagName.slice(1))),
                    ),
            );

            await expectNoA11yViolations(page);
        });

        test('f10-ac12-cetak-tanpa-bocor-data', async ({ page }) => {
            await masukPalsu(page);

            await page.addInitScript(() => {
                (window as unknown as Record<string, number>).__jumlahCetak = 0;

                window.print = () => {
                    (window as unknown as Record<string, number>).__jumlahCetak += 1;
                };
            });

            await pasangMock(page);

            await page.goto('/rekam-medis/42');

            const peringatan = page.locator('[data-slot="cetak-peringatan"]');

            await expect(peringatan).toBeVisible();
            await expect(peringatan).toContainText('perangkat bersama');

            await page.locator('[data-slot="cetak-button"]').click();

            expect(
                await page.evaluate(
                    () =>
                        (window as unknown as Record<string, number>).__jumlahCetak,
                ),
            ).toBe(1);

            const judul = await page.title();

            expect(judul).not.toContain(DIAGNOSIS);
            expect(judul).not.toContain(KELUHAN);
        });

        test('f10-ac13-riwayat-akses', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page);

            await page.goto('/rekam-medis/42');

            await page.locator('[data-slot="riwayat-akses-aksi"]').click();

            const panel = page.locator('[data-slot="riwayat-akses"]');

            await expect(panel).toBeVisible();

            const baris = panel.locator('[data-slot="riwayat-akses-baris"]');

            await expect(baris).toHaveCount(2);
            await expect(baris.nth(0)).toContainText('Dokter');
            await expect(baris.nth(0)).toContainText('Perawatan');
            await expect(baris.nth(1)).toContainText('Pasien');
            await expect(baris.nth(1)).toContainText('Pasien sendiri');

            const teks = await panel.innerText();

            expect(teks).not.toContain(NAMA_PELAKU_RAHASIA);
            expect(teks).not.toContain(NAMA_PASIEN);

            await simpanGambar(page, vp, 'akses');

            await panel.getByRole('button', { name: 'Berikutnya' }).click();

            await expect(baris).toHaveCount(1);
            await expect(baris.nth(0)).toContainText('Perawat');
            await expect(baris.nth(0)).toContainText('Klaim');

            expect(state.aksesUrls.some((url) => url.includes('page=2'))).toBe(true);

            state.akses = 'empty';

            await page.goto('/rekam-medis/42');
            await page.locator('[data-slot="riwayat-akses-aksi"]').click();

            await expect(page.getByText('Belum ada catatan akses.')).toBeVisible();

            state.aksesStatus = 404;

            await page.goto('/rekam-medis/42');
            await page.locator('[data-slot="riwayat-akses-aksi"]').click();

            const tidakAda = page.locator('[data-slot="riwayat-akses"] [data-slot="not-found-state"]');

            await expect(tidakAda).toBeVisible();

            const teks404 = await page.locator('[data-slot="riwayat-akses"]').innerText();

            expect(teks404).not.toContain(DIAGNOSIS);
            expect(teks404).not.toContain(KELUHAN);

            state.aksesStatus = 403;

            await page.goto('/rekam-medis/42');
            await page.locator('[data-slot="riwayat-akses-aksi"]').click();

            await expect(
                page.locator('[data-slot="riwayat-akses"] [data-slot="forbidden-state"]'),
            ).toBeVisible();

            const teks403 = await page.locator('[data-slot="riwayat-akses"]').innerText();

            expect(teks403).not.toContain(DIAGNOSIS);
            expect(teks403).not.toContain(KELUHAN);
        });
    });
}
