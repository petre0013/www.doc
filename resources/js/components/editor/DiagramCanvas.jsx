import { createContext, memo, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import {
    ReactFlow,
    ReactFlowProvider,
    Background,
    Controls,
    MiniMap,
    Handle,
    Position,
    MarkerType,
    ConnectionMode,
    SelectionMode,
    NodeToolbar,
    NodeResizer,
    BaseEdge,
    EdgeLabelRenderer,
    getBezierPath,
    getStraightPath,
    getSmoothStepPath,
    useReactFlow,
    applyNodeChanges,
    applyEdgeChanges,
    addEdge,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { toast } from 'sonner';
import {
    IconPlus, IconX, IconTrash, IconCircleDot, IconServer, IconRouter, IconSwitch3,
    IconShieldLock, IconCloud, IconDatabase, IconDeviceDesktop, IconAccessPoint,
    IconServer2, IconArrowsSplit2, IconKey, IconWorld, IconWifi, IconDeviceLaptop,
    IconDeviceMobile, IconPhone, IconPrinter, IconDeviceCctv, IconBroadcast,
    IconBrandDocker, IconStack2, IconMail, IconActivity, IconLock, IconLockOpen, IconUser, IconUsers,
    IconChevronDown, IconChevronUp,
    IconLineDashed, IconArrowNarrowRight, IconArrowsHorizontal, IconMinus, IconSquareDashed,
    IconVectorSpline, IconLine, IconCornerDownRight, IconMap2,
    IconArrowBackUp, IconArrowForwardUp, IconGridDots, IconCopy, IconDownload,
    IconLayoutAlignLeft, IconLayoutAlignCenter, IconLayoutAlignRight,
    IconLayoutAlignTop, IconLayoutAlignMiddle, IconLayoutAlignBottom,
    IconLayoutDistributeHorizontal, IconLayoutDistributeVertical,
} from '@tabler/icons-react';

/**
 * The editable React Flow canvas for a diagram node. Lazy-loaded by the diagram
 * node view so React Flow only enters the bundle when a diagram is actually edited.
 * (The persisted TipTap node type is still `networkDiagram` — a data contract.)
 *
 * The node's `graph` attr is the source of truth; this canvas seeds its working
 * state from it once and reports changes back via `onChange` (cleaned of React
 * Flow's transient fields and our render-only callbacks). Refs hold the live
 * state synchronously so persistence after a drag/connect reads final values.
 */

const uid = () =>
    (window.crypto?.randomUUID?.() ?? `id-${Date.now()}-${Math.random().toString(36).slice(2)}`);

// Handlers the custom node needs but that must NOT be persisted — passed via
// context instead of polluting node.data (which is serialized into the graph).
const NodeBehavior = createContext({
    editable: false, onLabelChange: () => {}, onLabelLive: () => {}, onKindChange: () => {},
    onPropsChange: () => {}, onPropsLive: () => {},
    onNodeColorChange: () => {}, onNodeColorLive: () => {}, onLockToggle: () => {}, onPersist: () => {},
    snapToGrid: false, interactive: true, soloSelection: false,
});

// Device kinds a node can take. `id` is persisted in node.data.kind; the icon and
// default label are render-only. Generic is the plain box (no icon).
// `core` kinds are the common ones shown by default in the type picker; the rest
// hide behind an "expand" toggle so the picker isn't overcrowded.
const NODE_KINDS = [
    { id: 'generic',      label: 'Node',          Icon: IconCircleDot,    core: true },
    { id: 'server',       label: 'Server',        Icon: IconServer,       core: true },
    { id: 'database',     label: 'Database',      Icon: IconDatabase,     core: true },
    { id: 'router',       label: 'Router',        Icon: IconRouter,       core: true },
    { id: 'switch',       label: 'Switch',        Icon: IconSwitch3,      core: true },
    { id: 'firewall',     label: 'Firewall',      Icon: IconShieldLock,   core: true },
    { id: 'cloud',        label: 'Cloud',         Icon: IconCloud,        core: true },
    { id: 'workstation',  label: 'Workstation',   Icon: IconDeviceDesktop, core: true },
    { id: 'storage',      label: 'Storage',       Icon: IconServer2 },
    { id: 'loadbalancer', label: 'Load balancer', Icon: IconArrowsSplit2 },
    { id: 'vpn',          label: 'VPN / Key',     Icon: IconKey },
    { id: 'internet',     label: 'Internet',      Icon: IconWorld },
    { id: 'ap',           label: 'Access Point',  Icon: IconAccessPoint },
    { id: 'wifi',         label: 'Wi-Fi',         Icon: IconWifi },
    { id: 'laptop',       label: 'Laptop',        Icon: IconDeviceLaptop },
    { id: 'mobile',       label: 'Mobile',        Icon: IconDeviceMobile },
    { id: 'phone',        label: 'IP Phone',      Icon: IconPhone },
    { id: 'printer',      label: 'Printer',       Icon: IconPrinter },
    { id: 'camera',       label: 'Camera',        Icon: IconDeviceCctv },
    { id: 'iot',          label: 'IoT / Sensor',  Icon: IconBroadcast },
    { id: 'container',    label: 'Container',     Icon: IconBrandDocker },
    { id: 'vm',           label: 'VM / Cluster',  Icon: IconStack2 },
    { id: 'mail',         label: 'Mail',          Icon: IconMail },
    { id: 'monitor',      label: 'Monitoring',    Icon: IconActivity },
    { id: 'security',     label: 'Security',      Icon: IconLock },
    { id: 'user',         label: 'User',          Icon: IconUser },
    { id: 'users',        label: 'Team',          Icon: IconUsers },
];
const KIND_BY_ID = Object.fromEntries(NODE_KINDS.map((k) => [k.id, k]));
const kindMeta = (kind) => KIND_BY_ID[kind] ?? KIND_BY_ID.generic;
const CORE_KINDS = NODE_KINDS.filter((k) => k.core);
const EXTRA_KIND_IDS = new Set(NODE_KINDS.filter((k) => ! k.core).map((k) => k.id));

// Node fill colours for grouping by zone / VLAN. `id` is persisted in
// node.data.color; the rest is render-only (light fill + matching border +
// accent for the icon). `swatch` is the palette button colour.
const NODE_COLORS = [
    { id: 'default',    bg: 'var(--surface)', border: 'var(--border)', accent: 'var(--accent-600)', swatch: '#FBFAF5' },
    { id: 'sage',       bg: '#EAF1E5', border: '#BFD2B5', accent: '#4B6840', swatch: '#CDDEC4' },
    { id: 'blue',       bg: '#E9EFF4', border: '#B8CCDD', accent: '#42637E', swatch: '#C4D6E4' },
    { id: 'amber',      bg: '#F6EEDC', border: '#E5CF9F', accent: '#9A6F2E', swatch: '#EBD6A6' },
    { id: 'terracotta', bg: '#F4E5DF', border: '#DDB3A6', accent: '#A04A33', swatch: '#E6C2B5' },
    { id: 'purple',     bg: '#EEE9F4', border: '#CDBDDD', accent: '#6A5286', swatch: '#D6C7E6' },
];
const COLOR_BY_ID = Object.fromEntries(NODE_COLORS.map((c) => [c.id, c]));
const isHexColor = (id) => typeof id === 'string' && /^#[0-9a-fA-F]{3,8}$/.test(id);
// Resolve a node's `color` (a preset id OR a custom #hex) to a render meta. For a
// custom hex the fill/border are derived from it the same way the zones tint.
const colorMeta = (id) => {
    if (isHexColor(id)) {
        return {
            id,
            bg: `color-mix(in srgb, ${id} 16%, var(--surface))`,
            border: `color-mix(in srgb, ${id} 55%, var(--border))`,
            accent: id,
            swatch: id,
        };
    }
    return COLOR_BY_ID[id] ?? COLOR_BY_ID.default;
};

// Swatch colour for a node in the minimap. Concrete hexes only (the minimap's
// SVG fills can't resolve the CSS-var tokens the default palette uses).
const miniMapNodeColor = (n) => {
    const id = n.data?.color ?? (n.type === 'group' ? 'sage' : 'default');
    if (isHexColor(id)) return id;
    if (id === 'default') return '#9FB994'; // accent-300 for the plain box / zone
    const c = colorMeta(id);
    return n.type === 'group' ? c.border : c.accent;
};

// Swatch row: the preset colours plus a native colour input for any custom hex.
// `onPick` commits a discrete choice (preset click); `onLive` streams the custom
// picker's drag (the canvas debounces it into a single undo entry).
function NodeColorRow({ value, onPick, onLive, includeDefault = true }) {
    const isCustom = isHexColor(value);
    const [localValue, setLocalValue] = useState(isCustom ? value : '#7E9D72');
    
    useEffect(() => {
        setLocalValue(isCustom ? value : '#7E9D72');
    }, [value, isCustom]);

    const colors = includeDefault ? NODE_COLORS : NODE_COLORS.filter((c) => c.id !== 'default');
    return (
        <div className="flex items-center gap-1 px-0.5">
            {colors.map((c) => (
                <button
                    key={c.id}
                    type="button"
                    title={`${c.id[0].toUpperCase()}${c.id.slice(1)}`}
                    onClick={() => onPick(c.id)}
                    className={`h-4 w-4 rounded-full border ${value === c.id ? 'border-foreground' : 'border-border'}`}
                    style={{ background: c.swatch }}
                />
            ))}
            <label
                title="Custom colour"
                className={`relative h-4 w-4 cursor-pointer overflow-hidden rounded-full border ${isCustom ? 'border-foreground' : 'border-border'}`}
                style={{ background: isCustom ? localValue : 'conic-gradient(from 90deg, #B5573E, #C99650, #4B6840, #6E8AA7, #6A5286, #B5573E)' }}
            >
                <input
                    type="color"
                    value={localValue}
                    onChange={(e) => {
                        setLocalValue(e.target.value);
                        onLive(e.target.value);
                    }}
                    className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                />
            </label>
        </div>
    );
}

// Position-lock toggle shown in a node/zone toolbar. Locked elements can't be
// dragged or nudged (still selectable, editable, deletable).
function LockToggle({ locked, onToggle }) {
    return (
        <button
            type="button"
            onClick={onToggle}
            title={locked ? 'Unlock position' : 'Lock position'}
            aria-label={locked ? 'Unlock position' : 'Lock position'}
            aria-pressed={locked}
            className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-sm transition-colors ${
                locked ? 'bg-accent-100 text-accent-700' : 'text-text-secondary hover:bg-surface-hover hover:text-foreground'
            }`}
        >
            {locked ? <IconLock className="h-3.5 w-3.5" stroke={1.5} /> : <IconLockOpen className="h-3.5 w-3.5" stroke={1.5} />}
        </button>
    );
}

// Small badge marking a locked element so its state reads without selecting it.
// `className` positions it (nodes overhang the corner; zones tuck inside).
function LockBadge({ className }) {
    return (
        <span
            className={`absolute flex h-4 w-4 items-center justify-center rounded-full border border-border bg-surface text-text-tertiary shadow-sm ${className}`}
            title="Position locked"
        >
            <IconLock className="h-2.5 w-2.5" stroke={2} />
        </span>
    );
}

// A connection point on each side of a node. With ConnectionMode.Loose every
// handle can be both a source and a target, so any node connects to any node.
const HANDLE_SIDES = [
    { id: 'top', position: Position.Top },
    { id: 'right', position: Position.Right },
    { id: 'bottom', position: Position.Bottom },
    { id: 'left', position: Position.Left },
];

function LabeledNode({ id, data, selected, dragging }) {
    const { editable, onLabelChange, onLabelLive, onKindChange, onPropsChange, onPropsLive, onNodeColorChange, onNodeColorLive, onLockToggle, onPersist, snapToGrid, soloSelection } = useContext(NodeBehavior);
    const name = (data.label ?? '').trim();
    const props = Array.isArray(data.props) ? data.props : [];

    const kind = data.kind ?? 'generic';
    // Start the icon picker expanded if this node already uses a non-core icon,
    // so its current type is visible without hunting for the expand toggle.
    const [kindsExpanded, setKindsExpanded] = useState(() => EXTRA_KIND_IDS.has(kind));
    
    // Auto-collapse when deselected
    useEffect(() => {
        if (!selected) {
            setKindsExpanded(EXTRA_KIND_IDS.has(data.kind ?? 'generic'));
        }
    }, [selected, data.kind]);
    const shownKinds = kindsExpanded ? NODE_KINDS : CORE_KINDS;
    const Icon = kindMeta(kind).Icon;
    const color = colorMeta(data.color ?? 'default');

    return (
        // h-full/w-full so a manually-resized node fills its box; on an un-resized
        // node the wrapper is auto-sized, so this just resolves to the label size
        // (minWidth keeps small labels legible).
        <div
            className={`group relative flex h-full w-full ${props.length ? 'flex-col items-start justify-start gap-0.5' : 'items-center justify-center'} gap-1.5 rounded-md border px-3 py-2 text-xs text-foreground shadow-md`}
            style={{ minWidth: 90, background: color.bg, borderColor: color.border }}
        >
            {/* At-rest lock badge only: while selected the resize handle sits on this
                same corner (and the toolbar shows the lock state), and in the read
                view locks are meaningless — so hide it in both cases. */}
            {editable && !selected && data.locked && <LockBadge className="-right-1.5 -top-1.5" />}
            {editable && (
                <NodeResizer
                    minWidth={90}
                    minHeight={36}
                    isVisible={!dragging && selected}
                    lineClassName="!border-accent-400"
                    handleClassName="!h-3 !w-3 !rounded-sm !border-accent-400 !bg-surface"
                    onResizeEnd={onPersist}
                />
            )}

            {/* Type + colour picker — appears above the node while it's the whole
                selection (a marquee over several nodes would stack one of these
                panels per node). */}
            {editable && (
                <NodeToolbar isVisible={!dragging && selected && soloSelection} position={Position.Top} offset={8}>
                    <div className="flex flex-col gap-1 rounded-md border border-border bg-surface p-1 shadow-md">
                        <div className="grid grid-cols-9 gap-0.5">
                            {shownKinds.map((k) => (
                                <button
                                    key={k.id}
                                    type="button"
                                    title={k.label}
                                    onClick={() => onKindChange(id, k.id)}
                                    className={`flex h-6 w-6 items-center justify-center rounded-sm transition-colors ${
                                        kind === k.id ? 'bg-accent-100 text-accent-700' : 'text-text-secondary hover:bg-surface-hover hover:text-foreground'
                                    }`}
                                >
                                    <k.Icon className="h-3.5 w-3.5" stroke={1.5} />
                                </button>
                            ))}
                            <button
                                type="button"
                                title={kindsExpanded ? 'Show fewer icons' : 'More icons'}
                                onClick={() => setKindsExpanded((e) => !e)}
                                className="flex h-6 w-6 items-center justify-center rounded-sm text-text-secondary transition-colors hover:bg-surface-hover hover:text-foreground"
                            >
                                {kindsExpanded
                                    ? <IconChevronUp className="h-3.5 w-3.5" stroke={1.5} />
                                    : <IconChevronDown className="h-3.5 w-3.5" stroke={1.5} />}
                            </button>
                        </div>
                        <div className="flex items-center gap-1">
                            <NodeColorRow
                                value={data.color ?? 'default'}
                                onPick={(c) => onNodeColorChange(id, c)}
                                onLive={(c) => onNodeColorLive(id, c)}
                            />
                            <LockToggle locked={!!data.locked} onToggle={() => onLockToggle(id)} />
                        </div>
                        <div className="flex flex-col gap-1 border-t border-border pt-1">
                            <input
                                type="text"
                                value={data.label ?? ''}
                                onChange={(e) => onLabelLive(id, e.target.value)}
                                onBlur={(e) => onLabelChange(id, e.target.value)}
                                onKeyDown={(e) => e.stopPropagation()}
                                placeholder="Name"
                                aria-label="Node name"
                                className="w-40 rounded-sm border border-border bg-canvas px-1.5 py-0.5 text-xs outline-none focus:border-accent-400"
                            />
                            {props.map((p, i) => (
                                <div key={i} className="flex items-center gap-1">
                                    <input
                                        type="text"
                                        value={p.key}
                                        onChange={(e) => onPropsLive(id, props.map((q, j) => j === i ? { ...q, key: e.target.value } : q))}
                                        onBlur={() => onPropsChange(id, props)}
                                        onKeyDown={(e) => e.stopPropagation()}
                                        placeholder="Key"
                                        aria-label="Property key"
                                        className="w-16 rounded-sm border border-border bg-canvas px-1.5 py-0.5 text-xs outline-none focus:border-accent-400"
                                    />
                                    <input
                                        type="text"
                                        value={p.value}
                                        onChange={(e) => onPropsLive(id, props.map((q, j) => j === i ? { ...q, value: e.target.value } : q))}
                                        onBlur={() => onPropsChange(id, props)}
                                        onKeyDown={(e) => e.stopPropagation()}
                                        placeholder="Value"
                                        aria-label="Property value"
                                        className="w-24 rounded-sm border border-border bg-canvas px-1.5 py-0.5 text-xs outline-none focus:border-accent-400"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => onPropsChange(id, props.filter((_, j) => j !== i))}
                                        title="Remove property"
                                        aria-label="Remove property"
                                        className="flex h-5 w-5 shrink-0 items-center justify-center rounded-sm text-text-tertiary hover:bg-danger hover:text-text-inverse"
                                    >
                                        <IconX className="h-3.5 w-3.5" stroke={1.5} />
                                    </button>
                                </div>
                            ))}
                            <button
                                type="button"
                                onClick={() => onPropsChange(id, [...props, { key: '', value: '' }])}
                                className="flex items-center gap-1 rounded-sm px-1 py-0.5 text-xs text-text-secondary hover:bg-surface-hover hover:text-foreground"
                            >
                                <IconPlus className="h-3.5 w-3.5" stroke={1.5} /> Add property
                            </button>
                        </div>
                    </div>
                </NodeToolbar>
            )}

            {/* Connection points stay in the DOM so edges keep anchoring to them,
                but the dots are hidden until you'd actually use one: never in the
                read view, and in the editor only while the node is hovered or
                selected (a connected edge already marks its own side). */}
            {HANDLE_SIDES.map(({ id, position }) => (
                <Handle
                    key={id}
                    id={id}
                    type="source"
                    position={position}
                    isConnectable={editable}
                    className={`!h-2 !w-2 !border !border-border !bg-accent-300 !transition-opacity ${
                        editable
                            ? (selected ? '!opacity-100' : '!opacity-0 group-hover:!opacity-100')
                            : '!opacity-0 !pointer-events-none'
                    }`}
                />
            ))}

            {/* Name row (icon + bold name) */}
            <div className="flex items-center gap-1.5 font-bold">
                {kind !== 'generic' && <Icon className="h-4 w-4 shrink-0" stroke={1.5} style={{ color: color.accent }} />}
                <span className={props.length ? 'text-left' : 'text-center'}>{name || 'Node'}</span>
            </div>

            {/* Property rows */}
            {props.length > 0 && (
                <div className="mt-0.5 grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5 text-[10px] font-normal leading-tight">
                    {props.map((p, i) => (
                        <div key={i} className="contents">
                            <span className="text-text-secondary">{p.key}</span>
                            <span className="text-foreground">{p.value}</span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

// A zone / grouping container. Renders behind device nodes; nodes dropped inside
// become its children (handled in onNodeDragStop) and move with it.
function GroupNode({ id, data, selected, dragging }) {
    const { editable, interactive, onLabelChange, onNodeColorChange, onNodeColorLive, onLockToggle, onPersist, soloSelection } = useContext(NodeBehavior);
    const color = colorMeta(data.color ?? 'sage');
    const [editing, setEditing] = useState(false);
    const [val, setVal] = useState(data.label ?? 'Zone');

    useEffect(() => { setVal(data.label ?? 'Zone'); }, [data.label]);

    const commit = () => { setEditing(false); onLabelChange(id, val.trim() || 'Zone'); };

    return (
        <div
            className="relative h-full w-full rounded-md border"
            style={{ background: `color-mix(in srgb, ${color.swatch} 30%, transparent)`, borderColor: color.border }}
        >
            {editable && (
                <NodeResizer
                    minWidth={144}
                    minHeight={90}
                    isVisible={!dragging && selected}
                    lineClassName="!border-accent-400"
                    handleClassName="!h-3 !w-3 !rounded-sm !border-accent-400 !bg-surface"
                    onResizeEnd={onPersist}
                />
            )}
            {editable && (
                <NodeToolbar isVisible={!dragging && selected && soloSelection} position={Position.Top} offset={8}>
                    <div className="flex items-center gap-1 rounded-md border border-border bg-surface p-1 shadow-md">
                        <NodeColorRow
                            value={data.color ?? 'sage'}
                            onPick={(c) => onNodeColorChange(id, c)}
                            onLive={(c) => onNodeColorLive(id, c)}
                            includeDefault={false}
                        />
                        <LockToggle locked={!!data.locked} onToggle={() => onLockToggle(id)} />
                    </div>
                </NodeToolbar>
            )}
            {editable && !selected && data.locked && <LockBadge className="right-1.5 top-1.5" />}
            <div className="absolute left-2 top-1.5 max-w-[85%]" onDoubleClick={() => editable && interactive && setEditing(true)}>
                {editing ? (
                    <input
                        autoFocus
                        onFocus={(e) => e.target.select()}
                        value={val}
                        onChange={(e) => setVal(e.target.value)}
                        onBlur={commit}
                        onKeyDown={(e) => {
                            // Keep label keystrokes inside the input (like the other
                            // label editors) so Esc cancels the edit here and does
                            // NOT bubble to the maximize handler's window listener.
                            e.stopPropagation();
                            if (e.key === 'Enter') { e.preventDefault(); commit(); }
                            if (e.key === 'Escape') { setEditing(false); setVal(data.label ?? 'Zone'); }
                        }}
                        className="nodrag rounded-sm border border-accent-400 bg-canvas px-1 text-xs outline-none"
                    />
                ) : (
                    <span className="text-xs font-semibold" style={{ color: color.accent }}>{data.label || 'Zone'}</span>
                )}
            </div>
        </div>
    );
}

// memo() so a node only re-renders when its own props change — during a drag only
// the moved node updates, instead of every node re-rendering on each frame.
const nodeTypes = { labeled: memo(LabeledNode), group: memo(GroupNode) };
const PAN_ON_DRAG = [1, 2];
const DEFAULT_VIEWPORT = { x: 0, y: 0, zoom: 1 };

// Order groups so a parent zone always precedes the zones nested inside it —
// React Flow requires every parent to appear before its children, and array
// order also drives paint order (later = on top), so inner zones sit above
// their container. Stable otherwise; the `visiting` set makes a malformed
// parent cycle terminate rather than recurse forever (the real cycle guard
// lives in reparentOnDragStop).
const topoSortGroups = (groups) => {
    const byId = new Map(groups.map((g) => [g.id, g]));
    const emitted = new Set();
    const visiting = new Set();
    const out = [];
    const visit = (g) => {
        if (emitted.has(g.id) || visiting.has(g.id)) return;
        visiting.add(g.id);
        const parent = g.parentId ? byId.get(g.parentId) : null;
        if (parent) visit(parent);
        visiting.delete(g.id);
        emitted.add(g.id);
        out.push(g);
    };
    groups.forEach(visit);
    return out;
};

// Groups first (topologically, ancestors before descendants), then every other
// node. Keeping all non-group nodes after all groups preserves the invariant
// that a device node always paints above any zone, while the group ordering
// lets zones nest.
const sortGroupsFirst = (nodes) => {
    const groups = nodes.filter((n) => n.type === 'group');
    const rest = nodes.filter((n) => n.type !== 'group');
    return [...topoSortGroups(groups), ...rest];
};

// Every node transitively parented under `rootId` (BFS over parentId). Drives
// the self/descendant exclusion when re-parenting a zone, so a zone can never be
// dropped into its own child (which would create a cycle).
const collectDescendants = (rootId, nodes) => {
    const out = new Set();
    let frontier = new Set([rootId]);
    while (frontier.size) {
        const next = new Set();
        for (const n of nodes) {
            if (n.parentId && frontier.has(n.parentId) && !out.has(n.id)) {
                out.add(n.id);
                next.add(n.id);
            }
        }
        frontier = next;
    }
    return out;
};

// ── Edges ────────────────────────────────────────────────────────────────────

const EDGE_COLORS = ['#8E938E', '#4B6840', '#6E8AA7', '#C99650', '#B5573E']; // gray, sage, blue, amber, terracotta
const ARROW_MODES = ['end', 'both', 'none'];
const ARROW_ICON = { end: IconArrowNarrowRight, both: IconArrowsHorizontal, none: IconMinus };

// How a connection routes from node to node. `curved` is the default bezier;
// `straight` is a direct line; `step` is an orthogonal (right-angle) path with
// gently rounded corners.
const ROUTING_MODES = ['curved', 'straight', 'step'];
const ROUTING_ICON = { curved: IconVectorSpline, straight: IconLine, step: IconCornerDownRight };
const ROUTING_LABEL = { curved: 'Curved', straight: 'Straight', step: 'Step' };

const edgeData = (e) => ({
    label: '', lineStyle: 'solid', arrows: 'end', routing: 'curved', color: EDGE_COLORS[0], ...(e.data || {}),
});

// Build React Flow's visual props (type + colored arrow markers) from edge.data,
// which is the persisted source of truth. Re-run whenever an edge's data changes.
const decorateEdge = (e) => {
    const data = edgeData(e);
    const marker = (on) => (on ? { type: MarkerType.ArrowClosed, color: data.color, width: 16, height: 16 } : undefined);
    return {
        ...e,
        type: 'configurable',
        data,
        markerEnd: marker(data.arrows === 'end' || data.arrows === 'both'),
        markerStart: marker(data.arrows === 'both'),
    };
};

function ConfigurableEdge({ id, sourceX, sourceY, targetX, targetY, sourcePosition, targetPosition, markerStart, markerEnd, data, selected }) {
    const { editable, onEdgeChange, onEdgeDelete, soloSelection } = useContext(NodeBehavior);
    const d = edgeData({ data });
    const geom = { sourceX, sourceY, sourcePosition, targetX, targetY, targetPosition };
    const [path, labelX, labelY] =
        d.routing === 'straight' ? getStraightPath({ sourceX, sourceY, targetX, targetY })
        : d.routing === 'step' ? getSmoothStepPath({ ...geom, borderRadius: 8 })
        : getBezierPath(geom);

    return (
        <>
            <BaseEdge
                id={id}
                path={path}
                markerStart={markerStart}
                markerEnd={markerEnd}
                style={{
                    stroke: d.color,
                    strokeWidth: selected ? 2.5 : 1.5,
                    strokeDasharray: d.lineStyle === 'dashed' ? '6 4' : undefined,
                }}
            />
            <EdgeLabelRenderer>
                {d.label && (
                    <div
                        className="rounded-sm border border-border-subtle bg-surface/95 px-1 py-px text-[10px] font-medium text-text-secondary"
                        style={{ position: 'absolute', transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)`, pointerEvents: 'none', zIndex: 5 }}
                    >
                        {d.label}
                    </div>
                )}

                {editable && selected && soloSelection && (
                    <div
                        className="nodrag nopan flex items-center gap-1 rounded-md border border-border bg-surface p-1 shadow-md"
                        // High z-index so the controls sit ABOVE nodes: the edge-label
                        // layer renders before the nodes layer, so without this a nearby
                        // node covers the toolbar and swallows its clicks.
                        style={{ position: 'absolute', transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY - 30}px)`, pointerEvents: 'all', zIndex: 1000 }}
                    >
                        <EdgeLabelInput value={d.label} onCommit={(label) => onEdgeChange(id, { label })} />
                        <EdgeIconButton
                            active={d.lineStyle === 'dashed'}
                            title={d.lineStyle === 'dashed' ? 'Dashed line' : 'Solid line'}
                            onClick={() => onEdgeChange(id, { lineStyle: d.lineStyle === 'dashed' ? 'solid' : 'dashed' })}
                        >
                            <IconLineDashed className="h-3.5 w-3.5" stroke={1.5} />
                        </EdgeIconButton>
                        {(() => { const A = ARROW_ICON[d.arrows]; return (
                            <EdgeIconButton
                                title={`Arrows: ${d.arrows}`}
                                onClick={() => onEdgeChange(id, { arrows: ARROW_MODES[(ARROW_MODES.indexOf(d.arrows) + 1) % ARROW_MODES.length] })}
                            >
                                <A className="h-3.5 w-3.5" stroke={1.5} />
                            </EdgeIconButton>
                        ); })()}
                        {(() => { const R = ROUTING_ICON[d.routing]; return (
                            <EdgeIconButton
                                title={`Routing: ${ROUTING_LABEL[d.routing]}`}
                                onClick={() => onEdgeChange(id, { routing: ROUTING_MODES[(ROUTING_MODES.indexOf(d.routing) + 1) % ROUTING_MODES.length] })}
                            >
                                <R className="h-3.5 w-3.5" stroke={1.5} />
                            </EdgeIconButton>
                        ); })()}
                        <span className="mx-0.5 h-4 w-px bg-border" />
                        {EDGE_COLORS.map((c) => (
                            <button
                                key={c}
                                type="button"
                                title="Line colour"
                                onClick={() => onEdgeChange(id, { color: c })}
                                className={`h-4 w-4 rounded-full border ${d.color === c ? 'border-foreground' : 'border-border'}`}
                                style={{ background: c }}
                            />
                        ))}
                        <span className="mx-0.5 h-4 w-px bg-border" />
                        <EdgeIconButton title="Delete connection" danger onClick={() => onEdgeDelete(id)}>
                            <IconTrash className="h-3.5 w-3.5" stroke={1.5} />
                        </EdgeIconButton>
                    </div>
                )}
            </EdgeLabelRenderer>
        </>
    );
}

function EdgeLabelInput({ value, onCommit }) {
    const [v, setV] = useState(value ?? '');
    useEffect(() => { setV(value ?? ''); }, [value]);
    return (
        <input
            value={v}
            onChange={(e) => setV(e.target.value)}
            onBlur={() => onCommit(v.trim())}
            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); } }}
            placeholder="Label"
            className="h-5 w-20 rounded-sm bg-canvas px-1 text-[11px] text-foreground outline-none placeholder:text-text-tertiary"
        />
    );
}

function EdgeIconButton({ active, danger, title, onClick, children }) {
    return (
        <button
            type="button"
            title={title}
            onClick={onClick}
            className={`flex h-5 w-5 items-center justify-center rounded-sm transition-colors ${
                active ? 'bg-accent-100 text-accent-700'
                : danger ? 'text-text-secondary hover:bg-danger hover:text-text-inverse'
                : 'text-text-secondary hover:bg-surface-hover hover:text-foreground'
            }`}
        >
            {children}
        </button>
    );
}

// Compact toolbar button used by the align/distribute cluster — same flat,
// bordered card styling as the duplicate/download controls beside it.
function ToolbarIconButton({ title, onClick, disabled, children }) {
    return (
        <button
            type="button"
            title={title}
            onClick={onClick}
            disabled={disabled}
            className="flex items-center justify-center rounded-sm border border-border bg-card px-1.5 py-1 text-text-secondary shadow-sm transition-colors hover:bg-surface-hover hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-card disabled:hover:text-text-secondary"
        >
            {children}
        </button>
    );
}

const edgeTypes = { configurable: memo(ConfigurableEdge) };

// Persisted shape — strip React Flow's transient fields and render-only data.
const cleanNodes = (nodes) =>
    nodes.map((n) => {
        if (n.type === 'group') {
            const g = {
                id: n.id,
                type: 'group',
                position: n.position,
                width: n.width,
                height: n.height,
                data: { label: n.data?.label ?? 'Zone', color: n.data?.color ?? 'sage' },
            };
            if (n.parentId) g.parentId = n.parentId;   // a zone nested in another zone
            if (n.data?.locked) g.data.locked = true;  // position lock
            return g;
        }
        const props = (Array.isArray(n.data?.props) ? n.data.props : [])
            .map((p) => ({ key: (p?.key ?? '').trim(), value: (p?.value ?? '').trim() }))
            .filter((p) => p.key !== '' || p.value !== '');
        const out = {
            id: n.id,
            type: 'labeled',
            position: n.position,
            data: { label: n.data?.label ?? '', kind: n.data?.kind ?? 'generic', color: n.data?.color ?? 'default', props },
        };
        if (n.data?.locked) out.data.locked = true;  // position lock
        if (n.parentId) out.parentId = n.parentId;   // membership in a zone
        if (n.width != null) out.width = n.width;     // present only once manually resized
        if (n.height != null) out.height = n.height;
        return out;
    });
const cleanEdges = (edges) =>
    edges.map((e) => ({
        id: e.id,
        source: e.source,
        target: e.target,
        sourceHandle: e.sourceHandle ?? null,
        targetHandle: e.targetHandle ?? null,
        data: edgeData(e),
    }));

// Mirror of App\Support\DiagramSvg::normalizeNode — turns a node's data into a
// name + ordered {key,value} props, migrating a legacy multi-line label (first
// line = name, remaining lines = value-only props) when no structured props
// exist. Keeps the client and the server SVG in agreement.
const normalizeNodeData = (data = {}) => {
    const rawProps = Array.isArray(data.props) ? data.props : [];
    let props = rawProps
        .map((p) => ({ key: (p?.key ?? '').trim(), value: (p?.value ?? '').trim() }))
        .filter((p) => p.key !== '' || p.value !== '');

    const lines = String(data.label ?? '')
        .split('\n')
        .map((l) => l.trim())
        .filter((l) => l !== '');
    const name = lines[0] ?? '';

    if (props.length === 0 && lines.length > 1) {
        props = lines.slice(1).map((value) => ({ key: '', value }));
    }
    return { name, props };
};

// Inflate a persisted graph back into React Flow's working shape — used both to
// seed the canvas and to restore a snapshot on undo/redo.
const hydrateNodes = (raw) =>
    sortGroupsFirst((raw ?? []).map((n) => {
        // A persisted lock maps to React Flow's `draggable: false` so the element
        // loads un-draggable (the toggle keeps this in sync on change).
        const draggable = n.data?.locked ? false : undefined;
        if (n.type === 'group') {
            return { ...n, type: 'group', width: n.width ?? 240, height: n.height ?? 150, draggable };
        }
        const { name, props } = normalizeNodeData(n.data ?? {});
        return { ...n, type: 'labeled', draggable, data: { ...n.data, label: name, props } };
    }));
const hydrateEdges = (raw) =>
    (raw ?? []).map((e) => decorateEdge({
        ...e,
        // Legacy edges (saved before per-side handles) were always bottom→top.
        sourceHandle: e.sourceHandle ?? 'bottom',
        targetHandle: e.targetHandle ?? 'top',
    }));

const HISTORY_LIMIT = 60;

// Hoisted so it isn't a fresh object on every render (which makes React Flow
// re-run its option effects each drag frame).
const PRO_OPTIONS = { hideAttribution: true };

// Snap step for the optional grid — matches the dotted Background gap so nodes
// land on the dots.
const SNAP_GRID = [18, 18];

// Snap a world-space position to the grid. New nodes are placed here so their
// origin sits on a grid line: React Flow's NodeResizer snaps the pointer but not
// the node's origin, so an off-grid origin makes a snapped resize compute
// arbitrary, un-roundable sizes (the "weird height" you can't reset). With the
// origin on-grid, snapped pointer − snapped origin is always a clean multiple.
const snapToGridPos = ({ x, y }) => ({
    x: Math.round(x / SNAP_GRID[0]) * SNAP_GRID[0],
    y: Math.round(y / SNAP_GRID[1]) * SNAP_GRID[1],
});

// Padding (flow units) added around the graph's bounding box to form the
// read-view pan boundary, so viewers can pan/zoom but not wander into the void.
const READ_VIEW_MARGIN = 80;

function Canvas({ graph, editable, name, onChange, onActivate }) {
    const seed = useRef(graph ?? { nodes: [], edges: [], viewport: { x: 0, y: 0, zoom: 1 } });
    const wrapperRef = useRef(null);
    const rf = useReactFlow();

    // Persisted per-diagram settings (snap-to-grid + the routing new connections
    // take). Seeded from the saved graph and written back via persist().
    const settingsSeed = seed.current.settings ?? {};
    const [snap, setSnap] = useState(settingsSeed.snap ?? true);
    const [defaultRouting, setDefaultRouting] = useState(
        ROUTING_MODES.includes(settingsSeed.routing) ? settingsSeed.routing : 'curved',
    );
    // Synchronous mirrors so persist() (called from many places) always writes the
    // current settings, not a lagged render's.
    const snapRef = useRef(snap);
    const routingRef = useRef(defaultRouting);
    // Optional minimap overview (editing aid, not persisted).
    const [showMap, setShowMap] = useState(false);
    // Read view only: pan boundary (graph bounds + margin) and the matching
    // zoom-out floor (the zoom at which that whole extent just fills the
    // container), computed once nodes are measured. Lets viewers pan/zoom but
    // never lose the diagram or shrink it into empty space.
    const [readExtent, setReadExtent] = useState(null);
    const [readMinZoom, setReadMinZoom] = useState(null);
    // How many nodes are selected — drives the Duplicate button (≥1) and the
    // align (≥2) / distribute (≥3) controls.
    const [selCount, setSelCount] = useState(0);
    const hasSel = selCount > 0;
    const canAlign = selCount >= 2;
    const canDistribute = selCount >= 3;
    // Edges are counted separately: only the whole-selection total gates the
    // per-item options popovers (see soloSelection), while the align/duplicate
    // controls above stay node-only.
    const [selEdgeCount, setSelEdgeCount] = useState(0);
    // A node's (or edge's) options popover is per-ITEM, so a marquee drag over a
    // dozen of them would pop a dozen overlapping panels. Show one only when it's
    // unambiguously the thing being edited — i.e. it is the entire selection.
    // Multi-selection is served by the canvas toolbar (align/distribute/duplicate)
    // instead; resize handles and selection outlines are unaffected.
    const soloSelection = selCount + selEdgeCount === 1;
    // Global interactivity (the Controls padlock): true = draggable/selectable,
    // false = frozen. Tracked here so we can clear the selection on freeze and
    // give the padlock an active style. Editor starts fully interactive.
    const [interactive, setInteractive] = useState(true);
    // Internal clipboard for copy/paste/duplicate (not the system clipboard).
    const clipboard = useRef(null);

    const [nodes, setNodesState] = useState(() => hydrateNodes(seed.current.nodes));
    const [edges, setEdgesState] = useState(() => hydrateEdges(seed.current.edges));

    // Synchronous mirrors so persistence reads final values, not lagged state.
    const nodesRef = useRef(nodes);
    const edgesRef = useRef(edges);
    const viewportRef = useRef(seed.current.viewport ?? { x: 0, y: 0, zoom: 1 });
    const selectionRef = useRef({ nodes: [], edges: [] });

    const setNodes = useCallback((next) => { nodesRef.current = next; setNodesState(next); }, []);
    const setEdges = useCallback((next) => { edgesRef.current = next; setEdgesState(next); }, []);

    const dirtyRef = useRef(false);
    const hasInteractedRef = useRef(false);

    const persist = () => {
        if (!editable || !dirtyRef.current || !hasInteractedRef.current) return;   // read-only mount renders the graph but never writes back
        onChange?.({
            nodes: cleanNodes(nodesRef.current),
            edges: cleanEdges(edgesRef.current),
            viewport: viewportRef.current,
            settings: { snap: snapRef.current, routing: routingRef.current },
        });
    };

    // Toggle/cycle the persisted settings: update the ref + state, then persist so
    // the choice is remembered (no undo entry — these are preferences, not edits).
    const toggleSnap = () => { const v = !snapRef.current; snapRef.current = v; setSnap(v); dirtyRef.current = true; hasInteractedRef.current = true; persist(); };
    const cycleDefaultRouting = () => {
        const v = ROUTING_MODES[(ROUTING_MODES.indexOf(routingRef.current) + 1) % ROUTING_MODES.length];
        routingRef.current = v; setDefaultRouting(v); dirtyRef.current = true; hasInteractedRef.current = true; persist();
    };

    // ── Undo / redo ──────────────────────────────────────────────────────────
    // A stack of cleaned graph snapshots. A new entry is pushed whenever a
    // structural change settles (via `commit()`); pure pan/zoom is excluded so
    // history isn't flooded. Undo/redo restore a snapshot and write it back.
    const snapshot = () => ({
        nodes: cleanNodes(nodesRef.current),
        edges: cleanEdges(edgesRef.current),
        viewport: viewportRef.current,
    });
    const history = useRef({ stack: [], index: -1 });
    const [hist, setHist] = useState({ canUndo: false, canRedo: false });
    const syncHist = () => {
        const h = history.current;
        setHist({ canUndo: h.index > 0, canRedo: h.index < h.stack.length - 1 });
    };

    // Seed the history with the initial graph once mounted.
    useEffect(() => {
        if (!editable) return;
        history.current = { stack: [snapshot()], index: 0 };
        syncHist();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const pushHistory = () => {
        const h = history.current;
        const stack = h.stack.slice(0, h.index + 1);   // drop any redo tail
        stack.push(snapshot());
        const trimmed = stack.length > HISTORY_LIMIT ? stack.slice(-HISTORY_LIMIT) : stack;
        history.current = { stack: trimmed, index: trimmed.length - 1 };
        syncHist();
    };

    // persist + record an undo step.
    const commit = () => { dirtyRef.current = true; hasInteractedRef.current = true; persist(); pushHistory(); };

    const restore = (snap) => {
        setNodes(hydrateNodes(snap.nodes));
        setEdges(hydrateEdges(snap.edges));
        viewportRef.current = snap.viewport ?? viewportRef.current;
        rf.setViewport(viewportRef.current);
        dirtyRef.current = true;
        persist();          // reflect the restored state into the document
    };
    const undo = () => {
        flushNudge();   // record a pending arrow-nudge run before stepping back
        const h = history.current;
        if (h.index <= 0) return;
        h.index -= 1;
        restore(h.stack[h.index]);
        syncHist();
    };
    const redo = () => {
        flushNudge();
        const h = history.current;
        if (h.index >= h.stack.length - 1) return;
        h.index += 1;
        restore(h.stack[h.index]);
        syncHist();
    };

    // Undo/redo keyboard shortcuts. React Flow doesn't take focus, so clicking the
    // canvas leaves focus on the host ProseMirror editor — a wrapper listener would
    // never see the keystroke and Cmd/Ctrl+Z would hit the document's undo instead.
    // Instead we track whether this diagram is the "active" one (last pointer-down
    // landed inside it) and intercept the combo on `document` in the CAPTURE phase,
    // which runs before ProseMirror's handler so we can stop it from undoing the doc.
    const activeRef = useRef(false);
    // Latest action closures for the (mount-time) key handler; assigned once the
    // copy/paste helpers below are defined to avoid a temporal-dead-zone error.
    const keyActions = useRef({});
    useEffect(() => {
        if (!editable) return;
        const onPointerDown = (e) => {
            const inside = !!wrapperRef.current?.contains(e.target);
            activeRef.current = inside;
            if (inside) hasInteractedRef.current = true;
            // Select the node so the toolbar reflects "inside a diagram" — but not
            // when starting to type in a label input (it would blur the input).
            if (inside && e.target?.tagName !== 'INPUT' && e.target?.tagName !== 'TEXTAREA') onActivate?.();
        };
        const onKeyDown = (e) => {
            if (!activeRef.current) return;
            // Skip while a canvas label editor (an <input>) is focused so it keeps
            // its own text undo; the host ProseMirror root is also contentEditable
            // but is exactly the focus we want to override, so don't guard on that.
            const t = e.target;
            if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) return;
            const a = keyActions.current;
            const stop = () => { e.preventDefault(); e.stopPropagation(); };

            // Plain keys (no Ctrl/Cmd): delete selection + arrow nudge. Delete and
            // typing are always swallowed while the diagram is active — the canvas
            // selects its own (atom) node in ProseMirror, where a stray Backspace
            // or keystroke would otherwise delete or replace the whole block.
            if (!e.metaKey && !e.ctrlKey && !e.altKey) {
                // Esc clears the selection first (swallowing the key in capture so
                // it can't reach the maximize handler); with nothing selected it
                // falls through, so a second Esc exits full screen.
                if (e.key === 'Escape') { if (a.clearSelection()) stop(); return; }
                if (e.key === 'Delete' || e.key === 'Backspace') { a.deleteSelected(); stop(); return; }
                const step = e.shiftKey ? 10 : 1;
                if (e.key === 'ArrowUp')    { if (a.nudge(0, -step)) stop(); return; }
                if (e.key === 'ArrowDown')  { if (a.nudge(0,  step)) stop(); return; }
                if (e.key === 'ArrowLeft')  { if (a.nudge(-step, 0)) stop(); return; }
                if (e.key === 'ArrowRight') { if (a.nudge( step, 0)) stop(); return; }
                if (e.key.length === 1) stop();   // printable key — don't replace the node
                return;
            }

            const k = e.key.toLowerCase();
            if (k === 'z' && !e.shiftKey) { stop(); a.undo(); }
            else if ((k === 'z' && e.shiftKey) || k === 'y') { stop(); a.redo(); }
            // Only swallow copy/paste/duplicate when we actually act on the graph,
            // so an empty selection / clipboard falls through to normal behaviour.
            else if (k === 'c') { if (a.copySelection()) stop(); }
            else if (k === 'v') { if (a.paste()) stop(); }
            else if (k === 'd') { if (a.duplicate()) stop(); }
        };
        document.addEventListener('pointerdown', onPointerDown, true);
        document.addEventListener('keydown', onKeyDown, true);
        return () => {
            document.removeEventListener('pointerdown', onPointerDown, true);
            document.removeEventListener('keydown', onKeyDown, true);
        };
    }, [editable]);



    useEffect(() => () => {
        clearTimeout(nudgeTimer.current);
        clearTimeout(colorTimer.current);
    }, []);

    // Read view: once the nodes have a measured size, derive the pan boundary from
    // their bounding box (+ margin) so dragging/zooming can't lose the diagram.
    useEffect(() => {
        if (editable || !nodesRef.current.length) return;
        const t = setTimeout(() => {
            const b = rf.getNodesBounds(nodesRef.current.map((n) => n.id));
            if (!b || !Number.isFinite(b.width) || !Number.isFinite(b.height)) return;
            const m = READ_VIEW_MARGIN;
            setReadExtent([[b.x - m, b.y - m], [b.x + b.width + m, b.y + b.height + m]]);

            // Zoom-out floor: the zoom that makes the bounds+margin box fill the
            // container. Capped at 1 so a tiny diagram isn't forced to fill the
            // frame, floored low so a huge one can still fit.
            const rect = wrapperRef.current?.getBoundingClientRect();
            if (rect?.width > 0 && rect?.height > 0) {
                const fit = Math.min(rect.width / (b.width + 2 * m), rect.height / (b.height + 2 * m));
                setReadMinZoom(Math.max(0.05, Math.min(fit, 1)));
            }
        }, 80);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const onNodesChange = useCallback((changes) => setNodes(applyNodeChanges(changes, nodesRef.current)), [setNodes]);
    const onEdgesChange = useCallback((changes) => setEdges(applyEdgeChanges(changes, edgesRef.current)), [setEdges]);

    const onConnect = useCallback((params) => {
        setEdges(addEdge(decorateEdge({ ...params, id: uid(), data: { routing: routingRef.current } }), edgesRef.current));
        commit();
    }, [setEdges]);

    const onEdgeChange = (id, patch) => {
        setEdges(edgesRef.current.map((e) => (e.id === id ? decorateEdge({ ...e, data: { ...edgeData(e), ...patch } }) : e)));
        commit();
    };

    const onEdgeDelete = (id) => {
        setEdges(edgesRef.current.filter((e) => e.id !== id));
        commit();
    };

    const onLabelChange = (id, label) => {
        setNodes(nodesRef.current.map((n) => (n.id === id ? { ...n, data: { ...n.data, label } } : n)));
        commit();
    };

    // Live variant for the properties-panel Name input: state-only update while
    // typing (see onNodeColorLive) — the panel commits (persist + undo entry)
    // on blur instead of flooding history on every keystroke.
    const onLabelLive = (id, label) => {
        setNodes(nodesRef.current.map((n) => (n.id === id ? { ...n, data: { ...n.data, label } } : n)));
    };

    const onPropsChange = (id, props) => {
        setNodes(nodesRef.current.map((n) => (n.id === id ? { ...n, data: { ...n.data, props } } : n)));
        commit();
    };

    // Live variant for the properties-panel Key/Value inputs — same rationale
    // as onLabelLive.
    const onPropsLive = (id, props) => {
        setNodes(nodesRef.current.map((n) => (n.id === id ? { ...n, data: { ...n.data, props } } : n)));
    };

    const onKindChange = (id, kind) => {
        setNodes(nodesRef.current.map((n) => {
            if (n.id !== id) return n;
            // Refresh the label to the new kind's default only if it was still the
            // old kind's default (i.e. the user never named it).
            const prevDefault = kindMeta(n.data?.kind ?? 'generic').label;
            const label = (!n.data?.label || n.data.label === prevDefault) ? kindMeta(kind).label : n.data.label;
            return { ...n, data: { ...n.data, kind, label } };
        }));
        commit();
    };

    const onNodeColorChange = (id, color) => {
        setNodes(nodesRef.current.map((n) => (n.id === id ? { ...n, data: { ...n.data, color } } : n)));
        commit();
    };

    // Position lock: flip data.locked and mirror it onto React Flow's `draggable`
    // so the element can't be dragged (nudge + reparent guards read data.locked).
    const onLockToggle = (id) => {
        setNodes(nodesRef.current.map((n) => {
            if (n.id !== id) return n;
            const locked = !n.data?.locked;
            // Mirror the load path (line ~739): locked -> draggable:false, unlocked
            // -> undefined so the node follows the global interactive lock again.
            // An explicit draggable:true would override the Controls padlock and
            // keep the node movable under a global freeze.
            return { ...n, draggable: locked ? false : undefined, data: { ...n.data, locked } };
        }));
        commit();
    };

    // Live custom-colour drag: apply immediately but collapse the stream of native
    // picker events into a single undo entry (debounced), like the arrow nudge.
    const colorTimer = useRef(null);
    const onNodeColorLive = (id, color) => {
        setNodes(nodesRef.current.map((n) => (n.id === id ? { ...n, data: { ...n.data, color } } : n)));
        clearTimeout(colorTimer.current);
        colorTimer.current = setTimeout(() => { colorTimer.current = null; commit(); }, 400);
    };

    // The canvas's visible centre in flow coords — new nodes/zones drop where the
    // user is currently looking rather than at a fixed origin that may be panned
    // off-screen. screenToFlowPosition accounts for the live pan/zoom.
    const viewportCenter = () => {
        const rect = wrapperRef.current?.getBoundingClientRect();
        if (!rect) return { x: 0, y: 0 };
        return rf.screenToFlowPosition({ x: rect.x + rect.width / 2, y: rect.y + rect.height / 2 });
    };

    const addNode = () => {
        const c = viewportCenter();
        // Cycle a 2×2 cluster around the centre so repeated clicks stay in view but
        // don't stack — steps sized to clearly separate the ~90×44 node boxes.
        const k = nodesRef.current.filter((x) => x.type !== 'group').length % 4;
        const pos = { x: c.x - 110 + (k % 2) * 140, y: c.y - 42 + Math.floor(k / 2) * 84 };
        const node = {
            id: uid(),
            type: 'labeled',
            position: snapRef.current ? snapToGridPos(pos) : pos,
            data: { label: 'Node' },
        };
        setNodes([...nodesRef.current, node]);
        commit();
    };

    const addZone = () => {
        const c = viewportCenter();
        // Same idea as addNode, larger cascade step for the bigger 288×180 box.
        const step = (nodesRef.current.filter((x) => x.type === 'group').length % 4) * 24;
        const pos = { x: c.x - 140 + step, y: c.y - 90 + step };
        const zone = {
            id: uid(),
            type: 'group',
            position: snapRef.current ? snapToGridPos(pos) : pos,
            width: 288,   // 16 grid steps — a clean multiple of SNAP_GRID
            height: 180,  // 10 grid steps
            data: { label: 'Zone', color: 'sage' },
        };
        // Groups must precede their children in the array (React Flow requirement).
        setNodes(sortGroupsFirst([zone, ...nodesRef.current]));
        commit();
    };

    // Absolute (world) bounding rect of a node at drag stop.
    const nodeRect = (id, fallbackPos) => {
        const internal = rf.getInternalNode(id);
        const pos = internal?.internals?.positionAbsolute ?? fallbackPos ?? { x: 0, y: 0 };
        const w = internal?.measured?.width ?? internal?.width ?? 0;
        const h = internal?.measured?.height ?? internal?.height ?? 0;
        return { x: pos.x, y: pos.y, w, h };
    };

    // Innermost of a set of candidate zones = the one with the smallest area (a
    // nested zone is always smaller than its container), so a drop lands in the
    // deepest zone under it rather than an outer one.
    const innermost = (groups) => groups
        .slice()
        .sort((a, b) => {
            const ra = nodeRect(a.id); const rb = nodeRect(b.id);
            return (ra.w * ra.h) - (rb.w * rb.h);
        })[0]?.id;

    // Drop a node/zone into a zone (or out of one): re-parent it and convert its
    // position to be relative to the new parent, so it moves as a unit with the
    // zone. A device node joins the innermost zone it INTERSECTS (they're small,
    // so intersection ≈ containment). A dragged ZONE joins the innermost zone
    // that FULLY contains it — excluding itself and its own descendants, so a
    // zone can never be dropped into its own child. No matching zone ⇒ root.
    const reparentOnDragStop = (dragged) => {
        const nodes = nodesRef.current;
        const rect = nodeRect(dragged.id, dragged.position);

        // A locked zone is frozen: it never accepts a dropped child.
        const lockedIds = new Set(nodes.filter((n) => n.data?.locked).map((n) => n.id));
        let newParent;
        if (dragged.type === 'group') {
            const excluded = collectDescendants(dragged.id, nodes);
            excluded.add(dragged.id);
            const contains = (o) => o.x <= rect.x && o.y <= rect.y
                && o.x + o.w >= rect.x + rect.w && o.y + o.h >= rect.y + rect.h;
            newParent = innermost(nodes.filter((n) =>
                n.type === 'group' && !excluded.has(n.id) && !lockedIds.has(n.id) && contains(nodeRect(n.id))));
        } else {
            newParent = innermost(rf.getIntersectingNodes(dragged)
                .filter((g) => g.type === 'group' && !lockedIds.has(g.id)));
        }

        if ((newParent ?? undefined) === (dragged.parentId ?? undefined)) return;

        const gAbs = newParent
            ? (rf.getInternalNode(newParent)?.internals?.positionAbsolute ?? { x: 0, y: 0 })
            : { x: 0, y: 0 };
        const position = { x: rect.x - gAbs.x, y: rect.y - gAbs.y };

        setNodes(sortGroupsFirst(nodes.map((n) => {
            if (n.id !== dragged.id) return n;
            const next = { ...n, position };
            if (newParent) next.parentId = newParent; else delete next.parentId;
            return next;
        })));
    };

    const deleteSelected = () => {
        const delNodeIds = new Set(selectionRef.current.nodes.map((x) => x.id));
        const delEdgeIds = new Set(selectionRef.current.edges.map((x) => x.id));
        if (!delNodeIds.size && !delEdgeIds.size) return false;

        const byId = new Map(nodesRef.current.map((n) => [n.id, n]));
        const next = nodesRef.current
            .filter((x) => !delNodeIds.has(x.id))
            .map((n) => {
                // If a node's parent zone is being deleted, re-home it to the
                // nearest surviving ancestor (walking up past any other deleted
                // zones), or to root if none survives — preserving its absolute
                // on-screen position either way. This keeps a sub-zone nested
                // where it visually sits instead of orphaning it to root.
                if (!n.parentId || !delNodeIds.has(n.parentId)) return n;
                let ancestor = n.parentId;
                while (ancestor && delNodeIds.has(ancestor)) ancestor = byId.get(ancestor)?.parentId;
                const newParent = ancestor && !delNodeIds.has(ancestor) ? ancestor : undefined;
                const abs = rf.getInternalNode(n.id)?.internals?.positionAbsolute ?? n.position;
                const gAbs = newParent
                    ? (rf.getInternalNode(newParent)?.internals?.positionAbsolute ?? { x: 0, y: 0 })
                    : { x: 0, y: 0 };
                const { parentId, ...rest } = n;
                const relocated = { ...rest, position: { x: abs.x - gAbs.x, y: abs.y - gAbs.y } };
                if (newParent) relocated.parentId = newParent;
                return relocated;
            });
        setNodes(sortGroupsFirst(next));
        setEdges(edgesRef.current.filter(
            (e) => !delEdgeIds.has(e.id) && !delNodeIds.has(e.source) && !delNodeIds.has(e.target),
        ));
        selectionRef.current = { nodes: [], edges: [] };
        setSelCount(0);
        commit();
        return true;
    };

    // Arrow-key nudge. Positions update live on each press, but the undo entry is
    // debounced so holding an arrow collapses the whole run into one step.
    const nudgeTimer = useRef(null);
    const flushNudge = () => {
        if (!nudgeTimer.current) return;
        clearTimeout(nudgeTimer.current);
        nudgeTimer.current = null;
        pushHistory();
    };
    const nudge = (dx, dy) => {
        // Locked elements don't move — mirror the drag lock for the keyboard.
        const lockedIds = new Set(nodesRef.current.filter((n) => n.data?.locked).map((n) => n.id));
        const ids = new Set((selectionRef.current.nodes ?? []).map((n) => n.id).filter((id) => !lockedIds.has(id)));
        if (!ids.size) return false;
        setNodes(nodesRef.current.map((n) =>
            ids.has(n.id) ? { ...n, position: { x: n.position.x + dx, y: n.position.y + dy } } : n));
        dirtyRef.current = true;
        persist();
        clearTimeout(nudgeTimer.current);
        nudgeTimer.current = setTimeout(() => { nudgeTimer.current = null; pushHistory(); }, 350);
        return true;
    };

    // ── Copy / paste / duplicate ─────────────────────────────────────────────
    // Snapshot the current selection (nodes + edges wholly between them) into a
    // portable clip. Absolute positions are stored so paste doesn't depend on a
    // node's parent zone still existing.
    const buildClip = () => {
        const sel = selectionRef.current.nodes ?? [];
        if (!sel.length) return null;
        const selIds = new Set(sel.map((n) => n.id));
        const nodes = sel.map((s) => {
            const n = nodesRef.current.find((x) => x.id === s.id) ?? s;
            const abs = rf.getInternalNode(n.id)?.internals?.positionAbsolute ?? n.position;
            const out = { id: n.id, type: n.type, data: { ...n.data }, abs: { x: abs.x, y: abs.y } };
            if (n.width != null) out.width = n.width;
            if (n.height != null) out.height = n.height;
            if (n.parentId) out.parentId = n.parentId;
            return out;
        });
        const edges = edgesRef.current
            .filter((e) => selIds.has(e.source) && selIds.has(e.target))
            .map((e) => ({
                source: e.source, target: e.target,
                sourceHandle: e.sourceHandle ?? null, targetHandle: e.targetHandle ?? null,
                data: edgeData(e),
            }));
        return { nodes, edges };
    };

    // Drop a clip into the graph at an offset: mint fresh ids, remap edges and
    // parent membership, select the new nodes (so paste/duplicate can chain).
    const pasteFrom = (clip, offset = { x: 24, y: 24 }) => {
        if (!clip || !clip.nodes.length) return false;
        const idMap = new Map(clip.nodes.map((n) => [n.id, uid()]));
        const absById = new Map(clip.nodes.map((n) => [n.id, n.abs]));

        const newNodes = clip.nodes.map((n) => {
            const node = { id: idMap.get(n.id), type: n.type, data: { ...n.data }, selected: true };
            if (n.width != null) node.width = n.width;
            if (n.height != null) node.height = n.height;
            if (n.parentId && idMap.has(n.parentId)) {
                // Parent came along: keep the child relative; the whole group shifts.
                node.parentId = idMap.get(n.parentId);
                const pAbs = absById.get(n.parentId);
                node.position = { x: n.abs.x - pAbs.x, y: n.abs.y - pAbs.y };
            } else {
                node.position = { x: n.abs.x + offset.x, y: n.abs.y + offset.y };
            }
            return node;
        });
        const newEdges = clip.edges.map((e) => decorateEdge({
            ...e, id: uid(), source: idMap.get(e.source), target: idMap.get(e.target),
        }));

        const existing = nodesRef.current.map((x) => (x.selected ? { ...x, selected: false } : x));
        setNodes(sortGroupsFirst([...existing, ...newNodes]));
        setEdges([...edgesRef.current, ...newEdges]);
        selectionRef.current = { nodes: newNodes, edges: [] };
        setSelCount(newNodes.length);
        commit();
        return true;
    };

    const copySelection = () => { const c = buildClip(); if (!c) return false; clipboard.current = c; return true; };
    const paste = () => pasteFrom(clipboard.current);
    const duplicate = () => { const c = buildClip(); return c ? pasteFrom(c) : false; };

    // ── Align / distribute ───────────────────────────────────────────────────
    // Resolve the selected nodes into absolute boxes (children of a zone store
    // positions relative to it, so go through React Flow's internal node).
    const selectedBoxes = () =>
        (selectionRef.current.nodes ?? []).map((s) => {
            const ni = rf.getInternalNode(s.id);
            const abs = ni?.internals?.positionAbsolute ?? s.position ?? { x: 0, y: 0 };
            const w = ni?.measured?.width ?? s.width ?? 0;
            const h = ni?.measured?.height ?? s.height ?? 0;
            return { id: s.id, x: abs.x, y: abs.y, w, h };
        });

    // Write new ABSOLUTE positions back, converting to parent-relative for any
    // node living inside a zone (using the zone's new position if it also moved).
    const moveNodesTo = (absById) => {
        if (!absById.size) return;
        const parentAbs = (pid) => {
            if (absById.has(pid)) return absById.get(pid);
            return rf.getInternalNode(pid)?.internals?.positionAbsolute ?? { x: 0, y: 0 };
        };
        setNodes(sortGroupsFirst(nodesRef.current.map((n) => {
            const abs = absById.get(n.id);
            if (!abs) return n;
            const position = n.parentId
                ? { x: abs.x - parentAbs(n.parentId).x, y: abs.y - parentAbs(n.parentId).y }
                : { x: abs.x, y: abs.y };
            return { ...n, position };
        })));
        commit();
    };

    // axis 'x' aligns horizontal edges/centres; mode start|center|end.
    const alignNodes = (axis, mode) => {
        const items = selectedBoxes();
        if (items.length < 2) return false;
        const sizeKey = axis === 'x' ? 'w' : 'h';
        const min = Math.min(...items.map((i) => i[axis]));
        const max = Math.max(...items.map((i) => i[axis] + i[sizeKey]));
        const mid = (min + max) / 2;
        const target = (i) => mode === 'start' ? min : mode === 'end' ? max - i[sizeKey] : mid - i[sizeKey] / 2;
        const absById = new Map(items.map((i) => [i.id, axis === 'x' ? { x: target(i), y: i.y } : { x: i.x, y: target(i) }]));
        moveNodesTo(absById);
        return true;
    };

    // Equal spacing between adjacent edges along an axis; the two outermost
    // nodes stay put and the rest are spread evenly between them.
    const distributeNodes = (axis) => {
        const items = selectedBoxes();
        if (items.length < 3) return false;
        const sizeKey = axis === 'x' ? 'w' : 'h';
        const sorted = [...items].sort((a, b) => a[axis] - b[axis]);
        const first = sorted[0], last = sorted[sorted.length - 1];
        const span = (last[axis] + last[sizeKey]) - first[axis];
        const gap = (span - sorted.reduce((s, i) => s + i[sizeKey], 0)) / (sorted.length - 1);
        const absById = new Map();
        let cursor = first[axis];
        for (const i of sorted) {
            absById.set(i.id, axis === 'x' ? { x: cursor, y: i.y } : { x: i.x, y: cursor });
            cursor += i[sizeKey] + gap;
        }
        moveNodesTo(absById);
        return true;
    };

    // Deselect everything. Returns whether anything was actually selected so the
    // caller (Esc) can decide to swallow the key or let it fall through.
    const clearSelection = () => {
        const had = (selectionRef.current.nodes?.length ?? 0) + (selectionRef.current.edges?.length ?? 0) > 0;
        if (!had) return false;
        setNodes(nodesRef.current.map((n) => (n.selected ? { ...n, selected: false } : n)));
        setEdges(edgesRef.current.map((e) => (e.selected ? { ...e, selected: false } : e)));
        selectionRef.current = { nodes: [], edges: [] };
        setSelCount(0);
        return true;
    };

    keyActions.current = { undo, redo, copySelection, paste, duplicate, deleteSelected, nudge, clearSelection };

    // Stable context value for the node/edge components. The handlers above are
    // recreated every render but only ever touch refs, so we route them through a
    // ref and hand the Provider an identity-stable object (changing only with
    // `editable`). Without this the value is a fresh literal each render, so every
    // node and edge (all context consumers) re-renders on every drag frame — which
    // is what made dragging feel laggy. Now only the dragged node re-renders.
    const behaviorRef = useRef(null);
    behaviorRef.current = { onLabelChange, onLabelLive, onKindChange, onPropsChange, onPropsLive, onNodeColorChange, onNodeColorLive, onLockToggle, onEdgeChange, onEdgeDelete, onPersist: commit };
    const behavior = useMemo(() => ({
        editable,
        onLabelChange: (...a) => behaviorRef.current.onLabelChange(...a),
        onLabelLive: (...a) => behaviorRef.current.onLabelLive(...a),
        onKindChange: (...a) => behaviorRef.current.onKindChange(...a),
        onPropsChange: (...a) => behaviorRef.current.onPropsChange(...a),
        onPropsLive: (...a) => behaviorRef.current.onPropsLive(...a),
        onNodeColorChange: (...a) => behaviorRef.current.onNodeColorChange(...a),
        onNodeColorLive: (...a) => behaviorRef.current.onNodeColorLive(...a),
        onLockToggle: (...a) => behaviorRef.current.onLockToggle(...a),
        onEdgeChange: (...a) => behaviorRef.current.onEdgeChange(...a),
        onEdgeDelete: (...a) => behaviorRef.current.onEdgeDelete(...a),
        onPersist: (...a) => behaviorRef.current.onPersist(...a),
        snapToGrid: snap,
        // Gates content editing (e.g. zone-label double-click) so the global lock
        // freezes editing too, not just drag/select. Changes only on padlock toggle,
        // so it doesn't churn the memo during drags.
        interactive,
        // Gates the per-item options popovers. Churns the memo on every selection
        // change, but only across the true/false boundary — and the nodes have to
        // re-render on that transition anyway.
        soloSelection,
    }), [editable, snap, interactive, soloSelection]);

    // In the editor, leave node interactivity to React Flow's defaults (all on) so
    // the Controls lock button can toggle it; the read-only mount pins it all off.
    //
    // Editor selection model: a plain left-drag on empty canvas draws a marquee
    // (partial-intersection, Figma-style) instead of panning — panning moves to the
    // middle/right mouse button, or holding Space while dragging. The read view has
    // no selection, so there a left-drag still pans (the natural way to look around).
    const interactionProps = useMemo(() => editable
        ? {
            selectionOnDrag: true,
            selectionMode: SelectionMode.Partial,
            panOnDrag: PAN_ON_DRAG,           // middle / right button pan
            panActivationKeyCode: 'Space',
        }
        : {
            nodesDraggable: false, nodesConnectable: false, elementsSelectable: false,
            panOnDrag: true,
        }, [editable]);

    const handleNodeDragStop = useCallback((_, node) => {
        reparentOnDragStop(node);
        commit();
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const handleMoveEnd = useCallback((_, vp) => {
        viewportRef.current = vp;
    }, []);

    const handleSelectionChange = useCallback((sel) => {
        selectionRef.current = sel;
        setSelCount(sel.nodes?.length ?? 0);
        setSelEdgeCount(sel.edges?.length ?? 0);
    }, []);

    // A plain click on a node that is part of a multi-selection narrows the
    // selection to just that node, so its per-item options panel opens (React
    // Flow's default keeps the whole marquee selection so the group can be
    // dragged as one). A DRAG still moves the group — that fires drag events, not
    // a click — so group-move is unaffected. Runs only in the editor.
    const handleNodeClick = useCallback((_, node) => {
        const sel = selectionRef.current;
        if ((sel.nodes?.length ?? 0) + (sel.edges?.length ?? 0) <= 1) {
            return;
        }
        setNodes(nodesRef.current.map((n) => (
            n.selected === (n.id === node.id) ? n : { ...n, selected: n.id === node.id }
        )));
        setEdges(edgesRef.current.map((e) => (e.selected ? { ...e, selected: false } : e)));
    }, [setNodes, setEdges]);

    const [downloading, setDownloading] = useState(false);

    const downloadSvg = async () => {
        if (downloading) return;
        setDownloading(true);
        try {
            const graph = { 
                nodes: cleanNodes(rf.getNodes()), 
                edges: cleanEdges(rf.getEdges()) 
            };
            
            if (!graph.nodes.length) return;
            
            const tokenMeta = document.querySelector('meta[name="csrf-token"]');
            const token = tokenMeta ? tokenMeta.getAttribute('content') : '';

            const res = await fetch('/documents/diagram-export', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token 
                },
                body: JSON.stringify({ graph, name })
            });

            if (!res.ok) throw new Error('Export failed');

            const blob = await res.blob();
            const url = URL.createObjectURL(blob);
            const slug = (name ?? '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
            const a = document.createElement('a');
            a.href = url;
            a.download = `${slug || 'network-diagram'}.svg`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        } catch (e) {
            console.warn('Network diagram download failed', e);
            toast.error("Couldn't download the diagram. Please try again.");
        } finally {
            setDownloading(false);
        }
    };

    return (
        <NodeBehavior.Provider value={behavior}>
            <div ref={wrapperRef} style={{ width: '100%', height: '100%' }}>
                <ReactFlow
                    nodes={nodes}
                    edges={edges}
                    nodeTypes={nodeTypes}
                    edgeTypes={edgeTypes}
                    connectionMode={ConnectionMode.Loose}
                    snapToGrid={editable && snap}
                    snapGrid={SNAP_GRID}
                    onNodesChange={editable ? onNodesChange : undefined}
                    onEdgesChange={editable ? onEdgesChange : undefined}
                    onConnect={editable ? onConnect : undefined}
                    onNodeDragStop={editable ? handleNodeDragStop : undefined}
                    onMoveEnd={editable ? handleMoveEnd : undefined}
                    onSelectionChange={handleSelectionChange}
                    onNodeClick={editable ? handleNodeClick : undefined}
                    defaultViewport={seed.current.viewport ?? DEFAULT_VIEWPORT}
                    {...interactionProps}
                    // Navigation (both views): pinch / double-click / the Controls
                    // buttons to zoom, all clamped to translateExtent (graph bounds +
                    // margin) so it can't be lost. Pan-on-drag is set per-mode in
                    // interactionProps (left-drag in read view; middle/right/Space in
                    // the editor, where left-drag marquee-selects instead). Scroll-to-
                    // zoom stays editor-only so reading the page over an embedded
                    // diagram scrolls the article instead of hijacking the wheel.
                    zoomOnScroll={editable}
                    zoomOnPinch
                    zoomOnDoubleClick
                    preventScrolling={editable}
                    minZoom={!editable && readMinZoom ? readMinZoom : 0.2}
                    translateExtent={!editable && readExtent ? readExtent : undefined}
                    deleteKeyCode={null}   /* explicit Delete button — avoids clashing with the editor */
                    proOptions={PRO_OPTIONS}
                    fitView={(seed.current.nodes ?? []).length > 0}
                >
                    <Background color="#BFD2B5" gap={18} size={1.6} />
                    {/* Zoom / fit / lock — the lock toggles node interactivity.
                        Freezing also clears the selection so a still-selected node's
                        toolbar can't keep editing it; `controls-locked` styles the
                        padlock as active (see app.css). */}
                    {editable && (
                        <Controls
                            showInteractive
                            className={interactive ? undefined : 'controls-locked'}
                            onInteractiveChange={(next) => { setInteractive(next); if (!next) clearSelection(); }}
                        />
                    )}
                    {/* Read view: zoom in/out + fit-to-recenter (no interactivity lock). */}
                    {!editable && nodes.length > 0 && <Controls showInteractive={false} />}
                    {editable && showMap && (
                        <MiniMap
                            pannable
                            zoomable
                            nodeColor={miniMapNodeColor}
                            nodeStrokeWidth={2}
                            maskColor="color-mix(in srgb, var(--accent-600) 12%, transparent)"
                            className="!bottom-2 !right-2 !rounded-md !border !border-border !bg-card !shadow-sm"
                            style={{ width: 140, height: 96 }}
                        />
                    )}

                    {editable && (
                        <div className="absolute left-2 top-2 z-10 flex flex-col gap-1">
                            <div className="flex gap-1">
                            <button
                                type="button"
                                onClick={addNode}
                                className="flex items-center gap-1 rounded-sm border border-border bg-card px-2 py-1 text-xs font-medium text-text-secondary shadow-sm transition-colors hover:bg-surface-hover hover:text-foreground"
                            >
                                <IconPlus className="h-3.5 w-3.5" stroke={1.5} /> Node
                            </button>
                            <span className="mx-px w-px self-stretch bg-border" />
                            <button
                                type="button"
                                onClick={undo}
                                disabled={!hist.canUndo}
                                title="Undo (Ctrl+Z)"
                                className="flex items-center justify-center rounded-sm border border-border bg-card px-1.5 py-1 text-text-secondary shadow-sm transition-colors hover:bg-surface-hover hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-card disabled:hover:text-text-secondary"
                            >
                                <IconArrowBackUp className="h-3.5 w-3.5" stroke={1.5} />
                            </button>
                            <button
                                type="button"
                                onClick={redo}
                                disabled={!hist.canRedo}
                                title="Redo (Ctrl+Shift+Z)"
                                className="flex items-center justify-center rounded-sm border border-border bg-card px-1.5 py-1 text-text-secondary shadow-sm transition-colors hover:bg-surface-hover hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-card disabled:hover:text-text-secondary"
                            >
                                <IconArrowForwardUp className="h-3.5 w-3.5" stroke={1.5} />
                            </button>
                            <span className="mx-px w-px self-stretch bg-border" />
                            <button
                                type="button"
                                onClick={addZone}
                                title="Add a grouping zone"
                                className="flex items-center gap-1 rounded-sm border border-border bg-card px-2 py-1 text-xs font-medium text-text-secondary shadow-sm transition-colors hover:bg-surface-hover hover:text-foreground"
                            >
                                <IconSquareDashed className="h-3.5 w-3.5" stroke={1.5} /> Zone
                            </button>
                            <button
                                type="button"
                                onClick={toggleSnap}
                                aria-pressed={snap}
                                title={snap ? 'Snap to grid: on' : 'Snap to grid: off'}
                                className={`flex items-center justify-center rounded-sm border px-1.5 py-1 shadow-sm transition-colors ${
                                    snap
                                        ? 'border-accent-300 bg-accent-100 text-accent-700'
                                        : 'border-border bg-card text-text-secondary hover:bg-surface-hover hover:text-foreground'
                                }`}
                            >
                                <IconGridDots className="h-3.5 w-3.5" stroke={1.5} />
                            </button>
                            {(() => { const R = ROUTING_ICON[defaultRouting]; return (
                                <button
                                    type="button"
                                    onClick={cycleDefaultRouting}
                                    title={`New connections: ${ROUTING_LABEL[defaultRouting]}`}
                                    className="flex items-center justify-center rounded-sm border border-border bg-card px-1.5 py-1 text-text-secondary shadow-sm transition-colors hover:bg-surface-hover hover:text-foreground"
                                >
                                    <R className="h-3.5 w-3.5" stroke={1.5} />
                                </button>
                            ); })()}
                            <button
                                type="button"
                                onClick={() => setShowMap((s) => !s)}
                                aria-pressed={showMap}
                                title={showMap ? 'Minimap: on' : 'Minimap: off'}
                                className={`flex items-center justify-center rounded-sm border px-1.5 py-1 shadow-sm transition-colors ${
                                    showMap
                                        ? 'border-accent-300 bg-accent-100 text-accent-700'
                                        : 'border-border bg-card text-text-secondary hover:bg-surface-hover hover:text-foreground'
                                }`}
                            >
                                <IconMap2 className="h-3.5 w-3.5" stroke={1.5} />
                            </button>
                            <button
                                type="button"
                                onClick={duplicate}
                                disabled={!hasSel}
                                title="Duplicate selection (Ctrl+D)"
                                className="flex items-center justify-center rounded-sm border border-border bg-card px-1.5 py-1 text-text-secondary shadow-sm transition-colors hover:bg-surface-hover hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-card disabled:hover:text-text-secondary"
                            >
                                <IconCopy className="h-3.5 w-3.5" stroke={1.5} />
                            </button>
                            <span className="mx-px w-px self-stretch bg-border" />
                            <button
                                type="button"
                                onClick={deleteSelected}
                                title="Delete selected node or connection (Del)"
                                className="flex items-center gap-1 rounded-sm border border-border bg-card px-2 py-1 text-xs font-medium text-text-secondary shadow-sm transition-colors hover:bg-danger hover:text-text-inverse"
                            >
                                <IconTrash className="h-3.5 w-3.5" stroke={1.5} /> Delete
                            </button>
                            <span className="mx-px w-px self-stretch bg-border" />
                            <button
                                type="button"
                                onClick={downloadSvg}
                                disabled={downloading || nodes.length === 0}
                                title="Download diagram as SVG"
                                className="flex items-center justify-center rounded-sm border border-border bg-card px-1.5 py-1 text-text-secondary shadow-sm transition-colors hover:bg-surface-hover hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-card disabled:hover:text-text-secondary"
                            >
                                <IconDownload className="h-3.5 w-3.5" stroke={1.5} />
                            </button>
                            </div>

                            {/* Align & distribute — only with a multi-selection, since
                                a single node has nothing to align against. */}
                            {canAlign && (
                                <div className="flex gap-1">
                                    <ToolbarIconButton title="Align left edges" onClick={() => alignNodes('x', 'start')}>
                                        <IconLayoutAlignLeft className="h-3.5 w-3.5" stroke={1.5} />
                                    </ToolbarIconButton>
                                    <ToolbarIconButton title="Align horizontal centres" onClick={() => alignNodes('x', 'center')}>
                                        <IconLayoutAlignCenter className="h-3.5 w-3.5" stroke={1.5} />
                                    </ToolbarIconButton>
                                    <ToolbarIconButton title="Align right edges" onClick={() => alignNodes('x', 'end')}>
                                        <IconLayoutAlignRight className="h-3.5 w-3.5" stroke={1.5} />
                                    </ToolbarIconButton>
                                    <span className="mx-px w-px self-stretch bg-border" />
                                    <ToolbarIconButton title="Align top edges" onClick={() => alignNodes('y', 'start')}>
                                        <IconLayoutAlignTop className="h-3.5 w-3.5" stroke={1.5} />
                                    </ToolbarIconButton>
                                    <ToolbarIconButton title="Align vertical centres" onClick={() => alignNodes('y', 'center')}>
                                        <IconLayoutAlignMiddle className="h-3.5 w-3.5" stroke={1.5} />
                                    </ToolbarIconButton>
                                    <ToolbarIconButton title="Align bottom edges" onClick={() => alignNodes('y', 'end')}>
                                        <IconLayoutAlignBottom className="h-3.5 w-3.5" stroke={1.5} />
                                    </ToolbarIconButton>
                                    <span className="mx-px w-px self-stretch bg-border" />
                                    <ToolbarIconButton
                                        title="Distribute horizontally (needs 3+)"
                                        onClick={() => distributeNodes('x')}
                                        disabled={!canDistribute}
                                    >
                                        <IconLayoutDistributeHorizontal className="h-3.5 w-3.5" stroke={1.5} />
                                    </ToolbarIconButton>
                                    <ToolbarIconButton
                                        title="Distribute vertically (needs 3+)"
                                        onClick={() => distributeNodes('y')}
                                        disabled={!canDistribute}
                                    >
                                        <IconLayoutDistributeVertical className="h-3.5 w-3.5" stroke={1.5} />
                                    </ToolbarIconButton>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Read view: a single unobtrusive download control so viewers
                        can export the diagram on its own. */}
                    {!editable && nodes.length > 0 && (
                        <button
                            type="button"
                            onClick={downloadSvg}
                            disabled={downloading}
                            title="Download diagram as SVG"
                            className="absolute right-2 top-2 z-10 flex items-center justify-center rounded-sm border border-border bg-card/90 p-1.5 text-text-secondary shadow-sm backdrop-blur transition-colors hover:bg-surface-hover hover:text-foreground disabled:opacity-40"
                        >
                            <IconDownload className="h-3.5 w-3.5" stroke={1.5} />
                        </button>
                    )}
                </ReactFlow>
            </div>
        </NodeBehavior.Provider>
    );
}

export default function DiagramCanvas(props) {
    return (
        <ReactFlowProvider>
            <Canvas {...props} />
        </ReactFlowProvider>
    );
}
