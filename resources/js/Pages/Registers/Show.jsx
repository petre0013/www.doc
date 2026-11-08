import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { IconX } from '@tabler/icons-react';
import DocsLayout from '@/Layouts/DocsLayout';
import { Button } from '@/components/ui/button';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import RegisterForm from '@/components/registers/RegisterForm';
import IncidentDeadlines from '@/components/registers/IncidentDeadlines';
import { formatDateTime } from '@/lib/date';
import { can } from '@/lib/permissions';

const selectClass = 'ui-select h-9 min-w-0 flex-1 rounded-sm border border-border bg-surface px-2 text-sm text-foreground';

export default function RegisterShow({ type, config, users, item, links, documentOptions, itemOptions, canAccept }) {
    const { auth } = usePage().props;
    const [documentId, setDocumentId] = useState('');
    const [linkedItemId, setLinkedItemId] = useState('');
    const [deleting, setDeleting] = useState(false);
    const base = `/registers/${type}/${item.id}`;

    function add(payload, reset) {
        router.post(`${base}/links`, payload, { preserveScroll: true, onSuccess: reset });
    }

    return (
        <DocsLayout>
            <Head title={item.reference} />
            <p className="text-sm text-text-secondary">
                <Link href="/registers" className="hover:text-foreground">Registers</Link> / <Link href={`/registers/${type}`} className="hover:text-foreground">{config.label}</Link> / {item.reference}
            </p>
            <h1 className="mt-1 text-[19px] font-semibold text-foreground">{item.title}</h1>

            {item.computed.deadlines && <div className="mt-3 rounded-md border border-border bg-card p-3"><IncidentDeadlines deadlines={item.computed.deadlines} /></div>}

            {type === 'risk' && (
                <div className="mt-3 flex flex-wrap items-center gap-3 rounded-md border border-border bg-card p-3 text-sm text-text-secondary">
                    <span>Score: {item.computed.score ?? 'not rated'}</span>
                    <span>Residual: {item.computed.residual_score ?? 'not rated'}</span>
                    {item.data.accepted_by
                        ? <span>Accepted by {item.data.accepted_by}, {formatDateTime(item.data.accepted_at)}</span>
                        : canAccept && <Button size="sm" variant="outline" onClick={() => router.post(`/registers/risk/${item.id}/accept`, {}, { preserveScroll: true })}>Accept residual risk</Button>}
                </div>
            )}

            <div className="mt-4 rounded-md border border-border bg-card p-4">
                <RegisterForm key={item.id} type={type} config={config} users={users} item={item} />
            </div>

            <section className="mt-6">
                <h2 className="text-sm font-semibold text-foreground">Linked evidence and records</h2>
                {links.length > 0 && (
                    <ul className="mt-2 divide-y divide-border rounded-md border border-border bg-card text-sm">
                        {links.map((l) => (
                            <li key={l.id} className="flex items-center justify-between gap-2 px-3 py-2">
                                {l.document
                                    ? <Link href={`/documents/${l.document.id}`} className="text-foreground hover:underline">{l.document.title}</Link>
                                    : <Link href={`/registers/${l.item.type}/${l.item.id}`} className="text-foreground hover:underline">{l.item.reference} {l.item.title}</Link>}
                                <button type="button" aria-label="Remove link" className="text-text-tertiary hover:text-danger" onClick={() => router.delete(`${base}/links/${l.id}`, { preserveScroll: true })}>
                                    <IconX className="h-4 w-4" stroke={1.5} />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                <div className="mt-2 flex gap-2">
                    <select aria-label="Page to link" value={documentId} onChange={(e) => setDocumentId(e.target.value)} className={selectClass}>
                        <option value="">Choose a page…</option>
                        {documentOptions.map((d) => <option key={d.id} value={d.id}>{d.title}</option>)}
                    </select>
                    <Button variant="outline" disabled={!documentId} onClick={() => add({ document_id: Number(documentId) }, () => setDocumentId(''))}>Link page</Button>
                </div>
                <div className="mt-2 flex gap-2">
                    <select aria-label="Record to link" value={linkedItemId} onChange={(e) => setLinkedItemId(e.target.value)} className={selectClass}>
                        <option value="">Choose a record…</option>
                        {itemOptions.map((i) => <option key={i.id} value={i.id}>{i.reference} {i.title}</option>)}
                    </select>
                    <Button variant="outline" disabled={!linkedItemId} onClick={() => add({ linked_item_id: Number(linkedItemId) }, () => setLinkedItemId(''))}>Link record</Button>
                </div>
            </section>

            {can(auth).isAdmin && (
                <div className="mt-8">
                    <button type="button" className="text-xs text-danger underline" onClick={() => setDeleting(true)}>Delete this record</button>
                </div>
            )}

            <ConfirmDialog
                open={deleting}
                title={`Delete ${item.reference}?`}
                message="It disappears from the register, but stays in backups and the audit trail records the deletion."
                confirmLabel="Delete"
                onConfirm={() => router.delete(base)}
                onCancel={() => setDeleting(false)}
            />
        </DocsLayout>
    );
}
