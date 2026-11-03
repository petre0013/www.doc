import { useState, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { router } from '@inertiajs/react';
import { IconX } from '@tabler/icons-react';
import { Button } from '@/components/ui/button';
import { useScrollLock } from '@/hooks/useScrollLock';

/**
 * Create-or-rename a workspace group (BookStack-style shelf). Mirrors
 * WorkspaceFormModal, but a group carries a name only — it owns no content.
 *
 * Props:
 *   open    – controlled visibility
 *   onClose – called on cancel / success / backdrop click
 *   group   – when present, the dialog renames it (PATCH); otherwise creates (POST).
 */
export default function GroupFormModal({ open, onClose, group = null }) {
    const editing = Boolean(group);

    const [name, setName]   = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy]   = useState(false);

    useEffect(() => {
        if (open) {
            setName(group?.name ?? '');
            setError('');
        }
    }, [open, group]);

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
        setBusy(true);
        const opts = {
            preserveScroll: true,
            onSuccess: () => { setBusy(false); onClose(); },
            onError: (errs) => { setBusy(false); setError(errs.name ?? 'Something went wrong.'); },
        };

        if (editing) {
            router.patch(`/workspaces/groups/${group.id}`, { name: name.trim() }, opts);
        } else {
            router.post('/workspaces/groups', { name: name.trim() }, opts);
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
                        {editing ? 'Rename group' : 'New group'}
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
                        <label htmlFor="group-name" className="mb-1.5 block text-xs font-medium text-foreground">
                            Name
                        </label>
                        <input
                            id="group-name"
                            autoFocus
                            type="text"
                            value={name}
                            onChange={(e) => { setName(e.target.value); setError(''); }}
                            placeholder="e.g. Security"
                            className="h-9 w-full rounded-sm border border-border bg-canvas px-3 text-sm text-foreground placeholder:text-text-tertiary outline-none transition-[border-color,box-shadow] duration-150 focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                        />
                        {error && <p className="mt-1.5 text-xs text-danger">{error}</p>}
                        <p className="mt-2 text-xs text-text-tertiary">
                            Groups gather related workspaces in the list. They hold no content of their own.
                        </p>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-border-subtle bg-canvas px-5 py-3.5">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={busy || !name.trim()}>
                            {busy
                                ? (editing ? 'Saving…' : 'Creating…')
                                : (editing ? 'Save changes' : 'Create group')}
                        </Button>
                    </div>
                </form>
            </div>
        </div>,
        document.body
    );
}
