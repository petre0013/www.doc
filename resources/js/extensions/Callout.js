import { Node, mergeAttributes } from '@tiptap/core';

// Keep in sync with CalloutNode in app/Services/RenderDocument.php and the
// .callout-* styles in resources/css/app.css (schema-parity rule 2).
export const CALLOUT_KINDS = ['info', 'success', 'warning', 'danger'];

/**
 * Callout / admonition block: a tinted panel holding block content, with a
 * `kind` attr selecting the semantic color family. Serialized as
 * `<div data-callout="kind">` — the same signature RenderDocument emits and
 * the paste cleaner whitelists, so it round-trips through copy/paste.
 */
export const Callout = Node.create({
    name: 'callout',
    group: 'block',
    content: 'block+',
    defining: true,

    addAttributes() {
        return {
            kind: {
                default: 'info',
                parseHTML: (el) => {
                    const kind = el.getAttribute('data-callout');
                    return CALLOUT_KINDS.includes(kind) ? kind : 'info';
                },
                renderHTML: (attrs) => ({ 'data-callout': attrs.kind }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'div[data-callout]' }];
    },

    renderHTML({ node, HTMLAttributes }) {
        return [
            'div',
            mergeAttributes(HTMLAttributes, { class: `callout callout-${node.attrs.kind}` }),
            0,
        ];
    },

    addCommands() {
        return {
            toggleCallout:
                (attrs = {}) =>
                ({ commands }) =>
                    commands.toggleWrap(this.name, attrs),
            setCalloutKind:
                (kind) =>
                ({ commands }) =>
                    CALLOUT_KINDS.includes(kind) && commands.updateAttributes(this.name, { kind }),
        };
    },
});
