import { expect, test, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * F08's chat additions, mocked end to end: the typing whisper, the `chat.dibaca`
 * read receipt, the patient's "Keluar dari sesi" confirmation, and the absence of
 * a delete affordance.
 *
 * ## Why the socket is faked here, and how
 *
 * The three realtime behaviours under test travel over the Reverb WebSocket, and
 * the live two-browser proof (`konsultasi.spec.ts`) needs a broker. This spec is
 * deterministic instead, and it does not pretend a socket exists: `page.addInitScript`
 * installs `window.__sehatlyRealtimeSocketFactory`, the documented dev/test seam
 * that `useKonsultasiChannel.realtimeClient()` reads instead of building the Echo
 * transport. The fake reports `connected`, confirms every subscribe, records
 * outgoing whispers, and exposes `window.__sehatlyRealtimeFake` so the test pushes
 * `chat.pesan` / `chat.dibaca` frames and `chat.mengetik` whispers on demand.
 *
 * That is also why this spec passes in an environment whose Vite server has no
 * Reverb env: `connectEcho()` - and the render-time throw it raises without
 * `VITE_REVERB_APP_KEY` - is never reached on the seam path.
 *
 * Every `/api/v1` response is intercepted as well, so no PHP process is needed.
 * Two viewports for each scenario: 390x844 and 1280x900.
 */

const VIEWPORTS = [
    { nama: 'mobile', width: 390, height: 844 },
    { nama: 'desktop', width: 1280, height: 900 },
] as const;

const KONSULTASI_ID = 47;
const CHANNEL = `konsultasi.${KONSULTASI_ID}`;
const PASIEN_USER_ID = 11;
const DOKTER_USER_ID = 22;
const RUANG = `[data-slot="chat-window"]`;

type Pesan = {
    id: number;
    konsultasi_id: number;
    pengirim_user_id: number;
    pengirim_tipe: 'pasien' | 'dokter' | 'sistem';
    tipe_pesan: string;
    isi: string | null;
    file_url: null;
    file_nama: null;
    file_ukuran_kb: null;
    dibaca_at: string | null;
    terkirim_at: string;
};

const PESAN_DOKTER: Pesan = {
    id: 100,
    konsultasi_id: KONSULTASI_ID,
    pengirim_user_id: DOKTER_USER_ID,
    pengirim_tipe: 'dokter',
    tipe_pesan: 'teks',
    isi: 'Selamat pagi, silakan sampaikan keluhan Anda.',
    file_url: null,
    file_nama: null,
    file_ukuran_kb: null,
    dibaca_at: null,
    terkirim_at: '2026-10-01T01:55:00.000Z',
};

const PESAN_PASIEN: Pesan = {
    id: 101,
    konsultasi_id: KONSULTASI_ID,
    pengirim_user_id: PASIEN_USER_ID,
    pengirim_tipe: 'pasien',
    tipe_pesan: 'teks',
    isi: 'Saya demam sejak kemarin.',
    file_url: null,
    file_nama: null,
    file_ukuran_kb: null,
    dibaca_at: null,
    terkirim_at: '2026-10-01T02:00:00.000Z',
};

type Baca = {
    pasien_user_id: number;
    pasien_last_read_at: string | null;
    dokter_user_id: number;
    dokter_last_read_at: string | null;
};

const BACA_KOSONG: Baca = {
    pasien_user_id: PASIEN_USER_ID,
    pasien_last_read_at: null,
    dokter_user_id: DOKTER_USER_ID,
    dokter_last_read_at: null,
};

const BACA_DIBACA: Baca = {
    ...BACA_KOSONG,
    dokter_last_read_at: '2026-10-01T02:10:00.000Z',
};

type MockState = {
    baca: Baca;
    requests: string[];
};

async function balasJson(route: Route, status: number, body: unknown): Promise<void> {
    await route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

function meta(total: number): Record<string, unknown> {
    return {
        current_page: 1,
        last_page: 1,
        per_page: 50,
        total,
        from: total === 0 ? null : 1,
        to: total === 0 ? null : total,
    };
}

async function masukPalsu(page: Page): Promise<void> {
    await page.addInitScript(() => {
        sessionStorage.setItem('sehatly.access_token', 'token-uji-f08');
        sessionStorage.setItem('sehatly.refresh_token', 'refresh-uji-f08');
    });
}

async function pasangMock(
    page: Page,
    baca: Baca,
    status = 'berlangsung',
): Promise<MockState> {
    const state: MockState = { baca, requests: [] };

    await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;
        const method = request.method();

        state.requests.push(`${method} ${path}`);

        if (path === '/api/v1/me') {
            return balasJson(route, 200, {
                success: true,
                message: 'Akun berhasil dimuat.',
                data: {
                    user: {
                        id: PASIEN_USER_ID,
                        uuid: '00000000-0000-4000-8000-000000000011',
                        nama_lengkap: 'Sari Wulandari',
                        no_telepon: '081300000011',
                        email: null,
                        tipe: 'pasien',
                        status: 'aktif',
                        bahasa: 'id',
                        foto_profil: null,
                        telepon_terverifikasi: true,
                        email_terverifikasi: false,
                        last_login_at: null,
                        dibuat_at: '2026-01-01T00:00:00.000000Z',
                    },
                },
            });
        }

        if (path === `/api/v1/konsultasi/${KONSULTASI_ID}` && method === 'GET') {
            return balasJson(route, 200, {
                success: true,
                message: 'Konsultasi berhasil dimuat.',
                data: {
                    konsultasi: {
                        id: KONSULTASI_ID,
                        booking_id: null,
                        pasien_id: 5,
                        dokter_id: 9,
                        tipe: 'chat',
                        status,
                        room_id: '00000000-0000-4000-8000-000000000047',
                        mulai_at: '2026-10-01T01:50:00.000000Z',
                        selesai_at: null,
                        total_durasi_detik: null,
                        catatan_subjektif: null,
                        catatan_objektif: null,
                        catatan_asessment: null,
                        catatan_plan: null,
                        diagnosis_kerja: null,
                        saran_tindak_lanjut: null,
                        biaya_konsultasi: '45000.00',
                        dibuat_at: '2026-10-01T01:45:00.000000Z',
                        diubah_at: '2026-10-01T01:50:00.000000Z',
                        pasien: { id: 5, nik: null, nama_lengkap: 'Sari Wulandari' },
                        dokter: { id: 9, nama_lengkap: 'dr. Rina Wijaya, Sp.A' },
                        booking: null,
                        baca: { ...state.baca },
                    },
                },
            });
        }

        if (path === `/api/v1/konsultasi/${KONSULTASI_ID}/chat` && method === 'GET') {
            return balasJson(route, 200, {
                success: true,
                message: 'Riwayat chat berhasil dimuat.',
                data: { pesan: [PESAN_DOKTER, PESAN_PASIEN] },
                meta: meta(2),
            });
        }

        if (path === `/api/v1/konsultasi/${KONSULTASI_ID}/chat/baca` && method === 'POST') {
            return balasJson(route, 200, {
                success: true,
                message: 'Pesan ditandai sudah dibaca.',
                data: { konsultasi_id: KONSULTASI_ID, jumlah_ditandai_baca: 0 },
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

        // The leave-confirmation destination reads this list; without it the index
        // page would fall through to the generic envelope and error its own render.
        if (path === '/api/v1/pasien/resep') {
            return balasJson(route, 200, {
                success: true,
                message: 'Riwayat resep berhasil dimuat.',
                data: { resep: [] },
                meta: meta(0),
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

type WhisperTercatat = {
    channel: string;
    event: string;
    data: Record<string, unknown>;
};

type FakeGlobal = {
    whispers: WhisperTercatat[];
    frame: (event: string, data: unknown) => void;
    whisper: (event: string, data: unknown) => void;
};

type RealtimePalsu = {
    frame: (event: string, data: unknown) => Promise<void>;
    whisper: (event: string, data: unknown) => Promise<void>;
    whispers: () => Promise<WhisperTercatat[]>;
};

/**
 * The fake `RealtimeSocket` contract, installed before any app script runs.
 *
 * `subscribe()` answers `confirmed` through the listener - the same positive
 * statement the broker's `subscription_succeeded` is - so the UI's strip and the
 * outgoing-whisper gate both see a live channel. Frames pushed through
 * `__sehatlyRealtimeFake` arrive exactly where a decoded Reverb frame would.
 */
async function pasangRealtimePalsu(page: Page): Promise<void> {
    await page.addInitScript(
        ({ channel }) => {
            type Listener = {
                onSignal: (signal: { kind: string; reason?: string }) => void;
                onFrame: (frame: {
                    channelName: string;
                    eventName: string;
                    data: unknown;
                }) => void;
                onWhisper: (frame: {
                    channelName: string;
                    eventName: string;
                    data: unknown;
                }) => void;
                onSubscriptionState: (channelName: string, state: string) => void;
            };

            const state: {
                listener: Listener | null;
                subscribed: boolean;
                whispers: WhisperTercatat[];
            } = { listener: null, subscribed: false, whispers: [] };

            (window as unknown as Record<string, unknown>).__sehatlyRealtimeFake = {
                whispers: state.whispers,
                frame: (event: string, data: unknown): void => {
                    state.listener?.onFrame({ channelName: channel, eventName: event, data });
                },
                whisper: (event: string, data: unknown): void => {
                    state.listener?.onWhisper({ channelName: channel, eventName: event, data });
                },
            };

            (window as unknown as Record<string, unknown>).__sehatlyRealtimeSocketFactory = (
                listener: Listener,
            ) => {
                state.listener = listener;

                return {
                    connect: (): void => {
                        listener.onSignal({ kind: 'connected' });
                    },
                    disconnect: (): void => {
                        listener.onSignal({ kind: 'disconnected' });
                    },
                    subscribe: async (request: { channelName: string }): Promise<void> => {
                        state.subscribed = true;
                        listener.onSubscriptionState(request.channelName, 'confirmed');
                    },
                    unsubscribe: (channelName: string): void => {
                        state.subscribed = false;
                        listener.onSubscriptionState(channelName, 'idle');
                    },
                    whisper: (
                        channelName: string,
                        eventName: string,
                        data: Record<string, unknown>,
                    ): void => {
                        state.whispers.push({ channel: channelName, event: eventName, data });
                    },
                    subscriptionState: (): string =>
                        state.subscribed ? 'confirmed' : 'idle',
                };
            };
        },
        { channel: CHANNEL },
    );
}

function realtimePalsu(page: Page): RealtimePalsu {
    return {
        frame: (event, data) =>
            page.evaluate(
                (kiriman) => {
                    (
                        window as unknown as { __sehatlyRealtimeFake: FakeGlobal }
                    ).__sehatlyRealtimeFake.frame(kiriman.event, kiriman.data);
                },
                { event, data },
            ),
        whisper: (event, data) =>
            page.evaluate(
                (bisikan) => {
                    (
                        window as unknown as { __sehatlyRealtimeFake: FakeGlobal }
                    ).__sehatlyRealtimeFake.whisper(bisikan.event, bisikan.data);
                },
                { event, data },
            ),
        whispers: () =>
            page.evaluate(
                () =>
                    (window as unknown as { __sehatlyRealtimeFake: FakeGlobal })
                        .__sehatlyRealtimeFake.whispers,
            ),
    };
}

async function bukaRuang(
    page: Page,
    opsi: { baca: Baca; status?: string },
): Promise<{ state: MockState; realtime: RealtimePalsu }> {
    await masukPalsu(page);
    await pasangRealtimePalsu(page);

    const realtime = realtimePalsu(page);
    const state = await pasangMock(page, opsi.baca, opsi.status);

    await page.goto(`/konsultasi/${KONSULTASI_ID}`);

    await expect(page.locator(RUANG)).toHaveAttribute('data-subscription', 'confirmed', {
        timeout: 30_000,
    });

    await expect(page.locator('[data-pesan-id="101"]')).toHaveCount(1);

    // `/me` has landed once the patient-only leave action renders, and the channel
    // hook needs its id before an OUTGOING whisper can be emitted.
    await expect(page.getByRole('button', { name: 'Keluar dari sesi' })).toBeVisible();

    return { state, realtime };
}

function mengirim(page: Page, isi: string) {
    return page.locator(RUANG).getByLabel('Tulis pesan').fill(isi);
}

async function simpanGambar(
    page: Page,
    vp: { nama: string },
    keadaan: string,
    fullPage = true,
) {
    await page.screenshot({
        path: `ux/refs/f08/f08-${keadaan}-${vp.nama}.png`,
        fullPage,
        animations: 'disabled',
    });
}

for (const vp of VIEWPORTS) {
    test.describe(`F08 chat ${vp.nama} ${vp.width}x${vp.height}`, () => {
        test.use({
            viewport: { width: vp.width, height: vp.height },
            timezoneId: 'Asia/Jakarta',
        });

        test('f08-mengetik-whisper-tampil-dan-hilang', async ({ page }) => {
            const { realtime } = await bukaRuang(page, { baca: BACA_KOSONG });

            await realtime.whisper('chat.mengetik', {
                user_id: DOKTER_USER_ID,
                at: '2026-10-01T02:01:00.000Z',
            });

            const indikator = page.locator('[data-slot="chat-mengetik"]');

            await expect(indikator).toBeVisible();
            await expect(indikator).toContainText('Dokter sedang mengetik…');
            await expect(indikator).toHaveAttribute('role', 'status');

            await simpanGambar(page, vp, 'mengetik');

            // The watcher's own expiry must clear it: a whisper has no "stopped"
            // follow-up, so a stuck indicator is the failure mode.
            await expect(indikator).toHaveCount(0, { timeout: 12_000 });

            // --- outgoing: one throttled whisper while the composer has text ----
            await mengirim(page, 'D');

            await expect
                .poll(
                    async () =>
                        (await realtime.whispers()).filter(
                            (tercatat) => tercatat.event === 'chat.mengetik',
                        ).length,
                )
                .toBe(1);

            const pertama = (await realtime.whispers()).find(
                (tercatat) => tercatat.event === 'chat.mengetik',
            );

            expect(pertama?.channel).toBe(CHANNEL);
            expect(pertama?.data).toEqual({
                user_id: PASIEN_USER_ID,
                at: expect.any(String),
            });

            await page
                .locator(RUANG)
                .getByLabel('Tulis pesan')
                .pressSequentially('emam', { delay: 40 });

            // Still inside the throttle window: no second whisper.
            expect(
                (await realtime.whispers()).filter(
                    (tercatat) => tercatat.event === 'chat.mengetik',
                ),
            ).toHaveLength(1);

            await expectNoA11yViolations(page);
        });

        test('f08-dibaca-dari-seed-rest', async ({ page }) => {
            await bukaRuang(page, { baca: BACA_DIBACA });

            await expect(page).toHaveTitle('Konsultasi #47');

            const milikSaya = page.locator('[data-pesan-id="101"]');

            await expect(
                milikSaya.locator('[data-slot="chat-status-pesan"][data-status="dibaca"]'),
            ).toBeVisible();
            await expect(milikSaya).toContainText('Dibaca');
            await expect(milikSaya.locator('[data-status="terkirim"]')).toHaveCount(0);

            // The incoming row never claims a receipt.
            await expect(
                page.locator('[data-pesan-id="100"] [data-slot="chat-status-pesan"]'),
            ).toHaveCount(0);

            await simpanGambar(page, vp, 'dibaca');

            await expectNoA11yViolations(page);
        });

        test('f08-realtime-pesan-dan-dibaca-tanpa-refetch', async ({ page }) => {
            const { state, realtime } = await bukaRuang(page, { baca: BACA_KOSONG });

            const milikSaya = page.locator('[data-pesan-id="101"]');

            await expect(milikSaya.locator('[data-status="terkirim"]')).toBeVisible();
            await expect(milikSaya).toContainText('Terkirim');

            const jumlahFetchSesi = (): number =>
                state.requests.filter((baris) =>
                    baris.startsWith(`GET /api/v1/konsultasi/${KONSULTASI_ID}`),
                ).length;

            const sebelum = jumlahFetchSesi();

            const pesanBaru: Pesan = {
                ...PESAN_DOKTER,
                id: 200,
                isi: 'Baik, saya catat keluhannya.',
                terkirim_at: '2026-10-01T02:20:00.000Z',
            };

            await realtime.frame('chat.pesan', pesanBaru);

            await expect(page.locator('[data-pesan-id="200"]')).toHaveCount(1);
            await expect(page.locator('[data-pesan-id="200"]')).toContainText(
                'Baik, saya catat keluhannya.',
            );

            // A duplicate delivery is suppressed by the same gate that protects a
            // reconnect's backfill: one row, one bubble.
            await realtime.frame('chat.pesan', pesanBaru);

            await expect(page.locator('[data-pesan-id="200"]')).toHaveCount(1);
            await expect(page.locator(RUANG)).toHaveAttribute(
                'data-duplicates-suppressed',
                '1',
            );

            await realtime.frame('chat.dibaca', {
                user_id: DOKTER_USER_ID,
                last_read_at: '2026-10-01T02:30:00.000Z',
            });

            await expect(milikSaya.locator('[data-status="dibaca"]')).toBeVisible();
            await expect(milikSaya).toContainText('Dibaca');

            // Both markers arrived over the socket: no consultation refetch happened.
            expect(jumlahFetchSesi()).toBe(sebelum);

            await simpanGambar(page, vp, 'dibaca-realtime');

            await expectNoA11yViolations(page);
        });

        test('f08-keluar-dari-sesi-konfirmasi', async ({ page }) => {
            const { state } = await bukaRuang(page, { baca: BACA_KOSONG });

            const pemicu = page.getByRole('button', { name: 'Keluar dari sesi' });

            await expect(pemicu).toBeVisible();
            await pemicu.click();

            const dialog = page.locator('[data-slot="dialog-content"]');

            await expect(dialog).toBeVisible();
            await expect(
                dialog.getByRole('heading', { name: 'Keluar dari ruang konsultasi?' }),
            ).toBeVisible();
            await expect(
                dialog.getByRole('button', { name: 'Tetap di ruang konsultasi' }),
            ).toBeVisible();

            await simpanGambar(page, vp, 'keluar-dialog', false);

            // Cancel leaves the room exactly where it was.
            await dialog.getByRole('button', { name: 'Tetap di ruang konsultasi' }).click();

            await expect(dialog).toHaveCount(0);
            await expect(page).toHaveURL(new RegExp(`/konsultasi/${KONSULTASI_ID}$`));
            await expect(pemicu).toBeFocused();

            // Confirm navigates and changes NO consultation state.
            await pemicu.click();
            await dialog.getByRole('button', { name: 'Keluar dari sesi' }).click();

            await expect(page).toHaveURL(/\/konsultasi$/, { timeout: 10_000 });

            expect(
                state.requests.some((baris) => baris.includes('/selesai')),
            ).toBe(false);

            await expectNoA11yViolations(page);
        });

        test('f08-ac7-sesi-tutup-composer-nonaktif', async ({ page }) => {
            const { state } = await bukaRuang(page, {
                baca: BACA_KOSONG,
                status: 'selesai',
            });

            await expect(
                page.locator('[data-slot="konsultasi-status-badge"][data-status="selesai"]'),
            ).toBeVisible();

            await expect(page.locator(RUANG)).toContainText(
                'Ruang konsultasi ini sudah ditutup.',
            );

            await expect(page.locator(RUANG).getByLabel('Tulis pesan')).toBeDisabled();
            await expect(
                page.locator(RUANG).getByRole('button', { name: 'Kirim' }),
            ).toBeDisabled();

            // A closed room cannot have sent anything: no POST reached /chat.
            expect(
                state.requests.filter(
                    (baris) => baris === `POST /api/v1/konsultasi/${KONSULTASI_ID}/chat`,
                ),
            ).toHaveLength(0);

            await expectNoA11yViolations(page);
        });

        test('f08-target-44px', async ({ page }) => {
            await bukaRuang(page, { baca: BACA_KOSONG });

            const target = [
                page.locator(RUANG).getByRole('button', { name: 'Kirim' }),
                page.locator(RUANG).getByRole('button', { name: 'Hubungkan ulang' }),
                page.getByRole('button', { name: 'Keluar dari sesi' }),
            ];

            for (const tombol of target) {
                const kotak = await tombol.boundingBox();

                expect(kotak, 'target sentuh harus punya bounding box').not.toBeNull();
                expect(
                    Math.round((kotak as { height: number }).height),
                    await tombol.innerText(),
                ).toBeGreaterThanOrEqual(44);
            }

            await expectNoA11yViolations(page);
        });

        test('f08-tanpa-hapus-pesan', async ({ page }) => {
            await bukaRuang(page, { baca: BACA_KOSONG });

            expect(await page.getByRole('button', { name: /hapus/i }).count()).toBe(0);
            expect(await page.getByText(/hapus untuk semua/i).count()).toBe(0);

            const isiRuang = await page.locator(RUANG).innerText();

            expect(isiRuang.toLowerCase()).not.toContain('hapus');
        });

        test('f08-offline-banner-dan-draft', async ({ page, context }) => {
            await bukaRuang(page, { baca: BACA_KOSONG });

            await context.setOffline(true);

            await expect(page.getByTestId('offline-banner')).toBeVisible();
            await expect(page.getByTestId('offline-banner')).toHaveAttribute(
                'role',
                'status',
            );

            // The draft survives a connection loss: a drop must never eat text.
            await mengirim(page, 'Saya masih bisa menulis saat luring.');

            await expect(page.locator(RUANG).getByLabel('Tulis pesan')).toHaveValue(
                'Saya masih bisa menulis saat luring.',
            );

            await simpanGambar(page, vp, 'offline');

            await expectNoA11yViolations(page);
        });
    });
}
