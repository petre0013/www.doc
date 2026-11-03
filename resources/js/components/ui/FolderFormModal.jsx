import { useState, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { router } from '@inertiajs/react';
import { IconX } from '@tabler/icons-react';
import { Button } from '@/components/ui/button';
import { useScrollLock } from '@/hooks/useScrollLock';

/**
 * Create-or-rename a page folder. Mirrors GroupFormModal — a folder carries a
 * name only. It is NOT a page: no body, no slug, no URL, and it can never be
 * opened, which is exactly why it needs its own dialog rather than reusing the
 * page one.
 *
 * Props:
 *   open        – controlled visibility
 *   onClose     – called on cancel / success / backdrop click
 *   folder      – when present, the dialog renames it (PATCH); otherwise creates (POST)
 *   workspaceId – required to create (a folder always belongs to one workspace)
 *   onCreate    – when given, a NEW folder is handed back by name instead of POSTed
 *                 (Edit mode defers the create to its "Done" save); rename is
 *                 unaffected. Omit it and creation POSTs immediately, as before.
 */
export default function FolderFormModal({ open, onClose, folder = null, workspaceId, onCreate }) {
    const editing = Boolean(folder);

    const [name, setName]   = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy]   = useState(false);

    useEffect(() => {
        if (open) {
            setName(folder?.name ?? '');
            setError('');
        }
    }, [open, folder]);

    useScrollLock(open);

    useEffect(() => {
        if (!open) return;
        const handler = (e) => { if (e.key === 'Escape') onClose(); };
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, [open, onClose]);

    if (!open) return null;

    function handleSubmit(e) {
        e.preventDefault();
        if (!name.trim()) { setError('Name is required.'); return; }

        // Deferred create (Edit mode): hand the name to the caller, no request.
        if (!editing && onCreate) {
            onCreate(name.trim());
            onClose();
            return;
        }

        setBusy(true);
        const opts = {
            preserveScroll: true,
            onSuccess: () => { setBusy(false); onClose(); },
            onError: (errs) => { setBusy(false); setError(errs.name ?? 'Something went wrong.'); },
        };

        if (editing) {
            router.patch(`/folders/${folder.id}`, { name: name.trim() }, opts);
        } else {
            router.post(`/workspaces/${workspaceId}/folders`, { name: name.trim() }, opts);
        }
    }

    return createPortal(
        <div
            className="fixed inset-0 z-50 flex items-center justify-center p-6"
            style={{ background: 'rgba(31, 37, 32, 0.42)' }}
            onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}
        >
            <div
                className="w-full max-w-md overflow-hidden rounded-[14px] bg-surface"
                style={{ boxShadow: 'var(--shadow-lg)' }}
            >
                <div className="flex items-center justify-between border-b border-border-subtle px-5 py-4">
                    <h2 className="text-[15px] font-medium text-foreground">
                        {editing ? 'Rename folder' : 'New folder'}
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="flex h-7 w-7 items-center justify-center rounded-sm text-text-tertiary transition-colors hover:bg-surface-hover hover:text-foreground"
                    >
                        <IconX className="h-4 w-4" stroke={1.5} />
                    </button>
                </div>

                <form onSubmit={handleSubmit}>
                    <div className="px-5 py-5">
                        <label htmlFor="folder-name" className="mb-1.5 block text-xs font-medium text-foreground">
                            Name
                        </label>
                        <input
                            id="folder-name"
                            autoFocus
                            type="text"
                            value={name}
                            onChange={(e) => { setName(e.target.value); setError(''); }}
                            placeholder="e.g. BitLocker Keys"
                            className="h-9 w-full rounded-sm border border-border bg-canvas px-3 text-sm text-foreground placeholder:text-text-tertiary outline-none transition-[border-color,box-shadow] duration-150 focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                        />
                        {error && <p className="mt-1.5 text-xs text-danger">{error}</p>}
                        <p className="mt-2 text-xs text-text-tertiary">
                            Folders gather top-level pages. A folder holds no content and has no page of its own —
                            move pages in from their ⋯ menu.
                        </p>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-border-subtle bg-canvas px-5 py-3.5">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={busy || !name.trim()}>
                            {busy
                                ? (editing ? 'Saving…' : 'Creating…')
                                : (editing ? 'Save changes' : 'Create folder')}
                        </Button>
                    </div>
                </form>
            </div>
        </div>,
        document.body
    );
}
