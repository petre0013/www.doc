import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import DocsLayout from '@/Layouts/DocsLayout';
import { Button } from '@/components/ui/button';
import RegisterForm, { humanize } from '@/components/registers/RegisterForm';
import { formatDate } from '@/lib/date';

export default function RegisterIndex({ type, config, users, items }) {
    const [adding, setAdding] = useState(false);

    return (
        <DocsLayout>
            <Head title={config.label} />
            <p className="text-sm text-text-secondary"><Link href="/registers" className="hover:text-foreground">Registers</Link> / {config.label}</p>
            <div className="mt-1 flex items-center justify-between gap-3">
                <h1 className="text-[19px] font-semibold text-foreground">{config.label}</h1>
                <Button onClick={() => setAdding(true)}>Add {config.singular}</Button>
            </div>

            {adding && (
                <div className="mt-4 rounded-md border border-border bg-card p-4">
                    <RegisterForm type={type} config={config} users={users} onCancel={() => setAdding(false)} />
                </div>
            )}

            {items.length === 0 ? (
                <p className="mt-6 rounded-md border border-border bg-card px-4 py-10 text-center text-sm text-text-secondary">Nothing recorded yet.</p>
            ) : (
                <div className="mt-4 overflow-x-auto rounded-md border border-border bg-card">
                    <table className="w-full min-w-[640px] text-left text-sm">
                        <thead className="border-b border-border text-xs text-text-tertiary">
                            <tr>{['Ref', 'Title', 'Status', 'Owner', 'Due'].concat(type === 'risk' ? ['Score'] : []).map((h) => <th key={h} className="px-3 py-2 font-medium">{h}</th>)}</tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {items.map((item) => (
                                <tr key={item.id}>
                                    <td className="whitespace-nowrap px-3 py-2 text-text-secondary">{item.reference}</td>
                                    <td className="px-3 py-2"><Link href={`/registers/${type}/${item.id}`} className="font-medium text-foreground hover:underline">{item.title}</Link></td>
                                    <td className="px-3 py-2 text-text-secondary">{humanize(item.status)}</td>
                                    <td className="px-3 py-2 text-text-secondary">{item.owner ?? '—'}</td>
                                    <td className={`whitespace-nowrap px-3 py-2 ${item.overdue ? 'font-medium text-danger' : 'text-text-secondary'}`}>{item.due_at ? formatDate(item.due_at) : '—'}</td>
                                    {type === 'risk' && <td className="px-3 py-2 text-text-secondary">{item.computed.score ?? '—'}{item.computed.residual_score != null && ` → ${item.computed.residual_score}`}</td>}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </DocsLayout>
    );
}
