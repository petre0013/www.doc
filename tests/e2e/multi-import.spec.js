import { test, expect } from '@playwright/test';
import { openWorkspace } from './helpers';

/**
 * Batch import via the workspace Import dialog: real .docx files become
 * sibling pages; an unsupported file is listed as skipped (never uploaded);
 * a corrupt "docx" fails alone without sinking the rest of the batch.
 */
test('a batch of files imports as sibling pages and bad files fail alone', async ({ page }) => {
    test.setTimeout(120_000);

    await openWorkspace(page, 'E2E Multi Import');

    await page.getByRole('button', { name: 'More actions' }).click();
    await page.getByRole('menuitem', { name: /Import pages/ }).click();

    const dialogInput = page.locator('input[type=file]');
    await dialogInput.setInputFiles([
        'tests/e2e/fixtures/alpha-runbook.docx',
        'tests/e2e/fixtures/beta-checklist.docx',
    ]);
    // Staging appends across picks; these two exercise the failure paths.
    await dialogInput.setInputFiles([
        { name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('plain text — not importable') },
        { name: 'corrupt.docx', mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', buffer: Buffer.alloc(2048) },
    ]);

    // The unsupported file is visibly skipped, not silently dropped, and only
    // the three staged files count toward the batch.
    const txtRow = page.getByRole('listitem').filter({ hasText: 'notes.txt' });
    await expect(txtRow.getByText('Skipped')).toBeVisible();
    await page.getByRole('button', { name: 'Import 3 files' }).click();

    // Real files convert to pages; the corrupt one fails alone (server-side
    // validation rejects its content), without affecting its siblings.
    const alphaRow = page.getByRole('listitem').filter({ hasText: 'alpha-runbook.docx' });
    const betaRow = page.getByRole('listitem').filter({ hasText: 'beta-checklist.docx' });
    const corruptRow = page.getByRole('listitem').filter({ hasText: 'corrupt.docx' });
    await expect(alphaRow.getByText('Imported')).toBeVisible({ timeout: 60_000 });
    await expect(betaRow.getByText('Imported')).toBeVisible({ timeout: 60_000 });
    await expect(corruptRow.getByText('Failed')).toBeVisible({ timeout: 60_000 });
    await expect(page.getByText('2 pages imported, 1 failed.')).toBeVisible();

    // The tree behind the dialog refreshed live as conversions landed — the new
    // pages are in the DOM before Done is clicked, titles derived from filenames.
    // (.first(): local reruns accumulate same-named pages in the aged dev DB.)
    await expect(page.getByRole('link', { name: 'Alpha runbook' }).first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'Beta checklist' }).first()).toBeVisible();
    await page.getByRole('button', { name: 'Done' }).click();
    await expect(page.getByRole('heading', { name: 'Import pages' })).toBeHidden();

    // Per-row entry point: "Import as subpage" reopens the dialog with that
    // page preselected as the destination.
    const alphaTreeRow = page.getByRole('listitem')
        .filter({ has: page.getByRole('link', { name: 'Alpha runbook' }) })
        .first();
    await alphaTreeRow.hover();
    await alphaTreeRow.getByTitle('Import as subpage').click();
    await expect(page.getByRole('heading', { name: 'Import pages' })).toBeVisible();
    await expect(page.getByText(/Pages will be created under/)).toContainText('Alpha runbook');
});

/**
 * Renaming a staged row before importing. Escape belongs to the edit, not to
 * the dialog: the dialog's Escape-to-close listener sits on `document`, above
 * the input, so cancelling a rename used to close the whole modal with it.
 */
test('a staged file can be renamed, and Escape cancels the rename without closing the dialog', async ({ page }) => {
    await openWorkspace(page, 'E2E Rename Import');

    await page.getByRole('button', { name: 'More actions' }).click();
    await page.getByRole('menuitem', { name: /Import pages/ }).click();

    await page.locator('input[type=file]').setInputFiles(['tests/e2e/fixtures/alpha-runbook.docx']);

    // The row shows the title the page will get, derived from the filename,
    // with the filename itself kept underneath.
    const row = page.getByRole('listitem').filter({ hasText: 'alpha-runbook.docx' });
    await expect(row.getByText('Alpha runbook')).toBeVisible();

    const pencil = row.getByRole('button', { name: 'Rename alpha-runbook.docx' });
    const field = row.getByRole('textbox', { name: 'Rename alpha-runbook.docx' });

    // Enter commits.
    await pencil.click();
    await field.fill('Renamed by hand');
    await field.press('Enter');
    await expect(row.getByText('Renamed by hand')).toBeVisible();

    // Escape discards the next edit and leaves the dialog open.
    await pencil.click();
    await field.fill('Discarded');
    await field.press('Escape');
    await expect(page.getByRole('heading', { name: 'Import pages' })).toBeVisible();
    await expect(row.getByText('Renamed by hand')).toBeVisible();

    // Escape outside a field still closes the dialog, as it always did.
    await page.keyboard.press('Escape');
    await expect(page.getByRole('heading', { name: 'Import pages' })).toBeHidden();
});

/** Synthesizes the events an OS file drag fires; dnd-kit row drags never do. */
function fireFileDrag(page, type) {
    return page.evaluate((eventType) => {
        const dt = new DataTransfer();
        dt.items.add(new File([new Uint8Array(64)], 'dropped-notes.docx', {
            type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        }));
        window.dispatchEvent(new DragEvent(eventType, { dataTransfer: dt, bubbles: true, cancelable: true }));
    }, type);
}

test('dragging files over a workspace offers the drop overlay and staging them opens the dialog', async ({ page }) => {
    await openWorkspace(page, 'E2E Drop Import');

    await fireFileDrag(page, 'dragenter');
    await expect(page.getByText(/Drop files to import into/)).toBeVisible();

    await fireFileDrag(page, 'drop');
    await expect(page.getByRole('heading', { name: 'Import pages' })).toBeVisible();
    await expect(page.getByRole('listitem').filter({ hasText: 'dropped-notes.docx' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Import 1 file' })).toBeVisible();
});
