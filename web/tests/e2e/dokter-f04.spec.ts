import { mkdirSync } from 'node:fs';
import { expect, test, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F04 (profil dokter dan kepercayaan) mocked end to end at 390x844 and 1280x900.
 *
 * ## Scope: the whole flow now that the backend unblocked reviews
 *
 * AC-1/2/3/7/8/10/11/12 cover the profile built from `DokterDetailResource` plus
 * `/jadwal` and `/slot`. AC-4/5/6/9 cover `GET /dokter/{id}/ulasan`: the summary (only
 * at `total >= 5`), the clickable distribution, the sub-ratings, the item list with the
 * doctor's reply, the exact policy sentence and a block-local error state. The write
 * surface (`/konsultasi/:id/ulasan` -> `POST /konsultasi/{id}/ulasan`) has its own two
 * specs below.
 *
 * ## Why every call is mocked
 *
 * Every criterion needs a response the live seed cannot produce on demand: a doctor with a
 * two-day schedule, an empty weekly schedule, a 404 detail, a 500 detail, a profile whose
 * bio/education/faskes are all null, a 128-review aggregate, a filtered page with no 5★
 * rows, a failing review list, a `selesai` consultation and a pre-reviewed 422. The
 * review fixture body ("Amoxicillin 500 mg") must never reach a real server, which
 * mocking guarantees.
 *
 * ## The session for the click-throughs
 *
 * AC-1 taps `Pesan jadwal` -> `/booking/5`, and both review surfaces sit behind
 * `RequireAuth`. An init script seeds the token pair (`sessionStorage`, the storage
 * `lib/token.ts` uses) so a criterion measures its own destination rather than the
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

/**
 * The aggregate fixture: 128 reviews whose distribution sums to `meta.total` when no
 * filter is applied, so a test can tell a filtered page-set from the overall count.
 */
const DISTRIBUSI_128 = {
    '1': 2,
    '2': 3,
    '3': 8,
    '4': 30,
    '5': 85,
};

/** Anonymous review with a doctor's reply; the body is the privacy test word. */
const ULASAN_ANON: Record<string, unknown> = {
    id: 901,
    rating: 5,
    rating_komunikasi: 5,
    rating_akurasi: 4,
    isi: 'Dokter menjelaskan dengan sabar dan jadwalnya tepat.',
    is_anonim: true,
    penulis: null,
    balasan_dokter: 'Terima kasih atas kepercayaannya. Semoga lekas sembuh.',
    dibalas_at: '2026-10-05T02:30:00.000000Z',
    dibuat_at: '2026-10-04T09:00:00.000000Z',
};

/** Non-anonymous review: the API publishes only the masked short name. */
const ULASAN_NAMED: Record<string, unknown> = {
    id: 902,
    rating: 4,
    rating_komunikasi: 4,
    rating_akurasi: null,
    isi: 'Penjelasan mudah dipahami dan antreannya tidak lama.',
    is_anonim: false,
    penulis: 'Dewi S.',
    balasan_dokter: null,
    dibalas_at: null,
    dibuat_at: '2026-10-03T09:00:00.000000Z',
};

/**
 * `GET /konsultasi/{id}` for the write surface. Deliberately complete enough that the
 * page's gates (`pasien`, `selesai`) pass; the SOAP fields carry no assertion value and
 * are never rendered by the review page.
 */
const KONSULTASI_SELESAI: Record<string, unknown> = {
    id: 11,
    booking_id: null,
    pasien_id: 1,
    dokter_id: 5,
    tipe: 'chat',
    status: 'selesai',
    room_id: '00000000-0000-4000-8000-000000000011',
    mulai_at: '2026-10-01T02:00:00.000000Z',
    selesai_at: '2026-10-01T02:15:00.000000Z',
    total_durasi_detik: 900,
    catatan_subjektif: 'Keluhan demam.',
    catatan_objektif: 'Suhu 37,8 C.',
    catatan_asessment: 'Observasi.',
    catatan_plan: 'Istirahat dan cairan.',
    diagnosis_kerja: null,
    saran_tindak_lanjut: null,
    biaya_konsultasi: '85000.00',
    dibuat_at: '2026-10-01T01:55:00.000000Z',
    diubah_at: '2026-10-01T02:15:00.000000Z',
    pasien: { id: 1, nik: '3271••••••••1234', nama_lengkap: 'Pasien Uji F04' },
    dokter: { id: 5, nama_lengkap: NAMA_DOKTER },
    booking: null,
    baca: {
        pasien_user_id: 1,
        pasien_last_read_at: '2026-10-01T02:15:00.000000Z',
        dokter_user_id: 99,
        dokter_last_read_at: '2026-10-01T02:15:00.000000Z',
    },
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
    ulasanStatus: number;
    ulasanRows: Array<Record<string, unknown>>;
    /** `meta.total` when no `rating` filter is applied. */
    ulasanTotal: number;
    ulasanMeta: Record<string, unknown>;
    konsultasiStatus: number;
    konsultasi: Record<string, unknown>;
    kirimUlasanStatus: number;
    kirimUlasanBody: Record<string, unknown> | null;
    permintaan: Permintaan[];
};

type OpsiMock = {
    detailStatus?: number;
    detail?: Record<string, unknown>;
    jadwal?: Record<string, Array<Record<string, unknown>>>;
    slotStatus?: number;
    slots?: Array<Record<string, unknown>>;
    ulasanStatus?: number;
    ulasanRows?: Array<Record<string, unknown>>;
    ulasanTotal?: number;
    ulasanMeta?: Record<string, unknown>;
    konsultasiStatus?: number;
    konsultasi?: Record<string, unknown>;
    kirimUlasanStatus?: number;
};

const DISTRIBUSI_KOSONG = {
    '1': 0,
    '2': 0,
    '3': 0,
    '4': 0,
    '5': 0,
};

const ULASAN_META_KOSONG = {
    distribusi: { ...DISTRIBUSI_KOSONG },
    rata_rata: null,
    rata_rata_komunikasi: null,
    rata_rata_akurasi: null,
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
        ulasanStatus: opsi.ulasanStatus ?? 200,
        ulasanRows: opsi.ulasanRows ?? [],
        ulasanTotal: opsi.ulasanTotal ?? opsi.ulasanRows?.length ?? 0,
        ulasanMeta: opsi.ulasanMeta ?? { ...ULASAN_META_KOSONG },
        konsultasiStatus: opsi.konsultasiStatus ?? 200,
        konsultasi: opsi.konsultasi ?? { ...KONSULTASI_SELESAI },
        kirimUlasanStatus: opsi.kirimUlasanStatus ?? 201,
        kirimUlasanBody: null,
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

        if (/^\/api\/v1\/konsultasi\/[^/]+\/ulasan$/.test(path) && method === 'POST') {
            state.kirimUlasanBody = (request.postDataJSON() ?? null) as Record<
                string,
                unknown
            > | null;

            if (state.kirimUlasanStatus === 201) {
                return balasJson(route, 201, {
                    success: true,
                    message: 'Ulasan berhasil disimpan.',
                    data: { ulasan: { ...ULASAN_ANON, id: 999 } },
                });
            }

            return balasJson(route, state.kirimUlasanStatus, {
                success: false,
                message: 'Data yang dikirim tidak valid.',
                errors: {
                    konsultasi_id: ['Konsultasi ini sudah memiliki ulasan.'],
                },
            });
        }

        if (/^\/api\/v1\/konsultasi\/[^/]+$/.test(path) && method === 'GET') {
            if (state.konsultasiStatus !== 200) {
                return balasJson(route, state.konsultasiStatus, {
                    success: false,
                    message:
                        state.konsultasiStatus === 404
                            ? 'Resource not found.'
                            : 'Terjadi kesalahan pada server.',
                    errors: {},
                });
            }

            return balasJson(route, 200, {
                success: true,
                message: 'Detail konsultasi berhasil dimuat.',
                data: { konsultasi: state.konsultasi },
            });
        }

        if (/^\/api\/v1\/dokter\/[^/]+\/ulasan$/.test(path) && method === 'GET') {
            if (state.ulasanStatus !== 200) {
                return balasJson(route, state.ulasanStatus, {
                    success: false,
                    message: 'Terjadi kesalahan pada server.',
                    errors: {},
                });
            }

            const ratingParam = url.searchParams.get('rating');
            const halaman = Math.max(1, Number(url.searchParams.get('page') ?? '1'));
            const perPage = Math.max(1, Number(url.searchParams.get('per_page') ?? '10'));

            /**
             * `?rating=` narrows the page-set exactly as `UlasanDokterService::daftar()`
             * does; the distribution in `meta` stays the FULL fixture, because the
             * server computes the aggregate over every review.
             */
            const tersaring =
                ratingParam === null
                    ? state.ulasanRows
                    : state.ulasanRows.filter(
                          (row) => Number(row.rating) === Number(ratingParam),
                      );

            const total = ratingParam === null ? state.ulasanTotal : tersaring.length;
            const awal = (halaman - 1) * perPage;
            const baris = tersaring.slice(awal, awal + perPage);

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar ulasan berhasil dimuat.',
                data: { ulasan: baris },
                meta: {
                    current_page: halaman,
                    last_page: Math.max(1, Math.ceil(total / perPage)),
                    per_page: perPage,
                    total,
                    from: baris.length === 0 ? null : awal + 1,
                    to: baris.length === 0 ? null : awal + baris.length,
                    ...state.ulasanMeta,
                },
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

for (const vp of VIEWPORTS) {
    test.describe(`F04 profil dokter ${vp.nama} ${vp.width}x${vp.height}`, () => {
        test.use({
            viewport: { width: vp.width, height: vp.height },
            timezoneId: 'Asia/Jakarta',
        });

        test.setTimeout(60_000);

        test('f04-ac1-hero-cta', async ({ page }) => {
            await masukPalsu(page);

            await bukaProfil(page);

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

        test('f04-ac4-ulasan-render', async ({ page }) => {
            await bukaProfil(page, {
                ulasanRows: [{ ...ULASAN_ANON }, { ...ULASAN_NAMED }],
                ulasanTotal: 128,
                ulasanMeta: {
                    distribusi: { ...DISTRIBUSI_128 },
                    rata_rata: 4.9,
                    rata_rata_komunikasi: 4.8,
                    rata_rata_akurasi: 4.7,
                },
            });

            const blok = page.locator('[data-slot="reviews-block"]');
            const ringkasan = blok.locator('[data-slot="ringkasan-rating"]');

            // Summary and sub-ratings, all from the one response.
            await expect(ringkasan).toContainText('dari 5');
            await expect(ringkasan).toContainText('4,9');
            await expect(ringkasan).toContainText('128 ulasan');
            await expect(blok.getByText('Komunikasi')).toBeVisible();
            await expect(blok.getByText('4,8')).toBeVisible();
            await expect(blok.getByText('Akurasi')).toBeVisible();
            await expect(blok.getByText('4,7')).toBeVisible();

            // Five clickable bars, each a >=44px target with its numeric count.
            const bar = blok.locator('[data-slot="distribusi-bintang"]');

            await expect(bar).toHaveCount(5);

            for (let index = 0; index < 5; index += 1) {
                const kotak = await bar.nth(index).boundingBox();

                expect(kotak?.height ?? 0).toBeGreaterThanOrEqual(44);
            }

            const bintangLima = blok.getByRole('button', {
                name: 'Tampilkan hanya 5 bintang',
            });

            await expect(bintangLima).toBeVisible();
            await expect(bintangLima).toContainText('85');

            // Items: anonymous and masked author, verification, date, full body, reply.
            const item = blok.locator('[data-slot="ulasan-item"]');

            await expect(item).toHaveCount(2);
            await expect(blok.getByText('Pasien', { exact: true })).toBeVisible();
            await expect(blok.getByText('Dewi S.')).toBeVisible();
            await expect(blok.getByText('Terverifikasi')).toHaveCount(2);
            await expect(blok.getByText('04 Okt 2026')).toBeVisible();
            await expect(blok.locator('[data-slot="balasan-dokter"]')).toContainText(
                'Balasan dokter',
            );
            await expect(blok.locator('[data-slot="balasan-dokter"]')).toContainText(
                'Terima kasih atas kepercayaannya.',
            );
            await expect(blok.getByText(ULASAN_ANON.isi as string)).toBeVisible();

            await simpanGambar(page, vp, 'ulasan');

            // Clicking 4★ filters the list with `?rating=4`.
            const permintaanFilter = page.waitForRequest((request) => {
                const alamat = new URL(request.url());

                return (
                    alamat.pathname.endsWith('/ulasan') &&
                    alamat.searchParams.get('rating') === '4'
                );
            });

            await blok
                .getByRole('button', { name: 'Tampilkan hanya 4 bintang' })
                .click();

            await permintaanFilter;

            // The filtered page-set has one row; the distribution still speaks about 128.
            await expect(item).toHaveCount(1);
            await expect(ringkasan).toContainText('128 ulasan');

            await expectNoA11yViolations(page);
        });

        test('f04-ac5-rating-kecil', async ({ page }) => {
            await bukaProfil(page, {
                ulasanRows: [
                    { ...ULASAN_NAMED },
                    { ...ULASAN_ANON, id: 903, rating: 5, balasan_dokter: null, dibalas_at: null },
                    { ...ULASAN_ANON, id: 904, rating: 3, balasan_dokter: null, dibalas_at: null },
                ],
                ulasanTotal: 3,
                ulasanMeta: {
                    distribusi: { '1': 0, '2': 0, '3': 1, '4': 1, '5': 1 },
                    rata_rata: 4,
                    rata_rata_komunikasi: 4.5,
                    rata_rata_akurasi: null,
                },
            });

            const blok = page.locator('[data-slot="reviews-block"]');

            await expect(
                blok.getByText('Belum cukup ulasan untuk menampilkan rating.'),
            ).toBeVisible();
            await expect(blok.locator('[data-slot="ulasan-belum-cukup"]')).toContainText(
                '3 ulasan',
            );

            // No summary block, no distribution and no "dari 5" sentence below five
            // reviews. Per-item stars still render - each review carries its own rating.
            await expect(blok.locator('[data-slot="ringkasan-rating"]')).toHaveCount(0);
            await expect(blok.locator('[data-slot="ringkasan-ulasan"]')).toHaveCount(0);
            await expect(blok.locator('[data-slot="distribusi-bintang"]')).toHaveCount(0);
            await expect(blok.getByText(/dari 5/)).toHaveCount(0);

            await simpanGambar(page, vp, 'ulasan-kecil');

            await expectNoA11yViolations(page);
        });

        test('f04-ac6-kebijakan', async ({ page }) => {
            await bukaProfil(page, {
                ulasanRows: [{ ...ULASAN_ANON }],
                ulasanTotal: 1,
                ulasanMeta: {
                    distribusi: { '1': 0, '2': 0, '3': 0, '4': 0, '5': 1 },
                    rata_rata: 5,
                    rata_rata_komunikasi: 5,
                    rata_rata_akurasi: 4,
                },
            });

            const blok = page.locator('[data-slot="reviews-block"]');

            await expect(blok.locator('[data-slot="kebijakan-ulasan"]')).toHaveText(
                'Ulasan hanya dapat ditulis oleh pasien yang telah menyelesaikan konsultasi. Tidak ada ulasan berbayar atau berinsentif.',
            );

            // F04 §3/FTC: no provider control may hide or suppress a review.
            await expect(page.getByRole('button', { name: /sembunyikan/i })).toHaveCount(0);
            await expect(page.getByText(/sembunyikan ulasan/i)).toHaveCount(0);

            await expectNoA11yViolations(page);
        });

        test('f04-ac9-ulasan-500', async ({ page }) => {
            const state = await bukaProfil(page, { ulasanStatus: 500 });

            const blok = page.locator('[data-slot="reviews-block"]');

            await expect(blok.getByText('Ulasan belum dapat dimuat.')).toBeVisible();
            await expect(
                blok.getByRole('button', { name: 'Coba lagi' }),
            ).toBeVisible();

            // The rest of the profile survives the reviews failure.
            await expect(page.getByRole('heading', { name: NAMA_DOKTER })).toBeVisible();
            await expect(page.locator('[data-slot="schedule-preview"]')).toBeVisible();
            await expect(page.locator('[data-slot="credential-trigger"]')).toBeVisible();
            await expect(page.getByText(/Rp\s?85\.000/)).toBeVisible();

            await simpanGambar(page, vp, 'ulasan-error');

            await expectNoA11yViolations(page);

            state.ulasanStatus = 200;

            await blok.getByRole('button', { name: 'Coba lagi' }).click();

            await expect(blok.getByText('Belum ada ulasan.')).toBeVisible();
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
            await bukaProfil(page, {
                detail: { ...DOKTER, ...RAHASIA },
                ulasanRows: [
                    { ...ULASAN_ANON, isi: 'Amoxicillin 500 mg' },
                ],
                ulasanTotal: 1,
                ulasanMeta: {
                    distribusi: { '1': 0, '2': 0, '3': 0, '4': 0, '5': 1 },
                    rata_rata: 5,
                    rata_rata_komunikasi: 5,
                    rata_rata_akurasi: 4,
                },
            });

            expect(await page.title()).toBe(`${NAMA_DOKTER} | Sehatly`);

            const alamat = page.url();

            expect(alamat).not.toContain('Amoxicillin');
            expect(alamat).not.toContain('STR-999');
            expect(alamat).not.toContain('0812');

            // The review body IS page content, and must be - so the privacy checked here
            // is that it stays out of every non-content surface: title, URL, aria-label
            // and toast. The old AC-11 fixture asserted the text was nowhere because the
            // reviews block did not exist; now it renders and the boundary moved.
            await expect(
                page
                    .locator('[data-slot="reviews-block"]')
                    .getByText('Amoxicillin 500 mg'),
            ).toBeVisible();

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
                'STR-999',
                'SIP-999',
                RAHASIA.file_str_url,
                RAHASIA.no_telepon,
                RAHASIA.email,
            ]) {
                expect(html).not.toContain(kata);
            }

            await expect(page.locator('[data-sonner-toast]')).toHaveCount(0);

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

        test('f04-ulasan-tulis', async ({ page }) => {
            await masukPalsu(page);

            const state = await pasangMock(page, {
                konsultasi: { ...KONSULTASI_SELESAI },
            });

            await page.goto('/konsultasi/11/ulasan');

            await expect(
                page.getByRole('heading', { name: 'Tulis ulasan' }),
            ).toBeVisible({ timeout: 15_000 });

            await expect(
                page.getByText(`Untuk konsultasi #11 bersama ${NAMA_DOKTER}.`),
            ).toBeVisible();
            await expect(
                page.locator('[data-slot="pilih-bintang-rating"]'),
            ).toBeVisible();
            await expect(
                page.locator('[data-slot="pilih-bintang-komunikasi"]'),
            ).toBeVisible();
            await expect(
                page.locator('[data-slot="pilih-bintang-akurasi"]'),
            ).toBeVisible();

            await expectNoA11yViolations(page);

            // Submitting without a rating is refused locally and costs no request.
            await page.getByRole('button', { name: 'Kirim ulasan' }).click();

            await expect(page.getByText('Pilih rating terlebih dahulu.')).toBeVisible();

            expect(
                state.permintaan.some(
                    (row) => row.method === 'POST' && row.path.endsWith('/ulasan'),
                ),
            ).toBe(false);

            await page
                .locator('[data-slot="pilih-bintang-rating"]')
                .getByText('5 bintang', { exact: true })
                .click();

            await page
                .getByLabel('Ulasan (opsional)')
                .fill('Dokter menjelaskan dengan sabar.');

            // Anonymity is the default, not a choice the patient has to make.
            await expect(page.getByRole('checkbox', { name: /anonim/i })).toBeChecked();

            // Back to the top first: a full-page capture paints the shell's fixed mobile
            // nav at the current scroll offset, which would otherwise land over the form.
            await page.evaluate(() => {
                window.scrollTo(0, 0);
            });

            await simpanGambar(page, vp, 'ulasan-tulis');

            await page.getByRole('button', { name: 'Kirim ulasan' }).click();

            await expect(page.getByText('Ulasan berhasil dikirim.')).toBeVisible();

            const body = state.kirimUlasanBody as Record<string, unknown> | null;

            expect(body?.rating).toBe(5);
            expect(body?.is_anonim).toBe(true);
            expect(body?.isi).toBe('Dokter menjelaskan dengan sabar.');
            expect(body?.rating_komunikasi).toBeUndefined();
            expect(body?.rating_akurasi).toBeUndefined();

            await simpanGambar(page, vp, 'ulasan-tulis-sukses');

            await expectNoA11yViolations(page);
        });

        test('f04-ulasan-tulis-422', async ({ page }) => {
            await masukPalsu(page);

            await pasangMock(page, {
                konsultasi: { ...KONSULTASI_SELESAI },
                kirimUlasanStatus: 422,
            });

            await page.goto('/konsultasi/11/ulasan');

            await expect(
                page.getByRole('heading', { name: 'Tulis ulasan' }),
            ).toBeVisible({ timeout: 15_000 });

            await page
                .locator('[data-slot="pilih-bintang-rating"]')
                .getByText('4 bintang', { exact: true })
                .click();

            await page.getByRole('button', { name: 'Kirim ulasan' }).click();

            await expect(
                page.getByText('Konsultasi ini sudah memiliki ulasan.'),
            ).toBeVisible();

            // The form stays so the refusal is attached to it, and success did not render.
            await expect(
                page.getByRole('button', { name: 'Kirim ulasan' }),
            ).toBeVisible();
            await expect(page.getByText('Ulasan berhasil dikirim.')).toHaveCount(0);

            await expectNoA11yViolations(page);
        });
    });
}
