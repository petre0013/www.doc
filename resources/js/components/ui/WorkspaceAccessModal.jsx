import { useState, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { router } from '@inertiajs/react';
import { IconX } from '@tabler/icons-react';
import { Button } from '@/components/ui/button';
import { useScrollLock } from '@/hooks/useScrollLock';

/** Admin dialog: choose whether a workspace is restricted, and who its members are. */
export default function WorkspaceAccessModal({ open, onClose, workspace, access }) {
    const [restricted, setRestricted] = useState(false);
    const [members, setMembers] = useState([]);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (open) {
            setRestricted(workspace.restricted);
            setMembers(access.member_ids);
        }
    }, [open, workspace, access]);

    useScrollLock(open);

    useEffect(() => {
        if (!open) return;
        const handler = (e) => { if (e.key === 'Escape') onClose(); };
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, [open, onClose]);

    if (!open) return null;

    function toggle(id) {
        setMembers((current) => (current.includes(id) ? current.filter((m) => m !== id) : [...current, id]));
    }

    function submit(e) {
        e.preventDefault();
        setBusy(true);
        router.put(`/workspaces/${workspace.id}/access`, { restricted, member_ids: members }, {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => setBusy(false),
        });
    }

    return createPortal(
        <div
            className="fixed inset-0 z-50 flex items-center justify-center p-6"
            style={{ background: 'rgba(31, 37, 32, 0.42)' }}
            onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}
        >
            <form onSubmit={submit} className="w-full max-w-md overflow-hidden rounded-[14px] bg-surface" style={{ boxShadow: 'var(--shadow-lg)' }}>
                <div className="flex items-center justify-between border-b border-border-subtle px-5 py-4">
                    <h2 className="text-[15px] font-medium text-foreground">Workspace access</h2>
                    <button type="button" onClick={onClose} aria-label="Close" className="flex h-7 w-7 items-center justify-center rounded-sm text-text-tertiary transition-colors hover:bg-surface-hover hover:text-foreground">
                        <IconX className="h-4 w-4" stroke={1.5} />
                    </button>
                </div>

                <div className="space-y-4 px-5 py-4">
                    <label className="flex items-start gap-2.5 text-sm text-foreground">
                        <input type="checkbox" className="mt-0.5" checked={restricted} onChange={(e) => setRestricted(e.target.checked)} />
                        <span>
                            <span className="font-medium">Restrict this workspace</span>
                            <span className="mt-0.5 block text-xs text-text-secondary">
                                Only the members below and admins can find or open its pages. A member's role still decides whether they can edit.
                            </span>
                        </span>
                    </label>

                    {restricted && (
                        <div className="max-h-64 divide-y divide-border-subtle overflow-y-auto rounded-md border border-border">
                            {access.users.map((user) => (
                                <label key={user.id} className="flex items-center gap-2.5 px-3 py-2 text-sm text-foreground">
                                    <input type="checkbox" checked={members.includes(user.id)} onChange={() => toggle(user.id)} />
                                    <span className="min-w-0 truncate">{user.name} <span className="text-xs text-text-tertiary">{user.email}</span></span>
                                </label>
                            ))}
                        </div>
                    )}
                </div>

                <div className="flex justify-end gap-2 border-t border-border-subtle px-5 py-3">
                    <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                    <Button type="submit" disabled={busy}>Save</Button>
                </div>
            </form>
        </div>,
        document.body,
    );
}
