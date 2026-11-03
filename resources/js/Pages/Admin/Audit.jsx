import { Head, router, usePage } from '@inertiajs/react';
import { IconArrowRight, IconChevronLeft, IconChevronRight, IconHistory } from '@tabler/icons-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { avatarStyle, initials } from '@/lib/avatar';
import { describeEvent, namespaceIcon, namespaceLabel } from '@/lib/auditEvents.jsx';

function formatDate(iso) {
    const d = new Date(iso);
    return d.toLocaleString(undefined, {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

function FilterSelect({ value, onChange, children }) {
    return (
        <select
            value={value ?? ''}
            onChange={(e) => onChange(e.target.value || null)}
            className="ui-select h-8 rounded-sm border border-border bg-surface px-2 text-sm text-foreground"
        >
            {children}
        </select>
    );
}

export default function Audit() {
    const { events, filters, users, workspaces, eventTypes } = usePage().props;

    function applyFilters(patch) {
        const next = { ...filters, ...patch };
        // Drop empty filters so the URL stays clean; changing filters resets paging.
        const params = Object.fromEntries(Object.entries(next).filter(([, v]) => v != null && v !== ''));
        router.get('/admin/audit', params, { preserveState: true, preserveScroll: true });
    }

    return (
        <SettingsLayout>
            <Head title="Audit — Admin" />

            <Card>
                <CardHeader>
                    <CardTitle className="text-sm font-semibold text-foreground">Audit trail</CardTitle>
                    <CardDescription>
                        Who changed, restored or deleted content — and when. Events are
                        append-only and pruned after the retention window.
                    </CardDescription>
                </CardHeader>

                <CardContent className="p-0">
                    {/* Filters */}
                    <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                        <FilterSelect value={filters.user} onChange={(v) => applyFilters({ user: v })}>
                            <option value="">All users</option>
                            {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                        </FilterSelect>

                        <FilterSelect value={filters.event} onChange={(v) => applyFilters({ event: v })}>
                            <option value="">All events</option>
                            {eventTypes.map((ns) => <option key={ns} value={ns}>{namespaceLabel(ns)}</option>)}
                        </FilterSelect>

                        <FilterSelect value={filters.workspace} onChange={(v) => applyFilters({ workspace: v })}>
                            <option value="">All workspaces</option>
                            {workspaces.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                        </FilterSelect>

                        <div className="ml-auto flex items-center gap-2">
                            <input
                                type="date"
                                value={filters.from ?? ''}
                                onChange={(e) => applyFilters({ from: e.target.value || null })}
                                className="h-8 rounded-sm border border-border bg-surface px-2 text-sm text-foreground"
                            />
                            <span className="text-xs text-text-tertiary">to</span>
                            <input
                                type="date"
                                value={filters.to ?? ''}
                                onChange={(e) => applyFilters({ to: e.target.value || null })}
                                className="h-8 rounded-sm border border-border bg-surface px-2 text-sm text-foreground"
                            />
                        </div>
                    </div>

                    {/* Event rows */}
                    {events.data.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-4 py-12 text-center">
                            <IconHistory className="h-6 w-6 text-text-tertiary" stroke={1.5} />
                            <p className="text-sm text-text-secondary">No audit events match these filters.</p>
                        </div>
                    ) : (
                        <div className="divide-y divide-border">
                            {events.data.map((event) => {
                                const { text, plainText, actorless, change, toneClass } = describeEvent(event.event, event.context);
                                const NsIcon = namespaceIcon(event.event);

                                return (
                                    <div key={event.id} className="flex items-center gap-3 px-4 py-2.5">
                                        <div
                                            className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold ${event.user ? '' : 'bg-surface-hover text-text-tertiary'}`}
                                            style={event.user ? avatarStyle(event.user.avatar_color) : undefined}
                                            title={event.user?.name ?? 'System'}
                                        >
                                            {event.user
                                                ? initials(event.user.name)
                                                : <NsIcon className="h-3.5 w-3.5" stroke={1.5} aria-hidden="true" />}
                                        </div>

                                        <div className="min-w-0 flex-1">
                                            {/* Human sentence first; the actor is bold, the phrase comes from the event map. */}
                                            <p className="truncate text-sm text-foreground" title={`${actorless ? '' : (event.user?.name ?? 'System') + ' '}${plainText}`}>
                                                {!actorless && (
                                                    <span className="font-medium">{event.user?.name ?? 'System'} </span>
                                                )}
                                                {text}
                                                {change && (change.from || change.to) && (
                                                    <span className="ml-1.5 inline-flex items-center gap-1 align-middle text-xs">
                                                        <span className="rounded-full bg-surface-hover px-1.5 py-0.5 text-text-secondary">{change.from ?? '—'}</span>
                                                        <IconArrowRight className="h-3 w-3 text-text-tertiary" stroke={1.5} aria-hidden="true" />
                                                        <span className="rounded-full bg-accent-100 px-1.5 py-0.5 text-accent-600">{change.to ?? '—'}</span>
                                                    </span>
                                                )}
                                            </p>
                                            <p className="mt-0.5 text-xs text-text-tertiary">
                                                {formatDate(event.created_at)}
                                                {event.ip ? ` · ${event.ip}` : ''}
                                            </p>
                                        </div>

                                        {/* The stable machine code — the contract auditors filter and quote by. */}
                                        <span className={`shrink-0 rounded-full px-2 py-0.5 font-mono text-[10px] font-medium ${toneClass}`}>
                                            {event.event}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* Pager */}
            {(events.prev_page_url || events.next_page_url) && (
                <div className="mt-4 flex items-center justify-between">
                    <p className="text-xs text-text-tertiary">
                        {events.total} events · page {events.current_page} of {events.last_page}
                    </p>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            disabled={!events.prev_page_url}
                            onClick={() => router.get(events.prev_page_url, {}, { preserveState: true, preserveScroll: true })}
                            className="flex h-8 items-center gap-1 rounded-sm border border-border bg-surface px-2.5 text-sm text-foreground disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <IconChevronLeft className="h-4 w-4" stroke={1.5} /> Newer
                        </button>
                        <button
                            type="button"
                            disabled={!events.next_page_url}
                            onClick={() => router.get(events.next_page_url, {}, { preserveState: true, preserveScroll: true })}
                            className="flex h-8 items-center gap-1 rounded-sm border border-border bg-surface px-2.5 text-sm text-foreground disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            Older <IconChevronRight className="h-4 w-4" stroke={1.5} />
                        </button>
                    </div>
                </div>
            )}
        </SettingsLayout>
    );
}
