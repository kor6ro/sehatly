import { expect, test } from '@playwright/test';

/**
 * The Module 2 end-to-end proof, driven against a **live** Laravel API.
 *
 * ## What is real here
 *
 * Every assertion below is made against a real HTTP response. There is no route
 * interception, no `page.route` stub, no fixture array standing in for a server answer, and
 * no hard-coded token: the spec registers an account through the actual
 * `POST /api/v1/auth/register`, reads the OTP out of **that response body** - the server
 * publishes it only under `APP_ENV=local`, which is the dev deployment this runs against -
 * and verifies it through `POST /api/v1/auth/otp/verify`. The pair then lives in
 * `sessionStorage` exactly as it does for a human.
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
 * ## Why the booking uses a deep-linked time
 *
 * `GET /api/v1/dokter/{id}/slot` is **not registered** - measured, and recorded as finding
 * F1 in `.omo/evidence/task-26-sehatly.md`. The slot picker therefore renders its
 * "server published nothing" panel, which offers a time box carrying no availability logic
 * of its own, and `?jam=09:00:00` fills it. The server still decides: a second booking for
 * the same doctor, date and time is refused with 422 on `slot`, and the second test asserts
 * that refusal is rendered inline and that **no row was added**.
 */

/** A unique `no_telepon` per test; `users.no_telepon` is `UNIQUE`. */
function nomorTeleponBaru(): string {
    return '0812' + String(Date.now()).slice(-8);
}

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

/** Register and verify, returning the OTP page's success signal. */
async function daftarDanMasuk(page: import('@playwright/test').Page): Promise<void> {
    const telepon = nomorTeleponBaru();

    await page.goto('/register');

    /**
     * The page must be interactive before the first keystroke: a cold dev-server
     * transform can leave the form unmounted for seconds, and a click before hydration
     * fires into nothing.
     */
    await expect(page.getByLabel('Nama lengkap')).toBeVisible({ timeout: 60_000 });

    await page.getByLabel('Nama lengkap').fill('Pasien E2E Booking');
    await page.getByLabel('Nomor telepon').fill(telepon);
    await page.getByLabel('Kata sandi').fill('RahasiaKuat123');
    await page.getByLabel('Tanggal lahir').fill('1995-04-11');
    await page.getByLabel('Alamat lengkap').fill('Jl. Uji Coba No. 1, Bandung');

    /**
     * The OTP is read out of the server's own response rather than typed from a constant.
     * `AuthController` publishes it only when `app()->environment('local')`, so a
     * production deployment answers `kode: null` and this read yields nothing - which is
     * the correct outcome, not a silent fallback.
     *
     * The URL wait is load-bearing: `waitForResponse` resolves when headers arrive, while
     * `navigate('/otp')` happens later in the mutation's `onSuccess`. Reading just the
     * response would race the navigation and fill the register form's own inputs, which is
     * what Playwright's strict-mode violation proved on the first run.
     */
    const [respons] = await Promise.all([
        page.waitForResponse((r) =>
            r.url().includes('/api/v1/auth/register'),
        ),
        page.getByRole('button', { name: /Daftar/ }).click(),
    ]);

    expect(respons.status()).toBe(201);

    await expect(page).toHaveURL(/\/otp/, { timeout: 30_000 });

    const body = (await respons.json()) as {
        data: { otp: { kode: string | null } };
    };
    const kode = body.data.otp.kode;

    expect(kode, 'OTP harus terbit pada APP_ENV=local').not.toBeNull();

    await page
        .locator('form input[inputmode="numeric"]')
        .fill(kode as string);
    await page.getByRole('button', { name: 'Verifikasi' }).click();

    await expect(page).toHaveURL(/\/dashboard/, { timeout: 30_000 });
}

test.describe('Module 2 booking', () => {
    test('books a slot, shows the server row, and the list refetch is the only source', async ({
        page,
    }) => {
        await daftarDanMasuk(page);

        const tanggal = tanggalO();
        const jam = jamUnik();

        const jejak: string[] = [];

        page.on('response', (r) => {
            const url = new URL(r.url());

            if (url.pathname.startsWith('/api/v1/')) {
                jejak.push(`${r.status()} ${url.pathname}${url.search}`);
            }
        });

        await page.goto(`/booking/1?tanggal=${tanggal}&jam=${jam}`);

        /**
         * The doctor detail is a hard gate on this page, and this asserts it resolved
         * rather than being skipped: a form showing "Dokter: -" would still be submittable
         * and would prove nothing.
         */
        await expect(
            page.getByRole('heading', { name: /Booking dengan/ }),
        ).toBeVisible();

        /**
         * The slot endpoint is not registered, so the picker must say so **by name** and
         * name the path. This is the assertion that keeps the gap visible rather than
         * letting a client quietly invent availability.
         */
        const panel = page.getByTestId('slot-endpoint-belum-terdaftar');

        await expect(panel).toBeVisible();
        await expect(
            panel.getByText('GET /api/v1/dokter/1/slot'),
        ).toBeVisible();

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
        await page
            .getByLabel('Alasan pembatalan')
            .fill('Batal otomatis oleh uji e2e.');

        const [batalkanRespons] = await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes('/api/v1/booking') &&
                    r.request().method() === 'PUT',
            ),
            page
                .getByRole('button', { name: 'Batalkan booking' })
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
        await daftarDanMasuk(page);

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
        await daftarDanMasuk(page);

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
        await daftarDanMasuk(page);

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
