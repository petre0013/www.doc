import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { IconChevronRight, IconHistory, IconArrowBack, IconGitCompare, IconTag } from '@tabler/icons-react';
import DocsLayout from '@/Layouts/DocsLayout';
import TipTapEditor from '@/components/editor/TipTapEditor';
import { Button } from '@/components/ui/button';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import { formatDateTime } from '@/lib/date';
import { formatBytes } from '@/lib/utils';

const savedAt = (ts) => formatDateTime(ts) || '—';

export default function VersionShow({ document: doc, workspace, version }) {
    const [restoreOpen, setRestoreOpen] = useState(false);

    function confirmRestore() {
        setRestoreOpen(false);
        // Inertia sends the CSRF token itself — no manual header needed.
        router.post(`/documents/${doc.id}/versions/${version.id}/restore`);
    }

    return (
        <>
        {/* wideable: a snapshot is the page's own content, read the same way. */}
        <DocsLayout wideable>
            <Head title={`Version — ${version.title}`} />

            {/* Breadcrumb */}
            <nav className="mb-5 flex items-center gap-1.5 text-sm text-text-secondary">
                <Link href="/workspaces" className="hover:text-foreground">Workspaces</Link>
                <IconChevronRight className="h-3.5 w-3.5 text-text-tertiary" stroke={1.5} />
                <Link href={`/workspaces/${workspace.id}`} className="hover:text-foreground">{workspace.name}</Link>
                <IconChevronRight className="h-3.5 w-3.5 text-text-tertiary" stroke={1.5} />
                <Link href={`/documents/${doc.id}`} className="hover:text-foreground">{doc.title}</Link>
                <IconChevronRight className="h-3.5 w-3.5 text-text-tertiary" stroke={1.5} />
                <Link href={`/documents/${doc.id}/versions`} className="hover:text-foreground">History</Link>
                <IconChevronRight className="h-3.5 w-3.5 text-text-tertiary" stroke={1.5} />
                <span className="font-medium text-foreground">Version</span>
            </nav>

            {/* Snapshot banner */}
            <div className="mb-4 flex items-center justify-between gap-4 rounded-md border border-warning-border bg-warning-surface px-4 py-3">
                <div className="text-sm text-warning-text">
                    <span className="font-medium">Read-only snapshot</span>
                    {' · '}Saved {savedAt(version.created_at)}
                    {version.creator && <> by <span className="font-medium">{version.creator.name}</span></>}
                </div>
                <div className="flex shrink-0 gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/documents/${doc.id}/versions`}>
                            <IconHistory className="h-3.5 w-3.5" stroke={1.5} />
                            All versions
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/documents/${doc.id}/versions/compare?from=${version.id}&to=current`}>
                            <IconGitCompare className="h-3.5 w-3.5" stroke={1.5} />
                            Compare with current
                        </Link>
                    </Button>
                    <Button size="sm" onClick={() => setRestoreOpen(true)}>
                        <IconArrowBack className="h-3.5 w-3.5" stroke={1.5} />
                        Restore this version
                    </Button>
                </div>
            </div>

            {/* Content card */}
            <div className="overflow-hidden rounded-md border border-border bg-card">
                {/* Title */}
                <div className="border-b border-border px-8 py-5">
                    <h1 className="text-2xl font-semibold text-foreground">{version.title}</h1>

                    {/* Tags as they were at this snapshot — names, not links: a tag
                        here may have since been renamed or removed. */}
                    {version.tags?.length > 0 && (
                        <div className="mt-3 flex flex-wrap gap-1.5">
                            {version.tags.map((name) => (
                                <span
                                    key={name}
                                    className="inline-flex items-center gap-1.5 rounded-md bg-accent-100 px-2 py-0.5 text-[11px] font-medium text-accent-600"
                                >
                                    <IconTag className="h-3.5 w-3.5 shrink-0" stroke={1.5} />
                                    {name}
                                </span>
                            ))}
                        </div>
                    )}

                    {/* Attachments at this snapshot */}
                    {version.attachments?.length > 0 && (
                        <div className="mt-4 border-t border-border pt-4">
                            <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-text-tertiary">
                                Attachments at this time
                            </h3>
                            <div className="flex flex-wrap gap-x-4 gap-y-2">
                                {version.attachments.map((att) => (
                                    <div key={att.id} className="flex items-center gap-1.5 text-sm">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" className="text-text-tertiary"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                                        <span className="font-medium text-text-secondary">{att.original_name}</span>
                                        <span className="text-[11px] text-text-tertiary">
                                            {formatBytes(att.size)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {/* Body — rendered through the same read-only TipTap path as the
                    live page (not the cached content_html), so diagrams show as
                    view-only canvases from their graph JSON instead of a derived
                    PNG that may be missing/stale for this snapshot. */}
                <TipTapEditor
                    key={version.id}
                    content={version.content}
                    editable={false}
                />
            </div>
        </DocsLayout>

        <ConfirmDialog
            open={restoreOpen}
            title="Restore this version?"
            message={`The current page will be saved as a new version first, then this snapshot from ${savedAt(version.created_at)} — its content, title and tags — will become the active version.`}
            confirmLabel="Restore"
            cancelLabel="Cancel"
            variant="primary"
            onConfirm={confirmRestore}
            onCancel={() => setRestoreOpen(false)}
        />
        </>
    );
}
