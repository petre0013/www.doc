import { Suspense, lazy, useCallback, useEffect, useState, useRef } from 'react';
import { NodeViewWrapper } from '@tiptap/react';
import { IconTopologyStar3, IconTrash, IconArrowsMaximize, IconArrowsMinimize } from '@tabler/icons-react';
import ConfirmDialog from '@/components/ui/ConfirmDialog';

// React Flow is heavy, so the editable canvas is split out and only fetched
// when a diagram is actually edited (read view / diagram-free pages skip it).
const Canvas = lazy(() => import('./DiagramCanvas'));

const EMPTY_GRAPH = { nodes: [], edges: [], viewport: { x: 0, y: 0, zoom: 1 } };

/**
 * NodeView for the diagram block (persisted node type: `networkDiagram`).
 *   editable  → live React Flow canvas (edits write back to the `graph` attr)
 *   read-only → the same React Flow canvas, non-interactive. No-JS consumers
 *               (PDF/DOCX/search/version snapshots) render server-side via
 *               App\Support\DiagramSvg instead.
 */
export default function DiagramNodeView({ node, updateAttributes, editor, deleteNode, getPos }) {
    const editable = editor.isEditable;
    const graph = node.attrs.graph ?? EMPTY_GRAPH;
    const name = (node.attrs.name ?? '').trim();

    const onChange = useCallback((g) => {
        // Prevent React Flow mount/resize events from marking the editor dirty
        // if the diagram data hasn't actually mutated.
        const currentStr = JSON.stringify({
            nodes: node.attrs.graph?.nodes ?? [],
            edges: node.attrs.graph?.edges ?? [],
            viewport: node.attrs.graph?.viewport ?? { x: 0, y: 0, zoom: 1 },
            settings: node.attrs.graph?.settings ?? { snap: true, routing: 'curved' },
        });
        const newStr = JSON.stringify({
            nodes: g.nodes ?? [],
            edges: g.edges ?? [],
            viewport: g.viewport ?? { x: 0, y: 0, zoom: 1 },
            settings: g.settings ?? { snap: true, routing: 'curved' },
        });
        if (currentStr === newStr) return;
        updateAttributes({ graph: g });
    }, [updateAttributes, node.attrs.graph]);

    // Select this node in ProseMirror when its canvas is interacted with — the
    // canvas swallows the click that would normally create the NodeSelection, so
    // without this the toolbar's diagram button never lights up while editing.
    const onActivate = useCallback(() => {
        if (typeof getPos !== 'function') return;
        const pos = getPos();
        if (typeof pos !== 'number') return;
        if (editor.state.selection.from === pos && editor.isActive('networkDiagram')) return;
        editor.commands.setNodeSelection(pos);
    }, [editor, getPos]);

    // Confirm before removing the whole diagram — its layout would be lost.
    const [confirmOpen, setConfirmOpen] = useState(false);

    // Maximize the editing surface to a full-viewport overlay. The same React
    // Flow instance is just re-hosted via a class toggle (no remount, so the
    // viewport/zoom is preserved); no page ancestor is transformed, so
    // position:fixed anchors to the viewport without a portal.
    const [maximized, setMaximized] = useState(false);
    // Where the page was scrolled when we went full screen. Going full screen
    // takes the block out of the flow, so the article loses its height: a diagram
    // near the end of the page puts the reader past the shortened document's
    // maximum and the browser clamps the scroll upwards. Exiting restores the
    // height but not the offset, landing the reader above the diagram they were
    // just editing — so remember it here and put it back on the way out.
    //
    // Capture on the way IN, from the toggle handler: by the time an effect runs
    // the overlay class is already committed and the browser has clamped the
    // offset, so reading it there would just save the damage.
    const scrollYRef = useRef(null);
    const toggleMaximized = useCallback(() => {
        if (!maximized) scrollYRef.current = window.scrollY;
        setMaximized(!maximized);
    }, [maximized]);
    useEffect(() => {
        if (!maximized) {
            // Restore on exit only, not on unmount (this branch never runs for an
            // unmounting component) — scrolling a page we've navigated away from
            // would fight Inertia's own scroll handling.
            if (scrollYRef.current !== null) {
                window.scrollTo(0, scrollYRef.current);
                scrollYRef.current = null;
            }
            return undefined;
        }
        // Lock body scroll and exit on Esc (but not while a label input is
        // focused — there Esc cancels the edit).
        const prevOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        const onKey = (e) => {
            if (e.key !== 'Escape') return;
            const t = e.target;
            if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) return;
            setMaximized(false);
        };
        window.addEventListener('keydown', onKey);
        return () => {
            document.body.style.overflow = prevOverflow;
            window.removeEventListener('keydown', onKey);
        };
    }, [maximized]);

    if (!editable) {
        const hasNodes = (graph.nodes ?? []).length > 0;
        return (
            <NodeViewWrapper className="network-diagram-block my-4" data-network-diagram="true">
                {hasNodes ? (
                    <figure className="m-0">
                        {/* Render the real React Flow graph (read-only), not the
                            rasterised PNG — the PNG is only for no-JS consumers
                            (PDF/DOCX/search/version snapshots). */}
                        <div className="diagram-artifact overflow-hidden rounded-md border border-border bg-canvas" style={{ height: 420 }}>
                            <Suspense fallback={<div className="flex h-full items-center justify-center text-sm text-text-tertiary">Loading diagram…</div>}>
                                <Canvas graph={graph} editable={false} name={name} />
                            </Suspense>
                        </div>
                        <figcaption className="mt-1.5 text-center text-xs text-text-secondary">{name || 'Untitled diagram'}</figcaption>
                    </figure>
                ) : (
                    <div className="flex items-center justify-center gap-2 rounded-md border border-dashed border-border bg-canvas px-4 py-10 text-sm text-text-tertiary">
                        <IconTopologyStar3 className="h-4 w-4" stroke={1.5} />
                        {name || 'Untitled diagram'}
                    </div>
                )}
            </NodeViewWrapper>
        );
    }

    return (
        <NodeViewWrapper className="network-diagram-block my-4" contentEditable={false} data-network-diagram="true">
            <div className={`diagram-artifact bg-canvas ${
                maximized
                    ? 'fixed inset-0 z-50 flex flex-col rounded-none border-0'
                    : 'overflow-hidden rounded-md border border-border'
            }`}>
                <div className="flex items-center justify-between gap-2 border-b border-border bg-surface px-3 py-1.5">
                    <span className="flex min-w-0 flex-1 items-center gap-1.5 text-xs font-medium text-text-secondary">
                        <IconTopologyStar3 className="h-3.5 w-3.5 shrink-0" stroke={1.5} />
                        <input
                            type="text"
                            value={node.attrs.name ?? ''}
                            onChange={(e) => updateAttributes({ name: e.target.value })}
                            placeholder="Untitled diagram"
                            aria-label="Diagram name"
                            className="min-w-0 flex-1 bg-transparent text-xs font-medium text-foreground outline-none placeholder:font-normal placeholder:text-text-tertiary"
                        />
                    </span>
                    <button
                        type="button"
                        onClick={toggleMaximized}
                        title={maximized ? 'Exit full screen (Esc)' : 'Enter full screen'}
                        aria-label={maximized ? 'Exit full screen' : 'Enter full screen'}
                        className="flex h-6 w-6 items-center justify-center rounded-sm text-text-tertiary transition-colors hover:bg-surface-hover hover:text-foreground"
                    >
                        {maximized
                            ? <IconArrowsMinimize className="h-3.5 w-3.5" stroke={1.5} />
                            : <IconArrowsMaximize className="h-3.5 w-3.5" stroke={1.5} />}
                    </button>
                    <button
                        type="button"
                        onClick={() => setConfirmOpen(true)}
                        title="Remove this diagram"
                        className="flex h-6 w-6 items-center justify-center rounded-sm text-text-tertiary transition-colors hover:bg-danger hover:text-text-inverse"
                    >
                        <IconTrash className="h-3.5 w-3.5" stroke={1.5} />
                    </button>
                </div>
                <div className={maximized ? 'min-h-0 flex-1' : ''} style={maximized ? undefined : { height: 440 }}>
                    <Suspense fallback={<div className="flex h-full items-center justify-center text-sm text-text-tertiary">Loading diagram…</div>}>
                        <Canvas graph={graph} editable={editable} name={node.attrs.name ?? ''} onChange={onChange} onActivate={onActivate} />
                    </Suspense>
                </div>
            </div>

            <ConfirmDialog
                open={confirmOpen}
                title="Remove diagram"
                message={`Remove ${name ? `"${name}"` : 'this network diagram'} from the page? Its layout will be lost.`}
                confirmLabel="Remove diagram"
                onConfirm={() => { setConfirmOpen(false); deleteNode(); }}
                onCancel={() => setConfirmOpen(false)}
            />
        </NodeViewWrapper>
    );
}
