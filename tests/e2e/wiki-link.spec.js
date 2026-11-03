import { test, expect } from '@playwright/test';
import { createDoc } from './helpers.js';

const WS = 'E2E WikiLink Workspace';

test('typing [[ to a missing page offers Create new page and inserts a red link', async ({ page }) => {
  await createDoc(page, WS, `Wiki Source ${Date.now()}`);

  const editor = page.locator('.tiptap-edit-area .tiptap');
  await editor.click();

  const title = `Runbook ${Date.now()}`;
  await page.keyboard.type(`[[${title}`);

  const createItem = page.getByText(`Create new page '${title}'`);
  await expect(createItem).toBeVisible();
  await createItem.click();

  // An unresolved wiki-link chip now exists in the editor.
  const chip = editor.locator('span.wiki-link.unresolved', { hasText: title });
  await expect(chip).toBeVisible();
});

test('creating a page from a broken link in read mode lands on the new page', async ({ page }) => {
  const sourceUrl = await createDoc(page, WS, `Wiki Source B ${Date.now()}`);
  const sourceId = sourceUrl.split('/').pop();

  const editor = page.locator('.tiptap-edit-area .tiptap');
  await editor.click();

  const title = `Playbook ${Date.now()}`;
  await page.keyboard.type(`[[${title}`);
  await page.getByText(`Create new page '${title}'`).click();

  // Save → read view.
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Edit' })).toBeVisible();

  // Hover the red link; the card offers Create page.
  const chip = page.locator('.tiptap-read-area span.wiki-link.unresolved', { hasText: title });
  await chip.hover();
  const createBtn = page.getByRole('button', { name: /Create page/i });
  await expect(createBtn).toBeVisible();
  await createBtn.click();

  // Landed on a NEW document in edit mode with that title.
  await page.waitForURL(/\/documents\/\d+\?edit=1/);
  expect(page.url()).not.toContain(`/documents/${sourceId}`);
  await expect(page.locator('#edit-title')).toHaveValue(title);
});
