import { test, expect } from '@playwright/test';
import { createDoc } from './helpers.js';

// The attachments panel (edit mode) stages files as pending uploads — both by
// dragging them onto the panel and via the browse picker (the hidden file input
// behind the "Add attachment" button, which replaced the old add modal) — and
// they persist when the page is saved. The drop path is scoped to the panel so
// it never competes with the editor's own image drop.
const WS = 'E2E Attachment Drop Workspace';

/**
 * Synthesize a file drop onto the attachments panel by dispatching a real `drop`
 * DragEvent (carrying a DataTransfer) that bubbles up to the section's React
 * handler — the same trick image-paste.spec.js uses for paste.
 */
async function dropFiles(page, files) {
    await page.evaluate((files) => {
        const dt = new DataTransfer();
        for (const f of files) {
            dt.items.add(new File([f.content], f.name, { type: 'text/plain' }));
        }
        const heading = Array.from(document.querySelectorAll('h2'))
            .find((h) => /Attachments/.test(h.textContent));
        const section = heading.closest('section');
        section.dispatchEvent(new DragEvent('drop', {
            dataTransfer: dt, bubbles: true, cancelable: true,
        }));
    }, files);
}

test('dropping files onto the panel stages them and they persist on save', async ({ page }) => {
    const url = await createDoc(page, WS, `Drop Attach ${Date.now()}`);

    // Empty edit-mode state invites a drop.
    await expect(page.getByText('Drop files here or browse')).toBeVisible();

    const nameA = `runbook-${Date.now()}.txt`;
    const nameB = `notes-${Date.now()}.txt`;
    await dropFiles(page, [
        { name: nameA, content: 'hello world' },
        { name: nameB, content: 'second file' },
    ]);

    // Both stage as pending "New" rows, still uncommitted.
    await expect(page.getByText(nameA)).toBeVisible();
    await expect(page.getByText(nameB)).toBeVisible();
    await expect(page.getByText('New', { exact: true })).toHaveCount(2);

    // Save. The "Page saved." toast fires only after every staged upload has
    // committed (it's emitted after `await commitAttachments()`), so it's the
    // signal that the page is safe to reload — waiting on the read-mode header
    // instead would race the still-in-flight second upload.
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Page saved.')).toBeVisible();

    // The attachments survive a fresh load as real, downloadable files.
    await page.goto(url);
    await expect(page.getByText(nameA)).toBeVisible();
    await expect(page.getByText(nameB)).toBeVisible();
    await expect(page.getByRole('link', { name: 'Download' })).toHaveCount(2);
    // Committed files are no longer staged uploads.
    await expect(page.getByText('New', { exact: true })).toHaveCount(0);
});

test('the browse picker stages files and they persist on save', async ({ page }) => {
    const url = await createDoc(page, WS, `Browse Attach ${Date.now()}`);

    // Empty edit-mode state offers the browse affordance.
    await expect(page.getByText('Drop files here or browse')).toBeVisible();

    const nameA = `manual-${Date.now()}.txt`;
    const nameB = `guide-${Date.now()}.txt`;

    // The "Add attachment" / empty-state browse opens a native picker backed by a
    // hidden multiple file input (no intermediate modal) — drive it directly.
    const input = page.locator('section:has(h2:has-text("Attachments")) input[type=file]');
    await input.setInputFiles([
        { name: nameA, mimeType: 'text/plain', buffer: Buffer.from('hello world') },
        { name: nameB, mimeType: 'text/plain', buffer: Buffer.from('second file') },
    ]);

    // Both stage as pending "New" rows — same path as a drop, no modal in between.
    await expect(page.getByText(nameA)).toBeVisible();
    await expect(page.getByText(nameB)).toBeVisible();
    await expect(page.getByText('New', { exact: true })).toHaveCount(2);

    // Save — the "Page saved." toast fires only after every staged upload commits.
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Page saved.')).toBeVisible();

    // They survive a fresh load as real, downloadable files.
    await page.goto(url);
    await expect(page.getByText(nameA)).toBeVisible();
    await expect(page.getByText(nameB)).toBeVisible();
    await expect(page.getByRole('link', { name: 'Download' })).toHaveCount(2);
    await expect(page.getByText('New', { exact: true })).toHaveCount(0);
});

test('an oversized dropped file is rejected and not staged', async ({ page }) => {
    await createDoc(page, WS, `Drop Oversize ${Date.now()}`);

    const bigName = `too-big-${Date.now()}.txt`;
    await dropFiles(page, [
        // 26 MB > the 25 MB cap.
        { name: bigName, content: 'x'.repeat(26 * 1024 * 1024) },
    ]);

    await expect(page.getByText(/larger than 25 MB/i)).toBeVisible();
    await expect(page.getByText(bigName)).toHaveCount(0);
});
