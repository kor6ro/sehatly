import {
    expect,
    test,
    type Browser,
    type BrowserContext,
    type Page,
} from '@playwright/test';
import { daftarDanMasuk } from './akun';

/**
 * The shell's robustness guards: a failing ROUTE may not take the app down, and the whole
 * menu must be reachable on a phone.
 *
 * ## Both defects are asserted in a real browser, on a real session
 *
 * Nothing here is stubbed. The account is created by `./akun`, which posts the actual
 * `POST /api/v1/auth/sign-up`, reads the OTP out of THAT response body - which
 * `AuthController` publishes only under `APP_ENV=local` - and verifies it through the
 * actual `POST /api/v1/auth/otp/verify`. The route that
 * throws is the consultation screen under the **README's own boot path**: `web/.env` does
 * not exist, the README's "Running it" never mentions `VITE_REVERB_APP_KEY`, and
 * `lib/echo.ts`'s `requireEnv()` raises when the variable is absent. So this spec reproduces
 * F3-01 exactly as the audit reported it, and it keeps reproducing it only while the run
 * leaves those variables unset - which is the point: the boundary has to hold for the
 * deployment the README documents, not only for a correctly configured one.
 *
 * ```
 * php artisan serve --port=8100
 * SEHATLY_API_TARGET=http://127.0.0.1:8100 npx vite --port 5199
 * SEHATLY_BASE_URL=http://127.0.0.1:5199 npx playwright test app-shell-robustness
 * ```
 *
 * ## What each test would have caught
 *
 * | test | defect | the assertion that fails without the fix |
 * | --- | --- | --- |
 * | a route that throws is contained and the sidebar survives | F3-01 BLOCKER | the shell is gone, so `[data-sidebar="sidebar"]` never becomes visible and the navigation click cannot happen |
 * | no stack trace reaches the user | F3-01 BLOCKER | the body carries React's own error page |
 * | all sixteen destinations are reachable at 390 px | F3-04 MAJOR | there is no `[data-sidebar="trigger"]` to click |
 * | no destination carries a hardcoded id | F3-06 MAJOR | a link is `/konsultasi/1` and 404s for this account |
 * | every destination renders inside the shell | F3-06 MAJOR | the shell is destroyed, or a raw English error card is shown |
 */

/** `viewports` is not configured in `playwright.config.ts`, so both sizes are stated here. */
const DESKTOP = { width: 1280, height: 900 };

/** The width F3-04 was measured at, and a phone's height. */
const PONSEL = { width: 390, height: 844 };

/**
 * The sixteen nav destinations a patient account is offered, as paths.
 *
 * Written out rather than counted, because "the drawer has links" would pass with the three
 * F3-04 measured. A destination added later fails here until this list is updated, which is
 * the whole value of writing it down - and which is how `/profil/notifikasi` and
 * `/pengingat` were caught: the "Notifikasi & pengingat" group landed in the shell and this
 * array did not, so the spec failed until the list was brought up to date.
 *
 * `/dokter/booking` ("Booking masuk") left this list when the practitioner entrances were
 * held back from the sidebar: it answers `tipe:dokter` only, so for this account it was a
 * link to a 403 card rather than a destination.
 *
 * The directory's entry stayed at the address it always had: `/dokter` is a page again,
 * and the sidebar points straight at it - no forwarding address to write instead.
 */
const TUJUAN_PASIEN: ReadonlyArray<string> = [
    '/dashboard',
    '/profil',
    '/profil/keluarga',
    '/profil/alergi',
    '/profil/perangkat',
    '/profil/privasi',
    '/dokter',
    '/booking',
    '/konsultasi',
    '/rekam-medis',
    '/pasien/resep',
    '/checkout',
    '/pesanan',
    '/pembayaran',
    '/profil/notifikasi',
    '/pengingat',
];

/**
 * The two public pages that are deliberately outside `AppShell` (see `router.tsx`), so a
 * destination on them has no sidebar by design and is excluded from the sweep that
 * asserts one: the landing page at `/`, and the doctor directory at `/dokter`, which
 * carries the landing bar instead. F3-12 is a separate finding about that page's own
 * padding and is not this spec's to fix.
 */
const DI_LUAR_SHELL = '/dokter';

/** The five links F3-06 named as hardcoded to id `1`, kept as the regression list. */
const DULU_ID_SATU: ReadonlyArray<string> = [
    '/konsultasi/1',
    '/rekam-medis/1',
    '/checkout/1',
    '/pesanan/1',
    '/pembayaran/1',
];

let telepon: string;

/** The sidebar, whichever of the kit's two surfaces is currently rendering it. */
function sidebar(page: Page) {
    return page.locator('[data-sidebar="sidebar"]');
}

/** Every `href` the sidebar offers, de-duplicated and sorted for comparison. */
async function tujuanSidebar(page: Page): Promise<string[]> {
    const href = await sidebar(page)
        .locator('a[href]')
        .evaluateAll((links) =>
            links.map((link) => (link as HTMLAnchorElement).getAttribute('href') ?? ''),
        );

    return [...new Set(href)].sort();
}

/**
 * Wait until the shell knows WHO is signed in.
 *
 * The sidebar's whole patient group hangs off `user.tipe === 'pasien'`, and that user comes
 * from `GET /api/v1/me`. A hard navigation starts with an empty query cache, so for the
 * first few hundred milliseconds the shell legitimately renders the two destinations every
 * account type may open and only then the patient menu. Comparing the two sets before that
 * point would compare a half-rendered menu, and the run that first found this failed on a
 * race rather than on a defect.
 *
 * `/profil` is the marker because it is patient-only, so its presence IS the proof that
 * `/me` has answered and the role filter has been applied.
 */
async function tungguShellSiap(page: Page): Promise<void> {
    await expect(
        sidebar(page).locator('a[href="/profil"]'),
        'shell harus selesai memuat akun (GET /me) sebelum tujuannya dibandingkan',
    ).toHaveCount(1, { timeout: 30_000 });
}

/**
 * Create the account ONCE, so the three tests share one row instead of spending three of
 * the ten `POST /auth/sign-up` calls the limiter allows per minute. Only the phone number
 * is kept; the tokens are per-context and are re-earned by signing in.
 *
 * The throwaway context exists only to hold a page for `page.request`. The pair this mints
 * is discarded with it on purpose: a helper that pasted that pair into each test's own
 * context would be testing a session the sign-in screen never produced, and the point of
 * `masuk` below is exactly that it does not.
 */
async function daftar(browser: Browser): Promise<void> {
    const ctx: BrowserContext = await browser.newContext();
    const page = await ctx.newPage();

    const hasil = await daftarDanMasuk(page, {
        nama: 'Pasien E2E Robustness',
        tanggalLahir: '1994-07-19',
        alamat: 'Jl. Uji Robustness No. 7, Bandung',
    });

    telepon = hasil.telepon;

    await ctx.close();
}

/**
 * Sign in through the two real steps, because `POST /auth/login` answers `{otp}` and **no
 * token**, and `POST /auth/otp/verify` is the only endpoint in the controller that mints
 * one. A helper that pasted a token into `sessionStorage` would be testing a session no
 * user can obtain.
 *
 * No password is typed because the screen collects none: it sends a number and mints a
 * code. The seeded account's own password is never involved in getting in.
 */
async function masuk(page: Page): Promise<void> {
    await page.goto('/login');

    await expect(page.getByLabel('Nomor telepon')).toBeVisible({
        timeout: 60_000,
    });

    await page.getByLabel('Nomor telepon').fill(telepon);

    const [respons] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/api/v1/auth/login')),
        page.getByRole('button', { name: 'Lanjutkan' }).click(),
    ]);

    expect(respons.status()).toBe(200);

    await expect(page).toHaveURL(/\/otp/, { timeout: 30_000 });

    const body = (await respons.json()) as {
        data: { otp: { kode: string | null } };
    };

    expect(body.data.otp.kode, 'OTP login harus terbit pada APP_ENV=local').not.toBeNull();

    await page
        .locator('form input[inputmode="numeric"]')
        .fill(body.data.otp.kode as string);
    await page.getByRole('button', { name: 'Verifikasi' }).click();

    /**
     * Sign-in lands on the landing page now - `/` is where every door puts a signed-in
     * account - so the helper finishes by walking to the workspace itself, which is the
     * state every caller of `masuk` was written against.
     */
    await expect(page).toHaveURL(/\/$/, { timeout: 30_000 });
    await page.goto('/dashboard');
}

test.describe.configure({ mode: 'serial' });

test.describe('App shell robustness (F3-01, F3-04, F3-06)', () => {
    test.beforeAll(async ({ browser }) => {
        await daftar(browser);
    });

    test('a throwing route is contained, the sidebar survives, and no stack trace is shown', async ({
        page,
    }) => {
        test.setTimeout(180_000);

        await page.setViewportSize(DESKTOP);
        await masuk(page);

        /**
         * The PRECONDITION, asserted rather than assumed.
         *
         * This test is only meaningful while the realtime key is missing, and a green run in
         * a CONFIGURED environment would be a false pass: the consultation screen would load
         * normally, nothing would throw, and the assertions below would pass without ever
         * entering an error boundary. So the served `echo.ts` is read and the absence of the
         * key is asserted first, and the failure message says how to reproduce the defect.
         *
         * The repository root `.env` defines `VITE_REVERB_*` (lines 76-79), so a dev server
         * started without them cleared has to be started deliberately:
         *
         * ```
         * set VITE_REVERB_APP_KEY=&& set VITE_REVERB_HOST=&& set VITE_REVERB_PORT=&& set VITE_REVERB_SCHEME=
         * ```
         */
        const modul = await page.request.get('/src/lib/echo.ts');
        const isi = modul.ok() ? await modul.text() : '';

        if (isi !== '') {
            const baris = isi.split('\n').find((b) => b.includes('import.meta.env =')) ?? '';

            expect(
                baris,
                'test ini hanya bermakna saat VITE_REVERB_APP_KEY tidak terpasang; ' +
                    'hentikan dev server, kosongkan VITE_REVERB_* lalu jalankan ulang',
            ).not.toContain('VITE_REVERB_APP_KEY');
        }

        /**
         * The F3-01 reproduction, verbatim: follow the documented boot, sign in, follow the
         * Konsultasi destination. `connectEcho()` raises from `requireEnv()` during render
         * because `VITE_REVERB_APP_KEY` is unset, and it raises BEFORE the page's own
         * `isPending` check - so the throw lands on a route this account really is on.
         */
        await page.goto('/konsultasi/1');

        // (a) contained by the route's own errorElement, in the app's own words
        const kartu = page.locator('[data-slot="route-error"]');

        await expect(
            kartu,
            'galat harus tertangkap oleh errorElement rute',
        ).toBeVisible({ timeout: 30_000 });

        await expect(kartu).toContainText('Halaman ini gagal ditampilkan');

        /**
         * (b) THE assertion that matters: the sidebar is still on screen, still holds every
         * destination, and one of them can be clicked. Before the fix the whole document was
         * React's error page, so this element did not exist and the browser's back button
         * was the user's only way out.
         */
        await expect(
            sidebar(page),
            'sidebar harus tetap ada setelah route gagal',
        ).toBeVisible();

        await tungguShellSiap(page);

        expect(
            await tujuanSidebar(page),
            'semua tujuan harus tetap bisa dijangkau dari sidebar',
        ).toEqual([...TUJUAN_PASIEN].sort());

        const dashboard = sidebar(page).getByRole('link', { name: 'Dashboard' });

        await expect(dashboard).toBeVisible();
        await dashboard.click();

        await expect(
            page,
            'sidebar harus bisa diklik: klik harus benar-benar berpindah halaman',
        ).toHaveURL(/\/dashboard$/, { timeout: 30_000 });

        // Back to the failing route, for the stack-trace assertions.
        await page.goto('/konsultasi/1');
        await expect(kartu).toBeVisible();

        // (c) no stack trace reaches the user
        const teks = await page.locator('body').innerText();

        expect(teks, 'halaman error React tidak boleh muncul').not.toContain(
            'Unexpected Application Error',
        );
        expect(teks, 'sumber stack React tidak boleh bocor ke layar').not.toContain(
            'react-dom',
        );
        expect(teks, 'path modul bundler tidak boleh bocor ke layar').not.toContain(
            'node_modules',
        );
        expect(
            teks,
            'baris stack "at fn (file:line)" tidak boleh bocor ke layar',
        ).not.toMatch(/\bat\s+[A-Za-z_$][\w$]*\s+\(/);

        /**
         * The MESSAGE is shown, because one sentence is actionable and a stack is not: this
         * is the single line that tells a reader the realtime key is missing.
         */
        await expect(page.locator('[data-slot="route-error-detail"]')).toContainText(
            'Reverb is not configured',
        );
    });

    test('all sixteen destinations are reachable at 390 px', async ({ page }) => {
        test.setTimeout(180_000);

        await page.setViewportSize(DESKTOP);
        await masuk(page);

        await tungguShellSiap(page);

        /**
         * The desktop set is read from the DOM rather than from {@link TUJUAN_PASIEN}, so
         * the comparison is "the phone shows what the desktop shows" and not "the phone
         * shows what this file claims".
         *
         * The COUNT is {@link TUJUAN_PASIEN}.length rather than a literal: this assertion
         * used to say `15` twice, and when the shell grew a "Notifikasi & pengingat" group
         * both had to be found by hand. The drift is still caught - by the equality check
         * above and by the per-destination loop below - it just stops needing the same
         * number edited in three places.
         */
        const desktop = await tujuanSidebar(page);

        expect(desktop, `desktop harus menawarkan ${TUJUAN_PASIEN.length} tujuan`).toHaveLength(
            TUJUAN_PASIEN.length,
        );
        await page.setViewportSize(PONSEL);

        await expect(page.locator('[data-slot="mobile-nav"]')).toBeVisible();

        /**
         * The F3-04 measurement, inverted. Before the fix `[data-sidebar=trigger]` was
         * `ABSENT` and the drawer had no way to be opened at all, so a phone user reached
         * three destinations by typing a URL. The drawer starts CLOSED, which is why
         * nothing is visible yet.
         */
        const pemicu = page.locator('[data-sidebar="trigger"]');

        await expect(
            pemicu,
            'tombol pembuka menu harus ada di lebar ponsel',
        ).toBeVisible();

        await expect(
            sidebar(page),
            'drawer harus tertutup sebelum tombol ditekan',
        ).toHaveCount(0);

        await pemicu.click();

        await expect(
            sidebar(page),
            'drawer harus terbuka setelah tombol ditekan',
        ).toBeVisible();

        const ponsel = await tujuanSidebar(page);

        expect(
            ponsel,
            'drawer ponsel harus menawarkan tujuan yang sama persis dengan sidebar desktop',
        ).toEqual(desktop);

        expect(
            ponsel,
            'daftar tujuan yang dikumpulkan harus lengkap',
        ).toHaveLength(TUJUAN_PASIEN.length);

        for (const tujuan of TUJUAN_PASIEN) {
            await expect(
                sidebar(page).locator(`a[href="${tujuan}"]`),
                `tujuan ${tujuan} harus ada di drawer ponsel`,
            ).toHaveCount(1);
        }

        /**
         * And the drawer is USABLE, not merely populated: a click must navigate AND close
         * the panel. A drawer left open over the page it just opened is the same dead end
         * in a nicer frame.
         */
        await sidebar(page).getByRole('link', { name: 'Booking saya' }).click();

        await expect(page).toHaveURL(/\/booking$/, { timeout: 30_000 });
        await expect(
            sidebar(page),
            'drawer harus menutup setelah navigasi',
        ).toHaveCount(0);
    });

    test('no destination carries a hardcoded id, and every one renders inside the shell', async ({
        page,
    }) => {
        test.setTimeout(240_000);

        await page.setViewportSize(DESKTOP);
        await masuk(page);

        await tungguShellSiap(page);

        /**
         * F3-06, part one. A number in a nav href is a row in somebody else's tenant: the
         * audit measured five of them dead-ending in a 404 card. This reads the RENDERED
         * hrefs, so it fails for a link added with a literal, not only for a changed source
         * file.
         */
        const hrefs = await tujuanSidebar(page);

        for (const href of hrefs) {
            expect(
                href,
                `tautan ${href} menyimpan id milik orang lain, bukan milik akun ini`,
            ).not.toMatch(/^\/(konsultasi|rekam-medis|checkout|pesanan|pembayaran)\/\d+$/);
        }

        /**
         * F3-06, part two: every destination renders, and the shell survives each one. The
         * five former id links are walked too - the point is that they now produce a proper
         * in-shell state rather than a destroyed shell or a raw error card.
         */
        const jelajah = [
            ...hrefs.filter((href) => href !== DI_LUAR_SHELL),
            ...DULU_ID_SATU,
        ];

        for (const tujuan of jelajah) {
            await page.goto(tujuan);

            await expect(
                sidebar(page),
                `shell harus bertahan di ${tujuan}`,
            ).toBeVisible({ timeout: 30_000 });

            await expect(
                page.locator('body'),
                `tidak boleh ada halaman error React di ${tujuan}`,
            ).not.toContainText('Unexpected Application Error');
        }

        /**
         * And the wording of that state, because "a proper not-found state" is the
         * requirement and a page that merely fails differently is not it. Before the fix
         * this rendered the Indonesian heading over Laravel's English
         * "Resource not found.".
         */
        await page.goto('/rekam-medis/1');

        /**
         * A generous budget, and not a courtesy: this is a fresh document, so the page waits
         * on `/me` and `/rekam-medis/{id}` before it can render anything, and
         * `php artisan serve` is single-threaded on this platform (`PHP_CLI_SERVER_WORKERS`
         * reports that forking is unsupported) while the notification badge polls. Five
         * seconds - Playwright's default - failed here on latency, not on the assertion.
         */
        await expect(page.getByText('Rekam medis tidak ditemukan')).toBeVisible({
            timeout: 30_000,
        });
        await expect(
            page.getByRole('link', { name: 'Daftar rekam medis saya' }),
        ).toBeVisible({ timeout: 30_000 });
        await expect(page.locator('body')).not.toContainText('Resource not found.');
    });
});
