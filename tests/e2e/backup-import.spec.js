import { test, expect } from '@playwright/test';

/**
 * Import Backups: back up now → download the archive → import that file back in.
 * The imported archive should register as a restorable "Imported" row. Imports
 * are non-blocking — they resolve as a normal Queued→Done/Failed row, never
 * behind the blocking "Backing up…" overlay.
 */
test('an admin can back up, download, and re-import the archive', async ({ page }) => {
    test.setTimeout(90_000);

    await page.goto('/admin/backups');
    await expect(page.getByRole('heading', { name: 'Backups' })).toBeVisible();

    // Kick off a backup and wait for the blocking progress modal to clear.
    await page.getByRole('button', { name: 'Back up now' }).click();
    await expect(page.getByText(/Backing up|Backup queued/)).toBeVisible();
    await expect(page.getByText(/Backing up|Backup queued/)).toBeHidden({ timeout: 60_000 });

    // Download the newest archive.
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByTitle('Download').first().click(),
    ]);
    const filePath = await download.path();
    expect(filePath).toBeTruthy();

    // Import it back through the modal.
    await page.getByRole('button', { name: 'Import' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Import a backup')).toBeVisible();
    await dialog.locator('#import-file').setInputFiles(filePath);
    await dialog.getByRole('button', { name: 'Import' }).click();

    // Non-blocking: the dialog closes and the row resolves via polling — no
    // full-screen "Backing up…" overlay for an import.
    await expect(page.getByText('Import a backup')).toBeHidden({ timeout: 15_000 });
    await expect(page.getByText('Imported').first()).toBeVisible({ timeout: 60_000 });
    await expect(page.getByTitle('Restore').first()).toBeEnabled({ timeout: 60_000 });
});

test('an import upload can be cancelled while in flight', async ({ page }) => {
    test.setTimeout(30_000);

    // Slow the import response so the uploading state is observable.
    await page.route('**/admin/backups/import', async (route) => {
        await new Promise((r) => setTimeout(r, 4000));
        route.continue();
    });

    await page.goto('/admin/backups');
    await page.getByRole('button', { name: 'Import' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.locator('#import-file').setInputFiles({
        name: 'archive.zip',
        mimeType: 'application/zip',
        buffer: Buffer.from('PK placeholder body'),
    });
    await dialog.getByRole('button', { name: 'Import' }).click();

    // While uploading, a Cancel upload control is shown; clicking it aborts and
    // returns the form to its ready state (no row is created).
    const cancel = dialog.getByRole('button', { name: 'Cancel upload' });
    await expect(cancel).toBeVisible();
    await cancel.click();
    await expect(dialog.getByRole('button', { name: 'Import' })).toBeVisible();
});

test('importing a random file fails gracefully, without a stuck blocking modal', async ({ page }) => {
    test.setTimeout(60_000);

    await page.goto('/admin/backups');
    await page.getByRole('button', { name: 'Import' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.locator('#import-file').setInputFiles({
        name: 'random.zip',
        mimeType: 'application/zip',
        buffer: Buffer.from('this is not a www.doc backup archive'),
    });
    await dialog.getByRole('button', { name: 'Import' }).click();

    // The row resolves to Failed via polling — it does not hang on a loading state…
    await expect(page.getByText('Failed').first()).toBeVisible({ timeout: 60_000 });
    // …and the page is never trapped behind the blocking backup overlay.
    await expect(page.getByRole('button', { name: 'Back up now' })).toBeEnabled();
});
