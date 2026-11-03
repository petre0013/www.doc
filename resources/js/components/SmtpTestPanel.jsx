import { IconCheck, IconX, IconMinus } from '@tabler/icons-react';

// Stage labels for the staged SMTP connection report (SmtpProbe on the
// server). Presentation lives here, mirroring the auditEvents.jsx pattern —
// the server sends only stage codes + status + detail sentences.
const STAGE_LABELS = {
    dns: 'DNS lookup',
    connect: 'TCP connection',
    tls: 'Encryption (TLS)',
    send: 'Authenticate & send',
};

// A failed detail often carries the raw transport text as a "(raw: …)"
// suffix (SmtpProbe / ErrorClassifier convention); split it into the
// collapsible so the sentence stays readable and the forensics stay one
// click away.
function splitRaw(detail) {
    const m = detail.match(/\s*\(raw:\s([\s\S]*)\)\s*$/);
    return m ? { main: detail.slice(0, m.index), raw: m[1] } : { main: detail, raw: null };
}

/**
 * Inline result panel for "Send test email": one ✓/✗/– row per connection
 * stage. Renders under the test button and persists while the admin edits
 * fields — the durable counterpart to the transient toast. `result` is the
 * `flash.smtpTest` prop ({ stages: [{stage, status, detail}], endpoint }).
 */
export default function SmtpTestPanel({ result }) {
    if (!result?.stages?.length) return null;

    return (
        <div className="mt-3 rounded-md border border-border bg-surface p-3">
            <p className="text-[11px] font-semibold uppercase tracking-wider text-text-tertiary">
                Connection check — {result.endpoint}
                {result.transport === 'global' && ' (global Email settings)'}
                {result.transport === 'own' && ' (backup-specific SMTP)'}
            </p>
            <ul className="mt-2 space-y-1.5">
                {result.stages.map((s) => (
                    <StageRow key={s.stage} stage={s} />
                ))}
            </ul>
        </div>
    );
}

function StageRow({ stage }) {
    const { main, raw } = splitRaw(stage.detail);
    const failed = stage.status === 'failed';

    const icon = stage.status === 'ok'
        ? <IconCheck size={15} stroke={2} className="mt-0.5 shrink-0 text-success" aria-hidden="true" />
        : failed
            ? <IconX size={15} stroke={2} className="mt-0.5 shrink-0 text-danger" aria-hidden="true" />
            : <IconMinus size={15} stroke={1.5} className="mt-0.5 shrink-0 text-text-tertiary" aria-hidden="true" />;

    return (
        <li className="flex items-start gap-2 text-[13px] leading-relaxed">
            {icon}
            <div className="min-w-0">
                <span className={failed ? 'font-medium text-danger' : 'font-medium text-foreground'}>
                    {STAGE_LABELS[stage.stage] ?? stage.stage}
                </span>
                <span className="sr-only">{stage.status === 'ok' ? 'passed' : stage.status}</span>
                <span className={failed ? 'text-danger/90' : 'text-text-secondary'}> — {main}</span>
                {raw && (
                    <details className="mt-1">
                        <summary className="cursor-pointer text-xs text-text-tertiary hover:text-text-secondary">
                            Technical details
                        </summary>
                        <pre className="mt-1 whitespace-pre-wrap break-all rounded-sm border border-border-subtle bg-surface-hover/60 p-2 font-mono text-xs text-text-secondary">
                            {raw}
                        </pre>
                    </details>
                )}
            </div>
        </li>
    );
}
