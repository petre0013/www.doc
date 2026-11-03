import { test, expect } from '@playwright/test';
import { createDoc } from './helpers.js';

// A document may open with a list (or other non-textblock container). The
// LeadingParagraph extension lets you escape above it with ArrowUp/ArrowLeft to
// write intro text, without breaking normal caret navigation inside the list.
const WS = 'E2E List Escape Workspace';

const struct = (page) => page.evaluate(() => {
  const root = document.querySelector('.tiptap-edit-area .tiptap');
  return Array.from(root.children).map(el => el.tagName.toLowerCase());
});

test('ArrowUp from the first list item writes a paragraph above the list', async ({ page }) => {
  await createDoc(page, WS, `Up ${Date.now()}`);
  const editor = page.locator('.tiptap-edit-area .tiptap');
  await editor.click();

  await page.getByTitle('Bullet list').click();
  await page.keyboard.type('item one');
  await page.keyboard.press('ArrowUp');
  await page.keyboard.type('intro text');

  // A paragraph now precedes the list, holding the intro text.
  await expect(struct(page)).resolves.toEqual(expect.arrayContaining(['p', 'ul']));
  const first = editor.locator('> :first-child');
  await expect(first).toHaveText('intro text');
  await expect(first.locator('..').locator('> ul')).toContainText('item one');
});

test('ArrowLeft from the very start of the first list item escapes above', async ({ page }) => {
  await createDoc(page, WS, `Left ${Date.now()}`);
  const editor = page.locator('.tiptap-edit-area .tiptap');
  await editor.click();

  // Fresh empty list item — the caret is already at the document start, so a
  // single ArrowLeft (nothing to the left) escapes above.
  await page.getByTitle('Bullet list').click();
  await page.keyboard.press('ArrowLeft');
  await page.keyboard.type('lead');

  await expect(editor.locator('> :first-child')).toHaveText('lead');
});

test('Backspace in the empty leading paragraph undoes the escape', async ({ page }) => {
  await createDoc(page, WS, `Undo ${Date.now()}`);
  const editor = page.locator('.tiptap-edit-area .tiptap');
  await editor.click();

  await page.getByTitle('Bullet list').click();
  await page.keyboard.type('item one');
  await page.keyboard.press('ArrowUp'); // escape above → empty leading paragraph
  await expect(editor.locator('> :first-child')).toHaveText('');

  await page.keyboard.press('Backspace'); // changed my mind
  await page.keyboard.type('X');

  // The leading paragraph is gone; the list is first again and holds the edit.
  await expect(editor.locator('> :first-child')).toHaveText(/item one/);
  await expect(editor.locator('> ul')).toContainText('X');
});

test('ArrowUp from a lower item stays inside the list', async ({ page }) => {
  await createDoc(page, WS, `Multi ${Date.now()}`);
  const editor = page.locator('.tiptap-edit-area .tiptap');
  await editor.click();

  await page.getByTitle('Bullet list').click();
  await page.keyboard.type('one');
  await page.keyboard.press('Enter');
  await page.keyboard.type('two');
  await page.keyboard.press('ArrowUp'); // moves to item one, does NOT escape
  await page.keyboard.type('X');

  // No paragraph was inserted before the list; the edit landed in item one.
  await expect(editor.locator('> :first-child')).toHaveText(/one/);
  await expect(editor.locator('> ul')).toContainText('X');
});
