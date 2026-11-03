import { useState, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { router } from '@inertiajs/react';
import { IconX } from '@tabler/icons-react';
import { Button } from '@/components/ui/button';
import { useScrollLock } from '@/hooks/useScrollLock';

/**
 * Create-or-edit workspace dialog, mirroring the NewPageModal pattern.
 *
 * Props:
 *   open      – controlled visibility
 *   onClose   – called on cancel / success / backdrop click
 *   workspace – when present, the dialog edits it (PATCH); otherwise it
 *               creates a new one (POST). Only name + description are editable.
 *   groups    – groups offered by the create form's picker. Empty (or no groups
 *               at all) simply hides it.
 *
 * The group picker is deliberately CREATE-ONLY. Re-grouping an existing
 * workspace is a structural move: it goes through "Move to group" → regroup(),
 * which saves withoutTimestamps and records `workspace.moved`. Editing group
 * here would route it through update() instead, bumping updated_at (making a
 * move look like an edit) and skipping the audit event.
 */
export default function WorkspaceFormModal({ open, onClose, workspace = null, groups = [] }) {
    const editing = Boolean(workspace);

    const [name, setName]               = useState('');
    const [description, setDescription] = useState('');
    const [groupId, setGroupId]         = useState('');
    const [error, setError]             = useState('');
    const [busy, setBusy]               = useState(false);

    // Seed fields from the edited workspace (or blank for create) each open.
    useEffect(() => {
        if (open) {
            setName(workspace?.name ?? '');
            setDescription(workspace?.description ?? '');
            setGroupId('');
            setError('');
        }
    }, [open, workspace]);

    // Lock body scroll while open.
    useScrollLock(open);

    // Close on Escape
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
        // Blank description sends null so an existing one can be cleared, not
        // just left unchanged.
        const payload = { name: name.trim(), description: description.trim() || null };
        const opts = {
            preserveScroll: true,
            onSuccess: () => { setBusy(false); onClose(); },
            onError: (errs) => { setBusy(false); setError(errs.name ?? 'Something went wrong.'); },
        };

        if (editing) {
            router.patch(`/workspaces/${workspace.id}`, payload, opts);
        } else {
            // Blank = ungrouped (top level), the default.
            router.post('/workspaces', { ...payload, group_id: groupId || null }, opts);
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
                {/* Header */}
                <div className="flex items-center justify-between border-b border-border-subtle px-5 py-4">
                    <h2 className="text-[15px] font-medium text-foreground">
                        {editing ? 'Edit workspace' : 'New workspace'}
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="flex h-7 w-7 items-center justify-center rounded-sm text-text-tertiary transition-colors hover:bg-surface-hover hover:text-foreground"
                    >
                        <IconX className="h-4 w-4" stroke={1.5} />
                    </button>
                </div>

                {/* Body */}
                <form onSubmit={handleSubmit}>
                    <div className="space-y-4 px-5 py-5">

                        {/* Name */}
                        <div>
                            <label htmlFor="workspace-name" className="mb-1.5 block text-xs font-medium text-foreground">
                                Name
                            </label>
                            <input
                                id="workspace-name"
                                autoFocus
                                type="text"
                                value={name}
                                onChange={(e) => { setName(e.target.value); setError(''); }}
                                placeholder="e.g. Network"
                                className="h-9 w-full rounded-sm border border-border bg-canvas px-3 text-sm text-foreground placeholder:text-text-tertiary outline-none transition-[border-color,box-shadow] duration-150 focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                            />
                            {error && <p className="mt-1.5 text-xs text-danger">{error}</p>}
                        </div>

                        {/* Description */}
                        <div>
                            <label htmlFor="workspace-description" className="mb-1.5 block text-xs font-medium text-foreground">
                                Description{' '}
                                <span className="font-normal text-text-tertiary">(optional)</span>
                            </label>
                            <input
                                id="workspace-description"
                                type="text"
                                value={description}
                                onChange={(e) => setDescription(e.target.value)}
                                placeholder="What lives in this workspace?"
                                className="h-9 w-full rounded-sm border border-border bg-canvas px-3 text-sm text-foreground placeholder:text-text-tertiary outline-none transition-[border-color,box-shadow] duration-150 focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                            />
                        </div>

                        {/* Group — create only (see the note on this component).
                            Hidden when no groups exist, so an instance that never
                            made one sees the dialog exactly as before. */}
                        {!editing && groups.length > 0 && (
                            <div>
                                <label htmlFor="workspace-group" className="mb-1.5 block text-xs font-medium text-foreground">
                                    Group{' '}
                                    <span className="font-normal text-text-tertiary">(optional)</span>
                                </label>
                                <select
                                    id="workspace-group"
                                    value={groupId}
                                    onChange={(e) => setGroupId(e.target.value)}
                                    className="ui-select h-9 w-full rounded-sm border border-border bg-canvas px-3 text-sm text-foreground outline-none transition-[border-color,box-shadow] duration-150 focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                                >
                                    <option value="">Ungrouped (top level)</option>
                                    {groups.map((g) => (
                                        <option key={g.id} value={g.id}>{g.name}</option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </div>

                    {/* Footer */}
                    <div className="flex justify-end gap-2 border-t border-border-subtle bg-canvas px-5 py-3.5">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={busy || !name.trim()}>
                            {busy
                                ? (editing ? 'Saving…' : 'Creating…')
                                : (editing ? 'Save changes' : 'Create workspace')}
                        </Button>
                    </div>
                </form>
            </div>
        </div>,
        document.body
    );
}
