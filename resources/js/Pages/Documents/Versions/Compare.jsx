import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    IconArrowRight, IconArrowsLeftRight, IconChevronRight, IconGitCompare,
    IconPhoto, IconTable, IconTag, IconTopologyStar3,
} from '@tabler/icons-react';
import DocsLayout from '@/Layouts/DocsLayout';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/date';

const STATUS_PILLS = {
    added:    'bg-accent-100 text-accent-600',
    removed:  'bg-danger-surface text-danger',
    modified: 'bg-warning-surface text-warning-text',
};

function StatusPill({ status }) {
    return (
        <span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${STATUS_PILLS[status] ?? 'bg-surface-hover text-text-secondary'}`}>
            {status}
        </span>
    );
}

function SectionCard({ label, children }) {
    return (
        <div className="mb-4 overflow-hidden rounded-md border border-border bg-card">
            <div className="border-b border-border bg-surface-hover px-4 py-2.5 text-[11px] font-semibold uppercase tracking-wider text-text-tertiary">
                {label}
            </div>
            {children}
        </div>
    );
}

/** "v3 · Jul 02, 2026" style descriptor for one side of the comparison. */
function sideLabel(side) {
    if (side.kind === 'current') return 'Current page';
    if (side.kind === 'document') return side.title;
    return `Version from ${formatDateTime(side.created_at) || '—'}`;
}

export default function Compare({ mode, workspace, document: doc, left, right, versions, diff }) {
    const [view, setView] = useState('inline'); // inline | side-by-side

    // Version-mode pickers navigate with fresh props (no client re-diffing).
    function repick(from, to) {
        router.get(`/documents/${doc.id}/versions/compare`, { from, to }, { preserveScroll: true });
    }

    const pickerValue = (side) => (side.kind === 'current' ? 'current' : String(side.id));
    const diagrams = diff.diagrams.filter((d) => d.status !== 'unchanged' || d.repositioned > 0);

    // The two sides differ in stored JSON, yet no section below has anything to
    // show — e.g. a diagram was only panned, or an empty page carries a null
    // body on one side. Say so: a page with nothing on it reads as a bug.
    // KEEP IN STEP with the section render conditions below: a new section that
    // isn't listed here would render under a banner claiming nothing changed.
    const hasVisibleChange = diff.title.changed
        || diff.tags.added.length > 0 || diff.tags.removed.length > 0
        || diff.body.changed
        || diff.blocks.length > 0
        || diagrams.length > 0;

    // wideable: a comparison renders document content, so it honours the user's
    // page-width preference like the page and snapshot views do. The history
    // LIST stays boxed — that's a list page, not content.
    return (
        <DocsLayout wideable>
            <Head title={`Compare — ${doc?.title ?? 'Pages'}`} />

            {/* Breadcrumb */}
            <nav className="mb-5 flex items-center gap-1.5 text-sm text-text-secondary">
                <Link href="/workspaces" className="hover:text-foreground">Workspaces</Link>
                <IconChevronRight className="h-3.5 w-3.5 text-text-tertiary" stroke={1.5} />
                {workspace && (
                    <>
                        <Link href={`/workspaces/${workspace.id}`} className="hover:text-foreground">{workspace.name}</Link>
                        <IconChevronRight className="h-3.5 w-3.5 text-text-tertiary" stroke={1.5} />
                    </>
                )}
                {mode === 'versions' && doc && (
                    <>
                        <Link href={`/documents/${doc.id}`} className="hover:text-foreground">{doc.title}</Link>
                        <IconChevronRight className="h-3.5 w-3.5 text-text-tertiary" stroke={1.5} />
                        <Link href={`/documents/${doc.id}/versions`} className="hover:text-foreground">History</Link>
                        <IconChevronRight className="h-3.5 w-3.5 text-text-tertiary" stroke={1.5} />
                    </>
                )}
                <span className="font-medium text-foreground">Compare</span>
            </nav>

            {/* Comparison header */}
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-border bg-card px-4 py-3">
                <div className="flex min-w-0 items-center gap-2 text-sm">
                    <IconGitCompare className="h-4 w-4 shrink-0 text-accent-500" stroke={1.5} aria-hidden="true" />
                    <span className="truncate rounded-full bg-danger-surface px-2 py-0.5 text-[12px] font-medium text-danger" title={sideLabel(left)}>
                        {sideLabel(left)}
                    </span>
                    <IconArrowRight className="h-3.5 w-3.5 shrink-0 text-text-tertiary" stroke={1.5} aria-hidden="true" />
                    <span className="truncate rounded-full bg-accent-100 px-2 py-0.5 text-[12px] font-medium text-accent-600" title={sideLabel(right)}>
                        {sideLabel(right)}
                    </span>
                </div>

                <div className="flex shrink-0 items-center gap-2">
                    {mode === 'versions' && (
                        <>
                            <select
                                value={pickerValue(left)}
                                onChange={(e) => repick(e.target.value, pickerValue(right))}
                                className="ui-select h-8 rounded-sm border border-border bg-surface px-2 text-sm text-foreground"
                                aria-label="Compare from"
                            >
                                {versions.map((v) => (
                                    <option key={v.id} value={v.id}>{formatDateTime(v.created_at)}</option>
                                ))}
                                <option value="current">Current page</option>
                            </select>
                            <button
                                type="button"
                                title="Swap sides"
                                onClick={() => repick(pickerValue(right), pickerValue(left))}
                                className="flex h-8 w-8 items-center justify-center rounded-sm border border-border bg-surface text-text-secondary hover:text-foreground"
                            >
                                <IconArrowsLeftRight className="h-4 w-4" stroke={1.5} />
                            </button>
                            <select
                                value={pickerValue(right)}
                                onChange={(e) => repick(pickerValue(left), e.target.value)}
                                className="ui-select h-8 rounded-sm border border-border bg-surface px-2 text-sm text-foreground"
                                aria-label="Compare to"
                            >
                                {versions.map((v) => (
                                    <option key={v.id} value={v.id}>{formatDateTime(v.created_at)}</option>
                                ))}
                                <option value="current">Current page</option>
                            </select>
                        </>
                    )}

                    {diff.body.changed && !diff.body.skipped && !diff.body.formatting_only && (
                        <div className="flex items-center rounded-sm border border-border bg-surface p-0.5 text-xs">
                            {['inline', 'side-by-side'].map((v) => (
                                <button
                                    key={v}
                                    type="button"
                                    onClick={() => setView(v)}
                                    className={`rounded-sm px-2 py-1 capitalize transition-colors ${
                                        view === v ? 'bg-accent-100 font-medium text-accent-600' : 'text-text-secondary hover:text-foreground'
                                    }`}
                                >
                                    {v === 'inline' ? 'Inline' : 'Side by side'}
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {diff.identical ? (
                <div className="rounded-md border border-accent-200 bg-accent-50 px-4 py-3 text-sm text-accent-700">
                    These two are identical — no differences to show.
                </div>
            ) : (
                <>
                    {!hasVisibleChange && (
                        <div className="rounded-md border border-warning-border bg-warning-surface px-4 py-3 text-sm text-warning-text">
                            These two differ in stored content, but nothing visible changed —
                            usually a diagram that was only panned or zoomed, or an empty page.
                        </div>
                    )}

                    {/* Title */}
                    {diff.title.changed && (
                        <SectionCard label="Title">
                            <div className="flex flex-wrap items-center gap-2 px-4 py-3 text-sm">
                                <span className="rounded px-1.5 py-0.5 line-through" style={{ background: 'var(--diff-del-bg)', color: 'var(--diff-del-text)' }}>
                                    {diff.title.old}
                                </span>
                                <IconArrowRight className="h-3.5 w-3.5 text-text-tertiary" stroke={1.5} aria-hidden="true" />
                                <span className="rounded px-1.5 py-0.5" style={{ background: 'var(--diff-add-bg)', color: 'var(--diff-add-text)' }}>
                                    {diff.title.new}
                                </span>
                            </div>
                        </SectionCard>
                    )}

                    {/* Tags */}
                    {(diff.tags.added.length > 0 || diff.tags.removed.length > 0) && (
                        <SectionCard label="Tags">
                            <div className="flex flex-wrap gap-1.5 px-4 py-3">
                                {diff.tags.added.map((name) => (
                                    <span key={`a-${name}`} className="inline-flex items-center gap-1.5 rounded-md bg-accent-100 px-2 py-0.5 text-[11px] font-medium text-accent-600">
                                        <IconTag className="h-3.5 w-3.5 shrink-0" stroke={1.5} /> {name}
                                    </span>
                                ))}
                                {diff.tags.removed.map((name) => (
                                    <span key={`r-${name}`} className="inline-flex items-center gap-1.5 rounded-md bg-danger-surface px-2 py-0.5 text-[11px] font-medium text-danger line-through">
                                        <IconTag className="h-3.5 w-3.5 shrink-0" stroke={1.5} /> {name}
                                    </span>
                                ))}
                            </div>
                        </SectionCard>
                    )}

                    {/* Body */}
                    {diff.body.changed && (
                        <SectionCard label="Content">
                            {diff.body.skipped ? (
                                <div className="m-4 rounded-md border border-warning-border bg-warning-surface px-4 py-3 text-sm text-warning-text">
                                    This page is too large to diff inline — the old and new content
                                    are shown side by side instead.
                                </div>
                            ) : diff.body.formatting_only ? (
                                <div className="m-4 rounded-md border border-warning-border bg-warning-surface px-4 py-3 text-sm text-warning-text">
                                    Only attributes changed — formatting, alignment, a heading level
                                    or a ticked task. The inline diff cannot mark those, so the old
                                    and new content are shown side by side instead.
                                </div>
                            ) : view === 'inline' ? (
                                <div className="tiptap-read-area">
                                    {/* Server-side diffed HTML (php-htmldiff over RenderDocument
                                        output). The sanctioned exception to the client-TipTap-render
                                        convention: ins/del markup exists only server-side, and
                                        diagrams are stripped before diffing, so no base64 payloads
                                        land here. */}
                                    <div className="tiptap diff-content" dangerouslySetInnerHTML={{ __html: diff.body.html }} />
                                </div>
                            ) : null}

                            {(diff.body.skipped || diff.body.formatting_only || view === 'side-by-side') && (
                                <div className="grid grid-cols-1 gap-0 md:grid-cols-2 md:divide-x md:divide-border">
                                    {[['Old', diff.body.leftHtml], ['New', diff.body.rightHtml]].map(([label, html]) => (
                                        <div key={label} className="min-w-0">
                                            <div className="border-b border-border px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-text-tertiary">
                                                {label}
                                            </div>
                                            <div className="tiptap-read-area">
                                                <div className="tiptap" dangerouslySetInnerHTML={{ __html: html }} />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </SectionCard>
                    )}

                    {/* Blocks */}
                    {diff.blocks.length > 0 && (
                        <SectionCard label="Images & tables">
                            <div className="divide-y divide-border">
                                {diff.blocks.map((block, i) => (
                                    <div key={i} className="flex items-center gap-3 px-4 py-2.5 text-sm">
                                        {block.type === 'image'
                                            ? <IconPhoto className="h-4 w-4 shrink-0 text-text-tertiary" stroke={1.5} aria-hidden="true" />
                                            : <IconTable className="h-4 w-4 shrink-0 text-text-tertiary" stroke={1.5} aria-hidden="true" />}
                                        <span className="min-w-0 flex-1 truncate text-foreground" title={block.label}>{block.label}</span>
                                        <StatusPill status={block.status} />
                                    </div>
                                ))}
                            </div>
                        </SectionCard>
                    )}

                    {/* Diagrams */}
                    {diagrams.map((diagram, i) => (
                        <SectionCard key={i} label={`Diagram — ${diagram.name}`}>
                            <div className="px-4 py-3">
                                <div className="mb-2 flex items-center gap-2">
                                    <IconTopologyStar3 className="h-4 w-4 text-text-tertiary" stroke={1.5} aria-hidden="true" />
                                    <StatusPill status={diagram.status} />
                                </div>

                                {diagram.overlay && (
                                    <figure className="m-0 mb-3">
                                        <div className="overflow-x-auto rounded-md border border-border bg-canvas p-3">
                                            <img
                                                src={diagram.overlay.src}
                                                width={diagram.overlay.width}
                                                height={diagram.overlay.height}
                                                alt={`Change overlay for diagram ${diagram.name}`}
                                                className="max-w-full"
                                            />
                                        </div>
                                        <figcaption className="mt-1.5 flex items-center gap-3 text-[11px] text-text-tertiary">
                                            <span className="flex items-center gap-1"><span className="text-[8px] text-accent-600">●</span> added</span>
                                            <span className="flex items-center gap-1"><span className="text-[8px] text-danger">●</span> removed</span>
                                            <span className="flex items-center gap-1"><span className="text-[8px] text-warning">●</span> changed</span>
                                        </figcaption>
                                    </figure>
                                )}

                                {(diagram.changes.length > 0 || diagram.repositioned > 0) && (
                                    <ul className="space-y-1 text-[13px] text-text-secondary">
                                        {diagram.changes.map((change, j) => (
                                            <li key={j}>{change.text}</li>
                                        ))}
                                        {diagram.repositioned > 0 && (
                                            <li className="text-xs italic text-text-tertiary">
                                                {diagram.repositioned} node{diagram.repositioned === 1 ? '' : 's'} repositioned
                                            </li>
                                        )}
                                    </ul>
                                )}
                            </div>
                        </SectionCard>
                    ))}

                    <p className="mt-2 text-[11px] text-text-tertiary">
                        File attachments are not versioned and are not part of this comparison.
                    </p>
                </>
            )}

            {mode === 'versions' && doc && (
                <div className="mt-6">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/documents/${doc.id}/versions`}>Back to history</Link>
                    </Button>
                </div>
            )}
        </DocsLayout>
    );
}
