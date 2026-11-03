import { test, expect } from '@playwright/test';
import { openWorkspace } from './helpers.js';

// v1.3 page templates: manage under /templates, then instantiate from the
// New page modal's "Start from" picker — content lands in the new page.
const WS = 'E2E Templates Workspace';

test('create a template, instantiate it, then delete it', async ({ page }) => {
    const stamp = Date.now();
    const templateName = `E2E Checklist ${stamp}`;

    // ── Create the template and give it content ─────────────────────────────
    await page.goto('/templates');
    await expect(page.getByRole('heading', { name: 'Templates' })).toBeVisible();

    await page.getByRole('button', { name: 'New template' }).click();
    await page.getByPlaceholder('Template name').fill(templateName);
    await page.getByPlaceholder('Short description (optional)').fill('Made by the e2e suite');
    await page.getByRole('button', { name: 'Create', exact: true }).click();

    // Lands in the template editor.
    await page.waitForURL(/\/templates\/\d+\/edit/);
    const editor = page.locator('.tiptap');
    await editor.click();
    await page.keyboard.type('Template boilerplate body');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText(`Saved template "${templateName}".`)).toBeVisible();
    // Saving stays in the editor (it used to bounce back to the index).
    await expect(page).toHaveURL(/\/templates\/\d+\/edit/);

    // ── Instantiate it from the New page modal ──────────────────────────────
    await openWorkspace(page, WS);
    await page.getByRole('button', { name: /New page/i }).first().click();
    await page.getByPlaceholder('e.g. VPN setup').fill(`From Template ${stamp}`);
    // Options are radios in the "Start from" listbox (scrolled into view on click).
    await page.getByRole('radio', { name: templateName }).click();
    await page.getByRole('button', { name: 'Create page' }).click();

    // The new page opens in edit mode with the template's content copied in.
    await page.waitForURL(/\/documents\/\d+/);
    await expect(page.locator('.tiptap-edit-area .tiptap')).toContainText('Template boilerplate body');

    // ── Delete the template (pages made from it are unaffected) ─────────────
    await page.goto('/templates');
    const row = page.locator('li').filter({ hasText: templateName });
    await row.hover();
    await row.getByTitle(`Delete ${templateName}`).click();
    await page.getByRole('button', { name: 'Delete template' }).click();
    await expect(page.locator('li').filter({ hasText: templateName })).toHaveCount(0);
});

test('save an existing page as a template', async ({ page }) => {
    const stamp = Date.now();
    const pageTitle = `Source Page ${stamp}`;

    // A page with recognisable content.
    await openWorkspace(page, WS);
    await page.getByRole('button', { name: /New page/i }).first().click();
    await page.getByPlaceholder('e.g. VPN setup').fill(pageTitle);
    await page.getByRole('button', { name: 'Create page' }).click();
    await page.waitForURL(/\/documents\/\d+/);

    await page.locator('.tiptap-edit-area .tiptap').click();
    await page.keyboard.type('Reusable page body');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Edit' })).toBeVisible();

    // Save it as a template from the header's ⋯ menu.
    await page.getByRole('button', { name: 'More actions' }).click();
    await page.getByRole('menuitem', { name: /Save as template/i }).click();
    const nameField = page.getByPlaceholder('e.g. Runbook');
    await expect(nameField).toHaveValue(pageTitle); // prefilled with the title
    await nameField.fill(`Saved As ${stamp}`);
    await page.getByRole('button', { name: 'Save template' }).click();
    await expect(page.getByText(`Saved "Saved As ${stamp}" as a template.`)).toBeVisible();

    // It shows up in the manage list with its content.
    await page.goto('/templates');
    await page.getByRole('link', { name: `Saved As ${stamp}` }).click();
    await page.waitForURL(/\/templates\/\d+\/edit/);
    await expect(page.locator('.tiptap')).toContainText('Reusable page body');
});
