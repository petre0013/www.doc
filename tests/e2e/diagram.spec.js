import { test, expect } from '@playwright/test';
import { createDoc } from './helpers';

/**
 * Network diagram — the browser-only parts Pest can't reach (this replaces the
 * old docs/network-diagram-smoke-test.md manual checklist):
 *
 *   - the live React Flow canvas: insert, add nodes, rename via double-click
 *   - persistence round-trip: the graph JSON in `attrs` is the source of truth,
 *     so a reload must bring the same nodes/labels back
 *   - the read view renders a (read-only) canvas, not an editor
 *   - hidden-label FTS: a node label never appears in the page TEXT, yet search
 *     must find the page by it (the search vector indexes diagram labels)
 *
 * Everything downstream of the persisted graph (server-side SVG for PDF/DOCX,
 * search-vector SQL) is covered by Pest: DiagramSvgTest, RenderDocumentTest,
 * NetworkDiagramTest, SearchTest.
 */
test('a network diagram edits, persists, renders read-only, and is searchable by node label', async ({ page }) => {
    test.setTimeout(60_000);

    const stamp = Date.now();
    const docTitle = `Diagram Doc ${stamp}`;
    // Unique label so the search assertion can't match older test runs' data.
    const label = `core-router-${stamp}`;

    await createDoc(page, 'E2E Diagram Workspace', docTitle);

    // Insert a diagram from the editor toolbar.
    const editor = page.locator('.tiptap.ProseMirror');
    await expect(editor).toBeVisible();
    await editor.click();
    await page.getByTitle('Insert diagram').click();

    const diagram = page.locator('[data-network-diagram]');
    await expect(diagram).toBeVisible();

    // Add two nodes.
    const addNode = diagram.getByRole('button', { name: 'Node', exact: true });
    await addNode.click();
    await addNode.click();
    await expect(diagram.locator('.react-flow__node')).toHaveCount(2);

    // Rename the first node via the floating properties panel: select it, set
    // its name, and add a key/value property row.
    const firstNode = diagram.locator('.react-flow__node').first();
    await firstNode.click(); // select → floating editor appears
    const nameInput = diagram.getByRole('textbox', { name: 'Node name' });
    await expect(nameInput).toBeVisible();
    await nameInput.fill(label);
    await diagram.getByRole('button', { name: 'Add property' }).click();
    await diagram.getByRole('textbox', { name: 'Property key' }).fill('IP');
    await diagram.getByRole('textbox', { name: 'Property value' }).fill('10.10.10.10');
    // Deselect to commit (click empty canvas, away from the top-left toolbar).
    const pane = diagram.locator('.react-flow__pane');
    const paneBox = await pane.boundingBox();
    await pane.click({ position: { x: paneBox.width - 30, y: paneBox.height - 30 } });
    await expect(diagram.getByText(label)).toBeVisible();
    await expect(diagram.getByText('10.10.10.10')).toBeVisible();

    // Save → read mode. The read view renders the graph as a READ-ONLY canvas:
    // the label is visible but the editing chrome (the Node button) is not.
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Edit' })).toBeVisible();
    await expect(page.locator('[data-network-diagram]').getByText(label)).toBeVisible();
    await expect(page.locator('[data-network-diagram]').getByText('10.10.10.10')).toBeVisible();
    await expect(page.locator('[data-network-diagram]').getByRole('button', { name: 'Node', exact: true })).toHaveCount(0);

    // Reload: the graph JSON persisted in the document is the source of truth.
    await page.reload();
    await expect(page.locator('[data-network-diagram]').getByText(label)).toBeVisible();

    // Hidden-label FTS: the label only ever existed inside the canvas, never in
    // the page text — searching for it must still find the page.
    const searchInput = page.getByPlaceholder(/Search docs/i);
    await searchInput.fill(label);
    await searchInput.press('Enter');
    await expect(page).toHaveURL(/\/search\?q=/);
    await expect(page.getByText(docTitle).first()).toBeVisible();
});
