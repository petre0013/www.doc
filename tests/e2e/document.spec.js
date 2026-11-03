import { test, expect } from '@playwright/test';
import { createDoc } from './helpers';

test.describe('Document and Workspace Management', () => {
  test('user can create a document, type, save, export, and see it audited', async ({ page }) => {
    const docTitle = `My E2E Document ${Date.now()}`;
    await createDoc(page, 'E2E Docs Workspace', docTitle);

    // Now inside the document view (edit mode)
    await expect(page.getByText(docTitle)).toBeVisible();

    // Type inside the editor
    const editor = page.locator('.tiptap.ProseMirror');
    await expect(editor).toBeVisible();
    await editor.click();
    await page.keyboard.type('This is some E2E test content.');
    await expect(editor).toContainText('This is some E2E test content.');

    // Save → the page returns to read mode (Edit button visible again)
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Edit' })).toBeVisible();

    // Export to PDF, via the header's ⋯ menu. No conditional guard: if the
    // entry disappears from the UI this test must FAIL, not silently skip
    // half its assertions.
    await page.getByRole('button', { name: 'More actions' }).click();
    await page.getByRole('menuitem', { name: /Export/i }).click();
    await page.getByText('PDF').click();
    await page.getByRole('dialog').getByRole('button', { name: 'Export' }).click();

    // Generation is queued; wait for the download to become ready.
    const downloadBtn = page.getByRole('button', { name: 'Download PDF' });
    await expect(downloadBtn).toBeVisible({ timeout: 15000 });

    const downloadPromise = page.waitForEvent('download');
    await downloadBtn.click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toMatch(/\.pdf$/);

    // The actions above landed in the audit trail (admin-only page).
    await page.goto('/admin/audit');
    await expect(page.getByText('document.created').first()).toBeVisible();
    await expect(page.getByText('document.updated').first()).toBeVisible();
  });
});
