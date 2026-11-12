import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for the Sehatly web client.
 *
 * Owned by todo 1 as a committed baseline: the Playwright dependency and this
 * config file are installed and committed here so that the later UI todos
 * (which name Playwright as their QA driver) have a working harness to build on.
 *
 * The end-to-end spec files are intentionally absent. Todo 5 owns the real SPA
 * and the first real specs; until then `npx playwright test` exits non-zero with
 * "no tests found", which is the expected state for this baseline.
 */
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: process.env.CI ? 'github' : 'list',

    use: {
        baseURL: process.env.SEHATLY_BASE_URL ?? 'http://127.0.0.1:8000',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },

    /**
     * Playwright's default 5s is not enough for THIS harness.
     *
     * Measured on this machine: `goto('/')` resolves on `load` at ~2.6s, and the SPA's
     * nav row only mounts at ~11.4s after that - because `php artisan serve` is
     * single-threaded and answers the ~40 chunks of the landing graph one at a time
     * (250-300ms each). Any assertion whose content is correct then fails for being
     * read too early, which is a false red that teaches the wrong lesson.
     *
     * The assertions themselves are unchanged; only the grace period is longer. If the
     * suite ever runs against a threaded static host, this can go back down.
     */
    expect: { timeout: 15_000 },

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
