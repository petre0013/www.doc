import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { IconPlus, IconTemplate, IconTrash } from '@tabler/icons-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Button } from '@/components/ui/button';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import { timeAgo } from '@/lib/date';

/**
 * Manage page templates — a Settings tab for editors and admins (the route
 * 403s viewers). Creating one asks for a name/description, then goes straight
 * to its editor.
 */
export default function TemplatesIndex({ templates }) {
    const [showForm, setShowForm]     = useState(false);
    const [name, setName]             = useState('');
    const [description, setDescription] = useState('');
    const [error, setError]           = useState('');
    const [processing, setProcessing] = useState(false);
    const [toDelete, setToDelete]     = useState(null);
    const [deleting, setDeleting]     = useState(false);

    function submit(e) {
        e.preventDefault();
        if (!name.trim()) { setError('Name is required.'); return; }
        setProcessing(true);
        router.post('/templates', { name: name.trim(), description: description.trim() || null }, {
            onError: (errs) => { setError(errs.name ?? 'Something went wrong.'); setProcessing(false); },
        });
    }

    function confirmDelete() {
        setDeleting(true);
        router.delete(`/templates/${toDelete.id}`, {
            onFinish: () => { setDeleting(false); setToDelete(null); },
        });
    }

    return (
        <>
        <SettingsLayout>
            <Head title="Templates" />

            <div className="flex min-w-0 items-start justify-between gap-4">
                <div className="min-w-0">
                    <h2 className="text-[15px] font-semibold text-foreground">Templates</h2>
                    <p className="mt-0.5 text-sm text-text-secondary">
                        {templates.length} {templates.length === 1 ? 'template' : 'templates'} · Reusable starting points offered in the New page dialog.
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-1.5 self-center">
                    <Button onClick={() => setShowForm(true)}>
                        <IconPlus stroke={1.5} />
                        New template
                    </Button>
                </div>
            </div>

            <div className="overflow-hidden rounded-md border border-border bg-card">
                {/* Column headers */}
                {/* Cells pad themselves (not the container) so the grid tracks
                    span the same width as the data rows' and columns line up. */}
                <div className="grid grid-cols-[minmax(180px,1fr)_2fr_120px_44px] border-b border-border bg-surface-hover py-2.5">
                    <span className="pl-4 pr-4 text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">Template</span>
                    <span className="pr-4 text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">Description</span>
                    <span className="pr-4 text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">Updated</span>
                    <span />
                </div>

                {templates.length === 0 && !showForm && (
                    <p className="px-4 py-8 text-center text-sm text-text-tertiary">
                        No templates yet. Create one here, or save an existing page as a template.
                    </p>
                )}

                {/* Inline form — new template (at the top) */}
                {showForm && (
                    <div className="border-b border-border bg-surface-hover/30 px-4 py-3">
                        <form onSubmit={submit} className="flex items-start gap-2">
                            <div className="w-56">
                                <input
                                    autoFocus
                                    type="text"
                                    value={name}
                                    onChange={(e) => { setName(e.target.value); setError(''); }}
                                    placeholder="Template name"
                                    className="h-[33px] w-full rounded-sm border border-border bg-canvas px-3 text-sm text-foreground placeholder:text-text-tertiary outline-none transition-[border-color,box-shadow] duration-150 focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                                />
                                {error && <p className="mt-1 text-xs text-danger">{error}</p>}
                            </div>
                            <input
                                type="text"
                                value={description}
                                onChange={(e) => setDescription(e.target.value)}
                                placeholder="Short description (optional)"
                                className="h-[33px] flex-1 rounded-sm border border-border bg-canvas px-3 text-sm text-foreground placeholder:text-text-tertiary outline-none transition-[border-color,box-shadow] duration-150 focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                            />
                            <button
                                type="submit"
                                disabled={processing || !name.trim()}
                                className="rounded-sm bg-primary px-3 py-1.5 text-xs font-medium text-text-inverse transition-opacity hover:opacity-90 disabled:opacity-50"
                            >
                                Create
                            </button>
                            <button
                                type="button"
                                onClick={() => { setShowForm(false); setName(''); setDescription(''); setError(''); }}
                                className="rounded-sm px-2 py-1.5 text-xs text-text-secondary transition-colors hover:bg-surface-hover hover:text-foreground"
                            >
                                Cancel
                            </button>
                        </form>
                    </div>
                )}

                {templates.length > 0 && (
                    <ul>
                        {templates.map((template) => (
                            <li
                                key={template.id}
                                className="group grid grid-cols-[minmax(180px,1fr)_2fr_120px_44px] items-center border-b border-border-subtle last:border-0 transition-colors hover:bg-surface-hover/60"
                            >
                                <Link
                                    href={`/templates/${template.id}/edit`}
                                    className="flex min-w-0 items-center gap-2 py-2.5 pl-4 pr-4 text-sm font-medium text-foreground transition-colors hover:text-accent-600"
                                >
                                    <IconTemplate className="h-4 w-4 shrink-0 text-text-tertiary" stroke={1.5} />
                                    <span className="truncate" title={template.name}>{template.name}</span>
                                </Link>
                                <div className="truncate py-2.5 pr-4 text-sm text-text-secondary" title={template.description || undefined}>
                                    {template.description}
                                </div>
                                <div className="py-2.5 pr-4 text-xs text-text-tertiary">
                                    {timeAgo(template.updated_at)}
                                </div>
                                <div className="flex items-center justify-center py-1.5 pr-2">
                                    <button
                                        type="button"
                                        onClick={() => setToDelete(template)}
                                        title={`Delete ${template.name}`}
                                        className="flex h-7 w-7 items-center justify-center rounded-sm text-text-tertiary opacity-0 transition-opacity group-hover:opacity-100 hover:bg-danger-surface hover:text-danger"
                                    >
                                        <IconTrash className="h-4 w-4" stroke={1.5} />
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </SettingsLayout>

        <ConfirmDialog
            open={toDelete !== null}
            busy={deleting}
            title={`Delete template "${toDelete?.name}"?`}
            message="Pages already created from it are not affected. This cannot be undone."
            confirmLabel="Delete template"
            cancelLabel="Cancel"
            variant="danger"
            onConfirm={confirmDelete}
            onCancel={() => setToDelete(null)}
        />
        </>
    );
}
