import { mkdirSync } from 'node:fs';
import {
    expect,
    test,
    type Locator,
    type Page,
    type Route,
} from '@playwright/test';
import { expectNoA11yViolations, istirahatkanGerak } from './a11y';

/**
 * F03 (cari dokter dan filter) mocked end to end at 390x844 and 1280x900.
 *
 * ## Why every call is mocked
 *
 * Every criterion needs a response the live seed cannot produce on demand: a doctor named
 * "Rina", eight results for one specialisation, zero results for a combined filter, a 500
 * on page three, and a count that changes while the request is still in flight. Mocking also
 * keeps the privacy fixture ("kanker") out of any real server.
 *
 * ## Where the tests open it
 *
 * There is no `/dokter` page any more. `bukaDirektori` opens `/?semua=1` - the landing
 * page showing the directory with nothing applied - and every door in the navigation
 * writes that address. The consequence that shapes every selector below is that ASKING
 * and ANSWERING now live on separate surfaces: a constraint is picked in the header (its
 * hover panel on a desktop, the drawer on a phone) and the answer under the headline
 * carries no picker of its own. It carries what was picked (the chips), how many matched
 * (the count), who (the cards), in what order (`Urutkan`) and the way out. The one
 * leftover of the old address, `/dokter`, is a redirect to `/?semua=1` and has its own
 * test, `f03-alamat-lama-beranda-mengalihkan-ke-dokter`.
 *
 * ## What the F03 backend commit unblocked
 *
 * AC-5 (`Urutkan`, needs `?sort=`) and AC-11 (`{n} ulasan` on the card, needs
 * `jumlah_ulasan` in `DokterResource`) were `[TERBLOKIR backend]` per `patterns/F03.md`.
 * Commit `1ce97b1` adds both, so they are real tests below: the mock answers `sort` with a
 * real ordering and every fixture row carries `pengalaman_tahun` and `jumlah_ulasan`.
 *
 * ## The free-text query never reaches the URL
 *
 * The fixture word is a medical condition, which is the point of the assertion: after
 * typing it and submitting, the address bar, the title, every `aria-label` and every toast
 * must be clean (AC-13). The input itself keeps the term, and `sessionStorage` carries it
 * across the reload AC-1 requires.
 */

const VIEWPORTS = [
    { nama: 'mobile', width: 390, height: 844 },
    { nama: 'desktop', width: 1280, height: 900 },
] as const;

type Vp = (typeof VIEWPORTS)[number];

const KATA_UJI = 'kanker';

mkdirSync('ux/refs/f03', { recursive: true });

type DokterUji = {
    id: number;
    nama_lengkap: string;
    tipe: string;
    spesialisasi: string | null;
    biaya_konsultasi_online: string;
    rating_rata_rata: string;
    jumlah_konsultasi: number;
    pengalaman_tahun: number;
    jumlah_ulasan: number;
    status_verifikasi: string;
    /** Test-only handle for `?spesialisasi=`; not part of the published resource. */
    kode: string;
};

type SpesialisasiUji = {
    id: number;
    kode: string;
    nama: string;
    tipe: string;
};

const MASTER_SPESIALISASI: SpesialisasiUji[] = [
    { id: 1, kode: 'UMUM', nama: 'Dokter Umum', tipe: 'dokter_umum' },
    { id: 2, kode: 'SP.PD', nama: 'Spesialis Penyakit Dalam', tipe: 'spesialis' },
    { id: 3, kode: 'SP.A', nama: 'Spesialis Anak', tipe: 'spesialis' },
    { id: 4, kode: 'SP.OG', nama: 'Spesialis Obstetri & Ginekologi', tipe: 'spesialis' },
    { id: 16, kode: 'GIGI', nama: 'Dokter Gigi', tipe: 'spesialis' },
];

function dokterUji(
    id: number,
    nama: string,
    tipe: string,
    kode: string,
    spesialisasi: string,
    biaya: string,
    rating: string,
    konsultasi: number,
    pengalaman: number,
    ulasan: number,
): DokterUji {
    return {
        id,
        nama_lengkap: nama,
        tipe,
        kode,
        spesialisasi,
        biaya_konsultasi_online: biaya,
        rating_rata_rata: rating,
        jumlah_konsultasi: konsultasi,
        pengalaman_tahun: pengalaman,
        jumlah_ulasan: ulasan,
        status_verifikasi: 'terverifikasi',
    };
}

/** 8 Anak, 2 Penyakit Dalam, 2 Gigi: the fixture every criterion below starts from. */
function dokterDefault(): DokterUji[] {
    const anak = Array.from({ length: 8 }, (_unused, index) =>
        dokterUji(
            100 + index,
            index === 0
                ? 'dr. Rina Wulandari, Sp.A'
                : `dr. Sari Kusuma ${String(index + 1)}, Sp.A`,
            'dokter_spesialis',
            'SP.A',
            'Spesialis Anak',
            '85000.00',
            '4.9',
            128 - index,
            12 - index,
            128 - index,
        ),
    );

    return [
        ...anak,
        dokterUji(
            200,
            'dr. Andi Pratama, Sp.PD',
            'dokter_spesialis',
            'SP.PD',
            'Spesialis Penyakit Dalam',
            '120000.00',
            '4.7',
            210,
            20,
            156,
        ),
        dokterUji(
            201,
            'dr. Sri Handayani, Sp.PD',
            'dokter_spesialis',
            'SP.PD',
            'Spesialis Penyakit Dalam',
            '110000.00',
            '4.8',
            180,
            15,
            98,
        ),
        dokterUji(
            300,
            'drg. Maya Sari',
            'dokter_gigi',
            'GIGI',
            'Dokter Gigi',
            '90000.00',
            '4.8',
            64,
            8,
            64,
        ),
        // Deliberately unreviewed: the AC-11 fixture for "0 ulasan must not be printed".
        dokterUji(
            301,
            'drg. Bagus Nugroho',
            'dokter_gigi',
            'GIGI',
            'Dokter Gigi',
            '95000.00',
            '4.6',
            52,
            6,
            0,
        ),
    ];
}

/** `n` Sp.A doctors whose names all contain "Anak", for pagination and failure tests. */
function banyakDokter(n: number): DokterUji[] {
    return Array.from({ length: n }, (_unused, index) =>
        dokterUji(
            400 + index,
            `dr. Anak Contoh ${String(index + 1)}, Sp.A`,
            'dokter_spesialis',
            'SP.A',
            'Spesialis Anak',
            '85000.00',
            '4.7',
            40 + index,
            5,
            0,
        ),
    );
}

type State = {
    dokter: DokterUji[];
    gagal: boolean;
    tundaMs: number;
    halamanKosong: boolean;
    permintaanDokter: string[];
    permintaanMaster: number;
};

type OpsiMock = {
    dokter?: DokterUji[];
    tundaMs?: number;
};

async function balasJson(route: Route, status: number, body: unknown): Promise<void> {
    await route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

/**
 * The server's six orderings, mirrored so AC-5 can assert a real sequence rather than a
 * request parameter alone. `null` and an unknown value both leave the default order, which
 * is what `IndexDokterRequest`'s whitelist plus the service's `default` arm do.
 */
function urutkanMock(rows: DokterUji[], sort: string | null): DokterUji[] {
    const hasil = [...rows];

    if (sort === 'biaya_asc') {
        hasil.sort(
            (a, b) =>
                Number(a.biaya_konsultasi_online) - Number(b.biaya_konsultasi_online),
        );
    } else if (sort === 'biaya_desc') {
        hasil.sort(
            (a, b) =>
                Number(b.biaya_konsultasi_online) - Number(a.biaya_konsultasi_online),
        );
    } else if (sort === 'rating') {
        hasil.sort((a, b) => Number(b.rating_rata_rata) - Number(a.rating_rata_rata));
    } else if (sort === 'pengalaman') {
        hasil.sort((a, b) => b.pengalaman_tahun - a.pengalaman_tahun);
    } else if (sort === 'ulasan') {
        hasil.sort((a, b) => b.jumlah_ulasan - a.jumlah_ulasan);
    }

    return hasil;
}

async function pasangMock(page: Page, opsi: OpsiMock = {}): Promise<State> {
    const state: State = {
        dokter: opsi.dokter ?? dokterDefault(),
        gagal: false,
        tundaMs: opsi.tundaMs ?? 0,
        halamanKosong: false,
        permintaanDokter: [],
        permintaanMaster: 0,
    };

    await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;

        if (path === '/api/v1/master-spesialisasi') {
            state.permintaanMaster += 1;

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar spesialisasi berhasil dimuat.',
                data: { spesialisasi: MASTER_SPESIALISASI },
                meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: MASTER_SPESIALISASI.length,
                    total: MASTER_SPESIALISASI.length,
                    from: 1,
                    to: MASTER_SPESIALISASI.length,
                },
            });
        }

        if (path === '/api/v1/dokter' && request.method() === 'GET') {
            state.permintaanDokter.push(request.url());

            if (state.tundaMs > 0) {
                await new Promise((resolve) => {
                    setTimeout(resolve, state.tundaMs);
                });
            }

            if (state.gagal) {
                return balasJson(route, 500, {
                    success: false,
                    message: 'Terjadi kesalahan pada server.',
                    errors: {},
                });
            }

            const search = url.searchParams.get('search');
            const spesialisasi = url.searchParams.get('spesialisasi');
            const tipe = url.searchParams.get('tipe');
            const telemedisin = url.searchParams.get('tersedia_telemedisin');
            const sort = url.searchParams.get('sort');
            const page = Number(url.searchParams.get('page') ?? '1');
            const perPage = Number(url.searchParams.get('per_page') ?? '15');

            const tersaring = state.dokter.filter((row) => {
                if (
                    search !== null &&
                    !row.nama_lengkap.toLowerCase().includes(search.toLowerCase())
                ) {
                    return false;
                }

                if (spesialisasi !== null && row.kode !== spesialisasi) {
                    return false;
                }

                if (tipe !== null && row.tipe !== tipe) {
                    return false;
                }

                if (telemedisin === '1' || telemedisin === 'true') {
                    return true;
                }

                return true;
            });

            const terurut = urutkanMock(tersaring, sort);
            const total = terurut.length;
            const mulai = (page - 1) * perPage;
            const kosongPaksa = state.halamanKosong && page > 1;
            const halamanTerakhir = kosongPaksa
                ? 1
                : Math.max(1, Math.ceil(total / perPage));
            const baris = kosongPaksa
                ? []
                : terurut.slice(mulai, mulai + perPage);

            return balasJson(route, 200, {
                success: true,
                message: 'Daftar dokter berhasil dimuat.',
                data: { dokter: baris },
                meta: {
                    current_page: page,
                    last_page: halamanTerakhir,
                    per_page: perPage,
                    total,
                    from: baris.length === 0 ? null : mulai + 1,
                    to: baris.length === 0 ? null : mulai + baris.length,
                },
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

function punyaPermintaan(
    state: State,
    cek: (params: URLSearchParams) => boolean,
): boolean {
    return state.permintaanDokter.some((url) => cek(new URL(url).searchParams));
}

/**
 * The directory as this spec finds it: the LANDING page with the directory open and
 * nothing applied. `?semua=1` is the address every door in the navigation writes, and
 * `/dokter` redirects to it - see `f03-alamat-lama-beranda-mengalihkan-ke-dokter`.
 */
async function bukaDirektori(page: Page, opsi: OpsiMock = {}): Promise<State> {
    const state = await pasangMock(page, opsi);

    await page.goto('/?semua=1');

    await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
        'dokter ditemukan.',
        { timeout: 15_000 },
    );

    return state;
}

/**
 * The one search field: in the bar on a desktop, inside the drawer on a phone. The
 * answer under it has none, because a form beneath a result list is a second place to
 * ask the same question.
 */
async function kolomCari(page: Page, vp: Vp): Promise<Locator> {
    if (vp.nama === 'mobile') {
        const laci = page.locator('[role="dialog"]');

        // Reuses an already-open drawer rather than toggling it shut: a test that needs
        // the field twice (AC-9 does, before and after the connection comes back) must
        // not depend on whether the first submit closed it.
        if ((await laci.count()) === 0) {
            await page.getByRole('button', { name: 'Buka menu navigasi' }).click();

            await expect(laci).toBeVisible();
        }

        return laci.getByRole('searchbox', { name: 'Cari dokter' });
    }

    return page
        .locator('[data-slot="landing-cari"]:visible')
        .getByRole('searchbox', { name: 'Cari dokter' });
}

/**
 * Closing the phone drawer. No-op on a desktop, where the bar has no drawer to close.
 *
 * Tests that reach past the drawer for something in the answer behind it need this: a
 * click that lands on the scrim closes the sheet, and Radix unmounts the drawer's copy of
 * the search bar on the way out - taking the typed term with it, because that term is
 * component state and state belongs to the instance.
 */
async function tutupLaci(page: Page, vp: Vp): Promise<void> {
    if (vp.nama !== 'mobile') {
        return;
    }

    const laci = page.locator('[role="dialog"]');

    if ((await laci.count()) === 0) {
        return;
    }

    await page.keyboard.press('Escape');

    await expect(laci).toHaveCount(0);
}

async function cariDokter(page: Page, vp: Vp, kueri: string): Promise<void> {
    const kolom = await kolomCari(page, vp);

    await kolom.fill(kueri);
    await kolom.press('Enter');

    /**
     * The term never reaches the address (F03 §9), so "the search happened" has exactly
     * one visible witness: the chip. Waiting on it is what stops every assertion below
     * from reading the answer to the question the visitor had asked BEFORE typing.
     */
    await expect(
        page.locator('[data-slot="dokter-chip"]').filter({ hasText: kueri }),
    ).toHaveCount(1);
}

/**
 * Where a constraint is chosen - the header's hover panel on a desktop, the same choices
 * inside the drawer on a phone. The answer no longer has a picker to drive, so these
 * helpers use the surface a real visitor would use rather than editing the address.
 */
async function panelPilihan(page: Page, vp: Vp): Promise<Locator> {
    if (vp.nama === 'mobile') {
        await page.getByRole('button', { name: 'Buka menu navigasi' }).click();

        const laci = page.locator('[role="dialog"]');

        await expect(laci).toBeVisible();

        return laci.locator('[data-slot="nav-direktori-laci"]');
    }

    await page.getByRole('button', { name: 'Direktori Dokter' }).hover();

    const panel = page.locator('[data-slot="nav-direktori-panel"]');

    await expect(panel).toBeVisible();

    return panel;
}

/**
 * Pick one thing by name. A row is a plain `<a href>`, so the click navigates (no reload)
 * and the drawer or panel closes itself on the way out - asserted rather than assumed,
 * because an overlay left open over the answer it just opened is the defect that made
 * these two surfaces separate in the first place.
 */
async function pilihTautan(page: Page, vp: Vp, nama: string): Promise<void> {
    const panel = await panelPilihan(page, vp);

    await panel.getByRole('link', { name: nama }).click();

    await expect(page.locator('[data-slot="landing-direktori"]')).toBeVisible();
    await expect(page.locator('[role="dialog"]')).toHaveCount(0);
    await expect(page.locator('[data-slot="nav-direktori-panel"]')).toHaveCount(0);
}

async function pilihSpesialisasi(page: Page, vp: Vp, nama: string): Promise<void> {
    await pilihTautan(page, vp, nama);
}

/**
 * `pilihTipe` and `pilihTelemedisin` used to live here and have no caller any more: the
 * panel and the drawer offer ONE kind of choice now. A doctor type and telemedisin can
 * still be reached by typing `?tipe=` or `?telemedisin=` into the address - the answer
 * reads both - but nothing in the interface offers them, so there is nothing for a test
 * to click.
 */

/**
 * Opens the shared `Urutkan` select and picks `label`. The control sits beside the count
 * on both viewports now - there is no sheet to have drafted it in first, because a draft
 * that only "Tampilkan" can commit is a control that costs two taps to do the thing the
 * one visible control already does.
 *
 * Selection is driven by keyboard, not by a coordinate click: on a phone the select sits
 * at the top of a scrolling list, so Radix clamps the listbox and a forced click on a
 * partially scrolled option can land on its neighbour. Typing the option's own label uses
 * Radix's typeahead, and it is also the keyboard check F03 §8 asks of this control.
 */
async function pilihOpsiUrutkan(page: Page, label: string): Promise<void> {
    const pemicu = page.getByRole('combobox', { name: 'Urutkan' });

    await pemicu.click();

    await page.locator('[data-slot="select-content"]').waitFor();

    // The content animates in with a zoom transform; keyboard input mid-animation can miss.
    await page.waitForTimeout(250);

    await page.keyboard.type(label);
    await page.keyboard.press('Enter');

    await expect(page.locator('[data-slot="select-content"]')).toHaveCount(0);
    await expect(pemicu).toContainText(label);
}

/** The `Mulai Rp …` badge of each card, as a number, in DOM order. */
async function biayaKartu(page: Page): Promise<number[]> {
    return page.locator('[data-slot="dokter-biaya"]').evaluateAll((badges) =>
        badges.map((element) =>
            Number((element.textContent ?? '').replace(/[^0-9]/g, '')),
        ),
    );
}

async function simpanGambar(
    page: Page,
    vp: Vp,
    keadaan: string,
    fullPage = true,
): Promise<void> {
    await page.screenshot({
        path: `ux/refs/f03/f03-${keadaan}-${vp.nama}.png`,
        fullPage,
        animations: 'disabled',
    });
}

for (const vp of VIEWPORTS) {
    test.describe(`F03 direktori dokter ${vp.nama} ${vp.width}x${vp.height}`, () => {
        test.use({
            viewport: { width: vp.width, height: vp.height },
            timezoneId: 'Asia/Jakarta',
        });

        test.setTimeout(60_000);

        /**
         * The address the app wrote for years still answers, and it answers with the
         * directory rather than a 404 or the marketing sections.
         *
         * `/dokter` is a redirect to `/?semua=1`, and every parameter is carried across:
         * a `?spesialisasi=` bookmark means the same thing on both addresses, and `search`
         * is carried too because F03 §9 forbids this app from WRITING a free-text query
         * into an address - dropping one an older build already wrote would trade that
         * rule for a wrong answer. `?direktori=semua`, which this app's own navigation
         * used to write, becomes `?semua=1`.
         */
        test('f03-alamat-lama-beranda-mengalihkan-ke-dokter', async ({ page }) => {
            const state = await pasangMock(page);

            await page.goto('/dokter');

            await expect(page).toHaveURL('/?semua=1');
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                'dokter ditemukan.',
                { timeout: 15_000 },
            );
            // No headline over an unfiltered visit. The section is NAMED - assistive
            // technology still gets "Direktori dokter" through `aria-label` - but it is
            // not printed, because a screen that has to tell somebody looking at a list
            // of doctors that they are looking at a list of doctors is a line of ink
            // with nothing to say.
            const seksi = page.locator('[data-slot="landing-direktori"]');

            await expect(seksi).toHaveAttribute('aria-label', 'Direktori dokter');
            await expect(seksi.locator('h1')).toHaveCount(0);

            // The address a search never travels through: no request carries one until
            // the visitor types one.
            expect(
                state.permintaanDokter.filter(
                    (url) => new URL(url).searchParams.get('search') !== null,
                ),
            ).toHaveLength(0);

            // A bookmark written by the build that had a `/dokter` page with filters.
            await page.goto('/dokter?spesialisasi=SP.A');

            await expect(page).toHaveURL('/?spesialisasi=SP.A');
            await expect(
                page.getByRole('heading', { name: 'Spesialis Anak', level: 1 }),
            ).toBeVisible();
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '8 dokter ditemukan.',
            );

            // The landing page's own old door.
            await page.goto('/?direktori=semua');

            await expect(page).toHaveURL('/?semua=1');
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                'dokter ditemukan.',
            );

            // A question an older build wrote into the address keeps its answer.
            await page.goto('/?search=Rina');

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '1 dokter ditemukan.',
            );
            await expect(
                page.locator('[data-slot="dokter-chip"]').filter({ hasText: 'Rina' }),
            ).toHaveCount(1);

            await expectNoA11yViolations(page);
        });

        test('f03-ac1-cari-submit', async ({ page }) => {
            const state = await bukaDirektori(page);

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '12 dokter ditemukan.',
            );

            // Asking happens in the header now. A search field beneath a result list is a
            // second place to ask the same question, and the "Sering dicari" shortcuts
            // that used to sit beside it moved into the same panel as the filters.
            await expect(
                page.locator('[data-slot="landing-direktori"] form'),
            ).toHaveCount(0);
            await expect(page.getByText('Sering dicari:')).toHaveCount(0);

            await cariDokter(page, vp, 'Rina');

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '1 dokter ditemukan.',
            );
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(1);
            await expect(
                page.locator('[data-slot="dokter-kartu"]').first(),
            ).toContainText('dr. Rina Wulandari, Sp.A');

            // The term faces the visitor as a chip they can take off - there is no field
            // down here to read it back from.
            await expect(
                page.locator('[data-slot="dokter-chip"]').filter({ hasText: 'Rina' }),
            ).toHaveCount(1);

            const pencarian = state.permintaanDokter.filter((url) =>
                new URL(url).searchParams.get('search') === 'Rina',
            );

            expect(pencarian.length).toBe(1);

            await simpanGambar(page, vp, 'hasil');

            // AC-1: the query survives a reload through state, never through the URL.
            expect(page.url()).not.toContain('Rina');

            await page.reload();

            await expect(
                page.locator('[data-slot="dokter-chip"]').filter({ hasText: 'Rina' }),
            ).toHaveCount(1);
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '1 dokter ditemukan.',
            );
            expect(page.url()).not.toContain('Rina');

            await expectNoA11yViolations(page);
        });

        /**
         * AC-2 used to be "a filter sheet on a phone", because the constraints lived on
         * the directory and a phone had no room for a sidebar. They live in the header
         * now - its hover panel on a desktop, the same rows inside the drawer on a phone
         * - so what is left to prove is the part that actually matters: a pick made there
         * is obeyed down here, and this answer holds no picker that could contradict it.
         */
        test('f03-ac2-filter-dipilih-di-navbar', async ({ page }) => {
            const state = await bukaDirektori(page);

            await expect(page.locator('[data-slot="dokter-panel-filter"]')).toHaveCount(0);
            await expect(page.locator('[data-slot="dokter-filter-button"]')).toHaveCount(0);
            await expect(
                page.locator('[data-slot="landing-direktori"] form'),
            ).toHaveCount(0);

            await pilihSpesialisasi(page, vp, 'Spesialis Anak');

            await expect(page).toHaveURL('/?spesialisasi=SP.A');
            await expect(
                page.getByRole('heading', { name: 'Spesialis Anak', level: 1 }),
            ).toBeVisible();
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '8 dokter ditemukan.',
            );
            await expect(
                page.getByRole('button', { name: 'Hapus filter Spesialis Anak' }),
            ).toBeVisible();

            expect(
                punyaPermintaan(
                    state,
                    (params) => params.get('spesialisasi') === 'SP.A',
                ),
            ).toBe(true);

            await simpanGambar(page, vp, 'terfilter');

            await expectNoA11yViolations(page);
        });

        test('f03-ac3-chip-hapus', async ({ page }) => {
            await bukaDirektori(page);

            /**
             * Two chips, and the second one has to come from the SEARCH: the panel and
             * the drawer write specialisations only now, so a term typed into the
             * header's bar is the one other constraint a visitor can still earn. Every
             * Anak doctor in the fixture is named "…, Sp.A" and no other row is, so the
             * intersection is the same eight - which is also what keeps the page tall
             * enough for the scroll check below to mean anything.
             */
            await pilihSpesialisasi(page, vp, 'Spesialis Anak');
            await cariDokter(page, vp, 'Sp.A');

            const chips = page.locator('[data-slot="dokter-chip"]');

            await expect(chips).toHaveCount(2);
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '8 dokter ditemukan.',
            );

            await page.evaluate(() => {
                (window as unknown as Record<string, unknown>).__f03_tanpaReload = true;
                window.scrollTo(0, 320);
            });

            const sebelum = await page.evaluate(() => window.scrollY);

            expect(sebelum).toBeGreaterThan(0);

            // A JS click keeps the scroll position under the test's control; a real click
            // would auto-scroll the chip into view first and hide the behaviour under test.
            await page
                .getByRole('button', { name: 'Hapus filter Mencari "Sp.A"' })
                .evaluate((element) => {
                    (element as HTMLButtonElement).click();
                });

            await expect(chips).toHaveCount(1);
            await expect(chips).toContainText('Spesialis Anak');
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '8 dokter ditemukan.',
            );

            const sesudah = await page.evaluate(() => window.scrollY);

            expect(Math.abs(sesudah - sebelum)).toBeLessThanOrEqual(4);

            expect(
                await page.evaluate(
                    () =>
                        (window as unknown as Record<string, unknown>)
                            .__f03_tanpaReload === true,
                ),
            ).toBe(true);

            await expectNoA11yViolations(page);
        });

        test('f03-ac4-reset', async ({ page }) => {
            const state = await bukaDirektori(page);

            await pilihSpesialisasi(page, vp, 'Spesialis Anak');
            await cariDokter(page, vp, 'Sp.A');

            const chips = page.locator('[data-slot="dokter-chip"]');

            await expect(chips).toHaveCount(2);

            await page
                .getByRole('button', { name: 'Hapus semua filter' })
                .first()
                .click();

            await expect(chips).toHaveCount(0);
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '12 dokter ditemukan.',
            );

            await expect
                .poll(() =>
                    punyaPermintaan(
                        state,
                        (params) =>
                            params.get('spesialisasi') === null &&
                            params.get('tersedia_telemedisin') === null,
                    ),
                )
                .toBe(true);

            await expectNoA11yViolations(page);
        });

        test('f03-ac5-sort', async ({ page }) => {
            const state = await bukaDirektori(page);

            // AC-5: the default `Paling relevan` sends NO `sort` key at all - the server's
            // pre-F03 order is what `relevan` means, so sending it would change nothing.
            expect(state.permintaanDokter.length).toBeGreaterThan(0);

            for (const url of state.permintaanDokter) {
                expect(new URL(url).searchParams.get('sort')).toBeNull();
            }

            // `Biaya terendah` -> `sort=biaya_asc`, ascending by the published fee. There
            // is no draft to commit: `Urutkan` sits beside the count on both viewports and
            // costs exactly one request, which is the whole of AC-5's "one change, one
            // fetch" clause.
            await pilihOpsiUrutkan(page, 'Biaya terendah');

            const jumlahPermintaanAsc = (): number =>
                state.permintaanDokter.filter(
                    (url) => new URL(url).searchParams.get('sort') === 'biaya_asc',
                ).length;

            await expect.poll(jumlahPermintaanAsc).toBe(1);

            const biaya = await biayaKartu(page);

            expect(biaya).toEqual([
                85_000, 85_000, 85_000, 85_000, 85_000, 85_000, 85_000, 85_000, 90_000,
                95_000, 110_000, 120_000,
            ]);

            await simpanGambar(page, vp, 'urutkan');

            // Exactly one request: no second fetch follows the render.
            await page.waitForTimeout(300);
            expect(jumlahPermintaanAsc()).toBe(1);

            // Sort survives adding a constraint, and the constraint survives re-sorting.
            await pilihSpesialisasi(page, vp, 'Spesialis Anak');

            await expect
                .poll(() =>
                    punyaPermintaan(
                        state,
                        (params) =>
                            params.get('spesialisasi') === 'SP.A' &&
                            params.get('sort') === 'biaya_asc',
                    ),
                )
                .toBe(true);

            await pilihOpsiUrutkan(page, 'Rating tertinggi');

            await expect
                .poll(() =>
                    punyaPermintaan(
                        state,
                        (params) =>
                            params.get('spesialisasi') === 'SP.A' &&
                            params.get('sort') === 'rating',
                    ),
                )
                .toBe(true);

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '8 dokter ditemukan.',
            );

            await istirahatkanGerak(page);
            await expectNoA11yViolations(page);
        });

        test('f03-ac6-count', async ({ page }) => {
            await bukaDirektori(page, { tundaMs: 600 });

            const count = page.locator('[data-slot="dokter-count"]');

            await expect(count).toHaveAttribute('role', 'status');
            await expect(count).toContainText('12 dokter ditemukan.');

            await cariDokter(page, vp, 'tidakada');

            // While the filter request is in flight the count must not show the old number.
            await expect(count).toContainText('Menghitung hasil…');
            await expect(count).toContainText('0 dokter ditemukan.');
            await expect(page.getByText('Tidak ada dokter yang cocok.')).toBeVisible();

            await expectNoA11yViolations(page);
        });

        test('f03-ac7-zero-result', async ({ page }) => {
            await bukaDirektori(page);

            /**
             * A specialisation and a term that matches none of its doctors. It used to be
             * two picks from the panel - a specialisation plus a type that has none of
             * them - and the panel writes specialisations only now, so the second half of
             * an empty intersection has to come from the search.
             */
            await pilihSpesialisasi(page, vp, 'Spesialis Anak');
            await cariDokter(page, vp, 'tidakada');

            await expect(page.getByText('Tidak ada dokter yang cocok.')).toBeVisible();
            await expect(
                page.getByText('Coba hapus satu filter atau gunakan kata kunci lain.'),
            ).toBeVisible();

            const pemulihan = [
                page.getByRole('button', { name: 'Hapus filter Spesialis Anak' }).last(),
                page.getByRole('button', { name: 'Hapus semua filter' }).first(),
                page.getByRole('button', { name: 'Cari "Dokter Anak"' }),
                page.getByRole('button', { name: 'Lihat semua dokter' }),
            ];

            for (const aksi of pemulihan) {
                await expect(aksi).toBeVisible();
            }

            await simpanGambar(page, vp, 'nol-hasil');

            // Removing ONE chip leaves the other constraint in place.
            await page
                .getByRole('button', { name: 'Hapus filter Spesialis Anak' })
                .last()
                .click();

            const chips = page.locator('[data-slot="dokter-chip"]');

            await expect(chips).toHaveCount(1);
            await expect(chips).toContainText('tidakada');
            await expect(page.getByText('Tidak ada dokter yang cocok.')).toBeVisible();

            // `Lihat semua dokter` clears everything and restores the default list.
            await page.getByRole('button', { name: 'Lihat semua dokter' }).click();

            await expect(chips).toHaveCount(0);
            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '12 dokter ditemukan.',
            );

            await expectNoA11yViolations(page);
        });

        test('f03-ac8-500', async ({ page }) => {
            const state = await bukaDirektori(page, { dokter: banyakDokter(30) });

            await cariDokter(page, vp, 'Anak');
            await pilihSpesialisasi(page, vp, 'Spesialis Anak');

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '30 dokter ditemukan.',
            );

            await page.getByRole('button', { name: 'Halaman 2' }).click();
            await expect(page.locator('[data-slot="dokter-count"]')).toBeFocused();

            // The next page change fails.
            state.gagal = true;

            const galat = page.locator('[data-slot="error-state"]');

            await page.getByRole('button', { name: 'Halaman 3' }).click();

            await expect(galat).toBeVisible({ timeout: 25_000 });
            await expect(galat).toContainText(
                'Gagal memuat daftar dokter. Periksa koneksi lalu coba lagi.',
            );
            await expect(galat.getByRole('button', { name: 'Coba lagi' })).toBeVisible();

            // Chips, query and current page survive the failure.
            await expect(page.locator('[data-slot="dokter-chip"]')).toHaveCount(2);
            await expect(
                page.locator('[data-slot="dokter-chip"]').filter({ hasText: 'Mencari' }),
            ).toHaveCount(1);
            await expect(
                page
                    .locator('[data-slot="dokter-chip"]')
                    .filter({ hasText: 'Spesialis Anak' }),
            ).toHaveCount(1);

            const sebelumRetry = state.permintaanDokter.length;

            state.gagal = false;

            await galat.getByRole('button', { name: 'Coba lagi' }).click();

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '30 dokter ditemukan.',
                { timeout: 15_000 },
            );

            const setelahRetry = state.permintaanDokter.slice(sebelumRetry);

            expect(
                setelahRetry.some((url) => {
                    const params = new URL(url).searchParams;

                    return (
                        params.get('page') === '3' &&
                        params.get('spesialisasi') === 'SP.A' &&
                        params.get('search') === 'Anak'
                    );
                }),
            ).toBe(true);

            await expectNoA11yViolations(page);
        });

        test('f03-ac9-offline', async ({ page, context }) => {
            const state = await bukaDirektori(page);

            await pilihSpesialisasi(page, vp, 'Spesialis Anak');

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '8 dokter ditemukan.',
            );

            // The term is typed into the header's field - it never reaches the address,
            // and offline it never reaches a request either.
            const kolom = await kolomCari(page, vp);

            await kolom.fill('Rina');

            await context.setOffline(true);

            await expect(page.getByTestId('offline-banner')).toBeVisible();

            const sebelum = state.permintaanDokter.length;

            await kolom.press('Enter');

            await page.waitForTimeout(500);

            /**
             * Submitting sends nothing and navigates nowhere. A term that cannot be
             * answered must not be the reason the address changes, or the visitor comes
             * back online looking at a screen whose question they can no longer see.
             */
            expect(state.permintaanDokter.length).toBe(sebelum);
            await expect(kolom).toHaveValue('Rina');

            /**
             * On a phone the term was typed into the DRAWER's copy of the bar. The forced
             * click below would land on the drawer's scrim, close the sheet, and take the
             * typed term with it - the field is state, and state belongs to the instance.
             * Close it first, the way a visitor reading the answer would.
             */
            await tutupLaci(page, vp);

            // The controls the answer still carries are inert and point at the reason.
            const chip = page.locator('[data-slot="dokter-chip"]').first();

            await expect(chip).toHaveAttribute('aria-disabled', 'true');
            await expect(chip).toHaveAttribute(
                'aria-describedby',
                'dokter-alasan-offline',
            );

            const urutkan = page.locator('[data-slot="dokter-urutkan"]');

            await expect(urutkan).toBeDisabled();
            await expect(urutkan).toHaveAttribute(
                'aria-describedby',
                'dokter-alasan-offline',
            );

            // Playwright treats `aria-disabled` as non-actionable, but the criterion is
            // exactly that the handler is reachable and still sends nothing.
            await chip.click({ force: true });

            await page.waitForTimeout(300);

            expect(state.permintaanDokter.length).toBe(sebelum);

            await simpanGambar(page, vp, 'offline');

            await context.setOffline(false);

            await expect(page.getByTestId('offline-banner')).toHaveCount(0);
            await expect(chip).not.toHaveAttribute('aria-disabled', 'true');
            await expect(urutkan).not.toBeDisabled();

            // Re-opened and re-typed: a drawer that was closed starts with an empty
            // field, which is right - the question is not its to remember. What AC-9 is
            // about is whether the term is SENT once the connection is back, and that is
            // exactly what the poll below watches for.
            const kolomAkhir = await kolomCari(page, vp);

            await kolomAkhir.fill('Rina');
            await kolomAkhir.press('Enter');

            await expect
                .poll(
                    () =>
                        state.permintaanDokter.some(
                            (url) =>
                                new URL(url).searchParams.get('search') === 'Rina',
                        ),
                    { timeout: 10_000 },
                )
                .toBe(true);

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '1 dokter ditemukan.',
            );

            await expectNoA11yViolations(page);
        });

        test('f03-ac10-pagination', async ({ page }) => {
            const state = await bukaDirektori(page, { dokter: banyakDokter(14) });

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '14 dokter ditemukan.',
            );

            expect(
                punyaPermintaan(
                    state,
                    (params) =>
                        params.get('per_page') === '12' && params.get('page') === '1',
                ),
            ).toBe(true);

            await pilihSpesialisasi(page, vp, 'Spesialis Anak');

            await page.getByRole('button', { name: 'Halaman 2' }).click();

            await expect
                .poll(() =>
                    punyaPermintaan(
                        state,
                        (params) =>
                            params.get('page') === '2' &&
                            params.get('per_page') === '12' &&
                            params.get('spesialisasi') === 'SP.A',
                    ),
                )
                .toBe(true);

            // AC-10: focus returns to the list heading after a page change.
            await expect(page.locator('[data-slot="dokter-count"]')).toBeFocused();
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(2);

            // Past the last page: the server reports a page beyond `last_page`.
            await page.getByRole('button', { name: 'Halaman 1' }).click();
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(12);

            state.halamanKosong = true;

            // A different query key, because page 2 of the previous filter set is still
            // fresh in the React Query cache and would not refetch. The search carries
            // the specialisation with it: a term is a narrower question about the same
            // shortlist, not a new one.
            await cariDokter(page, vp, 'Contoh');

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '14 dokter ditemukan.',
            );
            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(12);

            await page.getByRole('button', { name: 'Halaman 2' }).click();

            await expect(page.getByText('Halaman ini kosong')).toBeVisible();
            await expect(page.getByText('Ada 14 dokter yang cocok.')).toBeVisible();

            await page
                .getByRole('button', { name: 'Kembali ke halaman pertama' })
                .click();

            await expect(page.locator('[data-slot="dokter-kartu"]')).toHaveCount(12);

            // The specialisation the search carried survives too - it is still the same
            // shortlist, only narrower by name.
            await expect(
                page
                    .locator('[data-slot="dokter-chip"]')
                    .filter({ hasText: 'Spesialis Anak' }),
            ).toHaveCount(1);

            await expectNoA11yViolations(page);
        });

        test('f03-ac11-kartu-ulasan', async ({ page }) => {
            await bukaDirektori(page);

            const kartu = page.locator('[data-slot="dokter-kartu"]');

            await expect(kartu).toHaveCount(12);

            // AC-11: no card lacks a name, and every eligible doctor carries the badge.
            for (let index = 0; index < 12; index += 1) {
                const tautan = kartu.nth(index).getByRole('link');

                await expect(tautan).toBeVisible();
                expect((await tautan.textContent())?.trim() ?? '').not.toBe('');
                await expect(
                    kartu.nth(index).getByText('Tersedia telemedisin'),
                ).toBeVisible();
            }

            const rina = kartu.filter({ hasText: 'dr. Rina Wulandari, Sp.A' });

            await expect(rina).toHaveCount(1);
            await expect(rina).toContainText('128 ulasan');
            await expect(rina).toContainText('12 tahun pengalaman');
            await expect(rina).toContainText(/Mulai\s+Rp\s*85\.000/);

            // The unreviewed doctor: `0` is omitted, never printed as a score.
            const bagus = kartu.filter({ hasText: 'drg. Bagus Nugroho' });

            await expect(bagus).toHaveCount(1);
            await expect(bagus).not.toContainText('ulasan');
            await expect(page.getByText('0 ulasan')).toHaveCount(0);

            await simpanGambar(page, vp, 'kartu-ulasan');

            await expectNoA11yViolations(page);
        });

        test('f03-ac12-a11y', async ({ page }) => {
            await bukaDirektori(page);

            for (const ukuran of [
                { width: 390, height: 844 },
                { width: 768, height: 1024 },
                { width: 1280, height: 900 },
            ]) {
                await page.setViewportSize(ukuran);

                await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                    'dokter ditemukan.',
                );

                // The one control that arranges the rows keeps a 44px target at every
                // width, because it is now the only control of its kind on the screen -
                // there is no sheet hiding a second copy.
                const kotakUrutkan = await page
                    .locator('[data-slot="dokter-urutkan"]')
                    .boundingBox();

                expect(kotakUrutkan?.height ?? 0).toBeGreaterThanOrEqual(44);

                await istirahatkanGerak(page);
                await expectNoA11yViolations(page);
            }

            await page.setViewportSize({ width: 390, height: 844 });

            await pilihSpesialisasi(
                page,
                { nama: 'mobile', width: 390, height: 844 },
                'Spesialis Anak',
            );

            const kotakChip = await page
                .locator('[data-slot="dokter-chip"]')
                .first()
                .boundingBox();

            expect(kotakChip?.height ?? 0).toBeGreaterThanOrEqual(44);

            await istirahatkanGerak(page);
            await expectNoA11yViolations(page);
        });

        test('f03-ac13-privasi', async ({ page }) => {
            await bukaDirektori(page);

            await cariDokter(page, vp, KATA_UJI);

            await expect(page.locator('[data-slot="dokter-count"]')).toContainText(
                '0 dokter ditemukan.',
            );

            /**
             * AC-13 asks that the QUERY stay out of the address bar and out of the tab.
             * The directory is a section of the landing page now, so its title is the
             * landing page's own - the name of the screen rather than anything the
             * visitor typed into it.
             */
            expect(await page.title()).toBe('Beranda | Sehatly');
            expect(page.url()).not.toContain(KATA_UJI);

            /**
             * And the term is not written into any label either. The controls that NAME
             * the filter they remove are the one place it is meant to be written down -
             * "Hapus filter" without its subject is a button nobody can tell apart from
             * its neighbour - and they are not shareable. Every OTHER label on the screen
             * stays silent about the question.
             */
            const label = await page.evaluate(() =>
                Array.from(document.querySelectorAll('[aria-label]'))
                    .filter(
                        (element) =>
                            !(element.getAttribute('aria-label') ?? '').startsWith(
                                'Hapus filter',
                            ),
                    )
                    .map((element) =>
                        (element.getAttribute('aria-label') ?? '').toLowerCase(),
                    ),
            );

            expect(label.some((teks) => teks.includes(KATA_UJI))).toBe(false);

            await expect(page.locator('[data-slot="dokter-chip"]')).toHaveCount(1);
            await expect(
                page.locator('[data-slot="dokter-chip"]').first(),
            ).toContainText(KATA_UJI);

            await expect(page.locator('[data-sonner-toast]')).toHaveCount(0);
            await expect(page.getByText('Tidak ada dokter yang cocok.')).toBeVisible();

            await expectNoA11yViolations(page);
        });
    });
}
