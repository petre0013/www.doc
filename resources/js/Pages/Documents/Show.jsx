import React, { useState, useCallback, useRef, useEffect, useMemo } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import {
    IconChevronRight, IconTrash, IconPencil, IconX, IconDeviceFloppy,
    IconUser, IconTag, IconCircleCheck, IconClock,
    IconDownload, IconLoader2, IconHistory, IconFileText, IconPlus, IconCalendar, IconLink,
    IconFolderSymlink, IconTemplate, IconDots, IconStar, IconStarFilled, IconFolder,
} from '@tabler/icons-react';
import DocsLayout from '@/Layouts/DocsLayout';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription,
} from '@/components/ui/dialog';
import {
    DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import TipTapEditor from '@/components/editor/TipTapEditor';
import NewPageModal from '@/components/ui/NewPageModal';
import AttachmentsPanel from '@/components/AttachmentsPanel';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import ConflictDialog from '@/components/documents/ConflictDialog';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { can } from '@/lib/permissions';
import { formatDate, timeAgo } from '@/lib/date';
import { csrfToken } from '@/lib/utils';

function isDocEmpty(content) {
    const nodes = content?.content ?? [];
    if (nodes.length === 0) return true;
    if (nodes.length === 1) {
        const n = nodes[0];
        return n.type === 'paragraph' && (!n.content || n.content.length === 0);
    }
    return false;
}

function fmtDate(iso) {
    if (!iso) return null;
    return formatDate(iso);
}

function MetaItem({ icon: Icon, children }) {
    return (
        <span className="flex items-center gap-1.5">
            <Icon className="h-3.5 w-3.5 shrink-0 text-text-tertiary" stroke={1.5} />
            {children}
        </span>
    );
}

function PageMeta({ document, versionsCount }) {
    return (
        <div className="mt-6 flex flex-wrap items-center gap-x-5 gap-y-1.5 border-t border-border-subtle pt-4 text-xs text-text-tertiary">
            <MetaItem icon={IconCalendar}>
                Created {fmtDate(document.created_at)}
                {document.creator && (
                    <> by <span className="text-text-secondary">{document.creator.name}</span></>
                )}
            </MetaItem>
            <MetaItem icon={IconClock}>
                Edited {timeAgo(document.updated_at)}
                {document.updater && (
                    <> by <span className="text-text-secondary">{document.updater.name}</span></>
                )}
            </MetaItem>
            <Link
                href={`/documents/${document.id}/versions`}
                title="View version history"
                className="flex items-center gap-1.5 text-accent-600 underline decoration-dotted underline-offset-2 transition-colors hover:text-accent-700"
            >
                <IconHistory className="h-3.5 w-3.5 shrink-0" stroke={1.5} />
                <span>{versionsCount} {versionsCount === 1 ? 'version' : 'versions'} · View history</span>
            </Link>
        </div>
    );
}

// Give up polling a conversion job after ~2 minutes: a healthy export takes
// seconds, so by then the queue worker is almost certainly not running and an
// explanation beats an infinite spinner.
const EXPORT_POLL_MS = 1500;
const EXPORT_POLL_LIMIT = 80;
const WORKER_HINT = 'The export is taking unusually long — the background worker may not be running. Ask your administrator to check it, then retry.';

function ExportModal({ documentId, open, onClose }) {
    const [format, setFormat]   = useState('pdf');
    const [state, setState]     = useState('idle'); // idle | pending | done | failed
    const [error, setError]     = useState(null);
    const [downloaded, setDownloaded] = useState(false);
    const pollRef               = useRef(null);
    const jobIdRef              = useRef(null);

    function reset() {
        clearInterval(pollRef.current);
        setState('idle');
        setError(null);
        setDownloaded(false);
        jobIdRef.current = null;
    }

    function handleClose() {
        reset();
        onClose();
    }

    async function startExport() {
        setState('pending');
        setError(null);

        try {
            const res = await fetch(`/documents/${documentId}/exports`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: JSON.stringify({ format }),
            });

            if (!res.ok) throw new Error('Failed to start export');
            const { id } = await res.json();
            jobIdRef.current = id;

            let polls = 0;
            pollRef.current = setInterval(async () => {
                if (++polls > EXPORT_POLL_LIMIT) {
                    clearInterval(pollRef.current);
                    setState('failed');
                    setError(WORKER_HINT);
                    return;
                }
                try {
                    const poll = await fetch(`/documents/${documentId}/exports/${id}`, {
                        headers: { 'Accept': 'application/json' },
                    });
                    const data = await poll.json();

                    if (data.status === 'done') {
                        clearInterval(pollRef.current);
                        setState('done');
                    } else if (data.status === 'failed') {
                        clearInterval(pollRef.current);
                        setState('failed');
                        setError(data.error ?? 'Export failed.');
                    }
                } catch {
                    // keep polling
                }
            }, EXPORT_POLL_MS);
        } catch (e) {
            setState('failed');
            setError(e.message);
        }
    }

    function triggerDownload() {
        setDownloaded(true);
        window.location.href = `/documents/${documentId}/exports/${jobIdRef.current}?download=1`;
    }

    useEffect(() => () => clearInterval(pollRef.current), []);

    const formats = [
        { value: 'pdf',  label: 'PDF',  description: 'Print-ready with headers & page numbers' },
        { value: 'docx', label: 'DOCX', description: 'Microsoft Word — preserves all formatting' },
    ];

    return (
        <Dialog open={open} onOpenChange={(v) => !v && handleClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>Export page</DialogTitle>
                    <DialogDescription>Choose a format to download this page.</DialogDescription>
                </DialogHeader>

                {/* Format selector */}
                {state === 'idle' && (
                    <div className="space-y-3 py-2">
                        {formats.map((f) => (
                            <button
                                key={f.value}
                                type="button"
                                onClick={() => setFormat(f.value)}
                                className={`w-full rounded-sm border px-4 py-3 text-left transition-all ${
                                    format === f.value
                                        ? 'border-accent-400 bg-accent-50 ring-[3px] ring-accent-200/60'
                                        : 'border-border bg-surface hover:bg-surface-hover'
                                }`}
                            >
                                <p className="text-sm font-semibold text-text-primary">{f.label}</p>
                                <p className="mt-0.5 text-xs text-text-secondary">{f.description}</p>
                            </button>
                        ))}

                        <div className="flex justify-end gap-2 pt-2">
                            <Button variant="outline" onClick={handleClose}>Cancel</Button>
                            <Button
                                className="bg-accent-400 hover:bg-accent-500 text-text-inverse"
                                onClick={startExport}
                            >
                                <IconDownload stroke={1.5} />
                                Export
                            </Button>
                        </div>
                    </div>
                )}

                {/* Pending */}
                {state === 'pending' && (
                    <div className="flex flex-col items-center gap-3 py-8 text-text-secondary">
                        <IconLoader2 className="h-8 w-8 animate-spin text-accent-600" stroke={1.5} />
                        <p className="text-sm">Generating {format.toUpperCase()}…</p>
                    </div>
                )}

                {/* Done */}
                {state === 'done' && (
                    <div className="flex flex-col items-center gap-4 py-6">
                        <IconCircleCheck className="h-10 w-10 text-accent-600" stroke={1.5} />
                        <p className="text-sm font-medium text-text-primary">Your file is ready!</p>
                        <div className="flex gap-2">
                            <Button variant="outline" onClick={handleClose}>Close</Button>
                            <Button
                                className="bg-accent-400 hover:bg-accent-500 text-text-inverse disabled:opacity-50"
                                onClick={triggerDownload}
                                disabled={downloaded}
                            >
                                <IconDownload stroke={1.5} />
                                {downloaded ? 'Downloaded' : `Download ${format.toUpperCase()}`}
                            </Button>
                        </div>
                    </div>
                )}

                {/* Failed */}
                {state === 'failed' && (
                    <div className="flex flex-col items-center gap-4 py-6">
                        <p className="text-sm font-medium text-danger">Export failed</p>
                        {error && <p className="text-xs text-text-secondary">{error}</p>}
                        <div className="flex gap-2">
                            <Button variant="outline" onClick={handleClose}>Close</Button>
                            <Button onClick={() => { reset(); startExport(); }}>Retry</Button>
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

/** "Save as template": snapshot this page's current content as a reusable template. */
function SaveAsTemplateModal({ open, onClose, documentId, documentTitle }) {
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (open) { setName(documentTitle); setDescription(''); setError(''); setSaving(false); }
    }, [open, documentTitle]);

    function submit(e) {
        e.preventDefault();
        if (!name.trim()) { setError('Name is required.'); return; }
        setSaving(true);
        router.post(`/documents/${documentId}/template`, {
            name: name.trim(),
            description: description.trim() || null,
        }, {
            preserveScroll: true,
            // Stay on the page — the success toast confirms it, and navigating
            // away would remount the layout and kill that toast mid-display.
            onSuccess: () => onClose(),
            onError: (errs) => setError(errs.name ?? 'Something went wrong.'),
            onFinish: () => setSaving(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>Save as template</DialogTitle>
                    <DialogDescription>
                        The page's current content becomes a reusable starting point in the New page dialog.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-3 py-2">
                    <div>
                        <label className="mb-1.5 block text-xs font-medium text-foreground">Name</label>
                        <Input
                            autoFocus
                            value={name}
                            onChange={(e) => { setName(e.target.value); setError(''); }}
                            placeholder="e.g. Runbook"
                        />
                        {error && <p className="mt-1 text-xs text-danger">{error}</p>}
                    </div>
                    <div>
                        <label className="mb-1.5 block text-xs font-medium text-foreground">
                            Description <span className="font-normal text-text-tertiary">(optional)</span>
                        </label>
                        <Input
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            placeholder="Shown under the name in the picker"
                        />
                    </div>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                        <Button
                            type="submit"
                            disabled={saving || !name.trim()}
                            className="bg-accent-400 hover:bg-accent-500 text-text-inverse"
                        >
                            <IconTemplate stroke={1.5} />
                            {saving ? 'Saving…' : 'Save template'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function MoveModal({ open, onClose, documentId, workspaces, currentWorkspaceId }) {
    const targets = workspaces.filter((w) => w.id !== currentWorkspaceId);
    const [target, setTarget] = useState('');
    const [moving, setMoving] = useState(false);

    useEffect(() => { if (open) { setTarget(''); setMoving(false); } }, [open]);

    function submit() {
        if (!target) return;
        setMoving(true);
        router.patch(`/documents/${documentId}/move`, { workspace_id: Number(target), parent_id: null }, {
            preserveScroll: true,
            onFinish: () => { setMoving(false); onClose(); },
        });
    }

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>Move page</DialogTitle>
                    <DialogDescription>
                        Move this page and its subpages to another workspace. It's placed at
                        the top level there — you can re-nest it afterwards.
                    </DialogDescription>
                </DialogHeader>
                <div className="py-2">
                    <label htmlFor="move-target" className="mb-1.5 block text-sm font-medium text-foreground">
                        Destination workspace
                    </label>
                    <select
                        id="move-target"
                        value={target}
                        onChange={(e) => setTarget(e.target.value)}
                        className="ui-select h-9 w-full rounded-sm border border-border bg-surface px-2.5 text-sm text-foreground outline-none transition-[border-color,box-shadow] duration-150 focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                    >
                        <option value="" disabled>Select a workspace…</option>
                        {targets.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                    </select>
                </div>
                <div className="flex justify-end gap-2 pt-2">
                    <Button variant="outline" onClick={onClose}>Cancel</Button>
                    <Button onClick={submit} disabled={!target || moving}>
                        {moving && <IconLoader2 className="h-3.5 w-3.5 animate-spin" stroke={1.5} />}
                        Move page
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function formatContext(text) {
    if (!text) return null;
    return text.split(/(\[\[.*?\]\])/g).map((part, index) => {
        if (part.startsWith('[[') && part.endsWith(']]')) {
            const innerText = part.slice(2, -2);
            return (
                <span key={index} className="mx-0.5 inline-block rounded-[3px] bg-accent-50 px-1 font-medium text-accent-600 underline decoration-accent-300 underline-offset-2">
                    {innerText}
                </span>
            );
        }
        return <span key={index}>{part}</span>;
    });
}

function BacklinksPanel({ backlinks }) {
    return (
        <section className="mt-8">
            <h2 className="mb-3 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">
                <IconLink className="h-3.5 w-3.5" stroke={1.5} />
                Referenced by ({backlinks.length})
            </h2>
            <div className="overflow-hidden rounded-md border border-border bg-card">
                {backlinks.map((link, idx) => (
                    <Link
                        key={link.id}
                        href={`/documents/${link.id}`}
                        className={`block px-4 py-3 transition-colors hover:bg-surface-hover${idx > 0 ? ' border-t border-border-subtle' : ''}`}
                    >
                        <div className="flex items-center gap-2">
                            <IconFileText className="h-4 w-4 shrink-0 text-text-tertiary" stroke={1.5} />
                            <span className="truncate text-sm font-medium text-foreground" title={link.title}>{link.title}</span>
                        </div>
                        {link.context && (
                            <p className="mt-1 truncate pl-6 text-xs text-text-tertiary" title={link.context}>
                                {formatContext(link.context)}
                            </p>
                        )}
                    </Link>
                ))}
            </div>
        </section>
    );
}

/**
 * "Contents" index — the folder view. A page that has child pages (or that has
 * no body of its own) reads as a folder: opening it lists its children here
 * instead of a blank editor. A child that itself has children shows a folder
 * icon. Derived, soft — the same page can also carry an intro body above this.
 */
function ContentsIndex({ pages, canCreate, onAddPage, hasIntro }) {
    return (
        <section className={hasIntro ? 'mt-6' : ''}>
            <div className="mb-3 flex items-center justify-between gap-3">
                <h2 className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">
                    <IconFolder className="h-3.5 w-3.5" stroke={1.5} />
                    Contents ({pages.length})
                </h2>
                {canCreate && (
                    <Button type="button" size="xs" variant="outline" onClick={onAddPage}>
                        <IconPlus stroke={1.5} />
                        New page
                    </Button>
                )}
            </div>
            <div className="overflow-hidden rounded-md border border-border bg-card">
                {pages.map((page, idx) => {
                    const isFolder = (page.children_count ?? 0) > 0;
                    return (
                        <Link
                            key={page.id}
                            href={`/documents/${page.id}`}
                            className={`group flex items-center gap-3 px-4 py-3 transition-colors hover:bg-surface-hover${idx > 0 ? ' border-t border-border-subtle' : ''}`}
                        >
                            {isFolder
                                ? <IconFolder className="h-4 w-4 shrink-0 text-text-tertiary transition-colors group-hover:text-accent-600" stroke={1.5} />
                                : <IconFileText className="h-4 w-4 shrink-0 text-text-tertiary transition-colors group-hover:text-accent-600" stroke={1.5} />}
                            <span className="min-w-0 flex-1 truncate text-sm font-medium text-foreground transition-colors group-hover:text-accent-600" title={page.title}>
                                {page.title}
                            </span>
                            {isFolder && (
                                <span className="shrink-0 text-xs text-text-tertiary">
                                    {page.children_count} {page.children_count === 1 ? 'page' : 'pages'}
                                </span>
                            )}
                            <span className="shrink-0 text-xs text-text-tertiary">{timeAgo(page.updated_at) ?? '—'}</span>
                            <IconChevronRight className="h-3.5 w-3.5 shrink-0 text-text-tertiary opacity-0 transition-opacity group-hover:opacity-100" stroke={1.5} />
                        </Link>
                    );
                })}
            </div>
        </section>
    );
}

export default function DocumentShow({ document, isStarred = false, versionsCount, children: childPages = [], breadcrumbs = [], backlinks = [], allTags = [], allDocuments = [], workspaces = [], folders = [] }) {
    const { auth } = usePage().props;
    const perms = can(auth);
    const [isEditing, setIsEditing]       = useState(
        () => perms.update && new URLSearchParams(window.location.search).get('edit') === '1'
    );

    // `router.post`/`patch`/etc. default to `preserveState: true`, so a
    // same-component navigation (e.g. the "Create page" hover-card action
    // posting to /documents and redirecting to the new page's
    // `?edit=1` URL) reuses this mounted instance instead of remounting —
    // the `isEditing` initializer above only runs once, at first mount, so
    // it would otherwise stay stale. Re-derive it whenever the document
    // identity changes so that redirect target actually opens in edit mode.
    useEffect(() => {
        if (perms.update && new URLSearchParams(window.location.search).get('edit') === '1') {
            setIsEditing(true);
        }
    }, [document.id]);
    const [exportOpen, setExportOpen]     = useState(false);
    const [saveTemplateOpen, setSaveTemplateOpen] = useState(false);
    const [moveOpen, setMoveOpen]         = useState(false);
    const [newPageOpen, setNewPageOpen]   = useState(false);

    // Folder-view derivation (soft, no stored flag): a page with children — or
    // with no body of its own — reads as a folder and shows its Contents.
    const hasChildren = childPages.length > 0;
    const bodyEmpty = isDocEmpty(document.content);
    // Parent options for "New page inside this folder" — this workspace's pages.
    const parentOptions = useMemo(
        () => allDocuments
            .filter((d) => d.workspace_id === document.workspace_id)
            .map((d) => ({ id: d.id, label: d.title })),
        [allDocuments, document.workspace_id],
    );

    const [editTitle, setEditTitle] = useState(document.title);
    const [editTags, setEditTags] = useState(document.tags.map((t) => t.id));

    // Holds the latest JSON from the editor without triggering re-renders
    const editorContentRef = useRef(document.content);

    const [saveStatus, setSaveStatus] = useState(null); // null | 'saving' | 'saved'
    const [deleteOpen, setDeleteOpen]   = useState(false);
    // Fresh server state when an optimistic-locking save is rejected (someone else
    // edited this page since it loaded). Non-null opens the conflict dialog.
    const [conflict, setConflict]       = useState(null);
    const isDirtyRef = useRef(false);

    // Attachment changes are STAGED while editing — new files and ids marked for
    // removal — and only committed to the server when the page is saved (see
    // performSave). Cancel discards them; nothing was ever sent.
    const [pendingUploads, setPendingUploads]   = useState([]);
    const [pendingRemovals, setPendingRemovals] = useState([]);
    // Staged renames of EXISTING attachments: { [attachmentId]: newDisplayName }.
    // Pending (not-yet-uploaded) files are renamed in place on pendingUploads.
    const [pendingRenames, setPendingRenames]   = useState({});

    const [showNewTag, setShowNewTag]           = useState(false);
    const [newTagName, setNewTagName]           = useState('');
    const [newTagProcessing, setNewTagProcessing] = useState(false);
    const [newTagError, setNewTagError]         = useState('');

    // Build resolvedLinks map: { "Page Title": "/documents/id" }
    const resolvedLinks = Object.fromEntries(
        (document.outgoing_links ?? [])
            .filter((l) => l.target)
            .map((l) => [l.target_title, `/documents/${l.target.id}`])
    );

    // Warn before losing unsaved edits on close/refresh or an in-app navigation
    // (breadcrumb, version history, sidebar). In-page POSTs — saving, creating a
    // tag — are non-GET and pass through; see the discard modal below.
    const { promptOpen, requestLeave, confirmDiscard, dismissPrompt } = useUnsavedChangesGuard({
        active: isEditing,
        dirtyRef: isDirtyRef,
        revert: () => { setIsEditing(false); setSaveStatus(null); },
    });

    // Title/tag edits count as unsaved too (content edits flag the ref directly).
    useEffect(() => {
        if (!isEditing) return;
        const titleChanged = editTitle !== document.title;
        const tagsChanged = JSON.stringify([...editTags].sort()) !== JSON.stringify([...document.tags.map(t => t.id)].sort());
        if (titleChanged || tagsChanged) isDirtyRef.current = true;
    }, [editTitle, editTags, isEditing]);

    // Staged attachment changes are unsaved changes too — so the discard guard
    // prompts before leaving edit mode with files pending.
    useEffect(() => {
        if (!isEditing) return;
        if (pendingUploads.length > 0 || pendingRemovals.length > 0 || Object.keys(pendingRenames).length > 0) isDirtyRef.current = true;
    }, [pendingUploads, pendingRemovals, pendingRenames, isEditing]);

    // Reset form fields when entering/leaving edit mode
    useEffect(() => {
        // Always drop staged attachment changes on a mode toggle — a fresh edit
        // starts clean, and leaving (cancel/save) clears the staging area.
        setPendingUploads([]);
        setPendingRemovals([]);
        setPendingRenames({});
        if (isEditing) {
            setEditTitle(document.title);
            setEditTags(document.tags.map((t) => t.id));
            editorContentRef.current = document.content;
            setSaveStatus(null);
            isDirtyRef.current = false;
        } else {
            setShowNewTag(false);
            setNewTagName('');
            setNewTagError('');
        }
    }, [isEditing]);

    // --- Save helpers ---

    // Commit the staged attachment changes (removals, then uploads) to the server.
    // Each successful op is dropped from the pending set so a retry after a partial
    // failure resumes from where it stopped instead of repeating work. A 404 on a
    // removal means it's already gone — treat that as done.
    const commitAttachments = useCallback(async () => {
        let removals = [...pendingRemovals];
        let renames  = { ...pendingRenames };
        let uploads  = [...pendingUploads];
        try {
            for (const id of [...removals]) {
                const res = await fetch(`/documents/${document.id}/attachments/${id}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
                });
                if (!res.ok && res.status !== 404) throw new Error('remove');
                removals = removals.filter((x) => x !== id);
            }
            // Renames of surviving attachments (a removed id is dropped above, so a
            // stale rename of it is a harmless 404 we treat as done).
            for (const [id, name] of Object.entries(renames)) {
                const res = await fetch(`/documents/${document.id}/attachments/${id}`, {
                    method: 'PATCH',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name }),
                });
                if (!res.ok && res.status !== 404) throw new Error('rename');
                delete renames[id];
            }
            for (const item of [...uploads]) {
                const body = new FormData();
                body.append('file', item.file);
                body.append('name', item.name);
                const res = await fetch(`/documents/${document.id}/attachments`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
                    body,
                });
                if (!res.ok) throw new Error('upload');
                uploads = uploads.filter((x) => x !== item);
            }
        } finally {
            // Leave only the uncommitted changes staged.
            setPendingRemovals(removals);
            setPendingRenames(renames);
            setPendingUploads(uploads);
        }
    }, [document.id, pendingRemovals, pendingRenames, pendingUploads]);

    const performSave = useCallback(
        (content, { force = false } = {}) => {
            setSaveStatus('saving');
            (async () => {
                try {
                    await commitAttachments();
                } catch {
                    setSaveStatus(null);
                    toast.error("Couldn't save your attachments — your changes are still here, try again.");
                    return; // keep editing; the document save is skipped
                }
                router.patch(
                    `/documents/${document.id}`,
                    {
                        title: editTitle,
                        content,
                        tags: editTags,
                        // Optimistic-locking base: the version this editor loaded. A
                        // `force` retry (from the conflict dialog) overwrites regardless.
                        base_version: document.version,
                        ...(force ? { force: true } : {}),
                    },
                    {
                        // Keep the in-progress draft mounted when the server reports a
                        // conflict; otherwise reload fresh props as before.
                        preserveState: (page) => !!page.props.flash?.saveConflict,
                        preserveScroll: true,
                        onSuccess: (page) => {
                            const conflictData = page.props.flash?.saveConflict;
                            if (conflictData) {
                                setSaveStatus(null);
                                setConflict(conflictData);
                                return; // stay in edit mode; the dialog resolves it
                            }
                            isDirtyRef.current = false;
                            setSaveStatus('saved');
                            toast.success('Page saved.');
                            setIsEditing(false);
                        },
                        onError: () => {
                            setSaveStatus(null);
                            toast.error("Couldn't save your changes — they're still here, try again.");
                        },
                    }
                );
            })();
        },
        [document.id, document.version, editTitle, editTags, commitAttachments]
    );

    // Conflict resolution — overwrite the other edit with mine (force save), or
    // discard my draft and reload their current version.
    const overwriteWithMine = useCallback(() => {
        setConflict(null);
        performSave(editorContentRef.current, { force: true });
    }, [performSave]);

    const reloadTheirs = useCallback(() => {
        setConflict(null);
        isDirtyRef.current = false; // let the unsaved-changes guard pass
        router.get(`/documents/${document.id}`, {}, { preserveState: false, preserveScroll: false });
    }, [document.id]);

    const handleEditorUpdate = useCallback((json, userInitiated) => {
        editorContentRef.current = json;
        // Only a focused edit is a real change. Entering edit mode remounts the
        // editor (key change), and a page whose stored JSON isn't editor-normalized
        // (seeded/imported pages) emits a settling transaction on that mount while
        // unfocused — which must not trip the unsaved-changes guard on its own.
        if (userInitiated) isDirtyRef.current = true;
    }, []);

    function handleExplicitSave(e) {
        e.preventDefault();
        // A second click while the PATCH is in flight would reuse the same
        // base_version and bounce off optimistic locking as a self-conflict.
        if (saveStatus === 'saving') return;
        performSave(editorContentRef.current);
    }

    // --- Attachment staging handlers ---
    const addPendingUpload = useCallback((item) => setPendingUploads((prev) => [...prev, item]), []);
    const removeExistingAttachment = useCallback((id) => setPendingRemovals((prev) => prev.includes(id) ? prev : [...prev, id]), []);
    const undoRemoveAttachment = useCallback((id) => setPendingRemovals((prev) => prev.filter((x) => x !== id)), []);
    const removePendingUpload = useCallback((index) => setPendingUploads((prev) => prev.filter((_, i) => i !== index)), []);
    const renameExistingAttachment = useCallback((id, name) => setPendingRenames((prev) => ({ ...prev, [id]: name })), []);
    const renamePendingUpload = useCallback((index, name) => setPendingUploads((prev) => prev.map((it, i) => (i === index ? { ...it, name } : it))), []);

    function handleTagToggle(tagId) {
        setEditTags((prev) =>
            prev.includes(tagId) ? prev.filter((id) => id !== tagId) : [...prev, tagId]
        );
    }

    function submitNewTag(e) {
        e.preventDefault();
        const name = newTagName.trim();
        if (!name || newTagProcessing) return;
        setNewTagProcessing(true);
        setNewTagError('');
        router.post('/tags', { name }, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const created = (page.props.allTags ?? []).find(t => t.name === name);
                if (created) setEditTags(prev => prev.includes(created.id) ? prev : [...prev, created.id]);
                setNewTagName('');
                setShowNewTag(false);
                setNewTagProcessing(false);
            },
            onError: (errors) => {
                setNewTagError(errors.name ?? 'Failed to create tag.');
                setNewTagProcessing(false);
            },
        });
    }

    function destroyDocument() {
        setDeleteOpen(true);
    }

    function confirmDelete() {
        setDeleteOpen(false);
        router.delete(`/documents/${document.id}`);
    }

    return (
        <>
        <DocsLayout wideable>
            <Head title={document.title} />

            {/* Breadcrumb */}
            <nav className="flex flex-wrap items-center gap-1.5 text-sm text-text-secondary">
                <Link href="/workspaces" className="transition-colors hover:text-foreground">
                    Workspaces
                </Link>
                <IconChevronRight className="h-3.5 w-3.5 shrink-0 text-text-tertiary" stroke={1.5} />
                <Link
                    href={`/workspaces/${document.workspace.id}`}
                    className="transition-colors hover:text-foreground"
                >
                    {document.workspace.name}
                </Link>
                {breadcrumbs.map((anc) => (
                    <React.Fragment key={anc.id}>
                        <IconChevronRight className="h-3.5 w-3.5 shrink-0 text-text-tertiary" stroke={1.5} />
                        <Link
                            href={`/documents/${anc.id}`}
                            className="transition-colors hover:text-foreground"
                        >
                            {anc.title}
                        </Link>
                    </React.Fragment>
                ))}
                <IconChevronRight className="h-3.5 w-3.5 shrink-0 text-text-tertiary" stroke={1.5} />
                <span className="font-medium text-foreground">{document.title}</span>
            </nav>

            {/* Header */}
            <div className="mt-4 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                {isEditing ? (
                    <div className="flex-1">
                        <label htmlFor="edit-title" className="sr-only">Page title</label>
                        <input
                            id="edit-title"
                            type="text"
                            value={editTitle}
                            onChange={(e) => setEditTitle(e.target.value)}
                            placeholder="Page title"
                            className="w-full bg-transparent text-2xl font-semibold text-foreground outline-none placeholder:text-text-tertiary border-b border-border-subtle focus:border-accent-400 transition-colors duration-150"
                        />
                    </div>
                ) : (
                    <div>
                        <h1 className="text-2xl font-semibold text-foreground">
                            {document.title}
                        </h1>
                        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-text-tertiary">
                            <Link
                                href={`/documents/${document.id}/versions`}
                                title="View version history"
                                className="flex items-center gap-1 text-accent-600 underline decoration-dotted underline-offset-2 transition-colors hover:text-accent-700"
                            >
                                <IconHistory className="h-3.5 w-3.5" stroke={1.5} />
                                {versionsCount} {versionsCount === 1 ? 'version' : 'versions'}
                            </Link>
                            {document.updater && (
                                <>
                                    <span>·</span>
                                    <span className="flex items-center gap-1">
                                        <IconUser className="h-3.5 w-3.5" stroke={1.5} />
                                        {document.updater.name}
                                    </span>
                                </>
                            )}
                        </div>
                    </div>
                )}

                <div className="flex items-center gap-2 self-start">
                    {isEditing ? (
                        <>
                            {saveStatus === 'saving' && (
                                <span className="flex items-center gap-1 text-xs text-text-tertiary">
                                    <IconClock className="h-3.5 w-3.5 animate-pulse" stroke={1.5} />
                                    Saving…
                                </span>
                            )}
                            {saveStatus === 'saved' && (
                                <span className="flex items-center gap-1 text-xs text-accent-600">
                                    <IconCircleCheck className="h-3.5 w-3.5" stroke={1.5} />
                                    Saved
                                </span>
                            )}
                            <Button
                                onClick={handleExplicitSave}
                                disabled={saveStatus === 'saving'}
                                className="bg-accent-400 hover:bg-accent-500 text-text-inverse"
                            >
                                <IconDeviceFloppy stroke={1.5} />
                                Save
                            </Button>
                            <Button
                                variant="outline"
                                className="border-border hover:bg-surface-hover"
                                onClick={requestLeave}
                            >
                                <IconX stroke={1.5} />
                                Cancel
                            </Button>
                        </>
                    ) : (
                        <>
                            {/* Star is personal quick access — any role, not audited. */}
                            <button
                                type="button"
                                onClick={() => router.post(`/documents/${document.id}/star`, {}, { preserveScroll: true, preserveState: true })}
                                title={isStarred ? 'Unstar this page' : 'Star this page'}
                                aria-pressed={isStarred}
                                className={`flex h-8 w-8 items-center justify-center rounded-sm transition-colors hover:bg-surface-hover ${
                                    isStarred ? 'text-warning' : 'text-text-tertiary hover:text-foreground'
                                }`}
                            >
                                {isStarred
                                    ? <IconStarFilled className="h-4 w-4" />
                                    : <IconStar className="h-4 w-4" stroke={1.5} />}
                            </button>
                            {/* Edit is THE action on a page — it stays a button.
                                Everything occasional lives in the ⋯ menu so the
                                header doesn't stack five buttons. */}
                            {perms.update && (
                                <Button
                                    variant="outline"
                                    className="border-border hover:bg-surface-hover"
                                    onClick={() => setIsEditing(true)}
                                >
                                    <IconPencil stroke={1.5} />
                                    Edit
                                </Button>
                            )}
                            {(perms.create || perms.delete) && (
                                <DropdownMenu modal={false}>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="outline"
                                            className="border-border px-2 hover:bg-surface-hover"
                                            title="More actions"
                                            aria-label="More actions"
                                        >
                                            <IconDots stroke={1.5} />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" className="w-52">
                                        {perms.create && (
                                            <DropdownMenuItem onSelect={() => setExportOpen(true)}>
                                                <IconDownload stroke={1.5} />
                                                Export…
                                            </DropdownMenuItem>
                                        )}
                                        {perms.create && (
                                            <DropdownMenuItem onSelect={() => setSaveTemplateOpen(true)}>
                                                <IconTemplate stroke={1.5} />
                                                Save as template…
                                            </DropdownMenuItem>
                                        )}
                                        {perms.update && workspaces.length > 1 && (
                                            <DropdownMenuItem onSelect={() => setMoveOpen(true)}>
                                                <IconFolderSymlink stroke={1.5} />
                                                Move to workspace…
                                            </DropdownMenuItem>
                                        )}
                                        {perms.delete && (
                                            <>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    onSelect={destroyDocument}
                                                    className="text-danger focus:bg-danger-surface focus:text-danger"
                                                >
                                                    <IconTrash stroke={1.5} />
                                                    Move to Trash
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                        </>
                    )}
                </div>
            </div>

            {/* Tags — edit mode toggle */}
            {isEditing && (
                <div className="mt-4">
                    <p className="mb-2 text-xs font-medium text-text-secondary">Tags</p>
                    <div className="flex flex-wrap items-center gap-1.5">
                        {allTags.map((tag) => {
                            const selected = editTags.includes(tag.id);
                            return (
                                <button
                                    key={tag.id}
                                    type="button"
                                    onClick={() => handleTagToggle(tag.id)}
                                    className={`inline-flex cursor-pointer items-center rounded-sm border px-2.5 py-0.5 text-xs font-medium transition-all ${
                                        selected
                                            ? 'border-accent-200 bg-accent-100 text-accent-600'
                                            : 'border-border bg-surface text-text-secondary hover:bg-surface-hover'
                                    }`}
                                >
                                    <IconTag className="mr-1 h-3 w-3 opacity-60" stroke={1.5} />
                                    {tag.name}
                                </button>
                            );
                        })}

                        {showNewTag ? (
                            <form onSubmit={submitNewTag} className="flex items-center gap-1">
                                <input
                                    autoFocus
                                    type="text"
                                    value={newTagName}
                                    onChange={(e) => { setNewTagName(e.target.value); setNewTagError(''); }}
                                    onKeyDown={(e) => { if (e.key === 'Escape') { setShowNewTag(false); setNewTagName(''); setNewTagError(''); } }}
                                    placeholder="Tag name"
                                    className="h-[22px] w-28 rounded-sm border border-border bg-canvas px-2 text-xs text-foreground outline-none placeholder:text-text-tertiary focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                                />
                                <button
                                    type="submit"
                                    disabled={!newTagName.trim() || newTagProcessing}
                                    className="rounded-sm bg-accent-400 px-2 py-0.5 text-xs font-medium text-text-inverse hover:bg-accent-500 disabled:opacity-50"
                                >
                                    {newTagProcessing ? '…' : 'Add'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => { setShowNewTag(false); setNewTagName(''); setNewTagError(''); }}
                                    className="rounded-sm px-1.5 py-0.5 text-xs text-text-tertiary transition-colors hover:bg-surface-hover hover:text-foreground"
                                >
                                    Cancel
                                </button>
                            </form>
                        ) : (
                            <button
                                type="button"
                                onClick={() => setShowNewTag(true)}
                                className="inline-flex items-center gap-1 rounded-sm border border-dashed border-border px-2 py-0.5 text-xs text-text-tertiary transition-colors hover:border-accent-300 hover:text-accent-600"
                            >
                                <IconPlus className="h-3 w-3" stroke={1.5} />
                                New tag
                            </button>
                        )}
                    </div>
                    {newTagError && (
                        <p className="mt-1 text-[11px] text-danger">{newTagError}</p>
                    )}
                </div>
            )}

            {/* Tags — read mode */}
            {!isEditing && document.tags.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-1.5">
                    {document.tags.map((tag) => (
                        <Link
                            key={tag.id}
                            href={`/tags/${tag.id}`}
                            className="inline-flex items-center gap-1.5 rounded-md bg-accent-100 px-2 py-0.5 text-[11px] font-medium text-accent-600 transition-colors hover:bg-accent-200"
                        >
                            <IconTag className="h-3.5 w-3.5 shrink-0" stroke={1.5} />
                            {tag.name}
                        </Link>
                    ))}
                </div>
            )}

            {/* Attachments — files attached to this page, above the body for prominence */}
            <AttachmentsPanel
                documentId={document.id}
                attachments={document.attachments ?? []}
                editable={isEditing && perms.update}
                pendingUploads={pendingUploads}
                pendingRemovals={pendingRemovals}
                pendingRenames={pendingRenames}
                onAddUpload={addPendingUpload}
                onRemoveExisting={removeExistingAttachment}
                onUndoRemove={undoRemoveAttachment}
                onRemovePending={removePendingUpload}
                onRenameExisting={renameExistingAttachment}
                onRenamePending={renamePendingUpload}
            />

            {/* Content — full width. When not editing, an empty body with child
                pages reads as a folder: the Contents index stands in for the blank
                editor, and a body (if any) sits above it as an intro. */}
            <div className="mt-6">
                {isEditing ? (
                    <Card className="overflow-clip">
                        <TipTapEditor
                            key="edit"
                            content={document.content}
                            editable
                            suggestions={allDocuments}
                            resolvedLinks={resolvedLinks}
                            onUpdate={handleEditorUpdate}
                            canCreate={perms.create}
                            workspaceId={document.workspace_id}
                        />
                    </Card>
                ) : (
                    <>
                        {!bodyEmpty && (
                            <Card className="overflow-clip">
                                <TipTapEditor
                                    key="view"
                                    content={document.content}
                                    editable={false}
                                    suggestions={allDocuments}
                                    resolvedLinks={resolvedLinks}
                                    onUpdate={handleEditorUpdate}
                                    canCreate={perms.create}
                                    workspaceId={document.workspace_id}
                                />
                            </Card>
                        )}

                        {hasChildren ? (
                            <ContentsIndex
                                pages={childPages}
                                canCreate={perms.create}
                                onAddPage={() => setNewPageOpen(true)}
                                hasIntro={!bodyEmpty}
                            />
                        ) : bodyEmpty && (
                            <Card className="overflow-clip">
                                <div className="flex flex-col items-center gap-3 px-6 py-14 text-center">
                                    <div className="flex h-12 w-12 items-center justify-center rounded-xl border border-accent-200 bg-accent-50">
                                        <IconFolder className="h-6 w-6 text-accent-600" stroke={1.5} />
                                    </div>
                                    <div>
                                        <p className="text-sm font-medium text-foreground">This page is empty</p>
                                        <p className="mt-0.5 text-xs text-text-tertiary">
                                            Add a page inside it, or {perms.update ? 'use Edit above to' : 'ask an editor to'} write here.
                                        </p>
                                    </div>
                                    {perms.create && (
                                        <Button type="button" size="xs" className="mt-1" onClick={() => setNewPageOpen(true)}>
                                            <IconPlus stroke={1.5} />
                                            New page
                                        </Button>
                                    )}
                                </div>
                            </Card>
                        )}
                    </>
                )}
            </div>

            {/* Referenced by — pages that wiki-link here */}
            {!isEditing && backlinks.length > 0 && <BacklinksPanel backlinks={backlinks} />}

            {/* Page metadata strip */}
            <PageMeta document={document} versionsCount={versionsCount} />
            <ExportModal
                documentId={document.id}
                open={exportOpen}
                onClose={() => setExportOpen(false)}
            />
            <MoveModal
                open={moveOpen}
                onClose={() => setMoveOpen(false)}
                documentId={document.id}
                workspaces={workspaces}
                currentWorkspaceId={document.workspace.id}
            />
            <SaveAsTemplateModal
                open={saveTemplateOpen}
                onClose={() => setSaveTemplateOpen(false)}
                documentId={document.id}
                documentTitle={document.title}
            />
            <NewPageModal
                open={newPageOpen}
                onClose={() => setNewPageOpen(false)}
                workspaceId={document.workspace_id}
                initialParentId={document.id}
                parentOptions={parentOptions}
                folderOptions={folders}
            />
        </DocsLayout>

        <ConfirmDialog
            open={promptOpen}
            title="Discard changes?"
            message="You have unsaved changes. Leaving edit mode will discard them permanently."
            confirmLabel="Discard changes"
            cancelLabel="Keep editing"
            variant="danger"
            onConfirm={confirmDiscard}
            onCancel={dismissPrompt}
        />

        <ConflictDialog
            open={!!conflict}
            theirs={conflict}
            mine={editorContentRef.current}
            resolvedLinks={resolvedLinks}
            onReloadTheirs={reloadTheirs}
            onOverwrite={overwriteWithMine}
            onKeepEditing={() => setConflict(null)}
        />

        <ConfirmDialog
            open={deleteOpen}
            title={`Delete "${document.title}"?`}
            message="This page, along with any subpages, will be moved to Trash. You can restore it later from there."
            confirmLabel="Move to Trash"
            cancelLabel="Cancel"
            variant="danger"
            onConfirm={confirmDelete}
            onCancel={() => setDeleteOpen(false)}
        />
        </>
    );
}
