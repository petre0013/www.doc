import { test, expect } from '@playwright/test';
import { createDoc } from './helpers.js';

// The paste handler must upload the raw bitmap from the clipboard whenever the
// accompanying HTML offers no image we can resolve — e.g. web apps like
// WhatsApp that paste <img src="blob:…their-origin…"> alongside the bitmap.
// A blob: URL is scoped to the source origin: we can neither fetch nor store
// it, so without this the image lands as a dead "Image Unavailable" placeholder.
const WS = 'E2E Image Paste Workspace';

// 1x1 transparent PNG.
const PNG_B64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

/**
 * Synthesize a paste of an image bitmap plus optional text/html, and return the
 * src attributes of the images that ended up in the editor (after upload).
 */
async function pasteBitmap(page, { html }) {
  await page.evaluate(({ b64, html }) => {
    const bytes = Uint8Array.from(atob(b64), c => c.charCodeAt(0));
    const file = new File([bytes], 'pasted.png', { type: 'image/png' });

    const dt = new DataTransfer();
    dt.items.add(file);
    if (html) dt.setData('text/html', html);

    const el = document.querySelector('.tiptap-edit-area .tiptap');
    el.focus();
    el.dispatchEvent(new ClipboardEvent('paste', {
      clipboardData: dt, bubbles: true, cancelable: true,
    }));
  }, { b64: PNG_B64, html });

  // The image drops in immediately with a temporary blob: preview, then its src
  // is swapped for the hosted URL once the upload round-trip resolves. Wait for
  // that swap to settle rather than a fixed delay: the upload can take a second
  // or two on a cold CI stack, which a fixed sleep raced against (and lost).
  await page.waitForFunction(() => {
    const imgs = Array.from(document.querySelectorAll('.tiptap-edit-area img'));
    return imgs.length > 0
      && imgs.every(i => !(i.getAttribute('src') ?? '').startsWith('blob:'));
  }, null, { timeout: 15000 });

  return page.evaluate(() =>
    Array.from(document.querySelectorAll('.tiptap-edit-area img'))
      .map(i => i.getAttribute('src')));
}

test('pasting a WhatsApp-style image (bitmap + blob img) uploads the bitmap', async ({ page }) => {
  await createDoc(page, WS, `WA Paste ${Date.now()}`);
  await page.locator('.tiptap-edit-area .tiptap').click();

  const srcs = await pasteBitmap(page, {
    html: '<img src="blob:https://web.whatsapp.com/deadbeef-0000">',
  });

  expect(srcs.length).toBeGreaterThan(0);
  for (const src of srcs) expect(src).not.toContain('web.whatsapp.com');
  expect(srcs.some(s => /\/storage\/assets\//.test(s))).toBe(true);
});

test('pasting a plain screenshot (bitmap, no html) uploads the bitmap', async ({ page }) => {
  await createDoc(page, WS, `Screenshot Paste ${Date.now()}`);
  await page.locator('.tiptap-edit-area .tiptap').click();

  const srcs = await pasteBitmap(page, { html: '' });

  expect(srcs.length).toBeGreaterThan(0);
  expect(srcs.some(s => /\/storage\/assets\//.test(s))).toBe(true);
});
