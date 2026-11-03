import { test, expect } from '@playwright/test';
import { openWorkspace } from './helpers';

/**
 * The Import page advertises "max 50 MB" and Laravel validates max:51200 — but
 * in production nginx fronts the app, and its global client_max_body_size (16m)
 * once rejected anything larger with a 413 before PHP ever saw the request.
 * This guards the per-route exemption in docker/nginx/default.conf: an ~18 MB
 * body must reach Laravel. Against `php artisan serve` (no nginx) it passes
 * trivially; the CI e2e job runs the real prod stack, where it bites.
 */
test('an 18 MB import upload gets past the web server to Laravel', async ({ page }) => {
    test.setTimeout(60_000);

    await openWorkspace(page, 'E2E Import Limit');
    await page.waitForURL(/\/workspaces\/\d+/);
    const workspaceId = page.url().match(/\/workspaces\/(\d+)/)[1];

    const cookies = await page.context().cookies();
    const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN');
    expect(xsrf).toBeTruthy();

    // 18 MB of zeros is deliberately not a valid docx: Laravel answers 422 from
    // the mimes rule. The assertion is that the status is Laravel's validation
    // response — nginx rejecting the body would surface as a 413 instead.
    const response = await page.request.post(`/workspaces/${workspaceId}/imports`, {
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(xsrf.value),
            Accept: 'application/json',
        },
        multipart: {
            file: {
                name: 'padding.docx',
                mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                buffer: Buffer.alloc(18 * 1024 * 1024),
            },
        },
    });

    expect(response.status(), 'expected Laravel validation (422), not a web-server body-size rejection').toBe(422);
    const body = await response.json();
    expect(body.errors).toHaveProperty('file');
});
