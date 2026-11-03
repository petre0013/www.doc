import { test, expect } from '@playwright/test';
import { createDoc, openWorkspace } from './helpers.js';

// v1.3 navigation aids: star a page, find it (and the visit itself) in the
// personal quick-access lists on the workspaces overview, unstar from the tree.
const WS = 'E2E Nav Aids Workspace';

test('star a page, see it in quick access, unstar from the tree', async ({ page }) => {
    const title = `Quick Access Doc ${Date.now()}`;

    // Sections are identified by their collapse-toggle's accessible name
    // ("Starred (1)") — plain text filters would also match doc titles echoed
    // in the other lists.
    const section = (label) => page.locator('section')
        .filter({ has: page.getByRole('button', { name: new RegExp(`^${label} \\(`) }) });

    await createDoc(page, WS, title);

    // Leave edit mode (nothing typed, so no discard prompt) — the star lives
    // in the read-mode header.
    await page.getByRole('button', { name: 'Cancel' }).click();
    await page.getByTitle('Star this page').click();
    await expect(page.getByTitle('Unstar this page')).toBeVisible();

    // Quick-access sections are collapsed by default; expanding shows the page.
    await page.goto('/workspaces');
    await expect(section('Starred').getByRole('link', { name: title })).toBeHidden();
    await section('Starred').getByRole('button', { name: /^Starred \(/ }).click();
    await expect(section('Starred').getByRole('link', { name: title })).toBeVisible();
    await section('Recently viewed').getByRole('button', { name: /^Recently viewed \(/ }).click();
    await expect(section('Recently viewed').getByRole('link', { name: title })).toBeVisible();

    // The expanded state persists across reload (localStorage).
    await page.reload();
    await expect(section('Starred').getByRole('link', { name: title })).toBeVisible();

    // Unstar via the tree row's affordance.
    await openWorkspace(page, WS);
    const row = page.locator('li').filter({ hasText: title }).first();
    await row.hover();
    await row.getByTitle('Unstar').click();
    await expect(row.getByTitle('Star', { exact: true })).toBeVisible();

    // Gone from Starred; still in Recently viewed (it was genuinely visited).
    await page.goto('/workspaces');
    await expect(section('Starred').getByRole('link', { name: title })).toHaveCount(0);
    await expect(section('Recently viewed').getByRole('link', { name: title })).toBeVisible();
});
