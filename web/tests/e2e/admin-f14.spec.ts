import { expect, test, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F14's acceptance criteria, mocked end to end.
 *
 * ## Why every request is intercepted
 *
 * The F14 surfaces read sixteen `/api/v1/admin/*` routes, and a live spec would
 * need an admin account, seeded bookings, STR dates inside a 60-day window and a
 * schedule with an occupied slot - four fixtures the shared database does not
 * promise at any given moment. `page.route` makes each branch deterministic:
 * the 422s (overlap, delete-refusal, duplicate leave), the blast-radius counts
 * and the masked credential are all produced on demand. No live backend and no
 * real health data are involved.
 *
 * ## The session is seeded, not registered
 *
 * `RequireAuth` checks `sessionStorage` for an access token, so an init script
 * writes a pair before the first navigation. Nothing validates it: every call
 * is mocked and the transport's 401 path is never reached.
 *
 * ## Both viewports, every criterion
 *
 * Each test runs twice, at 390x844 and 1280x900, because the F14 pattern makes
 * the target sizes, the sheet form and the hidden nav mobile requirements. The
 * timezone is pinned to `Asia/Jakarta` so the required `WIB` label is
 * deterministic on any machine.
 */

const VIEWPORTS = [
    { nama: 'mobile', width: 390, height: 844 },
    { nama: 'desktop', width: 1280, height: 900 },
] as const;

const STR_PENUH = '3312345678901234';
const STR_MASKED = '3312••••••••1234';

const ADMIN = {
    id: 99,
    uuid: '00000000-0000-4000-8000-000000000099',
    nama_lengkap: 'Admin Uji F14',
    no_telepon: '081200000099',
    email: 'admin@contoh.example',
    tipe: 'admin',
    status: 'aktif',
    bahasa: 'id',
    foto_profil: null,
    telepon_terverifikasi: true,
    email_terverifikasi: true,
    last_login_at: null,
    dibuat_at: '2026-01-01T00:00:00.000000Z',
};

const BUKAN_ADMIN = {
    ...ADMIN,
    id: 98,
    nama_lengkap: 'Apoteker Uji F14',
    tipe: 'apoteker',
};

const NAMA_DOKTER = [
    'dr. Anisa Rahmawati',
    'dr. Budi Santoso, Sp.PD',
    'dr. Citra Dewi, Sp.A',
    'dr. Dedi Kurniawan',
    'dr. Eka Putri',
    'dr. Fajar Nugroho',
    'dr. Gita Lestari',
];

const TANGGAL_STR = [
    '2026-10-20',
    '2026-11-12',
    '2027-03-03',
    '2026-12-01',
    '2027-01-15',
    '2027-02-20',
    '2027-04-10',
];

function buatDokter(index: number, over: Record<string, unknown> = {}) {
    const nomor = index + 1;

    return {
        id: nomor,
        user_id: 100 + nomor,
        nama_lengkap: NAMA_DOKTER[index],
        akun_dihapus: false,
        tipe: 'dokter_spesialis',
        nomor_str: STR_MASKED,
        str_berlaku_sampai: TANGGAL_STR[index],
        str_sisa_hari: index === 0 ? 35 : index === 1 ? 58 : 300,
        str_kedaluwarsa: false,
        str_segera_kedaluwarsa: index <= 1,
        nomor_sip: '3100••••••••88',
        sip_berlaku_sampai: '2027-12-31',
        sip_sisa_hari: 400,
        sip_kedaluwarsa: false,
        sip_segera_kedaluwarsa: false,
        status_verifikasi:
            index === 0 ? 'pending' : index === 2 ? 'ditolak' : 'terverifikasi',
        status_aktif: index === 2 ? false : true,
        tersedia_telemedisin: true,
        pengalaman_tahun: 10,
        biaya_konsultasi_online: '350000.00',
        rating_rata_rata: '4.80',
        jumlah_ulasan: 100,
        jumlah_konsultasi: 340,
        jumlah_booking: 120,
        spesialisasi_utama: {
            spesialisasi_id: 1,
            kode: 'PD',
            nama: 'Penyakit Dalam',
            is_utama: true,
        },
        dibuat_at: '2026-01-01T00:00:00.000000Z',
        diubah_at: '2026-01-01T00:00:00.000000Z',
        ...over,
    };
}

function dokterAwal(): Array<Record<string, unknown>> {
    return NAMA_DOKTER.map((_nama, index) => buatDokter(index));
}

function jadwalAwal(): Array<Record<string, unknown>> {
    return [
        {
            id: 11,
            dokter_id: 1,
            faskes_id: null,
            tipe_layanan: 'klinik',
            hari: 1,
            hari_label: 'Senin',
            jam_mulai: '08:00:00',
            jam_selesai: '12:00:00',
            durasi_slot_menit: 15,
            kuota_per_sesi: 12,
            berlaku_mulai: '2026-10-06',
            berlaku_sampai: null,
            status_aktif: true,
            booking_aktif: 3,
            dibuat_at: '2026-10-01T00:00:00.000000Z',
            diubah_at: '2026-10-01T00:00:00.000000Z',
        },
        {
            id: 12,
            dokter_id: 1,
            faskes_id: null,
            tipe_layanan: 'online',
            hari: 3,
            hari_label: 'Rabu',
            jam_mulai: '14:00:00',
            jam_selesai: '16:00:00',
            durasi_slot_menit: 20,
            kuota_per_sesi: null,
            berlaku_mulai: '2026-10-06',
            berlaku_sampai: null,
            status_aktif: true,
            booking_aktif: 0,
            dibuat_at: '2026-10-01T00:00:00.000000Z',
            diubah_at: '2026-10-01T00:00:00.000000Z',
        },
    ];
}

const LIBUR_AWAL = [
    {
        id: 21,
        dokter_id: 1,
        tanggal: '2026-10-17',
        alasan: 'Acara keluarga',
    },
];

const AUDIT = [
    {
        id: 32,
        user_id: null,
        aksi: 'create',
        tabel_target: 'dokter',
        record_id: '12|34',
        data_lama: null,
        data_baru: { nomor_str: STR_MASKED, status_verifikasi: 'pending' },
        ip_address: '127.0.0.1',
        user_agent: 'Playwright',
        endpoint: '/api/v1/admin/dokter/1/verifikasi',
        dibuat_at: '2026-10-02T02:14:00.000000Z',
    },
    {
        id: 31,
        user_id: 7,
        aksi: 'update',
        tabel_target: 'dokter_jadwal',
        record_id: '35',
        data_lama: { jam_selesai: '11:00:00' },
        data_baru: { jam_selesai: '12:00:00' },
        ip_address: '127.0.0.1',
        user_agent: 'Playwright',
        endpoint: '/api/v1/admin/jadwal/35',
        dibuat_at: '2026-10-02T02:10:00.000000Z',
    },
];

const PDP = [
    {
        id: 42,
        user_id: 5,
        jenis: 'komunikasi_tindak_lanjut',
        versi_dokumen: 'v01',
        disetujui: false,
        disetujui_at: '2026-09-30T04:00:00.000000Z',
    },
    {
        id: 41,
        user_id: 5,
        jenis: 'berbagi_data_medis',
        versi_dokumen: 'v01',
        disetujui: true,
        disetujui_at: '2026-09-30T03:00:00.000000Z',
    },
];

const LAPORAN_BOOKING = {
    ringkasan: {
        total: 128,
        per_status: {
            menunggu_pembayaran: 2,
            terjadwal: 10,
            check_in: 3,
            berlangsung: 1,
            selesai: 112,
            dibatalkan: 9,
            no_show: 0,
            kadaluarsa: 4,
        },
    },
    harian: [
        {
            tanggal: '2026-09-29',
            total: 8,
            per_status: { selesai: 7, dibatalkan: 1, no_show: 0 },
        },
        {
            tanggal: '2026-09-30',
            total: 6,
            per_status: { selesai: 5, dibatalkan: 1, no_show: 0 },
        },
    ],
};

const LAPORAN_PENDAPATAN = {
    ringkasan: { total: '1245000.00', jumlah_invoice: 28 },
    harian: [
        { tanggal: '2026-09-29', total: '810000.00', jumlah_invoice: 18 },
        { tanggal: '2026-09-30', total: '620000.00', jumlah_invoice: 10 },
    ],
};

const LAPORAN_KEHADIRAN = {
    ringkasan: { total: 115, check_in: 3, selesai: 112, no_show: 0 },
    harian: [
        { tanggal: '2026-09-29', total: 7, check_in: 0, selesai: 7, no_show: 0 },
        { tanggal: '2026-09-30', total: 5, check_in: 0, selesai: 5, no_show: 0 },
    ],
};

type Permintaan = {
    method: string;
    path: string;
    url: string;
    body: unknown;
};

type OpsiMock = {
    user?: Record<string, unknown>;
    dokter?: Array<Record<string, unknown>>;
    jadwal?: Array<Record<string, unknown>>;
    liburDuplikat?: boolean;
    peringatanLedger?: boolean;
    auditStatus?: number;
    verifyStatus?: number;
    postJadwalStatus?: number;
    deleteJadwalStatus?: number;
    halamanTerakhir?: number;
};

type Catatan = {
    permintaan: Permintaan[];
};

async function balasJson(route: Route, status: number, body: unknown): Promise<void> {
    await route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

function metaHalaman(total: number, lastPage = 1): Record<string, unknown> {
    return {
        current_page: 1,
        last_page: lastPage,
        per_page: 15,
        total,
        from: total === 0 ? null : 1,
        to: total === 0 ? null : total,
    };
}

/**
 * Intercept every `/api/v1` call the F14 screens make.
 *
 * The handler is stateful: a `POST` appends to the same array the next `GET`
 * serves, so the assertions read what the UI did rather than what a second
 * canned payload claims. The catch-all at the bottom is load-bearing - an
 * unmocked request would reach the real API, answer 401 and send the transport
 * down its session-expiry path, redirecting the test to `/login` for a reason
 * that has nothing to do with the assertion.
 */
async function pasangMock(page: Page, opsi: OpsiMock = {}): Promise<Catatan> {
    const catatan: Catatan = { permintaan: [] };

    const state = {
        user: opsi.user ?? ADMIN,
        dokter: opsi.dokter ?? dokterAwal(),
        jadwal: opsi.jadwal ?? jadwalAwal(),
        libur: [...LIBUR_AWAL],
    };

    await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;
        const method = request.method();

        let body: unknown = null;

        try {
            body = request.postDataJSON();
        } catch {
            body = null;
        }

        catatan.permintaan.push({ method, path, url: request.url(), body });

        const sukses = (
            data: unknown,
            meta?: Record<string, unknown>,
        ): Promise<void> =>
            balasJson(route, 200, {
                success: true,
                message: 'Berhasil.',
                data,
                ...(meta === undefined ? {} : { meta }),
            });

        const gagal = (
            status: number,
            message: string,
            errors: Record<string, string[]>,
        ): Promise<void> =>
            balasJson(route, status, { success: false, message, errors });

        if (path === '/api/v1/me') {
            return sukses({ user: state.user });
        }

        if (path === '/api/v1/notifikasi') {
            return sukses({ notifikasi: [] }, { ...metaHalaman(0), unread: 0 });
        }

        if (path === '/api/v1/admin/dokter' && method === 'GET') {
            return sukses(
                { dokter: state.dokter },
                metaHalaman(state.dokter.length, opsi.halamanTerakhir ?? 1),
            );
        }

        const detail = /^\/api\/v1\/admin\/dokter\/(\d+)$/.exec(path);

        if (detail !== null && method === 'GET') {
            const dokter = state.dokter.find((row) => row.id === Number(detail[1]));

            if (dokter === undefined) {
                return gagal(404, 'Resource not found.', {});
            }

            return sukses({
                dokter,
                dampak: { booking_aktif: 4, jadwal_aktif: 6, libur_mendatang: 1 },
            });
        }

        const verifikasi = /^\/api\/v1\/admin\/dokter\/(\d+)\/verifikasi$/.exec(path);

        if (verifikasi !== null && method === 'PUT') {
            if (opsi.verifyStatus !== undefined && opsi.verifyStatus !== 200) {
                return gagal(opsi.verifyStatus, 'Data yang dikirim tidak valid.', {
                    status_verifikasi: [
                        'Status verifikasi dokter ini sudah berubah, bukan pending. Muat ulang.',
                    ],
                });
            }

            const dokter = state.dokter.find(
                (row) => row.id === Number(verifikasi[1]),
            );

            if (dokter === undefined) {
                return gagal(404, 'Resource not found.', {});
            }

            const isi = body as { status_verifikasi?: string };
            dokter.status_verifikasi = isi.status_verifikasi ?? 'terverifikasi';

            return sukses({
                dokter,
                dampak: { booking_aktif: 4, jadwal_aktif: 6, libur_mendatang: 1 },
            });
        }

        const status = /^\/api\/v1\/admin\/dokter\/(\d+)\/status$/.exec(path);

        if (status !== null && method === 'PUT') {
            const dokter = state.dokter.find((row) => row.id === Number(status[1]));

            if (dokter === undefined) {
                return gagal(404, 'Resource not found.', {});
            }

            const isi = body as {
                status_aktif?: boolean;
                tersedia_telemedisin?: boolean;
            };

            if (typeof isi.status_aktif === 'boolean') {
                dokter.status_aktif = isi.status_aktif;
            }

            if (typeof isi.tersedia_telemedisin === 'boolean') {
                dokter.tersedia_telemedisin = isi.tersedia_telemedisin;
            }

            return sukses({
                dokter,
                dampak: { booking_aktif: 4, jadwal_aktif: 6, libur_mendatang: 1 },
            });
        }

        const jadwalDokter = /^\/api\/v1\/admin\/dokter\/(\d+)\/jadwal$/.exec(path);

        if (jadwalDokter !== null && method === 'GET') {
            return sukses({ jadwal: state.jadwal });
        }

        if (jadwalDokter !== null && method === 'POST') {
            if (opsi.postJadwalStatus !== undefined && opsi.postJadwalStatus !== 201) {
                return gagal(opsi.postJadwalStatus, 'Data yang dikirim tidak valid.', {
                    hari: [
                        'Jadwal Jumat 08.00-10.00 bertumpuk dengan jadwal 09.00-11.00 WIB pada hari yang sama.',
                    ],
                    jam_selesai: ['Jam selesai harus lebih besar dari jam mulai.'],
                });
            }

            const isi = body as {
                hari?: number[];
                tipe_layanan?: string;
                jam_mulai?: string;
                jam_selesai?: string;
                durasi_slot_menit?: number;
                kuota_per_sesi?: number | null;
                berlaku_mulai?: string;
                berlaku_sampai?: string | null;
                status_aktif?: boolean;
            };

            const label = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

            const baru = (isi.hari ?? []).map((satuHari, index) => ({
                id: 500 + state.jadwal.length + index,
                dokter_id: Number(jadwalDokter[1]),
                faskes_id: null,
                tipe_layanan: isi.tipe_layanan ?? 'klinik',
                hari: satuHari,
                hari_label: label[satuHari],
                jam_mulai: `${isi.jam_mulai ?? '08:00'}:00`,
                jam_selesai: `${isi.jam_selesai ?? '12:00'}:00`,
                durasi_slot_menit: isi.durasi_slot_menit ?? 15,
                kuota_per_sesi: isi.kuota_per_sesi ?? null,
                berlaku_mulai: isi.berlaku_mulai ?? '2026-10-06',
                berlaku_sampai: isi.berlaku_sampai ?? null,
                status_aktif: isi.status_aktif === true,
                booking_aktif: 0,
                dibuat_at: '2026-10-02T00:00:00.000000Z',
                diubah_at: '2026-10-02T00:00:00.000000Z',
            }));

            state.jadwal.push(...baru);

            return balasJson(route, 201, {
                success: true,
                message: 'Jadwal disimpan.',
                data: { jadwal: baru },
            });
        }

        const jadwalBaris = /^\/api\/v1\/admin\/jadwal\/(\d+)$/.exec(path);

        if (jadwalBaris !== null && method === 'PUT') {
            const baris = state.jadwal.find((row) => row.id === Number(jadwalBaris[1]));

            if (baris === undefined) {
                return gagal(404, 'Resource not found.', {});
            }

            const isi = body as Record<string, unknown>;

            for (const [key, value] of Object.entries(isi)) {
                baris[key] = value;
            }

            return sukses({ jadwal: baris });
        }

        if (jadwalBaris !== null && method === 'DELETE') {
            if (opsi.deleteJadwalStatus === 422) {
                return gagal(422, 'Data yang dikirim tidak valid.', {
                    jadwal: [
                        'Jadwal tidak dapat dihapus karena masih direferensikan 2 booking (1 aktif/akan datang). Nonaktifkan jadwal agar tidak menerima booking baru - booking yang ada tetap berjalan.',
                    ],
                });
            }

            const id = Number(jadwalBaris[1]);
            state.jadwal = state.jadwal.filter((row) => row.id !== id);

            return sukses({ deleted: true, id });
        }

        const liburDokter = /^\/api\/v1\/admin\/dokter\/(\d+)\/libur$/.exec(path);

        if (liburDokter !== null && method === 'GET') {
            return sukses({ libur: state.libur });
        }

        if (liburDokter !== null && method === 'POST') {
            if (opsi.liburDuplikat === true) {
                return gagal(422, 'Data yang dikirim tidak valid.', {
                    tanggal: ['Tanggal ini sudah tercatat libur untuk dokter tersebut.'],
                });
            }

            const isi = body as { tanggal?: string; alasan?: string };

            const baru = {
                id: 900 + state.libur.length,
                dokter_id: Number(liburDokter[1]),
                tanggal: isi.tanggal ?? null,
                alasan: isi.alasan ?? null,
            };

            state.libur.push(baru);

            return balasJson(route, 201, {
                success: true,
                message: 'Libur ditambahkan.',
                data: { libur: baru },
            });
        }

        const liburBaris = /^\/api\/v1\/admin\/libur\/(\d+)$/.exec(path);

        if (liburBaris !== null && method === 'DELETE') {
            const id = Number(liburBaris[1]);
            state.libur = state.libur.filter((row) => row.id !== id);

            return sukses({ deleted: true, id });
        }

        if (path === '/api/v1/admin/laporan/booking') {
            return sukses(LAPORAN_BOOKING);
        }

        if (path === '/api/v1/admin/laporan/pendapatan') {
            return sukses(
                LAPORAN_PENDAPATAN,
                opsi.peringatanLedger === true
                    ? { peringatan_ledger: true }
                    : undefined,
            );
        }

        if (path === '/api/v1/admin/laporan/kehadiran') {
            return sukses(LAPORAN_KEHADIRAN);
        }

        if (path === '/api/v1/admin/audit-log') {
            if (opsi.auditStatus === 403) {
                return gagal(403, 'Akun ini tidak berhak mengakses data tersebut.', {});
            }

            return sukses({ audit: AUDIT }, metaHalaman(AUDIT.length));
        }

        if (path === '/api/v1/admin/persetujuan-pdp') {
            return sukses({ persetujuan_pdp: PDP }, metaHalaman(PDP.length));
        }

        return sukses({});
    });

    return catatan;
}

async function masukPalsu(page: Page): Promise<void> {
    await page.addInitScript(() => {
        sessionStorage.setItem('sehatly.access_token', 'token-uji-f14');
        sessionStorage.setItem('sehatly.refresh_token', 'refresh-uji-f14');
    });
}

async function bukaNavigasiMobile(page: Page, lebar: number): Promise<void> {
    if (lebar >= 768) {
        return;
    }

    await page
        .getByRole('button', { name: 'Buka atau tutup menu navigasi' })
        .click();
}

async function tinggiAksiSetidaknya44(page: Page): Promise<void> {
    const aksi = page.locator('[data-testid="admin-aksi"]');
    const jumlah = await aksi.count();

    expect(jumlah).toBeGreaterThan(0);

    for (let index = 0; index < jumlah; index += 1) {
        const box = await aksi.nth(index).boundingBox();

        expect(box, `admin-aksi #${index} harus punya kotak`).not.toBeNull();
        expect(
            box?.height ?? 0,
            `admin-aksi #${index} tinggi >= 44`,
        ).toBeGreaterThanOrEqual(44);
    }
}

for (const viewport of VIEWPORTS) {
    test.describe(`F14 admin klinik ${viewport.nama}`, () => {
        test.use({
            viewport: { width: viewport.width, height: viewport.height },
            timezoneId: 'Asia/Jakarta',
        });

        test('AC-1 daftar dokter: tiga request, chip Semua, urutan STR, badge 58 hari', async ({
            page,
        }) => {
            await masukPalsu(page);
            const catatan = await pasangMock(page, { halamanTerakhir: 2 });

            await page.goto('/admin/dokter');

            const baris = page.locator('[data-slot="admin-dokter-row"]');
            await expect(baris).toHaveCount(7);

            const getDokter = catatan.permintaan.filter(
                (item) =>
                    item.path === '/api/v1/admin/dokter' && item.method === 'GET',
            );
            expect(getDokter).toHaveLength(1);
            expect(getDokter[0].url).toContain('urutan=str_berlaku_sampai');

            await expect(
                page.getByRole('button', { name: 'Semua' }),
            ).toHaveAttribute('aria-pressed', 'true');

            await expect(baris.first()).toContainText('dr. Anisa Rahmawati');
            await expect(baris.first()).toContainText('20 Okt 2026');
            await expect(baris.nth(1)).toContainText('12 Nov 2026');
            await expect(baris.nth(1)).toContainText('58 hari lagi');

            await expect(page.getByText('1 / 2')).toBeVisible();
            await page.getByRole('button', { name: 'Berikutnya' }).click();

            await expect
                .poll(
                    () =>
                        catatan.permintaan.filter(
                            (item) =>
                                item.path === '/api/v1/admin/dokter' &&
                                item.method === 'GET' &&
                                new URL(item.url).searchParams.get('page') === '2',
                        ).length,
                )
                .toBe(1);

            await expectNoA11yViolations(page);
        });

        test('AC-2 verifikasi: dialog kredensial, 1x PUT, badge inline, privasi', async ({
            page,
        }) => {
            await masukPalsu(page);
            const catatan = await pasangMock(page);

            await page.goto('/admin/dokter');

            const baris = page.locator(
                '[data-slot="admin-dokter-row"][data-dokter-id="1"]',
            );

            await baris.getByRole('button', { name: 'Verifikasi' }).click();

            const dialog = page.getByRole('dialog');
            await expect(dialog).toContainText('dr. Anisa Rahmawati');
            await expect(dialog).toContainText(STR_MASKED);
            await expect(dialog).toContainText('20 Okt 2026');

            await dialog.getByRole('button', { name: 'Verifikasi dokter' }).click();

            await expect(dialog).toBeHidden();

            const put = catatan.permintaan.filter(
                (item) =>
                    item.method === 'PUT' &&
                    item.path === '/api/v1/admin/dokter/1/verifikasi',
            );
            expect(put).toHaveLength(1);
            expect((put[0].body as { status_verifikasi: string }).status_verifikasi).toBe(
                'terverifikasi',
            );

            await expect(baris).toContainText('Terverifikasi');
            await expect(page.getByText('Status dokter diperbarui.')).toBeVisible();

            expect(page.url()).not.toContain(STR_PENUH);
            expect(await page.title()).not.toContain(STR_PENUH);
            expect(await page.locator('body').innerText()).not.toContain(STR_PENUH);

            await expectNoA11yViolations(page);
        });

        test('AC-3 nonaktifkan: tombol menunggu dampak, blast radius, 1x PUT status', async ({
            page,
        }) => {
            await masukPalsu(page);
            const catatan = await pasangMock(page);

            await page.route('**/api/v1/admin/dokter/1', async (route) => {
                await new Promise((resolve) => {
                    setTimeout(resolve, 400);
                });

                await route.fallback();
            });

            await page.goto('/admin/dokter/1');

            const tombol = page.locator('[data-slot="admin-nonaktif-dokter"]');

            await expect(tombol).toHaveCount(0);
            await expect(tombol).toBeVisible({ timeout: 10_000 });
            await expect(tombol).toBeEnabled();

            await tombol.click();

            const dialog = page.getByRole('dialog');
            await expect(dialog).toContainText('4 booking aktif');
            await expect(dialog).toContainText('tidak otomatis dibatalkan');

            await dialog
                .getByRole('button', { name: 'Nonaktifkan dokter' })
                .click();

            const put = catatan.permintaan.filter(
                (item) =>
                    item.method === 'PUT' &&
                    item.path === '/api/v1/admin/dokter/1/status',
            );
            expect(put).toHaveLength(1);
            expect((put[0].body as { status_aktif: boolean }).status_aktif).toBe(false);

            const pembatalan = catatan.permintaan.filter((item) =>
                /batal|cancel/i.test(item.path),
            );
            expect(pembatalan).toHaveLength(0);

            await expect(page.getByText('Status dokter diperbarui.')).toBeVisible();
            await expect(page.locator('body')).toContainText('Nonaktif');

            await expectNoA11yViolations(page);
        });

        test('AC-4 jadwal multi-hari: pratinjau, 1x POST tiga hari, tiga baris draf', async ({
            page,
        }) => {
            await masukPalsu(page);
            const catatan = await pasangMock(page);

            await page.goto('/admin/dokter/1?tab=jadwal');

            await page.getByRole('button', { name: 'Tambah jadwal' }).first().click();

            const sheet = page.getByRole('dialog');

            await sheet.getByRole('checkbox', { name: 'Senin' }).check();
            await sheet.getByRole('checkbox', { name: 'Rabu' }).check();
            await sheet.getByRole('checkbox', { name: 'Kamis' }).check();

            await sheet.getByLabel('Jam mulai').fill('08:00');
            await sheet.getByLabel('Jam selesai').fill('12:00');

            await expect(sheet.getByText('Senin 16 slot')).toBeVisible();
            await expect(sheet.getByText('Rabu 16 slot')).toBeVisible();
            await expect(sheet.getByText('Kamis 16 slot')).toBeVisible();

            await sheet.getByRole('button', { name: 'Simpan sebagai draf' }).click();

            await expect
                .poll(
                    () =>
                        catatan.permintaan.filter(
                            (item) =>
                                item.method === 'POST' &&
                                item.path === '/api/v1/admin/dokter/1/jadwal',
                        ).length,
                )
                .toBe(1);

            const post = catatan.permintaan.find(
                (item) =>
                    item.method === 'POST' &&
                    item.path === '/api/v1/admin/dokter/1/jadwal',
            );

            expect((post?.body as { hari: number[] }).hari).toHaveLength(3);
            expect((post?.body as { status_aktif: boolean }).status_aktif).toBe(false);

            await expect(sheet).toBeVisible();
            await expect(
                sheet.getByText('3 jadwal disimpan sebagai Draf.'),
            ).toBeVisible();

            await sheet.getByRole('button', { name: 'Selesai' }).click();
            await expect(sheet).toBeHidden();

            await expect(
                page.locator(
                    '[data-slot="admin-jadwal-status"][data-status="draf"]',
                ),
            ).toHaveCount(3);

            await expectNoA11yViolations(page);
        });

        test('AC-5 konflik 422: pesan hari+jam+bentrok inline, isi form dipertahankan', async ({
            page,
        }) => {
            await masukPalsu(page);
            await pasangMock(page, { postJadwalStatus: 422 });

            await page.goto('/admin/dokter/1?tab=jadwal');

            await page.getByRole('button', { name: 'Tambah jadwal' }).first().click();

            const sheet = page.getByRole('dialog');

            await sheet.getByRole('checkbox', { name: 'Jumat' }).check();
            await sheet.getByLabel('Jam mulai').fill('08:00');
            await sheet.getByLabel('Jam selesai').fill('07:00');
            await sheet.getByLabel('Durasi per slot (menit)').fill('15');

            await sheet.getByRole('checkbox', { name: 'Batasi kuota per sesi' }).check();
            await sheet.getByLabel('Kuota pasien (opsional)').fill('12');

            await sheet.getByRole('button', { name: 'Simpan sebagai draf' }).click();

            await expect(sheet).toContainText('bertumpuk');
            await expect(sheet).toContainText('Jam selesai harus lebih besar');

            await expect(sheet.getByRole('checkbox', { name: 'Jumat' })).toBeChecked();
            await expect(sheet.getByLabel('Jam mulai')).toHaveValue('08:00');
            await expect(sheet.getByLabel('Jam selesai')).toHaveValue('07:00');
            await expect(sheet.getByLabel('Durasi per slot (menit)')).toHaveValue('15');
            await expect(sheet.getByLabel('Kuota pasien (opsional)')).toHaveValue('12');
            await expect(sheet).toBeVisible();

            await expectNoA11yViolations(page);
        });

        test('AC-6 libur: tanpa jam, duplikat 422 inline, simpan sukses', async ({
            page,
        }) => {
            await masukPalsu(page);
            const opsi: OpsiMock = { liburDuplikat: true };
            const catatan = await pasangMock(page, opsi);

            await page.goto('/admin/dokter/1?tab=libur');

            const panel = page.locator('[aria-label="Libur dokter"]');
            await expect(panel).toBeVisible();
            await expect(panel.getByText('Libur berlaku seharian.')).toBeVisible();
            await expect(panel.locator('input[type="time"]')).toHaveCount(0);

            await panel.getByLabel('Tanggal').fill('2026-10-24');
            await panel.getByRole('button', { name: 'Tambah libur' }).click();

            await expect(panel.getByText(/sudah tercatat libur/)).toBeVisible();

            opsi.liburDuplikat = false;
            await panel.getByRole('button', { name: 'Tambah libur' }).click();

            await expect
                .poll(
                    () =>
                        catatan.permintaan.filter(
                            (item) =>
                                item.method === 'POST' &&
                                item.path === '/api/v1/admin/dokter/1/libur',
                        ).length,
                )
                .toBe(2);

            await expect(page.getByText('Libur ditambahkan.')).toBeVisible();

            await expectNoA11yViolations(page);
        });

        test('AC-7 jadwal berbooking: Nonaktifkan bukan Hapus, 422 -> Nonaktifkan saja', async ({
            page,
        }) => {
            await masukPalsu(page);
            const catatan = await pasangMock(page, { deleteJadwalStatus: 422 });

            await page.goto('/admin/dokter/1?tab=jadwal');

            const terisi = page.locator(
                '[data-slot="admin-jadwal-row"][data-booking-aktif="3"]',
            );
            await expect(
                terisi.getByRole('button', { name: 'Nonaktifkan' }),
            ).toBeVisible();
            await expect(terisi.getByRole('button', { name: 'Hapus' })).toHaveCount(0);

            const kosong = page.locator(
                '[data-slot="admin-jadwal-row"][data-booking-aktif="0"]',
            );
            await kosong.getByRole('button', { name: 'Hapus' }).click();

            const alert = page.locator('[data-slot="admin-hapus-gagal"]');
            await expect(alert).toBeVisible();
            await expect(alert).toContainText('tidak dapat dihapus');
            await expect(alert).toContainText('2 booking');

            await alert.getByRole('button', { name: 'Nonaktifkan saja' }).click();

            await expect
                .poll(
                    () =>
                        catatan.permintaan.filter(
                            (item) =>
                                item.method === 'PUT' &&
                                item.path === '/api/v1/admin/jadwal/12',
                        ).length,
                )
                .toBe(1);

            const put = catatan.permintaan.find(
                (item) =>
                    item.method === 'PUT' && item.path === '/api/v1/admin/jadwal/12',
            );
            expect((put?.body as { status_aktif: boolean }).status_aktif).toBe(false);

            await expect(alert).toHaveCount(0);
            await expect(kosong).toContainText('Draf');

            await expectNoA11yViolations(page);
        });

        test('AC-8 aksi massal: 3 dipilih + dialog nama, 6 dokter butuh ketik NONAKTIFKAN, hasil persisten', async ({
            page,
        }) => {
            await masukPalsu(page);
            const catatan = await pasangMock(page);

            await page.goto('/admin/dokter');

            const tiga = [
                'dr. Dedi Kurniawan',
                'dr. Eka Putri',
                'dr. Fajar Nugroho',
            ];

            for (const nama of tiga) {
                await page.getByRole('checkbox', { name: `Pilih ${nama}` }).check();
            }

            await expect(page.getByText('3 dipilih')).toBeVisible();

            await page.getByRole('button', { name: 'Nonaktifkan' }).click();

            const dialog = page.getByRole('dialog');
            await expect(dialog).toContainText('3 dokter');
            await expect(dialog).toContainText('dr. Dedi Kurniawan');
            await expect(dialog).toContainText('dr. Eka Putri');
            await expect(dialog).toContainText('dr. Fajar Nugroho');

            await dialog
                .getByRole('button', { name: 'Nonaktifkan dokter' })
                .click();

            const hasil = page.locator('[data-slot="admin-hasil-bulk"]');
            await expect(hasil).toContainText('3 berhasil, 0 gagal');
            await page.waitForTimeout(300);
            await expect(hasil).toBeVisible();

            await expect
                .poll(
                    () =>
                        catatan.permintaan.filter(
                            (item) =>
                                item.method === 'PUT' &&
                                /\/status$/.test(item.path),
                        ).length,
                )
                .toBe(3);

            const enam = NAMA_DOKTER.slice(0, 6);

            for (const nama of enam) {
                await page.getByRole('checkbox', { name: `Pilih ${nama}` }).check();
            }

            await expect(page.getByText('6 dipilih')).toBeVisible();

            await page.getByRole('button', { name: 'Nonaktifkan' }).click();

            const dialogEnam = page.getByRole('dialog');
            const konfirmasi = dialogEnam.getByRole('button', {
                name: 'Nonaktifkan dokter',
            });

            await expect(konfirmasi).toBeDisabled();

            const ketik = dialogEnam.getByLabel(
                'Ketik NONAKTIFKAN untuk melanjutkan',
            );

            await ketik.fill('NONAKTIF');
            await expect(konfirmasi).toBeDisabled();

            await ketik.fill('NONAKTIFKAN');
            await expect(konfirmasi).toBeEnabled();
            await konfirmasi.click();

            await expect(hasil).toContainText('6 berhasil, 0 gagal');

            await expect(
                page.locator('[data-slot="admin-dokter-row"][data-dokter-id="1"]'),
            ).toContainText('Nonaktif');

            await expectNoA11yViolations(page);
        });

        test('AC-9 laporan: tiga kartu + tabel, chip ubah URL, tanpa ekspor, peringatan ledger', async ({
            page,
        }) => {
            await masukPalsu(page);
            const catatan = await pasangMock(page, { peringatanLedger: true });

            await page.goto('/admin/laporan');

            const kartu = page.locator('[data-slot="card-title"]');

            await expect(kartu.filter({ hasText: 'Total booking' })).toBeVisible();
            await expect(kartu.filter({ hasText: 'Pendapatan' })).toBeVisible();
            await expect(kartu.filter({ hasText: 'Kehadiran' })).toBeVisible();
            await expect(page.getByRole('table')).toBeVisible();
            await expect(page.getByText(/1\.245\.000/)).toBeVisible();

            await expect(page.getByRole('alert')).toContainText(
                'Angka pendapatan dapat berubah setelah perbaikan pencatatan pembatalan.',
            );

            const sebelum = catatan.permintaan.filter(
                (item) => item.path === '/api/v1/admin/laporan/booking',
            ).length;

            await page.getByRole('button', { name: '7 hari' }).click();

            await expect
                .poll(() => {
                    const url = new URL(page.url());

                    return url.searchParams.get('dari') !== null &&
                        url.searchParams.get('sampai') !== null;
                })
                .toBe(true);

            await expect
                .poll(
                    () =>
                        catatan.permintaan.filter(
                            (item) =>
                                item.path === '/api/v1/admin/laporan/booking',
                        ).length,
                )
                .toBeGreaterThan(sebelum);

            await expect(
                page.getByRole('button', { name: /unduh|ekspor|export/i }),
            ).toHaveCount(0);
            await expect(
                page.getByRole('link', { name: /unduh|ekspor|export/i }),
            ).toHaveCount(0);
            await expect(
                page.getByText(
                    'Ekspor belum tersedia pada versi ini. Gunakan cetak peramban bila perlu.',
                ),
            ).toBeVisible();

            await expect(page.getByText('Tidak hadir').first()).toBeVisible();
            await expect(page.getByText('—').first()).toBeVisible();

            await expectNoA11yViolations(page);
        });

        test('AC-10 audit: baris waktu/aktor/aksi/tabel, filter tabel, masked, tanpa aksi tulis', async ({
            page,
        }) => {
            await masukPalsu(page);
            const catatan = await pasangMock(page);

            await page.goto('/admin/audit-log');

            const barisMasked = page
                .locator('[data-slot="admin-audit-row"]')
                .filter({ hasText: 'nomor_str' });

            await expect(barisMasked.first()).toBeVisible();

            const teks = await barisMasked.first().innerText();
            expect(teks).toMatch(/3312•+1234/);
            expect(teks).not.toMatch(/\d{16}/);

            await expect(page.getByText('Pengguna #7')).toBeVisible();
            await expect(
                page
                    .locator('[data-slot="admin-audit-row"]')
                    .filter({ hasText: 'dokter_jadwal' })
                    .first(),
            ).toBeVisible();

            await page.getByLabel('Tabel').fill('dokter_jadwal');
            await page.getByRole('button', { name: 'Terapkan filter' }).click();

            await expect
                .poll(
                    () =>
                        catatan.permintaan.filter(
                            (item) =>
                                item.path === '/api/v1/admin/audit-log' &&
                                new URL(item.url).searchParams.get(
                                    'tabel_target',
                                ) === 'dokter_jadwal',
                        ).length,
                )
                .toBe(1);

            await expect(
                page.getByRole('button', {
                    name: /^(ubah|edit|hapus|delete|ekspor|export|unduh|download)$/i,
                }),
            ).toHaveCount(0);
            await expect(
                page.getByRole('link', {
                    name: /^(ubah|edit|hapus|delete|ekspor|export|unduh|download)$/i,
                }),
            ).toHaveCount(0);
            await expect(
                page.getByText(
                    'Jejak audit bersifat hanya-baca dan tidak dapat diubah atau dihapus.',
                ),
            ).toBeVisible();

            await expectNoA11yViolations(page);
        });

        test('AC-11 rbac: 403 -> ForbiddenState, tanpa tautan klinis, tanpa nav admin', async ({
            page,
        }) => {
            await masukPalsu(page);
            await pasangMock(page, { auditStatus: 403 });

            await page.goto('/admin/audit-log');

            await expect(page.locator('[data-slot="forbidden-state"]')).toBeVisible();
            await expect(
                page.getByText('Anda tidak memiliki izin untuk membuka halaman ini.'),
            ).toBeVisible();

            await bukaNavigasiMobile(page, viewport.width);

            /**
             * The shell renders the patient menu for an `admin` account now, so the
             * clinical groups are absent for the same reason they always were here
             * (the role holds no `rekam_medis.lihat`/`resep.lihat`), and the admin
             * destinations are absent for the new reason: the practitioner and clinic
             * entrances are held back from the sidebar until they get a door of their
             * own. The route itself still answers the `tipe:` guard - this test is on
             * `/admin/audit-log`, and the 403 above is the server's own.
             */
            await expect(
                page.getByRole('link', { name: 'Rekam medis' }),
            ).toHaveCount(0);
            await expect(page.getByRole('link', { name: 'Resep' })).toHaveCount(0);

            await expect(
                page.getByRole('link', { name: 'Jejak audit' }),
            ).toHaveCount(0);
            await expect(
                page.getByRole('link', { name: 'Persetujuan PDP' }),
            ).toHaveCount(0);

            // ...and the drawer really did open, rather than the two counts above
            // passing because nothing rendered: the two destinations every `users.tipe`
            // may open are on screen.
            await expect(
                page.getByRole('link', { name: 'Dashboard' }).first(),
            ).toBeVisible();
            await expect(
                page.getByRole('link', { name: 'Direktori dokter' }).first(),
            ).toBeVisible();
        });

        test('AC-11b non-admin tidak melihat navigasi admin', async ({ page }) => {
            await masukPalsu(page);
            await pasangMock(page, { user: BUKAN_ADMIN });

            await page.goto('/admin/audit-log');

            await expect(page.locator('[data-slot="forbidden-state"]')).toBeVisible();

            await bukaNavigasiMobile(page, viewport.width);

            await expect(
                page.getByRole('link', { name: 'Jejak audit' }),
            ).toHaveCount(0);
            await expect(
                page.getByRole('link', { name: 'Persetujuan PDP' }),
            ).toHaveCount(0);
        });

        test('AC-12 offline: banner, semua tulis disabled, isi sheet tetap, pulih + refetch', async ({
            page,
            context,
        }) => {
            await masukPalsu(page);
            const catatan = await pasangMock(page);

            await page.goto('/admin/dokter/1?tab=jadwal');

            await page.getByRole('button', { name: 'Tambah jadwal' }).first().click();

            const sheet = page.getByRole('dialog');
            await sheet.getByRole('checkbox', { name: 'Senin' }).check();
            await sheet.getByLabel('Jam mulai').fill('09:00');

            await context.setOffline(true);

            await expect(page.getByTestId('offline-banner')).toBeVisible();

            const tulis = page.locator('[data-testid="admin-tulis"]');
            const jumlah = await tulis.count();

            expect(jumlah).toBeGreaterThan(0);

            for (let index = 0; index < jumlah; index += 1) {
                await expect(tulis.nth(index)).toBeDisabled();
            }

            await expect(sheet.getByRole('checkbox', { name: 'Senin' })).toBeChecked();
            await expect(sheet.getByLabel('Jam mulai')).toHaveValue('09:00');

            const sebelum = catatan.permintaan.filter(
                (item) =>
                    item.path === '/api/v1/admin/dokter/1/jadwal' &&
                    item.method === 'GET',
            ).length;

            await context.setOffline(false);

            await expect(page.getByTestId('offline-banner')).toBeHidden();
            await expect(tulis.first()).toBeEnabled();

            await expect
                .poll(
                    () =>
                        catatan.permintaan.filter(
                            (item) =>
                                item.path === '/api/v1/admin/dokter/1/jadwal' &&
                                item.method === 'GET',
                        ).length,
                )
                .toBeGreaterThan(sebelum);
        });

        test('AC-13 axe 0 di empat layar, Esc kembali ke pemicu, target aksi >= 44px', async ({
            page,
        }) => {
            await masukPalsu(page);
            await pasangMock(page);

            await page.goto('/admin/dokter');

            await expectNoA11yViolations(page);

            const pemicu = page
                .locator('[data-slot="admin-dokter-row"][data-dokter-id="1"]')
                .getByRole('button', { name: 'Verifikasi' });

            await pemicu.click();
            await expect(page.getByRole('dialog')).toBeVisible();

            await page.keyboard.press('Escape');
            await expect(page.getByRole('dialog')).toBeHidden();
            await expect(pemicu).toBeFocused();

            await tinggiAksiSetidaknya44(page);

            await page.goto('/admin/dokter/1');
            await expectNoA11yViolations(page);

            await page.goto('/admin/laporan');
            await expectNoA11yViolations(page);

            await page.goto('/admin/audit-log');
            await expectNoA11yViolations(page);
        });

        test('AC-14 privasi: tanpa nomor STR penuh di body/title/URL dan tanpa nama pasien di toast', async ({
            page,
        }) => {
            await masukPalsu(page);
            await pasangMock(page);

            await page.goto('/admin/dokter');

            const baris = page.locator(
                '[data-slot="admin-dokter-row"][data-dokter-id="1"]',
            );

            await baris.getByRole('button', { name: 'Verifikasi' }).click();
            await page
                .getByRole('dialog')
                .getByRole('button', { name: 'Verifikasi dokter' })
                .click();

            await expect(page.getByText('Status dokter diperbarui.')).toBeVisible();

            await page.goto('/admin/dokter/1?tab=jadwal');

            await page.getByRole('button', { name: 'Tambah jadwal' }).first().click();

            const sheet = page.getByRole('dialog');
            await sheet.getByRole('checkbox', { name: 'Senin' }).check();
            await sheet.getByRole('button', { name: 'Simpan sebagai draf' }).click();

            await expect(
                sheet.getByText('1 jadwal disimpan sebagai Draf.'),
            ).toBeVisible();

            await page.goto('/admin/dokter/1');

            await expect(page.getByText(STR_MASKED).first()).toBeVisible();

            const teks = await page.locator('body').innerText();

            expect(teks).not.toContain(STR_PENUH);
            expect(teks).toContain('3312•');
            expect(await page.title()).not.toContain(STR_PENUH);
            expect(page.url()).not.toContain(STR_PENUH);

            const toast = page.locator('[data-sonner-toast]').first();

            if ((await toast.count()) > 0) {
                expect((await toast.innerText()).toLowerCase()).not.toContain(
                    'pasien',
                );
            }
        });

        test('AC-15 zona waktu: label WIB pada jadwal dan pratinjau, tanggal id-ID', async ({
            page,
        }) => {
            await masukPalsu(page);
            await pasangMock(page);

            await page.goto('/admin/dokter/1?tab=jadwal');

            const baris = page.locator('[data-slot="admin-jadwal-row"]').first();
            await expect(baris).toContainText('08.00–12.00 WIB');
            await expect(baris).toContainText('6 Okt 2026');
            await expect(baris).toContainText('16 slot');

            const barisRabu = page
                .locator('[data-slot="admin-jadwal-row"]')
                .filter({ hasText: 'Rabu' });
            await expect(barisRabu).toContainText('6 slot');

            await page.getByRole('button', { name: 'Tambah jadwal' }).first().click();

            const sheet = page.getByRole('dialog');
            await sheet.getByRole('checkbox', { name: 'Senin' }).check();

            await expect(sheet.getByText('08.00–12.00 WIB')).toBeVisible();

            await expect(baris).toContainText(/\d{2}\.\d{2} WIB/);
        });
    });
}
