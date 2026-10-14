import { expect, test, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F13's acceptance criteria, mocked end to end at 390x844 and 1280x900.
 *
 * ## Why every request is mocked
 *
 * The dashboard merges three server surfaces - today's bookings, the doctor's own
 * consultations and the weekly schedule - and each criterion needs a response the
 * live seed cannot produce on demand: a waiting consultation that races into
 * `berlangsung`, a 500 on the queue, an empty booking day. Interception also keeps
 * real patient data out of every run.
 *
 * ## The socket is faked, and only the consultation screens need it
 *
 * `/konsultasi/:id` opens `private-konsultasi.{id}`. `addInitScript` installs
 * `window.__sehatlyRealtimeSocketFactory`, the documented test seam
 * `useKonsultasiChannel` reads instead of building the Echo transport, so no
 * Reverb process is required and the render-time throw without
 * `VITE_REVERB_APP_KEY` is never reached.
 *
 * ## The list is mocked with mutable state
 *
 * `PUT /terima` and the race 422 rewrite the same consultation the next `GET`
 * publishes, which is what makes "badge berubah" and "refetch membarui badge"
 * assertions about data rather than about a spinner.
 */

const VIEWPORTS = [
    { nama: 'mobile', width: 390, height: 844 },
    { nama: 'desktop', width: 1280, height: 900 },
] as const;

const KONSULTASI_MENUNGGU = 501;
const KONSULTASI_AKTIF = 502;
const PASIEN_WAITING = 'Siti Rahayu';
const PASIEN_AKTIF = 'Budi Santoso';
const KELUHAN = 'Demam tiga hari, batuk sejak Senin.';
const OBAT_GENERIK = 'Amoksisilin';
const RESEP_NOMOR = 'RSP20261001001';
const SURAT_NOMOR = 'SK/2026/000123';

function keYmd(tanggal: Date): string {
    const tahun = String(tanggal.getFullYear()).padStart(4, '0');
    const bulan = String(tanggal.getMonth() + 1).padStart(2, '0');
    const hari = String(tanggal.getDate()).padStart(2, '0');

    return `${tahun}-${bulan}-${hari}`;
}

function tanggalGeser(hari: number): string {
    const d = new Date();
    d.setDate(d.getDate() + hari);

    return keYmd(d);
}

type BookingUji = {
    id: number;
    nomor_booking: string;
    tipe_layanan: string;
    tanggal_kunjungan: string;
    slot_mulai: string;
    slot_selesai: string;
    status: string;
    keluhan: string;
    pasien: { id: number; nik: string | null; nama_lengkap: string };
};

function bookingUji(
    id: number,
    nomor: string,
    tanggal: string,
    mulai: string,
    selesai: string,
    namaPasien: string,
    keluhan: string,
): BookingUji {
    return {
        id,
        nomor_booking: nomor,
        tipe_layanan: 'chat',
        tanggal_kunjungan: tanggal,
        slot_mulai: mulai,
        slot_selesai: selesai,
        status: 'terjadwal',
        keluhan,
        pasien: { id: 5 + id, nik: '3201••••••••1234', nama_lengkap: namaPasien },
    };
}

type KonsultasiUji = {
    id: number;
    tipe: string;
    status: string;
    mulai_at: string | null;
    selesai_at: string | null;
    pasien: { id: number; nama_lengkap: string } | null;
    booking: {
        id: number;
        nomor_booking: string;
        tipe_layanan: string;
        tanggal_kunjungan: string;
        slot_mulai: string;
        slot_selesai: string;
        status: string;
    } | null;
};

type ObatUji = {
    id: number;
    kode_obat: string;
    nama_generik: string;
    nama_brand: string | null;
    bentuk_sediaan: string;
    kekuatan: string | null;
    satuan: string;
    pabrikan: string | null;
    kelas_terapi: string | null;
    kelas_obat: string;
    requires_resep: boolean;
    aturan_pakai_umum: string | null;
    indikasi: string | null;
    kontraindikasi: string | null;
    harga_jual: string;
    status_aktif: boolean;
};

const OBAT_AMOKSISILIN: ObatUji = {
    id: 77,
    kode_obat: 'OBT-AMO-500',
    nama_generik: OBAT_GENERIK,
    nama_brand: 'Amoxsan',
    bentuk_sediaan: 'kapsul',
    kekuatan: '500 mg',
    satuan: 'kapsul',
    pabrikan: 'PT Kimia Farma',
    kelas_terapi: 'Antibiotik',
    kelas_obat: 'keras',
    requires_resep: true,
    aturan_pakai_umum: '3 kali sehari 1 kapsul sesudah makan',
    indikasi: 'Infeksi bakteri.',
    kontraindikasi: null,
    harga_jual: '12000.00',
    status_aktif: true,
};

type State = {
    bookings: BookingUji[] | null;
    bookingGagal: boolean;
    konsultasi: KonsultasiUji[];
    terimaStatus: 200 | 422;
    obatStatus: number;
    resepStatus: number;
    suratStatus: number;
    counts: Record<string, number>;
    bookingUrls: string[];
    obatUrls: string[];
    konsultasiRequests: number;
    jadwalRequests: number;
};

type OpsiMock = {
    bookings?: BookingUji[] | null;
    bookingGagal?: boolean;
    konsultasi?: KonsultasiUji[];
    terimaStatus?: 200 | 422;
    obatStatus?: number;
    resepStatus?: number;
    suratStatus?: number;
};

function konsultasiMenunggu(tanggal: string): KonsultasiUji {
    return {
        id: KONSULTASI_MENUNGGU,
        tipe: 'chat',
        status: 'menunggu_dokter',
        mulai_at: null,
        selesai_at: null,
        pasien: { id: 6, nama_lengkap: PASIEN_WAITING },
        booking: {
            id: 1,
            nomor_booking: 'BK20261001AAA001',
            tipe_layanan: 'chat',
            tanggal_kunjungan: tanggal,
            slot_mulai: '09:00:00',
            slot_selesai: '09:15:00',
            status: 'terjadwal',
        },
    };
}

function konsultasiBerlangsung(tanggal: string): KonsultasiUji {
    return {
        id: KONSULTASI_AKTIF,
        tipe: 'video_call',
        status: 'berlangsung',
        mulai_at: '2026-10-01T02:00:00.000000Z',
        selesai_at: null,
        pasien: { id: 7, nama_lengkap: PASIEN_AKTIF },
        booking: {
            id: 2,
            nomor_booking: 'BK20261001AAA002',
            tipe_layanan: 'video_call',
            tanggal_kunjungan: tanggal,
            slot_mulai: '09:30:00',
            slot_selesai: '09:45:00',
            status: 'terjadwal',
        },
    };
}

function meta(jumlah: number, perPage = 10): Record<string, unknown> {
    return {
        current_page: 1,
        last_page: 1,
        per_page: perPage,
        total: jumlah,
        from: jumlah === 0 ? null : 1,
        to: jumlah === 0 ? null : jumlah,
    };
}

async function balasJson(route: Route, status: number, body: unknown): Promise<void> {
    await route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

async function masukPalsu(page: Page): Promise<void> {
    await page.addInitScript(() => {
        sessionStorage.setItem('sehatly.access_token', 'token-uji-f13');
        sessionStorage.setItem('sehatly.refresh_token', 'refresh-uji-f13');
    });
}

async function pasangRealtimePalsu(page: Page): Promise<void> {
    await page.addInitScript(() => {
        type Listener = {
            onSignal: (signal: { kind: string; reason?: string }) => void;
            onFrame: (frame: { channelName: string; eventName: string; data: unknown }) => void;
            onWhisper: (frame: { channelName: string; eventName: string; data: unknown }) => void;
            onSubscriptionState: (channelName: string, state: string) => void;
        };

        let listener: Listener | null = null;
        let subscribed = false;

        (window as unknown as Record<string, unknown>).__sehatlyRealtimeSocketFactory = (
            next: Listener,
        ) => {
            listener = next;

            return {
                connect: (): void => {
                    listener?.onSignal({ kind: 'connected' });
                },
                disconnect: (): void => {
                    listener?.onSignal({ kind: 'disconnected' });
                },
                subscribe: async (request: { channelName: string }): Promise<void> => {
                    subscribed = true;
                    listener?.onSubscriptionState(request.channelName, 'confirmed');
                },
                unsubscribe: (channelName: string): void => {
                    subscribed = false;
                    listener?.onSubscriptionState(channelName, 'idle');
                },
                whisper: (): void => {},
                subscriptionState: (): string => (subscribed ? 'confirmed' : 'idle'),
            };
        };
    });
}

function userDokter(): Record<string, unknown> {
    return {
        id: 9,
        uuid: '00000000-0000-4000-8000-000000000009',
        nama_lengkap: 'dr. Bima Saputra',
        no_telepon: '081200000009',
        email: null,
        tipe: 'dokter',
        status: 'aktif',
        bahasa: 'id',
        foto_profil: null,
        telepon_terverifikasi: true,
        email_terverifikasi: false,
        last_login_at: null,
        dibuat_at: '2026-01-01T00:00:00.000000Z',
        dokter: {
            id: 3,
            tipe: 'dokter_spesialis',
            pengalaman_tahun: 12,
            bio: null,
            durasi_default_menit: 15,
            tersedia_telemedisin: true,
            status_verifikasi: 'terverifikasi',
        },
    };
}

function detailKonsultasi(state: State, id: number): Record<string, unknown> {
    const row = state.konsultasi.find((item) => item.id === id);

    return {
        id,
        booking_id: row?.booking?.id ?? null,
        pasien_id: row?.pasien?.id ?? 6,
        dokter_id: 3,
        tipe: row?.tipe ?? 'chat',
        status: row?.status ?? 'berlangsung',
        room_id: '00000000-0000-4000-8000-000000000501',
        mulai_at: row?.mulai_at ?? '2026-10-01T02:00:00.000000Z',
        selesai_at: row?.selesai_at ?? null,
        total_durasi_detik: null,
        catatan_subjektif: null,
        catatan_objektif: null,
        catatan_asessment: null,
        catatan_plan: null,
        diagnosis_kerja: null,
        saran_tindak_lanjut: null,
        biaya_konsultasi: '45000.00',
        dibuat_at: '2026-10-01T01:45:00.000000Z',
        diubah_at: '2026-10-01T02:00:00.000000Z',
        pasien: {
            id: row?.pasien?.id ?? 6,
            nik: '3201••••••••1234',
            nama_lengkap: row?.pasien?.nama_lengkap ?? PASIEN_WAITING,
        },
        dokter: { id: 3, nama_lengkap: 'dr. Bima Saputra' },
        booking:
            row?.booking === null || row?.booking === undefined
                ? null
                : {
                      id: row.booking.id,
                      nomor_booking: row.booking.nomor_booking,
                      tipe_layanan: row.booking.tipe_layanan,
                      tanggal_kunjungan: row.booking.tanggal_kunjungan,
                      slot_mulai: row.booking.slot_mulai,
                      slot_selesai: row.booking.slot_selesai,
                      status: row.booking.status,
                  },
        baca: {
            pasien_user_id: 16,
            pasien_last_read_at: null,
            dokter_user_id: 9,
            dokter_last_read_at: null,
        },
    };
}

async function pasangMock(page: Page, opsi: OpsiMock = {}): Promise<State> {
    const tanggal = tanggalGeser(0);

    const state: State = {
        bookings:
            opsi.bookings === undefined
                ? [
                      bookingUji(
                          1,
                          'BK20261001AAA001',
                          tanggal,
                          '09:00:00',
                          '09:15:00',
                          PASIEN_WAITING,
                          KELUHAN,
                      ),
                      bookingUji(
                          2,
                          'BK20261001AAA002',
                          tanggal,
                          '09:30:00',
                          '09:45:00',
                          PASIEN_AKTIF,
                          'Kontrol tekanan darah.',
                      ),
                  ]
                : opsi.bookings,
        bookingGagal: opsi.bookingGagal ?? false,
        konsultasi:
            opsi.konsultasi === undefined
                ? [konsultasiMenunggu(tanggal), konsultasiBerlangsung(tanggal)]
                : opsi.konsultasi,
        terimaStatus: opsi.terimaStatus ?? 200,
        obatStatus: opsi.obatStatus ?? 200,
        resepStatus: opsi.resepStatus ?? 201,
        suratStatus: opsi.suratStatus ?? 201,
        counts: {},
        bookingUrls: [],
        obatUrls: [],
        konsultasiRequests: 0,
        jadwalRequests: 0,
    };

    await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;
        const method = request.method();
        const kunci = `${method} ${path}`;

        state.counts[kunci] = (state.counts[kunci] ?? 0) + 1;

        if (path === '/api/v1/me') {
            return balasJson(route, 200, {
                success: true,
                message: 'Akun berhasil dimuat.',
                data: { user: userDokter() },
            });
        }

        if (path === '/api/v1/notifikasi') {
            return balasJson(route, 200, {
                success: true,
                message: 'Daftar notifikasi berhasil dimuat.',
                data: { notifikasi: [] },
                meta: { ...meta(0), unread: 0 },
            });
        }

        if (path === '/api/v1/dokter/booking' && method === 'GET') {
            state.bookingUrls.push(request.url());

            if (state.bookingGagal) {
                return balasJson(route, 500, {
                    success: false,
                    message: 'Terjadi kesalahan pada server.',
                    errors: {},
                });
            }

            const rows = state.bookings ?? [];

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar booking berhasil dimuat.',
                data: { booking: rows },
                meta: meta(rows.length),
            });
        }

        if (path === '/api/v1/konsultasi' && method === 'GET') {
            state.konsultasiRequests += 1;

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar konsultasi berhasil dimuat.',
                data: { konsultasi: state.konsultasi },
                meta: meta(state.konsultasi.length, 100),
            });
        }

        if (/^\/api\/v1\/dokter\/[^/]+\/jadwal$/.test(path)) {
            state.jadwalRequests += 1;

            return balasJson(route, 200, {
                success: true,
                message: 'Jadwal dokter berhasil dimuat.',
                data: {
                    jadwal: {
                        1: [
                            {
                                jadwal_id: 1,
                                hari: 1,
                                tipe_layanan: 'online',
                                faskes_id: null,
                                jam_mulai: '08:00:00',
                                jam_selesai: '12:00:00',
                                durasi_slot_menit: 15,
                                kuota_per_sesi: 8,
                            },
                        ],
                        3: [
                            {
                                jadwal_id: 2,
                                hari: 3,
                                tipe_layanan: 'klinik',
                                faskes_id: null,
                                jam_mulai: '14:00:00',
                                jam_selesai: '16:00:00',
                                durasi_slot_menit: 20,
                                kuota_per_sesi: 6,
                            },
                        ],
                    },
                },
            });
        }

        const terima = /^\/api\/v1\/konsultasi\/(\d+)\/terima$/.exec(path);

        if (terima !== null && method === 'PUT') {
            const id = Number(terima[1]);
            const baris = state.konsultasi.find((item) => item.id === id);

            if (baris !== undefined) {
                baris.status = 'berlangsung';
                baris.mulai_at = '2026-10-01T02:05:00.000000Z';
            }

            if (state.terimaStatus === 422) {
                return balasJson(route, 422, {
                    success: false,
                    message: 'Konsultasi tidak dapat diterima.',
                    errors: { status: ['Konsultasi sudah berlangsung.'] },
                });
            }

            return balasJson(route, 200, {
                success: true,
                message: 'Konsultasi berhasil dimulai.',
                data: { konsultasi: detailKonsultasi(state, id) },
            });
        }

        const detail = /^\/api\/v1\/konsultasi\/(\d+)$/.exec(path);

        if (detail !== null && method === 'GET') {
            return balasJson(route, 200, {
                success: true,
                message: 'Detail konsultasi berhasil dimuat.',
                data: { konsultasi: detailKonsultasi(state, Number(detail[1])) },
            });
        }

        if (/^\/api\/v1\/konsultasi\/\d+\/chat$/.test(path) && method === 'GET') {
            return balasJson(route, 200, {
                success: true,
                message: 'Riwayat chat berhasil dimuat.',
                data: { pesan: [] },
                meta: meta(0, 50),
            });
        }

        if (/^\/api\/v1\/konsultasi\/\d+\/chat\/baca$/.test(path) && method === 'POST') {
            return balasJson(route, 200, {
                success: true,
                message: 'Pesan ditandai sudah dibaca.',
                data: { konsultasi_id: KONSULTASI_MENUNGGU, jumlah_ditandai_baca: 0 },
            });
        }

        if (path === '/api/v1/obat' && method === 'GET') {
            state.obatUrls.push(request.url());

            if (state.obatStatus !== 200) {
                return balasJson(route, state.obatStatus, {
                    success: false,
                    message: 'Gagal memuat katalog obat.',
                    errors: {},
                });
            }

            return balasJson(route, 200, {
                success: true,
                message: 'Katalog obat berhasil dimuat.',
                data: { obat: [OBAT_AMOKSISILIN] },
                meta: meta(1, 15),
            });
        }

        const resep = /^\/api\/v1\/konsultasi\/(\d+)\/resep$/.exec(path);

        if (resep !== null && method === 'POST') {
            if (state.resepStatus !== 201) {
                return balasJson(route, state.resepStatus, {
                    success: false,
                    message: 'Resep tidak valid.',
                    errors: {},
                });
            }

            return balasJson(route, 201, {
                success: true,
                message: 'Resep berhasil dibuat.',
                data: {
                    resep: {
                        id: 900,
                        nomor_resep: RESEP_NOMOR,
                        konsultasi_id: Number(resep[1]),
                        rekam_medis_id: null,
                        pasien_id: 6,
                        dokter_id: 3,
                        apotek_id: null,
                        tipe: 'digital',
                        status: 'aktif',
                        catatan_dokter: null,
                        tanggal_resep: '2026-10-01T02:15:00.000000Z',
                        berlaku_sampai: '2026-10-08',
                        is_kedaluwarsa: false,
                        terminal: false,
                        is_iter: false,
                        jumlah_iter: 0,
                        qr_token: 'qr-uji-f13',
                        dibuat_at: '2026-10-01T02:15:00.000000Z',
                        items: [],
                    },
                    warning: [],
                    warning_grup: { antar_item: [], riwayat_resep: [], alergi: [] },
                    acknowledgement: {
                        diminta: false,
                        catatan_dodio: null,
                        jumlah_peringatan: 0,
                    },
                },
            });
        }

        const surat = /^\/api\/v1\/konsultasi\/(\d+)\/surat-keterangan$/.exec(path);

        if (surat !== null && method === 'POST') {
            if (state.suratStatus !== 201) {
                return balasJson(route, state.suratStatus, {
                    success: false,
                    message: 'Surat tidak valid.',
                    errors: {},
                });
            }

            return balasJson(route, 201, {
                success: true,
                message: 'Surat keterangan berhasil dibuat.',
                data: {
                    surat_keterangan: {
                        id: 12,
                        nomor_surat: SURAT_NOMOR,
                        konsultasi_id: Number(surat[1]),
                        tipe: 'surat_sakit',
                        pasien_id: 6,
                        dokter_id: 3,
                        tanggal_mulai: '2026-10-01',
                        tanggal_selesai: '2026-10-03',
                        jumlah_hari: 3,
                        isi: null,
                        qr_token: 'qr-surat-uji',
                        file_url: null,
                        dibuat_at: '2026-10-01T02:20:00.000000Z',
                    },
                    rujukan: null,
                },
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

async function bukaDasbor(page: Page): Promise<State> {
    await masukPalsu(page);
    await pasangRealtimePalsu(page);

    const state = await pasangMock(page);

    await page.goto('/dokter/dashboard');

    await expect(page.locator('[data-slot="f13-antrean"]')).toBeVisible();

    return state;
}

async function simpanGambar(
    page: Page,
    vp: { nama: string },
    keadaan: string,
    fullPage = true,
): Promise<void> {
    await page.screenshot({
        path: `ux/refs/f13/f13-${keadaan}-${vp.nama}.png`,
        fullPage,
        animations: 'disabled',
    });
}

for (const vp of VIEWPORTS) {
    test.describe(`F13 dasbor dokter ${vp.nama} ${vp.width}x${vp.height}`, () => {
        test.use({
            viewport: { width: vp.width, height: vp.height },
            timezoneId: 'Asia/Jakarta',
        });

        test('f13-ac1-muat-paralel', async ({ page }) => {
            const state = await bukaDasbor(page);

            await expect(
                page.getByRole('button', { name: 'Hari ini' }),
            ).toHaveAttribute('aria-pressed', 'true');

            await expect(
                page.locator('[data-slot="f13-baris-antrean"]').first(),
            ).toBeVisible();

            expect(state.counts['GET /api/v1/dokter/booking'] ?? 0).toBeGreaterThanOrEqual(1);
            expect(state.counts['GET /api/v1/konsultasi'] ?? 0).toBeGreaterThanOrEqual(1);
            expect(state.jadwalRequests).toBeGreaterThanOrEqual(1);

            await expect(
                page.locator('[data-slot="f13-menunggu"]'),
            ).toContainText(PASIEN_WAITING);

            await simpanGambar(page, vp, 'antrean');

            await expectNoA11yViolations(page);
        });

        test('f13-ac2-terima-satu-ketukan', async ({ page }) => {
            const state = await bukaDasbor(page);

            const barisMenunggu = page.locator('[data-slot="f13-baris-menunggu"]');

            await expect(barisMenunggu).toContainText(PASIEN_WAITING);
            await expect(
                barisMenunggu.locator(
                    '[data-slot="konsultasi-status-badge"][data-status="menunggu_dokter"]',
                ),
            ).toContainText('Menunggu diterima');

            await barisMenunggu.getByRole('button', { name: 'Terima' }).click();

            await expect(barisMenunggu).toHaveCount(0);

            const barisAktif = page
                .locator(`[data-slot="f13-baris-aktif"]`)
                .filter({ hasText: PASIEN_WAITING });

            await expect(barisAktif).toBeVisible();
            await expect(
                barisAktif.locator(
                    '[data-slot="konsultasi-status-badge"][data-status="berlangsung"]',
                ),
            ).toContainText('Berlangsung');

            expect(
                state.counts[`PUT /api/v1/konsultasi/${KONSULTASI_MENUNGGU}/terima`],
            ).toBe(1);

            const toast = page.locator('[data-sonner-toast]');

            await expect(toast).toContainText('Konsultasi diterima.');
            await expect(toast).not.toContainText(PASIEN_WAITING);

            expect(await page.title()).toBe('Dasbor Dokter');
            expect(page.url()).not.toContain(PASIEN_WAITING);

            await expectNoA11yViolations(page);
        });

        test('f13-ac3-jalur-ke-resep', async ({ page }) => {
            const state = await bukaDasbor(page);

            const adaTerpotong = await page.evaluate(() => {
                const area = document.querySelectorAll('[data-slot="f13-keluhan"]');

                let jumlah = 0;

                for (const akar of area) {
                    if (
                        akar.classList.contains('truncate') ||
                        akar.classList.contains('line-clamp-1') ||
                        akar.classList.contains('line-clamp-2') ||
                        akar.classList.contains('line-clamp-3')
                    ) {
                        jumlah += 1;
                    }
                }

                return jumlah;
            });

            expect(adaTerpotong).toBe(0);

            await page
                .locator('[data-slot="f13-baris-antrean"][data-booking-id="1"]')
                .getByRole('link', { name: 'Buka konsultasi' })
                .click();

            await expect(page).toHaveURL(
                new RegExp(`/konsultasi/${KONSULTASI_MENUNGGU}$`),
            );

            await page
                .locator('[data-slot="f13-aksi-dokter"]')
                .getByRole('link', { name: 'Tulis resep' })
                .click();

            await expect(page).toHaveURL(
                new RegExp(`/konsultasi/${KONSULTASI_MENUNGGU}/resep$`),
            );

            await page
                .locator('[data-slot="obat-autocomplete"]')
                .getByLabel('Cari obat')
                .fill('amo');

            await expect
                .poll(() => state.obatUrls.length, { timeout: 10_000 })
                .toBeGreaterThanOrEqual(1);

            expect(state.obatUrls.some((u) => u.includes('search=amo'))).toBe(true);

            const komposerTerpotong = await page.evaluate(() => {
                const area = document.querySelectorAll('[data-slot="resep-composer"]');

                let jumlah = 0;

                for (const akar of area) {
                    for (const elemen of akar.querySelectorAll('*')) {
                        if (
                            elemen.classList.contains('truncate') ||
                            elemen.classList.contains('line-clamp-1') ||
                            elemen.classList.contains('line-clamp-2') ||
                            elemen.classList.contains('line-clamp-3')
                        ) {
                            jumlah += 1;
                        }
                    }
                }

                return jumlah;
            });

            expect(komposerTerpotong).toBe(0);

            await expectNoA11yViolations(page);
        });

        test('f13-ac4-keyboard-dan-axe', async ({ page }) => {
            await bukaDasbor(page);

            const pemicu = page.getByRole('button', { name: 'Pintasan keyboard' });

            await page.keyboard.press('?');

            const dialog = page.locator('[data-slot="f13-pintasan"]');

            await expect(dialog).toBeVisible();
            await expect(dialog).toContainText('Pintasan');

            expect(await dialog.locator('dl > div').count()).toBeGreaterThanOrEqual(4);

            await dialog.getByText('Buka daftar pintasan').waitFor();

            await page.keyboard.press('Escape');

            await expect(dialog).toHaveCount(0);
            await expect(pemicu).toBeFocused();

            const baris = page.locator('[data-slot="f13-baris-antrean"]');

            await baris.nth(0).focus();
            await expect(baris.nth(0)).toBeFocused();

            await page.keyboard.press('ArrowDown');

            await expect(baris.nth(1)).toBeFocused();

            await page.keyboard.press('Enter');

            await expect(page).toHaveURL(
                new RegExp(`/konsultasi/${KONSULTASI_AKTIF}$`),
            );

            await page.goto('/dokter/dashboard');

            await expect(page.locator('[data-slot="f13-antrean"]')).toBeVisible();

            const aksi = page.locator('[data-testid="f13-aksi"]');
            const jumlah = await aksi.count();

            expect(jumlah).toBeGreaterThan(0);

            for (let index = 0; index < jumlah; index += 1) {
                const kotak = await aksi.nth(index).boundingBox();

                expect(kotak, 'aksi harus punya bounding box').not.toBeNull();
                expect(
                    Math.round((kotak as { height: number }).height),
                    await aksi.nth(index).innerText(),
                ).toBeGreaterThanOrEqual(44);
            }

            await expectNoA11yViolations(page);
        });

        test('f13-ac5-kepadatan-persisten', async ({ page }) => {
            await bukaDasbor(page);

            const keluhan = page.locator('[data-slot="f13-keluhan"]');

            await expect(keluhan).toHaveCount(2);

            await page.getByRole('button', { name: 'Ringkas' }).click();

            await expect(keluhan).toHaveCount(0);

            await simpanGambar(page, vp, 'ringkas');

            await page.reload();

            await expect(page.locator('[data-slot="f13-antrean"]')).toBeVisible();

            await expect(
                page.getByRole('button', { name: 'Ringkas' }),
            ).toHaveAttribute('aria-pressed', 'true');

            await expect(keluhan).toHaveCount(0);

            await page.getByRole('button', { name: 'Lengkap' }).click();

            await expect(keluhan).toHaveCount(2);
            await expect(keluhan.first()).toContainText(KELUHAN);

            await expectNoA11yViolations(page);
        });

        test('f13-ac6-race-422', async ({ page }) => {
            await masukPalsu(page);
            await pasangRealtimePalsu(page);

            const state = await pasangMock(page, { terimaStatus: 422 });

            await page.goto('/dokter/dashboard');

            await expect(page.locator('[data-slot="f13-menunggu"]')).toBeVisible();

            await page
                .locator('[data-slot="f13-baris-menunggu"]')
                .getByRole('button', { name: 'Terima' })
                .click();

            const alert = page.locator('[data-slot="f13-race"]');

            await expect(alert).toBeVisible();
            await expect(alert).toHaveAttribute('role', 'alert');
            await expect(alert).toContainText(
                'Konsultasi sudah dimulai di perangkat lain. Daftar disegarkan.',
            );

            const barisAktif = page
                .locator('[data-slot="f13-baris-aktif"]')
                .filter({ hasText: PASIEN_WAITING });

            await expect(barisAktif).toBeVisible();
            await expect(
                barisAktif.locator(
                    '[data-slot="konsultasi-status-badge"][data-status="berlangsung"]',
                ),
            ).toBeVisible();

            await expect(page.locator('[data-slot="f13-baris-antrean"]')).toHaveCount(2);

            expect(
                state.counts[`PUT /api/v1/konsultasi/${KONSULTASI_MENUNGGU}/terima`],
            ).toBe(1);
            expect(state.konsultasiRequests).toBeGreaterThanOrEqual(2);

            await expectNoA11yViolations(page);
        });

        test('f13-ac7-offline', async ({ page, context }) => {
            const state = await bukaDasbor(page);

            const terima = page
                .locator('[data-slot="f13-baris-menunggu"]')
                .getByRole('button', { name: 'Terima' });

            await expect(terima).toBeEnabled();

            await context.setOffline(true);

            const banner = page.getByTestId('offline-banner');

            await expect(banner).toBeVisible();
            await expect(terima).toBeDisabled();

            await simpanGambar(page, vp, 'offline');

            const sebelum = state.konsultasiRequests;

            await context.setOffline(false);

            await expect(banner).toHaveCount(0);
            await expect(terima).toBeEnabled();

            await expect
                .poll(() => state.konsultasiRequests, { timeout: 10_000 })
                .toBeGreaterThan(sebelum);

            await expectNoA11yViolations(page);
        });

        test('f13-ac8-kosong-dan-error', async ({ page }) => {
            await masukPalsu(page);
            await pasangRealtimePalsu(page);

            const state = await pasangMock(page, { bookings: [] });

            await page.goto('/dokter/dashboard');

            await expect(
                page.getByText(new RegExp(`Belum ada booking pada`)),
            ).toBeVisible();

            const lihatBesok = page.getByRole('button', { name: 'Lihat jadwal besok' });

            await expect(lihatBesok).toBeVisible();

            await simpanGambar(page, vp, 'kosong');

            await lihatBesok.click();

            await expect
                .poll(() =>
                    state.bookingUrls.some(
                        (u) => u.includes(`tanggal=${tanggalGeser(1)}`),
                    ),
                )
                .toBe(true);

            state.bookings = [
                bookingUji(
                    1,
                    'BK20261001AAA001',
                    tanggalGeser(0),
                    '09:00:00',
                    '09:15:00',
                    PASIEN_WAITING,
                    KELUHAN,
                ),
            ];
            state.bookingGagal = true;

            await page.getByRole('button', { name: 'Semua' }).click();

            const galat = page.locator('[data-slot="error-state"]');

            await expect(galat).toBeVisible({ timeout: 20_000 });
            await expect(galat).toContainText('Gagal memuat antrean.');

            await galat.scrollIntoViewIfNeeded();
            await simpanGambar(page, vp, 'error', false);

            const sebelum = state.bookingUrls.filter((u) => !u.includes('tanggal=')).length;

            state.bookingGagal = false;

            await galat.getByRole('button', { name: 'Coba lagi' }).click();

            await expect(page.locator('[data-slot="f13-baris-antrean"]')).toHaveCount(1);

            await expect
                .poll(() => state.bookingUrls.filter((u) => !u.includes('tanggal=')).length)
                .toBe(sebelum + 1);

            await expectNoA11yViolations(page);
        });

        test('f13-ac9-privasi-teks', async ({ page }) => {
            await masukPalsu(page);
            await pasangRealtimePalsu(page);

            const state = await pasangMock(page);

            await page.goto('/dokter/dashboard');

            await expect(page.locator('[data-slot="f13-menunggu"]')).toBeVisible();

            await page
                .locator('[data-slot="f13-baris-menunggu"]')
                .getByRole('button', { name: 'Terima' })
                .click();

            const toastTerima = page.locator('[data-sonner-toast]');

            await expect(toastTerima).toContainText('Konsultasi diterima.');
            await expect(toastTerima).not.toContainText(PASIEN_WAITING);

            expect(await page.title()).toBe('Dasbor Dokter');
            expect(page.url()).not.toContain(PASIEN_WAITING);
            expect(page.url()).not.toContain('Demam');

            await page.goto(`/konsultasi/${KONSULTASI_MENUNGGU}/resep`);

            await page
                .locator('[data-slot="obat-autocomplete"]')
                .getByLabel('Cari obat')
                .fill('amo');

            await page
                .locator('[data-slot="obat-autocomplete-pilihan"]')
                .first()
                .click();

            await page.getByRole('button', { name: 'Simpan resep' }).click();

            const toastResep = page.locator('[data-sonner-toast]');

            await expect(toastResep).toContainText('Resep berhasil dibuat.');
            await expect(toastResep).not.toContainText(OBAT_GENERIK);

            expect(
                state.counts[`POST /api/v1/konsultasi/${KONSULTASI_MENUNGGU}/resep`],
            ).toBe(1);

            expect(await page.title()).not.toContain(OBAT_GENERIK);
            expect(await page.title()).not.toContain(PASIEN_WAITING);
            expect(page.url()).not.toContain(PASIEN_WAITING);
            expect(page.url()).not.toContain(KELUHAN);
            expect(page.url()).not.toContain(OBAT_GENERIK);

            const teksHalaman = await page.locator('body').innerText();

            expect(teksHalaman).not.toContain(PASIEN_WAITING);

            // Workaround: sonner's transient toast node trips axe, so wait for it to dismiss first.
            await expect(page.locator('[data-sonner-toast]')).toHaveCount(0, {
                timeout: 10_000,
            });

            await expectNoA11yViolations(page);
        });

        test('f13-ac10-surat-keterangan', async ({ page }) => {
            await masukPalsu(page);
            await pasangRealtimePalsu(page);

            const state = await pasangMock(page);

            await page.goto(`/konsultasi/${KONSULTASI_MENUNGGU}`);

            await expect(
                page.locator('[data-slot="f13-aksi-dokter"]'),
            ).toBeVisible();

            await page.getByRole('button', { name: 'Surat keterangan' }).click();

            const dialog = page.locator('[data-slot="dialog-content"]');

            await expect(dialog).toBeVisible();

            await dialog.getByRole('button', { name: 'Simpan surat' }).click();

            await expect(dialog).toHaveCount(0);

            const hasil = page.locator('[data-slot="f13-surat-hasil"]');

            await expect(hasil).toBeVisible();
            await expect(hasil).toContainText('Nomor surat');
            await expect(
                hasil.locator('[data-slot="f13-nomor-surat"]'),
            ).toContainText(SURAT_NOMOR);

            expect(
                state.counts[
                    `POST /api/v1/konsultasi/${KONSULTASI_MENUNGGU}/surat-keterangan`
                ],
            ).toBe(1);

            const toast = page.locator('[data-sonner-toast]');

            await expect(toast).toContainText('Surat keterangan dibuat.');
            await expect(toast).not.toContainText(SURAT_NOMOR);
            await expect(toast).not.toContainText(PASIEN_WAITING);

            await expectNoA11yViolations(page);
        });
    });
}
