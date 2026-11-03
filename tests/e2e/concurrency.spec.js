import { test, expect } from '@playwright/test';
import { createDoc } from './helpers';

/**
 * Optimistic-locking: when two people edit the same page, the second save is
 * rejected with a conflict dialog instead of silently overwriting the first.
 */
test('a stale save shows the conflict dialog and overwrite wins', async ({ browser }) => {
    const ctxA = await browser.newContext({ storageState: 'playwright/.auth/admin.json' });
    const ctxB = await browser.newContext({ storageState: 'playwright/.auth/admin.json' });
    const pageA = await ctxA.newPage();
    const pageB = await ctxB.newPage();

    try {
        // A creates the page (lands in edit mode) — both A and B now load the same base version.
        const docUrl = await createDoc(pageA, 'E2E Concurrency Workspace', `Conflict Doc ${Date.now()}`);

        await pageB.goto(docUrl);
        await pageB.getByRole('button', { name: 'Edit' }).click();
        await expect(pageB.locator('.tiptap.ProseMirror')).toBeVisible();

        // A edits and saves first → the document version bumps.
        const editorA = pageA.locator('.tiptap.ProseMirror');
        await editorA.click();
        await pageA.keyboard.type('Content from user A.');
        await pageA.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(pageA.getByRole('button', { name: 'Edit' })).toBeVisible();

        // B edits and saves on the now-stale base → conflict.
        const editorB = pageB.locator('.tiptap.ProseMirror');
        await editorB.click();
        await pageB.keyboard.type('Content from user B.');
        await pageB.getByRole('button', { name: 'Save', exact: true }).click();

        // The conflict dialog appears, showing both versions.
        await expect(pageB.getByText('This page changed while you were editing')).toBeVisible();
        await expect(pageB.getByText('Content from user A.')).toBeVisible(); // "their" side

        // Overwrite with mine → B's content wins and the page returns to read mode.
        await pageB.getByRole('button', { name: /Overwrite with mine/i }).click();
        await expect(pageB.getByRole('button', { name: 'Edit' })).toBeVisible();

        await pageB.goto(docUrl);
        await expect(pageB.getByText('Content from user B.')).toBeVisible();
    } finally {
        await ctxA.close();
        await ctxB.close();
    }
});
