import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { IconPencil, IconPlus, IconTrash, IconTag } from '@tabler/icons-react';
import DocsLayout from '@/Layouts/DocsLayout';
import { Button } from '@/components/ui/button';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import { can } from '@/lib/permissions';

export default function TagsIndex({ tags }) {
    const { auth } = usePage().props;
    const perms = can(auth);
    const [showForm, setShowForm]       = useState(false);
    const [name, setName]               = useState('');
    const [error, setError]             = useState('');
    const [processing, setProcessing]   = useState(false);
    const [tagToDelete, setTagToDelete] = useState(null);
    const [deleting, setDeleting]       = useState(false);
    const [editingTag, setEditingTag]   = useState(null);
    const [editName, setEditName]       = useState('');
    const [editError, setEditError]     = useState('');
    const [renaming, setRenaming]       = useState(false);

    function submit(e) {
        e.preventDefault();
        if (!name.trim()) { setError('Name is required.'); return; }
        setProcessing(true);
        router.post('/tags', { name: name.trim() }, {
            onSuccess: () => { setName(''); setError(''); setProcessing(false); setShowForm(false); },
            onError: (errs) => { setError(errs.name ?? 'Something went wrong.'); setProcessing(false); },
        });
    }

    function startRename(tag) {
        setEditingTag(tag);
        setEditName(tag.name);
        setEditError('');
    }

    function submitRename(e) {
        e.preventDefault();
        if (!editName.trim()) { setEditError('Name is required.'); return; }
        setRenaming(true);
        router.patch(`/tags/${editingTag.id}`, { name: editName.trim() }, {
            onSuccess: () => { setEditingTag(null); setEditError(''); setRenaming(false); },
            onError: (errs) => { setEditError(errs.name ?? 'Something went wrong.'); setRenaming(false); },
        });
    }

    function confirmDelete() {
        setDeleting(true);
        router.delete(`/tags/${tagToDelete.id}`, {
            onFinish: () => { setDeleting(false); setTagToDelete(null); },
        });
    }

    return (
        <>
        <DocsLayout>
            <Head title="Tags" />

            <div className="flex min-w-0 items-start justify-between gap-4">
                <div className="min-w-0">
                    <h1 className="text-[19px] font-semibold text-foreground">Tags</h1>
                    <p className="mt-0.5 text-sm text-text-secondary">
                        {tags.length} {tags.length === 1 ? 'tag' : 'tags'} · Cross-cutting labels that span workspaces.
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-1.5 self-center">
                    {perms.create && (
                        <Button onClick={() => { setShowForm(true); }}>
                            <IconPlus stroke={1.5} />
                            New tag
                        </Button>
                    )}
                </div>
            </div>

            <div className="mt-4 overflow-hidden rounded-md border border-border bg-card">
                {/* Column headers */}
                {/* Cells pad themselves (not the container) so the grid tracks
                    span the same width as the data rows' and columns line up. */}
                <div className="grid grid-cols-[1fr_90px_76px] border-b border-border bg-surface-hover py-2.5">
                    <span className="pl-4 pr-4 text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">Tag</span>
                    <span className="pr-4 text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">Pages</span>
                    <span />
                </div>

                {/* Empty placeholder */}
                {tags.length === 0 && !showForm && (
                    <p className="px-4 py-8 text-center text-sm text-text-tertiary">No tags yet.</p>
                )}

                {/* Inline form — new tag (at the top) */}
                {showForm && (
                    <div className="border-b border-border bg-surface-hover/30 px-4 py-3">
                        <form onSubmit={submit} className="flex items-center gap-2">
                            <div className="flex-1">
                                <input
                                    autoFocus
                                    type="text"
                                    value={name}
                                    onChange={(e) => { setName(e.target.value); setError(''); }}
                                    placeholder="Tag name"
                                    className="h-[33px] w-full rounded-sm border border-border bg-canvas px-3 text-sm text-foreground placeholder:text-text-tertiary outline-none transition-[border-color,box-shadow] duration-150 focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                                />
                                {error && <p className="mt-1 text-xs text-danger">{error}</p>}
                            </div>
                            <button
                                type="submit"
                                disabled={processing || !name.trim()}
                                className="rounded-sm bg-primary px-3 py-1.5 text-xs font-medium text-text-inverse transition-opacity hover:opacity-90 disabled:opacity-50"
                            >
                                Create
                            </button>
                            <button
                                type="button"
                                onClick={() => { setShowForm(false); setName(''); setError(''); }}
                                className="rounded-sm px-2 py-1.5 text-xs text-text-secondary transition-colors hover:bg-surface-hover hover:text-foreground"
                            >
                                Cancel
                            </button>
                        </form>
                    </div>
                )}

                {/* Tag rows */}
                {tags.length > 0 && (
                    <ul>
                        {tags.map((tag) => (editingTag?.id === tag.id ? (
                            <li key={tag.id} className="border-b border-border-subtle bg-surface-hover/30 px-4 py-3 last:border-0">
                                <form onSubmit={submitRename} className="flex items-center gap-2">
                                    <div className="flex-1">
                                        <input
                                            autoFocus
                                            type="text"
                                            value={editName}
                                            onChange={(e) => { setEditName(e.target.value); setEditError(''); }}
                                            placeholder="Tag name"
                                            className="h-[33px] w-full rounded-sm border border-border bg-canvas px-3 text-sm text-foreground placeholder:text-text-tertiary outline-none transition-[border-color,box-shadow] duration-150 focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                                        />
                                        {editError && <p className="mt-1 text-xs text-danger">{editError}</p>}
                                    </div>
                                    <button
                                        type="submit"
                                        disabled={renaming || !editName.trim()}
                                        className="rounded-sm bg-primary px-3 py-1.5 text-xs font-medium text-text-inverse transition-opacity hover:opacity-90 disabled:opacity-50"
                                    >
                                        Save
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => { setEditingTag(null); setEditError(''); }}
                                        className="rounded-sm px-2 py-1.5 text-xs text-text-secondary transition-colors hover:bg-surface-hover hover:text-foreground"
                                    >
                                        Cancel
                                    </button>
                                </form>
                            </li>
                        ) : (
                            <li
                                key={tag.id}
                                className="group grid grid-cols-[1fr_90px_76px] items-center border-b border-border-subtle last:border-0 transition-colors hover:bg-surface-hover/60"
                            >
                                <Link
                                    href={`/tags/${tag.id}`}
                                    className="flex min-w-0 items-center gap-2 py-2.5 pl-4 pr-4"
                                >
                                    <span className="inline-flex items-center gap-1.5 rounded-md bg-accent-100 px-2 py-0.5 text-[11px] font-medium text-accent-600 transition-colors hover:bg-accent-200">
                                        <IconTag className="h-2.5 w-2.5 shrink-0" stroke={1.5} />
                                        {tag.name}
                                    </span>
                                </Link>
                                <div className="py-2.5 pr-4 text-xs text-text-tertiary">
                                    {tag.documents_count} {tag.documents_count === 1 ? 'page' : 'pages'}
                                </div>
                                <div className="flex items-center justify-end gap-0.5 py-1.5 pr-2">
                                    {perms.update && (
                                        <button
                                            type="button"
                                            onClick={() => startRename(tag)}
                                            title={`Rename ${tag.name}`}
                                            className="flex h-7 w-7 items-center justify-center rounded-sm text-text-tertiary opacity-0 transition-opacity group-hover:opacity-100 hover:bg-surface-hover hover:text-foreground"
                                        >
                                            <IconPencil className="h-4 w-4" stroke={1.5} />
                                        </button>
                                    )}
                                    {perms.delete && (
                                        <button
                                            type="button"
                                            onClick={() => setTagToDelete(tag)}
                                            title={`Delete ${tag.name}`}
                                            className="flex h-7 w-7 items-center justify-center rounded-sm text-text-tertiary opacity-0 transition-opacity group-hover:opacity-100 hover:bg-danger-surface hover:text-danger"
                                        >
                                            <IconTrash className="h-4 w-4" stroke={1.5} />
                                        </button>
                                    )}
                                </div>
                            </li>
                        )))}
                    </ul>
                )}
            </div>
        </DocsLayout>

        <ConfirmDialog
            open={tagToDelete !== null}
            busy={deleting}
            title={`Delete tag "${tagToDelete?.name}"?`}
            message={`This tag will be removed from all ${tagToDelete?.documents_count ?? 0} page${(tagToDelete?.documents_count ?? 0) !== 1 ? 's' : ''} that use it. This cannot be undone.`}
            confirmLabel="Delete tag"
            cancelLabel="Cancel"
            variant="danger"
            onConfirm={confirmDelete}
            onCancel={() => setTagToDelete(null)}
        />
        </>
    );
}
