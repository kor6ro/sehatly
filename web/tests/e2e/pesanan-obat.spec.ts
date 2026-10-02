import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

/**
 * Module 5's end-to-end walkthrough, driven against a **live** Laravel API.
 *
 * ## What is real here
 *
 * Every assertion is made against a real HTTP response. No `page.route` stub, no fixture
 * array standing in for a server answer, no hard-coded token, no hard-coded OTP. The
 * patient signs in through the real login screen and reads the OTP out of THAT response,
 * which `AuthController` publishes only under `APP_ENV=local`.
 *
 * ```
 * php artisan serve --port=8013
 * SEHATLY_API_TARGET=http://127.0.0.1:8013 npx vite --port 5193
 * SEHATLY_BASE_URL=http://localhost:5193 ^
 *   SEHATLY_PASIEN_NO_TELEPON=... SEHATLY_PASIEN_PASSWORD=... ^
 *   SEHATLY_PASIEN_RESEP_ID=... npx playwright test
 * ```
 *
 * ## The two places this spec reads the DATABASE, and why it has to
 *
 * **The invoice id.** `POST /resep/{id}/checkout` does not publish `invoice_id` and there is
 * no `GET /invoice/{id}`, so the id that `POST /invoice/{id}/bayar` needs is not obtainable
 * from any response the order flow produces. That is a real backend gap, reported in
 * `.omo/evidence/task-48-sehatly.md`. The spec reads it out of band rather than the client
 * inventing one, which is what a screen honestly has to do today.
 *
 * **The webhook HMAC secret.** `POST /webhook/payment/{gateway}` is unauthenticated and
 * HMAC-signed, so only a gateway may call it. The spec computes the signature the way
 * `MockPaymentGatewayService::verifyWebhook()` does - `hash_hmac('sha256', rawBody, secret)`
 * over the exact bytes - so the delivery is genuine. The browser never attempts it.
 */
const PHP =
    process.env.SEHATLY_PHP ??
    'C:\\laragon\\bin\\php\\php-8.4.17-nts-Win32-vs17-x64\\php.exe';

// `web/package.json` declares `"type": "module"`, so `__dirname` does not exist. The same
// `fileURLToPath(import.meta.url)` idiom `vite.config.ts` already uses is the way to get one.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const ROOT_POSIX = ROOT.replace(/\\/g, '/');

const BOOT =
    `require '${ROOT_POSIX}/vendor/autoload.php';` +
    `$a = require '${ROOT_POSIX}/bootstrap/app.php';` +
    `$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();`;

/** Run one PHP expression inside the booted application and return its stdout. */
function php(code: string): string {
    return execFileSync(PHP, ['-r', BOOT + code], {
        cwd: ROOT,
        encoding: 'utf8',
    }).trim();
}

function wajib(nama: string): string {
    const nilai = process.env[nama];

    if (nilai === undefined || nilai === '') {
        throw new Error(
            `${nama} wajib diisi. Akun apoteker/dokter tidak bisa didaftarkan lewat API ` +
                'publik karena POST /auth/register meng-hard-code tipe = pasien, dan ' +
                'DevFixtureSeeder memakai hash sandi acak yang tidak bisa dipakai.',
        );
    }

    return nilai;
}

/**
 * Fill a control and PROVE the value survived.
 *
 * The login screen re-renders its identifier control on mount, so a `fill` issued the
 * instant the field becomes visible can land on a node that is about to be replaced. The
 * symptom is a form that submits empty and a test that hangs on the response it will never
 * get. Verifying the value turns that into an immediate, legible failure, and retrying
 * makes it a non-failure at all.
 */
async function isi(locator: import('@playwright/test').Locator, nilai: string): Promise<void> {
    for (let percobaan = 0; percobaan < 3; percobaan += 1) {
        await locator.fill(nilai);
        await locator.press('Tab');

        if ((await locator.inputValue()) === nilai) {
            return;
        }
    }

    await locator.fill(nilai);

    await expect(locator, `kontrol tidak menerima nilai "${nilai}"`).toHaveValue(nilai);
}

/** Sign in through the real login screen, OTP included. */
async function masuk(page: Page): Promise<void> {
    const telepon = wajib('SEHATLY_PASIEN_NO_TELEPON');
    const sandi = wajib('SEHATLY_PASIEN_PASSWORD');

    await page.goto('/login');

    await expect(page.getByLabel('Nomor telepon')).toBeVisible({
        timeout: 60_000,
    });

    // Settle the mode toggle first: it is what re-renders the identifier control.
    await page.getByRole('button', { name: 'Telepon', exact: true }).click();

    await isi(page.getByLabel('Nomor telepon'), telepon);
    await isi(page.getByLabel('Kata sandi'), sandi);

    const [respons] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/api/v1/auth/login'), {
            timeout: 60_000,
        }),
        page.getByRole('button', { name: 'Lanjutkan' }).click(),
    ]);

    expect(respons.status()).toBe(200);
    await expect(page).toHaveURL(/\/otp/, { timeout: 30_000 });

    const body = (await respons.json()) as { data: { otp: { kode: string | null } } };

    expect(body.data.otp.kode, 'OTP login harus terbit pada APP_ENV=local').not.toBeNull();

    await isi(page.locator('form input[inputmode="numeric"]'), body.data.otp.kode as string);
    await page.getByRole('button', { name: 'Verifikasi' }).click();

    await expect(page).toHaveURL(/\/dashboard/, { timeout: 30_000 });
}

/**
 * F02's mandatory gate stands in front of the payment start, so the seeded patient must
 * have the three required consents recorded before this spec can pay. This does what the
 * screen does after the gate: read the ACTIVE version from `GET /pdp/dokumen` and post
 * each decision with it. The write is idempotent by contract, so 200 and 201 both mean
 * "recorded" and a re-run of this spec does not fail on its own history.
 */
async function catatConsentWajib(page: Page): Promise<void> {
    const token = await page.evaluate(() => sessionStorage.getItem('sehatly.access_token'));

    expect(token, 'sesi harus punya access token setelah verifikasi').not.toBeNull();

    const headers = { Authorization: `Bearer ${token as string}` };

    const dokumen = await page.request.get('/api/v1/pdp/dokumen', { headers });

    expect(dokumen.status(), 'GET /pdp/dokumen harus 200').toBe(200);

    const katalog = (await dokumen.json()) as {
        data: { dokumen: Array<{ jenis: string; versi_dokumen: string }> };
    };

    const versi = new Map(
        katalog.data.dokumen.map((baris) => [baris.jenis, baris.versi_dokumen]),
    );

    for (const jenis of [
        'syarat_ketentuan',
        'kebijakan_privasi',
        'berbagi_data_medis',
    ]) {
        const respons = await page.request.post('/api/v1/pdp/persetujuan', {
            headers,
            data: { jenis, versi_dokumen: versi.get(jenis), disetujui: true },
        });

        expect(
            [200, 201],
            `consent ${jenis} harus tercatat, diterima ${respons.status()}`,
        ).toContain(respons.status());
    }
}

/**
 * The network log, restricted to this feature's paths.
 *
 * Held as ONE array and read on demand, never spread at subscribe time: the `response`
 * handler keeps pushing into it for the whole run, so a snapshot would be empty and every
 * "nothing was sent" assertion would compare 0 with 0. That mistake is recorded in
 * `.omo/evidence/task-41-sehatly.md` and is why this shape is kept.
 */
function jejakNetwork(page: Page): string[] {
    const jejak: string[] = [];

    page.on('response', (r) => {
        const url = new URL(r.url());

        if (url.pathname.startsWith('/api/')) {
            jejak.push(`${r.request().method()} ${r.status()} ${url.pathname}${url.search}`);
        }
    });

    return jejak;
}

function hitung(jejak: string[], predicate: (baris: string) => boolean): number {
    return jejak.filter(predicate).length;
}

/**
 * Deliver one signed webhook event, exactly as `MockPaymentGatewayService` verifies it.
 *
 * The raw body is serialised ONCE and the signature is computed over those bytes, because
 * `verifyWebhook()` signs `$request->getContent()` - a re-serialised body would not be
 * byte-identical and the endpoint would answer 401. That is the same hazard the server's
 * own test asserts.
 */
async function kirimWebhook(
    baseURL: string,
    body: Record<string, unknown>,
): Promise<{ status: number; body: Record<string, unknown> }> {
    const secret = php(
        `echo config('services.payment.gateways.midtrans.webhook_secret');`,
    );

    const raw = JSON.stringify(body);
    const signature = php(
        `echo hash_hmac('sha256', base64_decode('${Buffer.from(raw).toString('base64')}', true), '${secret}');`,
    );

    const respons = await fetch(`${baseURL}/api/v1/webhook/payment/midtrans`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Payment-Signature': signature,
        },
        body: raw,
    });

    return { status: respons.status, body: (await respons.json()) as Record<string, unknown> };
}

/** Every leaf that differs between two decoded JSON bodies, as dotted paths. */
function beda(a: unknown, b: unknown, jalur = ''): string[] {
    if (JSON.stringify(a) === JSON.stringify(b)) {
        return [];
    }

    if (
        typeof a !== 'object' ||
        typeof b !== 'object' ||
        a === null ||
        b === null ||
        Array.isArray(a) ||
        Array.isArray(b)
    ) {
        return [jalur];
    }

    const kunci = new Set([
        ...Object.keys(a as Record<string, unknown>),
        ...Object.keys(b as Record<string, unknown>),
    ]);

    return [...kunci]
        .flatMap((k) =>
            beda(
                (a as Record<string, unknown>)[k],
                (b as Record<string, unknown>)[k],
                jalur === '' ? k : `${jalur}.${k}`,
            ),
        )
        .sort();
}

/**
 * Pick the stocked pharmacy on the checkout form and prove the control reflects it.
 *
 * Two things here are not incidental. The click is `force`d because `SelectContent` animates
 * in with a `zoom-in` transform, so the option's bounding box is never stable long enough
 * for Playwright's actionability gate and an unforced click loops until it reports the node
 * detached. And it is issued IMMEDIATELY after opening: a `waitForTimeout` there lets the
 * popup close, and the click then waits for an option that is no longer in the document.
 *
 * The combobox-text assertion is the real check on the choice AND the one that exposed the
 * bug where `FieldSelect` rendered its placeholder string instead of `SelectValue`, so the
 * trigger kept saying "Pilih apotek" after a selection that had in fact succeeded.
 */
async function pilihApotek(page: Page): Promise<void> {
    const pemilih = page.getByRole('combobox', { name: 'Apotek' });

    await pemilih.click();
    await page
        .locator('[data-slot="select-content"]')
        .getByRole('option', { name: /Apotek Utama/ })
        .click({ force: true });

    await expect(pemilih, 'apotek terpilih harus tampil di kontrol').toHaveText(/Apotek Utama/, {
        timeout: 15_000,
    });
}

test.describe.configure({ mode: 'serial' });

test.describe('Module 5 checkout, payment, tracking and notifications', () => {
    test('a stock-checked checkout, a server-computed discount, a settled payment that survives a webhook retry, and a notification centre reading real rows', async ({
        browser,
        baseURL,
    }) => {
        // 240 s, not the 420 s a first draft used: every wait below carries its own timeout,
        // so a hang here means a step with none, and a 7-minute silence per attempt is how a
        // broken selector gets mistaken for a slow server.
        test.setTimeout(240_000);

        const ctx = await browser.newContext();
        const page = await ctx.newPage();

        const jejak = jejakNetwork(page);
        const resepId = Number(wajib('SEHATLY_PASIEN_RESEP_ID'));

        await masuk(page);
        await catatConsentWajib(page);

        // === 1. CHECKOUT: stock is read before anything is committed ====================
        await page.goto(`/checkout/${resepId}`);

        await expect(page.locator('[data-slot="checkout-form"]')).toBeVisible({
            timeout: 40_000,
        });

        // Two lines, each stock-checked once. `GET /obat/{id}/stok` with NO `apotek_id`
        // returns `apotek: null` and `alternatif` as the candidate pharmacy list.
        await expect
            .poll(
                () =>
                    hitung(
                        jejak,
                        (b) =>
                            b.startsWith('GET ') &&
                            b.includes('/api/v1/obat/') &&
                            b.includes('/stok'),
                    ),
                { timeout: 40_000 },
            )
            .toBeGreaterThanOrEqual(2);

        // With no pharmacy chosen the submit is shut: stock is not judged yet.
        await expect(
            page.locator('[data-slot="checkout-submit"]'),
            'submit harus terkunci sebelum apotek dipilih',
        ).toBeDisabled();

        // The list is the INTERSECTION of the per-drug alternatives. Apotek Cadangan has
        // zero Amoxicillin and Apotek Kosong has none at all, so neither is offered.
        // Clicked by ROLE, not by the `[data-slot]` wrapper: that wrapper spans the label,
        // the trigger and the hint, so its centre is not necessarily on the button.
        const pemilih = page.getByRole('combobox', { name: 'Apotek' });

        await expect(pemilih, 'pemilih apotek harus ada setelah stok terbaca').toBeVisible({
            timeout: 30_000,
        });

        // The dropdown is opened only once the shelf reads have SETTLED. Radix rebuilds
        // `SelectContent` when its children change, so opening it while a stock query is
        // still in flight detaches the option mid-click and Playwright reports the click as
        // "element was not stable". Waiting for the network to go quiet first removes the
        // race instead of retrying against it.
        await page.waitForLoadState('networkidle');

        await pemilih.click();
        await expect(page.getByRole('option', { name: /Apotek Utama/ })).toBeVisible();
        await expect(
            page.getByRole('option', { name: /Apotek Cadangan/ }),
            'apotek tanpa stok cukup tidak boleh ditawarkan',
        ).toHaveCount(0);
        await expect(
            page.getByRole('option', { name: /Apotek Kosong/ }),
            'apotek tanpa stok cukup tidak boleh ditawarkan',
        ).toHaveCount(0);

        await page.screenshot({
            path: 'playwright-report/pesanan-01-pilih-apotek.png',
            fullPage: true,
        });

        // The stock assertions below are the real check on WHICH pharmacy was chosen, so
        // the choice itself is only about getting it made.
        await pilihApotek(page);

        // Now the shelf is read WITH `apotek_id`, and the server's own `cukup` opens the gate.
        await expect
            .poll(
                () => hitung(jejak, (b) => b.startsWith('GET ') && b.includes('apotek_id=')),
                { timeout: 40_000 },
            )
            .toBeGreaterThanOrEqual(2);

        const stokBaris = page.locator('[data-slot="checkout-stok"]');

        await expect(stokBaris).toHaveCount(2);

        for (let i = 0; i < 2; i += 1) {
            await expect(stokBaris.nth(i)).toHaveAttribute('data-state', 'cukup');

            // The COUNT is whatever the server says, not a literal. An earlier draft
            // asserted "stok 50" and failed at 49 - because a previous run's checkout had
            // really decremented the shelf. That failure was the stock guard working, and
            // it is exactly why the assertion is a pattern: the property under test is
            // "the number on screen is the server's number", not "the number is 50".
            await expect(stokBaris.nth(i)).toContainText(/stok \d+ untuk 1 diminta/);
        }

        // The only money on this screen is the sum of the server-published line subtotals.
        await expect(page.locator('[data-slot="checkout-subtotal"]')).toHaveText('Rp 13.800');
        await expect(page.locator('[data-slot="checkout-submit"]')).toBeEnabled();

        await page.screenshot({
            path: 'playwright-report/pesanan-02-checkout-siap.png',
            fullPage: true,
        });

        // === 2. THE WRITE, and exactly one of them =====================================
        const [dibuat] = await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes(`/api/v1/resep/${resepId}/checkout`) &&
                    r.request().method() === 'POST',
            ),
            page.locator('[data-slot="checkout-submit"]').click(),
        ]);

        expect(dibuat.status(), 'checkout harus 201').toBe(201);

        const dibuatBody = (await dibuat.json()) as {
            data: { pesanan: Record<string, unknown> };
        };

        const pesanan = dibuatBody.data.pesanan;
        const pesananId = pesanan.id as number;

        expect(pesanan.status).toBe('menunggu_pembayaran');
        expect(pesanan.subtotal).toBe('13800.00');
        expect(pesanan.total).toBe('13800.00');

        // `tracking` is ABSENT on the 201, not null: `PesananObatResource` guards it with
        // `whenLoaded()` and `buat()` never eager-loads the relation. Asserted because a
        // client that read it unconditionally would crash on this very response.
        expect(
            pesanan.tracking,
            'tracking harus tidak ada pada 201, bukan null',
        ).toBeUndefined();

        // === 3. TRACKING: the trail, with pesanan.status as the authority ==============
        await expect(page).toHaveURL(new RegExp(`/pesanan/${pesananId}$`), {
            timeout: 30_000,
        });
        await expect(page.locator('[data-slot="tracking-status"]')).toHaveText(
            'Menunggu pembayaran',
        );
        await expect(page.locator('[data-slot="tracking-row"]')).toHaveCount(1);

        await page.screenshot({
            path: 'playwright-report/pesanan-03-tracking.png',
            fullPage: true,
        });

        // === 4. FINDING: A SECOND CHECKOUT OF THE SAME PRESCRIPTION IS NOT REFUSED =======
        // This step asserts the CURRENT, DEFECTIVE behaviour on purpose. An earlier draft
        // expected 422 here and the server answered 201, so the truth is recorded here
        // rather than papered over with a client-side guard.
        //
        // `InvoiceService::tolakDuplikat()` cannot catch this, and the reason is structural:
        // checkout creates a NEW `pesanan_obat` row and then calls
        // `InvoiceService::buat('pesenan_obat', $pesanan->id, ...)`, so the pair the guard
        // checks, `(referensi_tipe, referensi_id) = ('pesanan_obat', <the order just
        // created>)`, is unique BY CONSTRUCTION on every call. The guard is correct for its
        // own contract and provides no idempotency for checkout.
        //
        // `pesanan_obat.resep_id` is nullable with no FK and no unique index either, and
        // the prescription status is never advanced when an order exists, so the submit
        // stays available indefinitely.
        //
        // The consequence is a real double-charge risk: two orders, two invoices, and the
        // pharmacy shelf decremented twice for one prescription. Measured below, from the
        // database, not inferred.
        await page.goto(`/checkout/${resepId}`);
        await expect(page.locator('[data-slot="checkout-form"]')).toBeVisible({
            timeout: 40_000,
        });

        await pilihApotek(page);
        await expect(page.locator('[data-slot="checkout-submit"]')).toBeEnabled({
            timeout: 30_000,
        });

        const [duplikat] = await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes(`/api/v1/resep/${resepId}/checkout`) &&
                    r.request().method() === 'POST',
                { timeout: 60_000 },
            ),
            page.locator('[data-slot="checkout-submit"]').click(),
        ]);

        expect(
            duplikat.status(),
            'DEFECT: checkout kedua untuk resep yang sama DITERIMA 201. ' +
                'InvoiceService::tolakDuplikat() memeriksa (pesanan_obat, id pesanan yang ' +
                'baru dibuat) sehingga tidak pernah menangkap duplikat ini.',
        ).toBe(201);

        const duplikatBody = (await duplikat.json()) as {
            data: { pesanan: { id: number } };
        };

        const pesananKedua = duplikatBody.data.pesanan.id;

        expect(
            pesananKedua,
            'dua pesanan harus berarti dua id pesanan yang berbeda',
        ).not.toBe(pesananId);

        // Hard evidence from the database, because "the API said 201" alone would not
        // distinguish two orders from one order reported twice.
        const duaPesanan = JSON.parse(
            php(
                `echo json_encode([` +
                    `'pesanan' => Illuminate\\Support\\Facades\\DB::table('pesanan_obat')` +
                    `->where('resep_id', ${resepId})->count(),` +
                    `'invoice' => Illuminate\\Support\\Facades\\DB::table('invoice')` +
                    `->whereIn('referensi_id', Illuminate\\Support\\Facades\\DB::table('pesanan_obat')` +
                    `->where('resep_id', ${resepId})->pluck('id'))->count(),` +
                    `]);`,
            ),
        ) as Record<string, number>;

        expect(
            duaPesanan.pesanan,
            'DEFECT TERBUKTI: satu resep menghasilkan lebih dari satu pesanan',
        ).toBe(2);
        expect(
            duaPesanan.invoice,
            'DEFECT TERBUKTI: satu resep menghasilkan lebih dari satu invoice',
        ).toBe(2);

        await page.screenshot({
            path: 'playwright-report/pesanan-04-duplikat-diterima.png',
            fullPage: true,
        });

        // === 5. PAYMENT =================================================================
        const invoiceId = Number(
            php(
                `echo (int) Illuminate\\Support\\Facades\\DB::table('invoice')` +
                    `->where('referensi_tipe', 'pesanan_obat')` +
                    `->where('referensi_id', ${pesananId})->value('id');`,
            ),
        );

        expect(invoiceId, 'invoice untuk pesanan ini harus ada').toBeGreaterThan(0);

        await page.goto(`/pembayaran/${pesananId}`);

        await expect(page.locator('[data-slot="pembayaran-menunggu"]')).toBeVisible({
            timeout: 40_000,
        });
        await expect(page.locator('[data-slot="pesanan-status"]')).toHaveText(
            'Menunggu pembayaran',
        );

        // The promo input states why it cannot run before an invoice is known.
        await expect(page.locator('[data-slot="promo-tidak-bisa"]')).toBeVisible();

        await page.locator('[data-slot="payment-invoice-id"]').fill(String(invoiceId));

        // All NINE `tipe` values are grouped, not the five the plan's prose names.
        await page.getByRole('combobox', { name: 'Metode pembayaran' }).click();
        await expect(
            page.locator('[data-slot="metode-grup"][data-tipe="va_bank"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-slot="metode-grup"][data-tipe="bpjs"]'),
            'sembilan tipe metode harus dikelompokkan, bukan lima',
        ).toBeVisible();

        await page.screenshot({
            path: 'playwright-report/pesanan-04-metode.png',
            fullPage: true,
        });

        // Same keyboard interaction for the method list, for the same reason. The group
        // assertions above have already proved what is on offer.
        await page.getByRole('combobox', { name: 'Metode pembayaran' }).focus();
        await page.getByRole('combobox', { name: 'Metode pembayaran' }).press('Enter');
        await page
            .locator('[data-slot="select-content"]')
            .locator('[data-slot="metode-pilihan"][data-tipe="va_bank"]')
            .first()
            .click({ force: true });

        await expect(
            page.getByRole('combobox', { name: 'Metode pembayaran' }),
        ).toHaveText(/Virtual Account/);

        const [mulai] = await Promise.all([
            page.waitForResponse(
                (r) => r.url().includes('/bayar') && r.request().method() === 'POST',
            ),
            page.locator('[data-slot="payment-submit"]').click(),
        ]);

        expect(mulai.status(), 'payment initiation harus 201').toBe(201);
        await expect(page.locator('[data-slot="payment-va"]')).not.toBeEmpty();
        await expect(page.locator('[data-slot="payment-status"]')).toHaveText(
            'Menunggu konfirmasi gateway',
        );

        const mulaiBody = (await mulai.json()) as {
            data: {
                pembayaran: { id: number; nomor_referensi: string; jumlah: string };
                gateway: { nama: string };
            };
        };

        const referensi = mulaiBody.data.pembayaran.nomor_referensi;
        const jumlah = mulaiBody.data.pembayaran.jumlah;

        expect(mulaiBody.data.gateway.nama).toBe('midtrans');

        await page.screenshot({
            path: 'playwright-report/pesanan-05-instruksi.png',
            fullPage: true,
        });

        // === 6. THE PROMO, and the proof it came from the server =======================
        // HEMAT10 is 10% with `maks_diskon` 1000. The subtotal is 13800, so 10% would be
        // 1380 and the server caps it at 1000. A client multiplying the percentage itself
        // would render 1380. The rendered number is therefore a discriminator.
        await page.locator('[data-slot="promo-kode"]').fill('HEMAT10');
        await page.locator('[data-slot="promo-cek"]').click();

        const valid = page.locator('[data-slot="promo-hasil"][data-valid="true"]');

        await expect(valid).toBeVisible({ timeout: 30_000 });
        await expect(page.locator('[data-slot="promo-diskon"]')).toHaveText('Rp 1.000');
        await expect(page.locator('[data-slot="promo-total"]')).toHaveText('Rp 12.800');

        await page.screenshot({
            path: 'playwright-report/pesanan-06-promo-valid.png',
            fullPage: true,
        });

        // The refusal is a 200 with `valid: false`, and it names the field it failed on.
        const [expired] = await Promise.all([
            page.waitForResponse(
                (r) =>
                    r.url().includes('/api/v1/promo/validasi') && r.request().method() === 'POST',
            ),
            (async () => {
                await page.locator('[data-slot="promo-kode"]').fill('KADALUARSA');
                await page.locator('[data-slot="promo-cek"]').click();
            })(),
        ]);

        expect(
            expired.status(),
            'promo kedaluwarsa dijawab 200 dengan valid false, bukan 422',
        ).toBe(200);

        const invalid = page.locator('[data-slot="promo-hasil"][data-valid="false"]');

        await expect(invalid).toBeVisible();
        await expect(
            invalid.locator('[data-slot="promo-alasan"][data-kolom="jendela_waktu"]'),
        ).toContainText('Promo sudah berakhir');

        await page.screenshot({
            path: 'playwright-report/pesanan-07-promo-kadaluwarsa.png',
            fullPage: true,
        });

        // A count taken BEFORE any webhook delivery, so the retry can be shown to change
        // nothing rather than merely to leave the state looking plausible afterwards.
        const pesananSebelum = JSON.parse(
            php(
                `echo Illuminate\\Support\\Facades\\DB::table('pesanan_obat')` +
                    `->where('pasien_id', ` +
                    `Illuminate\\Support\\Facades\\DB::table('invoice')` +
                    `->where('id', ${invoiceId})->value('pasien_id'))->count();`,
            ),
        ) as number;

        // === 7. THE WEBHOOK, DELIVERED TWICE ===========================================
        const payload = {
            gateway: 'midtrans',
            nomor_referensi: referensi,
            status: 'berhasil',
            jumlah,
        };

        const pertama = await kirimWebhook(baseURL as string, payload);

        expect(pertama.status, 'kiriman pertama harus 200').toBe(200);
        expect((pertama.body.data as Record<string, unknown>).duplicate).toBe(false);

        const pembayaranSatu = (pertama.body.data as { pembayaran: Record<string, unknown> })
            .pembayaran;
        const invoiceSatu = (pertama.body.data as { invoice: Record<string, unknown> }).invoice;
        const referensiSatu = (pertama.body.data as { referensi: Record<string, unknown> })
            .referensi;

        expect(pembayaranSatu.status).toBe('berhasil');
        expect(pembayaranSatu.terminal).toBe(true);
        expect(invoiceSatu.status).toBe('lunas');
        expect(invoiceSatu.lunas_at).not.toBeNull();
        expect(referensiSatu.tipe).toBe('pesanan_obat');
        expect(referensiSatu.status).toBe('diproses');
        expect(referensiSatu.advanced).toBe(true);

        // THE ASSERTION THIS FILE EXISTS FOR. The same event, delivered again with
        // DIFFERENT bytes, must change nothing at all. Diffing the two decoded bodies and
        // requiring the difference set to be EXACTLY two named keys is stronger than
        // asserting a list of equalities, which could miss a difference nobody checked.
        const kedua = await kirimWebhook(baseURL as string, {
            ...payload,
            catatan: 'kiriman kedua, byte berbeda',
            percobaan: 2,
        });

        expect(kedua.status, 'kiriman kedua harus 200, bukan error').toBe(200);
        expect((kedua.body.data as Record<string, unknown>).duplicate).toBe(true);

        const pembayaranDua = (kedua.body.data as { pembayaran: Record<string, unknown> })
            .pembayaran;
        const invoiceDua = (kedua.body.data as { invoice: Record<string, unknown> }).invoice;

        expect(kedua.body.data.pembayaran).toEqual(pertama.body.data.pembayaran);
        expect(kedua.body.data.invoice).toEqual(pertama.body.data.invoice);
        expect(kedua.body.data.referensi).toEqual(pertama.body.data.referensi);
        expect(invoiceDua.lunas_at, 'lunas_at tidak boleh berpindah').toBe(
            invoiceSatu.lunas_at,
        );
        expect(pembayaranDua.dibayar_at, 'dibayar_at tidak boleh berpindah').toBe(
            pembayaranSatu.dibayar_at,
        );

        expect(
            beda(pertama.body, kedua.body),
            'hanya duplicate dan message yang boleh berbeda',
        ).toEqual(['data.duplicate', 'message']);

        // And the database agrees: still exactly one payment for this reference, and the
        // order count is UNCHANGED by the retry - which is the property that matters, so it
        // is measured against a count taken before the first delivery rather than against a
        // literal. The FIRST delivery's payload also survived.
        const setelahDb = JSON.parse(
            php(
                `echo json_encode([` +
                    `'pembayaran' => Illuminate\\Support\\Facades\\DB::table('pembayaran')` +
                    `->where('nomor_referensi', '${referensi}')->count(),` +
                    `'status' => Illuminate\\Support\\Facades\\DB::table('pembayaran')` +
                    `->where('nomor_referensi', '${referensi}')->value('status'),` +
                    `'lunas' => Illuminate\\Support\\Facades\\DB::table('invoice')` +
                    `->where('id', ${invoiceId})->value('status'),` +
                    `'pesanan' => Illuminate\\Support\\Facades\\DB::table('pesanan_obat')` +
                    `->where('pasien_id', ` +
                    `Illuminate\\Support\\Facades\\DB::table('invoice')` +
                    `->where('id', ${invoiceId})->value('pasien_id'))->count(),` +
                    `'payload' => Illuminate\\Support\\Facades\\DB::table('pembayaran')` +
                    `->where('nomor_referensi', '${referensi}')->value('webhook_payload'),` +
                    `]);`,
            ),
        ) as Record<string, unknown>;

        expect(setelahDb.pembayaran, 'satu payment, tidak boleh dua').toBe(1);
        expect(setelahDb.status).toBe('berhasil');
        expect(setelahDb.lunas).toBe('lunas');
        expect(
            setelahDb.pesanan,
            'kiriman ulang webhook tidak boleh menambah pesanan',
        ).toBe(pesananSebelum);


        // The retry carried `percobaan: 2` and a `catatan`. Neither may be in the stored
        // payload, because `terimaWebhook()` returns from the terminal-status branch BEFORE
        // any assignment, so the FIRST delivery's body is what survives.
        const payloadTersimpan = JSON.parse(String(setelahDb.payload)) as Record<
            string,
            unknown
        >;

        expect(
            Object.hasOwn(payloadTersimpan, 'percobaan'),
            'kiriman kedua tidak boleh menimpa webhook_payload',
        ).toBe(false);
        expect(
            Object.hasOwn(payloadTersimpan, 'catatan'),
            'kiriman kedua tidak boleh menimpa webhook_payload',
        ).toBe(false);
        expect(payloadTersimpan.nomor_referensi).toBe(referensi);
        expect(payloadTersimpan.status).toBe('berhasil');

        // === 8. THE SCREEN REFLECTS IT =================================================
        // The page polls `GET /pesanan-obat/{id}` every 15s. `pesanan_obat.status` moving
        // off `menunggu_pembayaran` IS the settlement signal, because the webhook is
        // unreachable from a browser. No local flag is involved.
        await expect(
            page.locator('[data-slot="pesanan-sudah-terbayar"]'),
            ' polled order harus menunjukkan settlement yang dicatat server',
        ).toBeVisible({ timeout: 60_000 });
        await expect(page.locator('[data-slot="pesanan-status"]')).toHaveText(
            'Diproses apotek',
        );

        await page.screenshot({
            path: 'playwright-report/pesanan-08-sudah-terbayar.png',
            fullPage: true,
        });

        // === 9. THE NOTIFICATION CENTRE ================================================
        // No producer is wired: `NotificationService`'s docblock records that no service
        // calls it. The three rows below are provisioned fixtures, so the badge, mark-one
        // and mark-all are proven against real rows and the real endpoints.
        await page.goto('/notifikasi');

        await expect(page.locator('[data-slot="notifikasi-unread"]')).toHaveText('2');
        await expect(page.locator('[data-slot="notifikasi-item"]')).toHaveCount(3);
        await expect(
            page.locator('[data-slot="notifikasi-item"][data-dibaca="false"]'),
        ).toHaveCount(2);
        await expect(
            page.locator('[data-slot="notifikasi-item"][data-dibaca="true"]'),
        ).toHaveCount(1);

        // The badge in the shell reads the SAME `meta.unread`, not a page total.
        await expect(page.locator('[data-slot="notifikasi-badge"]')).toHaveText('2');

        await page.screenshot({
            path: 'playwright-report/pesanan-09-notifikasi.png',
            fullPage: true,
        });

        // The unread filter is the STRING "true", and it must not hide read rows by
        // accident: `?unread=false` means read-only, so "unread" is asserted as a subset.
        await page.locator('[data-slot="notifikasi-filter-unread"]').click();
        await expect(page.locator('[data-slot="notifikasi-item"]')).toHaveCount(2);

        await page.locator('[data-slot="notifikasi-filter"]').click();
        await expect(page.locator('[data-slot="notifikasi-item"]')).toHaveCount(3);

        // Mark one read, and the badge must follow the server rather than a local guess.
        await page.locator('[data-slot="notifikasi-item"][data-dibaca="false"]').first()
            .locator('[data-slot="notifikasi-baca"]')
            .click();

        await expect(page.locator('[data-slot="notifikasi-badge"]')).toHaveText('1', {
            timeout: 30_000,
        });

        // Mark all read, and the count really is zero afterwards.
        await page.locator('[data-slot="notifikasi-baca-semua"]').click();
        await expect(page.locator('[data-slot="notifikasi-unread"]')).toHaveText('0', {
            timeout: 30_000,
        });
        await expect(
            page.locator('[data-slot="notifikasi-item"][data-dibaca="false"]'),
        ).toHaveCount(0);

        await page.screenshot({
            path: 'playwright-report/pesanan-10-notifikasi-dibaca.png',
            fullPage: true,
        });

        console.log('NETWORK_LOG_T48\n' + jejak.join('\n'));
        console.log(
            'WEBHOOK_T48\n' +
                JSON.stringify(
                    {
                        referensi,
                        invoice_id: invoiceId,
                        pesanan_id: pesananId,
                        pertama: {
                            duplicate: (pertama.body.data as Record<string, unknown>).duplicate,
                            lunas_at: invoiceSatu.lunas_at,
                            pembayaran: pembayaranSatu.status,
                            referensi: referensiSatu,
                        },
                        kedua: {
                            duplicate: (kedua.body.data as Record<string, unknown>).duplicate,
                            lunas_at: invoiceDua.lunas_at,
                            pembayaran: pembayaranDua.status,
                        },
                        beda: beda(pertama.body, kedua.body),
                        database: setelahDb,
                    },
                    null,
                    2,
                ),
        );

        await ctx.close();
    });
});
