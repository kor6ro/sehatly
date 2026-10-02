import { expect, test, type BrowserContext, type Page } from '@playwright/test';

/**
 * Module 4's end-to-end proof, driven against a **live** Laravel API.
 *
 * ## What is real here
 *
 * Every assertion is made against a real HTTP response. There is no `page.route` stub, no
 * fixture array standing in for a server answer, no hard-coded token, no hard-coded OTP and
 * no hard-coded phone number for the patient. The patient registers through the actual
 * `POST /api/v1/auth/register` and reads the OTP out of **that response body**, which
 * `AuthController` publishes only under `APP_ENV=local`.
 *
 * The doctor and the pharmacist are **provisioned**, not registered: `POST /auth/register`
 * writes `users.tipe = 'pasien'`, and `DevFixtureSeeder` hashes `bin2hex(random_bytes(32))`
 * as each seeded doctor's password, so no seeded account can be signed into. Their two
 * credentials come from the environment.
 *
 * ```
 * php artisan serve --port=8013
 * SEHATLY_API_TARGET=http://127.0.0.1:8013 npx vite --port 5193
 * SEHATLY_BASE_URL=http://127.0.0.1:5193 ^
 *   SEHATLY_DOKTER_NO_TELEPON=... SEHATLY_DOKTER_PASSWORD=... ^
 *   SEHATLY_APOTEKER_NO_TELEPON=... SEHATLY_APOTEKER_PASSWORD=... ^
 *   npx playwright test
 * ```
 *
 * ## How a contraindication is produced WITHOUT touching the database
 *
 * The dev seed has no `obat_interaksi` row at `tingkat = 'kontraindikasi'`, and this spec
 * does not insert one. It uses the engine's own documented mapping instead:
 * `ObatInteraksiService::KEPARAHAN_TINGKAT` maps `pasien_alergi.keparahan = 'anafilaksis'`
 * onto `tingkat = 'kontraindikasi'`. So the patient records a real `anafilaksis` allergy
 * through the real `POST /api/v1/pasien/alergi`, and the doctor prescribes that drug.
 *
 * The second item is `Metformin`, which the seed pairs with `Amoxicillin` at
 * `tingkat = 'berat'` in `obat_interaksi` - so ONE prescription produces TWO of the three
 * `sumber` groups at once (`alergi` at `kontraindikasi` and `antar_item` at `berat`), and
 * the panel's third group is asserted present and empty rather than absent.
 *
 * ## The property being proved
 *
 * Not "a warning appears". The property is that **the override cannot be reached by an
 * unlabelled click and that reaching it writes a record server-side**. So the spec asserts,
 * in order: the submit is refused; the gate appears; clicking the submit again while the
 * gate is open changes nothing; ticking the box alone still changes nothing; only the box
 * AND a note together enable it; the re-submit is 201; and the stored `catatan_dokter` is
 * byte-equal to the note typed.
 */

function nomorTeleponBaru(): string {
    return '0813' + String(Date.now()).slice(-8);
}

function kredensial(nama: string): { telepon: string; sandi: string } {
    const telepon =
        nama === 'dokter'
            ? process.env.SEHATLY_DOKTER_NO_TELEPON
            : process.env.SEHATLY_APOTEKER_NO_TELEPON;
    const sandi =
        nama === 'dokter'
            ? process.env.SEHATLY_DOKTER_PASSWORD
            : process.env.SEHATLY_APOTEKER_PASSWORD;

    if (telepon === undefined || sandi === undefined) {
        throw new Error(
            `Kredensial ${nama} wajib diisi lewat environment. Akun ${nama} tidak bisa ` +
                'didaftarkan lewat API publik.',
        );
    }

    return { telepon, sandi };
}

/**
 * One call from inside the page, authenticated with the bearer the app already holds.
 *
 * `lib/token.ts` keeps it in `sessionStorage` and only the SPA's ky instance adds the header
 * in its `beforeRequest` hook, so a bare `fetch` is unauthenticated and the server answers
 * 401 - correctly. Reading the token here rather than minting a second one also asserts that
 * the session the app holds is the one being used.
 */
async function statusPanggil(
    page: Page,
    path: string,
    init: { method?: string; body?: unknown } = {},
): Promise<number> {
    return page.evaluate(
        async ([url, options]) => {
            const accessToken = globalThis.sessionStorage.getItem(
                'sehatly.access_token',
            );

            const response = await fetch(url as string, {
                method: (options as { method?: string }).method ?? 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(accessToken === null
                        ? {}
                        : { Authorization: `Bearer ${accessToken}` }),
                },
                ...((options as { body?: unknown }).body === undefined
                    ? {}
                    : { body: JSON.stringify((options as { body?: unknown }).body) }),
            });

            await response.text();

            return response.status;
        },
        [
            path,
            {
                ...(init.method === undefined ? {} : { method: init.method }),
                ...(init.body === undefined ? {} : { body: init.body }),
            },
        ],
    );
}

async function daftarDanMasuk(page: Page): Promise<void> {
    const telepon = nomorTeleponBaru();

    await page.goto('/register');

    await expect(page.getByLabel('Nama lengkap')).toBeVisible({ timeout: 60_000 });

    await page.getByLabel('Nama lengkap').fill('Pasien E2E Resep');
    await page.getByLabel('Nomor telepon').fill(telepon);
    await page.getByLabel('Kata sandi').fill('RahasiaKuat123');
    await page.getByLabel('Tanggal lahir').fill('1991-04-02');
    await page.getByLabel('Alamat lengkap').fill('Jl. Uji Resep No. 9, Jakarta');
    // Register requires the two UU PDP consents since commit `aacb7e8`.
    await page.getByRole('checkbox', { name: /Saya menyetujui/ }).check();

    const [respons] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/api/v1/auth/register')),
        page.getByRole('button', { name: /Daftar/ }).click(),
    ]);

    expect(respons.status()).toBe(201);

    await expect(page).toHaveURL(/\/otp/, { timeout: 30_000 });

    const body = (await respons.json()) as {
        data: { otp: { kode: string | null } };
    };

    expect(body.data.otp.kode, 'OTP harus terbit pada APP_ENV=local').not.toBeNull();

    await page
        .locator('form input[inputmode="numeric"]')
        .fill(body.data.otp.kode as string);
    await page.getByRole('button', { name: 'Verifikasi' }).click();

    await expect(page).toHaveURL(/\/dashboard/, { timeout: 30_000 });
}

/**
 * Sign in through the real login screen, OTP included.
 *
 * `AuthController::login` answers `{otp}` and **no token**; `POST /auth/otp/verify` is the
 * only endpoint in that controller that mints one.
 */
async function masuk(page: Page, siapa: 'dokter' | 'apoteker'): Promise<void> {
    const { telepon, sandi } = kredensial(siapa);

    await page.goto('/login');

    await expect(page.getByLabel('Nomor telepon')).toBeVisible({
        timeout: 60_000,
    });

    await page.getByLabel('Nomor telepon').fill(telepon);
    await page.getByLabel('Kata sandi').fill(sandi);

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

    await expect(page).toHaveURL(/\/dashboard/, { timeout: 30_000 });
}

async function dokterIdDokter(page: Page): Promise<number> {
    const [respons] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/api/v1/me')),
        page.goto('/dashboard'),
    ]);

    expect(respons.status()).toBe(200);

    const body = (await respons.json()) as {
        data: { user: { dokter?: { id: number } | null } };
    };

    const id = body.data.user.dokter?.id;

    if (id === undefined || id === null) {
        throw new Error('Akun yang masuk tidak punya baris dokter.');
    }

    return id;
}

/**
 * Start a consultation as the patient, through the real endpoint.
 *
 * `POST /konsultasi/mulai` with `dokter_id` + `tipe` and no `booking_id` is the second of
 * the endpoint's two forms, and it works because `konsultasi.booking_id` is `NULL UNIQUE`.
 */
async function mulaiKonsultasi(page: Page, dokterId: number): Promise<number> {
    const [respons] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/api/v1/konsultasi/mulai')),
        page.evaluate(async ([id]) => {
            const accessToken = globalThis.sessionStorage.getItem(
                'sehatly.access_token',
            );

            const response = await fetch('/api/v1/konsultasi/mulai', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(accessToken === null
                        ? {}
                        : { Authorization: `Bearer ${accessToken}` }),
                },
                body: JSON.stringify({ dokter_id: Number(id), tipe: 'chat' }),
            });

            await response.text();
        }, [String(dokterId)]),
    ]);

    expect(respons.status(), 'POST /konsultasi/mulai harus 201').toBe(201);

    const body = (await respons.json()) as {
        data: { konsultasi: { id: number } };
    };

    return body.data.konsultasi.id;
}

/**
 * Accept the consultation, as its doctor, through the real endpoint.
 *
 * Load-bearing: `KonsultasiService::selesai()` refuses to write onto a consultation that was
 * never accepted, and `ResepService::buat()` calls `KonsultasiAccess::untukDokter()`. Without
 * this the prescription write cannot succeed at all.
 */
async function terimaKonsultasi(page: Page, konsultasiId: number): Promise<void> {
    const [respons] = await Promise.all([
        page.waitForResponse((r) =>
            r.url().includes(`/api/v1/konsultasi/${konsultasiId}/terima`),
        ),
        page.evaluate(async ([id]) => {
            const accessToken = globalThis.sessionStorage.getItem(
                'sehatly.access_token',
            );

            const response = await fetch(`/api/v1/konsultasi/${id}/terima`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(accessToken === null
                        ? {}
                        : { Authorization: `Bearer ${accessToken}` }),
                },
            });

            await response.text();
        }, [String(konsultasiId)]),
    ]);

    expect(
        respons.status(),
        'PUT /konsultasi/{id}/terima harus 200, kalau tidak resep pasti 422',
    ).toBe(200);
}

/** The network log, restricted to this feature's paths. */
function jejakNetwork(page: Page): string[] {
    const jejak: string[] = [];

    page.on('response', (r) => {
        const url = new URL(r.url());

        if (url.pathname.startsWith('/api/')) {
            jejak.push(
                `${r.request().method()} ${r.status()} ${url.pathname}${url.search}`,
            );
        }
    });

    return jejak;
}

/**
 * How many POSTs reached `POST /konsultasi/{id}/resep`.
 *
 * This is the network-log counterpart of the disabled-button assertion: a click that the
 * gate refuses must not produce a request, and "the button is disabled" alone would not
 * notice a handler that fired anyway.
 */
function jejakPostResep(jejak: string[], konsultasiId: number): string[] {
    return jejak.filter(
        (baris) =>
            baris.startsWith('POST ') &&
            baris.includes(`/api/v1/konsultasi/${konsultasiId}/resep`),
    );
}

/**
 * How many catalogue queries reached `GET /api/v1/obat`.
 *
 * The counterpart to {@link jejakPostResep} for the search box, and the only place the
 * debounce is observable at all: `DEBOUNCE_MS` and `MINIMAL_KARAKTER` are internal to the
 * component, so the network log is the honest evidence that a keystroke did NOT become a
 * request.
 */
function jejakObat(jejak: string[]): string[] {
    return jejak.filter(
        (baris) => baris.startsWith('GET ') && baris.includes('/api/v1/obat'),
    );
}

test.describe.configure({ mode: 'serial' });

test.describe('Module 4 prescription warn-then-override', () => {
    test('a contraindication blocks the commit until it is acknowledged, and the acknowledgement is stored', async ({
        browser,
    }) => {
        test.setTimeout(300_000);

        const pasienCtx: BrowserContext = await browser.newContext();
        const dokterCtx: BrowserContext = await browser.newContext();

        const pasien = await pasienCtx.newPage();
        const dokter = await dokterCtx.newPage();

        /**
         * Held as two arrays and joined on demand, NOT spread once.
         *
         * `[...a, ...b]` copies the strings present at that moment; the `response` handler
         * keeps pushing into `a` and `b` afterwards, so a spread snapshot stays empty for
         * the whole run. It did: the log printed nothing and every "nothing was sent"
         * assertion compared 0 with 0.
         */
        const jejakPasien = jejakNetwork(pasien);
        const jejakDokter = jejakNetwork(dokter);
        const jejak = (): string[] => [...jejakPasien, ...jejakDokter];

        await daftarDanMasuk(pasien);
        await masuk(dokter, 'dokter');

        // --- the patient records a REAL anaphylaxis allergy, through the real endpoint --
        /**
         * `ObatInteraksiService::KEPARAHAN_TINGKAT` maps `anafilaksis` onto
         * `tingkat = 'kontraindikasi'`, which is the documented reason this works with no
         * seeded `obat_interaksi` row and no direct database write.
         */
        const statusAlergi = await statusPanggil(pasien, '/api/v1/pasien/alergi', {
            method: 'POST',
            body: {
                tipe_alergen: 'obat',
                nama_alergen: 'Amoxicillin',
                reaksi: 'Syok anafilaktik',
                keparahan: 'anafilaksis',
            },
        });

        expect(
            statusAlergi,
            'POST /pasien/alergi harus 201 untuk memicu kontraindikasi',
        ).toBe(201);

        const dokterId = await dokterIdDokter(dokter);
        const konsultasiId = await mulaiKonsultasi(pasien, dokterId);

        await terimaKonsultasi(dokter, konsultasiId);

        // --- the doctor composes: two drugs, one of them the allergen ---------------
        await dokter.goto(`/konsultasi/${konsultasiId}/resep`);

        await expect(
            dokter.locator('[data-slot="obat-autocomplete"]'),
        ).toBeVisible({ timeout: 30_000 });

        // --- the search box does not become a request per keystroke -------------------
        /**
         * Both halves of the box's cost control, measured on the wire.
         *
         * `MINIMAL_KARAKTER = 2` means one character issues NO query at all - the component
         * leaves the query `enabled: false`, so the request is never made rather than made
         * and discarded. Then the remaining characters go in at 50 ms each, which is well
         * inside the 300 ms debounce, so every keystroke RESTARTS the timer and only the
         * final value is ever sent. One burst of ten characters, one request.
         *
         * Without these two assertions the debounce is an implementation detail nobody could
         * tell had been removed, and a catalogue query per keystroke is exactly the cost
         * `staleTime: 60_000` exists to avoid.
         */
        await dokter.getByLabel('Cari obat').pressSequentially('A', { delay: 0 });
        await dokter.waitForTimeout(900);

        expect(
            jejakObat(jejak()).length,
            'satu karakter tidak boleh memicu query katalog',
        ).toBe(0);

        await dokter
            .getByLabel('Cari obat')
            .pressSequentially('moxicillin', { delay: 50 });

        await dokter
            .locator('[data-slot="obat-autocomplete-pilihan"][data-obat-id="2"]')
            .waitFor({ timeout: 20_000 });

        await dokter.waitForTimeout(900);

        expect(
            jejakObat(jejak()).length,
            'sepuluh karakter dalam waktu debounce harus menghasilkan SATU query',
        ).toBe(1);

        // The FULL generic name, not a prefix: `ObatSearchService` matches on
        // `NamaObat::inti()` EQUALITY, never containment. Evidence file, finding 5.2.
        await dokter
            .locator('[data-slot="obat-autocomplete-pilihan"][data-obat-id="2"]')
            .click();

        await dokter.getByLabel('Cari obat').fill('Metformin');
        await dokter
            .locator('[data-slot="obat-autocomplete-pilihan"][data-obat-id="5"]')
            .click();

        await expect(
            dokter.locator('[data-slot="resep-item"]'),
        ).toHaveCount(2, { timeout: 20_000 });

        // --- the first submit is REFUSED, and that refusal is the warning ------------
        const [ditolak] = await Promise.all([
            dokter.waitForResponse(
                (r) =>
                    r.url().includes(
                        `/api/v1/konsultasi/${konsultasiId}/resep`,
                    ) && r.request().method() === 'POST',
            ),
            dokter.getByRole('button', { name: 'Simpan resep' }).click(),
        ]);

        expect(
            ditolak.status(),
            'tanpa catatan pengakuan, server wajib 422 pada catatan_dodio',
        ).toBe(422);

        const badan422 = (await ditolak.json()) as {
            success: boolean;
            errors: Record<string, string[]>;
        };

        expect(badan422.success).toBe(false);
        expect(
            Object.keys(badan422.errors),
            'penolakan harus pada catatan_dodio',
        ).toContain('catatan_dodio');
        expect(badan422.errors.catatan_dodio?.length ?? 0).toBeGreaterThan(0);

        // --- the gate is open, and it is NOT dismissible ----------------------------
        const gate = dokter.locator('[data-slot="resep-override-gate"]');

        await expect(gate).toBeVisible({ timeout: 20_000 });
        await expect(gate).toHaveAttribute('data-terbuka', 'true');

        /**
         * THE ASSERTION THIS FILE EXISTS FOR.
         *
         * The submit is disabled, so a click on it - labelled or not - cannot commit. And the
         * gate has no close control at all: no button, no dialog, no escape. The count below
         * is the proof that there is nothing to click.
         */
        const submit = dokter.getByRole('button', { name: 'Kirim dengan pengakuan' });

        await expect(submit).toBeDisabled();

        const gateButtons = await gate.getByRole('button').count();

        expect(
            gateButtons,
            'gate tidak boleh punya tombol apa pun yang bisa menutupnya',
        ).toBe(0);

        // Clicking it anyway must change nothing at all - not even a request.
        const sebelumKlik = jejakPostResep(jejak(), konsultasiId).length;

        await submit.click({ force: true }).catch(() => {
            // A disabled button refuses the click; the assertion is that nothing followed.
        });

        await dokter.waitForTimeout(500);

        const sesudahKlik = jejakPostResep(jejak(), konsultasiId).length;

        expect(
            sesudahKlik,
            'klik pada tombol yang terkunci tidak boleh menambah permintaan',
        ).toBe(sebelumKlik);

        const checkbox = dokter.locator('[data-slot="resep-override-checkbox"]');

        const note = 'Needed despite the recorded anaphylaxis; patient informed and monitored.';

        // --- a NOTE ALONE is not enough either --------------------------------------
        /**
         * One condition at a time, and the order matters: the checkbox is still UNTICKED
         * here, so a request here would prove the note alone opened the gate.
         */
        await dokter.getByLabel('Catatan pengakuan (wajib diisi)').fill(note);

        await submit.click({ force: true }).catch(() => {
            // Deliberate: the guard is re-checked inside the handler, so a forced click on a
            // disabled button must be inert even when the browser dispatches the event.
        });

        await dokter.waitForTimeout(750);

        expect(
            jejakPostResep(jejak(), konsultasiId).length,
            'catatan tanpa pengakuan tidak boleh mengirim',
        ).toBe(sesudahKlik);

        // --- the CHECKBOX ALONE is not enough ----------------------------------------
        await dokter.getByLabel('Catatan pengakuan (wajib diisi)').fill('');

        await checkbox.click();

        await expect(
            submit,
            'centang tanpa catatan belum cukup',
        ).toBeDisabled();

        await submit.click({ force: true }).catch(() => {
            // Ditto: one condition is present and must still not be enough.
        });

        await dokter.waitForTimeout(750);

        expect(
            jejakPostResep(jejak(), konsultasiId).length,
            'pengakuan tanpa catatan tidak boleh mengirim',
        ).toBe(sesudahKlik);

        await dokter.screenshot({
            path: 'playwright-report/resep-override-gate.png',
            fullPage: true,
        });

        // --- BOTH together, and only now may it be committed ------------------------
        await dokter.getByLabel('Catatan pengakuan (wajib diisi)').fill(note);

        // --- BOTH together: now it may be committed ---------------------------------
        await expect(submit, 'centang DAN catatan harus membuka gerbang').toBeEnabled();

        const [dibuat] = await Promise.all([
            dokter.waitForResponse(
                (r) =>
                    r.url().includes(
                        `/api/v1/konsultasi/${konsultasiId}/resep`,
                    ) && r.request().method() === 'POST',
            ),
            submit.click(),
        ]);

        expect(dibuat.status(), 'dengan pengakuan, resep harus 201').toBe(201);

        const dibuatBody = (await dibuat.json()) as {
            data: {
                resep: {
                    id: number;
                    nomor_resep: string;
                    catatan_dokter: string | null;
                    items: { nama_obat: string }[];
                };
                warning: {
                    sumber: string;
                    tingkat: string;
                    wajib_catatan_dokter: boolean;
                }[];
                warning_grup: Record<string, unknown[]>;
                acknowledgement: {
                    diminta: boolean;
                    catatan_dodio: string | null;
                    jumlah_peringatan: number;
                };
            };
        };

        // --- the override is PERSISTED server-side, byte-for-byte -------------------
        expect(
            dibuatBody.data.acknowledgement.diminta,
            'server harus menyatakan pengakuan memang diminta',
        ).toBe(true);

        expect(
            dibuatBody.data.resep.catatan_dokter,
            'catatan yang diketik dokter harus tersimpan sebagai catatan_dokter',
        ).toBe(note);

        expect(dibuatBody.data.acknowledgement.catatan_dodio).toBe(note);

        // --- and the warnings really came back, from BOTH sources --------------------
        const sumber = dibuatBody.data.warning.map((w) => w.sumber).sort();

        expect(sumber, 'harus ada minimal dua sumber peringatan').toContain('alergi');
        expect(sumber).toContain('antar_item');

        const kontra = dibuatBody.data.warning.filter(
            (w) => w.tingkat === 'kontraindikasi',
        );

        expect(kontra.length, 'anafilaksis harus menjadi kontraindikasi').toBeGreaterThan(
            0,
        );

        expect(
            kontra.every((w) => w.wajib_catatan_dokter),
            'setiap kontraindikasi harus menandai wajibnya catatan',
        ).toBe(true);

        // --- the panel renders THREE visually distinct groups, empty ones included --
        const panel = dokter.locator('[data-slot="warning-panel"]');

        await expect(panel).toBeVisible({ timeout: 20_000 });
        await expect(
            dokter.locator('[data-slot="warning-grup"]'),
        ).toHaveCount(3);

        for (const sumberKey of ['antar_item', 'riwayat_resep', 'alergi']) {
            await expect(
                dokter.locator(
                    `[data-slot="warning-grup"][data-sumber="${sumberKey}"]`,
                ),
                `grup ${sumberKey} harus dirender`,
            ).toBeVisible();
        }

        await expect(
            dokter.locator(
                '[data-slot="warning-grup"][data-sumber="riwayat_resep"][data-jumlah="0"]',
            ),
            'sumber tanpa temuan harus tetap dirender, dengan jumlah nol',
        ).toBeVisible();

        const gaya = await dokter
            .locator('[data-slot="warning-grup"]')
            .evaluateAll((els) =>
                els.map((e) => ({
                    sumber: e.getAttribute('data-sumber'),
                    gaya: e.getAttribute('data-gaya'),
                    kelas: e.className,
                })),
            );

        expect(
            new Set(gaya.map((g) => g.gaya)).size,
            'tiga grup harus punya tiga perlakuan visual berbeda',
        ).toBe(3);

        await dokter.screenshot({
            path: 'playwright-report/resep-override-tercatat.png',
            fullPage: true,
        });

        // --- the pharmacist reads the same record ------------------------------------
        const apotekerCtx: BrowserContext = await browser.newContext();
        const apoteker = await apotekerCtx.newPage();

        const jejakApotek = jejakNetwork(apoteker);

        await masuk(apoteker, 'apoteker');
        await apoteker.goto('/apotek/resep');

        await expect(
            apoteker.locator('[data-slot="apotek-id-resep"]'),
        ).toBeVisible({ timeout: 30_000 });

        await apoteker
            .locator('[data-slot="apotek-id-resep"]')
            .fill(String(dibuatBody.data.resep.id));
        await apoteker.getByRole('button', { name: 'Buka' }).click();

        await expect(
            apoteker.locator('[data-slot="verifikasi-form"]'),
        ).toBeVisible({ timeout: 30_000 });

        /**
         * The doctor's acknowledgement is visible to the pharmacist. That is the whole point
         * of it living in `resep.catatan_dokter`: there is no other place in the schema where
         * the decision could have been recorded.
         */
        await expect(
            apoteker.locator('[data-slot="resep-catatan-dokter"]'),
        ).toContainText('anaphylaxis');

        /**
         * `ResepVerifikasiService::pastikanCatatan()` refuses `sesuai` with an empty note
         * while a contraindication is present, and never refuses `ditolak`. The button
         * therefore stays shut for the advancing outcome until the pharmacist writes their
         * own acknowledgement.
         */
        const kirim = apoteker.getByRole('button', { name: 'Kirim verifikasi' });

        await apoteker
            .locator('[data-slot="verifikasi-pilih"][data-status="sesuai"]')
            .click();

        await expect(
            kirim,
            'sesuai + kontraindikasi tanpa catatan harus terkunci',
        ).toBeDisabled();

        await expect(
            apoteker.locator('[data-slot="verifikasi-perlu-catatan"]'),
        ).toBeVisible();

        await apoteker.screenshot({
            path: 'playwright-report/apotek-antrean.png',
            fullPage: true,
        });

        await apoteker
            .locator('[data-slot="verifikasi-catatan"]')
            .fill('Dispensed with a warning label; patient counselled on anaphylaxis.');

        await expect(kirim, 'catatan apoteker harus membuka tombol').toBeEnabled();

        const [verifikasi] = await Promise.all([
            apoteker.waitForResponse((r) =>
                r.url().includes(
                    `/api/v1/resep/${dibuatBody.data.resep.id}/verifikasi`,
                ),
            ),
            kirim.click(),
        ]);

        expect(verifikasi.status(), 'verifikasi harus 201').toBe(201);

        const verifikasiBody = (await verifikasi.json()) as {
            data: { verifikasi: { status: string; catatan: string | null } };
        };

        expect(verifikasiBody.data.verifikasi.status).toBe('sesuai');
        expect(verifikasiBody.data.verifikasi.catatan).toContain('anaphylaxis');

        await apoteker.screenshot({
            path: 'playwright-report/apotek-terverifikasi.png',
            fullPage: true,
        });

        // --- the network log, printed for the evidence file -------------------------
        console.log(
            'CATALOGUE_QUERIES_T41\n' + jejakObat(jejak()).join('\n'),
        );

        console.log(
            'NETWORK_LOG_T41\n' + [...jejak(), ...jejakApotek].join('\n'),
        );

        await pasienCtx.close();
        await dokterCtx.close();
        await apotekerCtx.close();
    });
});
