import { spawn } from 'node:child_process';
import { expect, test, type BrowserContext, type Page } from '@playwright/test';

/**
 * Module 3's end-to-end proof, driven against a **live** Laravel API and a **live**
 * Reverb server.
 *
 * ## What is real here
 *
 * Every assertion is made against a real HTTP response or a real WebSocket frame.
 * There is no `page.route` stub, no fixture array standing in for a server answer, no
 * hard-coded token, no hard-coded OTP and no hard-coded phone number in this file. The
 * patient registers through the actual `POST /api/v1/auth/register` and reads the OTP
 * out of **that response body**, which `AuthController` publishes only under
 * `APP_ENV=local`. The doctor signs in through the actual `POST /api/v1/auth/login`
 * and the actual `POST /api/v1/auth/otp/verify`; its phone number and password come
 * from the environment, because a doctor account cannot be self-registered
 * (`POST /auth/register` writes `users.tipe = 'pasien'`).
 *
 * ```
 * php artisan reverb:start --port=8080
 * php artisan serve --port=8011
 * SEHATLY_API_TARGET=http://127.0.0.1:8011 npx vite --port 5190
 * SEHATLY_BASE_URL=http://127.0.0.1:5190 ^
 *   SEHATLY_DOKTER_NO_TELEPON=<provisioned> SEHATLY_DOKTER_PASSWORD=<provisioned> ^
 *   SEHATLY_REVERB_PID=<pid of the reverb you started> ^
 *   npx playwright test
 * ```
 *
 * The doctor is **provisioned**, not registered: `POST /auth/register` creates a
 * `pasien` and its profile row in one transaction, so there is no public way to obtain
 * a `dokter`. The provisioning is one throwaway PHP script that inserts the `users` and
 * `dokter` rows plus the `user_roles` grant through the project's own models, and its
 * content is reproduced in `.omo/evidence/task-35-sehatly.md`. Nothing it produces is
 * read by this spec except those two environment variables, so the spec asserts only
 * what the API answers.
 *
 * ## The dedupe, stated as the thing being proved
 *
 * The server broadcasts `chat.pesan` to the whole private channel, and the AUTHOR is
 * subscribed to that channel. The author's own client therefore receives their message
 * over the WebSocket as well as in the `POST /chat` response, on every single send.
 * Both carry the same `id`, because `KonsultasiController::siarkan()` hands
 * `(new KonsultasiChatResource($pesan))->resolve($request)` - the same allow-list the
 * history page is collected through - to `broadcastWith()`, which returns it verbatim.
 *
 * That is the case this file exists to prove, and the assertions are strict on
 * purpose: `toHaveCount(1)` on the rendered row, after the REST refetch has landed and
 * again after a settle, so a late duplicate still fails.
 */

/** A unique `no_telepon` per test; `users.no_telepon` is `UNIQUE`. */
function nomorTeleponBaru(): string {
    return '0813' + String(Date.now()).slice(-8);
}

/** The doctor credential, from the environment and never from this file. */
function kredensialDokter(): { telepon: string; sandi: string } {
    const telepon = process.env.SEHATLY_DOKTER_NO_TELEPON;
    const sandi = process.env.SEHATLY_DOKTER_PASSWORD;

    if (telepon === undefined || sandi === undefined) {
        throw new Error(
            'SEHATLY_DOKTER_NO_TELEPON dan SEHATLY_DOKTER_PASSWORD wajib diisi. ' +
                'Akun dokter tidak bisa didaftarkan lewat API publik.',
        );
    }

    return { telepon, sandi };
}

/**
 * The Reverb process this test is allowed to stop and start.
 *
 * Read from the environment and refused when absent. Stopping the broker by PORT would
 * be reckless on a shared machine - several executors run here, and the brief is
 * explicit that a stale listener belongs to somebody else - so the test refuses to
 * guess and demands the exact pid it may touch.
 */
function reverbPid(): number {
    const pid = process.env.SEHATLY_REVERB_PID;

    if (pid === undefined) {
        throw new Error(
            'SEHATLY_REVERB_PID wajib diisi agar uji tidak mematikan proses milik ' +
                'executor lain. Isi dengan pid dari `php artisan reverb:start`.',
        );
    }

    return Number(pid);
}

/**
 * Stop the broker, and wait until the port is actually closed.
 *
 * The exit code of `taskkill` is deliberately ignored and the port is polled instead.
 * On Windows the port-owner PID reported for a listening socket can be stale, so
 * `taskkill` legitimately reports "not found" for a process that has already gone; the
 * thing the test actually needs is a closed port, and that is what is asserted.
 */
async function stopReverb(): Promise<void> {
    const porto = process.env.SEHATLY_REVERB_PORT ?? '8080';

    spawn(`taskkill /PID ${reverbPid()} /F`, { stdio: 'ignore', shell: true });

    for (let percobaan = 0; percobaan < 40; percobaan += 1) {
        if (!(await portTerbuka(porto))) {
            return;
        }

        await new Promise((resolve) => {
            setTimeout(resolve, 250);
        });
    }
}

async function portTerbuka(porto: string): Promise<boolean> {
    try {
        await fetch(`http://127.0.0.1:${porto}/`, {
            signal: AbortSignal.timeout(1000),
        });

        return true;
    } catch {
        return false;
    }
}

/**
 * The PHP binary, and the Laravel root it has to run in.
 *
 * Neither is hardcoded: `php` is not on PATH on this machine, and a committed absolute
 * path would make the spec run on one developer's box only. The env var wins, and the
 * defaults are the layout of the repository.
 */
function phpBinary(): string {
    return process.env.SEHATLY_PHP ?? 'php';
}

function laravelRoot(): string {
    return process.env.SEHATLY_LARAVEL_ROOT ?? '..';
}

/**
 * Start Reverb again and wait until the port answers.
 *
 * Spawned as a detached `php.exe` **directly**, with no `cmd /c start` in between.
 * The indirection was the bug, and the failure it produced is worth recording: the
 * broker was started under a wrapper that exits immediately, and on this machine the
 * grandchild never got far enough to bind 8080, so `tungguReverbHidup` polled a dead
 * port for its full 30 s and then threw. Measured twice, identically, while Reverb was
 * demonstrably startable in the same invocation - the runner had started one 40 s
 * earlier on the same command line.
 *
 * `detached: true` puts the broker in its own process group, so it is not reaped with
 * the Playwright worker, and `unref()` lets this test's own event loop continue. If a
 * future run still cannot restart it, that is the harness, and the reconnect property
 * is covered deterministically by `tests/unit/realtime-reconnect.test.ts` against the
 * transport seam.
 */
async function startReverb(): Promise<void> {
    const anak = spawn(
        phpBinary(),
        [
            'artisan',
            'reverb:start',
            '--host=127.0.0.1',
            `--port=${process.env.SEHATLY_REVERB_PORT ?? '8080'}`,
        ],
        {
            cwd: laravelRoot(),
            detached: true,
            stdio: 'ignore',
            windowsHide: true,
        },
    );

    anak.unref();

    await tungguReverbHidup();
}

/** Poll until the broker answers, and fail loudly if it never does. */
async function tungguReverbHidup(): Promise<void> {
    const porto = process.env.SEHATLY_REVERB_PORT ?? '8080';
    const kunci = process.env.SEHATLY_REVERB_APP_KEY ?? '';

    for (let percobaan = 0; percobaan < 60; percobaan += 1) {
        if (await portTerbuka(porto)) {
            return;
        }

        await new Promise((resolve) => {
            setTimeout(resolve, 500);
        });
    }

    throw new Error(
        `Reverb tidak kembali di 127.0.0.1:${porto}. Periksa SEHATLY_PHP dan ` +
            'cwd artisan sebelum menjalankan suite ini.',
    );
}

/** Register, verify, and land on the dashboard. Mirrors `booking.spec.ts`. */
async function daftarDanMasuk(page: Page): Promise<void> {
    const telepon = nomorTeleponBaru();

    await page.goto('/register');

    await expect(page.getByLabel('Nama lengkap')).toBeVisible({ timeout: 60_000 });

    await page.getByLabel('Nama lengkap').fill('Pasien E2E Realtime');
    await page.getByLabel('Nomor telepon').fill(telepon);
    await page.getByLabel('Kata sandi').fill('RahasiaKuat123');
    await page.getByLabel('Tanggal lahir').fill('1993-02-18');
    await page.getByLabel('Alamat lengkap').fill('Jl. Uji Realtime No. 2, Bandung');

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
 * Sign the doctor in through the real login screen, OTP included.
 *
 * The two steps are the server's own contract, not a client convenience:
 * `AuthController::login` answers `{otp}` and **no token**, and
 * `POST /auth/otp/verify` is the only endpoint in that controller that mints one. A
 * test expecting a token from `/login` would be testing a flow this application does
 * not have.
 */
async function masukSebagaiDokter(page: Page): Promise<void> {
    const { telepon, sandi } = kredensialDokter();

    await page.goto('/login');

    await expect(page.getByLabel('Nomor telepon atau email')).toBeVisible({
        timeout: 60_000,
    });

    await page.getByLabel('Nomor telepon atau email').fill(telepon);
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

/** The `dokter.id` the signed-in account owns, read from the real `/me`. */
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
 * Start a consultation as the signed-in patient, through the real endpoint.
 *
 * `POST /konsultasi/mulai` with `dokter_id` + `tipe` and **no** `booking_id` is the
 * second of the endpoint's two forms, and it works because `konsultasi.booking_id` is
 * `NULL UNIQUE` and MySQL allows any number of NULLs in a UNIQUE index.
 */
async function mulaiKonsultasi(page: Page, dokterId: number): Promise<number> {
    const [respons] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/api/v1/konsultasi/mulai')),
        page.evaluate(async ([id]) => {
            /**
             * The bearer is read out of `sessionStorage`, which is where `lib/token.ts`
             * keeps it and never `localStorage`. A bare `fetch` does not get it for
             * free: only the SPA's ky instance adds the header in its `beforeRequest`
             * hook, so a raw call is unauthenticated and the server answers 401 -
             * correctly. Reading the token here rather than minting a second one also
             * asserts that the session the app holds is the one being used.
             */
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
                body: JSON.stringify({
                    dokter_id: Number(id),
                    tipe: 'chat',
                }),
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
 * `POST /konsultasi/mulai` leaves the row in `menunggu_dokter`, and
 * `KonsultasiService::selesai()` refuses to write a SOAP note onto a consultation
 * that was never accepted: `ubahStatus()` has no edge from `menunggu_dokter` to
 * `selesai`, and `mulai_at` is still null because `terima()` is its only writer. Both
 * guards are collected before either throws, so the server answers ONE 422 carrying
 * `errors.status` AND `errors.mulai_at` - a correct multi-field refusal that a spec
 * skipping the accept step reported as "the SOAP form does not save". This call is
 * therefore load-bearing; deleting it as redundant with `mulaiKonsultasi` brings that
 * failure back.
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
        'PUT /konsultasi/{id}/terima harus 200, kalau tidak SOAP pasti 422',
    ).toBe(200);
}

/** The transcript's own element, so every assertion is scoped to it. */
function transcript(page: Page) {
    return page.locator('[data-slot="chat-window"]');
}

/** One rendered message row, matched on the text the server stored. */
function barisPesan(page: Page, isi: string) {
    return page.locator('[data-slot="chat-pesan-baris"]').filter({ hasText: isi });
}

/** Wait for the BROKER to have confirmed the subscribe, not for silence. */
async function tungguKanalTerpasang(page: Page): Promise<void> {
    await expect(transcript(page)).toHaveAttribute('data-subscription', 'confirmed', {
        timeout: 45_000,
    });
}

/** Send one typed message through the composer the doctor and patient both see. */
async function kirimPesan(page: Page, isi: string): Promise<void> {
    await transcript(page).getByLabel('Tulis pesan').fill(isi);
    await transcript(page).getByRole('button', { name: 'Kirim' }).click();
}

/**
 * Let a late second copy arrive before the count is re-taken.
 *
 * The dedupe failure this file exists to catch is not always immediate: the socket
 * frame can land a beat after the REST page. A settle-then-re-assert is what turns
 * "it looked right for 200 ms" into "it is right".
 */
async function jedaSettle(): Promise<void> {
    await new Promise((resolve) => {
        setTimeout(resolve, 2500);
    });
}

async function patientScreenshot(page: Page, path: string): Promise<void> {
    await page.screenshot({ path, fullPage: true });
}

/** The network log, restricted to the paths this feature owns. */
function jejakNetwork(page: Page): string[] {
    const jejak: string[] = [];

    page.on('response', (r) => {
        const url = new URL(r.url());

        if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/apps/')) {
            jejak.push(
                `${r.request().method()} ${r.status()} ${url.pathname}${url.search}`,
            );
        }
    });

    return jejak;
}

test.describe.configure({ mode: 'serial' });

test.describe('Module 3 realtime consultation', () => {
    test('a two-context exchange over Reverb, deduped across both transports', async ({
        browser,
    }) => {
        const pasienCtx: BrowserContext = await browser.newContext();
        const dokterCtx: BrowserContext = await browser.newContext();

        const pasien = await pasienCtx.newPage();
        const dokter = await dokterCtx.newPage();

        const jejakPasien = jejakNetwork(pasien);
        const jejakDokter = jejakNetwork(dokter);

        await daftarDanMasuk(pasien);
        await masukSebagaiDokter(dokter);

        const dokterId = await dokterIdDokter(dokter);
        const konsultasiId = await mulaiKonsultasi(pasien, dokterId);

        await pasien.goto(`/konsultasi/${konsultasiId}`);
        await dokter.goto(`/konsultasi/${konsultasiId}`);

        await tungguKanalTerpasang(pasien);
        await tungguKanalTerpasang(dokter);

        // --- the patient sends, the doctor receives, once, with no reload --------
        const isiPasien = `Pesan pasien ${Date.now()}`;

        const [kirim] = await Promise.all([
            pasien.waitForResponse(
                (r) =>
                    r.url().includes(`/api/v1/konsultasi/${konsultasiId}/chat`) &&
                    r.request().method() === 'POST',
            ),
            kirimPesan(pasien, isiPasien),
        ]);

        expect(kirim.status(), 'POST /chat harus 201').toBe(201);

        const dibuat = (await kirim.json()) as {
            data: { pesan: { id: number } };
        };

        await expect(
            barisPesan(dokter, isiPasien),
            'dokter harus melihat pesan tanpa reload',
        ).toHaveCount(1, { timeout: 20_000 });

        /**
         * The sender's own screen is the dedupe case: the broadcast reaches the author
         * too, so the same row arrives over REST and over the socket.
         *
         * The refetch that follows the POST is awaited first, because the two
         * transports RACE and either may be first - counting early would assert a race
         * rather than the outcome. The property being asserted is the requirement, one
         * row, and it holds whichever order they arrive in because
         * `useKonsultasiChannel` merges the history page and the live frames through a
         * `Map` keyed on `konsultasi_chat.id`. The row is then re-counted after a
         * short settle, so a second copy arriving late still fails the test.
         */
        await pasien.waitForResponse(
            (r) =>
                r.url().includes(`/api/v1/konsultasi/${konsultasiId}/chat`) &&
                r.request().method() === 'GET',
            { timeout: 20_000 },
        );

        await expect(
            barisPesan(pasien, isiPasien),
            'pengirim harus melihat pesannya tepat sekali setelah refetch',
        ).toHaveCount(1, { timeout: 20_000 });

        await jedaSettle();

        await expect(
            barisPesan(pasien, isiPasien),
            'duplikat yang muncul belakangan tetap harus gagal',
        ).toHaveCount(1);

        // The row is one element keyed by the server's own id, not by its text.
        await expect(
            dokter.locator(`[data-pesan-id="${dibuat.data.pesan.id}"]`),
        ).toHaveCount(1);
        await expect(
            pasien.locator(`[data-pesan-id="${dibuat.data.pesan.id}"]`),
        ).toHaveCount(1);

        console.log(
            `DUPLICATES_SUPPRESSED_ON_SENDER=${await transcript(pasien).getAttribute(
                'data-duplicates-suppressed',
            )}`,
        );

        // --- the doctor replies, the patient receives ----------------------------
        const isiDokter = `Balasan dokter ${Date.now()}`;

        await kirimPesan(dokter, isiDokter);

        await expect(
            barisPesan(pasien, isiDokter),
            'pasien harus melihat balasan tanpa reload',
        ).toHaveCount(1, { timeout: 20_000 });
        await expect(barisPesan(dokter, isiDokter)).toHaveCount(1);

        // --- the auth endpoint, once per context, and only at /api --------------
        const semuaJejak = [...jejakPasien, ...jejakDokter];
        const panggilanAuth = semuaJejak.filter((baris) =>
            baris.includes('/broadcasting/auth'),
        );

        console.log('NETWORK_LOG_PASIEN\n' + jejakPasien.join('\n'));
        console.log('NETWORK_LOG_DOKTER\n' + jejakDokter.join('\n'));
        console.log('BROADCAST_AUTH_CALLS\n' + panggilanAuth.join('\n'));

        expect(
            panggilanAuth.filter(
                (baris) => baris === 'POST 200 /api/broadcasting/auth',
            ).length,
            'tepat satu POST 200 ke /api/broadcasting/auth per konteks',
        ).toBe(2);

        expect(
            panggilanAuth.some((baris) =>
                baris.includes('/api/v1/broadcasting/auth'),
            ),
            'auth endpoint tidak boleh dipanggil di /api/v1',
        ).toBe(false);

        await patientScreenshot(
            pasien,
            'playwright-report/realtime-pasien.png',
        );
        await patientScreenshot(dokter, 'playwright-report/realtime-dokter.png');

        await pasienCtx.close();
        await dokterCtx.close();
    });

    test('a Reverb outage degrades to REST and recovers without loss or duplication', async ({
        browser,
    }) => {
        /**
         * This test needs a budget larger than Playwright's 30 s default, and the
         * default made it unwinnable rather than merely slow.
         *
         * `tungguReverbHidup()` alone polls 60 x 500 ms - a full 30 seconds - so on the
         * worst legal path the restart alone consumes the entire default budget before
         * the recovery assertions have even started. On top of that the spec waits
         * 60 s for `data-connected="false"`, 60 s for the resync counter, and 2.5 s of
         * settle twice. `mode: 'serial'` then skipped the two tests after it, so a
         * budget problem was reported as "the SOAP and medical-record tests did not
         * run" - a failure that looks like missing coverage rather than a timeout.
         */
        test.setTimeout(240_000);

        const pasienCtx: BrowserContext = await browser.newContext();
        const dokterCtx: BrowserContext = await browser.newContext();

        const pasien = await pasienCtx.newPage();
        const dokter = await dokterCtx.newPage();

        await daftarDanMasuk(pasien);
        await masukSebagaiDokter(dokter);

        const dokterId = await dokterIdDokter(dokter);
        const konsultasiId = await mulaiKonsultasi(pasien, dokterId);

        await pasien.goto(`/konsultasi/${konsultasiId}`);
        await dokter.goto(`/konsultasi/${konsultasiId}`);

        await tungguKanalTerpasang(pasien);
        await tungguKanalTerpasang(dokter);

        // --- before the outage: one message, rendered once -----------------------
        const sebelum = `Sebelum putus ${Date.now()}`;

        await kirimPesan(dokter, sebelum);

        await expect(barisPesan(pasien, sebelum)).toHaveCount(1, {
            timeout: 20_000,
        });

        // --- the broker goes away ------------------------------------------------
        // --- the broker goes away ------------------------------------------------
        await stopReverb();

        try {
            await expect(transcript(pasien)).toHaveAttribute(
                'data-connected',
                'false',
                { timeout: 60_000 },
            );

            await expect(
                transcript(pasien).locator('[data-slot="realtime-status"]'),
            ).toContainText('Mode REST, tanpa realtime');

            /**
             * The degradation the plan names: history still loads over REST, so the
             * screen is degraded rather than blank, and the message sent before the
             * outage is still on it. A "blank screen" failure is exactly what loses
             * that.
             */
            await expect(barisPesan(pasien, sebelum)).toHaveCount(1);

            await patientScreenshot(
                pasien,
                'playwright-report/realtime-reconnecting.png',
            );

            await jedaSettle();
        } finally {
            await startReverb();
        }

        // --- the broker returns, and the gap closes over REST ---------------------
        // pusher-js parks itself once its state is `unavailable` and never retries, so
        // recovery is the app's own control - the same path a user takes, and the
        // counterpart of the Dart client's `resubscribe()`. It re-opens the socket AND
        // re-dispatches the subscribe, re-reading the bearer.
        await transcript(pasien)
            .getByRole('button', { name: 'Hubungkan ulang' })
            .click();

        await tungguKanalTerpasang(pasien);

        // `data-resyncs` lives on the transcript element, not on the status strip. A
        // non-zero count is the observable fact that the re-subscribe ran AND the gap
        // was closed over REST - the broker replayed nothing, so this number can only
        // have moved if a history fetch happened.
        await expect(transcript(pasien)).toHaveAttribute(
            'data-resyncs',
            /[1-9]\d*/,
            { timeout: 60_000 },
        );

        // --- continuity, and no double-render -------------------------------------
        // The pre-outage message survived the outage, the recovery and the resync, and
        // it is still drawn once.
        await expect(barisPesan(pasien, sebelum)).toHaveCount(1);
        await expect(barisPesan(dokter, sebelum)).toHaveCount(1);

        await jedaSettle();

        await expect(barisPesan(pasien, sebelum)).toHaveCount(1);

        // --- and live delivery really is restored ---------------------------------
        const sesudah = `Setelah pulih ${Date.now()}`;

        await kirimPesan(dokter, sesudah);

        await expect(barisPesan(pasien, sesudah)).toHaveCount(1, {
            timeout: 20_000,
        });

        await jedaSettle();

        await expect(barisPesan(pasien, sesudah)).toHaveCount(1);
        await expect(
            barisPesan(dokter, sesudah),
            'pengirim juga harus melihatnya tepat sekali',
        ).toHaveCount(1);

        await patientScreenshot(
            pasien,
            'playwright-report/realtime-recovered.png',
        );

        await pasienCtx.close();
        await dokterCtx.close();
    });

    test('the SOAP form writes through REST and the response reflects the write', async ({
        browser,
    }) => {
        const pasienCtx: BrowserContext = await browser.newContext();
        const dokterCtx: BrowserContext = await browser.newContext();

        const patientPage = await pasienCtx.newPage();
        const dokterPage = await dokterCtx.newPage();

        await daftarDanMasuk(patientPage);
        await masukSebagaiDokter(dokterPage);

        const dokterId = await dokterIdDokter(dokterPage);
        const konsultasiId = await mulaiKonsultasi(patientPage, dokterId);

        // Without this the note cannot be written at all: see terimaKonsultasi.
        await terimaKonsultasi(dokterPage, konsultasiId);

        // --- a patient is not offered the form at all ---------------------------
        await patientPage.goto(`/konsultasi/${konsultasiId}`);

        await expect(
            patientPage.locator('[data-slot="soap-form"]'),
            'pasien tidak boleh melihat form SOAP',
        ).toHaveCount(0);

        // --- the doctor writes all six fields -----------------------------------
        await dokterPage.goto(`/konsultasi/${konsultasiId}`);

        const tanda = String(Date.now()).slice(-6);

        /**
         * `Asesmen (A)` is the label on the `catatan_asessment` field, whose DDL
         * spelling at `telemedicine_test.sql:550` is one `s` short of the English
         * word. It is asserted by name here because that is the field a reader is most
         * likely to believe is a typo and "fix".
         */
        const KOLOM: ReadonlyArray<readonly [string, string]> = [
            ['Subjektif (S)', 'catatan_subjektif'],
            ['Objektif (O)', 'catatan_objektif'],
            ['Asesmen (A)', 'catatan_asessment'],
            ['Plan (P)', 'catatan_plan'],
            ['Diagnosis kerja', 'diagnosis_kerja'],
            ['Saran tindak lanjut', 'saran_tindak_lanjut'],
        ];

        const diisi: Record<string, string> = {};

        for (const [label, kolom] of KOLOM) {
            const nilai = `Nilai uji ${kolom} ${tanda}`;

            diisi[kolom] = nilai;
            await dokterPage.getByLabel(label).fill(nilai);
        }

        const [selesai] = await Promise.all([
            dokterPage.waitForResponse(
                (r) =>
                    r.url().includes(
                        `/api/v1/konsultasi/${konsultasiId}/selesai`,
                    ) && r.request().method() === 'PUT',
            ),
            dokterPage
                .getByRole('button', { name: 'Simpan catatan SOAP' })
                .click(),
        ]);

        expect(
            selesai.status(),
            'PUT /konsultasi/{id}/selesai harus 200',
        ).toBe(200);

        /**
         * The response carries the consultation after the transaction, and this is the
         * check that the six columns really were stored. A 200 alone would prove only
         * that the request was accepted, and `KonsultasiService::tulisSoap()` exists
         * because a typo'd key could be accepted and written nowhere.
         */
        const body = (await selesai.json()) as {
            data: { konsultasi: Record<string, string | null> };
        };

        for (const [label, kolom] of KOLOM) {
            expect(
                body.data.konsultasi[kolom],
                `kolom ${kolom} harus terbaca kembali sama dengan yang dikirim`,
            ).toBe(diisi[kolom]);
        }

        // The form's own read-back check must not have fired the mismatch notice.
        await expect(
            dokterPage.getByText('tidak tersimpan'),
        ).toHaveCount(0);

        await patientScreenshot(
            dokterPage,
            'playwright-report/soap-form.png',
        );

        await pasienCtx.close();
        await dokterCtx.close();
    });

    test('the medical record shows the amendment chain and is read-only for a patient', async ({
        browser,
    }) => {
        const pasienCtx: BrowserContext = await browser.newContext();
        const dokterCtx: BrowserContext = await browser.newContext();

        const patientPage = await pasienCtx.newPage();
        const dokterPage = await dokterCtx.newPage();

        await daftarDanMasuk(patientPage);
        await masukSebagaiDokter(dokterPage);

        const dokterId = await dokterIdDokter(dokterPage);
        const konsultasiId = await mulaiKonsultasi(patientPage, dokterId);

        // --- the doctor opens a DRAFT off the consultation ------------------------
        const [buat] = await Promise.all([
            dokterPage.waitForResponse((r) =>
                r.url().includes(
                    `/api/v1/konsultasi/${konsultasiId}/rekam-medis`,
                ),
            ),
            dokterPage.evaluate(async ([id]) => {
                const accessToken = globalThis.sessionStorage.getItem(
                    'sehatly.access_token',
                );

                const response = await fetch(
                    `/api/v1/konsultasi/${id}/rekam-medis`,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            ...(accessToken === null
                                ? {}
                                : { Authorization: `Bearer ${accessToken}` }),
                        },
                        body: JSON.stringify({
                            keluhan_utama: 'Keluhan uji e2e',
                            subjektif: 'Subjektif versi satu',
                        }),
                    },
                );

                await response.text();
            }, [String(konsultasiId)]),
        ]);

        expect(
            buat.status(),
            'POST /konsultasi/{id}/rekam-medis harus 201',
        ).toBe(201);

        const dibuatBadan = (await buat.json()) as {
            data: { rekam_medis: { id: number } };
        };

        const rekamId = dibuatBadan.data.rekam_medis.id;

        // --- the patient reads it: one version, and it is readable by them --------
        await patientPage.goto(`/rekam-medis/${rekamId}`);

        /**
         * `.first()` throughout this test, and it is not a convenience.
         *
         * A status badge appears TWICE for a single-version record: once in the card
         * header for the record itself and once inside the chain entry, which is the
         * same row. Playwright's strict mode rejects a locator that resolves to two
         * elements, so an un-`.first()`ed assertion fails on the app rendering a chain
         * at all - which is a feature, and the very thing the test checks next.
         */
        await expect(
            patientPage
                .locator('[data-slot="status-dokumen-badge"][data-status="draft"]')
                .first(),
        ).toBeVisible({ timeout: 20_000 });
        await expect(
            patientPage.locator('[data-slot="rekam-medis-rantai-entri"]'),
        ).toHaveCount(1);
        await expect(
            patientPage.locator('[data-slot="versi-badge"][data-versi="1"]').first(),
        ).toBeVisible();

        // A patient is offered no editor.
        await expect(
            patientPage.getByRole('button', { name: 'Edit rekam medis' }),
        ).toHaveCount(0);

        await patientScreenshot(
            patientPage,
            'playwright-report/rekam-medis-pasien.png',
        );

        // --- the doctor signs it: in-place editing is gone -----------------------
        await dokterPage.goto(`/rekam-medis/${rekamId}`);

        await dokterPage
            .getByRole('button', { name: 'Edit rekam medis' })
            .click();
        await dokterPage
            .getByRole('button', { name: /Tanda tangani/ })
            .click();

        await expect(
            dokterPage
                .locator('[data-slot="status-dokumen-badge"][data-status="final"]')
                .first(),
        ).toBeVisible({ timeout: 20_000 });

        await expect(
            dokterPage.getByRole('button', { name: /Tanda tangani/ }),
        ).toHaveCount(0);

        // --- then amends it: TWO versions afterwards ----------------------------
        await dokterPage
            .getByRole('button', { name: 'Ajukan amandemen' })
            .click();
        await dokterPage
            .getByLabel('Asesmen (A)')
            .fill('Asesmen diamendemen');
        await dokterPage
            .getByRole('button', {
                name: 'Konfirmasi kirim sebagai amandemen',
            })
            .click();

        /**
         * The chain is reconstructed by `(pasien_id, dokter_id, tanggal_periksa)`
         * ordered by `versi`, with no linkage column anywhere, so the `ran` block is
         * the only way to see it. Two entries means the amendment INSERTED a row and
         * left the signed one untouched.
         */
        await expect(
            dokterPage.locator('[data-slot="rekam-medis-rantai-entri"]'),
        ).toHaveCount(2, { timeout: 20_000 });
        await expect(
            dokterPage.locator(
                '[data-slot="rekam-medis-rantai-entri"][data-versi="1"]',
            ),
        ).toHaveCount(1);
        await expect(
            dokterPage.locator(
                '[data-slot="rekam-medis-rantai-entri"][data-versi="2"]',
            ),
        ).toHaveCount(1);
        await expect(
            dokterPage
                .locator(
                    '[data-slot="status-dokumen-badge"][data-status="diamendemen"]',
                )
                .first(),
        ).toBeVisible();

        await patientScreenshot(
            dokterPage,
            'playwright-report/rekam-medis-rantai.png',
        );

        await pasienCtx.close();
        await dokterCtx.close();
    });
});
