import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { IconChevronRight, IconClock, IconGitCompare, IconHistory, IconUser } from '@tabler/icons-react';
import DocsLayout from '@/Layouts/DocsLayout';
import { Button } from '@/components/ui/button';
import { timeAgo } from '@/lib/date';

/** "+12 −3 · diagram" change badges from the version's stored summary. */
function SummaryBadges({ summary }) {
    if (!summary) return null;

    const blocks = (summary.blocks_added ?? 0) + (summary.blocks_removed ?? 0);

    return (
        <span className="ml-2 inline-flex items-center gap-1.5 align-middle text-[11px]">
            {summary.words_added > 0 && <span className="font-medium text-accent-600">+{summary.words_added}</span>}
            {summary.words_removed > 0 && <span className="font-medium text-danger">−{summary.words_removed}</span>}
            {blocks > 0 && (
                <span className="text-text-tertiary">
                    {blocks} block{blocks === 1 ? '' : 's'}
                </span>
            )}
            {summary.diagram_changed && (
                <span className="rounded-sm bg-warning-surface px-1.5 py-0.5 text-[10px] font-medium text-warning-text">
                    diagram
                </span>
            )}
            {summary.formatting_changed && (
                <span className="text-text-tertiary">formatting</span>
            )}
        </span>
    );
}

export default function VersionsIndex({ document, workspace, versions }) {
    // Row indexes of the (at most two) versions picked for comparison.
    // Default: the two most recent. Picking a third swaps out the older pick.
    const [selected, setSelected] = useState(versions.length >= 2 ? [0, 1] : []);

    function toggle(index) {
        setSelected((current) => {
            if (current.includes(index)) return current.filter((i) => i !== index);
            if (current.length < 2) return [...current, index];
            return [current[current.length - 1], index];
        });
    }

    function compareSelected() {
        // Row 0 mirrors the live page; deeper rows are real snapshots. The
        // older row (higher index) is always the "from" side.
        const [a, b] = [...selected].sort((x, y) => y - x);
        const param = (i) => (i === 0 ? 'current' : String(versions[i].id));
        router.get(`/documents/${document.id}/versions/compare`, { from: param(a), to: param(b) });
    }

    return (
        <DocsLayout>
            <Head title={`History — ${document.title}`} />

            <nav className="mb-5 flex items-center gap-1.5 text-sm text-text-secondary">
                <Link href="/workspaces" className="hover:text-foreground">Workspaces</Link>
                <IconChevronRight className="h-3.5 w-3.5 text-text-tertiary" stroke={1.5} />
                <Link href={`/workspaces/${workspace.id}`} className="hover:text-foreground">{workspace.name}</Link>
                <IconChevronRight className="h-3.5 w-3.5 text-text-tertiary" stroke={1.5} />
                <Link href={`/documents/${document.id}`} className="hover:text-foreground">{document.title}</Link>
                <IconChevronRight className="h-3.5 w-3.5 text-text-tertiary" stroke={1.5} />
                <span className="font-medium text-foreground">History</span>
            </nav>

            <div className="mb-4 flex items-center gap-2">
                <IconHistory className="h-5 w-5 text-accent-600" stroke={1.5} />
                <h1 className="text-[19px] font-semibold text-foreground">Version history</h1>
                <span className="ml-1 text-sm text-text-tertiary">({versions.length})</span>

                {versions.length >= 2 && (
                    <Button
                        size="sm"
                        className="ml-auto"
                        disabled={selected.length !== 2}
                        onClick={compareSelected}
                        title={selected.length === 2 ? 'Compare the selected versions' : 'Select two versions to compare'}
                    >
                        <IconGitCompare className="h-3.5 w-3.5" stroke={1.5} />
                        Compare
                    </Button>
                )}
            </div>

            {versions.length === 0 ? (
                <p className="mt-8 text-center text-sm text-text-tertiary">No versions saved yet.</p>
            ) : (
                <div className="overflow-hidden rounded-md border border-border bg-card">
                    <div className="grid grid-cols-[28px_1fr_140px_140px] border-b border-border bg-surface-hover px-4 py-2.5">
                        <span aria-hidden="true" />
                        <span className="text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">Title</span>
                        <span className="text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">Author</span>
                        <span className="text-[11px] font-semibold uppercase tracking-[0.05em] text-text-tertiary">Saved</span>
                    </div>
                    <ul>
                        {versions.map((v, i) => (
                            <li key={v.id}
                                className="grid grid-cols-[28px_1fr_140px_140px] items-center border-b border-border-subtle last:border-0 px-4 py-3 transition-colors hover:bg-surface-hover">
                                <input
                                    type="checkbox"
                                    className="h-3.5 w-3.5 accent-accent-400"
                                    checked={selected.includes(i)}
                                    onChange={() => toggle(i)}
                                    aria-label={`Select version from ${timeAgo(v.created_at)} for comparison`}
                                />
                                <div className="min-w-0">
                                    <Link
                                        href={i === 0
                                            ? `/documents/${document.id}`
                                            : `/documents/${document.id}/versions/${v.id}`}
                                        className="truncate text-sm font-medium text-accent-600 hover:underline"
                                        title={v.title}
                                    >
                                        {v.title}
                                    </Link>
                                    {i === 0 && (
                                        <span className="ml-2 rounded-sm bg-accent-100 px-1.5 py-0.5 text-[10px] font-semibold text-accent-600">
                                            current
                                        </span>
                                    )}
                                    <SummaryBadges summary={v.summary} />
                                </div>
                                <div className="flex items-center gap-1.5 text-xs text-text-secondary">
                                    <IconUser className="h-3.5 w-3.5 text-text-tertiary" stroke={1.5} />
                                    {v.creator?.name ?? '—'}
                                </div>
                                <div className="flex items-center gap-1.5 text-xs text-text-tertiary">
                                    <IconClock className="h-3.5 w-3.5" stroke={1.5} />
                                    {timeAgo(v.created_at) ?? '—'}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </DocsLayout>
    );
}
