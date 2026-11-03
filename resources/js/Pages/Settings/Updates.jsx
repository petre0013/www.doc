import { useEffect, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { toast } from 'sonner';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { IconExternalLink, IconRefresh, IconLoader2 } from '@tabler/icons-react';

// "dev" stays as-is; a real tag renders as "v1.4.0" (with or without a leading v).
const showVer = (v) => (!v || v === 'dev' ? 'dev' : `v${String(v).replace(/^v/i, '')}`);

const fmtDate = (iso) => {
    if (!iso) return null;
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? null : d.toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    });
};

function Row({ label, children }) {
    return (
        <div className="flex items-center justify-between gap-4 border-t border-border py-2 text-sm first:border-t-0">
            <span className="text-text-secondary">{label}</span>
            <span className="text-right font-medium text-foreground">{children}</span>
        </div>
    );
}

const POLL_MS = 2500;
const POLL_LIMIT = 12; // ~30s, the job's own timeout; failed checks never bump checked_at
const COOLDOWN_MS = 10000; // after a completed check, hold the button briefly (courtesy to GitHub's API)

export default function Updates({ status, notesHtml, releasesUrl, system }) {
    const [enabled, setEnabled] = useState(status.enabled);
    const [checking, setChecking] = useState(false);
    const [coolingDown, setCoolingDown] = useState(false);
    // Inline result of the last completed check — replaces a toast, which
    // used to repeat on every poll's Inertia visit (the redundant-toast bug).
    const [result, setResult] = useState(null); // { available, version } | null
    const baseline = useRef(null); // checked_at as of the click
    const polls = useRef(0);
    const cooldownTimer = useRef(null);

    useEffect(() => () => clearTimeout(cooldownTimer.current), []);

    const toggle = (next) => {
        setEnabled(next); // optimistic; the page reloads its props on the redirect
        router.patch('/admin/settings/updates', { enabled: next }, { preserveScroll: true });
    };

    // The check runs on the queue, so the POST returns before it finishes.
    // Poll the props until checked_at moves past its pre-click value.
    useEffect(() => {
        if (!checking) return;
        const timer = setInterval(() => {
            if (polls.current >= POLL_LIMIT) {
                setChecking(false);
                toast.error("The check didn't finish — the instance may be offline, or the background worker may not be running.");
                return;
            }
            polls.current += 1;
            router.reload({ only: ['status', 'notesHtml'] });
        }, POLL_MS);
        return () => clearInterval(timer);
    }, [checking]);

    // Say what the check found — inline, not a toast (the button's own
    // "Checking…" state already told the admin it was running).
    useEffect(() => {
        if (checking && status.checked_at && status.checked_at !== baseline.current) {
            setChecking(false);
            setResult({ available: status.update_available, version: status.latest });
            setCoolingDown(true);
            cooldownTimer.current = setTimeout(() => setCoolingDown(false), COOLDOWN_MS);
        }
    }, [checking, status.checked_at]);

    const checkNow = () => {
        baseline.current = status.checked_at;
        polls.current = 0;
        setResult(null);
        setChecking(true);
        router.post('/admin/settings/updates/check', {}, {
            preserveScroll: true,
            onError: () => setChecking(false),
        });
    };

    return (
        <SettingsLayout>
            <Head title="Updates" />
            <div className="space-y-5">
                {/* Opt-in release check + its status */}
                <Card>
                    <CardHeader>
                        <CardTitle>Update checks</CardTitle>
                        <CardDescription>
                            Notify admins when this instance is behind the latest release.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="text-sm font-medium text-foreground">Check for new releases</p>
                                <p className="mt-0.5 text-xs text-text-secondary">
                                    Reads public release information from GitHub on a daily schedule.
                                    Sends nothing about this instance; off by default.
                                </p>
                            </div>
                            <Switch checked={enabled} onCheckedChange={toggle} className="mt-0.5" />
                        </div>

                        {status.is_dev ? (
                            <p className="rounded-md bg-surface-hover px-3 py-2 text-xs text-text-secondary">
                                This is a development build — checks run only on tagged releases.
                            </p>
                        ) : enabled && (
                            <div className="rounded-md border border-border px-3 py-1">
                                <Row label="Installed">{showVer(status.current)}</Row>
                                <Row label="Latest">
                                    <span className="inline-flex items-center gap-2">
                                        {status.latest ? showVer(status.latest) : '—'}
                                        {status.update_available && (
                                            <span className="rounded-full bg-accent-100 px-2 py-0.5 text-xs font-medium text-accent-700">
                                                Update available
                                            </span>
                                        )}
                                    </span>
                                </Row>
                                <Row label="Last checked">{fmtDate(status.checked_at) ?? 'Never'}</Row>
                                <div className="flex items-center justify-end gap-3 py-2">
                                    {result && !checking && (
                                        <p className="text-xs text-text-secondary">
                                            {result.available
                                                ? `Update available: ${showVer(result.version)}.`
                                                : "You're up to date."}
                                        </p>
                                    )}
                                    <Button variant="outline" size="sm" onClick={checkNow} disabled={checking || coolingDown}>
                                        {checking ? (
                                            <>
                                                <IconLoader2 className="h-4 w-4 animate-spin" stroke={1.5} />
                                                Checking…
                                            </>
                                        ) : (
                                            <>
                                                <IconRefresh className="h-4 w-4" stroke={1.5} />
                                                Check now
                                            </>
                                        )}
                                    </Button>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Latest release notes (cached from GitHub) */}
                <Card>
                    <CardHeader>
                        <CardTitle>Latest release</CardTitle>
                        <CardDescription>Release notes for the newest published version.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {notesHtml ? (
                            <>
                                <div className="flex items-baseline justify-between gap-4">
                                    <h3 className="text-sm font-semibold text-foreground">
                                        {status.latest_name || showVer(status.latest)}
                                    </h3>
                                    {fmtDate(status.published_at) && (
                                        <span className="shrink-0 text-xs text-text-tertiary">
                                            {fmtDate(status.published_at)}
                                        </span>
                                    )}
                                </div>
                                <div
                                    className="release-notes mt-3"
                                    dangerouslySetInnerHTML={{ __html: notesHtml }}
                                />
                                {status.latest_url && (
                                    <a
                                        href={status.latest_url}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                        className="mt-4 inline-flex items-center gap-1.5 text-sm text-accent-600 hover:underline"
                                    >
                                        View on GitHub <IconExternalLink className="h-4 w-4" stroke={1.5} />
                                    </a>
                                )}
                            </>
                        ) : (
                            <p className="text-sm text-text-secondary">
                                {status.is_dev
                                    ? 'Release notes appear on tagged builds once a check has run.'
                                    : enabled
                                        ? 'No release notes cached yet — run a check, or the instance may be offline.'
                                        : 'Enable update checks to fetch the latest release notes.'}{' '}
                                <a
                                    href={releasesUrl}
                                    target="_blank"
                                    rel="noreferrer noopener"
                                    className="inline-flex items-center gap-1 text-accent-600 hover:underline"
                                >
                                    View all releases <IconExternalLink className="h-3.5 w-3.5" stroke={1.5} />
                                </a>
                            </p>
                        )}
                    </CardContent>
                </Card>

                {/* System info */}
                <Card>
                    <CardHeader>
                        <CardTitle>System</CardTitle>
                    </CardHeader>
                    <CardContent className="px-4 py-1">
                        <Row label="Instance">{system.instance}</Row>
                        <Row label="App version">{showVer(system.app_version)}</Row>
                        <Row label="PHP">{system.php}</Row>
                        <Row label="Laravel">{system.laravel}</Row>
                    </CardContent>
                </Card>
            </div>
        </SettingsLayout>
    );
}
