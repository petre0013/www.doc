import { Extension } from '@tiptap/core';
import Suggestion from '@tiptap/suggestion';
import { PluginKey } from '@tiptap/pm/state';

const SlashCommandsPluginKey = new PluginKey('slashCommands');

const ALL_COMMANDS = [
    {
        title: 'Heading 1',
        group: 'Text',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).setNode('heading', { level: 1 }).run(),
    },
    {
        title: 'Heading 2',
        group: 'Text',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).setNode('heading', { level: 2 }).run(),
    },
    {
        title: 'Heading 3',
        group: 'Text',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).setNode('heading', { level: 3 }).run(),
    },
    {
        title: 'Bullet List',
        group: 'Lists',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).toggleBulletList().run(),
    },
    {
        title: 'Ordered List',
        group: 'Lists',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).toggleOrderedList().run(),
    },
    {
        title: 'Task List',
        group: 'Lists',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).toggleTaskList().run(),
    },
    {
        title: 'Blockquote',
        group: 'Blocks',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).setBlockquote().run(),
    },
    {
        title: 'Code Block',
        group: 'Blocks',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).setCodeBlock().run(),
    },
    {
        title: 'Table',
        group: 'Blocks',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(),
    },
    {
        title: 'Divider',
        group: 'Blocks',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).setHorizontalRule().run(),
    },
    {
        title: 'Diagram',
        group: 'Blocks',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).insertDiagram().run(),
    },
    ...['info', 'success', 'warning', 'danger'].map((kind) => ({
        title: `Callout: ${kind.charAt(0).toUpperCase()}${kind.slice(1)}`,
        group: 'Callouts',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).toggleCallout({ kind }).run(),
    })),
];

export const SlashCommands = Extension.create({
    name: 'slashCommands',

    addOptions() {
        return {
            onSuggestionStart: null,
            onSuggestionUpdate: null,
            onSuggestionExit: null,
            onSuggestionKeyDown: null,
        };
    },

    addProseMirrorPlugins() {
        const ext = this;

        return [
            Suggestion({
                pluginKey: SlashCommandsPluginKey,
                editor: this.editor,
                char: '/',
                startOfLine: false,
                allowSpaces: false,

                command: ({ editor, range, props }) => {
                    props.command({ editor, range });
                },

                items: ({ query }) => {
                    if (!query) return ALL_COMMANDS;
                    const q = query.toLowerCase();
                    return ALL_COMMANDS.filter((c) => c.title.toLowerCase().includes(q));
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
