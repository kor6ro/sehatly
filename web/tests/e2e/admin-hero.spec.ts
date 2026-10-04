import { expect, test, type Page, type Route } from '@playwright/test';
import { expectNoA11yViolations } from './a11y';

/**
 * `/admin/hero` - the carousel acceptance criteria, mocked end to end.
 *
 * ## Why every request is intercepted
 *
 * The live database has no `hero_slides` row to promise: publishing a banner is an
 * operator's act, so a live spec would be asserting against whatever the last person
 * to touch the landing page left behind - including "nothing", which makes the list
 * assertions vacuous. `page.route` makes each branch deterministic: the five-slide
 * cap's 422, the empty strip and the replacement of an image are produced on demand.
 *
 * The handler is stateful - a `POST` appends to the same array the next `GET` serves -
 * so the assertions read what the UI did rather than what a second canned payload
 * claims. The catch-all at the bottom is load-bearing: an unmocked request would reach
 * the real API, answer 401, and send the transport down its session-expiry path,
 * redirecting the test to `/login` for a reason unrelated to the assertion.
 *
 * ## The session is seeded, not registered
 *
 * `RequireAuth` checks `sessionStorage` for an access token, so an init script writes a
 * pair before the first navigation. Nothing validates it - every call is mocked - which
 * is also why this file can prove the PERMISSION story the server owns only by showing
 * what an `admin` screen does with a 422 (it names the field) rather than by pretending
 * to be a patient.
 *
 * ## Both viewports
 *
 * The row's action cluster wraps on a phone and sits beside the content on a desktop;
 * the publish switch, the delete confirmation and the file input have to work at both
 * widths. The timezone is pinned so the window label is deterministic.
 */

const VIEWPORTS = [
    { nama: 'mobile', width: 390, height: 844 },
    { nama: 'desktop', width: 1280, height: 900 },
] as const;

const ADMIN = {
    id: 99,
    uuid: '00000000-0000-4000-8000-000000000099',
    nama_lengkap: 'Admin Uji Hero',
    no_telepon: '081200000099',
    email: 'admin@contoh.example',
    tipe: 'admin',
    status: 'aktif',
    bahasa: 'id',
    foto_profil: null,
    telepon_terverifikasi: true,
    email_terverifikasi: true,
    last_login_at: null,
    dibuat_at: '2026-01-01T00:00:00.000000Z',
};

const PESAN_BATAS =
    'Maksimal 5 slide boleh tayang bersamaan. Matikan satu slide yang sudah lewat masa tayangnya dulu.';

function baris(over: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id: 1,
        urutan: 0,
        eyebrow: 'Promo Oktober',
        judul: 'Diskon konsultasi 30%',
        deskripsi: 'Sepanjang Oktober, konsultasi dokter umum lebih hemat.',
        cta_label: 'Lihat Promo',
        cta_target: '/dokter',
        gambar: null,
        gambar_alt: null,
        status: 'draf',
        mulai_tayang: null,
        selesai_tayang: null,
        tayang_aktif: false,
        dibuat_at: '2026-10-01T00:00:00.000000Z',
        diubah_at: '2026-10-01T00:00:00.000000Z',
        ...over,
    };
}

function heroAwal(): Array<Record<string, unknown>> {
    return [
        baris(),
        baris({
            id: 2,
            urutan: 1,
            eyebrow: 'Janji temu',
            judul: 'Booking tanpa antre',
            deskripsi: 'Pilih dokter, pilih jam, datang tepat waktunya.',
            cta_label: 'Booking',
            cta_target: '/booking',
            status: 'tayang',
            tayang_aktif: true,
            gambar: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            gambar_alt: 'Dokter tersenyum di klinik',
        }),
        baris({
            id: 3,
            urutan: 2,
            judul: 'Kampanye yang sudah lewat',
            deskripsi: 'Window-nya sudah ditutup, jadi tayang tapi tidak tampil.',
            cta_label: 'Lihat',
            cta_target: '/konsultasi',
            status: 'tayang',
            tayang_aktif: false,
            mulai_tayang: '2026-01-01 00:00:00',
            selesai_tayang: '2026-02-01 00:00:00',
        }),
    ];
}

type Permintaan = {
    method: string;
    path: string;
    url: string;
    body: unknown;
    raw: string | null;
};

type Catatan = { permintaan: Permintaan[] };

type OpsiMock = {
    user?: Record<string, unknown>;
    hero?: Array<Record<string, unknown>>;
    /** A POST answer this status instead of creating, so the cap's 422 is reachable. */
    postStatus?: number;
    /** Skip the `POST /hero` handler entirely to prove the empty state. */
    kosong?: boolean;
};

async function balasJson(route: Route, status: number, body: unknown): Promise<void> {
    await route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

/**
 * Intercept every `/api/v1` call this screen makes.
 *
 * The multipart upload is asserted through `raw` rather than `body`:
 * `postDataJSON()` throws on a form payload, so the record keeps the bytes and the
 * assertion greps them for the alternative text that must travel WITH the file.
 */
async function pasangMock(page: Page, opsi: OpsiMock = {}): Promise<Catatan> {
    const catatan: Catatan = { permintaan: [] };

    const state = {
        user: opsi.user ?? ADMIN,
        hero: opsi.kosong ? [] : (opsi.hero ?? heroAwal()),
        berikutnya: 100,
    };

    await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;
        const method = request.method();

        let body: unknown = null;

        try {
            body = request.postDataJSON();
        } catch {
            body = null;
        }

        catatan.permintaan.push({
            method,
            path,
            url: request.url(),
            body,
            raw: request.postData(),
        });

        const sukses = (data: unknown): Promise<void> =>
            balasJson(route, 200, { success: true, message: 'Berhasil.', data });

        const gagal = (
            status: number,
            message: string,
            errors: Record<string, string[]>,
        ): Promise<void> =>
            balasJson(route, status, { success: false, message, errors });

        if (path === '/api/v1/me') {
            return sukses({ user: state.user });
        }

        if (path === '/api/v1/notifikasi') {
            return balasJson(route, 200, {
                success: true,
                message: 'Berhasil.',
                data: { notifikasi: [] },
                meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: 15,
                    total: 0,
                    from: null,
                    to: null,
                    unread: 0,
                },
            });
        }

        if (path === '/api/v1/hero' && method === 'GET') {
            return sukses({ hero: [] });
        }

        if (path === '/api/v1/admin/hero' && method === 'GET') {
            return sukses({ hero: state.hero });
        }

        if (path === '/api/v1/admin/hero' && method === 'POST') {
            if (opsi.postStatus !== undefined) {
                return gagal(opsi.postStatus, 'Data yang dikirim tidak valid.', {
                    status: [PESAN_BATAS],
                });
            }

            const isi = body as Record<string, unknown>;
            const dibuat = baris({
                id: state.berikutnya,
                urutan: state.hero.length,
                judul: isi.judul,
                deskripsi: isi.deskripsi,
                cta_label: isi.cta_label,
                cta_target: isi.cta_target,
                status: isi.status ?? 'draf',
            });

            state.berikutnya += 1;
            state.hero = [...state.hero, dibuat];

            return balasJson(route, 201, {
                success: true,
                message: 'Slide hero berhasil ditambahkan.',
                data: { hero: dibuat },
            });
        }

        const gambar = /^\/api\/v1\/admin\/hero\/(\d+)\/gambar$/.exec(path);

        if (gambar !== null) {
            const target = state.hero.find((row) => row.id === Number(gambar[1]));

            if (target === undefined) {
                return gagal(404, 'Resource not found.', {});
            }

            if (method === 'POST') {
                target.gambar = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==';
                target.gambar_alt = 'Dipasang lewat formulir';
                return sukses({ hero: target });
            }

            target.gambar = null;
            target.gambar_alt = null;
            return sukses({ hero: target });
        }

        const detail = /^\/api\/v1\/admin\/hero\/(\d+)$/.exec(path);

        if (detail !== null && method === 'PUT') {
            const target = state.hero.find((row) => row.id === Number(detail[1]));

            if (target === undefined) {
                return gagal(404, 'Resource not found.', {});
            }

            const isi = body as Record<string, unknown>;

            Object.assign(target, isi);

            if (typeof isi.status === 'string') {
                target.tayang_aktif = isi.status === 'tayang';
            }

            return sukses({ hero: target });
        }

        if (detail !== null && method === 'DELETE') {
            state.hero = state.hero.filter((row) => row.id !== Number(detail[1]));

            return balasJson(route, 200, {
                success: true,
                message: 'Slide hero berhasil dihapus.',
                data: null,
            });
        }

        return sukses({});
    });

    return catatan;
}

async function masukPalsu(page: Page): Promise<void> {
    await page.addInitScript(() => {
        sessionStorage.setItem('sehatly.access_token', 'token-uji-hero');
        sessionStorage.setItem('sehatly.refresh_token', 'refresh-uji-hero');
    });
}

for (const viewport of VIEWPORTS) {
    test.describe(`Admin carousel ${viewport.nama}`, () => {
        test.use({
            viewport: { width: viewport.width, height: viewport.height },
            timezoneId: 'Asia/Jakarta',
        });

        test('hero-admin-daftar-menampilkan-badge-status-jendela-dan-cta', async ({
            page,
        }) => {
            await masukPalsu(page);
            const catatan = await pasangMock(page);

            await page.goto('/admin/hero');

            const barisLokator = page.locator('[data-testid="admin-hero-daftar"] > li');
            await expect(barisLokator).toHaveCount(3);

            const get = catatan.permintaan.filter(
                (item) => item.path === '/api/v1/admin/hero' && item.method === 'GET',
            );
            expect(get).toHaveLength(1);

            // Draft first, then live, then live-but-out-of-window: three states the
            // operator must be able to tell apart without opening anything.
            await expect(barisLokator.first()).toContainText('Diskon konsultasi 30%');
            await expect(barisLokator.first()).toContainText('Draf');
            await expect(barisLokator.first()).toContainText('Tanpa gambar');

            await expect(barisLokator.nth(1)).toContainText('Booking tanpa antre');
            await expect(barisLokator.nth(1)).toContainText('Tayang');

            await expect(barisLokator.nth(2)).toContainText('Di luar jendela tayang');

            // The CTA target is shown as the internal path it is, because that is
            // the only shape the server accepts.
            await expect(barisLokator.first()).toContainText('/dokter');

            await expectNoA11yViolations(page);
        });

        test('hero-admin-tombol-status-mengirim-put-parsial-lalu-memperbarui-badge', async ({
            page,
        }) => {
            await masukPalsu(page);
            const catatan = await pasangMock(page);

            await page.goto('/admin/hero');

            const barisLokator = page.locator('[data-testid="admin-hero-daftar"] > li');
            await expect(barisLokator.first()).toContainText('Draf');

            await barisLokator.first().getByRole('button', { name: 'Tayangkan' }).click();

            await expect(page.locator('[data-sonner-toast]')).toContainText(
                'Slide tayang di beranda.',
            );

            await expect
                .poll(() =>
                    catatan.permintaan.filter(
                        (item) =>
                            item.path === '/api/v1/admin/hero/1' && item.method === 'PUT',
                    ).length,
                )
                .toBe(1);

            const put = catatan.permintaan.find(
                (item) => item.path === '/api/v1/admin/hero/1' && item.method === 'PUT',
            );

            // ONE field. A publish switch that resubmits the whole form would be a
            // way to lose a slide's copy by toggling it.
            expect(put?.body).toEqual({ status: 'tayang' });

            await expect(barisLokator.first()).toContainText('Tayang');
        });

        test('hero-admin-form-baru-mengirim-post-lalu-menutup-dan-membaca-ulang', async ({
            page,
        }) => {
            await masukPalsu(page);
            const catatan = await pasangMock(page);

            await page.goto('/admin/hero');

            await page.getByTestId('admin-hero-baru').click();

            await expect(page.getByRole('dialog')).toBeVisible();

            await page.getByRole('textbox', { name: 'Judul', exact: true }).fill('Slide baru');
            await page
                .getByRole('textbox', { name: 'Deskripsi', exact: true })
                .fill('Ditulis dari halaman admin.');
            await page
                .getByRole('textbox', { name: 'Label tombol', exact: true })
                .fill('Lihat Promo');
            await page
                .getByRole('textbox', { name: 'Tautan tombol', exact: true })
                .fill('/konsultasi');

            await page.getByRole('button', { name: 'Simpan slide', exact: true }).click();

            await expect(page.locator('[data-sonner-toast]')).toContainText(
                'Slide ditambahkan sebagai draf.',
            );

            const post = catatan.permintaan.find(
                (item) => item.path === '/api/v1/admin/hero' && item.method === 'POST',
            );

            expect(post?.body).toMatchObject({
                judul: 'Slide baru',
                deskripsi: 'Ditulis dari halaman admin.',
                cta_label: 'Lihat Promo',
                cta_target: '/konsultasi',
                status: 'draf',
            });

            // The dialog closes and the list is re-read, so the new row is on screen
            // without a manual refresh - the stateful mock makes the second GET's
            // answer the array the POST just wrote to.
            await expect(page.getByRole('dialog')).toHaveCount(0);
            await expect(page.locator('[data-testid="admin-hero-daftar"] > li')).toHaveCount(4);

            await expectNoA11yViolations(page);
        });

        test('hero-admin-422-batas-tayang-tertampil-di-ringkasan-dan-kolom-status', async ({
            page,
        }) => {
            await masukPalsu(page);
            await pasangMock(page, { postStatus: 422 });

            await page.goto('/admin/hero');

            await page.getByTestId('admin-hero-baru').click();

            await page.getByRole('textbox', { name: 'Judul', exact: true }).fill('Keenam');
            await page
                .getByRole('textbox', { name: 'Deskripsi', exact: true })
                .fill('Sudah penuh.');
            await page
                .getByRole('textbox', { name: 'Label tombol', exact: true })
                .fill('Lihat');
            await page
                .getByRole('textbox', { name: 'Tautan tombol', exact: true })
                .fill('/dokter');

            // Radix's trigger is a button, not a `<select>`, so `selectOption` would
            // throw: the same click-then-option dance `dokter-f03` and
            // `pembayaran-f06` use for every `FieldSelect` in this app.
            await page.getByRole('combobox', { name: 'Status' }).click();
            await page.getByRole('option', { name: 'Tayang', exact: true }).click();
            await page.getByRole('button', { name: 'Simpan slide', exact: true }).click();

            /**
             * Two instruments, and the pattern file explains why both exist: the
             * summary announces that something failed (and how many fields), and the
             * field itself says which one - on a tall form the summary alone leaves
             * the operator scrolling to find the red.
             *
             * Scoped to the dialog rather than the page: `onError` also toasts the
             * same sentence, so a bare `getByText` resolves to both and Playwright's
             * strict mode fails the assertion for being ambiguous rather than wrong.
             */
            const dialog = page.getByRole('dialog');

            await expect(dialog.getByText('1 field perlu diperbaiki')).toBeVisible();
            await expect(dialog.getByText(PESAN_BATAS)).toBeVisible();

            // The dialog does not close on a 422: nothing was saved, and closing it
            // would silently discard what the operator typed.
            await expect(dialog).toBeVisible();
        });

        test('hero-admin-unggah-gambar-membawa-teks-alternatif-dalam-satu-permintaan', async ({
            page,
        }) => {
            await masukPalsu(page);
            const catatan = await pasangMock(page);

            await page.goto('/admin/hero');

            const barisLokator = page.locator('[data-testid="admin-hero-daftar"] > li');
            await barisLokator.first().getByRole('button', { name: 'Ubah' }).click();

            const unggah = page.getByRole('button', { name: 'Unggah', exact: true });

            // Nothing to send until a file is chosen: the button's disabled state is
            // what stops an empty multipart request rather than a 422 that names it.
            await expect(unggah).toBeDisabled();

            await page
                .locator('input[type="file"]')
                .setInputFiles({
                    name: 'promo.png',
                    mimeType: 'image/png',
                    buffer: Buffer.from(
                        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                        'base64',
                    ),
                });

            await page
                .getByRole('textbox', { name: 'Teks alternatif', exact: true })
                .fill('Promo konsultasi Oktober');

            await expect(unggah).toBeEnabled();
            await unggah.click();

            await expect(page.locator('[data-sonner-toast]')).toContainText(
                'Gambar terpasang.',
            );

            await expect
                .poll(() =>
                    catatan.permintaan.filter(
                        (item) =>
                            item.path === '/api/v1/admin/hero/1/gambar' &&
                            item.method === 'POST',
                    ).length,
                )
                .toBe(1);

            const kirim = catatan.permintaan.find(
                (item) =>
                    item.path === '/api/v1/admin/hero/1/gambar' && item.method === 'POST',
            );

            // The alternative text is IN THE SAME REQUEST as the bytes: no `CHECK` in
            // the schema can couple two columns, so the upload is the one moment both
            // are present and can be refused together.
            expect(kirim?.raw ?? '').toContain('Promo konsultasi Oktober');
            expect(kirim?.raw ?? '').toContain('name="gambar_alt"');
        });

        test('hero-admin-hapus-melalui-dialog-konfirmasi-lalu-baris-hilang', async ({
            page,
        }) => {
            await masukPalsu(page);
            const catatan = await pasangMock(page);

            await page.goto('/admin/hero');

            const barisLokator = page.locator('[data-testid="admin-hero-daftar"] > li');
            await expect(barisLokator).toHaveCount(3);

            await barisLokator.first().getByRole('button', { name: 'Hapus' }).click();

            // A dialog, not `window.confirm`: the consequence (the file goes too, and
            // no audit row is written) has to be stated where the operator can read
            // it before they commit.
            const dialog = page.getByRole('dialog');
            await expect(dialog).toContainText('beserta berkas gambarnya');
            await expect(dialog).toContainText('tidak tercatat di jejak audit');

            await dialog.getByRole('button', { name: 'Hapus slide' }).click();

            await expect(page.locator('[data-sonner-toast]')).toContainText(
                'Slide dihapus, termasuk berkas gambarnya.',
            );

            await expect
                .poll(() =>
                    catatan.permintaan.filter(
                        (item) =>
                            item.path === '/api/v1/admin/hero/1' && item.method === 'DELETE',
                    ).length,
                )
                .toBe(1);

            await expect(page.locator('[data-testid="admin-hero-daftar"] > li')).toHaveCount(2);
        });
    });
}
