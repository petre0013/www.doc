import { useState, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import {
    IconUpload, IconFileTypePdf, IconFileTypeDocx, IconLoader2, IconX, IconAlertCircle,
} from '@tabler/icons-react';
import { Button } from '@/components/ui/button';
import InlineEditableTitle from '@/components/ui/InlineEditableTitle';
import { csrfToken } from '@/lib/utils';
import { useScrollLock } from '@/hooks/useScrollLock';

// Batch guard: a migration drop of dozens is the target; hundreds is a mistake.
const MAX_FILES = 20;
// Files upload a few at a time so one big file doesn't serialize the batch.
const CONCURRENCY = 3;
// Give up polling a conversion after ~2 minutes — a healthy import takes
// seconds, so by then the queue worker is almost certainly not running.
const POLL_MS = 1500;
const POLL_LIMIT = 80;

let nextKey = 1;

function titleFromFilename(name) {
    const base = name.replace(/\.[^.]+$/, '').replace(/[-_]/g, ' ');
    return base.charAt(0).toUpperCase() + base.slice(1);
}

function formatSize(bytes) {
    return bytes >= 1024 * 1024
        ? `${(bytes / (1024 * 1024)).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** Status badge following the design-system chip recipe (success pinned green). */
function StatusBadge({ status }) {
    const map = {
        uploading:  { label: 'Uploading…',  cls: 'border-border bg-surface-hover text-text-secondary', spin: true },
        converting: { label: 'Converting…', cls: 'border-border bg-surface-hover text-text-secondary', spin: true },
        done:       { label: 'Imported',    cls: 'border-success-border bg-success-surface text-success-text' },
        failed:     { label: 'Failed',      cls: 'border-danger-border bg-danger-surface text-danger' },
        skipped:    { label: 'Skipped',     cls: 'border-border bg-surface-hover text-text-secondary' },
    };
    const badge = map[status];
    if (!badge) return null;
    return (
        <span className={`inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-medium ${badge.cls}`}>
            {badge.spin && <IconLoader2 className="h-3 w-3 animate-spin" stroke={1.5} aria-hidden="true" />}
            {badge.label}
        </span>
    );
}

/**
 * One staged/importing file: the page title it will become on top, the file it
 * came from underneath. Renaming reuses InlineEditableTitle — the same pencil
 * affordance that renames pages in the tree — so the list stays quiet text
 * until you ask to edit, instead of reading as a form of N inputs.
 * Skipped files never got a title, so they show their filename alone.
 */
function FileRow({ item, staging, onRename, onRemove }) {
    const Icon = item.name.toLowerCase().endsWith('.pdf') ? IconFileTypePdf : IconFileTypeDocx;
    const editable = staging && item.status === 'staged';

    return (
        <li className="flex items-center gap-2.5 px-3 py-2">
            <Icon className="h-4 w-4 shrink-0 text-text-tertiary" stroke={1.5} aria-hidden="true" />
            <span className="min-w-0 flex-1">
                {editable ? (
                    <InlineEditableTitle
                        value={item.title}
                        onCommit={onRename}
                        ariaLabel={`Rename ${item.name}`}
                        className="max-w-full text-[13px] font-medium text-foreground"
                        inputClassName="w-full text-[13px] font-medium"
                    />
                ) : (
                    <span className="block truncate text-[13px] font-medium text-foreground">{item.title || item.name}</span>
                )}
                <span className="mt-0.5 flex items-baseline gap-2 text-[11px] text-text-tertiary">
                    {Boolean(item.title) && <span className="truncate" title={item.name}>{item.name}</span>}
                    {item.size != null && <span className="shrink-0">{formatSize(item.size)}</span>}
                </span>
                {item.error && <span className="mt-0.5 block text-xs text-danger">{item.error}</span>}
            </span>
            {staging && item.status === 'staged' ? (
                <button
                    type="button"
                    onClick={onRemove}
                    aria-label={`Remove ${item.name}`}
                    className="flex h-6 w-6 shrink-0 items-center justify-center rounded-sm text-text-tertiary transition-colors hover:bg-surface-hover hover:text-foreground"
                >
                    <IconX className="h-3.5 w-3.5" stroke={1.5} />
                </button>
            ) : (
                <StatusBadge status={item.status} />
            )}
        </li>
    );
}

/**
 * Batch import dialog: stage N .docx/.pdf files, pick ONE destination, and
 * import them as flat sibling pages. Each file is its own POST to the existing
 * per-file endpoint (validation and conversion stay per-file), so one bad file
 * fails alone. Closing mid-batch is safe: files already sent keep converting
 * server-side; files not yet sent are cancelled.
 *
 * Props:
 *   open            – controlled visibility
 *   onClose({sent, pending}) – called on every close path; `sent` = files sent
 *                     to the server this run, `pending` = conversion job ids
 *                     still running (the parent keeps watching those)
 *   onSettled       – called each time a file finishes (done OR failed) while
 *                     the dialog is open, so the parent can refresh the tree
 *   workspaceId     – always required
 *   parentOptions   – [{ id, label }] pages the import can land under
 *   folderOptions   – [{ id, name }] folders the import can land in
 *   initialParentId – pre-select a page destination ("Import as subpage")
 *   initialFolderId – pre-select a folder destination ("Import into folder")
 *   initialFiles    – File[] staged on open (drop-anywhere hands files in here)
 *
 * Destination encoding matches NewPageModal: '' = top level, 'f:3' = folder 3,
 * '7' = subpage of page 7. A page is never both, so one select covers both.
 */
export default function ImportDialog({
    open, onClose, onSettled, workspaceId,
    parentOptions = [], folderOptions = [],
    initialParentId = '', initialFolderId = '', initialFiles = null,
}) {
    const [items, setItems] = useState([]);
    const [dest, setDest] = useState('');
    const [started, setStarted] = useState(false);
    const [notice, setNotice] = useState('');
    const [dragging, setDragging] = useState(false);
    const inputRef = useRef(null);

    // Async workers read fresh state through refs; bumping runRef cancels them.
    const itemsRef = useRef(items);
    itemsRef.current = items;
    const runRef = useRef(0);
    const destRef = useRef(dest);
    destRef.current = dest;
    const onSettledRef = useRef(onSettled);
    onSettledRef.current = onSettled;

    useEffect(() => {
        if (open) {
            setItems([]);
            setDest(initialFolderId ? `f:${initialFolderId}` : (initialParentId ? String(initialParentId) : ''));
            setStarted(false);
            setNotice('');
            setDragging(false);
            if (initialFiles?.length) addFiles(initialFiles, []);
        } else {
            runRef.current++; // cancel any not-yet-sent uploads
        }
    }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

    useScrollLock(open);

    const running = started && items.some((i) => ['staged', 'uploading', 'converting'].includes(i.status));
    const settled = started && !running;
    const stagedCount = items.filter((i) => i.status === 'staged').length;
    const doneCount = items.filter((i) => i.status === 'done').length;
    const failedCount = items.filter((i) => i.status === 'failed').length;
    const batchTotal = items.filter((i) => i.status !== 'skipped').length;

    function close() {
        onClose({
            // Only files that got a job id created a page (a rejected upload
            // creates nothing) — the parent refreshes the tree iff sent > 0.
            sent: items.filter((i) => i.jobId != null).length,
            pending: items.filter((i) => i.status === 'converting').map((i) => i.jobId),
        });
    }

    // Close on Escape (closing mid-batch is safe by design) — but never while a
    // field has focus: there, Escape belongs to whatever is being edited (a row
    // rename cancels, a native select closes its dropdown). This listener sits on
    // `document`, above the dialog, so it would otherwise fire on the way past.
    useEffect(() => {
        if (!open) return;
        const handler = (e) => {
            if (e.key !== 'Escape') return;
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target?.tagName)) return;
            close();
        };
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, [open, items]); // eslint-disable-line react-hooks/exhaustive-deps

    function patch(key, changes) {
        setItems((prev) => prev.map((i) => (i.key === key ? { ...i, ...changes } : i)));
    }

    function addFiles(fileList, current = itemsRef.current) {
        const additions = [];
        let staged = current.filter((i) => i.status === 'staged').length;
        let refused = 0;

        for (const file of Array.from(fileList)) {
            const ext = file.name.split('.').pop().toLowerCase();
            if (!['docx', 'pdf'].includes(ext)) {
                additions.push({ key: nextKey++, name: file.name, size: file.size, status: 'skipped', error: 'Only .docx and .pdf files can be imported.' });
                continue;
            }
            if (staged >= MAX_FILES) { refused++; continue; }
            staged++;
            // Prefilled from the filename, editable in the row until the batch
            // starts — same affordance the old single-file Import page had.
            additions.push({ key: nextKey++, file, name: file.name, size: file.size, title: titleFromFilename(file.name), status: 'staged', error: null });
        }

        setNotice(refused > 0 ? `Batches are limited to ${MAX_FILES} files — ${refused} ${refused === 1 ? 'file was' : 'files were'} not added.` : '');
        setItems([...current, ...additions]);
    }

    async function uploadOne(item) {
        patch(item.key, { status: 'uploading', sent: true });

        const form = new FormData();
        form.append('file', item.file);
        form.append('_token', csrfToken());
        // The row's title (renaming never leaves it blank). Sending none would
        // let the server read the file's own title, then the filename.
        const title = (item.title ?? '').trim();
        if (title) form.append('title', title);
        // 'f:3' files the page in folder 3; a bare id makes it a subpage.
        if (destRef.current.startsWith('f:')) form.append('folder_id', destRef.current.slice(2));
        else if (destRef.current) form.append('parent_id', destRef.current);

        try {
            const res = await fetch(`/workspaces/${workspaceId}/imports`, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: form,
            });
            if (!res.ok) {
                const body = await res.json().catch(() => ({}));
                const msg = Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? 'Upload failed.';
                patch(item.key, { status: 'failed', error: msg });
                return;
            }
            const { job_id, document_id } = await res.json();
            patch(item.key, { status: 'converting', jobId: job_id, docId: document_id, polls: 0 });
        } catch {
            patch(item.key, { status: 'failed', error: 'Network error — the file was not uploaded.' });
        }
    }

    function start() {
        setStarted(true);
        const run = ++runRef.current;
        const queue = itemsRef.current.filter((i) => i.status === 'staged');
        let idx = 0;

        const worker = async () => {
            while (runRef.current === run && idx < queue.length) {
                await uploadOne(queue[idx++]);
            }
        };
        for (let n = 0; n < Math.min(CONCURRENCY, queue.length); n++) worker();
    }

    // Poll every converting file until it settles (or the per-file limit trips).
    const convertingCount = items.filter((i) => i.status === 'converting').length;
    useEffect(() => {
        if (!open || convertingCount === 0) return;
        const timer = setInterval(() => {
            for (const item of itemsRef.current) {
                if (item.status !== 'converting') continue;
                if (item.polls >= POLL_LIMIT) {
                    patch(item.key, {
                        status: 'failed',
                        error: 'Taking unusually long — the background worker may not be running; the page may still appear later.',
                    });
                    continue;
                }
                patch(item.key, { polls: item.polls + 1 });
                fetch(`/imports/${item.jobId}`, { headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' } })
                    .then((res) => res.json())
                    .then((data) => {
                        if (data.status === 'done') patch(item.key, { status: 'done' });
                        else if (data.status === 'failed') patch(item.key, { status: 'failed', error: data.error ?? 'Import failed.' });
                        if (['done', 'failed'].includes(data.status)) onSettledRef.current?.();
                    })
                    .catch(() => { /* network hiccup — keep polling */ });
            }
        }, POLL_MS);
        return () => clearInterval(timer);
    }, [open, convertingCount]); // eslint-disable-line react-hooks/exhaustive-deps

    if (!open) return null;

    const hasPdf = items.some((i) => i.status === 'staged' && i.name.toLowerCase().endsWith('.pdf'));
    const selectedParent = parentOptions.find((o) => String(o.id) === dest);
    const selectedFolder = dest.startsWith('f:') ? folderOptions.find((f) => String(f.id) === dest.slice(2)) : null;

    return createPortal(
        <div
            className="fixed inset-0 z-50 flex items-center justify-center p-6"
            style={{ background: 'rgba(31, 37, 32, 0.42)' }}
            onMouseDown={(e) => { if (e.target === e.currentTarget && !running) close(); }}
        >
            <div
                className="flex w-full max-w-lg flex-col overflow-hidden rounded-[14px] bg-surface"
                style={{ boxShadow: 'var(--shadow-lg)', maxHeight: 'calc(100vh - 48px)' }}
            >
                {/* Header */}
                <div className="flex items-center justify-between border-b border-border-subtle px-5 py-4">
                    <h2 className="text-[15px] font-medium text-foreground">Import pages</h2>
                    <button
                        type="button"
                        onClick={close}
                        aria-label="Close"
                        className="flex h-7 w-7 items-center justify-center rounded-sm text-text-tertiary transition-colors hover:bg-surface-hover hover:text-foreground"
                    >
                        <IconX className="h-4 w-4" stroke={1.5} />
                    </button>
                </div>

                {/* Body */}
                <div className="ui-scroll min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5">
                    {!started && (
                        <div
                            onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
                            onDragLeave={() => setDragging(false)}
                            onDrop={(e) => { e.preventDefault(); setDragging(false); addFiles(e.dataTransfer.files); }}
                            onClick={() => inputRef.current?.click()}
                            className={[
                                'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-md border border-dashed px-6 py-7 transition-colors duration-150',
                                dragging ? 'border-accent-400 bg-accent-50' : 'border-border hover:border-accent-300 hover:bg-surface-hover',
                            ].join(' ')}
                        >
                            <input
                                ref={inputRef}
                                type="file"
                                accept=".docx,.pdf"
                                multiple
                                className="hidden"
                                onChange={(e) => { addFiles(e.target.files); e.target.value = ''; }}
                            />
                            <IconUpload className="h-6 w-6 text-text-tertiary" stroke={1.5} aria-hidden="true" />
                            <div className="text-center">
                                <p className="text-sm text-foreground">Drop <strong>.docx</strong> or <strong>.pdf</strong> files here</p>
                                <p className="mt-0.5 text-xs text-text-secondary">or click to browse — up to {MAX_FILES} files, 50 MB each</p>
                            </div>
                        </div>
                    )}

                    {notice && (
                        <div className="flex items-start gap-2 rounded-sm border border-warning-border bg-warning-surface px-3 py-2.5 text-sm text-warning-text">
                            <IconAlertCircle className="mt-0.5 h-4 w-4 shrink-0" stroke={1.5} aria-hidden="true" />
                            <span>{notice}</span>
                        </div>
                    )}

                    {items.length > 0 && (
                        <ul className="ui-scroll max-h-64 divide-y divide-border-subtle overflow-y-auto rounded-sm border border-border bg-canvas">
                            {items.map((item) => (
                                <FileRow
                                    key={item.key}
                                    item={item}
                                    staging={!started}
                                    onRename={(title) => patch(item.key, { title })}
                                    onRemove={() => setItems((prev) => prev.filter((i) => i.key !== item.key))}
                                />
                            ))}
                        </ul>
                    )}

                    {!started && (parentOptions.length > 0 || folderOptions.length > 0) && (
                        <div>
                            <label className="mb-1.5 block text-xs font-medium text-foreground">
                                Destination <span className="font-normal text-text-tertiary">(all files import here)</span>
                            </label>
                            <select
                                value={dest}
                                onChange={(e) => setDest(e.target.value)}
                                className="ui-select h-9 w-full rounded-sm border border-border bg-canvas px-3 text-sm text-foreground outline-none transition-[border-color,box-shadow] duration-150 focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                            >
                                <option value="">Top level (no parent)</option>
                                {folderOptions.length > 0 && (
                                    <optgroup label="Folders">
                                        {folderOptions.map((f) => (
                                            <option key={`f-${f.id}`} value={`f:${f.id}`}>{f.name}</option>
                                        ))}
                                    </optgroup>
                                )}
                                {parentOptions.length > 0 && (
                                    <optgroup label="Subpage of">
                                        {parentOptions.map((o) => (
                                            <option key={o.id} value={String(o.id)}>{o.label}</option>
                                        ))}
                                    </optgroup>
                                )}
                            </select>
                            {(selectedParent || selectedFolder) && (
                                <p className="mt-1 text-xs text-text-tertiary">
                                    Pages will be created {selectedFolder ? 'in' : 'under'}{' '}
                                    <span className="font-medium text-text-secondary">
                                        {(selectedFolder?.name ?? selectedParent.label).trim()}
                                    </span>
                                    {selectedFolder ? ' folder.' : '.'}
                                </p>
                            )}
                        </div>
                    )}

                    {!started && hasPdf && (
                        <div className="flex items-start gap-2 rounded-sm border border-warning-border bg-warning-surface px-3 py-2.5 text-sm text-warning-text">
                            <IconAlertCircle className="mt-0.5 h-4 w-4 shrink-0" stroke={1.5} aria-hidden="true" />
                            <span>PDF import extracts text only — formatting and images are not preserved.</span>
                        </div>
                    )}

                    {started && (
                        <p className="text-sm text-text-secondary" role="status">
                            {running
                                ? `Importing… ${doneCount + failedCount} of ${batchTotal} finished. You can close this — imports keep running in the background.`
                                : `${doneCount} ${doneCount === 1 ? 'page' : 'pages'} imported${failedCount > 0 ? `, ${failedCount} failed` : ''}.`}
                        </p>
                    )}
                </div>

                {/* Footer */}
                <div className="flex justify-end gap-2 border-t border-border-subtle bg-canvas px-5 py-3.5">
                    {!started ? (
                        <>
                            <Button type="button" variant="outline" onClick={close}>Cancel</Button>
                            <Button type="button" onClick={start} disabled={stagedCount === 0}>
                                <IconUpload className="h-4 w-4" stroke={1.5} />
                                Import {stagedCount > 0 ? `${stagedCount} ${stagedCount === 1 ? 'file' : 'files'}` : ''}
                            </Button>
                        </>
                    ) : (
                        <Button type="button" variant={settled ? 'default' : 'outline'} onClick={close}>
                            {settled ? 'Done' : 'Close'}
                        </Button>
                    )}
                </div>
            </div>
        </div>,
        document.body
    );
}
