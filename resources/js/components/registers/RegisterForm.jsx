import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export const humanize = (value) => (value ? String(value).replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase()) : '');

const control = 'mt-1 w-full rounded-sm border border-border bg-surface px-2 py-1.5 text-sm text-foreground';

// <input type="datetime-local"> needs "YYYY-MM-DDTHH:mm".
const forInput = (field, value) => (field.type === 'datetime' && value ? String(value).slice(0, 16) : value ?? '');

export default function RegisterForm({ type, config, users, item = null, onCancel }) {
    const [form, setForm] = useState({
        title: item?.title ?? '',
        status: item?.status ?? config.statuses[0],
        owner_id: item?.owner_id ?? '',
        due_at: item?.due_at ?? '',
        data: Object.fromEntries(config.fields.map((f) => [f.key, forInput(f, item?.data?.[f.key])])),
    });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    const setField = (key, value) => setForm((f) => ({ ...f, [key]: value }));
    const setData = (key, value) => setForm((f) => ({ ...f, data: { ...f.data, [key]: value } }));

    function save(e) {
        e.preventDefault();
        setBusy(true);
        const payload = {
            ...form,
            owner_id: form.owner_id || null,
            due_at: form.due_at || null,
            data: Object.fromEntries(Object.entries(form.data).map(([k, v]) => [k, v === '' ? null : v])),
        };
        const options = { preserveScroll: true, onError: setErrors, onFinish: () => setBusy(false) };
        if (item) router.patch(`/registers/${type}/${item.id}`, payload, options);
        else router.post(`/registers/${type}`, payload, options);
    }

    function fieldInput(field) {
        const value = form.data[field.key];
        const onChange = (e) => setData(field.key, e.target.value);

        if (field.type === 'textarea') return <textarea rows={3} value={value} onChange={onChange} className={control} />;
        if (field.type === 'select') {
            return (
                <select value={value} onChange={onChange} className={`ui-select ${control}`}>
                    <option value="">Choose…</option>
                    {field.options.map((o) => <option key={o} value={o}>{humanize(o)}</option>)}
                </select>
            );
        }
        if (field.type === 'date') return <input type="date" value={value} onChange={onChange} className={control} />;
        if (field.type === 'datetime') return <input type="datetime-local" value={value} onChange={onChange} className={control} />;
        return <Input className="mt-1" value={value} onChange={onChange} />;
    }

    return (
        <form onSubmit={save} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label className="text-xs text-text-secondary sm:col-span-2">
                Title
                <Input required className="mt-1" value={form.title} onChange={(e) => setField('title', e.target.value)} />
                {errors.title && <span className="mt-1 block text-danger">{errors.title}</span>}
            </label>
            <label className="text-xs text-text-secondary">
                Status
                <select value={form.status} onChange={(e) => setField('status', e.target.value)} className={`ui-select ${control}`}>
                    {config.statuses.map((s) => <option key={s} value={s}>{humanize(s)}</option>)}
                </select>
            </label>
            <label className="text-xs text-text-secondary">
                Owner
                <select value={form.owner_id} onChange={(e) => setField('owner_id', e.target.value)} className={`ui-select ${control}`}>
                    <option value="">Unassigned</option>
                    {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                </select>
            </label>
            <label className="text-xs text-text-secondary">
                Due or next review
                <input type="date" value={form.due_at} onChange={(e) => setField('due_at', e.target.value)} className={control} />
            </label>
            {config.fields.map((field) => (
                <label key={field.key} className={`text-xs text-text-secondary ${field.type === 'textarea' ? 'sm:col-span-2' : ''}`}>
                    {field.label}
                    {fieldInput(field)}
                    {field.help && <span className="mt-1 block text-text-tertiary">{field.help}</span>}
                    {errors[`data.${field.key}`] && <span className="mt-1 block text-danger">{errors[`data.${field.key}`]}</span>}
                </label>
            ))}
            <div className="flex justify-end gap-2 sm:col-span-2">
                {onCancel && <Button type="button" variant="outline" onClick={onCancel}>Cancel</Button>}
                <Button type="submit" disabled={busy}>Save</Button>
            </div>
        </form>
    );
}
