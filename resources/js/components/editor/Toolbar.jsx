import React, { useState, useCallback, useRef, useEffect } from 'react';
import {
    IconBold, IconItalic, IconUnderline, IconStrikethrough, IconCode, IconBraces,
    IconPalette, IconHighlight, IconBan, IconX,
    IconH1, IconH2, IconH3,
    IconList, IconListNumbers, IconListCheck, IconBlockquote, IconMinus, IconInfoSquareRounded,
    IconLink, IconLinkOff, IconPhoto, IconTable, IconTableOff, IconTopologyStar3,
    IconRowInsertBottom, IconRowInsertTop, IconRowRemove,
    IconColumnInsertLeft, IconColumnInsertRight, IconColumnRemove,
    IconAlignLeft, IconAlignCenter, IconAlignRight,
} from '@tabler/icons-react';
import { insertFiles } from '@/extensions/ImageUpload';

// On-palette swatches. Text colours run deep enough to stay legible on cream;
// highlight fills are light tints so dark text stays readable on top.
const TEXT_COLORS = [
    { name: 'Ink',        value: '#5C625C' },
    { name: 'Sage',       value: '#4B6840' },
    { name: 'Blue',       value: '#4F6B86' },
    { name: 'Amber',      value: '#97702F' },
    { name: 'Terracotta', value: '#B5573E' },
];

const HIGHLIGHT_COLORS = [
    { name: 'Yellow', value: '#FBE7A2' },
    { name: 'Sage',   value: '#DAE6D4' },
    { name: 'Blue',   value: '#D7E3EC' },
    { name: 'Rose',   value: '#F0D6CD' },
    { name: 'Amber',  value: '#F3E2BE' },
];

// Language picker options for code blocks — a curated subset of lowlight's
// `common` registry (every value here must exist there, or highlighting
// silently no-ops for that block). Empty value = plain text (no language attr).
const CODE_LANGUAGES = [
    ['', 'Plain text'],
    ['bash', 'Bash'], ['c', 'C'], ['cpp', 'C++'], ['csharp', 'C#'], ['css', 'CSS'],
    ['diff', 'Diff'], ['go', 'Go'], ['ini', 'INI'], ['java', 'Java'],
    ['javascript', 'JavaScript'], ['json', 'JSON'], ['kotlin', 'Kotlin'],
    ['lua', 'Lua'], ['makefile', 'Makefile'], ['markdown', 'Markdown'],
    ['perl', 'Perl'], ['php', 'PHP'], ['python', 'Python'], ['ruby', 'Ruby'],
    ['rust', 'Rust'], ['shell', 'Shell'], ['sql', 'SQL'], ['swift', 'Swift'],
    ['typescript', 'TypeScript'], ['xml', 'HTML/XML'], ['yaml', 'YAML'],
];

// Callout kind chips (token triads from the status recipe in the styleguide).
const CALLOUT_OPTIONS = [
    { kind: 'info',    label: 'Info',    chip: 'bg-accent-50 text-accent-700 border-accent-200' },
    { kind: 'success', label: 'Success', chip: 'bg-success-surface text-success-text border-success-border' },
    { kind: 'warning', label: 'Warning', chip: 'bg-warning-surface text-warning-text border-warning-border' },
    { kind: 'danger',  label: 'Danger',  chip: 'bg-danger-surface text-danger border-danger-border' },
];

// Normalise to a 6-digit #rrggbb hex (expands #rgb); returns null if invalid.
function normalizeHex(value) {
    const v = (value ?? '').trim().replace(/^#/, '');
    if (/^[0-9a-fA-F]{6}$/.test(v)) return `#${v.toLowerCase()}`;
    if (/^[0-9a-fA-F]{3}$/.test(v)) return `#${v.split('').map((c) => c + c).join('').toLowerCase()}`;
    return null;
}

/**
 * Swatch + visual/hex picker popover shared by the text-colour and highlight
 * controls. Offers on-palette swatches, a native colour picker (full
 * hue/saturation wheel + hex on the OS dialog), a hex field, and a clear button.
 *   swatches – [{ name, value }]
 *   current  – currently applied hex (or null)
 *   onPick(value|null) – apply a colour, or null to clear (stays open)
 *   onClose  – close the popover (the only thing that dismisses it)
 *   fallback – seed colour for the native picker when nothing is applied
 */
function ColorPicker({ swatches, current, onPick, onClose, clearLabel, fallback = '#7e9d72' }) {
    const [hex, setHex] = useState(current ?? '');

    const applyHex = () => {
        const norm = normalizeHex(hex);
        if (norm) onPick(norm);
    };

    return (
        <div className="ml-1 flex items-center gap-1">
            {swatches.map((c) => (
                <button
                    key={c.value}
                    type="button"
                    title={c.name}
                    onMouseDown={(e) => { e.preventDefault(); onPick(c.value); }}
                    className={`h-5 w-5 rounded-full border transition-transform hover:scale-110 ${
                        current?.toLowerCase() === c.value.toLowerCase()
                            ? 'border-foreground ring-1 ring-foreground'
                            : 'border-border'
                    }`}
                    style={{ backgroundColor: c.value }}
                />
            ))}
            <button
                type="button"
                title={clearLabel}
                onMouseDown={(e) => { e.preventDefault(); onPick(null); }}
                className="flex h-5 w-5 items-center justify-center rounded-full border border-border text-text-tertiary transition-colors hover:bg-surface-hover hover:text-foreground"
            >
                <IconBan className="h-3.5 w-3.5" stroke={1.5} />
            </button>

            <div className="mx-0.5 h-5 w-px bg-border" />

            {/* Native colour picker — opens the OS wheel/hex dialog */}
            <label
                title="Pick a custom colour"
                className="relative h-5 w-5 shrink-0 overflow-hidden rounded-full border border-border"
                style={{ backgroundColor: normalizeHex(hex) ?? current ?? fallback }}
            >
                <input
                    type="color"
                    value={normalizeHex(hex) ?? normalizeHex(current) ?? fallback}
                    onChange={(e) => { setHex(e.target.value); onPick(e.target.value); }}
                    className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                />
            </label>
            <input
                type="text"
                value={hex}
                onChange={(e) => setHex(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter')  { e.preventDefault(); applyHex(); }
                    if (e.key === 'Escape') onClose?.();
                }}
                placeholder="#hex"
                className="h-6 w-16 rounded-sm border border-border bg-canvas px-1.5 text-xs text-foreground outline-none focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
            />
            <button
                type="button"
                title="Apply hex"
                onMouseDown={(e) => { e.preventDefault(); applyHex(); }}
                className="rounded-sm bg-accent-400 px-2 py-0.5 text-xs font-medium text-text-inverse hover:bg-accent-500"
            >
                Apply
            </button>
            <button
                type="button"
                title="Close"
                onMouseDown={(e) => { e.preventDefault(); onClose?.(); }}
                className="flex h-5 w-5 items-center justify-center rounded-sm text-text-tertiary transition-colors hover:bg-surface-hover hover:text-foreground"
            >
                <IconX className="h-3.5 w-3.5" stroke={1.5} />
            </button>
        </div>
    );
}

function ToolbarButton({ onClick, active, title, children, disabled }) {
    return (
        <button
            type="button"
            title={title}
            disabled={disabled}
            onMouseDown={(e) => {
                e.preventDefault();
                onClick();
            }}
            className={`flex h-7 w-7 items-center justify-center rounded-sm transition-colors duration-100 ${
                active
                    ? 'bg-accent-100 text-accent-600'
                    : 'text-text-secondary hover:bg-surface-hover hover:text-foreground'
            } disabled:opacity-40 disabled:cursor-not-allowed`}
        >
            {children}
        </button>
    );
}

function Divider() {
    return <div className="mx-1 h-5 w-px bg-border" />;
}

export default function Toolbar({ editor }) {
    // One exclusive picker at a time —
    // null | 'link' | 'table' | 'image' | 'textColor' | 'highlight'
    const [openPicker, setOpenPicker] = useState(null);
    const [linkValue,  setLinkValue]  = useState('');
    const [tableRows,  setTableRows]  = useState(3);
    const [tableCols,  setTableCols]  = useState(3);
    const [imageMode,  setImageMode]  = useState('upload');
    const [imageUrl,   setImageUrl]   = useState('');
    const fileInputRef = useRef(null);

    // Re-render whenever the editor selection or content changes so that
    // isActive() calls always reflect the current cursor position.
    const [, rerender] = useState(0);
    useEffect(() => {
        if (!editor) return;
        const update = () => rerender((v) => v + 1);
        editor.on('selectionUpdate', update);
        editor.on('transaction', update);
        return () => {
            editor.off('selectionUpdate', update);
            editor.off('transaction', update);
        };
    }, [editor]);

    function togglePicker(name, onOpen) {
        if (openPicker === name) {
            setOpenPicker(null);
        } else {
            onOpen?.();
            setOpenPicker(name);
        }
    }

    const applyLink = useCallback(() => {
        if (!linkValue.trim()) {
            editor.chain().focus().unsetLink().run();
        } else {
            editor.chain().focus().setLink({ href: linkValue.trim() }).run();
        }
        setOpenPicker(null);
        setLinkValue('');
    }, [editor, linkValue]);

    const insertImageUrl = useCallback(() => {
        if (imageUrl.trim()) {
            editor.chain().focus().setImage({ src: imageUrl.trim() }).run();
        }
        setOpenPicker(null);
        setImageUrl('');
    }, [editor, imageUrl]);

    const insertTable = useCallback(() => {
        const rows = Math.max(1, Math.min(20, tableRows));
        const cols = Math.max(1, Math.min(20, tableCols));
        editor.chain().focus().insertTable({ rows, cols, withHeaderRow: true }).run();
        setOpenPicker(null);
        setTableRows(3);
        setTableCols(3);
    }, [editor, tableRows, tableCols]);

    if (!editor) return null;

    const inTable        = editor.isActive('table');
    const inImage        = editor.isActive('image');
    const inDiagram      = editor.isActive('networkDiagram');
    const textColor      = editor.getAttributes('textStyle').color ?? null;
    const highlightColor = editor.getAttributes('highlight').color ?? null;
    const imgAlign  = inImage ? (editor.getAttributes('image').align ?? 'left') : null;
    const textAlign = editor.getAttributes('paragraph').textAlign
                   ?? editor.getAttributes('heading').textAlign
                   ?? 'left';

    useEffect(() => {
        if (inTable && openPicker === 'table') setOpenPicker(null);
        if (!inTable && openPicker === 'tableOptions') setOpenPicker(null);
    }, [inTable, openPicker]);

    return (
        <div className="sticky top-0 z-20 flex flex-wrap items-center gap-0.5 border-b border-border bg-surface px-2 py-1.5">

            {/* ── Inline marks ──────────────────────────────────────── */}
            <ToolbarButton title="Bold (Ctrl+B)" active={editor.isActive('bold')}
                onClick={() => editor.chain().focus().toggleBold().run()}>
                <IconBold className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>
            <ToolbarButton title="Italic (Ctrl+I)" active={editor.isActive('italic')}
                onClick={() => editor.chain().focus().toggleItalic().run()}>
                <IconItalic className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>
            <ToolbarButton title="Underline (Ctrl+U)" active={editor.isActive('underline')}
                onClick={() => editor.chain().focus().toggleUnderline().run()}>
                <IconUnderline className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>
            <ToolbarButton title="Strikethrough" active={editor.isActive('strike')}
                onClick={() => editor.chain().focus().toggleStrike().run()}>
                <IconStrikethrough className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>
            <ToolbarButton title="Inline code" active={editor.isActive('code')}
                onClick={() => editor.chain().focus().toggleCode().run()}>
                <IconCode className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>

            <Divider />

            {/* ── Text colour & highlight ───────────────────────────── */}
            <ToolbarButton title="Text colour" active={!!textColor || openPicker === 'textColor'}
                onClick={() => togglePicker('textColor')}>
                <span className="relative flex h-3.5 w-3.5 items-center justify-center">
                    <IconPalette className="h-3.5 w-3.5" stroke={1.5} />
                    {textColor && (
                        <span className="absolute -bottom-1 left-0 h-0.5 w-full rounded-full"
                            style={{ backgroundColor: textColor }} />
                    )}
                </span>
            </ToolbarButton>
            {openPicker === 'textColor' && (
                <ColorPicker
                    swatches={TEXT_COLORS}
                    current={textColor}
                    fallback="#5c625c"
                    clearLabel="Remove text colour"
                    onClose={() => setOpenPicker(null)}
                    onPick={(value) => {
                        if (value) editor.chain().focus().setColor(value).run();
                        else       editor.chain().focus().unsetColor().run();
                    }}
                />
            )}

            <ToolbarButton title="Highlight" active={!!highlightColor || openPicker === 'highlight'}
                onClick={() => togglePicker('highlight')}>
                <span className="relative flex h-3.5 w-3.5 items-center justify-center">
                    <IconHighlight className="h-3.5 w-3.5" stroke={1.5} />
                    {highlightColor && (
                        <span className="absolute -bottom-1 left-0 h-0.5 w-full rounded-full"
                            style={{ backgroundColor: highlightColor }} />
                    )}
                </span>
            </ToolbarButton>
            {openPicker === 'highlight' && (
                <ColorPicker
                    swatches={HIGHLIGHT_COLORS}
                    current={highlightColor}
                    fallback="#fbe7a2"
                    clearLabel="Remove highlight"
                    onClose={() => setOpenPicker(null)}
                    onPick={(value) => {
                        if (value) editor.chain().focus().setHighlight({ color: value }).run();
                        else       editor.chain().focus().unsetHighlight().run();
                    }}
                />
            )}

            <Divider />

            {/* ── Headings ──────────────────────────────────────────── */}
            <ToolbarButton title="Heading 1" active={editor.isActive('heading', { level: 1 })}
                onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()}>
                <IconH1 className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>
            <ToolbarButton title="Heading 2" active={editor.isActive('heading', { level: 2 })}
                onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}>
                <IconH2 className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>
            <ToolbarButton title="Heading 3" active={editor.isActive('heading', { level: 3 })}
                onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}>
                <IconH3 className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>

            <Divider />

            {/* ── Lists & blocks ────────────────────────────────────── */}
            <ToolbarButton title="Bullet list" active={editor.isActive('bulletList')}
                onClick={() => editor.chain().focus().toggleBulletList().run()}>
                <IconList className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>
            <ToolbarButton title="Ordered list" active={editor.isActive('orderedList')}
                onClick={() => editor.chain().focus().toggleOrderedList().run()}>
                <IconListNumbers className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>
            <ToolbarButton title="Task list" active={editor.isActive('taskList')}
                onClick={() => editor.chain().focus().toggleTaskList().run()}>
                <IconListCheck className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>
            <ToolbarButton title="Blockquote" active={editor.isActive('blockquote')}
                onClick={() => editor.chain().focus().toggleBlockquote().run()}>
                <IconBlockquote className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>
            <ToolbarButton title="Code block" active={editor.isActive('codeBlock')}
                onClick={() => editor.chain().focus().toggleCodeBlock().run()}>
                <IconBraces className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>
            <ToolbarButton title="Callout" active={editor.isActive('callout')}
                onClick={() => editor.chain().focus().toggleCallout().run()}>
                <IconInfoSquareRounded className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>

            {/* Code block context — language picker, visible inside a code block */}
            {editor.isActive('codeBlock') && (
                <select
                    value={editor.getAttributes('codeBlock').language ?? ''}
                    onChange={(e) =>
                        editor.chain().focus()
                            .updateAttributes('codeBlock', { language: e.target.value || null })
                            .run()
                    }
                    className="ui-select ml-1 h-6 rounded-sm border border-border bg-canvas px-1.5 text-xs text-foreground outline-none focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                    title="Code language"
                >
                    {CODE_LANGUAGES.map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                    ))}
                </select>
            )}

            {/* Callout context — kind switcher, visible inside a callout */}
            {editor.isActive('callout') && (
                <div className="ml-1 flex items-center gap-1">
                    {CALLOUT_OPTIONS.map(({ kind, label, chip }) => {
                        const current = editor.getAttributes('callout').kind === kind;
                        return (
                            <button
                                key={kind}
                                type="button"
                                title={`${label} callout`}
                                onMouseDown={(e) => {
                                    e.preventDefault();
                                    editor.chain().focus().setCalloutKind(kind).run();
                                }}
                                className={`rounded-full border px-2 py-0.5 text-[11px] transition-transform hover:scale-105 ${chip} ${
                                    current ? 'ring-1 ring-foreground' : ''
                                }`}
                            >
                                {label}
                            </button>
                        );
                    })}
                </div>
            )}

            <Divider />

            {/* ── Link ──────────────────────────────────────────────── */}
            <ToolbarButton
                title="Insert / edit link"
                active={editor.isActive('link') || openPicker === 'link'}
                onClick={() => {
                    if (editor.isActive('link')) {
                        editor.chain().focus().unsetLink().run();
                    } else {
                        togglePicker('link', () => {
                            setLinkValue(editor.getAttributes('link').href ?? '');
                        });
                    }
                }}
            >
                {editor.isActive('link')
                    ? <IconLinkOff className="h-3.5 w-3.5" stroke={1.5} />
                    : <IconLink    className="h-3.5 w-3.5" stroke={1.5} />}
            </ToolbarButton>

            {openPicker === 'link' && (
                <div className="ml-1 flex items-center gap-1">
                    <input
                        autoFocus
                        type="url"
                        value={linkValue}
                        onChange={(e) => setLinkValue(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter')  applyLink();
                            if (e.key === 'Escape') setOpenPicker(null);
                        }}
                        placeholder="https://..."
                        className="h-6 rounded-sm border border-border bg-canvas px-2 text-xs text-foreground outline-none focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                        style={{ width: 200 }}
                    />
                    <button type="button" onMouseDown={(e) => { e.preventDefault(); applyLink(); }}
                        className="rounded-sm bg-accent-400 px-2 py-0.5 text-xs font-medium text-text-inverse hover:bg-accent-500">
                        Apply
                    </button>
                </div>
            )}

            {/* ── Table ─────────────────────────────────────────────── */}
            {!inTable && (
                <ToolbarButton title="Insert table" active={openPicker === 'table'}
                    onClick={() => togglePicker('table')}>
                    <IconTable className="h-3.5 w-3.5" stroke={1.5} />
                </ToolbarButton>
            )}

            {openPicker === 'table' && (
                <div className="ml-1 flex items-center gap-1.5">
                    <label className="text-xs text-text-secondary">Rows</label>
                    <input autoFocus type="number" min={1} max={20} value={tableRows}
                        onChange={(e) => setTableRows(Number(e.target.value))}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter')  insertTable();
                            if (e.key === 'Escape') setOpenPicker(null);
                        }}
                        className="h-6 w-12 rounded-sm border border-border bg-canvas px-1.5 text-center text-xs outline-none focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                    />
                    <label className="text-xs text-text-secondary">Cols</label>
                    <input type="number" min={1} max={20} value={tableCols}
                        onChange={(e) => setTableCols(Number(e.target.value))}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter')  insertTable();
                            if (e.key === 'Escape') setOpenPicker(null);
                        }}
                        className="h-6 w-12 rounded-sm border border-border bg-canvas px-1.5 text-center text-xs outline-none focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                    />
                    <button type="button" onMouseDown={(e) => { e.preventDefault(); insertTable(); }}
                        className="rounded-sm bg-accent-400 px-2 py-0.5 text-xs font-medium text-text-inverse hover:bg-accent-500">
                        Insert
                    </button>
                </div>
            )}

            {/* Table context controls */}
            {inTable && (
                <div className="relative flex items-center">
                        <ToolbarButton title="Table Options" active={openPicker === 'tableOptions'}
                            onClick={() => togglePicker('tableOptions')}>
                            <IconTable className="h-3.5 w-3.5" stroke={1.5} />
                        </ToolbarButton>

                        {openPicker === 'tableOptions' && (
                            <div className="absolute top-full left-0 mt-1 z-30 flex flex-col gap-2 rounded-md border border-border bg-surface p-2 shadow-md min-w-max">
                                <div className="flex gap-1 border-b border-border pb-2">
                                    <ToolbarButton title="Add row above" onClick={() => editor.chain().focus().addRowBefore().run()}><IconRowInsertTop className="h-4 w-4" stroke={1.5} /></ToolbarButton>
                                    <ToolbarButton title="Add row below" onClick={() => editor.chain().focus().addRowAfter().run()}><IconRowInsertBottom className="h-4 w-4" stroke={1.5} /></ToolbarButton>
                                    <ToolbarButton title="Delete row" onClick={() => editor.chain().focus().deleteRow().run()}><IconRowRemove className="h-4 w-4" stroke={1.5} /></ToolbarButton>
                                    <Divider />
                                    <ToolbarButton title="Add column left" onClick={() => editor.chain().focus().addColumnBefore().run()}><IconColumnInsertLeft className="h-4 w-4" stroke={1.5} /></ToolbarButton>
                                    <ToolbarButton title="Add column right" onClick={() => editor.chain().focus().addColumnAfter().run()}><IconColumnInsertRight className="h-4 w-4" stroke={1.5} /></ToolbarButton>
                                    <ToolbarButton title="Delete column" onClick={() => editor.chain().focus().deleteColumn().run()}><IconColumnRemove className="h-4 w-4" stroke={1.5} /></ToolbarButton>
                                </div>
                                <div className="flex items-center gap-2 border-b border-border pb-2">
                                    <button type="button" onClick={() => editor.chain().focus().mergeCells().run()} disabled={!editor.can().mergeCells()} className="rounded-sm bg-accent-50 px-2 py-1 text-xs text-accent-600 hover:bg-accent-100 disabled:opacity-50">Merge</button>
                                    <button type="button" onClick={() => editor.chain().focus().splitCell().run()} disabled={!editor.can().splitCell()} className="rounded-sm bg-accent-50 px-2 py-1 text-xs text-accent-600 hover:bg-accent-100 disabled:opacity-50">Split</button>
                                    <button type="button" onClick={() => editor.chain().focus().toggleHeaderRow().run()} className="rounded-sm bg-accent-50 px-2 py-1 text-xs text-accent-600 hover:bg-accent-100">Toggle Header Row</button>
                                    <button type="button" onClick={() => editor.chain().focus().toggleHeaderColumn().run()} className="rounded-sm bg-accent-50 px-2 py-1 text-xs text-accent-600 hover:bg-accent-100">Toggle Header Col</button>
                                </div>
                                <div className="flex items-center gap-2 border-b border-border pb-2">
                                    <span className="text-xs text-text-secondary">Cell Color:</span>
                                    <ColorPicker
                                        swatches={HIGHLIGHT_COLORS}
                                        current={editor.getAttributes('tableCell').backgroundColor || editor.getAttributes('tableHeader').backgroundColor}
                                        fallback="#ffffff"
                                        clearLabel="Remove cell color"
                                        onClose={() => setOpenPicker(null)}
                                        onPick={(value) => {
                                            if (value) editor.chain().focus().setCellAttribute('backgroundColor', value).run();
                                            else editor.chain().focus().setCellAttribute('backgroundColor', null).run();
                                        }}
                                    />
                                </div>
                                <div>
                                    <button type="button" onClick={() => { editor.chain().focus().deleteTable().run(); setOpenPicker(null); }} className="flex w-full items-center justify-center gap-1 rounded-sm bg-danger-surface px-2 py-1 text-xs text-danger hover:bg-danger hover:text-text-inverse">
                                        <IconTableOff className="h-3.5 w-3.5" stroke={1.5} /> Delete Table
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
            )}

            {/* ── Horizontal rule ───────────────────────────────────── */}
            <ToolbarButton title="Horizontal rule" active={false}
                onClick={() => editor.chain().focus().setHorizontalRule().run()}>
                <IconMinus className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>

            {/* ── Diagram ───────────────────────────────────────────── */}
            <ToolbarButton title="Insert diagram" active={inDiagram}
                onClick={() => editor.chain().focus().insertDiagram().run()}>
                <IconTopologyStar3 className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>

            {/* ── Image ─────────────────────────────────────────────── */}
            <ToolbarButton title={inImage ? "Edit image" : "Insert image"} active={openPicker === 'image' || inImage}
                onClick={() => togglePicker('image', () => {
                    if (inImage) {
                        setImageMode('url');
                        setImageUrl(editor.getAttributes('image').src ?? '');
                    } else {
                        setImageMode('upload');
                        setImageUrl('');
                    }
                })}>
                <IconPhoto className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>

            {/* Hidden file input */}
            <input ref={fileInputRef} type="file" accept="image/*" className="hidden"
                onChange={(e) => {
                    const files = Array.from(e.target.files ?? []).filter(f => f.type.startsWith('image/'));
                    if (files.length > 0) insertFiles(editor, editor.view, files);
                    setOpenPicker(null);
                    e.target.value = '';
                }}
            />

            {openPicker === 'image' && (
                <div className="ml-1 flex items-center gap-1">
                    <div className="flex overflow-hidden rounded-sm border border-border text-xs">
                        <button type="button" onMouseDown={(e) => { e.preventDefault(); setImageMode('upload'); }}
                            className={`px-2 py-0.5 ${imageMode === 'upload' ? 'bg-accent-100 text-accent-600' : 'text-text-secondary hover:bg-surface-hover'}`}>
                            {inImage ? 'Replace (Upload)' : 'Upload'}
                        </button>
                        <button type="button" onMouseDown={(e) => { e.preventDefault(); setImageMode('url'); }}
                            className={`border-l border-border px-2 py-0.5 ${imageMode === 'url' ? 'bg-accent-100 text-accent-600' : 'text-text-secondary hover:bg-surface-hover'}`}>
                            URL
                        </button>
                    </div>

                    {imageMode === 'upload' ? (
                        <button type="button"
                            onMouseDown={(e) => { e.preventDefault(); fileInputRef.current?.click(); }}
                            className="rounded-sm border border-border bg-canvas px-2 py-0.5 text-xs text-text-secondary transition-colors hover:bg-surface-hover">
                            Choose file…
                        </button>
                    ) : (
                        <>
                            <input autoFocus type="url" value={imageUrl}
                                onChange={(e) => setImageUrl(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter')  insertImageUrl();
                                    if (e.key === 'Escape') setOpenPicker(null);
                                }}
                                placeholder="https://…"
                                className="h-6 rounded-sm border border-border bg-canvas px-2 text-xs text-foreground outline-none focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                                style={{ width: 200 }}
                            />
                            <button type="button" onMouseDown={(e) => { e.preventDefault(); insertImageUrl(); }}
                                className="rounded-sm bg-accent-400 px-2 py-0.5 text-xs font-medium text-text-inverse hover:bg-accent-500">
                                {inImage ? 'Update' : 'Insert'}
                            </button>
                        </>
                    )}
                    
                    {inImage && (
                        <button type="button" onMouseDown={(e) => {
                            e.preventDefault();
                            editor.chain().focus().deleteSelection().run();
                            setOpenPicker(null);
                        }}
                            className="ml-1 rounded-sm border border-danger-border bg-danger-surface px-2 py-0.5 text-xs text-danger transition-colors hover:bg-danger hover:text-text-inverse">
                            Delete
                        </button>
                    )}
                </div>
            )}

            {/* ── Alignment ─────────────────────────────────────────── */}
            <Divider />
            <ToolbarButton title="Align left"
                active={inImage ? imgAlign === 'left' : textAlign === 'left'}
                onClick={() => inImage
                    ? editor.commands.updateAttributes('image', { align: 'left' })
                    : editor.chain().focus().setTextAlign('left').run()}>
                <IconAlignLeft className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>
            <ToolbarButton title="Align center"
                active={inImage ? imgAlign === 'center' : textAlign === 'center'}
                onClick={() => inImage
                    ? editor.commands.updateAttributes('image', { align: 'center' })
                    : editor.chain().focus().setTextAlign('center').run()}>
                <IconAlignCenter className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>
            <ToolbarButton title="Align right"
                active={inImage ? imgAlign === 'right' : textAlign === 'right'}
                onClick={() => inImage
                    ? editor.commands.updateAttributes('image', { align: 'right' })
                    : editor.chain().focus().setTextAlign('right').run()}>
                <IconAlignRight className="h-3.5 w-3.5" stroke={1.5} />
            </ToolbarButton>

        </div>
    );
}
