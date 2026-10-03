import { expect, test } from '@playwright/test';
import { daftarDanMasuk } from './akun';

/**
 * The Module 2 end-to-end proof, driven against a **live** Laravel API.
 *
 * ## What is real here
 *
 * Every assertion below is made against a real HTTP response. There is no route
 * interception, no `page.route` stub, no fixture array standing in for a server answer, and
 * no hard-coded token: the spec creates an account through the actual
 * `POST /api/v1/auth/sign-up`, reads the OTP out of **that response body** - the server
 * publishes it only under `APP_ENV=local`, which is the dev deployment this runs against -
 * and verifies it through `POST /api/v1/auth/otp/verify`. The pair then lives in
 * `sessionStorage` exactly as it does for a human.
 *
 * The whole round trip lives in `./akun` because `/register` no longer exists: one door,
 * and this spec is not the spec that asserts it. What is shared is the mechanism, what is
 * asserted here is booking.
 *
 * `php artisan serve` must be reachable through the Vite proxy. Point it at a port that is
 * free:
 *
 * ```
 * php artisan serve --port=8123
 * SEHATLY_API_TARGET=http://127.0.0.1:8123 npx vite --port 5173
 * SEHATLY_BASE_URL=http://127.0.0.1:5173 npx playwright test
 * ```
 *
 * ## The slot endpoint is real, and the fixture doctor publishes nothing
 *
 * `GET /api/v1/dokter/{id}/slot` is registered and answers 200 (measured 2026-10-01). The
 * fixture doctor this spec books (`/booking/1`, "Dokter Fixture Satu") has no
 * `dokter_jadwal` row, so the endpoint answers `slots: []` and the picker renders its
 * "Tidak ada jam tersedia" empty state. The deep-linked `?jam=09:00:00` still seeds the
 * form, and the server's instant path accepts it. The server still decides: a second
 * booking for the same doctor, date and time is refused with 422 on `slot`, and the second
 * test asserts that refusal is rendered inline and that **no row was added**.
 */

/**
 * `Y-m-d`, a random 30 to 60 days out.
 *
 * Random, for the same reason as {@link jamUnik}: the dev database keeps every booking
 * every run has ever made, so a fixed "+30 days" would converge on the same few slots and
 * turn a passing suite into a flaky one. The fixture doctors' STRs run to 2029/2030
 * (`DevFixtureSeeder`), so the whole window is inside the licence boundary.
 */
function tanggalO(): string {
    const d = new Date();
    d.setDate(d.getDate() + 30 + Math.floor(Math.random() * 30));

    const y = String(d.getFullYear()).padStart(4, '0');
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const h = String(d.getDate()).padStart(2, '0');

    return `${y}-${m}-${h}`;
}

/**
 * A `H:i:s` that is unique per run, so consecutive runs never fight over one slot.
 *
 * Random, not time-derived: two tests in the same suite already share a date and a
 * doctor, and `Date.now()`-derived times repeat within the minute while the dev
 * database keeps every booking every run has ever made.
 */
function jamUnik(): string {
    const jam = String(8 + Math.floor(Math.random() * 10)).padStart(2, '0');
    const menit = String(Math.floor(Math.random() * 60)).padStart(2, '0');

    return `${jam}:${menit}:00`;
}

/**
 * F02's mandatory gate stands in front of the booking form, so a freshly registered
 * account must record the three required consents before it can book. This does what the
 * screen does after the gate: read the ACTIVE version from `GET /pdp/dokumen` and post
 * each decision with it - never an invented version.
 *
 * The write is idempotent by contract (a repeated identical decision answers 200 without
 * writing), so 200 and 201 both mean "recorded".
 */
async function catatConsentWajib(page: import('@playwright/test').Page): Promise<void> {
    const token = await page.evaluate(() => sessionStorage.getItem('sehatly.access_token'));

    expect(token, 'sesi harus punya access token setelah verifikasi').not.toBeNull();

    const headers = { Authorization: `Bearer ${token as string}` };

    const dokumen = await page.request.get('/api/v1/pdp/dokumen', { headers });

    expect(dokumen.status(), 'GET /pdp/dokumen harus 200').toBe(200);

    const katalog = (await dokumen.json()) as {
        data: { dokumen: Array<{ jenis: string; versi_dokumen: string }> };
    };

    const versi = new Map(
        katalog.data.dokumen.map((baris) => [baris.jenis, baris.versi_dokumen]),
    );

    for (const jenis of [
        'syarat_ketentuan',
        'kebijakan_privasi',
        'berbagi_data_medis',
    ]) {
        const respons = await page.request.post('/api/v1/pdp/persetujuan', {
            headers,
            data: { jenis, versi_dokumen: versi.get(jenis), disetujui: true },
        });

        expect(
            [200, 201],
            `consent ${jenis} harus tercatat, diterima ${respons.status()}`,
        ).toContain(respons.status());
    }
}

test.describe('Module 2 booking', () => {
    /**
     * Each test registers a fresh account through the real UI and, since F02, also records
     * three consents before the form is reachable. On a cold Vite transform that setup
     * alone can take 25 s, so the default 30 s budget is not enough for the walkthrough.
     */
    test.describe.configure({ timeout: 90_000 });

    test('books a slot, shows the server row, and the list refetch is the only source', async ({
        page,
    }) => {
        await daftarDanMasuk(page, { nama: 'Pasien E2E Booking' });
        await catatConsentWajib(page);

        const tanggal = tanggalO();
        const jam = jamUnik();

        const jejak: string[] = [];

        page.on('response', (r) => {
            const url = new URL(r.url());

            if (url.pathname.startsWith('/api/v1/')) {
                jejak.push(`${r.status()} ${url.pathname}${url.search}`);
            }
        });

        const slotResponse = page.waitForResponse((r) =>
            r.url().includes('/api/v1/dokter/1/slot'),
        );

        await page.goto(`/booking/1?tanggal=${tanggal}&jam=${jam}`);

        /**
         * The doctor detail is a hard gate on this page, and this asserts it resolved
         * rather than being skipped: a form showing "Dokter: -" would still be submittable
         * and would prove nothing. The timeout is generous because a cold Vite transform
         * of this route can take longer than the 5 s expect default.
         */
        await expect(
            page.getByRole('heading', { name: /Booking dengan/ }),
        ).toBeVisible({ timeout: 30_000 });

        /**
         * The slot endpoint is registered and answers 200. The fixture doctor has no
         * schedule, so the published list is empty and the picker says so; the deep-linked
         * time still seeds the form and the server's instant path accepts it.
         */
        const slot = await slotResponse;

        expect(slot.status(), 'GET /dokter/1/slot harus 200').toBe(200);

        const slotBody = (await slot.json()) as {
            data: { timezone: string; slots: unknown[] };
        };

        expect(slotBody.data.timezone).toBe('Asia/Jakarta');
        expect(slotBody.data.slots).toEqual([]);

        await expect(page.getByText('Tidak ada jam tersedia')).toBeVisible();

        await page
            .getByLabel('Keluhan')
            .fill('Demam tiga hari disertai sakit kepala dan hilang nafsu makan.');

        const [create] = await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes('/api/v1/booking') &&
                    r.request().method() === 'POST',
            ),
            page.getByRole('button', { name: /Kirim booking/ }).click(),
        ]);

        expect(create.status(), 'POST /api/v1/booking harus 201').toBe(201);

        const dibuat = (await create.json()) as {
            data: { booking: { nomor_booking: string; status: string } };
        };

        // The confirmation quotes the server's own number, not one the client invented.
        await expect(
            page.getByText(`Booking ${dibuat.data.booking.nomor_booking} berhasil dibuat.`),
        ).toBeVisible();

        // `nomor_antrian` is unenforced in the DDL, so it is null and is not rendered.
        await expect(
            page.getByText('Nomor antrean belum ditetapkan'),
        ).toBeVisible();

        /**
         * The row below must come from a **refetch**, not from an optimistic insert. The
         * mutation invalidates `['v1','booking']`, so a `GET /pasien/booking` follows the
         * 201; if the row were local, that call would be absent.
         *
         * The assertion is scoped to the list's own rows on purpose. The confirmation card
         * above also carries the `nomor_booking`, so an unscoped `getByText` would pass on
         * the confirmation while the list still showed "Belum ada booking" - which is
         * exactly the false positive the first screenshot of this test caught.
         */
        const rows = page.locator('[data-slot="booking-rows"]');

        await expect(
            rows.getByText(dibuat.data.booking.nomor_booking),
        ).toBeVisible({ timeout: 15_000 });

        await expect(page.getByText('Belum ada booking')).toHaveCount(0);

        /**
         * The full lifecycle: cancel the booking just created and assert the server's
         * 200 moves it to `dibatalkan`. This also frees the slot, so the dev database
         * does not accumulate a taken slot per run.
         */
        const batal = rows.locator('li').filter({
            hasText: dibuat.data.booking.nomor_booking,
        });

        await batal.getByRole('button', { name: 'Batalkan' }).click();

        /**
         * F12's dialog replaced the single free-text field with a quick-reason
         * `toggle-group`: the textarea only exists once "Lainnya" is chosen, so the live
         * contract test drives that branch before typing.
         *
         * `radio`, not `button`. A Radix `ToggleGroup type="single"` is a `radiogroup` of
         * `radio` items - it was a button only while this was a row of buttons, and the
         * role moved the day F12 replaced them. An assertion that names the old role
         * does not fail on a wrong reason, it fails by never resolving, which is how this
         * line kept looking like a dialog bug.
         */
        await page.getByRole('radio', { name: 'Lainnya' }).click();
        await page
            .getByLabel('Catatan alasan')
            .fill('Batal otomatis oleh uji e2e.');

        const [batalkanRespons] = await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes('/api/v1/booking') &&
                    r.request().method() === 'PUT',
            ),
            page
                .getByRole('button', { name: 'Ya, batalkan janji temu' })
                .click(),
        ]);

        expect(
            batalkanRespons.status(),
            'PUT /api/v1/booking/{id}/batalkan harus 200',
        ).toBe(200);

        /**
         * Scoped to the badge's `data-status`: the row legitimately contains the word
         * "Dibatalkan" three times (badge, reason line, non-cancellable note), so an
         * unscoped text match is ambiguous. The badge is the status; the other two are
         * consequences of it.
         */
        await expect(
            batal.locator('[data-status="dibatalkan"]'),
        ).toBeVisible({ timeout: 15_000 });

        /**
         * The screenshot is taken after the row is visible, so it shows the complete
         * verified statement: the 201 confirmation **and** the refetched row beneath it.
         */
        await page.screenshot({
            path: 'playwright-report/booking-happy-path.png',
            fullPage: true,
        });

        expect(jejak.some((baris) => baris.includes('201 /api/v1/booking'))).toBe(
            true,
        );
        expect(
            jejak.some((baris) =>
                baris.includes('200 /api/v1/pasien/booking'),
            ),
            'harus ada GET /pasien/booking setelah create',
        ).toBe(true);

        // eslint-disable-next-line no-console
        console.log('NETWORK_LOG_HAPPY_PATH\n' + jejak.join('\n'));
    });

    test('a taken slot is refused inline and adds no row', async ({ page }) => {
        await daftarDanMasuk(page, { nama: 'Pasien E2E Booking' });
        await catatConsentWajib(page);

        const tanggal = tanggalO();
        const jam = jamUnik();

        const booking = async (waktu: string): Promise<number> => {
            await page.goto(`/booking/1?tanggal=${tanggal}&jam=${waktu}`);

            await page.getByLabel('Keluhan').fill('Keluhan untuk uji slot penuh.');

            const [respons] = await Promise.all([
                page.waitForResponse(
                    (r) =>
                        r.url().includes('/api/v1/booking') &&
                        r.request().method() === 'POST',
                ),
                page.getByRole('button', { name: /Kirim booking/ }).click(),
            ]);

            return respons.status();
        };

        expect(await booking(jam)).toBe(201);

        const kedua = await booking(jam);

        expect(kedua, 'slot yang sama harus ditolak').toBe(422);

        /**
         * The refusal is named, not generic: `SlotTakenNotice` renders the server's own
         * sentence and the field it arrived on. The assertion is scoped to the notice
         * because the same sentence is also rendered by `FormErrorSummary` and by the
         * per-field list - that triple rendering is intentional, and an unscoped
         * `getByText` would match all three.
         */
        const notice = page.locator('[data-slot="slot-taken-notice"]');

        await expect(notice).toBeVisible();
        await expect(
            notice.getByText('Slot sudah penuh untuk waktu ini.'),
        ).toBeVisible();

        await expect(
            notice.getByText('Status 422, field `slot`'),
        ).toBeVisible();

        /**
         * The failure must not have produced a second row. One booking exists, and the
         * success card for the refused submit is absent.
         */
        await expect(
            page.getByRole('heading', { name: 'Booking terakhir' }),
        ).toBeVisible();

        expect(
            await page
                .getByText('berhasil dibuat.')
                .count(),
            'hanya satu booking yang boleh ada',
        ).toBeLessThanOrEqual(1);

        await page.screenshot({
            path: 'playwright-report/booking-slot-taken.png',
            fullPage: true,
        });
    });

    test('the doctor-side list is a 403 capability refusal, not a retryable error', async ({
        page,
    }) => {
        await daftarDanMasuk(page, { nama: 'Pasien E2E Booking' });
        await catatConsentWajib(page);

        const [respons] = await Promise.all([
            page.waitForResponse((r) =>
                r.url().includes('/api/v1/dokter/booking'),
            ),
            page.goto('/dokter/booking'),
        ]);

        expect(respons.status()).toBe(403);

        await expect(
            page.getByText('Akun ini tidak berhak'),
        ).toBeVisible();

        /**
         * `ErrorState` withholds its retry button for a 403 because no retry can change
         * the answer. Asserting its absence is what separates a capability refusal from a
         * transient failure in the UI.
         */
        await expect(
            page.getByRole('button', { name: 'Coba lagi' }),
        ).toHaveCount(0);

        await page.screenshot({
            path: 'playwright-report/booking-dokter-403.png',
            fullPage: true,
        });
    });

    test('an account with no bookings sees the empty state, not a blank list', async ({
        page,
    }) => {
        await daftarDanMasuk(page, { nama: 'Pasien E2E Booking' });
        await catatConsentWajib(page);

        const [respons] = await Promise.all([
            page.waitForResponse((r) =>
                r.url().includes('/api/v1/pasien/booking'),
            ),
            page.goto('/booking'),
        ]);

        expect(respons.status()).toBe(200);

        const body = (await respons.json()) as {
            data: { booking: unknown[] };
            meta: { total: number };
        };

        expect(body.meta.total).toBe(0);
        expect(body.data.booking).toEqual([]);

        await expect(
            page.getByText('Belum ada booking'),
        ).toBeVisible();

        await page.screenshot({
            path: 'playwright-report/booking-empty.png',
            fullPage: true,
        });
    });
});
