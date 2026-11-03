import { useState, useRef } from 'react';
import { Head, useForm, router, usePage } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import MailFields from '@/components/MailFields';
import SmtpTestPanel from '@/components/SmtpTestPanel';
import ConnectionStatus from '@/components/ui/ConnectionStatus';
import { isEmail } from '@/lib/utils';
import { IconMailFast, IconLoader2, IconCheck } from '@tabler/icons-react';

export default function Mail({ settings, testStatus }) {
    const smtpTest = usePage().props.flash?.smtpTest;
    const form = useForm({
        host: settings.host ?? '',
        port: settings.port ?? 587,
        encryption: settings.encryption ?? 'tls',
        verify_peer: settings.verify_peer ?? true,
        username: settings.username ?? '',
        password: '',
        from_address: settings.from_address ?? '',
        from_name: settings.from_name ?? '',
    });

    const [testTo, setTestTo] = useState('');
    const [testing, setTesting] = useState(false);

    function submit(e) {
        e.preventDefault();
        form.patch('/admin/settings/mail', { preserveScroll: true });
    }

    // The server flashes success/error, surfaced as a toast app-wide (DocsLayout),
    // so there's no inline result here — just drive the spinner.
    function sendTest() {
        setTesting(true);
        router.post('/admin/settings/mail/test', { ...form.data, to: testTo }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setTesting(false),
        });
    }

    const mailReady = ['host', 'port'].every((f) => String(form.data[f] ?? '').trim() !== '')
        && isEmail(form.data.from_address);

    // Warn before leaving (in-app nav or browser close) with unsaved settings.
    const dirtyRef = useRef(false);
    dirtyRef.current = form.isDirty;
    const { promptOpen, confirmDiscard, dismissPrompt } = useUnsavedChangesGuard({
        active: true,
        dirtyRef,
        revert: () => form.reset(),
    });

    return (
        <SettingsLayout>
            <Head title="Email settings" />
            <form onSubmit={submit} className="space-y-5">
                <Card>
                    <CardHeader>
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <CardTitle>Email (SMTP)</CardTitle>
                                <CardDescription>
                                    The mail server the app sends through — password resets and notifications.
                                </CardDescription>
                            </div>
                            <ConnectionStatus
                                configured={String(settings.host ?? '').trim() !== ''}
                                status={testStatus}
                                className="mt-0.5 shrink-0"
                            />
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <MailFields
                            data={form.data}
                            setField={(name, value) => form.setData(name, value)}
                            errors={form.errors}
                            passwordSet={settings.password_set}
                        />

                        <div className="rounded-md border border-border bg-surface-hover/40 p-4">
                            <Label htmlFor="mail-test-to">Send a test email to</Label>
                            <div className="mt-1 flex items-center gap-2.5">
                                <Input id="mail-test-to" type="email" value={testTo}
                                    onChange={(e) => setTestTo(e.target.value)}
                                    placeholder="you@company.com" className="max-w-xs" />
                                <Button type="button" variant="outline" onClick={sendTest}
                                    disabled={testing || !mailReady || !isEmail(testTo)}>
                                    {testing
                                        ? <IconLoader2 className="h-3.5 w-3.5 animate-spin" stroke={1.5} />
                                        : <IconMailFast className="h-3.5 w-3.5" stroke={1.5} />}
                                    {testing ? 'Sending…' : 'Send test'}
                                </Button>
                            </div>
                            {testTo && !isEmail(testTo) && (
                                <p className="mt-1.5 text-xs text-danger">Enter a valid email address.</p>
                            )}
                            {!mailReady && (
                                <p className="mt-2 text-xs text-text-tertiary">Fill in the host, port and from address first.</p>
                            )}
                            <SmtpTestPanel result={smtpTest} />
                        </div>
                    </CardContent>
                </Card>

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing || !form.isDirty}>
                        {form.processing
                            ? <IconLoader2 className="h-3.5 w-3.5 animate-spin" stroke={1.5} />
                            : form.recentlySuccessful
                            ? <IconCheck className="h-3.5 w-3.5" stroke={1.5} />
                            : null}
                        {form.processing ? 'Saving…' : form.recentlySuccessful ? 'Saved' : 'Save changes'}
                    </Button>
                </div>
            </form>

            <ConfirmDialog
                open={promptOpen}
                title="Discard changes?"
                message="You have unsaved email settings. Leaving this page will discard them."
                confirmLabel="Discard changes"
                cancelLabel="Keep editing"
                variant="danger"
                onConfirm={confirmDiscard}
                onCancel={dismissPrompt}
            />
        </SettingsLayout>
    );
}
