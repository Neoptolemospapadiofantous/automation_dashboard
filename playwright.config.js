import { defineConfig, devices } from '@playwright/test';

// End-to-end / browser layer. Drives the real app over HTTP — the cross-system
// flows no unit can cover (widget loader → embed iframe → live dashboard).
// See tests/README.md ("End-to-end layer") and tests/e2e/README.md.
//
// Config via env:
//   E2E_BASE_URL     app origin (default http://127.0.0.1:8000)
//   E2E_AGENT_SLUG   an ACTIVE agent slug to drive (smokes skip if unset)
//   E2E_NO_SERVER    set to reuse an already-running server (no auto php serve)
const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL,
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
    // Auto-start the Laravel dev server unless one is already running (or
    // E2E_NO_SERVER is set, e.g. when CI starts it as a separate step).
    webServer: process.env.E2E_NO_SERVER
        ? undefined
        : {
            command: 'php artisan serve --host=127.0.0.1 --port=8000',
            url: baseURL,
            reuseExistingServer: !process.env.CI,
            timeout: 60_000,
        },
});
