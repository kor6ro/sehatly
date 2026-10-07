import { expect, test } from '@playwright/test';
import { daftarDanMasuk } from './akun';

/**
 * `/` - the landing page, and the header that now speaks to two audiences.
 *
 * ## What this spec is actually guarding
 *
 * Four decisions are pinned down below, and each of them can regress without
 * breaking anything else:
 *
 * 1. **The body is the whole page.** `RootPage` used to be a chooser with two buttons;
 *    it is now nine sections plus a footer. A section quietly dropped in a refactor
 *    would not fail any other spec - nothing else reads this screen - so the headings
 *    are written out here, one per line, the same way `TUJUAN_PASIEN` writes out the
 *    sidebar's seventeen destinations.
 *
 * 2. **One door, and no second one.** "Daftar" must not exist anywhere on the page,
 *    and "Masuk" must open the phone dialog rather than navigate. Both are asserted
 *    rather than assumed, because a header that grows a sign-up button is exactly the
 *    kind of drift nobody notices until a screenshot reaches the spec's author.
 *
 * 3. **The header changes its mind when a session appears.** Signed out it offers
 *    "Masuk"; signed in it collapses into the account pill - avatar, name, gear,
 *    chevron - whose menu carries the one route (`/dashboard`) that moved out of reach,
 *    the patient-only `/profil`, and the sign-out that puts the pill back to "Masuk".
 *
 * 4. **The nav shows its content before it asks for a click.** "Direktori Dokter" is
 *    no longer a link on the desktop bar but a hover panel of pre-filtered choices, and
 *    "Layanan Kesehatan" opens on hover instead of on a click. Nothing there may be
 *    hover-ONLY: the keyboard and the mobile sheet keep their own way in, which is what
 *    the last four tests in this file are for.
 *
 * ## Why the sign-in path is driven through `daftarDanMasuk`
 *
 * The one-door flow has 38 tests of its own in `auth-f01.spec.ts`; this file wants a
 * *session*, not an OTP. `daftarDanMasuk` installs a real pair issued by the real API,
 * so the pill below is rendered against an account a human could have obtained, and
 * `page.reload()` is what makes the header re-read `getAccessToken()` - it is read on
 * render, not observed.
 */

/** The nine section headings, in the order the page stacks them. */
const SEKSI: ReadonlyArray<string> = [
    'Solusi Kesehatan di Tanganmu',
    'Promo & Penawaran Hari Ini',
    'Konsultasi Spesialis Tepercaya',
    'Beli Obat & Suplemen Kesehatan',
    'Telusuri Kamus Kesehatan',
    'Baca Artikel Kesehatan Terkini',
    'Cek Kesehatan Mandiri',
    'Kata Mereka tentang Sehatly',
];

test.describe('Landing page (/)', () => {
    test('f00-landing-menampilkan-seluruh-seksi-tanpa-daftar', async ({ page }) => {
        await page.goto('/');

        await expect(page).toHaveTitle('Beranda | Sehatly');
        await expect(page.locator('[data-slot="landing-header"]')).toBeVisible();
        await expect(page.locator('[data-slot="hero-carousel"]')).toBeVisible();
        await expect(page.locator('[data-slot="landing-footer"]')).toBeVisible();

        /**
         * Level 2, not a bare text match: the hero renders an `<h1>` per slide, and a
         * `getByText` would pass against a paragraph that happens to quote a heading.
         * `exact` matters too - "Baca Artikel Kesehatan Terkini" would otherwise match
         * the shortened copy of some future card.
         */
        for (const judul of SEKSI) {
            await expect(
                page.getByRole('heading', { level: 2, name: judul, exact: true }),
                `seksi "${judul}" harus tampil`,
            ).toBeVisible();
        }

        /**
         * The whole point of the single door: no sign-up button anywhere, and the one
         * entry that does exist opens the phone dialog in place instead of navigating
         * away from a page the visitor has not finished reading.
         */
        await expect(page.getByRole('button', { name: 'Daftar', exact: true })).toHaveCount(0);

        /**
         * The DIALOG's own vocabulary, which is not the `/login` page's. The door says
         * "Nomor ponsel" and its first button says "Lanjut"; `/login` asks for "Nomor
         * telepon" and says "Lanjutkan". Asserting the wrong pair would still pass
         * against the other screen, which is why both strings are written out here.
         *
         * By role rather than `getByLabel`: the label carries a required marker in a
         * sibling span, so its element text is not the accessible name Playwright
         * matches against.
         */
        const tombolMasuk = page.getByRole('button', { name: 'Masuk', exact: true }).first();
        await tombolMasuk.click();

        await expect(page.getByRole('textbox', { name: 'Nomor ponsel', exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Lanjut', exact: true })).toBeVisible();

        // The door does not cost the visitor their place: closing it is the landing page.
        await page.keyboard.press('Escape');
        await expect(page.locator('[data-slot="hero-carousel"]')).toBeVisible();
    });

    test('f00-landing-ubin-spesialis-membuka-direktori-yang-sudah-terfilter', async ({ page }) => {
        await page.goto('/');

        const ubin = page.locator('a[href^="/dokter?spesialisasi="]').first();
        await expect(ubin).toBeVisible();

        const href = await ubin.getAttribute('href');
        expect(href).not.toBeNull();

        /**
         * The tile's visible text is the badge and the name run together ("SpDokter
         * Gigi"), because the badge is decorative and has no separator in the markup.
         * Stripping the leading "Sp" recovers `master_spesialisasi.nama`, which is the
         * exact string the directory's own filter chip shows - so the assertion is that
         * the SAME row arrived, not merely that some filter is active.
         */
        const nama = (await ubin.innerText()).trim().replace(/^Sp/, '');

        await ubin.click();

        await expect(page).toHaveURL(/\/dokter\?spesialisasi=/);
        await expect(page.locator('[data-slot="dokter-count"]')).toBeVisible();
        await expect(page.locator('[data-slot="dokter-chip"]')).toContainText(nama);
    });

    /**
     * The header's one content link is an ANCHOR, not a route. "Cek Kesehatan Mandiri"
     * is a section of tiles with no endpoint behind it, so following it must move the
     * reader down THIS page and leave the path alone: a registered `/cek-mandiri` route
     * would be a destination that resolves to nothing, and it would have to be added to
     * every route census in the PHP suite for the privilege.
     */
    test('f00-landing-tautan-cek-mandiri-lompat-ke-seksinya-tanpa-membuka-rute', async ({
        page,
    }) => {
        await page.goto('/');

        await page.getByRole('link', { name: 'Cek Kesehatan Mandiri' }).click();

        await expect(page).toHaveURL(/\/#cek-mandiri$/);
        await expect(page.locator('#cek-mandiri')).toBeInViewport();

        // the reader lands under the heading, not merely somewhere inside the box
        await expect(
            page.getByRole('heading', {
                level: 2,
                name: 'Cek Kesehatan Mandiri',
                exact: true,
            }),
        ).toBeInViewport();
    });

    /**
     * The same link on a phone, where it lives inside the header's sheet: the sheet has
     * to close AND the jump has to survive it closing. Radix scroll-locks the dialog it
     * opens, so the regression worth catching is a tap that closes the drawer and leaves
     * the section below the fold.
     */
    test('f00-landing-tautan-cek-mandiri-di-laci-ponsel-menutup-laci-lalu-lompat', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/');

        await page.getByRole('button', { name: 'Buka menu navigasi' }).click();

        await page.getByRole('link', { name: 'Cek Kesehatan Mandiri' }).click();

        await expect(page).toHaveURL(/\/#cek-mandiri$/);
        await expect(page.locator('[role="dialog"]')).toHaveCount(0);
        await expect(page.locator('#cek-mandiri')).toBeInViewport();
    });

    /**
     * The Zalora shape: the panel arrives under the pointer instead of behind a click.
     *
     * Two things are being pinned down here, and either one can regress silently:
     *
     * 1. Hovering "Direktori Dokter" OPENS it and does not navigate. Before this, the
     *    entry was a plain `<NavLink>`, so the first pointer-down threw the visitor onto
     *    `/dokter` - search, filters, results - before they knew what the directory held.
     *    The panel has to be visible without any click, and the URL has to be untouched
     *    while it opens.
     * 2. Moving the pointer INTO the panel keeps it open. `pointerleave` does not fire
     *    when the pointer enters a DOM descendant, so this passes by construction - until
     *    somebody portals the panel or introduces a seam under the trigger, at which
     *    point the panel would shut on the way in and this test is the only thing that
     *    would notice.
     *
     * Clicking a specialisation is the payoff: a pre-filtered directory, which is the
     * one promise the panel makes that the old link could not.
     */
    test('f00-landing-nav-direktori-terbuka-saat-hover-lalu-membuka-direktori-terfilter', async ({
        page,
    }) => {
        await page.goto('/');

        const panel = page.locator('[data-slot="nav-direktori-panel"]');
        const trigger = page.getByRole('button', { name: 'Direktori Dokter' });

        await expect(panel).toHaveCount(0);

        await trigger.hover();

        // Opening must not be navigating: the visitor is still on the landing page.
        await expect(panel).toBeVisible();
        await expect(trigger).toHaveAttribute('aria-expanded', 'true');
        await expect(page).toHaveURL(/\/$/);

        // Both kinds of choice the panel offers, before anything is clicked.
        await expect(panel.locator('a[href^="/dokter?spesialisasi="]').first()).toBeVisible();
        await expect(panel.locator('a[href="/dokter"]')).toBeVisible();

        /**
         * The panel is sized by the HEADER BAR, not by the trigger: its containing block
         * is the bar (`relative`), so the two boxes are the same width and the panel
         * cannot run off the right edge of a window the trigger's offset would have
         * pushed it past.
         */
        const panelBox = await panel.boundingBox();
        const barBox = await page
            .locator('[data-slot="landing-header"] > div')
            .first()
            .boundingBox();

        expect(panelBox).not.toBeNull();
        expect(barBox).not.toBeNull();
        expect(Math.round(panelBox!.width)).toBe(Math.round(barBox!.width));

        /**
         * And every specialisation name is shown IN FULL. This is the difference between
         * a menu that is a choice and one that is a teaser: `truncate` would ellipsise
         * "Spesialis Orthopaedi & Traumatologi" into a prefix, so the assertion is on
         * the rendered boxes rather than on the count of links.
         */
        const terpotong = await panel
            .locator('[data-slot="nav-direktori-spesialisasi"] a')
            .evaluateAll((els) =>
                els.filter((el) => el.scrollWidth > el.clientWidth + 1).map((el) => el.textContent),
            );
        expect(terpotong).toEqual([]);

        // Into the panel: still open, still on `/`.
        const pilihan = panel.locator('a[href^="/dokter?spesialisasi="]').first();
        await pilihan.hover();
        await expect(panel).toBeVisible();
        await expect(page).toHaveURL(/\/$/);

        await pilihan.click();

        await expect(page).toHaveURL(/\/dokter\?spesialisasi=/);
        await expect(page.locator('[data-slot="dokter-chip"]')).toBeVisible();
    });

    /**
     * The other half of "no click needed": the services dropdown opens on hover too.
     *
     * Its content is PORTALED, so it is not a DOM child of the nav entry - leaving the
     * entry for the menu therefore reads as leaving the menu, and the content carries its
     * own enter handler to cancel the pending close. Hovering straight onto a menu item
     * (which is what this does) is exactly the path that would break if that were
     * missing: the menu would close mid-flight and the click would hit the page beneath.
     *
     * The click itself lands on the login door, which is the documented behaviour for a
     * signed-out visitor: `/konsultasi` sits inside `RequireAuth`, so the router - not a
     * link that goes nowhere - is what answers.
     */
    test('f00-landing-nav-layanan-terbuka-saat-hover-lalu-menuju-pintu-masuk', async ({ page }) => {
        await page.goto('/');

        const menu = page.getByRole('menuitem', { name: 'Chat dengan Dokter' });
        await expect(menu).toHaveCount(0);

        await page.getByRole('button', { name: 'Layanan Kesehatan' }).hover();

        await expect(menu).toBeVisible();
        await expect(page.getByRole('menuitem', { name: 'Booking Janji Temu' })).toBeVisible();

        // straight onto an item, across the portal boundary
        await menu.hover();
        await expect(menu).toBeVisible();

        await menu.click();

        await expect(page).toHaveURL(/\/login/);
    });

    /**
     * Hover must never be the only way in. The keyboard reaches the same panel by
     * focusing the trigger (`onFocusCapture`), walks into it with Tab, and Escape closes
     * it and gives focus back to the trigger - in that order, because focusing a trigger
     * whose panel was JUST closed is what would reopen it.
     */
    test('f00-landing-nav-direktori-dibuka-keyboard-dan-ditutup-escape', async ({ page }) => {
        await page.goto('/');

        const trigger = page.getByRole('button', { name: 'Direktori Dokter' });
        const panel = page.locator('[data-slot="nav-direktori-panel"]');

        await trigger.focus();
        await expect(panel).toBeVisible();

        // Tab lands inside the panel, on the link that leads to the whole directory.
        await page.keyboard.press('Tab');
        await expect(
            panel.getByRole('link', { name: /Lihat semua dokter/ }),
        ).toBeFocused();

        await page.keyboard.press('Escape');

        await expect(panel).toHaveCount(0);
        await expect(trigger).toBeFocused();
        await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    });

    /**
     * On a phone the same entry is the plain link it always was: no hover exists there,
     * so the sheet keeps navigation rather than opening a panel a finger would have to
     * dismiss. The query is scoped to the dialog because the footer of the same page can
     * carry a directory link of its own.
     */
    test('f00-landing-nav-direktori-di-laci-ponsel-tetap-tautan-langsung', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/');

        await page.getByRole('button', { name: 'Buka menu navigasi' }).click();

        const laci = page.locator('[role="dialog"]');
        await expect(
            laci.getByRole('button', { name: 'Direktori Dokter' }),
        ).toHaveCount(0);

        await laci.getByRole('link', { name: 'Direktori Dokter' }).click();

        await expect(page).toHaveURL(/\/dokter$/);
    });

    test('f00-landing-pill-akun-menggantikan-masuk-setelah-sesi-terpasang', async ({ page }) => {
        await daftarDanMasuk(page, { nama: 'Pasien E2E Landing' });

        // `daftarDanMasuk` writes the pair after the page has already rendered, so the
        // reload is what makes the header read a token it did not have at mount.
        await page.reload();

        const pill = page.locator('[data-slot="landing-akun"]');
        await expect(pill).toBeVisible();
        await expect(pill).toContainText('Pasien E2E Landing');

        await expect(page.getByRole('button', { name: 'Masuk', exact: true })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Daftar', exact: true })).toHaveCount(0);

        await pill.click();

        /**
         * `Profil` is offered because this account is a `pasien`; `AppShell` and this
         * menu follow the same rule - a destination the account may not use is not
         * rendered - and `Dasbor` is the one screen every `users.tipe` can open.
         */
        await expect(page.getByRole('menuitem', { name: 'Dasbor' })).toBeVisible();
        await expect(page.getByRole('menuitem', { name: 'Profil' })).toBeVisible();
        await expect(page.getByRole('menuitem', { name: 'Keluar' })).toBeVisible();
    });

    test('f00-landing-menu-pill-menuju-dasbor-lalu-keluar-kembali-ke-tamu', async ({ page }) => {
        await daftarDanMasuk(page, { nama: 'Pasien E2E Landing' });
        await page.reload();

        const pill = page.locator('[data-slot="landing-akun"]');

        await pill.click();
        await page.getByRole('menuitem', { name: 'Dasbor' }).click();

        await expect(page).toHaveURL(/\/dashboard$/, { timeout: 30_000 });
        await expect(page.locator('[data-slot="landing-akun"]')).toHaveCount(0);

        // Back to the landing page as the same account, then out through the same menu.
        await page.goto('/');

        await pill.click();
        await page.getByRole('menuitem', { name: 'Keluar' }).click();

        await expect(page).toHaveURL(/\/$/, { timeout: 30_000 });
        await expect(page.getByRole('button', { name: 'Masuk', exact: true }).first()).toBeVisible();

        /**
         * The flash is mounted by the landing page's own `<Toaster />`. Without it the
         * sentence would be dispatched and swallowed, because the sign-out now ends on
         * a screen that is not `AppShell`.
         */
        await expect(page.locator('[data-sonner-toast]').getByText('Anda telah keluar.')).toBeVisible();
    });

    /**
     * The carousel's contract with `GET /hero`, which is new as of the admin-managed
     * module: the strip reads the database, and `SLIDE_HERO` in `features/landing/data.ts`
     * is its FALLBACK rather than its content. Three states have to render a real page -
     * an empty table, a published slide, and a dead endpoint - and none of them is
     * covered by the section-heading test above, which would pass against a blank band.
     */
    test('f00-landing-hero-membaca-endpoint-lalu-menampilkan-slide-bawaan-saat-kosong', async ({
        page,
    }) => {
        const panggilan: string[] = [];

        await page.route('**/api/v1/hero', async (route) => {
            panggilan.push(route.request().url());

            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    success: true,
                    message: 'Slide hero berhasil dimuat.',
                    data: { hero: [] },
                }),
            });
        });

        await page.goto('/');

        // The endpoint is asked, anonymously - no token is set on this page load, and
        // a `permission:` on this route would have made the strip 401 for every
        // signed-out visitor.
        await expect.poll(() => panggilan.length).toBeGreaterThan(0);
        expect(panggilan[0]).not.toContain('?');

        const karusel = page.locator('[data-slot="hero-carousel"]');

        await expect(karusel).toBeVisible();
        await expect(
            page.getByRole('heading', { level: 1, name: 'Bingung Pilih Dokter?', exact: true }),
        ).toBeVisible();

        // The built-in slides carry no photograph, so the gradient - not an empty
        // `<img>` - is what fills the band.
        await expect(karusel.locator('img')).toHaveCount(0);
    });

    test('f00-landing-hero-menampilkan-slide-admin-beserta-gambar-dan-tautan-internal', async ({
        page,
    }) => {
        await page.route('**/api/v1/hero', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    success: true,
                    message: 'Slide hero berhasil dimuat.',
                    data: {
                        hero: [
                            {
                                id: 7,
                                urutan: 0,
                                eyebrow: 'Promo Oktober',
                                judul: 'Slide dari halaman admin',
                                deskripsi: 'Ditulis lewat /admin/hero, bukan lewat deploy.',
                                cta_label: 'Lihat Promo',
                                cta_target: '/dokter',
                                gambar:
                                    'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                                gambar_alt: 'Promo konsultasi Oktober',
                                status: 'tayang',
                                mulai_tayang: null,
                                selesai_tayang: null,
                                tayang_aktif: true,
                                dibuat_at: '2026-10-01T00:00:00.000000Z',
                                diubah_at: '2026-10-01T00:00:00.000000Z',
                            },
                        ],
                    },
                }),
            });
        });

        await page.goto('/');

        const karusel = page.locator('[data-slot="hero-carousel"]');

        await expect(
            page.getByRole('heading', { level: 1, name: 'Slide dari halaman admin', exact: true }),
        ).toBeVisible();

        // The photograph, carrying the alternative text the admin was required to
        // upload with it - one slide, so the autoplay never moves it off screen.
        const gambar = karusel.locator('img[alt="Promo konsultasi Oktober"]');
        await expect(gambar).toBeVisible();
        await expect(gambar).toHaveAttribute('src', /^data:image\/png/);

        // The built-in copy is NOT rendered beside it: the server's list replaces the
        // fallback rather than appending to it.
        await expect(
            page.getByRole('heading', { level: 1, name: 'Bingung Pilih Dokter?', exact: true }),
        ).toHaveCount(0);

        // `cta_target` is internal, and the client renders it verbatim - the
        // `regex:/^\/(?!\/)/` rule on the server is what keeps it that way.
        await expect(karusel.locator('a[href="/dokter"]')).toBeVisible();
        await expect(karusel.locator('a[href^="http"]')).toHaveCount(0);
    });

    test('f00-landing-hero-endpoint-gagal-tetap-menampilkan-slide-bawaan', async ({ page }) => {
        await page.route('**/api/v1/hero', async (route) => {
            await route.abort('connectionrefused');
        });

        await page.goto('/');

        // `heroOptions` disables retry, so the fallback is on screen immediately
        // instead of after three failed attempts - and this assertion is what stops
        // somebody "fixing" the request with a retry policy that leaves the fold blank.
        await expect(
            page.getByRole('heading', { level: 1, name: 'Bingung Pilih Dokter?', exact: true }),
        ).toBeVisible();

        await expect(page.locator('[data-slot="landing-footer"]')).toBeVisible();
    });
});
