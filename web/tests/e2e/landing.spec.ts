import { expect, test, type Page } from '@playwright/test';
import { daftarDanMasuk } from './akun';

/**
 * The total brightness (0-765) of the middle pixel of a screenshot clip.
 *
 * The veil is a question about what a region actually LOOKS like while an overlay sits
 * on top of it, and no computed style can answer that - `opacity: 1` on a transparent
 * box and `opacity: 1` on a black one read identically. So the test reads pixels the
 * way a visitor's eye does.
 *
 * The clip goes back into the page as a data URL and is decoded through a canvas: data
 * URLs do not taint it, so `getImageData` stays readable without fetching anything.
 * Sampling the MIDDLE of a 3x3 clip is deliberate - it dodges the letterforms and the
 * anti-aliased edge of the plate the tab is painted on.
 */
async function sampelPiksel(
    page: Page,
    clip: { x: number; y: number; width: number; height: number },
): Promise<number> {
    const potongan = await page.screenshot({ clip });
    const dataUrl = 'data:image/png;base64,' + potongan.toString('base64');

    return await page.evaluate(async (src) => {
        const gambar = new Image();
        await new Promise<void>((selesai, gagal) => {
            gambar.onload = () => selesai();
            gambar.onerror = () => gagal(new Error('potongan gambar tidak terbaca'));
            gambar.src = src;
        });

        const kanvas = document.createElement('canvas');
        kanvas.width = gambar.width;
        kanvas.height = gambar.height;
        const ctx = kanvas.getContext('2d');
        if (!ctx) throw new Error('canvas 2d tidak tersedia');
        ctx.drawImage(gambar, 0, 0);

        const d = ctx.getImageData(Math.floor(gambar.width / 2), Math.floor(gambar.height / 2), 1, 1).data;
        return d[0] + d[1] + d[2];
    }, dataUrl);
}

/**
 * `/` - the landing page, and the header that now speaks to two audiences.
 *
 * ## What this spec is actually guarding
 *
 * Seven decisions are pinned down below, and each of them can regress without
 * breaking anything else:
 *
 * 1. **The body is the whole page.** `RootPage` used to be a chooser with two buttons;
 *    it is now seven sections plus a footer. A section quietly dropped in a refactor
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
 *    the keyboard and sheet tests further down are for.
 *
 * 5. **Both panels are shaped like the catalog menu and carry pictures.** Three zones
 *    side by side - an icon rail, a column of links, and the six-tile picture grid whose
 *    photograph comes from `GET /hero`, the admin's gallery - so the header has images
 *    without a deploy. The services are printed FLAT, once, in their own panel: the
 *    grouping they used to sit under is asserted to be GONE rather than merely absent,
 *    because it is the kind of thing a refactor puts back. One of these tests exists
 *    because the first version of the services panel flickered open and shut under a
 *    resting cursor.
 *
 * 6. **The bar is the reference's bar: two rows, and a search that goes somewhere.**
 *    Row one carries the wordmark, the search pill and the account cluster; row two
 *    carries the tabs - uppercase, bare of chevrons, ruled underneath only while their
 *    panel is open, and starting on the same pixel as the wordmark. The search opens
 *    `/dokter?search=…` because the directory is the only screen that can answer a
 *    query, and the directory seeds its own field from that parameter the way it already
 *    seeds `?spesialisasi=` from the rail: a field pointing at a screen that ignored it
 *    would be a field that pretends, and the pretence would break nothing else here.
 *    Below `md` the bar drops the field and the drawer carries its own copy of the same
 *    form, so "add a search" never quietly means "add a search to viewports 1024 and up".
 *
 * 7. **An open panel takes the light with it - and the tab does not.** The reference
 *    dims the page behind its menu, because a white panel on a white page has no edge
 *    except a shadow - and a shadow over a photograph reads as smudge. Two boxes do the
 *    dimming: one inside the bar, covering the bar's own 116 px (`backdrop-filter` makes
 *    that box the containing block for fixed descendants, so a `fixed` child goes no
 *    further than it anyway), and one hanging from the bar's bottom edge down a full
 *    viewport. Both are `pointer-events-none`, and the open tab keeps a `z-index` it
 *    takes as a flex item without taking `position` - the combination that keeps the
 *    panel's containing block where the width assertion above says it is. The tab also
 *    grows a `bg-popover` plate, rounded at the top only, so tab + rule + panel are one
 *    white shape; without it the open tab is a dark word in a grey field with a box
 *    hanging below it, and nothing ties the two together.
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
     * a column of shortcuts in the middle, and the picture grid on the right.
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
         * ...and it rides the LINE. The zone's right edge is the divider the eye already
         * follows, so the thumb's right edge must land on it (within the 1px border);
         * a bar sitting a centimetre short of the line it belongs to reads as a second,
         * broken scrollbar. What buys this is the padding living on the `<ul>` instead
         * of on the zone - the zone's own padding is what used to hold the thumb back.
         */
        const kotakZona = await zona.locator('div').first().boundingBox();
        expect(kotakZona).not.toBeNull();
        const jarakGaris = Math.abs(
            kotakThumb!.x + kotakThumb!.width - (kotakZona!.x + kotakZona!.width),
        );
        expect(jarakGaris, 'thumb harus menempel pada garis pembatas rel').toBeLessThanOrEqual(2);

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

        // Zone 2 - the shortcuts. The services column moved to the Layanan panel: a
        // visitor hovering "Direktori Dokter" is choosing a DOCTOR, and a column of
        // appointment links beside the specialties answers a question they did not ask.
        const pintasan = zona.locator('[data-slot="nav-direktori-pintasan"]');
        await expect(pintasan.getByText('Sering dicari')).toBeVisible();
        await expect(pintasan.getByText('Layanan', { exact: true })).toHaveCount(0);
        expect(await pintasan.locator('a').count()).toBeGreaterThanOrEqual(4);

        // Zone 3 - the picture grid: exactly six tiles in the reference's 3x2, the
        // admin's photograph first (which is the `nav-promo` contract the mega-panel
        // picture test checks against) and the product's own campaign cards filling the
        // rest - never a white rectangle where a photo should be.
        const promo = zona.locator('[data-slot="nav-promo-grid"]');
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

        // The panel is FLAT: the services are printed once, in the rail, and the group
        // headings that used to repeat them in the middle are gone for good.
        const rel = panel.locator('[data-slot="nav-layanan-daftar"]');
        await expect(rel).toBeVisible();

        for (const nama of ['Konsultasi & Janji', 'Obat & Apotek', 'Riwayat Kesehatan']) {
            await expect(
                panel.getByText(nama),
                `"${nama}" tidak boleh kembali memecah daftar`,
            ).toHaveCount(0);
        }

        const chat = panel.getByRole('link', { name: 'Chat dengan Dokter', exact: true });
        await expect(chat).toBeVisible();
        expect(
            await panel.getByRole('link', { name: 'Chat dengan Dokter', exact: true }).count(),
            'setiap layanan hanya boleh tercetak sekali',
        ).toBe(1);

        // moving onto a link inside the panel must not close it either
        await chat.hover();
        await expect(panel).toBeVisible();

        await chat.click();
        await expect(page).toHaveURL(/\/login/);
    });

    /**
     * The Layanan panel in the SAME three-zone shape as the directory's, because the two
     * are one menu rather than two menus that happen to share a header.
     *
     * Asserted as columns, not as visibility: three zones stacked would pass a naive
     * check while looking nothing like the reference. The rail is checked for the FIVE
     * services the bar and the sheet share - flat, one row each, in order - because the
     * grouping this replaces is the thing the test exists to keep out: it printed the
     * services, then printed them again under single-item headings, so the negative
     * assertions below (no heading, no second copy of a service, no directory row where
     * its own nav entry already sits) are the point rather than a detail of the current
     * markup.
     */
    test('f00-landing-nav-layanan-tiga-zona-ala-menu-katalog', async ({ page }) => {
        await page.goto('/');

        const panel = page.locator('[data-slot="nav-layanan-panel"]');
        await page.getByRole('button', { name: 'Layanan Kesehatan' }).hover();
        await expect(panel).toBeVisible();

        // the three zones are three COLUMNS, side by side
        const zona = panel.locator('[data-slot="nav-layanan-zona"]');
        const kolom = await zona.evaluate((el) =>
            getComputedStyle(el).gridTemplateColumns.split(' ').length,
        );
        expect(kolom, 'tiga zona harus sejajar, bukan menumpuk').toBe(3);

        // Zone 1 - the rail: one row per service, line art on every row, names in full
        const rel = zona.locator('[data-slot="nav-layanan-daftar"]');
        await expect(rel).toBeVisible();

        const jumlah = await rel.locator('li').count();
        expect(jumlah, 'lima layanan; direktori tidak ikut, pintunya sudah di sebelah').toBe(5);
        expect(await rel.locator('li svg').count()).toBe(jumlah);

        const terpotong = await rel
            .locator('a')
            .evaluateAll((els) =>
                els.filter((el) => el.scrollWidth > el.clientWidth + 1).map((el) => el.textContent),
            );
        expect(terpotong).toEqual([]);

        const hub = await rel
            .locator('a')
            .evaluateAll((els) => els.map((el) => el.getAttribute('href')));
        expect(hub, 'urut seperti halaman landing, tanpa kelompok').toEqual([
            '/konsultasi',
            '/booking',
            '/pasien/resep',
            '/pengingat',
            '/rekam-medis',
        ]);

        // nothing groups them any more, no service is printed a second time, and the
        // directory does not reappear inside a panel whose bar entry sits two items left
        for (const nama of ['Konsultasi & Janji', 'Obat & Apotek', 'Riwayat Kesehatan']) {
            await expect(panel.getByText(nama)).toHaveCount(0);
        }
        expect(
            await panel.getByRole('link', { name: 'Chat dengan Dokter', exact: true }).count(),
            'layanan tidak boleh tercetak dua kali',
        ).toBe(1);
        expect(
            await panel.getByRole('link', { name: 'Direktori Dokter', exact: true }).count(),
            'direktori sudah punya pintu sendiri di bar',
        ).toBe(0);

        // Zone 2 - the four campaigns as text links, the same four painted on the right
        const promoTeks = zona.locator('[data-slot="nav-layanan-promo"]');
        await expect(promoTeks.getByText('Promo & Penawaran')).toBeVisible();
        expect(await promoTeks.locator('a').count(), 'empat kampanye').toBe(4);

        // Zone 3 - the picture grid: the same six tiles, the admin's photograph among
        // them, which is the `nav-promo` contract the picture test checks both panels
        // against rather than the directory's alone.
        const promo = zona.locator('[data-slot="nav-promo-grid"]');
        await expect(promo).toBeVisible();
        expect(await promo.locator('a').count(), 'grid harus penuh 3x2').toBe(6);
        await expect(promo.locator('[data-slot="nav-promo"] img')).toHaveCount(1);
    });

    /**
     * The bar itself: two rows, and a search field that goes somewhere real.
     *
     * The reference stacks its bar - wordmark, search, account on top; tabs underneath -
     * because the search is the widest thing in it and cannot share a line with a nav it
     * would squeeze into a strip between two boxes. Row two is also the panel's
     * containing block, so this is the measurement behind every "the panel is as wide as
     * the bar" assertion below: two rows of the same geometry, or the panels and the bar
     * disagree about where the page's centre is.
     *
     * The search navigates to `/dokter?search=…` because the directory is the ONLY
     * screen in the product that can answer a query - it has a `search` field of its own
     * and `GET /dokter` takes the parameter - and the assertion that follows checks the
     * directory's own field received the value. A header that searched a screen which
     * ignored it would be a field that pretends, and the pretence would pass every other
     * test in this file.
     */
    test('f00-landing-bar-dua-baris-dengan-cari-membuka-direktori-terfilter', async ({ page }) => {
        await page.goto('/');

        const baris1 = page.locator('[data-slot="landing-header"] > div').first();
        const baris2 = page.locator('[data-slot="landing-nav-baris"]');

        await expect(baris2, 'baris tab ada hanya di desktop').toBeVisible();

        const kotak1 = await baris1.boundingBox();
        const kotak2 = await baris2.boundingBox();
        expect(kotak1, 'baris pertama harus terukur').not.toBeNull();
        expect(kotak2, 'baris tab harus terukur').not.toBeNull();
        if (kotak1 === null || kotak2 === null) {
            return;
        }

        expect(
            Math.round(kotak2.y),
            'baris tab harus DI BAWAH baris pencarian',
        ).toBeGreaterThanOrEqual(Math.round(kotak1.y + kotak1.height));
        expect(
            Math.round(kotak2.width),
            'kedua baris lebar sama - itulah lebar panelnya',
        ).toBe(Math.round(kotak1.width));

        const cari = page.locator('[data-slot="landing-cari"]');
        await expect(cari).toBeVisible();
        await expect(cari).toHaveAttribute('role', 'search');

        const kolom = page.getByRole('searchbox', { name: 'Cari dokter' });
        await expect(kolom).toBeVisible();

        await kolom.fill('rina');
        await cari.getByRole('button', { name: 'Cari', exact: true }).click();

        await expect(page).toHaveURL(/\/dokter\?search=rina$/);
        await expect(
            page.locator('[data-slot="dokter-cari"]'),
            'direktori menerima kuerinya, bukan sekadar dibuka',
        ).toHaveValue('rina');
    });

    /**
     * The tab row's style, and the size of the pictures behind it - both of which are
     * claims about the reference this header is drawn from and would otherwise drift
     * without anything breaking.
     *
     * Tabs: uppercase, 12px, no chevron (the reference's tabs are bare words), marked by
     * a bottom rule only while their panel is OPEN - which is the reference marking
     * "Wanita", and which React Router could not answer here since two of the three
     * entries are not routes. The first tab's text starts on the pixel the wordmark
     * starts on, so the two rows read as one column.
     *
     * Pictures: squares of about a tenth of the panel's width in a 3x2 block. The
     * `aspect-[4/3]` they replaced stretched six cards across the whole right-hand zone
     * and turned a picture grid into a wall; a test that only counted them would have
     * passed both.
     */
    test('f00-landing-nav-tab-alas-menu-bar-dan-gambar-persegi', async ({ page }) => {
        await page.goto('/');

        const tab = page.getByRole('button', { name: 'Direktori Dokter' });
        await expect(tab).toBeVisible();

        expect(await tab.evaluate((el) => getComputedStyle(el).textTransform)).toBe('uppercase');
        expect(await tab.evaluate((el) => getComputedStyle(el).fontSize)).toBe('12px');
        expect(
            await tab.locator('svg').count(),
            'tab rujukan tidak membawa chevron - garis bawahnya yang berbicara',
        ).toBe(0);

        // the tab's first letter on the wordmark's first pixel
        const teksKiri = await tab.evaluate((el) => {
            const range = document.createRange();
            range.selectNodeContents(el);
            return Math.round(range.getBoundingClientRect().x);
        });
        const logo = await page
            .getByRole('banner')
            .getByRole('link', { name: 'Sehatly - beranda' })
            .boundingBox();
        expect(logo).not.toBeNull();
        if (logo === null) {
            return;
        }
        expect(Math.abs(teksKiri - Math.round(logo.x)), 'baris 1 dan 2 mulai di satu garis').toBeLessThanOrEqual(
            2,
        );

        // the rule under a tab is transparent until its panel opens, and so is the plate
        // behind it: at rest the bar is just words on the header
        expect(await tab.evaluate((el) => getComputedStyle(el).borderBottomColor)).toBe(
            'rgba(0, 0, 0, 0)',
        );
        expect(
            await tab.evaluate((el) => getComputedStyle(el).backgroundColor),
            'tab yang belum dibuka tidak punya piring sama sekali',
        ).toBe('rgba(0, 0, 0, 0)');

        await tab.hover();

        const panel = page.locator('[data-slot="nav-direktori-panel"]');
        await expect(panel).toBeVisible();
        expect(await tab.evaluate((el) => getComputedStyle(el).borderBottomColor)).not.toBe(
            'rgba(0, 0, 0, 0)',
        );

        // the open tab is cut from the same cloth as the panel it owns - one plate,
        // rounded at the top only, so tab + rule + panel read as one object instead of a
        // word floating in a grey bar above a box. Polled because `transition-colors`
        // would otherwise be sampled mid-flight.
        await expect
            .poll(() => tab.evaluate((el) => getComputedStyle(el).backgroundColor), {
                message: 'piring tab harus menyetel sendiri sebelum dibandingkan',
            })
            .toBe(await panel.evaluate((el) => getComputedStyle(el).backgroundColor));

        const sudut = await tab.evaluate((el) => {
            const gaya = getComputedStyle(el);
            return { atas: gaya.borderTopLeftRadius, bawah: gaya.borderBottomLeftRadius };
        });
        expect(sudut.atas, 'sudut atas piring membulat').not.toBe('0px');
        expect(
            sudut.bawah,
            'sudut bawah tetap siku - garis bawahnya yang menempel ke panel',
        ).toBe('0px');

        // the title row is a display word, not a label
        const judul = panel.getByText('Direktori Dokter', { exact: true });
        expect(parseFloat(await judul.evaluate((el) => getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(
            24,
        );

        // six square tiles, each about a tenth of the panel - never stretched to fill
        const panelL = (await panel.boundingBox())?.width ?? 0;
        expect(panelL).toBeGreaterThan(0);

        const kotak = await panel
            .locator('[data-slot="nav-promo-grid"] a')
            .evaluateAll((els) =>
                els.map((el) => {
                    const b = el.getBoundingClientRect();
                    return { w: Math.round(b.width), h: Math.round(b.height) };
                }),
            );
        expect(kotak, 'grid harus penuh 3x2').toHaveLength(6);

        for (const { w, h } of kotak) {
            expect(Math.abs(w - h), `gambar harus persegi, bukan ${w}x${h}`).toBeLessThanOrEqual(1);
            expect(w, 'terlalu kecil untuk memuat judulnya sendiri').toBeGreaterThanOrEqual(100);
            expect(
                w,
                'terlalu besar: rujukan memakai ~9% lebar panel, batasnya 14%',
            ).toBeLessThanOrEqual(Math.round(panelL * 0.14));
        }
    });

    /**
     * The veil, and exactly what an open panel does to everything behind it.
     *
     * The reference opens its menu over a dimmed page because a white panel on a white
     * page has no edge anywhere but its shadow - and a shadow over a photograph reads as
     * smudge. Two boxes do the dimming, and BOTH are asserted rather than only the
     * obvious one: the bar's half (which takes the wordmark, the search, the account
     * cluster and the tab row with it) and the page's half, which has to start on the
     * pixel the bar ends on or the two veils either overlap into a double-dark seam or
     * leave a bright stripe at the boundary.
     *
     * `pointer-events: none` is asserted for the same reason the geometry is: an overlay
     * that swallowed clicks would change what the panel's links DO, and this suite has
     * opinions about those links. And the entry's `z-20` is checked together with
     * `position: static`, because z-index on a flex item is what lifts the open tab above
     * the veil WITHOUT `position: relative` - which would move the panel's containing
     * block onto the wrapper and size the panel to one tab. The width assertion at the
     * end is the one that would notice.
     *
     * Last come three PIXEL readings, because the requirement is a picture and no
     * computed style can see one: `opacity: 1` over a black box and `opacity: 1` over a
     * transparent one are the same line of CSS and completely different images. The bar
     * and the page must measurably darken; the open tab, on its plate, must not.
     */
    test('f00-landing-panel-aktif-menguapkan-bar-dan-halaman-di-belakangnya', async ({ page }) => {
        await page.goto('/');

        const tiraiBar = page.locator('[data-slot="landing-tirai-bar"]');
        const tiraiHalaman = page.locator('[data-slot="landing-tirai-halaman"]');
        const panel = page.locator('[data-slot="nav-direktori-panel"]');
        const baris2 = page.locator('[data-slot="landing-nav-baris"]');
        const tab = page.getByRole('button', { name: 'Direktori Dokter' });

        await expect(tiraiBar).toHaveCSS('opacity', '0');
        await expect(tiraiHalaman).toHaveCSS('opacity', '0');

        const tinggiLayar = page.viewportSize()?.height ?? 0;
        expect(tinggiLayar, 'perlu viewport yang jelas untuk ukuran ini').toBeGreaterThan(0);

        // The one strip of page where the page's half of the veil can be read from: the
        // panel spans the whole bar, so everything above its bottom edge is panel. Fixed
        // for BOTH readings - before and after the hover must sample the same pixel or
        // the comparison means nothing.
        const yHalaman = tinggiLayar - 32;

        // Left edge, x=6: inside the bar and inside the page, left of every word.
        const barSebelum = await sampelPiksel(page, { x: 6, y: 30, width: 4, height: 4 });
        const halamanSebelum = await sampelPiksel(page, { x: 6, y: yHalaman, width: 4, height: 4 });

        await tab.hover();
        await expect(panel).toBeVisible();

        await expect(tiraiBar).toHaveCSS('opacity', '1');
        await expect(tiraiHalaman).toHaveCSS('opacity', '1');

        // no wall: both halves let every pointer through to what is behind them
        await expect(tiraiBar).toHaveCSS('pointer-events', 'none');
        await expect(tiraiHalaman).toHaveCSS('pointer-events', 'none');

        const kotakBar = await tiraiBar.boundingBox();
        const kotakHalaman = await tiraiHalaman.boundingBox();
        expect(kotakBar).not.toBeNull();
        expect(kotakHalaman).not.toBeNull();
        if (kotakBar === null || kotakHalaman === null) {
            return;
        }

        expect(Math.round(kotakBar.y), 'setengah bar mulai di puncak header').toBe(0);
        expect(
            Math.round(kotakHalaman.y),
            'setengah halaman mulai tepat di situ - bukan bertumpuk, bukan berjarak',
        ).toBe(Math.round(kotakBar.height));

        expect(
            Math.round(kotakHalaman.height),
            'dan setidaknya setinggi satu layar penuh',
        ).toBeGreaterThanOrEqual(tinggiLayar);

        // the open entry: z-index without position, and the panel still spans the bar
        const pembungkus = page.locator('[data-slot="landing-nav-baris"] > nav > div').first();
        expect(await pembungkus.evaluate((el) => getComputedStyle(el).position)).toBe('static');
        expect(await pembungkus.evaluate((el) => getComputedStyle(el).zIndex)).toBe('20');

        const kotakPanel = await panel.boundingBox();
        const kotakBaris = await baris2.boundingBox();
        expect(kotakPanel).not.toBeNull();
        expect(kotakBaris).not.toBeNull();
        if (kotakPanel === null || kotakBaris === null) {
            return;
        }
        expect(
            Math.round(kotakPanel.width),
            'z-20 tidak boleh memindahkan containing block panelnya',
        ).toBe(Math.round(kotakBaris.width));

        // that strip has to still be page and not panel - the sampling above is only
        // honest while there IS a strip of page under the menu
        expect(
            Math.round(kotakPanel.y + kotakPanel.height),
            'panel harus menyisakan halaman di bawahnya untuk disampel',
        ).toBeLessThan(yHalaman);

        // THE PICTURE, which is the requirement itself. Three numbers no computed style
        // can produce: the bar goes dark, the page behind it goes dark, and the open tab
        // does NOT - its plate is what welds it to the panel below. Both dim samples are
        // taken before the hover at the same coordinates, so each is a comparison rather
        // than a guess about how light the theme happens to be.
        const kotakTab = await tab.boundingBox();
        expect(kotakTab).not.toBeNull();
        if (kotakTab === null) {
            return;
        }

        // polled because `transition-colors` would otherwise be photographed mid-flight
        await expect
            .poll(() => tab.evaluate((el) => getComputedStyle(el).backgroundColor), {
                message: 'piring tab harus menyetel sebelum diabadikan',
            })
            .toBe(await panel.evaluate((el) => getComputedStyle(el).backgroundColor));

        const piring = await sampelPiksel(page, {
            x: Math.round(kotakTab.x + kotakTab.width - 4),
            y: Math.round(kotakTab.y + kotakTab.height - 8),
            width: 3,
            height: 3,
        });
        const bar = await sampelPiksel(page, { x: 6, y: 30, width: 4, height: 4 });
        const halaman = await sampelPiksel(page, { x: 6, y: yHalaman, width: 4, height: 4 });

        expect(bar, 'baris satu ikut meredup di bawah tirai').toBeLessThanOrEqual(barSebelum * 0.75);
        expect(halaman, 'halaman ikut meredup di bawah tirai').toBeLessThanOrEqual(
            halamanSebelum * 0.75,
        );
        expect(
            piring,
            'tab terbuka TIDAK ikut meredup - piringnyalah yang menempelkannya ke panel',
        ).toBeGreaterThan(bar + 60);

        // closed again: both halves leave with the panel
        await page.mouse.move(720, 920);
        await expect(tiraiBar).toHaveCSS('opacity', '0');
        await expect(tiraiHalaman).toHaveCSS('opacity', '0');
    });

    /**
     * The Layanan panel's "Lihat semua", which is an ANCHOR rather than the route the
     * directory's see-all points at.
     *
     * The services have no index page - a `/layanan` URL would resolve to nothing - so
     * the link jumps to `#solusi`, the landing's own six-tile section, which is the index
     * it is promising. Following it must move THIS page, leave the path at `/`, and take
     * the panel with it: a menu left open over the section it scrolled to is a menu the
     * visitor has to dismiss twice.
     */
    test('f00-landing-tautan-lihat-semua-layanan-lompat-ke-seksi-solusi', async ({ page }) => {
        await page.goto('/');

        await page.getByRole('button', { name: 'Layanan Kesehatan' }).hover();

        const panel = page.locator('[data-slot="nav-layanan-panel"]');
        await expect(panel).toBeVisible();

        await panel.getByRole('link', { name: /Lihat semua/ }).click();

        await expect(page).toHaveURL(/\/#solusi$/);
        await expect(page.locator('#solusi')).toBeInViewport();
        await expect(
            page.getByRole('heading', {
                level: 2,
                name: 'Solusi Kesehatan di Tanganmu',
                exact: true,
            }),
        ).toBeInViewport();
        await expect(panel, 'panel ikut tertutup').toHaveCount(0);
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

    /**
     * The search on a PHONE, where the bar keeps only the wordmark and the menu button.
     *
     * Hiding the field below `md` is not a decision to make it desktop-only: the drawer
     * carries its own copy of the same form, because a search a visitor has to know to
     * look for is one they will not find. What the drawer must NOT do is sit on top of
     * the page it just opened, so closing it is asserted rather than assumed - the same
     * rule the anchor test applies to the panel, one overlay per dismissal.
     */
    test('f00-landing-cari-di-laci-ponsel-menutup-laci-lalu-membuka-direktori', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/');

        // no field in the BAR below `md` - only the drawer's, which is not yet open
        await expect(page.locator('[data-slot="landing-cari"]:visible')).toHaveCount(0);

        await page.getByRole('button', { name: 'Buka menu navigasi' }).click();

        const laci = page.locator('[role="dialog"]');
        const kolom = laci.getByRole('searchbox', { name: 'Cari dokter' });
        await expect(kolom).toBeVisible();

        await kolom.fill('anak');
        await laci.getByRole('button', { name: 'Cari', exact: true }).click();

        await expect(laci, 'laci ikut tertutup di belakang navigasinya').toHaveCount(0);
        await expect(page).toHaveURL(/\/dokter\?search=anak$/);
        await expect(page.locator('[data-slot="dokter-cari"]')).toHaveValue('anak');
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
