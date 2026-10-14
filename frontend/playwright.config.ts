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

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
