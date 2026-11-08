import { Head, Link, router, usePage } from '@inertiajs/react';
import { IconShieldCheck } from '@tabler/icons-react';
import DocsLayout from '@/Layouts/DocsLayout';
import { STATUS_LABELS } from '@/components/documents/DocumentControlPanel';
import { formatDate } from '@/lib/date';

export default function DocumentControlIndex({ controls, asOf, overdue }) {
    const { auth } = usePage().props;

    function changeDate(e) {
        router.get('/document-control', e.target.value ? { as_of: e.target.value } : {}, { preserveState: true, preserveScroll: true });
    }

    return (
        <DocsLayout>
            <Head title="Document control" />

            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-[19px] font-semibold text-foreground">Document control</h1>
                    <p className="mt-0.5 text-sm text-text-secondary">
                        {controls.length} controlled {controls.length === 1 ? 'document' : 'documents'}
                        {overdue > 0 && <span className="text-danger"> · {overdue} overdue for review</span>}
                    </p>
                </div>
                {auth?.user?.roles?.includes('admin') && (
                    <a href="/document-control/audit-pack" className="text-xs underline text-text-secondary">Download audit pack</a>
                )}
                <label className="text-xs text-text-secondary">
                    In force on
                    <input type="date" value={asOf ?? ''} onChange={changeDate} className="ml-2 h-8 rounded-sm border border-border bg-surface px-2 text-sm text-foreground" />
                </label>
            </div>

            {controls.length === 0 ? (
                <div className="mt-6 flex flex-col items-center gap-2 rounded-md border border-border bg-card px-4 py-12 text-center">
                    <IconShieldCheck className="h-6 w-6 text-text-tertiary" stroke={1.5} />
                    <p className="text-sm text-text-secondary">No controlled documents yet. Open a page and choose "Place under document control".</p>
                </div>
            ) : (
                <div className="mt-5 overflow-x-auto rounded-md border border-border bg-card">
                    <table className="w-full min-w-[820px] text-left text-sm">
                        <thead className="border-b border-border text-xs text-text-tertiary">
                            <tr>
                                {['ID', 'Document', 'Class', 'Owner', 'Status', 'In force', 'Next review'].map((h) => <th key={h} className="px-3 py-2 font-medium">{h}</th>)}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {controls.map((c) => (
                                <tr key={c.id}>
                                    <td className="whitespace-nowrap px-3 py-2 text-text-secondary">{c.doc_number}</td>
                                    <td className="px-3 py-2">
                                        <Link href={`/documents/${c.document_id}`} className="font-medium text-foreground hover:underline">{c.title}</Link>
                                        <span className="block text-xs text-text-tertiary">{c.workspace}</span>
                                    </td>
                                    <td className="px-3 py-2 capitalize text-text-secondary">{c.classification}</td>
                                    <td className="px-3 py-2 text-text-secondary">{c.owner ?? '—'}</td>
                                    <td className="px-3 py-2 text-text-secondary">{STATUS_LABELS[c.status]}</td>
                                    <td className="px-3 py-2 text-text-secondary">
                                        {c.in_force ? (
                                            <Link className="underline" href={`/documents/${c.document_id}/versions/${c.in_force.version_id}`} title={`Approved by ${c.in_force.approved_by}`}>
                                                {formatDate(c.in_force.approved_at)}
                                            </Link>
                                        ) : 'None'}
                                    </td>
                                    <td className={`whitespace-nowrap px-3 py-2 ${c.overdue ? 'font-medium text-danger' : 'text-text-secondary'}`}>
                                        {c.next_review_at ? formatDate(c.next_review_at) : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </DocsLayout>
    );
}
