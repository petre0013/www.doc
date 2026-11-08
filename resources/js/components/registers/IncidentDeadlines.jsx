import { formatDateTime } from '@/lib/date';

export default function IncidentDeadlines({ deadlines }) {
    if (!deadlines?.length) return null;

    return (
        <ul className="space-y-1 text-xs">
            {deadlines.map((d) => (
                <li key={d.key} className={d.late ? 'font-medium text-danger' : 'text-text-secondary'}>
                    {d.label}: {d.due ? `due ${formatDateTime(d.due)}` : 'due one month after the notification'}
                    {d.sent ? ` · sent ${formatDateTime(d.sent)}` : ' · not sent'}
                    {d.late && ' · late'}
                </li>
            ))}
        </ul>
    );
}
