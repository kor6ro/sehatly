import { mkdirSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';
import { daftarDanMasuk } from './akun';

/**
 * The dashboard's search panel: what opens it, what it may say, and where a term goes.
 *
 * ## Where this lives, and why it needed its own file
 *
 * `dokter-f03.spec.ts` owns the ASKING and ANSWERING that happen on `/`. This is the third
 * door into the same handoff - the field on `/dashboard`, the screen a signed-in patient
 * actually lands on - and it shares F03's constraints without sharing its surface, so its
 * assertions are about the panel rather than about the directory behind it.
 *
 * ## The two criteria that are about truth, not about pixels
 *
 * 1. **The error branch must not lie.** The suggestion list is a `GET /dokter`, and a
 *    failed one used to fall through to the "no rows" branch and print
 *    `Belum ada dokter yang memenuhi syarat untuk tampil.` - a confident statement about
 *    the catalogue when the real event was that we could not reach it. A 500 must produce
 *    `Gagal memuat saran.` plus `Coba lagi`. This is F03 §6's error row, applied to a
 *    second list.
 * 2. **The term never reaches the address.** F03 §9 and AC-13, with `kanker` as the
 *    fixture word because a medical condition is the whole reason the rule exists.
 *
 * ## Why every suggestion request is mocked in two tests and real in the rest
 *
 * The layout, privacy and history tests use the LIVE seed on purpose: a panel that only
 * ever passed against fixtures could be asserting a response the server never produces.
 * The two state tests mock because a live catalogue cannot be made empty or broken on
 * demand, and F03 §6 requires both states to exist.
 *
 * ## Screenshots
 *
 * Under `ux/refs/`, which `AGENTS.md` forbids committing - they are evidence for the
 * visual loop it requires at 390 px and 1280 px, in BOTH appearances. Dark is forced by
 * writing `appearance=dark` before the document boots, because `initializeTheme()`
 * normalises anything that is not `'dark'` to `'light'` and ignores `prefers-color-scheme`
 * entirely - a `colorScheme: 'dark'` context alone would screenshot a light app.
 */

const VIEWPORTS = [
    { nama: 'mobile', width: 390, height: 844 },
    { nama: 'desktop', width: 1280, height: 900 },
] as const;

type Vp = (typeof VIEWPORTS)[number];

/** F03's privacy fixture: a condition, not a name, so a leak is unmistakable. */
const KATA_UJI = 'kanker';

mkdirSync('ux/refs/dashboard-cari', { recursive: true });

const wadah = (page: Page) => page.locator('[data-slot="dashboard-cari"]');
const panel = (page: Page) => page.locator('[data-slot="dashboard-cari-panel"]');
const kolom = (page: Page) => page.getByLabel('Cari dokter');

/** Signed in and standing on the screen this panel belongs to. */
async function bukaBeranda(page: Page, nama: string): Promise<void> {
    await daftarDanMasuk(page, { nama });
    await page.goto('/dashboard');
    await expect(kolom(page)).toBeVisible({ timeout: 30_000 });
}

/** Focus the field and wait for the panel that focus is supposed to open. */
async function bukaPanel(page: Page): Promise<void> {
    await kolom(page).focus();
    await expect(panel(page)).toBeVisible({ timeout: 30_000 });
}

async function simpanGambar(page: Page, vp: Vp, keadaan: string): Promise<void> {
    await page.screenshot({
        path: `ux/refs/dashboard-cari/cari-${keadaan}-${vp.nama}.png`,
        fullPage: true,
        animations: 'disabled',
    });
}

/**
 * Every label on the page, read off the DOM rather than off the visible text - AC-13
 * forbids the fixture word in an `aria-label`, and a hidden accessible name is exactly
 * where a search term most plausibly would leak.
 */
async function labelAria(page: Page): Promise<string[]> {
    return page.locator('[aria-label]').evaluateAll((elemen) =>
        elemen.map((element) => element.getAttribute('aria-label') ?? ''),
    );
}

/**
 * Install a catalogue answer for a state a live seed cannot be made to produce.
 *
 * The filter is by `pathname` rather than by the glob, because that glob also matches
 * `/api/v1/dokter/{id}/slot`, and swallowing a slot request inside a test about the
 * suggestion list would fail somewhere the reader has to guess.
 *
 * (The glob itself is written out below as a string, never inside a comment: the sequence
 * `star star slash` that it contains would close a block comment early, which is exactly
 * how this file's first draft failed to parse at a line that had nothing wrong with it.)
 */
async function pasangMockSaran(
    page: Page,
    ambilMode: () => 'kosong' | 'galat',
): Promise<void> {
    await page.route('**/api/v1/dokter**', async (route) => {
        const url = new URL(route.request().url());

        if (url.pathname !== '/api/v1/dokter') {
            await route.fallback();
            return;
        }

        if (ambilMode() === 'galat') {
            await route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'Server error' }),
            });
            return;
        }

        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                data: { dokter: [] },
                meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: 6,
                    total: 0,
                    from: null,
                    to: null,
                },
            }),
        });
    });
}

for (const vp of VIEWPORTS) {
    test.describe(`Panel pencarian beranda ${vp.nama} ${vp.width}x${vp.height}`, () => {
        test.use({ viewport: { width: vp.width, height: vp.height }, timezoneId: 'Asia/Jakarta' });

        test.setTimeout(150_000);

        test('terbuka saat fokus, menawarkan saran dari katalog, dan lolos axe', async ({
            page,
        }) => {
            await bukaBeranda(page, `Pasien Panel Cari ${vp.nama}`);

            /**
             * Folded until touched. A panel that rendered on mount would cover the
             * dashboard the patient came to read, before they had asked for anything.
             */
            await expect(panel(page), 'panel harus tertutup sampai difokuskan').toHaveCount(0);

            await bukaPanel(page);

            const kotak = panel(page);

            await expect(
                kotak.locator('[data-slot="dashboard-cari-saran"]'),
                'bagian saran harus menyebut apa yang sebenarnya diurutkan',
            ).toContainText('Dokter paling banyak dikonsultasikan');

            /**
             * Rows from the live catalogue, so this cannot pass on a fixture the server
             * would never send. The label is asserted too: it is the honest one, and
             * swapping it back to "Pencarian populer" would be the lie this component's
             * docblock exists to prevent.
             */
            const baris = kotak.locator('[data-slot="dashboard-cari-saran"] button');

            await expect(baris.first(), 'katalog harus menawarkan sekurangnya satu nama').toBeVisible({
                timeout: 30_000,
            });
            expect(await baris.count()).toBeGreaterThan(0);

            await expectNoA11yViolations(page);
            await simpanGambar(page, vp, 'terbuka');
        });

        test('kueri tidak pernah masuk alamat, dan dikirim tepat satu kali', async ({ page }) => {
            await bukaBeranda(page, `Pasien Panel Privasi ${vp.nama}`);
            await bukaPanel(page);

            /**
             * Attached BEFORE the submit, because the assertion is a count: `on('request')`
             * sees only what happens after it is registered, which is exactly the window
             * the criterion is about.
             */
            const dicari: string[] = [];

            page.on('request', (permintaan) => {
                const url = new URL(permintaan.url());

                if (url.pathname === '/api/v1/dokter' && url.searchParams.has('search')) {
                    dicari.push(url.searchParams.get('search') ?? '');
                }
            });

            await kolom(page).fill(KATA_UJI);
            await panel(page).locator('[data-slot="dashboard-cari-kueri"]').click();

            await expect(page, 'submit harus mendarat di beranda yang menjawab').toHaveURL(/\/$/, {
                timeout: 30_000,
            });
            await expect(page.locator('[data-slot="landing-direktori"]')).toBeVisible({
                timeout: 30_000,
            });

            expect(
                dicari,
                'F03 AC-1: menekan kirim harus mengirim tepat satu GET /dokter?search=',
            ).toEqual([KATA_UJI]);

            // F03 AC-13, the three places the criterion names.
            expect(page.url(), 'alamat tidak boleh membawa kueri').not.toContain(KATA_UJI);
            expect(
                (await labelAria(page)).filter((label) => label.includes(KATA_UJI)),
                'aria-label tidak boleh membawa kueri',
            ).toEqual([]);
            expect(
                (await page.locator('[data-sonner-toast]').allInnerTexts()).filter((toast) =>
                    toast.includes(KATA_UJI),
                ),
                'toast tidak boleh membawa kueri',
            ).toEqual([]);

            /**
             * The term IS on screen, and that is correct: the count line reads
             * `{n} dokter untuk "{kueri}"`. The rule is about places a reader forwards to
             * another person - an address, an accessible name, a toast - not about the
             * answer to their own question, so this is asserted as present rather than
             * absent.
             */
            await expect(
                page.locator('[data-slot="dokter-count"]'),
                'jawaban harus tetap menyebut pertanyaannya',
            ).toContainText(KATA_UJI);
        });

        test('setiap sasaran sentuh di panel berukuran minimal 44 px', async ({ page }) => {
            await bukaBeranda(page, `Pasien Panel Sentuh ${vp.nama}`);

            /**
             * With a history seeded first, because the chips and `Hapus Semua` are the
             * controls a phone actually presses and they do not exist until there is a
             * history to clear. Seeding `localStorage` directly is the honest way to reach
             * it - the alternative is performing eight real searches to fill it.
             */
            await page.evaluate(() => {
                globalThis.localStorage.setItem(
                    'sehatly.dokter.riwayat',
                    JSON.stringify(['dr. Rina', 'drg. Andi', 'Sp.JP', 'dr. Budi']),
                );
            });

            await page.reload();
            await expect(kolom(page)).toBeVisible({ timeout: 30_000 });
            await bukaPanel(page);

            await expect(
                panel(page).locator('[data-slot="dashboard-cari-riwayat"]'),
                'riwayat yang disuntik harus tampil supaya chip-nya terukur',
            ).toBeVisible();

            /**
             * Every button reachable in the closed field AND the open panel, measured from
             * the DOM rather than from the class list - a `min-h-11` that a flex rule had
             * overridden would still be caught here.
             */
            const tombol = page.locator('[data-slot^="dashboard-cari"] button');
            const jumlah = await tombol.count();

            expect(jumlah, 'harus ada beberapa sasaran untuk diukur').toBeGreaterThan(0);

            const ukuran = await tombol.evaluateAll((elemen) =>
                elemen.map((element) => {
                    const kotak = element.getBoundingClientRect();

                    return {
                        teks: (element.textContent ?? element.getAttribute('aria-label') ?? '').trim(),
                        tinggi: Math.round(kotak.height),
                        lebar: Math.round(kotak.width),
                    };
                }),
            );

            for (const setiap of ukuran) {
                const sisi = Math.min(setiap.tinggi, setiap.lebar);

                expect(
                    sisi,
                    `"${setiap.teks.slice(0, 40)}" hanya ${setiap.lebar}x${setiap.tinggi} px, F03 §8 mensyaratkan >=44`,
                ).toBeGreaterThanOrEqual(44);
            }

            await simpanGambar(page, vp, 'sentuh');
        });
    });
}

test.describe('Panel pencarian beranda: keadaan', () => {
    test.use({
        viewport: { width: 1280, height: 900 },
        timezoneId: 'Asia/Jakarta',
    });

    test.setTimeout(150_000);

    test('saran kosong menawarkan jalan keluar, bukan kotak kosong', async ({ page }) => {
        await pasangMockSaran(page, () => 'kosong');
        await bukaBeranda(page, 'Pasien Panel Kosong');
        await bukaPanel(page);

        const kotak = panel(page);

        await expect(
            kotak,
            'daftar kosong membutuhkan salinan yang menjelaskan keadaan',
        ).toContainText('Belum ada dokter yang memenuhi syarat untuk tampil.');

        await expect(
            kotak.getByRole('link', { name: 'Lihat dokter terdekat' }),
            'keadaan kosong harus punya tindakan lanjut (AGENTS.md)',
        ).toBeVisible();

        /**
         * The link is a real door, not a label: `/?semua=1` is the directory with nothing
         * applied, so following it must leave the empty panel behind for the full list.
         * Asserted rather than assumed, because a link that re-rendered the same empty
         * state under a new address would satisfy the presence check above.
         */
        await kotak.getByRole('link', { name: 'Lihat dokter terdekat' }).click();

        await expect(page).toHaveURL(/\/\?semua=1$/, { timeout: 30_000 });
        await expect(page.locator('[data-slot="landing-direktori"]')).toBeVisible({
            timeout: 30_000,
        });
    });

    test('saran yang gagal mengatakan gagal, bukan bahwa katalognya kosong', async ({ page }) => {
        let mode: 'kosong' | 'galat' = 'galat';

        await pasangMockSaran(page, () => mode);
        await bukaBeranda(page, 'Pasien Panel Galat');
        await bukaPanel(page);

        const kotak = panel(page);

        /**
         * The defect this test exists for: before the error branch, `isError` fell through
         * to `rows.length === 0` and the panel answered a network failure with a statement
         * about the catalogue. Two assertions, because catching the lie is worthless if the
         * reader is then left with nothing to do about it.
         */
        await expect(kotak, 'galat harus diberi label galat').toContainText(
            'Gagal memuat saran.',
            { timeout: 60_000 },
        );
        await expect(
            kotak,
            'galat tidak boleh menjelma menjadi salinan "katalog kosong"',
        ).not.toContainText('Belum ada dokter yang memenuhi syarat');

        await expect(
            kotak.getByRole('button', { name: 'Coba lagi' }),
            'galat harus menyediakan coba lagi (F03 §6)',
        ).toBeVisible();

        /**
         * And the retry has to work, not merely exist - `shouldRetryQuery` has already
         * spent two attempts on the 500, so this flips the fixture mid-test and presses
         * the button the reader would press.
         */
        mode = 'kosong';
        await kotak.getByRole('button', { name: 'Coba lagi' }).click();

        await expect(
            kotak,
            'coba lagi harus menampilkan jawaban server yang baru',
        ).toContainText('Belum ada dokter yang memenuhi syarat untuk tampil.', {
            timeout: 60_000,
        });
    });
});

test.describe('Panel pencarian beranda: riwayat', () => {
    test.use({
        viewport: { width: 1280, height: 900 },
        timezoneId: 'Asia/Jakarta',
    });

    test.setTimeout(180_000);

    test('chip muncul setelah pencarian, bertahan muat ulang, dan Hapus Semua menghapusnya', async ({
        page,
    }) => {
        await bukaBeranda(page, 'Pasien Panel Riwayat');

        await bukaPanel(page);
        await kolom(page).fill('dr. Rina');
        await panel(page).locator('[data-slot="dashboard-cari-kueri"]').click();

        await expect(page).toHaveURL(/\/$/, { timeout: 30_000 });

        // Back to the screen the panel lives on, and the term is now a chip.
        await page.goto('/dashboard');
        await expect(kolom(page)).toBeVisible({ timeout: 30_000 });
        await bukaPanel(page);

        const riwayat = panel(page).locator('[data-slot="dashboard-cari-riwayat"]');

        await expect(riwayat, 'kueri yang baru dikirim harus menjadi chip').toContainText(
            'dr. Rina',
        );

        /**
         * The load-bearing assertion for the decision recorded in F03 §12 butir 10(a): the
         * chip must survive a reload, which is only possible from `localStorage`. If this
         * ever fails, the history has quietly fallen back to `sessionStorage` and the
         * deviation the product owner approved is no longer the behaviour in the product.
         */
        await page.reload();
        await expect(kolom(page)).toBeVisible({ timeout: 30_000 });
        await bukaPanel(page);

        await expect(
            panel(page).locator('[data-slot="dashboard-cari-riwayat"]'),
            'riwayat harus bertahan melewati muat ulang',
        ).toContainText('dr. Rina');

        const tersimpan = await page.evaluate(() =>
            globalThis.localStorage.getItem('sehatly.dokter.riwayat'),
        );

        expect(tersimpan, 'riwayat harus hidup di localStorage').not.toBeNull();
        expect(tersimpan).toContain('dr. Rina');

        // The control UU PDP requires for data kept this way.
        await panel(page).getByRole('button', { name: 'Hapus Semua' }).click();

        await expect(
            panel(page).locator('[data-slot="dashboard-cari-riwayat"]'),
            'Hapus Semua harus mengosongkan blok riwayat',
        ).toHaveCount(0);

        const sesudah = await page.evaluate(() =>
            globalThis.localStorage.getItem('sehatly.dokter.riwayat'),
        );

        expect(JSON.parse(sesudah ?? 'null'), 'Hapus Semua harus menulis kembali storage').toEqual(
            [],
        );
    });
});

for (const vp of VIEWPORTS) {
    test.describe(`Panel pencarian beranda mode gelap ${vp.nama}`, () => {
        test.use({
            viewport: { width: vp.width, height: vp.height },
            timezoneId: 'Asia/Jakarta',
        });

        test.setTimeout(150_000);

        test('panel tetap hitam-putih dan lolos axe saat tema gelap', async ({ page }) => {
            /**
             * BEFORE the first navigation: `initializeTheme()` runs from `main.tsx` and
             * rewrites anything that is not `'dark'` back to `'light'`, so a value set
             * after boot would be undone on the next full page load.
             */
            await page.addInitScript(() => {
                globalThis.localStorage.setItem('appearance', 'dark');
            });

            await bukaBeranda(page, `Pasien Panel Gelap ${vp.nama}`);
            await bukaPanel(page);

            await expect(
                page.locator('html'),
                'tema gelap harus benar-benar aktif, bukan sekadar diminta',
            ).toHaveClass(/dark/);

            await expectNoA11yViolations(page);
            await simpanGambar(page, vp, 'gelap');
        });
    });
}
