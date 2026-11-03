// Shared flat-tree helpers for the dnd-kit projection reorder used by the
// workspaces index (groups + workspaces) and the workspace page tree (folders +
// pages + subpages). Both flatten a forest into a positional list, hide a dragged
// node's descendants, and rebuild the forest after a drop — this is the part that
// is identical between the two surfaces. What differs (the depth-capped vs
// arbitrary-depth projection, and each surface's save payload) stays local to the
// page that owns those rules.

/** Flatten a forest into `{ id, parentId, depth, node }` rows, depth-first. */
export function flattenForDnd(nodes, parentId = null, depth = 0) {
    return nodes.flatMap((node) => [
        { id: node.id, parentId, depth, node },
        ...flattenForDnd(node.children ?? [], node.id, depth + 1),
    ]);
}

/** Every id reachable below `id` in the flat list (a dragged block hides its members). */
export function getDescendantIds(flat, id) {
    const out = [];
    const stack = [id];
    while (stack.length) {
        const current = stack.pop();
        for (const item of flat) {
            if (item.parentId === current) { out.push(item.id); stack.push(item.id); }
        }
    }
    return out;
}

/**
 * Rebuild the forest from a flat list, preserving sibling order. Rows flagged
 * `node.__placeholder` (empty-container drop targets) are dropped — they only
 * exist for the DnD, never in the persisted tree.
 */
export function buildTree(flat) {
    const real = flat.filter((item) => !item.node.__placeholder);
    const builtById = new Map(real.map((item) => [item.id, { ...item.node, children: [] }]));
    const roots = [];
    for (const item of real) {
        const built = builtById.get(item.id);
        const parent = item.parentId != null ? builtById.get(item.parentId) : null;
        (parent ? parent.children : roots).push(built);
    }
    return roots;
}
