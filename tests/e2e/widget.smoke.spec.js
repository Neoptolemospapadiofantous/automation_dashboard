import { test, expect } from '@playwright/test';

// Golden-path smoke for the public embed surface — the loader script and the
// iframe chat page. Both are LLM-free (only /launch and /interact hit the
// runtime), so these assertions are deterministic and cost nothing.
//
// Requires E2E_AGENT_SLUG to point at an ACTIVE agent. In CI the e2e job seeds
// one (database/seeders/E2eSeeder.php) and exports its slug.
const slug = process.env.E2E_AGENT_SLUG;

test.describe('embed widget', () => {
    test.skip(!slug, 'set E2E_AGENT_SLUG to an active agent slug');

    test('widget loader serves JavaScript for an active agent', async ({ request }) => {
        const res = await request.get(`/widget/${slug}.js`);
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type']).toContain('javascript');
        expect(await res.text()).toContain('flowstack');
    });

    test('embed page renders the chat iframe HTML', async ({ page }) => {
        const res = await page.goto(`/embed/${slug}`);
        expect(res?.status()).toBe(200);
        // Plain Blade chat shell — the message thread + composer the bootstrap drives.
        await expect(page.locator('#thread')).toBeAttached();
        await expect(page.locator('#composer')).toBeAttached();
    });

    test('unknown slug is a 404, not a server error', async ({ request }) => {
        const widget = await request.get('/widget/e2e-does-not-exist.js');
        expect(widget.status()).toBe(404);

        const embed = await request.get('/embed/e2e-does-not-exist');
        expect(embed.status()).toBe(404);
    });
});
