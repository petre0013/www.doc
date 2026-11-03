import { test, expect } from '@playwright/test';
import { createDoc } from './helpers.js';

// v1.3 editor nodes: task lists, code-block language, callouts.
// Exercises the full parity loop: insert via toolbar → save (JSON) → reload →
// read view renders from JSON via the same schema.
const WS = 'E2E Editor Nodes Workspace';

test('task list toggles, saves, and renders read-only', async ({ page }) => {
  await createDoc(page, WS, `Tasks Doc ${Date.now()}`);

  const editor = page.locator('.tiptap-edit-area .tiptap');
  await editor.click();
  await page.getByTitle('Task list').click();
  await page.keyboard.type('first task');
  await page.keyboard.press('Enter');
  await page.keyboard.type('second task');

  // Check the first item's box in the editor. (The live editor DOM omits
  // data-type on the li — scope through the ul instead.)
  const items = page.locator('ul[data-type="taskList"] > li');
  await items.first().locator('input').click();
  await expect(page.locator('ul[data-type="taskList"] > li[data-checked="true"]')).toHaveCount(1);

  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Edit' })).toBeVisible();

  // Read view: items render, checked state persisted, boxes not editable.
  const readItems = page.locator('.tiptap-read-area ul[data-type="taskList"] > li');
  await expect(readItems).toHaveCount(2);
  await expect(readItems.first()).toHaveAttribute('data-checked', 'true');
  await expect(readItems.first().locator('input')).toBeChecked();
  await expect(readItems.nth(1).locator('input')).not.toBeChecked();
});

test('code block gets a language and highlights in read view', async ({ page }) => {
  await createDoc(page, WS, `Code Doc ${Date.now()}`);

  const editor = page.locator('.tiptap-edit-area .tiptap');
  await editor.click();
  await page.getByTitle('Code block').click();
  await page.keyboard.type('const answer = 42;');

  // The contextual language picker appears while inside the block.
  await page.getByTitle('Code language').selectOption('javascript');

  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByRole('button', { name: 'Edit' })).toBeVisible();

  const readBlock = page.locator('.tiptap-read-area pre code');
  await expect(readBlock).toContainText('const answer = 42;');
  // Lowlight decorates tokens client-side in the read view too.
  await expect(readBlock.locator('.hljs-keyword').first()).toHaveText('const');
});

test('callout inserts via slash command, switches kind, and persists', async ({ page }) => {
  await createDoc(page, WS, `Callout Doc ${Date.now()}`);

  const editor = page.locator('.tiptap-edit-area .tiptap');
  await editor.click();
  // No spaces in the slash query — the suggestion popup closes on space.
  await page.keyboard.type('/callout');
  await page.getByRole('button', { name: 'Callout: Warning' }).click();
  await page.keyboard.type('mind the gap');

  await expect(page.locator('.tiptap-edit-area div[data-callout="warning"]')).toBeVisible();

  // Kind switcher chips appear while inside the callout.
  await page.getByTitle('Danger callout').click();
  await expect(page.locator('.tiptap-edit-area div[data-callout="danger"]')).toBeVisible();

  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByRole('button', { name: 'Edit' })).toBeVisible();

  const readCallout = page.locator('.tiptap-read-area div[data-callout="danger"]');
  await expect(readCallout).toBeVisible();
  await expect(readCallout).toContainText('mind the gap');
});
