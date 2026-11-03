import { test, expect } from '@playwright/test';
import { createDoc } from './helpers.js';

// A link mark must not be inclusive: typing at the end of a link should produce
// plain text, not extend the link. Regression guard for the boundary behaviour.
const WS = 'E2E Link Mark Workspace';

test('typing after a link produces plain text, not more link', async ({ page }) => {
  await createDoc(page, WS, `Link Doc ${Date.now()}`);

  const editor = page.locator('.tiptap-edit-area .tiptap');
  await editor.click();

  // Write a word and select it.
  await page.keyboard.type('linktext');
  await page.keyboard.press('Shift+Home');

  // Apply a link to the selection via the toolbar (Enter in the URL field).
  await page.getByTitle('Insert / edit link').click();
  const urlField = page.getByPlaceholder('https://...');
  await urlField.fill('https://example.com');
  // The field is a controlled input and applyLink() reads its committed state;
  // wait for React to flush the value before Enter, or the apply can fire with
  // an empty URL and never create the link.
  await expect(urlField).toHaveValue('https://example.com');
  await urlField.press('Enter');

  // The popover closes only once applyLink() has run — wait for that, then
  // confirm the link landed before typing so we don't race its creation.
  await expect(urlField).toBeHidden();
  const anchor = page.locator('.tiptap-edit-area a');
  await expect(anchor).toHaveText('linktext');

  // Put the caret back in the editor past the link and keep writing. Clicking
  // refocuses the editor deterministically (focus may not have returned after
  // the popover unmounted); End lands the caret beyond the non-inclusive link.
  await editor.click();
  await page.keyboard.press('End');
  await page.keyboard.type('PLAIN');

  // The anchor must hold only the linked word — the trailing text is plain.
  await expect(anchor).toHaveCount(1);
  await expect(anchor).toHaveText('linktext');
  await expect(editor).toContainText('linktextPLAIN'); // both present in the paragraph
});

test('typing after an inline code span produces plain text, not more code', async ({ page }) => {
  await createDoc(page, WS, `Code Mark Doc ${Date.now()}`);

  const editor = page.locator('.tiptap-edit-area .tiptap');
  await editor.click();

  // Write a word, select it, and mark it as inline code via the toolbar.
  await page.keyboard.type('codetext');
  await page.keyboard.press('Shift+Home');
  await page.getByTitle('Inline code').click();

  // Confirm the mark landed, then put the caret back in the editor past the
  // code span (click refocuses deterministically; End clears the non-inclusive
  // boundary) and keep typing.
  const code = page.locator('.tiptap-edit-area code');
  await expect(code).toHaveText('codetext');
  await editor.click();
  await page.keyboard.press('End');
  await page.keyboard.type('PLAIN');

  // The <code> must hold only the marked word — the trailing text is plain.
  await expect(code).toHaveCount(1);
  await expect(code).toHaveText('codetext');
  await expect(editor).toContainText('codetextPLAIN');
});
