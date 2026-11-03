import { Head, Link } from '@inertiajs/react';
import { IconChevronRight, IconTag, IconFileText, IconFolderOpen } from '@tabler/icons-react';
import DocsLayout from '@/Layouts/DocsLayout';

export default function TagShow({ tag, groups }) {
    const totalDocs = groups.reduce((n, g) => n + g.documents.length, 0);

    return (
        <DocsLayout>
            <Head title={`#${tag.name}`} />

            {/* Breadcrumb */}
            <nav className="mb-5 flex items-center gap-1.5 text-sm text-text-secondary">
                <Link href="/tags" className="hover:text-foreground">Tags</Link>
                <IconChevronRight className="h-3.5 w-3.5 shrink-0 text-text-tertiary" stroke={1.5} />
                <span className="text-foreground">#{tag.name}</span>
            </nav>

            {/* Header */}
            <div className="mb-6 flex items-center gap-2.5">
                <span className="inline-flex items-center gap-1.5 rounded-md bg-accent-100 px-2.5 py-1 text-[13px] font-medium text-accent-600">
                    <IconTag className="h-3.5 w-3.5 shrink-0" stroke={1.5} />
                    {tag.name}
                </span>
                <span className="text-sm text-text-tertiary">
                    {totalDocs} {totalDocs === 1 ? 'page' : 'pages'}
                    {groups.length > 1 && ` across ${groups.length} workspaces`}
                </span>
            </div>

            {groups.length === 0 ? (
                <p className="mt-8 text-center text-sm text-text-tertiary">No pages with this tag yet.</p>
            ) : (
                <div className="space-y-5">
                    {groups.map((group) => (
                        <div key={group.workspace.id}>
                            {/* Workspace group header */}
                            <div className="mb-2 flex items-center gap-2 rounded-md border border-accent-200 bg-accent-50 px-3 py-2">
                                <IconFolderOpen className="h-3.5 w-3.5 shrink-0 text-accent-600" stroke={1.5} />
                                <Link
                                    href={`/workspaces/${group.workspace.id}`}
                                    className="text-sm font-semibold text-accent-600 transition-colors hover:text-accent-700"
                                >
                                    {group.workspace.name}
                                </Link>
                                <span className="ml-auto text-[11px] text-accent-600">
                                    {group.documents.length} {group.documents.length === 1 ? 'page' : 'pages'}
                                </span>
                            </div>

                            <div className="overflow-hidden rounded-md border border-border bg-card">
                                <ul>
                                    {group.documents.map((doc) => (
                                        <li
                                            key={doc.id}
                                            className="flex items-center gap-3 border-b border-border-subtle px-4 py-2.5 last:border-0 transition-colors hover:bg-surface-hover"
                                        >
                                            <IconFileText className="h-3.5 w-3.5 shrink-0 text-text-tertiary" stroke={1.5} />
                                            <Link
                                                href={`/documents/${doc.id}`}
                                                className="flex-1 truncate text-sm text-foreground transition-colors hover:text-accent-600"
                                                title={doc.title}
                                            >
                                                {doc.title}
                                            </Link>
                                            <div className="flex shrink-0 gap-1">
                                                {doc.tags.filter(t => t.id !== tag.id).slice(0, 3).map(t => (
                                                    <Link
                                                        key={t.id}
                                                        href={`/tags/${t.id}`}
                                                        className="rounded-md bg-accent-100 px-1.5 py-0.5 text-[10px] font-medium text-accent-600 transition-colors hover:bg-accent-200"
                                                    >
                                                        {t.name}
                                                    </Link>
                                                ))}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </DocsLayout>
    );
}
