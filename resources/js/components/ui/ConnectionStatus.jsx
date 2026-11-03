import { IconCircleCheck, IconAlertTriangle, IconCircleDot } from '@tabler/icons-react';
import { timeAgo } from '@/lib/date';

/**
 * Passive, at-a-glance status for a connection-based setting (SMTP, SMB share).
 * Shows whether it's configured and the outcome of the last on-demand test —
 * it never probes on its own (that's the explicit "Test connection" button's
 * job). `status` is the persisted { ok, at, message } from the server, or null
 * when it has never been tested. Saving the settings clears `status` server-side,
 * so a green "verified" only ever reflects the config since the last save.
 */
export default function ConnectionStatus({ configured, status, className = '' }) {
    let tone = 'text-text-tertiary';
    let Icon = IconCircleDot;
    let text = 'Not configured';
    let title;

    if (configured && !status) {
        tone = 'text-text-secondary';
        text = 'Configured · not tested yet';
    } else if (configured && status?.ok) {
        tone = 'text-success-text';
        Icon = IconCircleCheck;
        text = `Connection verified · ${timeAgo(status.at) ?? ''}`.trim();
    } else if (configured && status) {
        tone = 'text-danger';
        Icon = IconAlertTriangle;
        text = `Last test failed · ${timeAgo(status.at) ?? ''}`.trim();
        title = status.message ?? undefined;
    }

    return (
        <span className={`inline-flex items-center gap-1.5 text-xs font-medium ${tone} ${className}`} title={title}>
            <Icon className="h-3.5 w-3.5 shrink-0" stroke={1.5} aria-hidden />
            {text}
        </span>
    );
}
