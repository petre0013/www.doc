import { expect } from '@playwright/test';

/**
 * Open a workspace by name, creating it if it doesn't exist yet.
 *
 * Each spec file must use its OWN workspace name: specs run fully parallel, and
 * two workers doing check-then-create on the same name race into duplicates
 * (and ambiguous `.first()` matches from then on).
 */
export async function openWorkspace(page, name) {
    await page.goto('/workspaces');
    await expect(page).toHaveTitle(/www\.doc/i);

    // Scope to table rows (li): the quick-access lists above the table repeat
    // workspace names as row meta, and those links lead to documents.
    const existing = page.locator('li').getByText(name).first();
    if (await existing.isVisible().catch(() => false)) {
        await existing.click();
    } else {
        await page.getByRole('button', { name: /New workspace/i }).first().click();
        await page.locator('#workspace-name').fill(name);
        await page.getByRole('button', { name: 'Create workspace' }).click();
    }

    await expect(page.getByRole('heading', { name })).toBeVisible();
}

/**
 * Create a fresh page in the given workspace (landing in edit mode) and return
 * its canonical /documents/{id} URL.
 */
export async function createDoc(page, workspaceName, title) {
    await openWorkspace(page, workspaceName);

    const addSubpage = page.getByTitle('Add subpage').first();
    if (await addSubpage.isVisible().catch(() => false)) {
        await addSubpage.click();
    } else {
        await page.getByRole('button', { name: /New page/i }).first().click();
    }

    await page.getByPlaceholder('e.g. VPN setup').fill(title);
    await page.getByRole('button', { name: 'Create page' }).click();

    await page.waitForURL(/\/documents\/\d+/);
    return page.url().split('?')[0];
}
