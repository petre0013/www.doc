import { Node } from '@tiptap/core';
import { ReactNodeViewRenderer } from '@tiptap/react';
import DiagramNodeView from '@/components/editor/DiagramNodeView';

const EMPTY_GRAPH = { nodes: [], edges: [], viewport: { x: 0, y: 0, zoom: 1 } };

/**
 * Block node holding a React Flow diagram (persisted node type: `networkDiagram`,
 * kept as-is so existing documents keep rendering).
 *
 * The diagram's editable data — `graph` ({ nodes, edges, viewport }) — is the
 * canonical source of truth and rides along in the node's attrs (so it's stored
 * in documents.content and versioned with the page). `imageSrc` is a DERIVED
 * PNG (rendered from the graph on save, uploaded as an asset) shown wherever a
 * live canvas can't run: read view, PDF/DOCX export, search, version snapshots.
 *
 * Phase 1: schema only — the node round-trips through the editor and
 * RenderDocument as a static image/placeholder. The editable React Flow
 * NodeView arrives in Phase 2.
 */
export const Diagram = Node.create({
    name: 'networkDiagram',
    group: 'block',
    atom: true,        // leaf node — the canvas manages its own internals
    draggable: true,
    selectable: true,

    addAttributes() {
        return {
            // Canonical graph. Each attr declares a no-op renderHTML so the
            // object never leaks into a DOM attribute; the node-level renderHTML
            // and the React NodeView own all rendering.
            graph: {
                default: EMPTY_GRAPH,
                renderHTML: () => ({}),
            },
            // User-facing diagram name. Rendered by the node-level renderHTML and
            // the NodeView (as a caption), never as a bare DOM attribute.
            name: {
                default: '',
                renderHTML: () => ({}),
            },
            width: {
                default: null,
                renderHTML: () => ({}),
            },
            align: {
                default: 'left',
                renderHTML: () => ({}),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'div[data-network-diagram]' }];
    },

    renderHTML({ node }) {
        const align = node.attrs.align ?? 'left';
        const name = (node.attrs.name ?? '').trim();
        const label = name || 'Untitled diagram';

        return ['div', { 'data-network-diagram': 'true', class: 'network-diagram-placeholder' }, label];
    },

    addNodeView() {
        return ReactNodeViewRenderer(DiagramNodeView);
    },

    addCommands() {
        return {
            insertDiagram: () => ({ commands }) =>
                commands.insertContent({ type: this.name, attrs: { graph: EMPTY_GRAPH } }),
        };
    },
});
