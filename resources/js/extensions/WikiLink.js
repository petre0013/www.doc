import { Node, mergeAttributes } from '@tiptap/core';
import Suggestion from '@tiptap/suggestion';
import { PluginKey, TextSelection } from '@tiptap/pm/state';
import { Fragment } from '@tiptap/pm/model';
import { router } from '@inertiajs/react';

const WikiLinkPluginKey = new PluginKey('wikiLink');

/**
 * Inline node representing a [[Wiki Link]].
 *
 * Storage format: { type: "wikiLink", attrs: { title: "Page Title" } }
 *
 * In edit mode the node renders as a styled chip. In read-only mode
 * it resolves to an <a> using the resolvedLinks map (title → href).
 */
export const WikiLink = Node.create({
    name: 'wikiLink',
    group: 'inline',
    inline: true,
    selectable: true,
    atom: true,

    addOptions() {
        return {
            suggestions: [],       // [{ id, title, slug }]
            resolvedLinks: {},     // { title: '/documents/{id}' }
            onSuggestionStart: null,
            onSuggestionUpdate: null,
            onSuggestionExit: null,
            onSuggestionKeyDown: null,
        };
    },

    addAttributes() {
        return {
            title: { default: null },
            target_id: { default: null },
        };
    },

    parseHTML() {
        return [
            {
                tag: 'span[data-wiki-link]',
                getAttrs: (el) => ({ 
                    title: el.getAttribute('data-title'),
                    target_id: el.getAttribute('data-target-id') ? parseInt(el.getAttribute('data-target-id')) : null,
                }),
            },
        ];
    },

    renderHTML({ node, HTMLAttributes }) {
        const title = node.attrs.title ?? '';
        const target_id = node.attrs.target_id ?? null;
        
        const attrs = {
            'data-wiki-link': 'true',
            'data-title': title,
            class: 'wiki-link',
        };
        if (target_id) attrs['data-target-id'] = target_id;

        return [
            'span',
            mergeAttributes(HTMLAttributes, attrs),
            title,
        ];
    },

    addNodeView() {
        return ({ node, extension, editor }) => {
            const title = node.attrs.title ?? '';
            const resolvedLinks = extension.options.resolvedLinks ?? {};
            const href = resolvedLinks[title];
            const editable = editor.isEditable;

            let dom;
            if (href && !editable) {
                dom = document.createElement('a');
                dom.href = href;
                dom.className = 'wiki-link resolved';
                dom.addEventListener('click', (e) => {
                    if (e.metaKey || e.ctrlKey || e.shiftKey) return;
                    e.preventDefault();
                    router.visit(href);
                });
            } else {
                dom = document.createElement('span');
                dom.className = href ? 'wiki-link resolved' : 'wiki-link unresolved';
            }

            if (editable) dom.classList.add('wiki-link-edit');

            dom.setAttribute('data-wiki-link', 'true');
            dom.setAttribute('data-title', title);
            dom.textContent = title;

            // Hover preview — fire after 400 ms, cancel on quick leave
            let showTimer;

            dom.addEventListener('mouseenter', () => {
                showTimer = setTimeout(() => {
                    document.dispatchEvent(new CustomEvent('wiki-link-preview-show', {
                        detail: { title, href: href ?? null, broken: !href, rect: dom.getBoundingClientRect() },
                    }));
                }, 400);
            });

            dom.addEventListener('mouseleave', () => {
                clearTimeout(showTimer);
                document.dispatchEvent(new CustomEvent('wiki-link-preview-hide'));
            });

            return {
                dom,
                destroy() {
                    clearTimeout(showTimer);
                    document.dispatchEvent(new CustomEvent('wiki-link-preview-hide'));
                },
            };
        };
    },

    addProseMirrorPlugins() {
        const ext = this;

        return [
            Suggestion({
                pluginKey: WikiLinkPluginKey,
                editor: this.editor,
                char: '[[',
                allowSpaces: true,
                startOfLine: false,

                command: ({ editor, range, props }) => {
                    // Insert the link plus its padding spaces straight through the
                    // transaction. insertContent(' ') / array content routes the
                    // whitespace through HTML parsing, which trims boundary spaces
                    // and leaves links butted against neighbouring words; building
                    // real text nodes and tr.insert-ing them never trims.
                    editor
                        .chain()
                        .focus()
                        .deleteRange(range)
                        .command(({ tr, state }) => {
                            const pos = range.from; // insertion point after the delete
                            const before = pos > 1 ? tr.doc.textBetween(pos - 1, pos) : ' ';
                            const after  = tr.doc.textBetween(pos, Math.min(tr.doc.content.size, pos + 1));

                            // Pad only when a space isn't already there (no doubles).
                            const pieces = [];
                            if (!(before === '' || /\s/.test(before))) pieces.push(state.schema.text(' '));
                            pieces.push(state.schema.nodes[ext.name].create({ 
                                title: props.title,
                                target_id: props.id 
                            }));
                            if (!/\s/.test(after)) pieces.push(state.schema.text(' '));

                            const fragment = Fragment.fromArray(pieces);
                            tr.insert(pos, fragment);
                            tr.setSelection(TextSelection.create(tr.doc, pos + fragment.size));

                            return true;
                        })
                        .run();
                },

                items: ({ query }) => {
                    const q = query.toLowerCase();
                    const matches = (ext.options.suggestions ?? [])
                        .filter((d) => d.title.toLowerCase().includes(q));

                    // Offer "Create new page" when the typed title is non-empty and
                    // doesn't already exist verbatim — selecting it inserts an
                    // unresolved link (target_id null) the user can create later.
                    const trimmed = query.trim();
                    const exists = (ext.options.suggestions ?? []).some(
                        (d) => d.title.toLowerCase() === trimmed.toLowerCase(),
                    );
                    const offerCreate = trimmed !== '' && !exists;

                    const list = matches.slice(0, offerCreate ? 7 : 8);
                    if (offerCreate) {
                        list.push({ __create: true, title: trimmed, id: null });
                    }
                    return list;
                },

                render: () => ({
                    onStart:   (props) => ext.options.onSuggestionStart?.(props),
                    onUpdate:  (props) => ext.options.onSuggestionUpdate?.(props),
                    onKeyDown: ({ event }) => ext.options.onSuggestionKeyDown?.(event) ?? false,
                    onExit:    ()       => ext.options.onSuggestionExit?.(),
                }),
            }),
        ];
    },
});
