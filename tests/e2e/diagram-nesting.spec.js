import { test, expect } from '@playwright/test';
import { createDoc } from './helpers';

/**
 * Network diagram — nested zones, per-element lock, and the maximize overlay
 * (v1.6 diagram editor UX). Browser-only interactions Pest can't reach; the
 * server-side render of nested zones is covered by DiagramSvgTest.
 *
 * Drag notes that make these deterministic:
 *   - New zones spawn near the canvas centre; a large container zone can overlap
 *     a later one, so we enlarge + move the container clear before adding a child.
 *     Every helper reads live bounding boxes, so exact spawn position doesn't
 *     matter — only that spawned elements stay visible and grabbable.
 *   - React Flow renders parent-before-child, so after a re-parent the node
 *     array (and DOM index) reorders — reference nodes by their stable data-id.
 *   - Dragging a non-draggable (locked) node falls through to a canvas pan,
 *     shifting every node's screen coords equally; measure a locked node's
 *     offset RELATIVE to a free reference node to tell a move from a pan.
 */

const insertDiagram = async (page) => {
    const editor = page.locator('.tiptap.ProseMirror');
    await expect(editor).toBeVisible();
    await editor.click();
    await page.getByTitle('Insert diagram').click();
    const diagram = page.locator('[data-network-diagram]');
    await expect(diagram).toBeVisible();
    return diagram;
};

const dragFrom = async (page, x, y, dx, dy) => {
    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.mouse.move(x + 15, y + 15, { steps: 4 });   // clear the drag threshold
    await page.mouse.move(x + dx, y + dy, { steps: 15 });
    await page.mouse.up();
    await page.waitForTimeout(300);
};

const dragCenter = async (page, loc, dx, dy) => {
    const b = await loc.boundingBox();
    await dragFrom(page, b.x + b.width / 2, b.y + b.height / 2, dx, dy);
};

// Grab a zone by its header strip (top edge), not its geometric centre — a
// container zone's centre sits inside its child, which paints on top.
const dragZone = async (page, loc, dx, dy) => {
    const b = await loc.boundingBox();
    await dragFrom(page, b.x + b.width / 2, b.y + 6, dx, dy);
};

// Add a zone, enlarge it via its bottom-right resize handle, and park it clear
// of the top-left spawn corner. Returns its data-id.
const addContainerZone = async (page, diagram) => {
    const groups = diagram.locator('.react-flow__node-group');
    const beforeIds = await groups.evaluateAll((els) => els.map((e) => e.getAttribute('data-id')));
    await diagram.getByRole('button', { name: 'Zone', exact: true }).click();
    await expect(groups).toHaveCount(beforeIds.length + 1);
    const id = await groups.evaluateAll(
        (els, before) => els.map((e) => e.getAttribute('data-id')).find((x) => !before.includes(x)),
        beforeIds,
    );
    const zone = diagram.locator(`.react-flow__node-group[data-id="${id}"]`);
    await zone.click();
    let box = await zone.boundingBox();
    await page.mouse.move(box.x + box.width - 3, box.y + box.height - 3);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width + 260, box.y + box.height + 220, { steps: 10 });
    await page.mouse.up();
    await page.waitForTimeout(150);
    await diagram.locator('.react-flow__pane').click({ position: { x: 5, y: 5 } });
    box = await zone.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2 + 150, box.y + box.height / 2 + 170, { steps: 12 });
    await page.mouse.up();
    await page.waitForTimeout(150);
    return id;
};

const saveDoc = async (page) => {
    await Promise.all([
        page.waitForResponse((r) => /\/documents\/\d+/.test(r.url()) && r.request().method() === 'PATCH'),
        page.getByRole('button', { name: 'Save', exact: true }).click(),
    ]);
};

test('a zone nests inside another, moves as a unit, and survives a reload', async ({ page }) => {
    test.setTimeout(90_000);
    const stamp = Date.now();
    await createDoc(page, `Diagram Nesting WS ${stamp}`, `Nesting ${stamp}`);
    const diagram = await insertDiagram(page);
    const groups = diagram.locator('.react-flow__node-group');

    const aId = await addContainerZone(page, diagram);
    const A = diagram.locator(`.react-flow__node-group[data-id="${aId}"]`);

    // Child zone B spawns clear; drag it well inside A.
    await diagram.getByRole('button', { name: 'Zone', exact: true }).click();
    await expect(groups).toHaveCount(2);
    const bId = await groups.evaluateAll((els, aid) => els.map((e) => e.getAttribute('data-id')).find((x) => x !== aid), aId);
    const B = diagram.locator(`.react-flow__node-group[data-id="${bId}"]`);
    const aBox = await A.boundingBox();
    const bBox = await B.boundingBox();
    await dragCenter(page, B, (aBox.x + 30 + bBox.width / 2) - (bBox.x + bBox.width / 2), (aBox.y + 30 + bBox.height / 2) - (bBox.y + bBox.height / 2));

    // Moving A carries B.
    const bBefore = await B.boundingBox();
    await dragZone(page, A, -100, -80);
    const bAfter = await B.boundingBox();
    expect(Math.abs((bAfter.x - bBefore.x) - -100)).toBeLessThan(25);
    expect(Math.abs((bAfter.y - bBefore.y) - -80)).toBeLessThan(25);

    // Persist: save + reload, nesting still holds.
    await saveDoc(page);
    await page.reload();
    await expect(diagram).toBeVisible();
    await expect(groups).toHaveCount(2);
    const A2 = diagram.locator(`.react-flow__node-group[data-id="${aId}"]`);
    const B2 = diagram.locator(`.react-flow__node-group[data-id="${bId}"]`);
    const b2Before = await B2.boundingBox();
    await dragZone(page, A2, 90, 70);
    const b2After = await B2.boundingBox();
    expect(Math.abs((b2After.x - b2Before.x) - 90)).toBeLessThan(25);
    expect(Math.abs((b2After.y - b2Before.y) - 70)).toBeLessThan(25);
});

test('deleting a mid-tree zone re-homes its child to the nearest surviving ancestor', async ({ page }) => {
    test.setTimeout(120_000);
    const stamp = Date.now();
    await createDoc(page, `Diagram MidDelete WS ${stamp}`, `MidDelete ${stamp}`);
    const diagram = await insertDiagram(page);
    const groups = diagram.locator('.react-flow__node-group');

    const aId = await addContainerZone(page, diagram);
    const A = diagram.locator(`.react-flow__node-group[data-id="${aId}"]`);

    // Zone B inside A.
    await diagram.getByRole('button', { name: 'Zone', exact: true }).click();
    const bId = await groups.evaluateAll((els, aid) => els.map((e) => e.getAttribute('data-id')).find((x) => x !== aid), aId);
    const B = diagram.locator(`.react-flow__node-group[data-id="${bId}"]`);
    let aBox = await A.boundingBox();
    let bBox = await B.boundingBox();
    await dragCenter(page, B, (aBox.x + 30 + bBox.width / 2) - (bBox.x + bBox.width / 2), (aBox.y + 30 + bBox.height / 2) - (bBox.y + bBox.height / 2));

    // Device node D inside B (innermost zone).
    await diagram.getByRole('button', { name: 'Node', exact: true }).click();
    const D = diagram.locator('.react-flow__node-labeled').last();
    bBox = await B.boundingBox();
    const dBox = await D.boundingBox();
    await dragCenter(page, D, (bBox.x + 20 + dBox.width / 2) - (dBox.x + dBox.width / 2), (bBox.y + 20 + dBox.height / 2) - (dBox.y + dBox.height / 2));

    // Delete the middle zone B.
    await B.click();
    await page.keyboard.press('Delete');
    await page.waitForTimeout(300);
    await expect(groups).toHaveCount(1);
    await expect(diagram.locator('.react-flow__node-labeled')).toHaveCount(1);

    // D re-homed to A: moving A (grabbed by header, clear of D) still carries D.
    const dBefore = await D.boundingBox();
    const a2 = await A.boundingBox();
    await page.mouse.move(a2.x + 15, a2.y + 6);
    await page.mouse.down();
    await page.mouse.move(a2.x + 15 - 100, a2.y + 6 - 80, { steps: 15 });
    await page.mouse.up();
    await page.waitForTimeout(300);
    const dAfter = await D.boundingBox();
    expect(Math.abs((dAfter.x - dBefore.x) - -100)).toBeLessThan(22);
    expect(Math.abs((dAfter.y - dBefore.y) - -80)).toBeLessThan(22);
});

test('a locked element cannot be dragged, persists locked, and unlocks', async ({ page }) => {
    test.setTimeout(90_000);
    const stamp = Date.now();
    await createDoc(page, `Diagram Lock WS ${stamp}`, `Lock ${stamp}`);
    const diagram = await insertDiagram(page);

    const addNode = diagram.getByRole('button', { name: 'Node', exact: true });
    const nodes = diagram.locator('.react-flow__node-labeled');
    await addNode.click();
    await addNode.click();
    await expect(nodes).toHaveCount(2);
    const R = nodes.nth(0);   // free reference
    const T = nodes.nth(1);   // target to lock

    const offset = async () => {
        const r = await R.boundingBox();
        const t = await T.boundingBox();
        return { x: t.x - r.x, y: t.y - r.y };
    };

    // Move T down so its top-positioned toolbar isn't clipped, then lock it.
    await dragCenter(page, T, 40, 150);
    await T.click();
    await diagram.getByRole('button', { name: 'Lock position' }).click();
    // While the node is selected the at-rest lock badge is hidden (the resize
    // handle occupies that corner), so the toolbar toggle flips to "Unlock
    // position" to signal the locked state. The badge itself is asserted at rest
    // (nothing selected) after the reload below.
    await expect(diagram.getByRole('button', { name: 'Unlock position' })).toBeVisible();

    // Locked: T's offset from R is unchanged after a drag (only a pan happened).
    const o1 = await offset();
    await dragCenter(page, T, 120, 90);
    const o2 = await offset();
    expect(Math.abs(o2.x - o1.x)).toBeLessThan(8);
    expect(Math.abs(o2.y - o1.y)).toBeLessThan(8);

    // Persists across reload.
    await saveDoc(page);
    await page.reload();
    await expect(diagram).toBeVisible();
    await expect(nodes).toHaveCount(2);
    // At rest (nothing selected after reload) the lock badge is shown.
    await expect(T.getByTitle('Position locked')).toBeVisible();
    const o3 = await offset();
    await dragCenter(page, T, 100, 80);
    const o4 = await offset();
    expect(Math.abs(o4.x - o3.x)).toBeLessThan(8);

    // Unlock → draggable again: T now moves relative to R (magnitude, not exact
    // vector, to stay robust under snap-to-grid).
    await T.click();
    await diagram.getByRole('button', { name: 'Unlock position' }).click();
    const o5 = await offset();
    await dragCenter(page, T, 90, 60);
    const o6 = await offset();
    expect(Math.hypot(o6.x - o5.x, o6.y - o5.y)).toBeGreaterThan(30);
});

test('the diagram editor maximizes to full viewport and restores', async ({ page }) => {
    test.setTimeout(90_000);
    const stamp = Date.now();
    await createDoc(page, `Diagram Maximize WS ${stamp}`, `Maximize ${stamp}`);
    const diagram = await insertDiagram(page);

    const addNode = diagram.getByRole('button', { name: 'Node', exact: true });
    await addNode.click();
    await addNode.click();
    const nodes = diagram.locator('.react-flow__node-labeled');
    await expect(nodes).toHaveCount(2);

    const artifact = diagram.locator('.diagram-artifact');
    const vp = page.viewportSize();

    const inlineBox = await artifact.boundingBox();
    expect(inlineBox.height).toBeLessThan(vp.height - 50);

    // Maximize → fills the viewport, same canvas instance (nodes survive).
    await diagram.getByRole('button', { name: 'Enter full screen' }).click();
    await page.waitForTimeout(200);
    const maxBox = await artifact.boundingBox();
    expect(Math.abs(maxBox.width - vp.width)).toBeLessThan(4);
    expect(Math.abs(maxBox.height - vp.height)).toBeLessThan(4);
    await expect(nodes).toHaveCount(2);
    await expect(diagram.getByText('Loading diagram…')).toHaveCount(0);
    expect(await page.evaluate(() => document.body.style.overflow)).toBe('hidden');

    // Esc restores inline + unlocks scroll.
    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);
    expect((await artifact.boundingBox()).height).toBeLessThan(vp.height - 50);
    expect(await page.evaluate(() => document.body.style.overflow)).not.toBe('hidden');

    // Minimize button also collapses it.
    await diagram.getByRole('button', { name: 'Enter full screen' }).click();
    await expect(diagram.getByRole('button', { name: 'Exit full screen' })).toBeVisible();
    await diagram.getByRole('button', { name: 'Exit full screen' }).click();
    await page.waitForTimeout(150);
    expect((await artifact.boundingBox()).height).toBeLessThan(vp.height - 50);
    await expect(nodes).toHaveCount(2);
});

test('a left-drag on empty canvas marquee-selects nodes (no Shift needed)', async ({ page }) => {
    test.setTimeout(90_000);
    const stamp = Date.now();
    await createDoc(page, `Diagram Marquee WS ${stamp}`, `Marquee ${stamp}`);
    const diagram = await insertDiagram(page);

    const addNode = diagram.getByRole('button', { name: 'Node', exact: true });
    const nodes = diagram.locator('.react-flow__node-labeled');
    await addNode.click();
    await addNode.click();
    await addNode.click();
    await expect(nodes).toHaveCount(3);

    // Nothing selected yet, so the align cluster (rendered only for a 2+ selection)
    // is absent.
    await expect(diagram.getByRole('button', { name: 'Align left edges' })).toHaveCount(0);

    // A plain left-drag starting on empty canvas now draws a marquee instead of
    // panning. Sweep from the empty top-right corner across the centred nodes to
    // the bottom-left, clamped to the visible viewport — the pane can extend below
    // the fold, and a start/end point off-screen wouldn't register a real drag.
    const pane = diagram.locator('.react-flow__pane');
    const p = await pane.boundingBox();
    const vp = page.viewportSize();
    const sx = p.x + p.width - 40, sy = p.y + 45;
    const ex = p.x + 40, ey = Math.min(p.y + p.height, vp.height) - 25;
    await dragFrom(page, sx, sy, ex - sx, ey - sy);

    // The sweep intersects every node, so all three select and the align/distribute
    // controls appear — confirming marquee-on-drag without holding Shift.
    await expect(diagram.locator('.react-flow__node-labeled.selected')).toHaveCount(3);
    await expect(diagram.getByRole('button', { name: 'Align left edges' })).toBeVisible();

    // The per-node options panel is per-ITEM, so a 3-node selection must not stack
    // three of them; it returns once the selection narrows back to a single node.
    await expect(diagram.getByRole('textbox', { name: 'Node name' })).toHaveCount(0);
    await nodes.first().click();
    await expect(diagram.getByRole('textbox', { name: 'Node name' })).toBeVisible();
});
