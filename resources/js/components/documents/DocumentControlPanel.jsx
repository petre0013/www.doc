import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { IconAlertTriangle, IconShieldCheck } from '@tabler/icons-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, formatDateTime } from '@/lib/date';

export const STATUS_LABELS = { draft: 'Draft', in_review: 'In review', approved: 'Approved', retired: 'Retired' };

const ACTION_LABELS = { submitted: 'Submitted', approved: 'Approved', rejected: 'Sent back', reviewed: 'Reviewed', retired: 'Retired' };

const selectClass = 'ui-select mt-1 h-9 w-full rounded-sm border border-border bg-surface px-2 text-sm capitalize text-foreground';

function Details({ documentId, control, options, onDone }) {
    const [form, setForm] = useState({
        doc_number: control?.doc_number ?? '',
        owner_id: control?.owner_id ?? '',
        approver_id: control?.approver_id ?? '',
        classification: control?.classification ?? 'internal',
        review_interval_months: control?.review_interval_months ?? 12,
        ack_required: control?.ack?.required ?? false,
    });
    const [errors, setErrors] = useState({});
    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

    function save(e) {
        e.preventDefault();
        const send = control ? router.patch : router.post;
        send(`/documents/${documentId}/control`, { ...form, doc_number: form.doc_number || null }, {
            preserveScroll: true,
            onSuccess: onDone,
            onError: setErrors,
        });
    }

    const person = (key, label) => (
        <label className="text-xs text-text-secondary">
            {label}
            <select required value={form[key]} onChange={set(key)} className={selectClass}>
                <option value="">Choose…</option>
                {options.users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </select>
            {errors[key] && <span className="mt-1 block text-danger">{errors[key]}</span>}
        </label>
    );

    return (
        <form onSubmit={save} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {person('owner_id', 'Owner')}
            {person('approver_id', 'Approver')}
            <label className="text-xs text-text-secondary">
                Classification
                <select value={form.classification} onChange={set('classification')} className={selectClass}>
                    {options.classifications.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
            </label>
            <label className="text-xs text-text-secondary">
                Review every (months)
                <Input type="number" min="1" max="60" className="mt-1" value={form.review_interval_months} onChange={set('review_interval_months')} />
                {errors.review_interval_months && <span className="mt-1 block text-danger">{errors.review_interval_months}</span>}
            </label>
            <label className="text-xs text-text-secondary sm:col-span-2">
                Document ID (blank to generate)
                <Input className="mt-1" value={form.doc_number} onChange={set('doc_number')} />
                {errors.doc_number && <span className="mt-1 block text-danger">{errors.doc_number}</span>}
            </label>
            <label className="flex items-center gap-2 text-xs text-text-secondary sm:col-span-2">
                <input type="checkbox" checked={form.ack_required} onChange={(e) => setForm((f) => ({ ...f, ack_required: e.target.checked }))} />
                Require everyone with access to confirm they've read the approved version
            </label>
            <div className="flex justify-end gap-2 sm:col-span-2">
                <Button type="button" variant="outline" onClick={onDone}>Cancel</Button>
                <Button type="submit">Save</Button>
            </div>
        </form>
    );
}

export default function DocumentControlPanel({ documentId, control, options }) {
    const [editing, setEditing] = useState(false);
    const [step, setStep] = useState(null);
    const [comment, setComment] = useState('');

    if (!control) {
        if (!options) return null;

        return editing ? (
            <div className="mt-3 rounded-md border border-border bg-card p-4">
                <Details documentId={documentId} control={null} options={options} onDone={() => setEditing(false)} />
            </div>
        ) : (
            <button type="button" onClick={() => setEditing(true)} className="mt-3 inline-flex items-center gap-1 text-xs text-text-tertiary hover:text-foreground">
                <IconShieldCheck className="h-3.5 w-3.5" stroke={1.5} />
                Place under document control
            </button>
        );
    }

    function run() {
        router.post(`/documents/${documentId}/control/${step.key}`, { comment: comment || null }, {
            preserveScroll: true,
            onSuccess: () => { setStep(null); setComment(''); },
        });
    }

    const can = control.can;
    const steps = [
        can.submit && { key: 'submit', label: 'Submit for approval', confirm: 'Submit' },
        can.decide && { key: 'approve', label: 'Approve', confirm: 'Approve this version' },
        can.decide && { key: 'reject', label: 'Send back', confirm: 'Send back', needsComment: true },
        can.review && { key: 'review', label: 'Mark reviewed', confirm: 'Record review' },
        can.retire && { key: 'retire', label: 'Retire', confirm: 'Retire this document' },
    ].filter(Boolean);

    const facts = [
        control.classification,
        control.owner && `Owner: ${control.owner.name}`,
        control.approver && `Approver: ${control.approver.name}`,
        control.approved_at && `Approved ${formatDate(control.approved_at)}`,
        control.next_review_at && `Review by ${formatDate(control.next_review_at)}`,
    ].filter(Boolean);

    return (
        <div className="mt-3 rounded-md border border-border bg-card p-4 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <IconShieldCheck className="h-4 w-4 text-text-tertiary" stroke={1.5} />
                <span className="font-medium text-foreground">{control.doc_number}</span>
                <span className="rounded-full bg-accent-100 px-2 py-0.5 text-xs font-medium text-accent-700">{STATUS_LABELS[control.status]}</span>
                {control.overdue && <span className="rounded-full bg-danger-surface px-2 py-0.5 text-xs font-medium text-danger">Review overdue</span>}
                {can.manage && options && (
                    <button type="button" onClick={() => setEditing((v) => !v)} className="ml-auto text-xs text-text-tertiary hover:text-foreground">Edit details</button>
                )}
            </div>

            <p className="mt-1.5 text-xs text-text-secondary">{facts.join(' · ')}</p>

            {control.serving_approved && (
                <p className="mt-2 text-xs text-text-secondary">You're reading the approved version. Newer edits are still a draft.</p>
            )}
            {!control.serving_approved && control.has_unapproved_changes && (
                <p className="mt-2 text-xs text-text-secondary">
                    This page has changes that aren't approved yet. Readers still see the{' '}
                    <Link className="underline" href={`/documents/${documentId}/versions/${control.approved_version_id}`}>approved version</Link>.
                </p>
            )}
            {control.pending_version_id && (
                <p className="mt-2 text-xs text-text-secondary">
                    Awaiting approval:{' '}
                    <Link className="underline" href={`/documents/${documentId}/versions/${control.pending_version_id}`}>view the submitted version</Link>
                    {control.approved_version_id && (
                        <>{' · '}<Link className="underline" href={`/documents/${documentId}/versions/compare?from=${control.approved_version_id}&to=${control.pending_version_id}`}>compare with approved</Link></>
                    )}
                </p>
            )}
            {!control.hash_intact && (
                <p className="mt-2 flex items-center gap-1 text-xs font-medium text-danger">
                    <IconAlertTriangle className="h-3.5 w-3.5" stroke={1.5} />
                    The stored approved version no longer matches what was signed off.
                </p>
            )}

            {editing && options && (
                <div className="mt-3 border-t border-border pt-3">
                    <Details documentId={documentId} control={control} options={options} onDone={() => setEditing(false)} />
                </div>
            )}

            {steps.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-2">
                    {steps.map((s) => <Button key={s.key} variant="outline" size="sm" onClick={() => { setStep(s); setComment(''); }}>{s.label}</Button>)}
                </div>
            )}

            {step && (
                <div className="mt-3 space-y-2">
                    <Input value={comment} onChange={(e) => setComment(e.target.value)} maxLength={2000} placeholder={step.needsComment ? 'What needs to change? (required)' : 'Comment (optional)'} aria-label="Comment" />
                    <div className="flex gap-2">
                        <Button size="sm" disabled={step.needsComment && !comment.trim()} onClick={run}>{step.confirm}</Button>
                        <Button size="sm" variant="outline" onClick={() => setStep(null)}>Cancel</Button>
                    </div>
                </div>
            )}

            {control.ack.can && (
                <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-text-secondary">
                    <span>{control.ack.done} of {control.ack.total} have confirmed reading this version</span>
                    {control.ack.mine
                        ? <span className="font-medium text-foreground">You've confirmed</span>
                        : <Button size="sm" variant="outline" onClick={() => router.post(`/documents/${documentId}/control/acknowledge`, {}, { preserveScroll: true })}>I have read this</Button>}
                    {control.ack.missing.length > 0 && <span className="basis-full">Still to confirm: {control.ack.missing.join(', ')}</span>}
                </div>
            )}

            {control.events.length > 0 && (
                <details className="mt-3 text-xs text-text-secondary">
                    <summary className="cursor-pointer">Approval history</summary>
                    <ul className="mt-2 space-y-1.5">
                        {control.events.map((e) => (
                            <li key={e.id}>
                                <span className="font-medium text-foreground">{ACTION_LABELS[e.action]}</span> by {e.actor} · {formatDateTime(e.created_at)}
                                {e.hash && <span className="ml-1 font-mono text-text-tertiary" title={e.hash}>{e.hash.slice(0, 12)}</span>}
                                {e.comment && <span className="block">“{e.comment}”</span>}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}
