import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import { TextStyle, Color } from '@tiptap/extension-text-style';
import Highlight from '@tiptap/extension-highlight';
import { Table, TableRow, TableHeader, TableCell } from '@tiptap/extension-table';
import Placeholder from '@tiptap/extension-placeholder';
import { TaskList, TaskItem } from '@tiptap/extension-list';
import CodeBlockLowlight from '@tiptap/extension-code-block-lowlight';
import { Code } from '@tiptap/extension-code';
import { Link } from '@tiptap/extension-link';
import { createLowlight, common } from 'lowlight';
import { IconPlus } from '@tabler/icons-react';

import { ResizableImage } from '@/extensions/ResizableImage';
import { Callout } from '@/extensions/Callout';
import { WikiLink } from '@/extensions/WikiLink';
import { Diagram } from '@/extensions/Diagram';
import { SlashCommands } from '@/extensions/SlashCommands';
import { ImageUpload } from '@/extensions/ImageUpload';
import { LeadingParagraph } from '@/extensions/LeadingParagraph';
import { cleanPastedHtml } from '@/utils/pasteCleanup';
import Toolbar from './Toolbar';
import SuggestionList from './SuggestionList';
import WikiLinkPreview from './WikiLinkPreview';

// Module-level: the grammar registry is stateless and shared by every editor
// instance (edit + read views highlight identically).
const lowlight = createLowlight(common);

// StarterKit's Link derives `inclusive` from `autolink` (default true), which
// makes the cursor at a link's end boundary count as "inside" it — typing there
// extends the link and the toolbar stays in link mode. Force non-inclusive so
// text typed after a link is plain, while keeping autolink on paste/type.
const NonInclusiveLink = Link.extend({
    inclusive() {
        return false;
    },
});

// Same boundary trap as Link: inline code defaults to inclusive, so typing at
// the end of a code span extends it. Force non-inclusive so text after a code
// span is plain (toggle the button again to resume code).
const NonInclusiveCode = Code.extend({
    inclusive() {
        return false;
    },
});

/**
 * Recursively drop invalid empty text nodes. ProseMirror requires every text
 * node to carry a non-empty string; a `{ type: 'text', text: null }` (or '')
 * makes `Node.fromJSON` throw and blanks the WHOLE document. Such nodes have
 * crept into stored content (seeder fallback, link-adjacent segments), so we
 * defensively strip them before handing content to the editor — otherwise a
 * single bad node renders the page blank even though the data is recoverable.
 */
function sanitizeDoc(node) {
    if (!node || typeof node !== 'object' || !Array.isArray(node.content)) {
        return node;
    }
    return {
        ...node,
        content: node.content
            .filter((c) => !(c?.type === 'text' && (typeof c.text !== 'string' || c.text === '')))
            .map(sanitizeDoc),
    };
}

/**
 * TipTap editor component — used for both edit and read-only view.
 *
 * Props:
 *   content        – initial TipTap JSON document
 *   editable       – true for edit mode, false for read-only
 *   suggestions    – [{ id, title, slug }] for wiki-link autocomplete
 *   resolvedLinks  – { [title]: '/documents/{id}' } for read-only link resolution
 *   onUpdate(json) – called when editor content changes (edit mode only)
 *   onReady(json)  – called once with the editor's normalized content after it
 *                    is created; the baseline for dirty-tracking, since the
 *                    editor fills schema defaults the stored JSON may omit
 *   placeholder    – placeholder text shown in empty editor
 *   canCreate      – when true (read mode + create permission), the broken
 *                    wiki-link hover card offers "Create page"
 *   workspaceId    – current document's workspace id, used when creating a
 *                    page from a broken wiki-link
 */

const customCellAttributes = {
    backgroundColor: {
        default: null,
        parseHTML: element => element.style.backgroundColor || null,
        renderHTML: attributes => {
            if (!attributes.backgroundColor) return {};
            return { style: `background-color: ${attributes.backgroundColor}` };
        },
    },
};

const CustomTableCell = TableCell.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            ...customCellAttributes,
        };
    },
});

const CustomTableHeader = TableHeader.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            ...customCellAttributes,
        };
    },
});

export default function TipTapEditor({
    content,
    editable = true,
    suggestions = [],
    resolvedLinks = {},
    onUpdate,
    onReady,
    placeholder = 'Start writing… Type / for commands or [[ to link a page.',
    canCreate = false,
    workspaceId = null,
}) {
    const [wikiSuggestion, setWikiSuggestion] = useState(null);
    const [slashSuggestion, setSlashSuggestion] = useState(null);

    const wikiKeyRef  = useRef(null);
    const slashKeyRef = useRef(null);

    // A wiki-link resolves if it matches a known page title OR an already-saved
    // outgoing link. Without the suggestions fallback a link to a page that
    // exists still shows as "doesn't exist yet" until the doc is saved and the
    // backend repopulates outgoing_links — so resolve against the page list too.
    // Explicit resolvedLinks win (they carry the canonical id for the title).
    const linkTargets = useMemo(() => {
        const map = {};
        for (const d of suggestions) {
            if (d?.title) map[d.title] = `/documents/${d.id}`;
        }
        return { ...map, ...resolvedLinks };
    }, [suggestions, resolvedLinks]);

    const safeContent = useMemo(() => (content ? sanitizeDoc(content) : content), [content]);

    const editor = useEditor({
        editable,
        // A doc requires block+ content; default to an empty paragraph (never an
        // empty doc) so there's always a text block to receive inline inserts
        // like wiki-links — otherwise an atom can land at the top level.
        content: safeContent ?? { type: 'doc', content: [{ type: 'paragraph' }] },
        onCreate: ({ editor: e }) => {
            // The editor normalizes the initial doc (filling default attrs the
            // stored JSON omits) as it's built, so this is the true post-load
            // baseline. Consumers compare against it so that normalization — and
            // any settling transaction it triggers — isn't mistaken for an edit.
            onReady?.(e.getJSON());
        },
        onUpdate: ({ editor: e }) => {
            // Pass whether the editor is focused: a genuine edit always follows the
            // user focusing the editor, whereas load-time settling transactions
            // (schema defaults, initial content) fire while it's unfocused. Lets
            // an always-editable consumer tell real edits from that noise.
            if (editable) onUpdate?.(e.getJSON(), e.isFocused);
        },
        editorProps: {
            attributes: {
                class: 'tiptap',
                spellcheck: 'true',
            },
            transformPastedHTML: cleanPastedHtml,
        },
        extensions: [
            StarterKit.configure({
                heading: { levels: [1, 2, 3] },
                // Link and Underline are now built into StarterKit v3. Link and
                // inline code are replaced below by non-inclusive variants so
                // typing past their end boundary produces plain text.
                link: false,
                code: false,
                underline: {},
                // Replaced by CodeBlockLowlight below (same node name, plus
                // a language attr and client-side syntax highlighting).
                codeBlock: false,
            }),
            CodeBlockLowlight.configure({ lowlight }),
            NonInclusiveLink.configure({
                openOnClick: !editable,
                HTMLAttributes: { rel: 'noopener noreferrer' },
            }),
            NonInclusiveCode,
            TaskList,
            TaskItem.configure({ nested: true }),
            Callout,
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            TextStyle,
            Color.configure({ types: ['textStyle'] }),
            Highlight.configure({ multicolor: true }),
            ResizableImage.configure({ inline: false, allowBase64: false }),
            Diagram,
            Table.configure({ resizable: true }),
            TableRow,
            CustomTableHeader,
            CustomTableCell,
            Placeholder.configure({ placeholder }),
            WikiLink.configure({
                suggestions,
                resolvedLinks: linkTargets,
                onSuggestionStart:   (p) => setWikiSuggestion(p),
                onSuggestionUpdate:  (p) => setWikiSuggestion(p),
                onSuggestionExit:    ()  => setWikiSuggestion(null),
                onSuggestionKeyDown: (e) => wikiKeyRef.current?.(e) ?? false,
            }),
            ...(editable
                ? [
                      SlashCommands.configure({
                          onSuggestionStart:   (p) => setSlashSuggestion(p),
                          onSuggestionUpdate:  (p) => setSlashSuggestion(p),
                          onSuggestionExit:    ()  => setSlashSuggestion(null),
                          onSuggestionKeyDown: (e) => slashKeyRef.current?.(e) ?? false,
                      }),
                      ImageUpload,
                      LeadingParagraph,
                  ]
                : []),
        ],
    });

    // Keep editable flag in sync (e.g. switching from read→edit without remount).
    useEffect(() => {
        if (editor && editor.isEditable !== editable) {
            editor.setEditable(editable);
        }
    }, [editor, editable]);

    // For read-only view: update content when the prop changes (e.g. after Inertia reload).
    useEffect(() => {
        if (editor && !editable && safeContent) {
            editor.commands.setContent(safeContent, false);
        }
    }, [editor, editable, safeContent]);

    return (
        <div className="flex flex-col">
            {editable && <Toolbar editor={editor} />}
            <EditorContent
                editor={editor}
                className={editable ? 'tiptap-edit-area' : 'tiptap-read-area'}
            />
            {wikiSuggestion && (
                <SuggestionList
                    suggestion={wikiSuggestion}
                    keyHandlerRef={wikiKeyRef}
                    renderItem={(item) =>
                        item.__create ? (
                            <div className="flex items-center gap-1.5 text-left leading-tight text-accent-600">
                                <IconPlus size={13} stroke={1.5} />
                                <span>Create new page '{item.title}'</span>
                            </div>
                        ) : (
                            <div className="flex flex-col text-left leading-tight">
                                <span>{item.title}</span>
                                <span className="text-[10px] text-text-tertiary">
                                    {item.workspace?.name} / {item.slug}
                                </span>
                            </div>
                        )
                    }
                />
            )}
            {slashSuggestion && (
                <SuggestionList
                    suggestion={slashSuggestion}
                    keyHandlerRef={slashKeyRef}
                    renderItem={(item) => item.title}
                />
            )}
            <WikiLinkPreview canCreate={!editable && canCreate} workspaceId={workspaceId} />
        </div>
    );
}
