import React, { useRef, useState, useEffect } from 'react';
import { toast } from 'sonner';
import {
    IconPaperclip, IconDownload, IconUpload, IconX, IconFile, IconPlus, IconPencil,
} from '@tabler/icons-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription,
} from '@/components/ui/dialog';

const MAX_BYTES = 25 * 1024 * 1024; // mirrors the server's 25 MB cap

function humanSize(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    const value = bytes / 1024 ** i;
    return `${value >= 10 || i === 0 ? Math.round(value) : value.toFixed(1)} ${units[i]}`;
}

// Split a filename into its editable base and its (fixed) extension, e.g.
// "report.final.pdf" → { base: "report.final", ext: ".pdf" }. A leading dot
// (dotfiles like ".env") is treated as having no extension.
function splitName(filename) {
    const dot = filename.lastIndexOf('.');
    return dot > 0
        ? { base: filename.slice(0, dot), ext: filename.slice(dot) }
        : { base: filename, ext: '' };
}

/**
 * Modal for renaming an already-chosen attachment (staged existing file or pending
 * upload). Only the base name is editable — the extension is pinned and shown as a
 * static chip, matching the add flow (the server re-pins it on existing files too).
 * Calls onSave with the full new name (base + original extension).
 */
function RenameAttachmentModal({ open, currentName, onClose, onSave }) {
    const [baseName, setBaseName] = useState('');
    const [ext, setExt] = useState('');

    // Seed the fields from the current name each time the modal opens.
    useEffect(() => {
        if (open) {
            const { base, ext: extension } = splitName(currentName ?? '');
            setBaseName(base);
            setExt(extension);
        }
    }, [open, currentName]);

    function confirm() {
        const trimmed = baseName.trim();
        if (!trimmed) return;
        onSave(trimmed + ext);
        onClose();
    }

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>Rename attachment</DialogTitle>
                    <DialogDescription>
                        Change the display name. The file type stays the same.
                    </DialogDescription>
                </DialogHeader>

                <div className="min-w-0 space-y-3 py-1">
                    <div>
                        <label htmlFor="rename-attachment-name" className="mb-1.5 block text-sm font-medium text-foreground">
                            Name
                        </label>
                        <div className="flex min-w-0 items-center gap-2">
                            <Input
                                id="rename-attachment-name"
                                value={baseName}
                                title={baseName}
                                autoFocus
                                onChange={(e) => setBaseName(e.target.value)}
                                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); confirm(); } }}
                                placeholder="Attachment name"
                                className="min-w-0 flex-1"
                            />
                            {ext && (
                                <span className="flex h-9 shrink-0 items-center rounded-sm border border-border bg-canvas px-3 text-sm text-text-tertiary">
                                    {ext}
                                </span>
                            )}
                        </div>
                    </div>
                    <div className="flex justify-end gap-2 pt-1">
                        <Button variant="outline" onClick={onClose}>Cancel</Button>
                        <Button
                            className="bg-accent-400 hover:bg-accent-500 text-text-inverse"
                            onClick={confirm}
                            disabled={!baseName.trim()}
                        >
                            Rename
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Files attached to a page. Controlled component: in edit mode the parent holds
 * the staged changes (new files + ids marked for removal) so nothing persists
 * until the page is saved — Cancel discards them, Save commits them. Read mode is
 * a plain download list (hidden when there are none).
 *
 * Each pending upload is a { file, name } pair — the name is the chosen display /
 * download filename, set in the add modal.
 */
export default function AttachmentsPanel({
    documentId,
    attachments = [],
    editable = false,
    pendingUploads = [],
    pendingRemovals = [],
    pendingRenames = {},
    onAddUpload,
    onRemoveExisting,
    onUndoRemove,
    onRemovePending,
    onRenameExisting,
    onRenamePending,
}) {
    // Hidden native file picker, opened by the "Add attachment" button / empty-state
    // browse area. Picked files stage exactly like dropped ones (name = filename,
    // renameable afterwards) — no intermediate modal.
    const fileInputRef = useRef(null);
    // What's being renamed, or null: { kind: 'existing'|'pending', id|index, currentName }.
    const [renameTarget, setRenameTarget] = useState(null);
    // True while a file drag hovers the panel. Tracked with a depth counter so
    // dragenter/dragleave on child rows don't flicker it off.
    const [dragOver, setDragOver] = useState(false);
    const dragDepth = useRef(0);

    // Only react to drags carrying files — not text/element drags (e.g. selecting
    // in the page, or dragging an editor node past the panel).
    const isFileDrag = (e) => Array.from(e.dataTransfer?.types ?? []).includes('Files');

    // Prefer the items API so dropped folders can be skipped — a folder shows up
    // as a zero-byte entry that would fail on upload. Fall back to .files where
    // the entry API isn't available.
    function collectFiles(dt) {
        const items = dt.items;
        if (items?.length && items[0].webkitGetAsEntry) {
            const files = [];
            for (const item of items) {
                if (item.webkitGetAsEntry?.()?.isDirectory) continue;
                const file = item.getAsFile?.();
                if (file) files.push(file);
            }
            return files;
        }
        return Array.from(dt.files ?? []);
    }

    // Stage each picked file as a pending upload, using its own name as the
    // display name (renameable afterwards). Shared by the drop zone and the browse
    // picker. Same 25 MB cap as the server.
    function stageFiles(files) {
        let staged = 0;
        for (const file of files) {
            if (file.size > MAX_BYTES) {
                toast.error(`"${file.name}" is larger than 25 MB.`);
                continue;
            }
            onAddUpload({ file, name: file.name });
            staged += 1;
        }
        if (staged > 0) toast.success(`${staged} file${staged === 1 ? '' : 's'} staged — save the page to attach.`);
    }

    // Panel-level drag handlers, only wired in edit mode. Scoped to the panel
    // (not the whole page) so it never competes with the editor's image drop.
    const dropHandlers = editable ? {
        onDragEnter: (e) => { if (!isFileDrag(e)) return; e.preventDefault(); dragDepth.current += 1; setDragOver(true); },
        onDragOver: (e) => { if (!isFileDrag(e)) return; e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; },
        onDragLeave: (e) => { if (!isFileDrag(e)) return; dragDepth.current -= 1; if (dragDepth.current <= 0) { dragDepth.current = 0; setDragOver(false); } },
        onDrop: (e) => { e.preventDefault(); dragDepth.current = 0; setDragOver(false); stageFiles(collectFiles(e.dataTransfer)); },
    } : {};

    // Read mode with nothing attached: render nothing at all.
    if (!editable && attachments.length === 0) return null;

    const liveCount = attachments.filter((a) => !pendingRemovals.includes(a.id)).length + pendingUploads.length;
    const hasRows = attachments.length > 0 || pendingUploads.length > 0;

    return (
        <section className="relative mt-6" {...dropHandlers}>
            {editable && dragOver && (
                <div className="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-md border-2 border-dashed border-accent-400 bg-accent-50/85">
                    <span className="flex items-center gap-2 text-sm font-medium text-accent-600">
                        <IconUpload className="h-5 w-5" stroke={1.5} />
                        Drop files to attach
                    </span>
                </div>
            )}
            <div className="mb-3 flex items-center justify-between gap-3">
                <h2 className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">
                    <IconPaperclip className="h-3.5 w-3.5" stroke={1.5} />
                    Attachments ({liveCount})
                </h2>
                {editable && (
                    <Button
                        variant="outline"
                        className="border-border hover:bg-surface-hover"
                        onClick={() => fileInputRef.current?.click()}
                    >
                        <IconPlus stroke={1.5} />
                        Add attachment
                    </Button>
                )}
            </div>

            {hasRows && (
                <div className="overflow-hidden rounded-md border border-border bg-card">
                    {attachments.map((att, idx) => {
                        const removing = pendingRemovals.includes(att.id);
                        const href = `/documents/${documentId}/attachments/${att.id}`;
                        // A staged rename overrides the stored name until saved.
                        const displayName = pendingRenames[att.id] ?? att.original_name;
                        return (
                            <div
                                key={att.id}
                                className={`flex items-center gap-3 px-4 py-2.5${idx > 0 ? ' border-t border-border-subtle' : ''}`}
                            >
                                <IconFile className={`h-4 w-4 shrink-0 text-text-tertiary${removing ? ' opacity-40' : ''}`} stroke={1.5} />
                                {removing ? (
                                    <>
                                        <span className="min-w-0 flex-1 truncate text-sm text-text-tertiary line-through" title={displayName}>{displayName}</span>
                                        <span className="shrink-0 text-xs text-text-tertiary">Will be removed</span>
                                        <button
                                            type="button"
                                            onClick={() => onUndoRemove(att.id)}
                                            className="shrink-0 rounded-sm px-1.5 py-0.5 text-xs font-medium text-accent-600 transition-colors hover:bg-surface-hover"
                                        >
                                            Undo
                                        </button>
                                    </>
                                ) : (
                                    <>
                                        <div
                                            className="flex min-w-0 flex-1 items-baseline gap-2"
                                        >
                                            <span className="min-w-0 flex-1 truncate text-sm font-medium text-foreground" title={displayName}>{displayName}</span>
                                            <span className="shrink-0 text-xs text-text-tertiary">{humanSize(att.size)}</span>
                                        </div>
                                        {editable && (
                                            <button
                                                type="button"
                                                onClick={() => setRenameTarget({ kind: 'existing', id: att.id, currentName: displayName })}
                                                title="Rename"
                                                className="shrink-0 rounded-sm p-1 text-text-tertiary transition-colors hover:bg-surface-hover hover:text-accent-600"
                                            >
                                                <IconPencil className="h-4 w-4" stroke={1.5} />
                                            </button>
                                        )}
                                        <a
                                            href={href}
                                            title="Download"
                                            className="shrink-0 rounded-sm p-1 text-text-tertiary transition-colors hover:bg-surface-hover hover:text-accent-600"
                                        >
                                            <IconDownload className="h-4 w-4" stroke={1.5} />
                                        </a>
                                        {editable && (
                                            <button
                                                type="button"
                                                onClick={() => onRemoveExisting(att.id)}
                                                title="Remove"
                                                className="shrink-0 rounded-sm p-1 text-text-tertiary transition-colors hover:bg-danger-surface hover:text-danger"
                                            >
                                                <IconX className="h-4 w-4" stroke={1.5} />
                                            </button>
                                        )}
                                    </>
                                )}
                            </div>
                        );
                    })}

                    {editable && pendingUploads.map((item, i) => (
                        <div
                            key={`pending-${i}`}
                            className={`flex items-center gap-3 px-4 py-2.5${attachments.length > 0 || i > 0 ? ' border-t border-border-subtle' : ''}`}
                        >
                            <IconFile className="h-4 w-4 shrink-0 text-text-tertiary" stroke={1.5} />
                            <span className="min-w-0 flex-1 truncate text-sm font-medium text-foreground" title={item.name}>{item.name}</span>
                            <span className="shrink-0 text-xs text-text-tertiary">{humanSize(item.file.size)}</span>
                            <span className="shrink-0 rounded-sm bg-accent-100 px-1.5 py-0.5 text-[10px] font-medium text-accent-600">New</span>
                            <button
                                type="button"
                                onClick={() => setRenameTarget({ kind: 'pending', index: i, currentName: item.name })}
                                title="Rename"
                                className="shrink-0 rounded-sm p-1 text-text-tertiary transition-colors hover:bg-surface-hover hover:text-accent-600"
                            >
                                <IconPencil className="h-4 w-4" stroke={1.5} />
                            </button>
                            <button
                                type="button"
                                onClick={() => onRemovePending(i)}
                                title="Remove"
                                className="shrink-0 rounded-sm p-1 text-text-tertiary transition-colors hover:bg-danger-surface hover:text-danger"
                            >
                                <IconX className="h-4 w-4" stroke={1.5} />
                            </button>
                        </div>
                    ))}
                </div>
            )}

            {editable && !hasRows && (
                <div
                    role="button"
                    tabIndex={0}
                    onClick={() => fileInputRef.current?.click()}
                    onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInputRef.current?.click(); } }}
                    className="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-md border border-dashed border-border px-4 py-8 text-center transition-colors hover:border-accent-300 hover:bg-surface-hover"
                >
                    <IconUpload className="h-5 w-5 text-text-tertiary" stroke={1.5} />
                    <p className="text-sm text-text-secondary">
                        <span className="font-medium text-accent-600">Drop files here or browse</span>
                    </p>
                    <p className="text-[11px] text-text-tertiary">Up to 25 MB each</p>
                </div>
            )}

            <input
                ref={fileInputRef}
                type="file"
                multiple
                className="hidden"
                onChange={(e) => { stageFiles(Array.from(e.target.files ?? [])); e.target.value = ''; }}
            />

            <RenameAttachmentModal
                open={renameTarget !== null}
                currentName={renameTarget?.currentName}
                onClose={() => setRenameTarget(null)}
                onSave={(name) => {
                    if (renameTarget?.kind === 'existing') onRenameExisting(renameTarget.id, name);
                    else if (renameTarget?.kind === 'pending') onRenamePending(renameTarget.index, name);
                }}
            />
        </section>
    );
}
