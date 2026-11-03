import { useState, useEffect, useRef, useMemo } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import {
    IconFileText, IconFolderOpen, IconFolderPlus, IconGripVertical, IconPlus, IconTrash,
    IconCheck, IconChevronRight, IconDots, IconHistory, IconStarFilled,
    IconLibrary, IconLibraryPlus, IconPencil, IconCornerDownRight,
} from '@tabler/icons-react';
import {
    DndContext, PointerSensor, useSensor, useSensors, closestCenter,
} from '@dnd-kit/core';
import {
    SortableContext, useSortable, arrayMove, verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import DocsLayout from '@/Layouts/DocsLayout';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import WorkspaceFormModal from '@/components/ui/WorkspaceFormModal';
import GroupFormModal from '@/components/ui/GroupFormModal';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import InlineEditableTitle from '@/components/ui/InlineEditableTitle';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { flattenForDnd, getDescendantIds, buildTree } from '@/lib/dndTree';
import { can } from '@/lib/permissions';
import { timeAgo } from '@/lib/date';

// A top-level slot is either a group (carrying its ordered members) or a lone
// ungrouped workspace. Both live in ONE shared order, so a loose workspace can
// sit between two groups. Sortable ids are namespaced (`g:`/`w:`) so a group and
// a workspace with the same numeric id never collide in a DnD context.
const tokenId = (t) => (t.type === 'group' ? `g:${t.id}` : `w:${t.id}`);

/**
 * Fold the flat workspace list + groups into the interleaved top-level order.
 * Groups and ungrouped workspaces share one `position` space (that's what the
 * server persists), so we merge both and sort by it. `sortBy === 'updated'` is a
 * view-only convenience: it re-sorts each group's members and any run of
 * adjacent loose rows by recency, but never moves groups around.
 */
function buildLayout(workspaces, groups, sortBy) {
    const membersOf = new Map(groups.map((g) => [g.id, []]));
    const ungrouped = [];
    for (const w of workspaces) {
        if (w.group_id != null && membersOf.has(w.group_id)) membersOf.get(w.group_id).push(w);
        else ungrouped.push(w);
    }

    const byPos = (a, b) => (a.position ?? 0) - (b.position ?? 0);
    const byUpdated = (a, b) => new Date(b.updated_at) - new Date(a.updated_at);
    const itemSort = sortBy === 'updated' ? byUpdated : byPos;
    for (const list of membersOf.values()) list.sort(itemSort);

    const tokens = [
        ...groups.map((g) => ({ type: 'group', id: g.id, position: g.position ?? 0, group: g, items: membersOf.get(g.id) })),
        ...ungrouped.map((w) => ({ type: 'workspace', id: w.id, position: w.position ?? 0, workspace: w })),
    ].sort((a, b) => (a.position - b.position)
        // Stable tie-break for pre-reorder data whose group/workspace positions
        // haven't been merged into one sequence yet: groups first, then by id.
        || (a.type === b.type ? a.id - b.id : (a.type === 'group' ? -1 : 1)));

    if (sortBy !== 'updated') return tokens;

    // Recency view: sort each maximal run of consecutive loose rows by updated_at,
    // leaving every group token exactly where its position put it.
    for (let i = 0; i < tokens.length;) {
        if (tokens[i].type !== 'workspace') { i++; continue; }
        let j = i;
        while (j < tokens.length && tokens[j].type === 'workspace') j++;
        const run = tokens.slice(i, j).sort((a, b) => byUpdated(a.workspace, b.workspace));
        tokens.splice(i, j - i, ...run);
        i = j;
    }
    return tokens;
}

// ── Reorder engine (mirrors Workspaces/Show.jsx's folder-aware tree) ──────────
//
// In reorder mode the index is ONE flat, projected list: each group becomes a
// depth-0 pseudo-row whose children are its member workspaces, interleaved with
// loose workspaces in the shared top-level order. A single drag can reorder the
// top level, reorder a group's members, carry a workspace ACROSS groups (drop it
// one level deep under a different group), or pull it out to loose (depth 0) —
// the projection decides which from the drop row + horizontal offset. Strictly
// two levels: groups never nest (pinned to depth 0) and a member can't parent, so
// depth caps at 1. This replaces the old nested-SortableContext model, whose
// per-group contexts made cross-group drag impossible.

const REORDER_INDENT = 24; // px of indent for a group member (the one nesting level)

/** layout tokens → reorder forest (group pseudo-nodes carry their members). */
function buildForest(layout) {
    return layout.map((t) => (
        t.type === 'group'
            ? {
                id: `g:${t.id}`, __group: true, groupId: t.id, group: t.group,
                children: t.items.map((w) => ({ id: `w:${w.id}`, workspace: w, children: [] })),
            }
            : { id: `w:${t.id}`, workspace: t.workspace, children: [] }
    ));
}

/** reorder forest → read-view layout tokens (the inverse of buildForest). */
function forestToLayout(forest) {
    return forest.map((node) => (
        node.__group
            ? { type: 'group', id: node.groupId, position: 0, group: node.group, items: (node.children ?? []).map((c) => c.workspace) }
            : { type: 'workspace', id: node.workspace.id, position: 0, workspace: node.workspace }
    ));
}

/**
 * Where would the dragged row land — its depth and new parent group — given the
 * cursor offset. A group is pinned to depth 0; a workspace may sit at depth 1
 * only when the row above it is a group header or an existing member (so its
 * parent group is unambiguous). Mirrors Show.jsx getProjection, capped at 1 level.
 */
function getProjection(items, activeId, overId, dragOffset) {
    const overIndex = items.findIndex((i) => i.id === overId);
    const activeIndex = items.findIndex((i) => i.id === activeId);
    if (overIndex === -1 || activeIndex === -1) return null;

    const activeItem = items[activeIndex];
    const newItems = arrayMove(items, activeIndex, overIndex);
    const prevItem = newItems[overIndex - 1];

    const isGroup = !!activeItem.node.__group;
    const projectedDepth = activeItem.depth + Math.round(dragOffset / REORDER_INDENT);
    // A workspace can nest only directly under a group header or another member.
    const canNest = !isGroup && prevItem && (prevItem.node.__group || prevItem.depth === 1);
    const depth = isGroup ? 0 : Math.max(0, Math.min(projectedDepth, canNest ? 1 : 0));

    const parentId = (() => {
        if (depth === 0 || !prevItem) return null;
        if (prevItem.node.__group) return prevItem.id;   // right under a group header
        if (prevItem.depth === 1) return prevItem.parentId; // after a sibling member
        return null;
    })();

    return { depth, parentId };
}

/**
 * Turn the final reorder forest into the top-level-order payload (M1's contract):
 *   items  — the interleaved top level (groups + loose workspaces)
 *   groups — each group with its ordered members, tagged by group id so a member
 *            dragged into a new group refiles there in the same atomic save.
 */
function deriveGroupsPayload(forest) {
    const items = [];
    const groups = [];
    for (const top of forest) {
        if (top.__group) {
            items.push({ type: 'group', id: top.groupId });
            groups.push({ id: top.groupId, members: (top.children ?? []).map((c) => c.workspace.id) });
        } else {
            items.push({ type: 'workspace', id: top.workspace.id });
        }
    }
    return { items, groups };
}

/** Grip handle shared by the flat reorder rows. */
function RowGrip({ listeners, attributes, label }) {
    return (
        <button
            type="button"
            {...listeners}
            {...attributes}
            tabIndex={-1}
            aria-label={label}
            className="flex h-5 w-5 shrink-0 cursor-grab items-center justify-center text-text-tertiary active:cursor-grabbing"
        >
            <IconGripVertical className="h-3.5 w-3.5" stroke={1.5} />
        </button>
    );
}

/**
 * A group header while in EDIT mode: draggable by its grip (moves as a block),
 * title renames in place, delete button. Group management lives here now, not in a
 * read-view ⋯ menu.
 */
function EditGroupRow({ id, name, count, gridClass, ghost, isDropTarget, canManage, onRename, onDelete, collapsed, onToggleCollapse }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id });
    return (
        <li
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition, opacity: ghost || isDragging ? 0.4 : 1 }}
            className={`group grid ${gridClass} items-center border-b border-border last:border-0 transition-colors ${
                isDropTarget ? 'bg-accent-50 ring-1 ring-inset ring-accent-300' : 'bg-surface-hover/50'
            }`}
        >
            <div className="flex min-w-0 items-center gap-2 py-2 pl-1.5 pr-4">
                <RowGrip listeners={listeners} attributes={attributes} label={`Drag to reorder ${name}`} />
                {/* Collapse hides the members so the top level is short and easy to
                    reorder; the group still drags as a block either way. */}
                <button
                    type="button"
                    onClick={onToggleCollapse}
                    aria-expanded={!collapsed}
                    aria-label={`${collapsed ? 'Expand' : 'Collapse'} ${name}`}
                    className="flex h-5 w-4 shrink-0 items-center justify-center text-text-tertiary transition-colors hover:text-foreground"
                >
                    <IconChevronRight className={`h-3.5 w-3.5 transition-transform ${collapsed ? '' : 'rotate-90'}`} stroke={1.5} />
                </button>
                <IconLibrary className="h-3.5 w-3.5 shrink-0 text-text-tertiary" stroke={1.5} />
                {canManage ? (
                    <InlineEditableTitle
                        value={name}
                        onCommit={onRename}
                        ariaLabel={`Rename ${name}`}
                        className="text-[13px] font-semibold text-foreground"
                        inputClassName="text-[13px] font-semibold"
                    />
                ) : (
                    <span className="truncate text-[13px] font-semibold text-foreground" title={name}>{name}</span>
                )}
                <span className="shrink-0 text-xs text-text-tertiary">({count})</span>
                {isDropTarget && (
                    <span className="ml-1 inline-flex shrink-0 items-center gap-1 rounded-full bg-accent-100 px-1.5 py-0.5 text-[10px] font-medium text-accent-700">
                        <IconCornerDownRight className="h-3 w-3" stroke={1.5} />
                        Into group
                    </span>
                )}
            </div>
            <div /><div />
            <div className="flex items-center justify-center pr-1.5">
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

/** A workspace row while in EDIT mode: draggable, indented inside a group, renames in place. */
function EditWorkspaceRow({ id, workspace, depth, gridClass, ghost, canManage, onRename }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id });
    return (
        <li
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition, opacity: ghost || isDragging ? 0.4 : 1 }}
            className={`grid ${gridClass} items-center border-b border-border-subtle last:border-0 transition-colors hover:bg-surface-hover/60`}
        >
            <div className="flex min-w-0 items-center gap-2 py-3 pl-1.5 pr-4" style={{ paddingLeft: `${6 + depth * REORDER_INDENT}px` }}>
                <RowGrip listeners={listeners} attributes={attributes} label={`Drag to move ${workspace.name}`} />
                <IconFolderOpen className="h-4 w-4 shrink-0 text-text-tertiary" stroke={1.5} />
                {canManage ? (
                    <InlineEditableTitle
                        value={workspace.name}
                        onCommit={onRename}
                        ariaLabel={`Rename ${workspace.name}`}
                        className="text-sm font-medium text-foreground"
                        inputClassName="text-sm font-medium"
                    />
                ) : (
                    <span className="truncate text-sm font-medium text-foreground" title={workspace.name}>{workspace.name}</span>
                )}
            </div>
            <div className="py-3 pr-4">
                <span className="text-xs text-text-tertiary">
                    {workspace.documents_count} {workspace.documents_count === 1 ? 'page' : 'pages'}
                </span>
            </div>
            <div className="py-3 pr-4">
                <span className="text-xs text-text-tertiary">{timeAgo(workspace.updated_at) ?? '—'}</span>
            </div>
            <div />
        </li>
    );
}

/**
 * A drop target rendered inside an EMPTY group during reorder, so a workspace can
 * be dragged into a group that has no members yet. It is a sortable (a valid drop
 * `over`) but carries no grip, so it can never be picked up; buildTree strips it.
 */
function EmptyGroupDropRow({ id, gridClass, isDropTarget }) {
    const { setNodeRef } = useSortable({ id });
    return (
        <li ref={setNodeRef} className={`grid ${gridClass} items-center border-b border-border-subtle last:border-0 ${isDropTarget ? 'bg-accent-50' : ''}`}>
            <div className="py-3 pr-4" style={{ paddingLeft: `${6 + REORDER_INDENT}px` }}>
                <span className="text-xs italic text-text-tertiary">Drop a workspace here</span>
            </div>
        </li>
    );
}

// A read-view workspace row: a link + counts. Organizing (drag, rename, move to
// group) happens in Edit mode via EditWorkspaceRow, so this row is inert.
function SortableRow({ workspace, gridClass, inGroup = false }) {
    return (
        <li className={`group grid ${gridClass} items-center border-b border-border-subtle last:border-0 transition-colors hover:bg-surface-hover/60`}>
            {/* Members indent to the group's interior (pl-9); loose rows stay at the
                outer pl-3. Groups and loose workspaces share one order, so a loose row
                can land directly under a group's last member — without this they are
                pixel-identical and you can't tell what's filed where. */}
            <div className={`flex min-w-0 items-center gap-2 py-3 pr-4 ${inGroup ? 'pl-9' : 'pl-3'}`}>
                <span className="w-4 shrink-0" />
                <IconFolderOpen className="h-4 w-4 shrink-0 text-text-tertiary" stroke={1.5} />
                <Link href={`/workspaces/${workspace.id}`} className="min-w-0">
                    <p className="truncate text-sm font-medium text-foreground transition-colors group-hover:text-accent-600" title={workspace.name}>
                        {workspace.name}
                    </p>
                    {workspace.description && (
                        <p className="truncate text-xs text-text-secondary" title={workspace.description}>{workspace.description}</p>
                    )}
                </Link>
            </div>
            <div className="py-3 pr-4">
                <span className="text-xs text-text-tertiary">
                    {workspace.documents_count} {workspace.documents_count === 1 ? 'page' : 'pages'}
                </span>
            </div>
            <div className="py-3 pr-4">
                <span className="text-xs text-text-tertiary">{timeAgo(workspace.updated_at) ?? '—'}</span>
            </div>
        </li>
    );
}

/**
 * A read-view group section: a collapsible header + its member rows. Organizing
 * the group (drag, rename, delete, filing workspaces in/out) happens in Edit mode
 * via EditGroupRow, so this section is inert.
 */
function GroupSection({ token, collapsed, onToggleCollapse, gridClass }) {
    const group = token.group;

    return (
        <div className="border-b border-border last:border-0">
            <div className="flex items-center bg-surface-hover/50 has-[[data-state=open]]:bg-surface-hover transition-colors hover:bg-surface-hover">
                <button
                    type="button"
                    onClick={() => onToggleCollapse(`g:${group.id}`)}
                    aria-expanded={!collapsed}
                    className="flex min-w-0 flex-1 items-center gap-2 py-2 pl-3 pr-2 text-left"
                >
                    <IconChevronRight
                        className={`h-3.5 w-3.5 shrink-0 text-text-tertiary transition-transform ${collapsed ? '' : 'rotate-90'}`}
                        stroke={1.5}
                    />
                    <IconLibrary className="h-3.5 w-3.5 shrink-0 text-text-tertiary" stroke={1.5} />
                    <span className="truncate text-[13px] font-semibold text-foreground" title={group.name}>
                        {group.name}
                    </span>
                    <span className="shrink-0 text-xs text-text-tertiary">({token.items.length})</span>
                </button>
            </div>

            {!collapsed && (
                token.items.length === 0 ? (
                    <p className="px-3 py-3 pl-9 text-xs text-text-tertiary">
                        No workspaces here yet — open Edit to drag some in.
                    </p>
                ) : (
                    <ul>
                        {token.items.map((w) => (
                            <SortableRow key={w.id} workspace={w} gridClass={gridClass} inGroup />
                        ))}
                    </ul>
                )
            )}
        </div>
    );
}

/**
 * Personal quick-access list (Starred / Recently viewed) above the workspace
 * table. Collapsible; the state is a per-device display preference, so it
 * lives in localStorage like the theme choice — no server round-trip.
 */
function QuickAccess({ id, title, icon: Icon, items, meta }) {
    const storageKey = `wwwdoc:quickaccess:${id}`;
    // Closed by default — the header (with its count) is the quiet resting
    // state; expanding is a deliberate, remembered choice.
    const [collapsed, setCollapsed] = useState(() => localStorage.getItem(storageKey) !== '0');

    function toggle() {
        setCollapsed((wasCollapsed) => {
            localStorage.setItem(storageKey, wasCollapsed ? '0' : '1');
            return !wasCollapsed;
        });
    }

    return (
        <section className="min-w-0">
            <button
                type="button"
                onClick={toggle}
                aria-expanded={!collapsed}
                className="mb-2 flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary transition-colors hover:text-text-secondary"
            >
                <IconChevronRight
                    className={`h-3.5 w-3.5 transition-transform ${collapsed ? '' : 'rotate-90'}`}
                    stroke={1.5}
                />
                <Icon className="h-3.5 w-3.5" stroke={1.5} />
                {title}
                <span className="font-normal">({items.length})</span>
            </button>
            {!collapsed && (
                <div className="ui-scroll max-h-56 overflow-y-auto rounded-md border border-border bg-card">
                    {items.map((doc, idx) => (
                        <Link
                            key={doc.id}
                            href={`/documents/${doc.id}`}
                            className={`group flex items-center gap-2.5 px-3 py-2 transition-colors hover:bg-surface-hover${idx > 0 ? ' border-t border-border-subtle' : ''}`}
                        >
                            <IconFileText className="h-3.5 w-3.5 shrink-0 text-text-tertiary transition-colors group-hover:text-accent-600" stroke={1.5} />
                            <span className="min-w-0 flex-1 truncate text-sm text-foreground transition-colors group-hover:text-accent-600" title={doc.title}>{doc.title}</span>
                            <span className="shrink-0 text-xs text-text-tertiary">
                                {meta ? meta(doc) : doc.workspace.name}
                            </span>
                        </Link>
                    ))}
                </div>
            )}
        </section>
    );
}

const COLLAPSE_KEY = 'wwwdoc:wsgroups:collapsed';

export default function WorkspacesIndex({ workspaces: initial, groups = [], recent = [], starred = [], recentlyViewed = [] }) {
    const { auth } = usePage().props;
    const perms = can(auth);
    const [workspaces, setWorkspaces] = useState(initial);
    const [modalOpen, setModalOpen]   = useState(false);
    const [sortBy, setSortBy]         = useState('arranged'); // 'arranged' | 'updated'

    // Group dialogs: create/rename share GroupFormModal; delete uses ConfirmDialog.
    const [groupModal, setGroupModal]     = useState({ open: false, group: null });
    const [groupToDelete, setGroupToDelete] = useState(null);

    // Explicit reorder mode (like the page tree's): drags only mutate local
    // state, and the new order is saved once on "Done".
    const [editing, setEditing] = useState(false);
    const editDirty = useRef(false); // any unsaved edit (drag OR rename) — drives the guard + Done
    const dragDirty = useRef(false); // drags specifically — gates the structural order save
    // The flat, folder-aware forest the DnD mutates while editing (groups as
    // depth-0 pseudo-nodes; see buildForest). Separate from `layout` so the read
    // view keeps its plain sectioned tree. Empty when not editing.
    const [editForest, setEditForest] = useState([]);
    // Pending inline renames (key `g:${id}` / `w:${id}` → new name), applied
    // optimistically to the rows and persisted on Done — so Cancel discards them
    // (mirrors how dragged order is pending). A ref shadows the state so the async
    // save can read the latest map. `editDirty` covers renames too.
    const [pendingRenames, setPendingRenames] = useState({});
    const pendingRenamesRef = useRef({});
    const setRenames = (next) => { pendingRenamesRef.current = next; setPendingRenames(next); };
    // Drag state for the projection: what's held, what it's over, how far right.
    const [activeId, setActiveId]     = useState(null);
    const [overId, setOverId]         = useState(null);
    const [offsetLeft, setOffsetLeft] = useState(0);

    // The interleaved top-level order (group blocks + loose rows). Held as state
    // so reorder drags mutate it directly; rebuilt from server data + the sort
    // toggle whenever we're NOT mid-reorder (see effect below).
    const [layout, setLayout] = useState(() => buildLayout(initial, groups, 'arranged'));

    // Which group sections are collapsed (keys are `g:${id}`). Expanded by
    // default (a key is present ONLY when the user has explicitly collapsed it);
    // persisted per-device like the theme choice.
    const [collapsedKeys, setCollapsedKeys] = useState(() => {
        try { return new Set(JSON.parse(localStorage.getItem(COLLAPSE_KEY) || '[]')); }
        catch { return new Set(); }
    });

    function toggleCollapsed(key) {
        setCollapsedKeys((prev) => {
            const next = new Set(prev);
            if (next.has(key)) next.delete(key); else next.add(key);
            localStorage.setItem(COLLAPSE_KEY, JSON.stringify([...next]));
            return next;
        });
    }
    const isCollapsed = (key) => collapsedKeys.has(key);

    // Warn before losing unsaved moves on close/refresh or any in-app navigation
    // (a workspace row, a nav link, "New workspace"); see the discard modal below.
    const { promptOpen, requestLeave, confirmDiscard, dismissPrompt } = useUnsavedChangesGuard({
        active: editing,
        dirtyRef: editDirty,
        // Drags mutate `editForest`, not `workspaces`; discarding drops it and
        // rebuilds the read `layout` from the untouched server order — otherwise the
        // dragged arrangement would linger on screen after "Discard".
        revert: () => {
            editDirty.current = false;
            dragDirty.current = false;
            setRenames({});
            setEditing(false);
            setEditForest([]);
            setLayout(buildLayout(workspaces, groups, sortBy));
        },
    });

    useEffect(() => { setWorkspaces(initial); }, [initial]);

    // Rebuild the interleaved layout from server data + the sort toggle. Keyed on
    // those inputs ONLY (not `editing`): none of them change during a drag, so
    // in-progress drags are never clobbered, and — crucially — leaving reorder
    // mode does NOT re-run this. That means `layout` keeps the order you dragged
    // until the server reload lands the persisted order, with no flicker back to
    // the pre-drag arrangement in between. `startEditing` seeds a fresh layout.
    useEffect(() => {
        setLayout(buildLayout(workspaces, groups, sortBy));
    }, [workspaces, groups, sortBy]);

    const hasGroups = groups.length > 0;
    const gridClass = hasGroups ? 'grid-cols-[1fr_90px_110px_44px]' : 'grid-cols-[1fr_90px_110px]';

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } })
    );

    // Flat list for the reorder DnD: the forest flattened, with an empty-group
    // placeholder drop-row injected after any group that has no members yet — so a
    // workspace can be dragged INTO a group before it has any members. Members of a
    // COLLAPSED group are omitted so the top level stays short and easy to reorder;
    // the group still drags as a block (buildTree works off the full forest, not
    // this render list). The dragged row's descendants are hidden while dragging.
    const flatWithPlaceholders = useMemo(() => {
        const flat = flattenForDnd(editForest);
        const out = [];
        for (const item of flat) {
            // A member (or placeholder) of a collapsed group is not rendered.
            if (item.parentId && collapsedKeys.has(item.parentId)) continue;
            out.push(item);
            if (item.node.__group && !collapsedKeys.has(item.id) && !flat.some((x) => x.parentId === item.id)) {
                out.push({
                    id: `drop:${item.node.groupId}`, parentId: item.id, depth: 1,
                    node: { __placeholder: true, groupId: item.node.groupId },
                });
            }
        }
        return out;
    }, [editForest, collapsedKeys]);

    const flattenedItems = useMemo(() => {
        if (activeId == null) return flatWithPlaceholders;
        const descendants = getDescendantIds(flatWithPlaceholders, activeId);
        return flatWithPlaceholders.filter((i) => i.id === activeId || !descendants.includes(i.id));
    }, [flatWithPlaceholders, activeId]);

    const projected = activeId != null && overId != null
        ? getProjection(flattenedItems, activeId, overId, offsetLeft)
        : null;

    function resetDrag() { setActiveId(null); setOverId(null); setOffsetLeft(0); }

    function handleDragEnd({ active, over }) {
        const projection = over && activeId != null
            ? getProjection(flattenedItems, active.id, over.id, offsetLeft)
            : null;
        resetDrag();
        if (!over || !projection) return;

        // Rebuild over the REAL rows (no placeholders, descendants included so a
        // dragged group carries its members along on buildTree).
        const full = flattenForDnd(editForest);
        const activeIndex = full.findIndex((i) => i.id === active.id);
        if (activeIndex === -1) return;
        const original = full[activeIndex];

        let { depth, parentId } = projection;
        if (original.node.__group) { depth = 0; parentId = null; } // groups never nest

        // A drop onto an empty group's placeholder has no real row — resolve it to
        // just after that group's header so the workspace lands as its first member.
        let overIndex = full.findIndex((i) => i.id === over.id);
        if (overIndex === -1) {
            const gid = String(over.id).slice('drop:'.length);
            overIndex = full.findIndex((i) => i.node.__group && String(i.node.groupId) === gid);
        }
        if (overIndex === -1) return;

        if (active.id === over.id && original.parentId === parentId && original.depth === depth) return;

        const moved = full.slice();
        moved[activeIndex] = { ...original, depth, parentId };
        const sorted = arrayMove(moved, activeIndex, overIndex);

        setEditForest(buildTree(sorted));
        editDirty.current = true;
        dragDirty.current = true;
    }

    /**
     * Persist everything pending — inline renames FIRST (chained; Inertia
     * serializes visits, so they can't run concurrently), then the one atomic order
     * save. `onDone` runs after it all lands; `onFail` on any error (keeps state so
     * Done/the action can retry).
     */
    function persistPending(onDone, onFail) {
        // Optimistically reflect the arrangement in the read view so leaving Edit
        // doesn't flicker to the pre-drag order.
        setLayout(forestToLayout(editForest));

        // Capture the order payload NOW, before the rename chain's reloads re-seed
        // the forest (which would drop the drag arrangement). Only when something
        // actually moved — a pure rename must not re-save. `items` = the interleaved
        // top level; `groups` = each group's members (a cross-group drag refiles).
        const orderPayload = dragDirty.current ? deriveGroupsPayload(editForest) : null;

        const renames = Object.entries(pendingRenamesRef.current).map(([key, name]) => {
            const [type, id] = key.split(':');
            return type === 'g'
                ? { url: `/workspaces/groups/${id}`, payload: { name } }
                : { url: `/workspaces/${id}`, payload: { name } };
        });

        const opts = (handlers) => ({ preserveState: true, preserveScroll: true, ...handlers });
        const finish = () => { editDirty.current = false; dragDirty.current = false; setRenames({}); onDone?.(); };
        const runOrder = () => {
            if (!orderPayload) return finish();
            router.patch('/workspaces/top-level-order', { items: orderPayload.items, groups: orderPayload.groups }, opts({
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
        if (!editDirty.current) { setEditForest([]); return; }
        persistPending(
            () => setEditForest([]),
            () => {
                editDirty.current = true;
                setEditing(true);
                toast.error("Couldn't save your changes — they're still here, click Done to retry.");
            },
        );
    }

    /** Enter Edit mode with a fresh position-ordered forest (ignores sort view). */
    function startEditing() {
        editDirty.current = false;
        dragDirty.current = false;
        setRenames({});
        const fresh = buildLayout(workspaces, groups, 'arranged');
        setLayout(fresh);
        setEditForest(buildForest(fresh));
        setEditing(true);
    }

    /**
     * Run an immediate Edit-mode mutation (create / delete a container), flushing
     * pending drags + renames to the server FIRST so the mutation's own reload
     * can't clobber them (the re-seed effect below rebuilds the forest afterward).
     */
    function flushThen(action) {
        if (!editDirty.current) { action(); return; }
        persistPending(action, () => { editDirty.current = true; toast.error("Couldn't save your changes — try again."); });
    }

    // Inline renames are PENDING (local) until Done, so Cancel can discard them.
    const renameWorkspace = (workspace, name) => { setRenames({ ...pendingRenamesRef.current, [`w:${workspace.id}`]: name }); editDirty.current = true; };
    const renameGroup = (group, name) => { setRenames({ ...pendingRenamesRef.current, [`g:${group.id}`]: name }); editDirty.current = true; };

    // While in Edit mode, an immediate mutation reloads server props; re-seed the
    // drag forest from the fresh data so the new state shows without leaving Edit
    // mode. Safe against losing drags: every immediate action flushes pending drags
    // FIRST. Not keyed on `editing` — startEditing seeds explicitly.
    useEffect(() => {
        if (editing) setEditForest(buildForest(buildLayout(workspaces, groups, 'arranged')));
    }, [workspaces, groups]); // eslint-disable-line react-hooks/exhaustive-deps

    function deleteGroup() {
        const group = groupToDelete;
        if (!group) return;
        router.delete(`/workspaces/groups/${group.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(`Deleted the group “${group.name}”.`),
            onError: () => toast.error("Couldn't delete the group."),
        });
        setGroupToDelete(null);
    }

    const totalPages = workspaces.reduce((sum, w) => sum + (w.documents_count ?? 0), 0);

    return (
        <DocsLayout>
            <Head title="Workspaces" />

            {/* Header */}
            <div className="flex items-baseline justify-between gap-4">
                <div>
                    <h1 className="text-[19px] font-semibold text-foreground">Workspaces</h1>
                    <p className="mt-0.5 text-sm text-text-secondary">
                        {workspaces.length} {workspaces.length === 1 ? 'workspace' : 'workspaces'}
                        {hasGroups && <> · {groups.length} {groups.length === 1 ? 'group' : 'groups'}</>}
                        {' · '}
                        {totalPages} pages
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-1.5 self-center">
                    {workspaces.length > 1 && !editing && (
                        <select
                            value={sortBy}
                            onChange={(e) => setSortBy(e.target.value)}
                            className="ui-select h-[33px] rounded-sm border border-border bg-surface px-2.5 text-[13px] text-foreground outline-none transition-[border-color,box-shadow] duration-150 focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                        >
                            <option value="arranged">Default</option>
                            <option value="updated">Last updated</option>
                        </select>
                    )}
                    {/* Edit mode is arrange-only (Cancel/Done + on-row rename/delete/
                        drag). Creation + Trash live in the read-view ⋯, next to the
                        New primary and the Edit toggle. */}
                    {editing ? (
                        <>
                            {perms.create && (
                                <Button
                                    variant="outline"
                                    className="border-border hover:bg-surface-hover"
                                    onClick={() => flushThen(() => setGroupModal({ open: true, group: null }))}
                                >
                                    <IconLibraryPlus stroke={1.5} />
                                    New group
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
                                <Button onClick={() => setModalOpen(true)}>
                                    <IconPlus stroke={1.5} />
                                    New workspace
                                </Button>
                            )}
                            {(perms.create || perms.isAdmin) && (
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
                                            <DropdownMenuItem onSelect={() => setGroupModal({ open: true, group: null })}>
                                                <IconLibraryPlus stroke={1.5} />
                                                New group
                                            </DropdownMenuItem>
                                        )}
                                        {perms.isAdmin && (
                                            <>
                                                {perms.create && <DropdownMenuSeparator />}
                                                <DropdownMenuItem asChild>
                                                    <Link href="/trash">
                                                        <IconTrash stroke={1.5} />
                                                        View Trash
                                                    </Link>
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                            {perms.update && workspaces.length > 0 && (
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

            {/* Personal quick access — starred + recently viewed (hidden while editing) */}
            {!editing && (starred.length > 0 || recentlyViewed.length > 0) && (
                <div className="mt-5 space-y-3">
                    {starred.length > 0 && (
                        <QuickAccess id="starred" title="Starred" icon={IconStarFilled} items={starred} />
                    )}
                    {recentlyViewed.length > 0 && (
                        <QuickAccess
                            id="recent"
                            title="Recently viewed"
                            icon={IconHistory}
                            items={recentlyViewed}
                            meta={(doc) => timeAgo(doc.viewed_at) ?? '—'}
                        />
                    )}
                </div>
            )}

            {/* Edit-mode hint — pinned above the table so it stays visible no
                matter how long the list is. */}
            {editing && workspaces.length > 0 && (
                <div className="mt-4 flex items-start gap-2 rounded-md border border-accent-200 bg-accent-50 px-3 py-2 text-xs text-accent-700">
                    <IconPencil className="mt-0.5 h-3.5 w-3.5 shrink-0" stroke={1.5} />
                    <span>
                        Drag a workspace to reorder it, or onto a group to file it there; drag it out to the top level to ungroup. Drag a group to move the whole shelf. Click “Done” when finished.
                    </span>
                </div>
            )}

            {/* Table */}
            <div className="mt-4 overflow-hidden rounded-md border border-border bg-card">
                {/* Column headers */}
                <div className={`grid ${gridClass} border-b border-border bg-surface-hover py-2.5`}>
                    <span className="pl-3 pr-4 text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">Workspace</span>
                    <span className="pr-4 text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">Pages</span>
                    <span className="pr-4 text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">Updated</span>
                    {hasGroups && <span />}
                </div>

                {workspaces.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
                        <div className="flex h-12 w-12 items-center justify-center rounded-xl border border-accent-200 bg-accent-50">
                            <IconFolderPlus className="h-6 w-6 text-accent-600" stroke={1.5} />
                        </div>
                        <div>
                            <p className="text-sm font-medium text-foreground">No workspaces yet</p>
                            <p className="mt-0.5 text-xs text-text-tertiary">Create a workspace to start organising your docs.</p>
                        </div>
                        {perms.create && (
                            <Button
                                type="button"
                                size="xs"
                                onClick={() => setModalOpen(true)}
                                className="mt-1"
                            >
                                Create workspace
                            </Button>
                        )}
                    </div>
                ) : (
                    <DndContext
                        sensors={sensors}
                        collisionDetection={closestCenter}
                        onDragStart={editing ? ({ active }) => { setActiveId(active.id); setOverId(active.id); } : undefined}
                        onDragMove={editing ? ({ delta }) => setOffsetLeft(delta.x) : undefined}
                        onDragOver={editing ? ({ over }) => setOverId(over?.id ?? null) : undefined}
                        onDragEnd={editing ? handleDragEnd : undefined}
                        onDragCancel={editing ? resetDrag : undefined}
                    >
                        {editing ? (
                            /* One flat, projected list: group headers + their members +
                               loose rows all sort against each other, so a workspace can be
                               dragged across groups or out to loose in a single gesture. */
                            <SortableContext items={flattenedItems.map((i) => i.id)} strategy={verticalListSortingStrategy}>
                                <ul>
                                    {flattenedItems.map((item) => {
                                        if (item.node.__placeholder) {
                                            return (
                                                <EmptyGroupDropRow
                                                    key={item.id}
                                                    id={item.id}
                                                    gridClass={gridClass}
                                                    isDropTarget={projected?.parentId === item.parentId}
                                                />
                                            );
                                        }
                                        if (item.node.__group) {
                                            return (
                                                <EditGroupRow
                                                    key={item.id}
                                                    id={item.id}
                                                    name={pendingRenames[item.id] ?? item.node.group.name}
                                                    count={item.node.children?.length ?? 0}
                                                    gridClass={gridClass}
                                                    ghost={item.id === activeId}
                                                    isDropTarget={projected?.parentId === item.id && item.id !== activeId}
                                                    canManage={perms.update}
                                                    onRename={(name) => renameGroup(item.node.group, name)}
                                                    onDelete={() => flushThen(() => setGroupToDelete(item.node.group))}
                                                    collapsed={isCollapsed(item.id)}
                                                    onToggleCollapse={() => toggleCollapsed(item.id)}
                                                />
                                            );
                                        }
                                        const depth = item.id === activeId && projected ? projected.depth : item.depth;
                                        return (
                                            <EditWorkspaceRow
                                                key={item.id}
                                                id={item.id}
                                                workspace={pendingRenames[item.id] ? { ...item.node.workspace, name: pendingRenames[item.id] } : item.node.workspace}
                                                depth={depth}
                                                gridClass={gridClass}
                                                ghost={item.id === activeId}
                                                canManage={perms.update}
                                                onRename={(name) => renameWorkspace(item.node.workspace, name)}
                                            />
                                        );
                                    })}
                                </ul>
                            </SortableContext>
                        ) : (
                            /* Read view: group blocks and loose rows in one shared order.
                               No drag — the sortables are inert (handles hidden). */
                            <SortableContext items={layout.map(tokenId)} strategy={verticalListSortingStrategy}>
                                {layout.map((token) => (
                                    token.type === 'group' ? (
                                        <GroupSection
                                            key={`g:${token.id}`}
                                            token={token}
                                            collapsed={isCollapsed(`g:${token.id}`)}
                                            onToggleCollapse={toggleCollapsed}
                                            gridClass={gridClass}
                                        />
                                    ) : (
                                        <ul key={`w:${token.id}`} className="border-b border-border last:border-0">
                                            <SortableRow workspace={token.workspace} gridClass={gridClass} />
                                        </ul>
                                    )
                                ))}
                            </SortableContext>
                        )}
                    </DndContext>
                )}

                {/* Footer. Its top rule is the last section's border-b (which stays
                    on because the footer, not the section, is the last child) —
                    adding one here would stack two 1px lines into a thick seam. */}
                {workspaces.length > 0 && perms.create && (
                    <button
                        type="button"
                        onClick={() => setModalOpen(true)}
                        className="flex w-full items-center gap-1.5 px-4 py-2.5 text-sm text-text-tertiary transition-colors hover:bg-surface-hover hover:text-text-secondary"
                    >
                        <IconPlus className="h-3.5 w-3.5" stroke={1.5} />
                        New workspace
                    </button>
                )}
            </div>

            {/* Recently updated */}
            {recent.length > 0 && (
                <section className="mt-8">
                    <h2 className="mb-3 text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">
                        Recently updated
                    </h2>
                    <div className="overflow-hidden rounded-md border border-border bg-card">
                        {recent.map((doc, idx) => (
                            <Link
                                key={doc.id}
                                href={`/documents/${doc.id}`}
                                className={`group flex items-center gap-3 px-4 py-3 transition-colors hover:bg-surface-hover${idx > 0 ? ' border-t border-border-subtle' : ''}`}
                            >
                                <IconFileText className="h-4 w-4 shrink-0 text-text-tertiary transition-colors group-hover:text-accent-600" stroke={1.5} />
                                <span className="min-w-0 flex-1 truncate text-sm font-medium text-foreground transition-colors group-hover:text-accent-600" title={doc.title}>
                                    {doc.title}
                                </span>
                                <span className="shrink-0 text-xs text-text-tertiary">
                                    {doc.workspace.name}
                                </span>
                                <span className="shrink-0 pl-3 text-xs text-text-tertiary">
                                    {timeAgo(doc.updated_at)}
                                </span>
                            </Link>
                        ))}
                    </div>
                </section>
            )}

            <WorkspaceFormModal open={modalOpen} onClose={() => setModalOpen(false)} groups={groups} />

            <GroupFormModal
                open={groupModal.open}
                group={groupModal.group}
                onClose={() => setGroupModal({ open: false, group: null })}
            />

            <ConfirmDialog
                open={Boolean(groupToDelete)}
                title="Delete group?"
                message={`“${groupToDelete?.name}” will be removed. Its workspaces aren't deleted — they move out of the group.`}
                confirmLabel="Delete group"
                cancelLabel="Cancel"
                variant="danger"
                onConfirm={deleteGroup}
                onCancel={() => setGroupToDelete(null)}
            />

            <ConfirmDialog
                open={promptOpen}
                title="Discard changes?"
                message="You have unsaved order changes. Leaving reorder mode will discard them permanently."
                confirmLabel="Discard changes"
                cancelLabel="Keep editing"
                variant="danger"
                onConfirm={confirmDiscard}
                onCancel={dismissPrompt}
            />
        </DocsLayout>
    );
}
