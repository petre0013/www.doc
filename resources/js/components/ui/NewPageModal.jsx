import { useState, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { router } from '@inertiajs/react';
import { IconCheck, IconX } from '@tabler/icons-react';
import { Button } from '@/components/ui/button';
import { useScrollLock } from '@/hooks/useScrollLock';

/**
 * One row of the "Start from" listbox. Selection is a filled background + check
 * mark, NOT a ring — rings bleed outside the row and get clipped by the list's
 * scroll container.
 */
function TemplateOption({ name, description, selected, onSelect }) {
    return (
        <button
            type="button"
            onClick={onSelect}
            role="radio"
            aria-checked={selected}
            className={`flex w-full items-center gap-3 px-3 py-2 text-left transition-colors ${
                selected ? 'bg-accent-50' : 'hover:bg-surface-hover'
            }`}
        >
            <span className="min-w-0 flex-1">
                <span className={`block truncate text-sm font-medium ${selected ? 'text-accent-600' : 'text-foreground'}`} title={name}>
                    {name}
                </span>
                {description && (
                    <span className="mt-0.5 block truncate text-xs text-text-tertiary" title={description}>{description}</span>
                )}
            </span>
            {selected && <IconCheck className="h-4 w-4 shrink-0 text-accent-600" stroke={2} />}
        </button>
    );
}

/** Select value ↔ destination: '' = top level, 'f:3' = folder 3, '7' = page 7. */
const destValue = ({ parentId = '', folderId = '' }) => (folderId ? `f:${folderId}` : String(parentId ?? ''));

/**
 * Modal for creating a new page, following the design-system dialog pattern.
 *
 * Props:
 *   open             – controlled visibility
 *   onClose          – called on cancel / success / backdrop click
 *   workspaceId      – always required
 *   parentOptions    – [{ id, label }] pages that can be the parent
 *   folderOptions    – [{ id, name }] folders the page can be filed into
 *   initialParentId  – pre-select a parent page (e.g. clicking + on a page row)
 *   initialFolderId  – pre-select a folder   (e.g. clicking + on a folder row)
 *   templates        – [{ id, name, description }] "start from" choices
 *
 * A page is EITHER a subpage of another page OR a member of a folder, never
 * both (folder membership is top-level only, enforced server-side), so the two
 * live in ONE select under separate optgroups — which is also what tells the
 * reader that folders and pages are different kinds of destination.
 */
export default function NewPageModal({
    open, onClose, workspaceId,
    parentOptions = [], folderOptions = [],
    initialParentId = '', initialFolderId = '', templates = [],
}) {
    const [title, setTitle]    = useState('');
    const [dest, setDest]      = useState(destValue({ parentId: initialParentId, folderId: initialFolderId }));
    const [templateId, setTemplateId] = useState(null);
    const [error, setError]    = useState('');
    const [busy, setBusy]      = useState(false);

    // Sync destination and reset title whenever the modal opens
    useEffect(() => {
        if (open) {
            setTitle('');
            setDest(destValue({ parentId: initialParentId, folderId: initialFolderId }));
            setTemplateId(null);
            setError('');
        }
    }, [open, initialParentId, initialFolderId]);

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
        if (!title.trim()) { setError('Title is required.'); return; }
        setBusy(true);
        const inFolder = dest.startsWith('f:');
        router.post('/documents', {
            title: title.trim(),
            workspace_id: workspaceId,
            parent_id: inFolder ? null : (dest || null),
            folder_id: inFolder ? Number(dest.slice(2)) : null,
            template_id: templateId,
        }, {
            onSuccess: () => { setBusy(false); onClose(); },
            onError: (errs) => { setBusy(false); setError(errs.title ?? 'Something went wrong.'); },
        });
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
                    <h2 className="text-[15px] font-medium text-foreground">New page</h2>
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

                        {/* Title */}
                        <div>
                            <label className="mb-1.5 block text-xs font-medium text-foreground">
                                Title
                            </label>
                            <input
                                autoFocus
                                type="text"
                                value={title}
                                onChange={(e) => { setTitle(e.target.value); setError(''); }}
                                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); handleSubmit(e); } }}
                                placeholder="e.g. VPN setup"
                                className="h-9 w-full rounded-sm border border-border bg-canvas px-3 text-sm text-foreground placeholder:text-text-tertiary outline-none transition-[border-color,box-shadow] duration-150 focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                            />
                            {error && <p className="mt-1.5 text-xs text-danger">{error}</p>}
                        </div>

                        {/* Start from — blank or a template */}
                        {templates.length > 0 && (
                            <div>
                                <label className="mb-1.5 block text-xs font-medium text-foreground">
                                    Start from
                                </label>
                                <div
                                    role="radiogroup"
                                    aria-label="Start from"
                                    className="ui-scroll max-h-44 overflow-y-auto rounded-sm border border-border bg-canvas divide-y divide-border-subtle"
                                >
                                    <TemplateOption
                                        name="Blank page"
                                        description="Start with an empty page."
                                        selected={templateId === null}
                                        onSelect={() => setTemplateId(null)}
                                    />
                                    {templates.map((t) => (
                                        <TemplateOption
                                            key={t.id}
                                            name={t.name}
                                            description={t.description}
                                            selected={templateId === t.id}
                                            onSelect={() => setTemplateId(t.id)}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Where it goes — a folder, or under an existing page */}
                        <div>
                            <label className="mb-1.5 block text-xs font-medium text-foreground">
                                Location{' '}
                                <span className="font-normal text-text-tertiary">(optional)</span>
                            </label>
                            <select
                                value={dest}
                                onChange={(e) => setDest(e.target.value)}
                                className="ui-select h-9 w-full rounded-sm border border-border bg-canvas px-3 text-sm text-foreground outline-none transition-[border-color,box-shadow] duration-150 focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                            >
                                <option value="">None — top-level page</option>
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
                                            <option key={o.id} value={o.id}>{o.label}</option>
                                        ))}
                                    </optgroup>
                                )}
                            </select>
                        </div>
                    </div>

                    {/* Footer */}
                    <div className="flex justify-end gap-2 border-t border-border-subtle bg-canvas px-5 py-3.5">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={busy || !title.trim()}>
                            {busy ? 'Creating…' : 'Create page'}
                        </Button>
                    </div>
                </form>
            </div>
        </div>,
        document.body
    );
}
