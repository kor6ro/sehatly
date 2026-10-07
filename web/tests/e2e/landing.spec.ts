import { expect, test } from '@playwright/test';
import { daftarDanMasuk } from './akun';

/**
 * `/` - the landing page, and the header that now speaks to two audiences.
 *
 * ## What this spec is actually guarding
 *
 * Five decisions are pinned down below, and each of them can regress without
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
 * 5. **The panels are shaped like a catalog menu and carry pictures.** Group headings
 *    over plain links, a promo card whose photograph comes from `GET /hero` - the
 *    admin's gallery - so the header has images without a deploy. One of these tests
 *    exists because the first version of the services panel flickered open and shut
 *    under a resting cursor.
 *
 * ## Why the sign-in path is driven through `daftarDanMasuk`
 *
 * The one-door flow has 38 tests of its own in `auth-f01.spec.ts`; this file wants a
 * *session*, not an OTP. `daftarDanMasuk` installs a real pair issued by the real API,
 * so the pill below is rendered against an account a human could have obtained, and
 * `page.reload()` is what makes the header re-read `getAccessToken()` - it is read on
 * render, not observed.
 */

/** The seven section headings, in the order the page stacks them. */
const SEKSI: ReadonlyArray<string> = [
    'Solusi Kesehatan di Tanganmu',
    'Promo & Penawaran Hari Ini',
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
        await expect(
            panel.getByRole('link', { name: /Lihat semua dokter/ }),
        ).toBeVisible();

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
     * The catalog-panel shape, which asked to live HERE rather than in the page body.
     *
     * It used to be published twice: a `SpesialisSection` on `/` and this hover panel,
     * both carrying the same reference table. The section was deleted and its shape moved
     * into this menu, so the first assertion is the negative one - the landing page no
     * longer has that heading - and the rest is the shape itself, which only makes sense
     * as a menu: a SCROLLING icon rail on the left (sixteen rows, about ten visible, with
     * the panel's own thumb because Chromium 153 reserves no space for a native one),
     * two columns of shortcuts in the middle, and the picture grid on the right.
     *
     * Every zone is asserted rather than only the first, because three zones that STACK
     * would satisfy a naive "is it visible" check while looking nothing like the
     * reference: the column count is computed from the box.
     */
    test('f00-landing-nav-direktori-tiga-zona-ala-menu-katalog', async ({ page }) => {
        await page.goto('/');

        // the section this shape was moved OUT of is gone from the page body
        await expect(
            page.getByRole('heading', { name: 'Konsultasi Spesialis Tepercaya' }),
        ).toHaveCount(0);

        const panel = page.locator('[data-slot="nav-direktori-panel"]');
        await page.getByRole('button', { name: 'Direktori Dokter' }).hover();
        await expect(panel).toBeVisible();

        // the three zones are three COLUMNS, side by side
        const zona = panel.locator('[data-slot="nav-direktori-zona"]');
        const kolom = await zona.evaluate((el) =>
            getComputedStyle(el).gridTemplateColumns.split(' ').length,
        );
        expect(kolom, 'tiga zona harus sejajar, bukan menumpuk').toBe(3);

        // Zone 1 - the rail: the whole table, line art on every row, and it scrolls
        const daftar = zona.locator('[data-slot="nav-direktori-spesialisasi"]');
        await expect(daftar).toBeVisible();

        const jumlah = await daftar.locator('li').count();
        expect(
            jumlah,
            'seluruh tabel spesialisasi harus terbaca, bukan enam teratas',
        ).toBeGreaterThan(10);
        expect(await daftar.locator('li svg').count()).toBe(jumlah);

        const tinggiGulir = await daftar.evaluate(
            (el) => el.scrollHeight - el.clientHeight,
        );
        expect(tinggiGulir, 'rel harus menggulir, bukan memendek').toBeGreaterThan(0);

        /**
         * ...and it says so. The thumb is an ELEMENT with a height, not a scrollbar
         * pseudo-element that may never paint (see `.gulir-sendiri`), and it is dragged
         * rather than merely found: a scrollbar that cannot be grabbed is decoration.
         */
        const thumb = zona.locator('[data-slot="nav-direktori-spesialisasi-gulir"]');
        await expect(thumb).toBeVisible();

        const kotakThumb = await thumb.boundingBox();
        const kotakRel = await daftar.boundingBox();
        expect(kotakThumb.height, 'thumb harus lebih pendek dari relnya').toBeLessThan(
            kotakRel.height,
        );

        /**
         * Deliberately NOT `thumb.hover()` first: hovering centres the pointer in a
         * thumb that is ~280px tall, and the drag below is downward - from the centre it
         * would be a NEGATIVE offset, which `scrollTop` clamps to zero and the assertion
         * then times out against a rail that never moved. So the pointer is parked at the
         * top of the track, pressed, and walked down.
         */
        await page.mouse.move(kotakThumb.x + 3, kotakThumb.y + 5);
        await page.mouse.down();
        await page.mouse.move(kotakThumb.x + 3, kotakThumb.y + 90, { steps: 6 });
        await page.mouse.up();

        await expect
            .poll(() => daftar.evaluate((el) => el.scrollTop))
            .toBeGreaterThan(0);

        // Zone 2 - both columns of shortcuts, with the headings that name them
        const pintasan = zona.locator('[data-slot="nav-direktori-pintasan"]');
        await expect(pintasan.getByText('Sering dicari')).toBeVisible();
        await expect(pintasan.getByText('Layanan', { exact: true })).toBeVisible();
        expect(await pintasan.locator('a').count()).toBeGreaterThanOrEqual(8);

        // Zone 3 - the picture grid: exactly six tiles in the reference's 3x2, the
        // admin's photograph first (which is the `nav-promo` contract the mega-panel
        // picture test checks against) and the product's own campaign cards filling the
        // rest - never a white rectangle where a photo should be.
        const promo = zona.locator('[data-slot="nav-direktori-promo"]');
        await expect(promo).toBeVisible();
        expect(await promo.locator('a').count(), 'grid harus penuh 3x2').toBe(6);
        await expect(promo.locator('[data-slot="nav-promo"] img')).toHaveCount(1);
    });

    /**
     * The other half of "no click needed": the services panel opens on hover too - and
     * it must STAY open, which is the whole point of this test.
     *
     * The regression it exists for: the first version of this panel was a Radix
     * `DropdownMenu` with a controlled `open`. Radix's menu is modal, so opening it
     * writes `pointer-events: none` onto `<body>`; the trigger the cursor is resting on
     * then stops being hoverable, `pointerleave` fires, the menu closes, Radix clears the
     * style, hover is re-evaluated and it reopens - a ~300 ms loop reported as "cursor
     * diarahkan ke layanan kesehatan dia auto buka tutup terus". Nothing here uses Radix
     * any more, so the assertion is two-fold: the panel is still there after a second of
     * sitting still, and `<body>` never grew the inline pointer-events lockout that would
     * mean a modal menu is back in the path.
     *
     * The click lands on the login door, which is the documented behaviour for a
     * signed-out visitor: `/konsultasi` sits inside `RequireAuth`, so the router - not a
     * link that goes nowhere - is what answers.
     */
    test('f00-landing-nav-layanan-terbuka-saat-hover-tanpa-membuka-menutup-sendiri', async ({
        page,
    }) => {
        await page.goto('/');

        const panel = page.locator('[data-slot="nav-layanan-panel"]');
        await expect(panel).toHaveCount(0);

        await page.getByRole('button', { name: 'Layanan Kesehatan' }).hover();
        await expect(panel).toBeVisible();

        // A second of sitting still: ten consecutive samplings, no flicker allowed.
        for (let putaran = 1; putaran <= 10; putaran++) {
            await expect(panel, `panel harus tetap terbuka (putaran ${putaran})`).toBeVisible();
            await page.waitForTimeout(100);
        }

        await expect(page.getByRole('button', { name: 'Layanan Kesehatan' })).toHaveAttribute(
            'aria-expanded',
            'true',
        );

        expect(
            await page.evaluate(() => document.body.style.pointerEvents),
            'body tidak boleh dikunci pointer-events oleh menu modal',
        ).toBe('');

        // The panel is a Zalora-shaped column set, not the old flat dropdown: group
        // headings over plain links, and every link a real route.
        await expect(panel.getByText('Konsultasi & Janji')).toBeVisible();
        await expect(panel.getByText('Obat & Apotek')).toBeVisible();
        await expect(panel.getByText('Riwayat Kesehatan')).toBeVisible();

        const chat = panel.getByRole('link', { name: 'Chat dengan Dokter', exact: true });
        await expect(chat).toBeVisible();

        // moving onto a link inside the panel must not close it either
        await chat.hover();
        await expect(panel).toBeVisible();

        await chat.click();
        await expect(page).toHaveURL(/\/login/);
    });

    /**
     * The pictures the mega panels carry, and where they come from.
     *
     * `GET /hero` is this product's only public source of real photography, and it is
     * the admin's own: a slide is written in `/admin/hero` together with its image and
     * its alt text. So the menu gets pictures without a deploy for exactly the reason the
     * carousel does - and the alt text is asserted rather than assumed, because an image
     * with no alternative text is how a "menu with pictures" becomes a menu with a blank
     * hole for anyone not looking at the screen.
     */
    test('f00-landing-nav-mega-menampilkan-kartu-promo-bergambar', async ({ page }) => {
        await page.goto('/');

        for (const nama of ['Direktori Dokter', 'Layanan Kesehatan']) {
            await page.getByRole('button', { name: nama }).hover();

            const panel = page.locator(
                nama === 'Direktori Dokter'
                    ? '[data-slot="nav-direktori-panel"]'
                    : '[data-slot="nav-layanan-panel"]',
            );
            const kartu = panel.locator('[data-slot="nav-promo"]').first();
            const gambar = kartu.locator('img');

            await expect(gambar, `panel "${nama}" harus memuat kartu promo`).toHaveCount(1);
            await expect(gambar).toBeVisible();

            // A real, decoded photograph - not a broken source painted over by alt text.
            await expect
                .poll(() => gambar.evaluate((el: HTMLImageElement) => el.naturalWidth))
                .toBeGreaterThan(0);

            await expect(gambar).not.toHaveAttribute('alt', '');

            // and the card is a link, so the picture is also a way in
            await expect(kartu).toHaveAttribute('href', '/dokter');
        }
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
