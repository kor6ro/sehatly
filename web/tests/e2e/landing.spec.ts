import { expect, test } from '@playwright/test';
import { daftarDanMasuk } from './akun';

/**
 * `/` - the landing page, and the header that now speaks to two audiences.
 *
 * ## What this spec is actually guarding
 *
 * Three decisions landed in the same commit and each of them can regress without
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
});
