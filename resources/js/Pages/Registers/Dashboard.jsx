import { Head, Link, router } from '@inertiajs/react';
import DocsLayout from '@/Layouts/DocsLayout';
import { Button } from '@/components/ui/button';
import IncidentDeadlines from '@/components/registers/IncidentDeadlines';
import { humanize } from '@/components/registers/RegisterForm';

export default function RegistersDashboard({ registers, requirements, incidents, catalogueInstalled }) {
    return (
        <DocsLayout>
            <Head title="Registers" />
            <h1 className="text-[19px] font-semibold text-foreground">Registers</h1>

            <div className="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                {registers.map((r) => (
                    <Link key={r.type} href={`/registers/${r.type}`} className="rounded-md border border-border bg-card p-3 hover:bg-surface-hover">
                        <p className="text-sm font-medium text-foreground">{r.label}</p>
                        <p className="mt-1 text-xs text-text-secondary">{r.open} open of {r.total}</p>
                        {r.overdue > 0 && <p className="text-xs font-medium text-danger">{r.overdue} overdue</p>}
                    </Link>
                ))}
            </div>

            {incidents.length > 0 && (
                <section className="mt-6">
                    <h2 className="text-sm font-semibold text-foreground">Open incidents</h2>
                    <div className="mt-2 divide-y divide-border rounded-md border border-border bg-card">
                        {incidents.map((i) => (
                            <div key={i.id} className="p-3">
                                <Link href={`/registers/incident/${i.id}`} className="text-sm font-medium text-foreground hover:underline">{i.reference} {i.title}</Link>
                                <div className="mt-1"><IncidentDeadlines deadlines={i.deadlines} /></div>
                            </div>
                        ))}
                    </div>
                </section>
            )}

            <section className="mt-6">
                <h2 className="text-sm font-semibold text-foreground">NIS2 requirements</h2>
                {catalogueInstalled ? (
                    <>
                        <p className="mt-1 text-sm text-text-secondary">
                            {Object.entries(requirements.by_status).map(([status, count]) => `${humanize(status)}: ${count}`).join(' · ')}
                        </p>
                        {requirements.no_evidence.length > 0 && (
                            <div className="mt-3 rounded-md border border-border bg-card p-3 text-sm">
                                <p className="font-medium text-foreground">Marked implemented with no linked evidence</p>
                                <ul className="mt-1 space-y-0.5">
                                    {requirements.no_evidence.map((r) => (
                                        <li key={r.id}><Link className="text-text-secondary underline" href={`/registers/requirement/${r.id}`}>{r.reference} {r.title}</Link></li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </>
                ) : (
                    <div className="mt-2">
                        <Button variant="outline" onClick={() => router.post('/registers/requirement/install', {}, { preserveScroll: true })}>Add the NIS2 requirements list</Button>
                    </div>
                )}
            </section>
        </DocsLayout>
    );
}
