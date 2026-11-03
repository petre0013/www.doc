import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { IconTrash, IconRestore, IconFileText, IconFolder, IconTrashX } from '@tabler/icons-react';
import DocsLayout from '@/Layouts/DocsLayout';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { timeAgo } from '@/lib/date';

function TrashRow({ icon: Icon, title, meta, deletedAt, badge, busy, onRestore, onPurge }) {
    return (
        <li className="grid grid-cols-[1fr_140px_160px] items-center border-b border-border-subtle px-4 py-3 transition-colors last:border-0 hover:bg-surface-hover/60">
            <div className="flex min-w-0 items-center gap-2">
                <Icon className="h-4 w-4 shrink-0 text-text-tertiary" stroke={1.5} />
                <span className="truncate text-sm font-medium text-foreground" title={title}>{title}</span>
                {meta && (
                    <>
                        <span className="text-[11px] text-text-tertiary">·</span>
                        <span className="shrink-0 text-[11px] text-text-secondary">{meta}</span>
                    </>
                )}
                {badge && (
                    <span className="shrink-0 rounded-full bg-border-subtle px-1.5 py-px text-[10px] font-medium text-text-secondary">
                        {badge}
                    </span>
                )}
            </div>
            <span className="text-xs text-text-tertiary">{timeAgo(deletedAt) ?? '—'}</span>
            <div className="flex items-center justify-end gap-1.5">
                <Button
                    type="button"
                    size="xs"
                    variant="secondary"
                    disabled={busy}
                    onClick={onRestore}
                    className="text-text-secondary hover:border-accent-200 hover:bg-accent-50 hover:text-accent-600"
                >
                    <IconRestore stroke={1.5} />
                    Restore
                </Button>
                <Button
                    type="button"
                    size="icon-xs"
                    variant="secondary"
                    disabled={busy}
                    onClick={onPurge}
                    title="Delete permanently"
                    className="text-text-tertiary hover:border-danger/20 hover:bg-danger-surface hover:text-danger"
                >
                    <IconTrashX stroke={1.5} />
                </Button>
            </div>
        </li>
    );
}

function SectionCard({ label, count, children }) {
    return (
        <div className="overflow-hidden rounded-md border border-border bg-card">
            <div className="grid grid-cols-[1fr_140px_160px] border-b border-border bg-surface-hover px-4 py-2.5">
                <span className="text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">{label}</span>
                <span className="text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">Deleted</span>
                <span className="text-right text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">Actions</span>
            </div>
            <ul>{children}</ul>
        </div>
    );
}

export default function TrashIndex({ workspaces = [], documents = [] }) {
    const [purge, setPurge]   = useState(null); // { type, id, title, count }
    const [emptyOpen, setEmptyOpen] = useState(false);
    const [busyKey, setBusyKey] = useState(null);

    const isEmpty = workspaces.length === 0 && documents.length === 0;
    const totalItems = workspaces.length + documents.length;

    function restore(type, item) {
        const key = `${type}-${item.id}`;
        setBusyKey(key);
        router.post(`/trash/${type}/${item.id}/restore`, {}, {
            preserveScroll: true,
            onFinish: () => setBusyKey(null),
        });
    }

    function confirmPurge() {
        const { type, id } = purge;
        setBusyKey(`${type}-${id}`);
        router.delete(`/trash/${type}/${id}`, {
            preserveScroll: true,
            onFinish: () => { setBusyKey(null); setPurge(null); },
        });
    }

    function confirmEmpty() {
        setBusyKey('empty');
        router.delete('/trash', {
            preserveScroll: true,
            onFinish: () => { setBusyKey(null); setEmptyOpen(false); },
        });
    }

    return (
        <DocsLayout>
            <Head title="Trash" />

            <div className="mb-1 flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <IconTrash className="h-5 w-5 text-accent-600" stroke={1.5} />
                    <h1 className="text-[19px] font-semibold text-foreground">Trash</h1>
                </div>
                {!isEmpty && (
                    <Button
                        type="button"
                        size="xs"
                        variant="secondary"
                        onClick={() => setEmptyOpen(true)}
                        className="border-danger/20 text-danger hover:bg-danger-surface"
                    >
                        <IconTrashX stroke={1.5} />
                        Empty trash
                    </Button>
                )}
            </div>
            <p className="mb-5 text-sm text-text-secondary">
                Deleted workspaces and pages are kept here. Restoring an item also restores everything inside it.
            </p>

            {isEmpty ? (
                <div className="overflow-hidden rounded-md border border-border bg-card">
                    <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
                        <div className="flex h-12 w-12 items-center justify-center rounded-xl border border-accent-200 bg-accent-50">
                            <IconTrash className="h-6 w-6 text-accent-600" stroke={1.5} />
                        </div>
                        <div>
                            <p className="text-sm font-medium text-foreground">Trash is empty</p>
                            <p className="mt-0.5 text-xs text-text-tertiary">Deleted workspaces and pages will appear here.</p>
                        </div>
                    </div>
                </div>
            ) : (
                <div className="space-y-5">
                    {workspaces.length > 0 && (
                        <SectionCard label="Workspaces">
                            {workspaces.map(ws => (
                                <TrashRow
                                    key={ws.id}
                                    icon={IconFolder}
                                    title={ws.name}
                                    badge={ws.page_count > 0 ? `${ws.page_count} page${ws.page_count !== 1 ? 's' : ''}` : null}
                                    deletedAt={ws.deleted_at}
                                    busy={busyKey === `workspaces-${ws.id}`}
                                    onRestore={() => restore('workspaces', ws)}
                                    onPurge={() => setPurge({ type: 'workspaces', id: ws.id, title: ws.name, count: ws.page_count })}
                                />
                            ))}
                        </SectionCard>
                    )}

                    {documents.length > 0 && (
                        <SectionCard label="Pages">
                            {documents.map(doc => (
                                <TrashRow
                                    key={doc.id}
                                    icon={IconFileText}
                                    title={doc.title}
                                    meta={doc.workspace?.name}
                                    badge={doc.child_count > 0 ? `+${doc.child_count} subpage${doc.child_count !== 1 ? 's' : ''}` : null}
                                    deletedAt={doc.deleted_at}
                                    busy={busyKey === `documents-${doc.id}`}
                                    onRestore={() => restore('documents', doc)}
                                    onPurge={() => setPurge({ type: 'documents', id: doc.id, title: doc.title, count: doc.child_count })}
                                />
                            ))}
                        </SectionCard>
                    )}
                </div>
            )}

            <ConfirmDialog
                open={!!purge}
                busy={!!purge && busyKey === `${purge.type}-${purge.id}`}
                title={purge ? `Permanently delete "${purge.title}"?` : ''}
                message={
                    purge?.type === 'workspaces'
                        ? `This will permanently delete this workspace, all ${purge.count} page${purge.count !== 1 ? 's' : ''} inside it, and their version history. This cannot be undone.`
                        : purge?.count > 0
                        ? `This will permanently delete this page, its ${purge.count} subpage${purge.count !== 1 ? 's' : ''}, and all version history. This cannot be undone.`
                        : 'This will permanently delete this page and all its version history. This cannot be undone.'
                }
                confirmLabel="Delete permanently"
                cancelLabel="Cancel"
                variant="danger"
                onConfirm={confirmPurge}
                onCancel={() => setPurge(null)}
            />

            <ConfirmDialog
                open={emptyOpen}
                busy={busyKey === 'empty'}
                title="Empty the trash?"
                message={`This will permanently delete all ${totalItems} item${totalItems !== 1 ? 's' : ''} in the trash, everything inside them, and their version history. This cannot be undone.`}
                confirmLabel="Empty trash"
                cancelLabel="Cancel"
                variant="danger"
                onConfirm={confirmEmpty}
                onCancel={() => setEmptyOpen(false)}
            />
        </DocsLayout>
    );
}
