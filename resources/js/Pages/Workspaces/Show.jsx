import React, { useState, useEffect, useMemo, useRef } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import { IconChevronRight, IconDots, IconFileText, IconFolder, IconFolderPlus, IconGripVertical, IconPencil, IconPlus, IconStar, IconStarFilled, IconTrash, IconUpload, IconFileImport, IconCheck, IconCornerDownRight } from '@tabler/icons-react';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { flattenForDnd, getDescendantIds, buildTree } from '@/lib/dndTree';
import {
    DndContext,
    PointerSensor,
    useSensor,
    useSensors,
    closestCenter,
} from '@dnd-kit/core';
import {
    SortableContext,
    useSortable,
    arrayMove,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import DocsLayout from '@/Layouts/DocsLayout';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import NewPageModal from '@/components/ui/NewPageModal';
import WorkspaceFormModal from '@/components/ui/WorkspaceFormModal';
import FolderFormModal from '@/components/ui/FolderFormModal';
import ImportDialog from '@/components/ui/ImportDialog';
import InlineEditableTitle from '@/components/ui/InlineEditableTitle';
import { can } from '@/lib/permissions';

const INDENT = 20; // px of left padding per nesting level

// A folder's member pages draw one level deeper than loose top-level pages. This
// is the whole visual distinction between "filed in this folder" and "sitting
// below it at the top level" — without it the two are pixel-identical and you
// cannot tell what belongs to what (the exact bug the Workspaces index hit).
// Reusing the tree's own depth scale means the guide spines line up for free.
const FOLDER_MEMBER_DEPTH = 1;

// ── Tree <-> flat-list helpers ──────────────────────────────────────────────
// flattenForDnd / getDescendantIds / buildTree are shared with the workspaces
// index (see @/lib/dndTree). The projection below is page-tree-specific (arbitrary
// nesting depth), so it stays local.

/** Where would the dragged row land — its depth and new parent — given the cursor offset. */
function getProjection(items, activeId, overId, dragOffset, indent) {
    const overIndex = items.findIndex((i) => i.id === overId);
    const activeIndex = items.findIndex((i) => i.id === activeId);
    if (overIndex === -1 || activeIndex === -1) return null;

    const activeItem = items[activeIndex];
    const newItems = arrayMove(items, activeIndex, overIndex);
    const prevItem = newItems[overIndex - 1];
    const nextItem = newItems[overIndex + 1];

    const projectedDepth = activeItem.depth + Math.round(dragOffset / indent);
    const maxDepth = prevItem ? prevItem.depth + 1 : 0;
    const minDepth = nextItem ? nextItem.depth : 0;
    const depth = Math.max(minDepth, Math.min(projectedDepth, maxDepth));

    const parentId = (() => {
        if (depth === 0 || !prevItem) return null;
        if (depth === prevItem.depth) return prevItem.parentId;
        if (depth > prevItem.depth) return prevItem.id;
        return newItems.slice(0, overIndex).reverse().find((i) => i.depth === depth)?.parentId ?? null;
    })();

    return { depth, parentId };
}

// ── Folder-aware reorder model ──────────────────────────────────────────────
//
// In reorder mode the tree is folder-aware: folders become depth-0 pseudo-nodes
// (`__folder`) whose children are their member pages, interleaved with loose
// pages in one shared order. This lets the EXISTING projection DnD move folder
// blocks, reorder members, and nest pages, on one flat list — the pseudo-node's
// children ride along on rebuild, so a folder moves as a block for free.

/**
 * The workspace's top level in its shared order: folders and loose root pages
 * interleaved by `position` (a loose page can sit between two folders). Returns
 * `{ kind: 'folder', folder, members } | { kind: 'page', node }` entries, plus a
 * `byFolder`/`loose` split for callers that want the raw buckets. ONE source of
 * truth for both the reading view and the reorder tree, so they never disagree.
 */
function buildTopLevel(folders, rootNodes) {
    const byFolder = new Map(folders.map((f) => [f.id, []]));
    const loose = [];
    for (const n of rootNodes) {
        if (n.folder_id != null && byFolder.has(n.folder_id)) byFolder.get(n.folder_id).push(n);
        else loose.push(n);
    }
    const entries = [
        ...folders.map((f) => ({ kind: 'folder', position: f.position, folder: f, members: byFolder.get(f.id) ?? [] })),
        ...loose.map((n) => ({ kind: 'page', position: n.position, node: n })),
    ];
    // Shared position space once saved; before the first save the two spaces are
    // independent, so break ties folder-first for a stable, predictable order.
    entries.sort((a, b) => (a.position - b.position) || ((a.kind === 'folder' ? 0 : 1) - (b.kind === 'folder' ? 0 : 1)));
    return { entries, byFolder, loose };
}

/** Merge folders (as pseudo-nodes) and loose root pages into one ordered forest. */
function buildReorderTree(folders, rootNodes) {
    return buildTopLevel(folders, rootNodes).entries.map((e) => (
        e.kind === 'folder'
            ? { id: `folder-${e.folder.id}`, __folder: true, folderId: e.folder.id, title: e.folder.name, position: e.position, tags: [], children: e.members }
            : { ...e.node }
    ));
}

/**
 * Turn the final reorder forest into the folder-order payload. Pure, so it can be
 * reasoned about and unit-tested without the DnD:
 *   items    — depth-0 order (folders + loose pages)
 *   folders  — each folder with its ordered direct children, tagged by folder id
 *              so a member's destination folder rides the save (a within-folder
 *              drag keeps it; cross-folder drag — M3 — will change it)
 *   subtrees — every page nested under a real page (parent_id + sibling position)
 *   newFolders — folders CREATED this Edit session (a `__new` pseudo-node with a
 *              negative temp id); the save creates them and maps the temp id to a
 *              real one. Deferring the create to here is what lets Cancel discard a
 *              new folder — nothing was sent until Done.
 */
function deriveReorderPayload(reorderTree) {
    const items = [];
    const folders = [];
    const subtrees = [];
    const newFolders = [];
    const collectSubtrees = (node) => {
        (node.children ?? []).forEach((child, i) => {
            subtrees.push({ id: child.id, parent_id: node.id, position: i });
            collectSubtrees(child);
        });
    };
    for (const top of reorderTree) {
        if (top.__folder) {
            items.push({ type: 'folder', id: top.folderId });
            const members = [];
            (top.children ?? []).forEach((m) => { members.push(m.id); collectSubtrees(m); });
            folders.push({ id: top.folderId, members });
            if (top.__new) newFolders.push({ id: top.folderId, name: top.title });
        } else {
            items.push({ type: 'page', id: top.id });
            collectSubtrees(top);
        }
    }
    return { items, folders, subtrees, newFolders };
}

/**
 * Is a tentative reorder legal? A page may move freely between loose ↔ a folder
 * member ↔ a subpage in one gesture — the folder-order save clears parent_id and
 * folder_id atomically, so un-nesting a subpage to the top level (or filing it
 * straight into a folder) lands in a single "Done". The one structural invariant
 * the DnD can still violate is nesting a FOLDER: folders are top-level only.
 * (handleDragEnd already pins a dragged folder to the top level; this backstops
 * the rebuilt payload against any other route to a nested folder.)
 */
function isValidReorder(flat) {
    for (const item of flat) {
        if (item.node.__folder && item.parentId != null) return false;
    }
    return true;
}

// ── Static helpers (filtered view, modal options) ───────────────────────────

function collectAllTags(nodes) {
    const map = new Map();
    function walk(n) {
        n.tags.forEach((t) => map.set(t.id, t));
        n.children.forEach(walk);
    }
    nodes.forEach(walk);
    return [...map.values()].sort((a, b) => a.name.localeCompare(b.name));
}

function flattenAll(nodes, depth = 0) {
    return nodes.flatMap((n) => [{ ...n, depth }, ...flattenAll(n.children, depth + 1)]);
}

/**
 * Total pages in a set of subtrees — every node plus all its descendants. A
 * folder's badge uses this over a bare `members.length` because the folder
 * section renders subpages inline, and the workspace header counts totals too;
 * counting only direct members would undercount what the folder visibly holds.
 */
function countPages(nodes) {
    return nodes.reduce((sum, n) => sum + 1 + countPages(n.children ?? []), 0);
}

function flattenOptions(nodes, depth = 0, acc = []) {
    for (const node of nodes) {
        acc.push({ id: node.id, label: `${'  '.repeat(depth)}${node.title}` });
        flattenOptions(node.children, depth + 1, acc);
    }
    return acc;
}

// ── Row components ──────────────────────────────────────────────────────────

/**
 * For each row, the "is last child" flag of every node on its path root→row.
 * The tree guides use it to draw spines that stop at the last child (└) and skip
 * levels whose ancestor was itself a last child.
 *
 * Computed per sibling-set, so it is also called PER FOLDER SECTION: a folder's
 * members are their own group, and "last member of this folder" is what makes its
 * spine stop — "last root page in the workspace" would be the wrong question.
 */
function computeGuideFlags(nodes) {
    const flat = flattenForDnd(nodes);
    const byId = new Map(flat.map((i) => [i.id, i]));
    const groups = new Map();
    for (const it of flat) {
        const k = it.parentId ?? 'root';
        if (!groups.has(k)) groups.set(k, []);
        groups.get(k).push(it);
    }
    const lastSet = new Set();
    for (const arr of groups.values()) lastSet.add(arr[arr.length - 1].id);
    const map = new Map();
    for (const it of flat) {
        const flags = [];
        for (let cur = it; cur; cur = cur.parentId != null ? byId.get(cur.parentId) : null) {
            flags.unshift(lastSet.has(cur.id));
        }
        map.set(it.id, flags);   // index k = isLast of the path node at depth k
    }
    return map;
}

function TagPill({ name, active }) {
    return (
        <span className={`shrink-0 text-[10px] font-medium px-1.5 py-0.5 rounded-md ${
            active ? 'bg-accent-100 text-accent-600' : 'bg-surface border border-border text-text-secondary'
        }`}>
            {name}
        </span>
    );
}

function GripHandle({ listeners, attributes }) {
    return (
        <button
            type="button"
            {...listeners}
            {...attributes}
            tabIndex={-1}
            aria-label="Drag to move or nest"
            className="flex h-5 w-4 shrink-0 cursor-grab items-center justify-center text-text-tertiary opacity-0 transition-opacity group-hover:opacity-100 focus:opacity-100 active:cursor-grabbing"
        >
            <IconGripVertical className="h-3.5 w-3.5" stroke={1.5} />
        </button>
    );
}

function ActionButton({ onClick, href, title, children }) {
    const cls = "flex h-6 w-6 items-center justify-center rounded-sm border border-transparent text-text-tertiary opacity-0 transition-all duration-150 group-hover:opacity-100 group-hover:border-border hover:bg-accent-50 hover:border-accent-200 hover:text-accent-600";
    return href
        ? <Link href={href} title={title} onClick={(e) => e.stopPropagation()} className={cls}>{children}</Link>
        : <button type="button" onClick={onClick} title={title} className={cls}>{children}</button>;
}

function RowActions({ node, onAddChild, onImport }) {
    // Create aids only — filing into a folder is now a drag (the "Move to folder"
    // menu was removed once Edit-mode drag covered it).
    return (
        <div className="flex items-center justify-end gap-1">
            <ActionButton onClick={() => onImport(node.id)} title="Import as subpage">
                <IconFileImport className="h-3.5 w-3.5" stroke={1.5} />
            </ActionButton>
            <ActionButton onClick={() => onAddChild(node.id)} title="Add subpage">
                <IconPlus className="h-3.5 w-3.5" stroke={1.5} />
            </ActionButton>
        </div>
    );
}

/**
 * Per-user star toggle on a tree row. Hover-revealed like the row actions,
 * but a starred page keeps its (filled) star visible — that's the point.
 */
function StarButton({ node, starred }) {
    return (
        <button
            type="button"
            onClick={() => router.post(`/documents/${node.id}/star`, {}, { preserveScroll: true, preserveState: true })}
            title={starred ? 'Unstar' : 'Star'}
            aria-pressed={starred}
            className={`flex h-6 w-6 items-center justify-center rounded-sm border border-transparent transition-all duration-150 group-hover:border-border hover:bg-accent-50 hover:border-accent-200 ${
                starred
                    ? 'text-warning opacity-100'
                    : 'text-text-tertiary opacity-0 group-hover:opacity-100 hover:text-warning'
            }`}
        >
            {starred
                ? <IconStarFilled className="h-3.5 w-3.5" />
                : <IconStar className="h-3.5 w-3.5" stroke={1.5} />}
        </button>
    );
}

// ── Tree guides (see .examples/tree-view-unlimited-nesting.webp) ─────────────
// The drag handle lives in its own fixed gutter at the far left (GRIP_GUTTER wide),
// OUTSIDE the tree indent, so the spines and branches never cross it. Within the
// content, each level's node (icon/dot) sits at NODE_X + depth*INDENT from the row's
// left edge: pl-3 (12) + grip gutter (20) + half node (8) = 40 at depth 0. A level's
// spine drops straight down UNDER its parent's node and branches one INDENT right into
// each child's node, so the whole thing reads as one continuous line with rounded turns.
const GUIDE = 'border-text-tertiary/55';   // line colour: visible on cream, not loud
const GUIDE_R = 7;            // corner radius where the lines turn
const NODE_X = 40;            // x of a depth-0 node (centre) from the row's left edge
const GUIDE_REACH = INDENT - 6; // branch length: spine across to the child icon's left edge
const GRIP_GUTTER = 'w-5';    // fixed drag-handle gutter (20px) — keep in sync with NODE_X
// Rows carry a 1px border; every vertical segment bleeds GUIDE_BLEED past the row edge(s)
// so neighbouring slices overlap across that hairline and read as one continuous line.
const GUIDE_BLEED = 1;

function TreeRow({ id, depth, node, activeTagId, onAddChild, onImport, onRename, canCreate, canReorder, ghost, dragging, pathLast, isDropParent, starred = false, isReordering = false, isRootPage }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
        useSortable({ id, disabled: !canReorder });

    // "Is a root page" is a fact about the document, not about where it's drawn:
    // inside a folder section a root page renders at visual depth 1, so deriving
    // this from `depth` would demote every filed page to subpage styling and hide
    // its refile menu. Callers that don't group can let it default.
    const isRoot = isRootPage ?? (depth === 0);
    const hasChildren = (node.children?.length ?? 0) > 0;

    return (
        <li
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition, opacity: ghost || isDragging ? 0.4 : 1 }}
            className={`group relative grid grid-cols-[1fr_110px_96px] items-center border-b border-border-subtle last:border-0 transition-colors ${
                isDropParent ? 'bg-accent-50 ring-1 ring-inset ring-accent-300' : 'hover:bg-surface-hover/60'
            }`}
        >
            {/* Tree guides on a full-height layer anchored to the <li> (not the
                vertically-centred content cell, which was the source of the gaps). Each
                level's spine sits UNDER its parent's node: the row's own spine drops from
                the top to its centre and rounds right into its node with a ╰, carrying on
                down only if it isn't the last child. A page with children drops a spine
                straight from under its own node to its first child. Ancestor spines pass
                straight through while that ancestor still has siblings below. Every
                segment bleeds GUIDE_BLEED past the 1px row borders so the per-row slices
                overlap and read as one continuous line. Hidden mid-drag: depths and the
                last-child flags shift while editing, which would paint stray segments. */}
            {!dragging && (depth > 0 || hasChildren) && (
                <span aria-hidden className="pointer-events-none absolute inset-0">
                    {depth > 0 && Array.from({ length: depth }).map((_, i) => {
                        const x = NODE_X + i * INDENT;   // spine under the depth-(i+1) node
                        const continues = pathLast ? !pathLast[i + 1] : true;
                        if (i === depth - 1) {
                            return (
                                <React.Fragment key={i}>
                                    {/* ╰: spine down to the centre, rounding right into this node */}
                                    <span className={`absolute border-l border-b ${GUIDE} rounded-bl-[7px]`} style={{ left: x, top: -GUIDE_BLEED, height: `calc(50% + ${GUIDE_BLEED}px)`, width: GUIDE_REACH }} />
                                    {/* carry the spine down to the next sibling */}
                                    {continues && <span className={`absolute border-l ${GUIDE}`} style={{ left: x, top: `calc(50% - ${GUIDE_R}px)`, bottom: -GUIDE_BLEED }} />}
                                </React.Fragment>
                            );
                        }
                        return continues ? <span key={i} className={`absolute border-l ${GUIDE}`} style={{ left: x, top: -GUIDE_BLEED, bottom: -GUIDE_BLEED }} /> : null;
                    })}
                    {hasChildren && (
                        /* spine dropping to the first child — starts just below this row's
                           own icon (8px = half the 16px icon) so it doesn't run through it */
                        <span className={`absolute border-l ${GUIDE}`} style={{ left: NODE_X + depth * INDENT, top: 'calc(50% + 8px)', bottom: -GUIDE_BLEED }} />
                    )}
                </span>
            )}
            <div className="relative flex min-w-0 items-center py-2.5 pr-4 pl-3">
                {/* Fixed drag-handle gutter, kept out of the tree indent so the guides
                    never cross it (empty but reserved when not editing). */}
                <span className={`flex ${GRIP_GUTTER} shrink-0 items-center justify-center`}>
                    {canReorder && <GripHandle listeners={listeners} attributes={attributes} />}
                </span>
                <div className="flex min-w-0 items-center gap-2" style={{ paddingLeft: `${depth * INDENT}px` }}>
                    {/* Every page is a document, so all rows share the file icon; the
                        rounded guide lines carry the hierarchy, and the branch stops at
                        the icon's left edge. */}
                    <IconFileText className="h-4 w-4 shrink-0 text-text-tertiary" stroke={1.5} />
                    {/* In Edit mode the title renames in place (no link — you're
                        organizing, not browsing); in the read view it navigates. */}
                    {isReordering ? (
                        <InlineEditableTitle
                            value={node.title}
                            onCommit={(t) => onRename(node, t)}
                            ariaLabel={`Rename ${node.title}`}
                            className={`text-sm ${isRoot ? 'font-medium text-foreground' : 'text-text-secondary'}`}
                            inputClassName="text-sm"
                        />
                    ) : (
                        <Link
                            href={`/documents/${node.id}`}
                            className={`truncate text-sm transition-colors group-hover:text-accent-600 ${isRoot ? 'font-medium text-foreground' : 'text-text-secondary'}`}
                            title={node.title}
                        >
                            {node.title}
                        </Link>
                    )}
                    {node.tags.slice(0, isRoot ? 2 : 1).map((t) => (
                        <TagPill key={t.id} name={t.name} active={activeTagId === t.id} />
                    ))}
                    {isDropParent && (
                        <span className="ml-1 inline-flex shrink-0 items-center gap-1 rounded-full bg-accent-100 px-1.5 py-0.5 text-[10px] font-medium text-accent-700">
                            <IconCornerDownRight className="h-3 w-3" stroke={1.5} />
                            New parent
                        </span>
                    )}
                </div>
            </div>
            <div className="py-2.5 pr-4">
                <span className="text-xs text-text-tertiary">{node.updated_at}</span>
            </div>
            <div className="flex items-center justify-end gap-1 py-2.5 pr-2">
                {/* Star + create aids (add/import subpage) are read-view affordances;
                    Edit mode is for organizing, so both hide there. */}
                {!dragging && !isReordering && <StarButton node={node} starred={starred} />}
                {canCreate && !isReordering && (
                    <RowActions node={node} onAddChild={onAddChild} onImport={onImport} />
                )}
            </div>
        </li>
    );
}

/**
 * A page folder: a container that is NOT a page. Its name is inert — it toggles
 * the section and links nowhere, because there is nothing to open. (This replaced
 * the "shelf" rendering, which faked a folder out of a top-level page that had
 * children; that container was a real page, so its title had to be a link.)
 *
 * The chevron sits in the grip gutter so the folder icon lands at NODE_X, the same
 * x as any depth-0 row, and the spine dropping to the first member lines up with
 * the tree guides. Members render one level deeper (see FOLDER_MEMBER_DEPTH), which
 * is what distinguishes them from loose top-level pages.
 */
function FolderHeaderRow({ folder, count, collapsed, onToggle, canCreate, onAddPage, onImport }) {
    return (
        <li className="group relative grid grid-cols-[1fr_110px_96px] items-center border-b border-border-subtle last:border-0 bg-surface-hover/50 transition-colors hover:bg-surface-hover">
            {/* Spine dropping to the first member (only when expanded) — same geometry
                as TreeRow's hasChildren guide so members connect under the folder. */}
            {!collapsed && count > 0 && (
                <span aria-hidden className="pointer-events-none absolute inset-0">
                    <span className={`absolute border-l ${GUIDE}`} style={{ left: NODE_X, top: 'calc(50% + 8px)', bottom: -GUIDE_BLEED }} />
                </span>
            )}
            <div className="relative flex min-w-0 items-center py-2 pr-4 pl-3">
                <span className={`flex ${GRIP_GUTTER} shrink-0 items-center justify-center`}>
                    <button
                        type="button"
                        onClick={onToggle}
                        aria-expanded={!collapsed}
                        aria-label={`${collapsed ? 'Expand' : 'Collapse'} ${folder.name}`}
                        className="flex h-5 w-5 items-center justify-center rounded-sm text-text-tertiary transition-colors hover:text-foreground"
                    >
                        <IconChevronRight className={`h-3.5 w-3.5 transition-transform ${collapsed ? '' : 'rotate-90'}`} stroke={1.5} />
                    </button>
                </span>
                {/* Not a link, and not a button either: the chevron owns the toggle, so
                    making the whole name clickable would just be a second hit target
                    for the same thing. A folder has nowhere to go. */}
                <div className="flex min-w-0 items-center gap-2">
                    <IconFolder className="h-4 w-4 shrink-0 text-text-tertiary" stroke={1.5} />
                    <span className="truncate text-sm font-semibold text-foreground" title={folder.name}>
                        {folder.name}
                    </span>
                    <span className="shrink-0 text-xs text-text-tertiary">({count})</span>
                </div>
            </div>
            <div className="py-2 pr-4" />
            {/* Same create aids a page row carries, aimed at the folder: without
                them the only way to fill a folder was to make a loose page and
                drag it in through Edit mode. */}
            <div className="flex items-center justify-end gap-1 py-2 pr-2">
                {canCreate && (
                    <>
                        <ActionButton onClick={() => onImport(folder.id)} title={`Import into ${folder.name}`}>
                            <IconFileImport className="h-3.5 w-3.5" stroke={1.5} />
                        </ActionButton>
                        <ActionButton onClick={() => onAddPage(folder.id)} title={`Add page to ${folder.name}`}>
                            <IconPlus className="h-3.5 w-3.5" stroke={1.5} />
                        </ActionButton>
                    </>
                )}
            </div>
        </li>
    );
}

/**
 * A folder header while in EDIT mode: draggable by its grip (moves as a block, its
 * members riding along on rebuild), title renames in place, and a delete button —
 * folder management lives here now, not in a read-view ⋯ menu.
 */
function EditFolderRow({ id, name, count, ghost, isDropTarget, canManage, onRename, onDelete, collapsed, onToggleCollapse }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
        useSortable({ id });

    return (
        <li
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition, opacity: ghost || isDragging ? 0.4 : 1 }}
            className={`group relative grid grid-cols-[1fr_110px_96px] items-center border-b border-border-subtle last:border-0 transition-colors ${
                isDropTarget ? 'bg-accent-50 ring-1 ring-inset ring-accent-300' : 'bg-surface-hover/50'
            }`}
        >
            <div className="flex min-w-0 items-center py-2 pr-4 pl-3">
                <span className={`flex ${GRIP_GUTTER} shrink-0 items-center justify-center`}>
                    <GripHandle listeners={listeners} attributes={attributes} />
                </span>
                <div className="flex min-w-0 flex-1 items-center gap-2">
                    {/* Collapse hides the members so the tree is short and easy to
                        reorder; the folder still drags as a block either way. */}
                    <button
                        type="button"
                        onClick={onToggleCollapse}
                        aria-expanded={!collapsed}
                        aria-label={`${collapsed ? 'Expand' : 'Collapse'} ${name}`}
                        className="flex h-5 w-4 shrink-0 items-center justify-center text-text-tertiary transition-colors hover:text-foreground"
                    >
                        <IconChevronRight className={`h-3.5 w-3.5 transition-transform ${collapsed ? '' : 'rotate-90'}`} stroke={1.5} />
                    </button>
                    <IconFolder className="h-4 w-4 shrink-0 text-text-tertiary" stroke={1.5} />
                    {canManage ? (
                        <InlineEditableTitle
                            value={name}
                            onCommit={onRename}
                            ariaLabel={`Rename ${name}`}
                            className="text-sm font-semibold text-foreground"
                            inputClassName="text-sm font-semibold"
                        />
                    ) : (
                        <span className="truncate text-sm font-semibold text-foreground" title={name}>{name}</span>
                    )}
                    <span className="shrink-0 text-xs text-text-tertiary">({count})</span>
                    {isDropTarget && (
                        <span className="ml-1 inline-flex shrink-0 items-center gap-1 rounded-full bg-accent-100 px-1.5 py-0.5 text-[10px] font-medium text-accent-700">
                            <IconCornerDownRight className="h-3 w-3" stroke={1.5} />
                            Into folder
                        </span>
                    )}
                </div>
            </div>
            <div className="py-2 pr-4" />
            <div className="flex items-center justify-end py-2 pr-2">
                {canManage && (
                    <button
                        type="button"
                        onClick={onDelete}
                        aria-label={`Delete ${name}`}
                        className="flex h-7 w-7 items-center justify-center rounded-sm text-text-tertiary opacity-0 transition-colors group-hover:opacity-100 focus:opacity-100 hover:text-danger"
                    >
                        <IconTrash className="h-4 w-4" stroke={1.5} />
                    </button>
                )}
            </div>
        </li>
    );
}

/**
 * A drop target rendered inside an EMPTY folder while editing, so a page can be
 * dragged into a folder that has no members yet. It is a sortable (a valid drop
 * `over`) but carries no grip, so it can never be picked up; buildTree ignores it.
 * Indented to the folder's interior (FOLDER_MEMBER_DEPTH), like a real member.
 */
function EmptyFolderDropRow({ id, isDropTarget }) {
    const { setNodeRef } = useSortable({ id });
    return (
        <li ref={setNodeRef} className={`grid grid-cols-[1fr_110px_96px] items-center border-b border-border-subtle last:border-0 ${isDropTarget ? 'bg-accent-50' : ''}`}>
            <div className="py-2.5 pr-4" style={{ paddingLeft: `${12 + 20 + FOLDER_MEMBER_DEPTH * INDENT}px` }}>
                <span className="text-xs italic text-text-tertiary">Drop a page here</span>
            </div>
            <div /><div />
        </li>
    );
}

function FilteredRow({ node }) {
    return (
        <li className="group grid grid-cols-[1fr_110px_96px] items-center border-b border-border-subtle last:border-0 transition-colors hover:bg-surface-hover/60">
            <div className="flex min-w-0 items-center gap-2 py-3 pl-4 pr-4">
                <IconFileText className="h-4 w-4 shrink-0 text-text-tertiary" stroke={1.5} />
                <Link href={`/documents/${node.id}`} className="truncate text-sm font-medium text-foreground transition-colors group-hover:text-accent-600" title={node.title}>
                    {node.title}
                </Link>
                {node.tags.map((t) => <TagPill key={t.id} name={t.name} active={true} />)}
            </div>
            <div className="py-3 pr-4"><span className="text-xs text-text-tertiary">{node.updated_at}</span></div>
            <div />
        </li>
    );
}

// ── Page ────────────────────────────────────────────────────────────────────

export default function WorkspaceShow({ workspace, tree, folders = [], templates = [], starredIds = [] }) {
    const { auth } = usePage().props;
    const perms = can(auth);
    const starredSet = useMemo(() => new Set(starredIds), [starredIds]);
    const [rootNodes, setRootNodes] = useState(tree);
    const [activeTag, setActiveTag] = useState(null);

    // Collapsed folder sections, by folder id. Per-device, per-workspace — same
    // treatment as the group sections on the Workspaces index, and the theme.
    const folderKey = `wwwdoc:wsfolders:${workspace.id}`;
    const [collapsedFolders, setCollapsedFolders] = useState(() => {
        try { return new Set(JSON.parse(localStorage.getItem(folderKey) || '[]')); }
        catch { return new Set(); }
    });
    function toggleFolder(id) {
        setCollapsedFolders((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            localStorage.setItem(folderKey, JSON.stringify([...next]));
            return next;
        });
    }
    const [folderModal, setFolderModal] = useState({ open: false, folder: null });
    const [folderToDelete, setFolderToDelete] = useState(null);
    const [modalOpen, setModalOpen] = useState(false);
    const [modalParentId, setModalParentId] = useState('');
    const [modalFolderId, setModalFolderId] = useState('');
    const [importOpen, setImportOpen] = useState(false);
    const [importParentId, setImportParentId] = useState('');
    const [importFolderId, setImportFolderId] = useState('');
    const [importFiles, setImportFiles] = useState(null);
    const [dropActive, setDropActive] = useState(false);
    const [pendingImportJobs, setPendingImportJobs] = useState([]);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);

    // Reorder is an explicit mode (like edit mode): off by default so a stray
    // drag can't rearrange the tree, toggled on to expose the drag handles.
    // Drags only mutate local state; the tree is persisted once, on "Done".
    const [editing, setEditing] = useState(false);
    const editDirty = useRef(false); // any unsaved edit (drag OR rename) — drives the guard + Done
    const dragDirty = useRef(false); // drags specifically — gates the structural order save
    // Folder-aware forest the DnD mutates while editing (folders as depth-0
    // pseudo-nodes; see buildReorderTree). Separate from rootNodes so the normal
    // view keeps its plain tree. Empty when not editing.
    const [editNodes, setEditNodes] = useState([]);
    // Pending inline renames (key `f:${id}` folder / `p:${id}` page / `ws` the
    // workspace → new name), applied optimistically and persisted on Done — so
    // Cancel discards them (mirrors how dragged order is pending). A ref shadows the
    // state so the async save reads the latest map. `editDirty` covers renames too.
    const [pendingRenames, setPendingRenames] = useState({});
    const pendingRenamesRef = useRef({});
    const setRenames = (next) => { pendingRenamesRef.current = next; setPendingRenames(next); };

    // Folders created in Edit mode are PENDING — added to the local forest with a
    // negative temp id and only created on Done (see deriveReorderPayload +
    // reorderTopLevel). This counter hands out those temp ids; nothing keys off
    // its value beyond uniqueness within a session.
    const tempFolderId = useRef(-1);

    function startEditing() {
        setEditNodes(buildReorderTree(folders, rootNodes));
        setRenames({});
        tempFolderId.current = -1;
        editDirty.current = false;
        dragDirty.current = false;
        setEditing(true);
    }

    /**
     * Add a new, empty folder to the top of the Edit forest without touching the
     * server. It carries a `__new` flag and a negative temp id; Done persists it
     * via the folder-order save, Cancel throws it away. Marks the order dirty so
     * the save actually runs even if nothing else was dragged.
     */
    function addPendingFolder(name) {
        const id = tempFolderId.current--;
        setEditNodes((prev) => [
            { id: `folder-${id}`, __folder: true, __new: true, folderId: id, title: name.trim(), position: -1, tags: [], children: [] },
            ...prev,
        ]);
        // Only editDirty (arms the unsaved guard). NOT dragDirty: the save is
        // needed because a __new folder is present in the forest — persistPending
        // gates on that directly — so adding then discarding a folder with no other
        // change leaves nothing to persist, instead of a stale dragDirty firing a
        // redundant order-save.
        editDirty.current = true;
    }

    /** Drop a still-pending folder locally, returning its members to the top level. */
    function discardPendingFolder(folderId) {
        setEditNodes((prev) => prev.flatMap((n) => (
            n.__folder && n.folderId === folderId ? (n.children ?? []) : [n]
        )));
        editDirty.current = true;
    }

    // Warn before losing unsaved moves on close/refresh or any in-app navigation
    // (a page link, a sidebar action, or "New page"); see the discard modal below.
    const { promptOpen, requestLeave, confirmDiscard, dismissPrompt } = useUnsavedChangesGuard({
        active: editing,
        dirtyRef: editDirty,
        revert: () => { editDirty.current = false; dragDirty.current = false; setEditNodes([]); setRenames({}); setEditing(false); },
    });

    // Drag state
    const [activeId, setActiveId] = useState(null);
    const [overId, setOverId] = useState(null);
    const [offsetLeft, setOffsetLeft] = useState(0);

    useEffect(() => { setRootNodes(tree); }, [tree]);

    const allTags = useMemo(() => collectAllTags(rootNodes), [rootNodes]);
    const filteredRows = useMemo(() => {
        if (!activeTag) return null;
        return flattenAll(rootNodes).filter((n) => n.tags.some((t) => t.id === activeTag));
    }, [rootNodes, activeTag]);

    const options = useMemo(() => flattenOptions(rootNodes), [rootNodes]);
    // Folder destinations for the create/import dialogs. Persisted folders only:
    // a folder still pending in Edit mode has no server id to file a page into.
    const folderChoices = useMemo(
        () => folders.map((f) => ({ id: f.id, name: f.name })),
        [folders],
    );

    // The DnD operates on the folder-aware forest while editing, the plain tree
    // otherwise (read view with no folders). With zero folders the two coincide.
    const dndNodes = editing ? editNodes : rootNodes;

    const guideFlags = useMemo(() => computeGuideFlags(dndNodes), [dndNodes]);

    // Flat list for the tree, with the dragged row's descendants hidden while
    // dragging. While editing: a collapsed folder hides its whole subtree (members
    // + their subpages) so the tree stays short and easy to reorder — the folder
    // still drags as a block (buildTree works off the full forest) — and an empty,
    // expanded folder gets a placeholder drop-row so a page can be dragged in.
    const flattenedItems = useMemo(() => {
        let flat = flattenForDnd(dndNodes);
        if (editing) {
            const hidden = new Set();
            for (const item of flat) {
                if (item.node.__folder && collapsedFolders.has(item.node.folderId)) {
                    for (const id of getDescendantIds(flat, item.id)) hidden.add(id);
                }
            }
            const withPlaceholders = [];
            for (const item of flat) {
                if (hidden.has(item.id)) continue;
                withPlaceholders.push(item);
                if (item.node.__folder && !collapsedFolders.has(item.node.folderId) && !flat.some((x) => x.parentId === item.id)) {
                    withPlaceholders.push({
                        id: `drop-${item.node.folderId}`, parentId: item.id, depth: item.depth + 1,
                        node: { __placeholder: true, folderId: item.node.folderId },
                    });
                }
            }
            flat = withPlaceholders;
        }
        if (activeId == null) return flat;
        const descendants = getDescendantIds(flat, activeId);
        return flat.filter((i) => i.id === activeId || !descendants.includes(i.id));
    }, [dndNodes, activeId, editing, collapsedFolders]);

    // The normal reading view groups pages into folder sections; reorder and
    // tag-filter views fall back to the plain flat tree (a folder is a reading
    // aid, not a structural mode — same call the shelf made).
    const showFolders = !editing && !activeTag && folders.length > 0;

    // Top level in its shared order: folders and loose pages interleaved by
    // position, so a loose page saved between two folders reads that way here too
    // (not just in reorder mode). Same merge the reorder tree uses.
    const topLevel = useMemo(
        () => (showFolders ? buildTopLevel(folders, rootNodes).entries : null),
        [rootNodes, folders, showFolders],
    );

    /**
     * Render a set of root nodes (a folder's members, or the loose pages) as tree
     * rows, shifted `depthOffset` levels right.
     *
     * Guide flags are computed over just these nodes, so "last member of this
     * folder" — not "last page in the workspace" — is what ends the spine. The
     * `false` prefix keeps the flag array aligned with the shifted depth: the
     * guide loop reads pathLast[i+1], so index 0 is padding, never read.
     */
    const renderRows = (nodes, depthOffset) => {
        const items = flattenForDnd(nodes);
        const flags = computeGuideFlags(nodes);
        return items.map((item) => {
            const base = flags.get(item.id) ?? [];
            return (
                <TreeRow
                    key={item.id}
                    id={item.id}
                    depth={item.depth + depthOffset}
                    node={item.node}
                    activeTagId={activeTag}
                    onAddChild={openModal}
                    onImport={openImport}
                    canCreate={perms.create}
                    canReorder={false}
                    ghost={false}
                    dragging={false}
                    pathLast={depthOffset ? [false, ...base] : base}
                    isDropParent={false}
                    starred={starredSet.has(item.node.id)}
                    isReordering={false}
                    isRootPage={item.depth === 0}
                />
            );
        });
    };

    const projected = activeId != null && overId != null
        ? getProjection(flattenedItems, activeId, overId, offsetLeft, INDENT)
        : null;

    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }));

    function openModal(parentId = '') {
        setModalParentId(String(parentId));
        setModalFolderId('');
        setModalOpen(true);
    }

    /** Same dialog, aimed at a folder: the new page is filed, not nested. */
    function openModalInFolder(folderId) {
        setModalParentId('');
        setModalFolderId(String(folderId));
        setModalOpen(true);
    }

    function openImport(parentId = '') {
        setImportParentId(String(parentId));
        setImportFolderId('');
        setImportFiles(null);
        setImportOpen(true);
    }

    function openImportInFolder(folderId) {
        setImportParentId('');
        setImportFolderId(String(folderId));
        setImportFiles(null);
        setImportOpen(true);
    }

    // Drop OS files anywhere on the page to import them. Only reacts to real
    // file drags (dataTransfer.types has "Files") — the tree's reorder drag is
    // dnd-kit pointer events and never fires these — and stays inert for
    // viewers, during reorder mode, and while the dialog is already open.
    useEffect(() => {
        if (!perms.create || importOpen || editing) return;

        let depth = 0; // dragenter/leave fire per element; count nesting
        const hasFiles = (e) => Array.from(e.dataTransfer?.types ?? []).includes('Files');

        const onEnter = (e) => {
            if (!hasFiles(e)) return;
            e.preventDefault();
            if (++depth === 1) setDropActive(true);
        };
        const onLeave = (e) => {
            if (!hasFiles(e)) return;
            if (depth > 0 && --depth === 0) setDropActive(false);
        };
        const onOver = (e) => { if (hasFiles(e)) e.preventDefault(); };
        const onDrop = (e) => {
            depth = 0;
            setDropActive(false);
            if (!hasFiles(e)) return;
            e.preventDefault();
            const files = Array.from(e.dataTransfer.files);
            if (files.length === 0) return;
            setImportParentId('');
            setImportFiles(files);
            setImportOpen(true);
        };

        window.addEventListener('dragenter', onEnter);
        window.addEventListener('dragleave', onLeave);
        window.addEventListener('dragover', onOver);
        window.addEventListener('drop', onDrop);
        return () => {
            window.removeEventListener('dragenter', onEnter);
            window.removeEventListener('dragleave', onLeave);
            window.removeEventListener('dragover', onOver);
            window.removeEventListener('drop', onDrop);
            setDropActive(false);
        };
    }, [perms.create, importOpen, editing]);

    function refreshTree() {
        router.reload({ only: ['tree', 'workspace'], preserveScroll: true });
    }

    /** Pages created by a batch exist server-side already — pull them into the tree. */
    function closeImport({ sent, pending }) {
        setImportOpen(false);
        if (sent > 0) refreshTree();
        if (pending.length > 0) setPendingImportJobs(pending);
    }

    // Conversions still running after the dialog closed: keep watching them and
    // refresh the tree as they land (a FAILED import trashes its placeholder
    // page, so completions can remove rows too, not just fill them in).
    const pendingPollsRef = useRef(0);
    useEffect(() => {
        if (pendingImportJobs.length === 0) return;
        const timer = setInterval(async () => {
            // Give up after ~2 minutes — same budget as the dialog's own polling.
            if (++pendingPollsRef.current > 48) {
                pendingPollsRef.current = 0;
                setPendingImportJobs([]);
                return;
            }
            const still = [];
            for (const jobId of pendingImportJobs) {
                try {
                    const res = await fetch(`/imports/${jobId}`, { headers: { Accept: 'application/json' } });
                    const data = await res.json();
                    if (!['done', 'failed'].includes(data.status)) still.push(jobId);
                } catch {
                    still.push(jobId); // network hiccup — keep watching
                }
            }
            if (still.length !== pendingImportJobs.length) {
                refreshTree();
                setPendingImportJobs(still);
                if (still.length === 0) pendingPollsRef.current = 0;
            }
        }, 2500);
        return () => clearInterval(timer);
    }, [pendingImportJobs]); // eslint-disable-line react-hooks/exhaustive-deps

    function resetDrag() {
        setActiveId(null);
        setOverId(null);
        setOffsetLeft(0);
    }

    function handleDragEnd({ active, over }) {
        const projection = over && activeId != null
            ? getProjection(flattenedItems, active.id, over.id, offsetLeft, INDENT)
            : null;
        resetDrag();
        if (!over || !projection) return;

        const full = flattenForDnd(editNodes);
        const activeIndex = full.findIndex((i) => i.id === active.id);
        if (activeIndex === -1) return;

        const original = full[activeIndex];
        let { depth, parentId } = projection;
        // Folders never nest — pin to the top level. Members follow on rebuild.
        if (original.node.__folder) { depth = 0; parentId = null; }

        // A drop onto an empty folder's placeholder has no real row — resolve it
        // to just after that folder's header, so the page lands as its first member.
        let overIndex = full.findIndex((i) => i.id === over.id);
        if (overIndex === -1) {
            const fid = String(over.id).slice('drop-'.length);
            overIndex = full.findIndex((i) => i.node.__folder && String(i.node.folderId) === fid);
        }
        if (overIndex === -1) return;

        if (active.id === over.id && original.parentId === parentId && original.depth === depth) {
            return; // dropped in place, no change
        }

        const moved = full.slice();
        moved[activeIndex] = { ...original, depth, parentId };
        const sorted = arrayMove(moved, activeIndex, overIndex);

        if (!isValidReorder(sorted)) {
            toast.error('Folders stay at the top level — they can’t be nested.');
            return; // snap back
        }

        setEditNodes(buildTree(sorted));
        // Don't persist per-drop — accumulate locally and save once on "Done".
        editDirty.current = true;
        dragDirty.current = true;
    }

    /** The batched-order save for the current forest: {url, payload}. */
    function orderSave() {
        // Any folder — a real one OR a folder created this session (pending, so not
        // yet in the `folders` prop) — means the folder-order path, which is the
        // only one that can create the pending folder and file pages into it.
        const hasFolders = folders.length > 0 || editNodes.some((n) => n.__folder);
        if (!hasFolders) {
            // No folders: the plain page tree — the existing restructure save
            // (audited, unchanged) so the no-folder path behaves exactly as before.
            const posByParent = new Map();
            const nodes = flattenForDnd(editNodes).map((i) => {
                const key = i.parentId ?? 0;
                const position = posByParent.get(key) ?? 0;
                posByParent.set(key, position + 1);
                return { id: i.id, parent_id: i.parentId ?? null, position };
            });
            return { url: `/workspaces/${workspace.id}/tree`, payload: { nodes } };
        }
        return { url: `/workspaces/${workspace.id}/folder-order`, payload: deriveReorderPayload(editNodes) };
    }

    /**
     * Persist everything pending — inline renames FIRST (chained; Inertia
     * serializes visits, so they can't run concurrently), then the one atomic order
     * save. `onDone` runs after it all lands; `onFail` on any error.
     */
    function persistPending(onDone, onFail) {
        // Capture the order save NOW, before the rename chain's reloads re-seed the
        // forest (which would drop the drag arrangement). Only when something moved
        // OR a folder was created this session (a pending __new node, which only the
        // order-save persists) — a pure rename must not re-save nor emit a spurious
        // workspace.restructured.
        const hasPendingFolder = editNodes.some((n) => n.__folder && n.__new);
        const order = (dragDirty.current || hasPendingFolder) ? orderSave() : null;

        const renames = Object.entries(pendingRenamesRef.current).map(([key, value]) => {
            const [type, id] = key.split(':');
            if (type === 'f') return { url: `/folders/${id}`, payload: { name: value } };
            if (type === 'p') return { url: `/documents/${id}/rename`, payload: { title: value } };
            return { url: `/workspaces/${workspace.id}`, payload: { name: value } }; // 'ws'
        });
        const opts = (handlers) => ({ preserveState: true, preserveScroll: true, ...handlers });
        const finish = () => { editDirty.current = false; dragDirty.current = false; setRenames({}); onDone?.(); };
        const runOrder = () => {
            if (!order) return finish();
            router.patch(order.url, order.payload, opts({
                onSuccess: finish, onError: onFail, onNetworkError: () => { onFail(); return false; },
            }));
        };
        let i = 0;
        const runNext = () => {
            if (i >= renames.length) return runOrder();
            const r = renames[i++];
            router.patch(r.url, r.payload, opts({ onSuccess: runNext, onError: onFail, onNetworkError: () => { onFail(); return false; } }));
        };
        runNext();
    }

    /** Leave Edit mode, persisting drags + renames once if anything changed. */
    function finishEditing() {
        setEditing(false);
        if (!editDirty.current) { setEditNodes([]); return; }
        persistPending(
            () => setEditNodes([]),
            () => {
                editDirty.current = true;
                setEditing(true);
                toast.error("Couldn't save your changes — they're still here, click Done to retry.");
            },
        );
    }

    /**
     * Run an immediate Edit-mode mutation (create / delete a container, import),
     * flushing pending drags + renames to the server FIRST so the mutation's own
     * reload can't clobber them (the re-seed effect below rebuilds afterward).
     */
    function flushThen(action) {
        if (!editDirty.current) { action(); return; }
        persistPending(action, () => { editDirty.current = true; toast.error("Couldn't save your changes — try again."); });
    }

    // Inline renames are PENDING (local) until Done, so Cancel can discard them.
    const renameWorkspace = (name) => { setRenames({ ...pendingRenamesRef.current, ws: name }); editDirty.current = true; };
    const renameFolder = (folder, name) => {
        // A pending (not-yet-created) folder has no row to PATCH — rename it in the
        // forest so its create carries the final name; a real folder queues a PATCH.
        if (folder.id < 0) {
            setEditNodes((prev) => prev.map((n) => (
                n.__folder && n.folderId === folder.id ? { ...n, title: name } : n
            )));
            editDirty.current = true;
            return;
        }
        setRenames({ ...pendingRenamesRef.current, [`f:${folder.id}`]: name });
        editDirty.current = true;
    };
    const renamePage = (node, title) => { setRenames({ ...pendingRenamesRef.current, [`p:${node.id}`]: title }); editDirty.current = true; };

    function destroyWorkspace() {
        // Trashing the whole workspace — pending edits are moot; drop them so the
        // unsaved-changes guard doesn't prompt on the redirect to the index.
        editDirty.current = false;
        dragDirty.current = false;
        setRenames({});
        setEditing(false);
        router.delete(`/workspaces/${workspace.id}`);
    }

    // While in Edit mode, an immediate mutation (rename/create/delete/import)
    // reloads server props; re-seed the drag forest from the fresh tree so the new
    // state shows without leaving Edit mode. Safe against losing drags: every
    // immediate action flushes pending drags FIRST, so nothing unsaved is in flight
    // when props change. Not keyed on `editing` — entering Edit mode seeds the
    // forest explicitly in startEditing, and this must not re-run then.
    useEffect(() => {
        if (editing) setEditNodes(buildReorderTree(folders, rootNodes));
    }, [folders, rootNodes]); // eslint-disable-line react-hooks/exhaustive-deps

    const pageCount = workspace.documents_count ?? rootNodes.length;

    return (
        <>
        <DocsLayout>
            <Head title={workspace.name} />

            {/* Breadcrumb */}
            <nav className="flex items-center gap-1.5 text-sm text-text-secondary">
                <Link href="/workspaces" className="transition-colors hover:text-foreground">Workspaces</Link>
                <IconChevronRight className="h-3.5 w-3.5 shrink-0 text-text-tertiary" stroke={1.5} />
                <span className="text-foreground">{workspace.name}</span>
            </nav>

            {/* Header */}
            <div className="mt-3 flex items-start justify-between gap-4">
                <div className="min-w-0 flex-1">
                    {editing && perms.update ? (
                        <InlineEditableTitle
                            value={pendingRenames.ws ?? workspace.name}
                            onCommit={renameWorkspace}
                            ariaLabel="Rename workspace"
                            className="w-full text-[19px] font-semibold text-foreground"
                            inputClassName="text-[19px] font-semibold"
                        />
                    ) : (
                        <h1 className="text-[19px] font-semibold text-foreground">{workspace.name}</h1>
                    )}
                    <p className="mt-0.5 text-sm text-text-secondary">
                        {pageCount} {pageCount === 1 ? 'page' : 'pages'}
                        {workspace.description ? ` · ${workspace.description}` : ''}
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    {/* Edit mode is arrange-only (Cancel/Done + on-row rename/delete/
                        drag). Creation, workspace details, and Trash live in the
                        read-view ⋯, next to the New primary and the Edit toggle. */}
                    {editing ? (
                        <>
                            {perms.create && (
                                <Button
                                    variant="outline"
                                    className="border-border hover:bg-surface-hover"
                                    // Deferred create: just open the namer — the folder
                                    // is added locally on submit, persisted on Done. No
                                    // flush, so it stays discardable via Cancel.
                                    onClick={() => setFolderModal({ open: true, folder: null })}
                                >
                                    <IconFolderPlus stroke={1.5} />
                                    New folder
                                </Button>
                            )}
                            <Button variant="outline" onClick={requestLeave}>
                                Cancel
                            </Button>
                            <Button onClick={finishEditing}>
                                <IconCheck stroke={1.5} />
                                Done
                            </Button>
                        </>
                    ) : (
                        <>
                            {perms.create && (
                                <Button onClick={() => openModal('')}>
                                    <IconPlus stroke={1.5} />
                                    New page
                                </Button>
                            )}
                            {(perms.create || perms.update || perms.delete || perms.isAdmin) && (
                                <DropdownMenu modal={false}>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="outline"
                                            className="border-border px-2 hover:bg-surface-hover"
                                            title="More actions"
                                            aria-label="More actions"
                                        >
                                            <IconDots stroke={1.5} />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" className="w-52">
                                        {perms.create && (
                                            <DropdownMenuItem onSelect={() => setFolderModal({ open: true, folder: null })}>
                                                <IconFolderPlus stroke={1.5} />
                                                New folder
                                            </DropdownMenuItem>
                                        )}
                                        {perms.create && (
                                            <DropdownMenuItem onSelect={() => openImport('')}>
                                                <IconUpload stroke={1.5} />
                                                Import pages…
                                            </DropdownMenuItem>
                                        )}
                                        {perms.update && (
                                            <DropdownMenuItem onSelect={() => setEditOpen(true)}>
                                                <IconPencil stroke={1.5} />
                                                Edit details…
                                            </DropdownMenuItem>
                                        )}
                                        {perms.isAdmin && (
                                            <>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem asChild>
                                                    <Link href="/trash">
                                                        <IconTrash stroke={1.5} />
                                                        View Trash
                                                    </Link>
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                        {perms.delete && (
                                            <>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    onSelect={() => setDeleteOpen(true)}
                                                    className="text-danger focus:bg-danger-surface focus:text-danger"
                                                >
                                                    <IconTrash stroke={1.5} />
                                                    Move to Trash
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                            {(perms.update || perms.create) && !activeTag && (
                                <Button
                                    variant="outline"
                                    className="border-border hover:bg-surface-hover"
                                    onClick={startEditing}
                                >
                                    <IconPencil stroke={1.5} />
                                    Edit
                                </Button>
                            )}
                        </>
                    )}
                </div>
            </div>

            {/* Tag filter chips — hidden in Edit mode (a filter swaps the tree out
                for a flat list, which has no drag surface). */}
            {allTags.length > 0 && !editing && (
                <div className="mt-4 flex flex-wrap items-center gap-1.5">
                    <span className="text-xs text-text-tertiary">Filter:</span>
                    {allTags.map((tag) => (
                        <button
                            key={tag.id}
                            type="button"
                            onClick={() => setActiveTag(activeTag === tag.id ? null : tag.id)}
                            className={`rounded-sm px-2.5 py-1 text-xs font-medium transition-colors ${
                                activeTag === tag.id
                                    ? 'bg-accent-100 text-accent-600'
                                    : 'border border-border bg-surface text-text-secondary hover:bg-surface-hover'
                            }`}
                        >
                            {tag.name}
                        </button>
                    ))}
                </div>
            )}

            {/* Edit-mode hint — pinned above the table so it stays visible no
                matter how long the tree is. */}
            {editing && rootNodes.length > 0 && !filteredRows && (
                <div className="mt-4 flex items-start gap-2 rounded-md border border-accent-200 bg-accent-50 px-3 py-2 text-xs text-accent-700">
                    <IconPencil className="mt-0.5 h-3.5 w-3.5 shrink-0" stroke={1.5} />
                    <span>
                        Drag a page to reorder it, sideways to nest it under another page, or onto a folder to file it there; drag it out to the top level to un-file. {folders.length > 0 ? 'Drag a folder to move the whole section. ' : ''}Click “Done” when finished.
                    </span>
                </div>
            )}

            {/* Page table */}
            <div className="mt-4 overflow-hidden rounded-md border border-border bg-card">
                {/* Column headers */}
                <div className="grid grid-cols-[1fr_110px_96px] border-b border-border bg-surface-hover py-2.5">
                    <span className="pl-3 pr-4 text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">Page</span>
                    <span className="pr-4 text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">Updated</span>
                    <span />
                </div>

                {filteredRows ? (
                    filteredRows.length === 0 ? (
                        <p className="px-4 py-6 text-center text-sm text-text-tertiary">No pages match this filter.</p>
                    ) : (
                        <ul>{filteredRows.map((node) => <FilteredRow key={node.id} node={node} />)}</ul>
                    )
                ) : (
                    rootNodes.length === 0 ? (
                        <p className="px-4 py-6 text-center text-sm text-text-tertiary">No pages yet.</p>
                    ) : (
                        <DndContext
                            sensors={sensors}
                            collisionDetection={closestCenter}
                            onDragStart={({ active }) => { setActiveId(active.id); setOverId(active.id); }}
                            onDragMove={({ delta }) => setOffsetLeft(delta.x)}
                            onDragOver={({ over }) => setOverId(over?.id ?? null)}
                            onDragEnd={perms.update && editing ? handleDragEnd : resetDrag}
                            onDragCancel={resetDrag}
                        >
                            <SortableContext items={flattenedItems.map((i) => i.id)} strategy={verticalListSortingStrategy}>
                                <ul>
                                    {showFolders ? (
                                        topLevel.map((entry) => {
                                            if (entry.kind === 'page') return renderRows([entry.node], 0);
                                            const { folder, members } = entry;
                                            const collapsed = collapsedFolders.has(folder.id);
                                            return (
                                                <React.Fragment key={`folder-${folder.id}`}>
                                                    <FolderHeaderRow
                                                        folder={folder}
                                                        count={countPages(members)}
                                                        collapsed={collapsed}
                                                        onToggle={() => toggleFolder(folder.id)}
                                                        canCreate={perms.create}
                                                        onAddPage={openModalInFolder}
                                                        onImport={openImportInFolder}
                                                    />
                                                    {!collapsed && (members.length === 0
                                                        ? (
                                                            <li className="border-b border-border-subtle last:border-0">
                                                                <p className="py-3 pl-[60px] pr-4 text-xs text-text-tertiary">
                                                                    Empty folder — hover the folder row to add or import a page, or open Edit to drag pages in.
                                                                </p>
                                                            </li>
                                                        )
                                                        : renderRows(members, FOLDER_MEMBER_DEPTH))}
                                                </React.Fragment>
                                            );
                                        })
                                    ) : (
                                        flattenedItems.map((item) => {
                                            // Reorder tree carries folder pseudo-nodes; render them as
                                            // draggable folder blocks, everything else as a page row.
                                            if (item.node.__placeholder) {
                                                return (
                                                    <EmptyFolderDropRow
                                                        key={item.id}
                                                        id={item.id}
                                                        isDropTarget={projected?.parentId === item.parentId}
                                                    />
                                                );
                                            }
                                            if (item.node.__folder) {
                                                return (
                                                    <EditFolderRow
                                                        key={item.id}
                                                        id={item.id}
                                                        name={pendingRenames[`f:${item.node.folderId}`] ?? item.node.title}
                                                        count={countPages(item.node.children ?? [])}
                                                        ghost={item.id === activeId}
                                                        isDropTarget={projected?.parentId === item.id && item.id !== activeId}
                                                        canManage={perms.update}
                                                        onRename={(name) => renameFolder({ id: item.node.folderId }, name)}
                                                        onDelete={() => (item.node.__new
                                                            // Never saved — drop it locally, no server round-trip.
                                                            ? discardPendingFolder(item.node.folderId)
                                                            : flushThen(() => setFolderToDelete(
                                                                folders.find((f) => f.id === item.node.folderId) ?? { id: item.node.folderId, name: item.node.title },
                                                            )))}
                                                        collapsed={collapsedFolders.has(item.node.folderId)}
                                                        onToggleCollapse={() => toggleFolder(item.node.folderId)}
                                                    />
                                                );
                                            }
                                            // A member draws at depth 1 but is still a root page — derive
                                            // root-ness from the parent, not the depth.
                                            const parentRow = item.parentId != null ? flattenedItems.find((x) => x.id === item.parentId) : null;
                                            const isRootPage = item.parentId == null || !!parentRow?.node.__folder;
                                            return (
                                                <TreeRow
                                                    key={item.id}
                                                    id={item.id}
                                                    depth={item.id === activeId && projected ? projected.depth : item.depth}
                                                    node={pendingRenames[`p:${item.node.id}`] ? { ...item.node, title: pendingRenames[`p:${item.node.id}`] } : item.node}
                                                    activeTagId={activeTag}
                                                    onAddChild={openModal}
                                                    onImport={openImport}
                                                    onRename={renamePage}
                                                    canCreate={perms.create}
                                                    canReorder={perms.update && editing}
                                                    ghost={item.id === activeId}
                                                    dragging={activeId != null}
                                                    pathLast={guideFlags.get(item.id)}
                                                    isDropParent={projected?.parentId != null && projected.parentId === item.id && item.id !== activeId}
                                                    starred={starredSet.has(item.node.id)}
                                                    isReordering={editing}
                                                    isRootPage={isRootPage}
                                                />
                                            );
                                        })
                                    )}
                                </ul>
                            </SortableContext>
                        </DndContext>
                    )
                )}

                {/* New page footer button */}
                {perms.create && !editing && (
                    <button
                        type="button"
                        onClick={() => openModal('')}
                        className="flex w-full items-center gap-1.5 border-t border-border px-4 py-2.5 text-sm text-text-tertiary transition-colors hover:bg-surface-hover hover:text-text-secondary"
                    >
                        <IconPlus className="h-3.5 w-3.5" stroke={1.5} />
                        New page
                    </button>
                )}
            </div>

        </DocsLayout>

        <NewPageModal
            open={modalOpen}
            onClose={() => setModalOpen(false)}
            workspaceId={workspace.id}
            parentOptions={options}
            folderOptions={folderChoices}
            initialParentId={modalParentId}
            initialFolderId={modalFolderId}
            templates={templates}
        />

        <WorkspaceFormModal
            open={editOpen}
            onClose={() => setEditOpen(false)}
            workspace={workspace}
        />

        <FolderFormModal
            open={folderModal.open}
            onClose={() => setFolderModal({ open: false, folder: null })}
            folder={folderModal.folder}
            workspaceId={workspace.id}
            // In Edit mode, creating is deferred: the modal hands the name back and
            // the folder joins the local forest (persisted on Done). Outside Edit
            // mode it POSTs immediately, as before.
            onCreate={editing ? addPendingFolder : undefined}
        />

        <ConfirmDialog
            open={Boolean(folderToDelete)}
            title="Delete folder"
            // Says what does NOT happen: a folder looks like it owns its pages, so
            // the non-destructive behavior is the thing worth stating.
            message={`Delete “${folderToDelete?.name ?? ''}”? Its pages move back to the top level — nothing is trashed.`}
            confirmLabel="Delete folder"
            onConfirm={() => {
                router.delete(`/folders/${folderToDelete.id}`, {
                    preserveScroll: true,
                    onError: () => toast.error("Couldn't delete that folder."),
                });
                setFolderToDelete(null);
            }}
            onCancel={() => setFolderToDelete(null)}
        />

        <ImportDialog
            open={importOpen}
            onClose={closeImport}
            onSettled={refreshTree}
            workspaceId={workspace.id}
            parentOptions={options}
            folderOptions={folderChoices}
            initialParentId={importParentId}
            initialFolderId={importFolderId}
            initialFiles={importFiles}
        />

        {/* Drop-anywhere target; pointer-events-none so the drop reaches the window listeners */}
        {dropActive && (
            <div
                className="pointer-events-none fixed inset-0 z-40 flex items-center justify-center p-8"
                style={{ background: 'rgba(31, 37, 32, 0.34)' }}
            >
                <div
                    className="flex flex-col items-center gap-2.5 rounded-[14px] border-2 border-dashed border-accent-400 bg-surface px-10 py-8"
                    style={{ boxShadow: 'var(--shadow-lg)' }}
                >
                    <IconUpload className="h-7 w-7 text-accent-600" stroke={1.5} aria-hidden="true" />
                    <p className="text-sm font-medium text-foreground">Drop files to import into {workspace.name}</p>
                    <p className="text-xs text-text-secondary">.docx and .pdf files become pages</p>
                </div>
            </div>
        )}

        <ConfirmDialog
            open={deleteOpen}
            title={`Delete "${workspace.name}"?`}
            message={`This workspace and all ${pageCount} ${pageCount === 1 ? 'page' : 'pages'} inside it will be moved to Trash. You can restore them later from there.`}
            confirmLabel="Move to Trash"
            cancelLabel="Cancel"
            variant="danger"
            onConfirm={destroyWorkspace}
            onCancel={() => setDeleteOpen(false)}
        />

        <ConfirmDialog
            open={promptOpen}
            title="Discard changes?"
            message="You have unsaved page-order changes. Leaving reorder mode will discard them permanently."
            confirmLabel="Discard changes"
            cancelLabel="Keep editing"
            variant="danger"
            onConfirm={confirmDiscard}
            onCancel={dismissPrompt}
        />
        </>
    );
}
