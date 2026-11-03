import { useState } from 'react';
import { Head, useForm, router, usePage } from '@inertiajs/react';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/ui/PasswordInput';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import MailFields from '@/components/MailFields';
import SmtpTestPanel from '@/components/SmtpTestPanel';
import { isEmail } from '@/lib/utils';
import {
    IconCheck, IconLoader2, IconMailFast, IconArrowRight, IconArrowLeft,
} from '@tabler/icons-react';

const STEPS = ['Welcome', 'Administrator', 'Instance', 'Email', 'Finish'];

export default function Wizard({ adminConfigured, adminName, instanceName, mail, checkUpdates }) {
    const { props } = usePage();
    const [step, setStep] = useState(adminConfigured ? 2 : 0);
    const next = () => setStep((s) => Math.min(s + 1, STEPS.length - 1));
    const back = () => setStep((s) => Math.max(s - 1, 0));

    const adminForm = useForm({ name: '', email: '', password: '', password_confirmation: '' });
    const instanceForm = useForm({ name: instanceName || '', check_updates: checkUpdates ?? false });
    const mailForm = useForm({
        host: mail?.host ?? '',
        port: mail?.port ?? 587,
        encryption: mail?.encryption ?? 'tls',
        verify_peer: mail?.verify_peer ?? true,
        username: mail?.username ?? '',
        password: '',
        from_address: mail?.from_address ?? '',
        from_name: mail?.from_name ?? '',
    });

    const [testing, setTesting] = useState(false);
    const [testResult, setTestResult] = useState(null); // { ok, message }

    function submitAdmin(e) {
        e.preventDefault();
        adminForm.post('/setup/admin', { preserveState: true, preserveScroll: true, onSuccess: next });
    }

    function submitInstance(e) {
        e.preventDefault();
        instanceForm.post('/setup/instance', { preserveState: true, preserveScroll: true, onSuccess: next });
    }

    function submitMail(e) {
        e.preventDefault();
        mailForm.post('/setup/mail', { preserveState: true, preserveScroll: true, onSuccess: next });
    }

    const testRecipient = adminForm.data.email || mailForm.data.from_address;

    function sendTest() {
        setTesting(true);
        setTestResult(null);
        router.post('/setup/mail/test', { ...mailForm.data, to: testRecipient }, {
            preserveState: true,
            preserveScroll: true,
            // Both outcomes redirect back with a flash now (the stage panel
            // carries the diagnosis); onError only fires for validation.
            onSuccess: (page) => {
                const flash = page.props.flash || {};
                setTestResult(flash.error
                    ? { ok: false, message: flash.error }
                    : { ok: true, message: flash.success || 'Test email sent.' });
            },
            onError: () => setTestResult({ ok: false, message: 'Could not send the test email.' }),
            onFinish: () => setTesting(false),
        });
    }

    function finish() {
        router.post('/setup/complete', {}, { preserveScroll: true });
    }

    const mailReady = ['host', 'port'].every((f) => String(mailForm.data[f] ?? '').trim() !== '')
        && isEmail(mailForm.data.from_address) && isEmail(testRecipient);

    const a = adminForm.data;
    const passwordsMismatch = a.password_confirmation !== '' && a.password !== a.password_confirmation;
    const adminReady = a.name.trim() !== '' && isEmail(a.email)
        && a.password.length >= 8 && a.password === a.password_confirmation;

    return (
        <>
            <Head title="Set up www.doc" />
            <div className="min-h-screen flex items-center justify-center bg-background px-4 py-10">
                <div className="w-full max-w-xl">
                    {/* Step progress */}
                    <div className="mb-6 flex items-center justify-center gap-2">
                        {STEPS.map((label, i) => (
                            <div key={label} className="flex items-center gap-2">
                                <span className={`flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold ${
                                    i < step ? 'bg-accent-400 text-text-inverse'
                                        : i === step ? 'bg-accent-200 text-accent-700'
                                        : 'bg-surface-hover text-text-tertiary'
                                }`}>
                                    {i < step ? <IconCheck className="h-3.5 w-3.5" stroke={2} /> : i + 1}
                                </span>
                                {i < STEPS.length - 1 && <span className="h-px w-5 bg-border" />}
                            </div>
                        ))}
                    </div>

                    <Card>
                        <CardHeader className="px-8 pt-8 pb-0">
                            <CardTitle className="text-xl">
                                {step === 0 && (<><span className="font-normal">www.</span><span className="font-extrabold">doc</span> setup</>)}
                                {step === 1 && 'Create your administrator account'}
                                {step === 2 && 'Name this instance'}
                                {step === 3 && 'Email (SMTP) settings'}
                                {step === 4 && 'All set'}
                            </CardTitle>
                            <CardDescription>
                                {step === 0 && 'A few quick steps to get your knowledge base ready.'}
                                {step === 1 && 'This account has full administrative access.'}
                                {step === 2 && 'Shown in the app title and emails.'}
                                {step === 3 && 'Lets the app send password-reset emails. You can skip and configure this later.'}
                                {step === 4 && 'Your instance is ready to use.'}
                            </CardDescription>
                        </CardHeader>

                        <CardContent className="px-8 pb-8 pt-6">
                            {/* 0 — Welcome */}
                            {step === 0 && (
                                <div className="space-y-5">
                                    <p className="text-sm text-text-secondary">
                                        We'll create your admin account, name the instance, and set up email so
                                        password resets work out of the box.
                                    </p>
                                    <Button onClick={next} className="w-full">
                                        Get started <IconArrowRight className="h-4 w-4" stroke={1.5} />
                                    </Button>
                                </div>
                            )}

                            {/* 1 — Administrator */}
                            {step === 1 && (
                                <form onSubmit={submitAdmin} className="space-y-4">
                                    <div>
                                        <Label htmlFor="admin-name">Name</Label>
                                        <Input id="admin-name" value={adminForm.data.name}
                                            onChange={(e) => adminForm.setData('name', e.target.value)}
                                            autoComplete="name" className="mt-1" required />
                                        {adminForm.errors.name && <p className="mt-1 text-xs text-danger">{adminForm.errors.name}</p>}
                                    </div>
                                    <div>
                                        <Label htmlFor="admin-email">Email</Label>
                                        <Input id="admin-email" type="email" value={adminForm.data.email}
                                            onChange={(e) => adminForm.setData('email', e.target.value)}
                                            autoComplete="email" className="mt-1" required />
                                        {adminForm.errors.email
                                            ? <p className="mt-1 text-xs text-danger">{adminForm.errors.email}</p>
                                            : adminForm.data.email && !isEmail(adminForm.data.email) && (
                                                <p className="mt-1 text-xs text-danger">Enter a valid email address.</p>
                                            )}
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <Label htmlFor="admin-password">Password</Label>
                                            <PasswordInput id="admin-password" value={adminForm.data.password}
                                                onChange={(e) => adminForm.setData('password', e.target.value)}
                                                autoComplete="new-password" className="mt-1" required />
                                            {adminForm.errors.password && <p className="mt-1 text-xs text-danger">{adminForm.errors.password}</p>}
                                        </div>
                                        <div>
                                            <Label htmlFor="admin-password2">Confirm password</Label>
                                            <PasswordInput id="admin-password2" value={adminForm.data.password_confirmation}
                                                onChange={(e) => adminForm.setData('password_confirmation', e.target.value)}
                                                autoComplete="new-password" className="mt-1" required />
                                            {passwordsMismatch && <p className="mt-1 text-xs text-danger">Passwords don't match.</p>}
                                        </div>
                                    </div>
                                    <div className="flex justify-between pt-1">
                                        <Button type="button" variant="outline" onClick={back}>
                                            <IconArrowLeft className="h-4 w-4" stroke={1.5} /> Back
                                        </Button>
                                        <Button type="submit" disabled={adminForm.processing || !adminReady}>
                                            {adminForm.processing ? 'Saving…' : 'Continue'}
                                            <IconArrowRight className="h-4 w-4" stroke={1.5} />
                                        </Button>
                                    </div>
                                </form>
                            )}

                            {/* 2 — Instance name */}
                            {step === 2 && (
                                <form onSubmit={submitInstance} className="space-y-4">
                                    <div>
                                        <Label htmlFor="instance-name">Instance name</Label>
                                        <Input id="instance-name" value={instanceForm.data.name}
                                            onChange={(e) => instanceForm.setData('name', e.target.value)}
                                            placeholder="Acme Knowledge Base" className="mt-1" required />
                                        {instanceForm.errors.name && <p className="mt-1 text-xs text-danger">{instanceForm.errors.name}</p>}
                                    </div>
                                    <div className="flex items-start justify-between gap-4 rounded-md border border-border bg-surface-hover px-3 py-2.5">
                                        <div>
                                            <p className="text-sm font-medium text-foreground">Check for new releases</p>
                                            <p className="mt-0.5 text-xs text-text-secondary">
                                                Reads public release info from GitHub to notify you when an update is out.
                                                Sends nothing about this instance. You can change this later in Settings.
                                            </p>
                                        </div>
                                        <Switch checked={instanceForm.data.check_updates}
                                            onCheckedChange={(v) => instanceForm.setData('check_updates', v)}
                                            className="mt-0.5" />
                                    </div>
                                    <div className="flex justify-between pt-1">
                                        <Button type="button" variant="outline" onClick={back}>
                                            <IconArrowLeft className="h-4 w-4" stroke={1.5} /> Back
                                        </Button>
                                        <Button type="submit" disabled={instanceForm.processing}>
                                            {instanceForm.processing ? 'Saving…' : 'Continue'}
                                            <IconArrowRight className="h-4 w-4" stroke={1.5} />
                                        </Button>
                                    </div>
                                </form>
                            )}

                            {/* 3 — Email / SMTP */}
                            {step === 3 && (
                                <form onSubmit={submitMail} className="space-y-4">
                                    <MailFields
                                        data={mailForm.data}
                                        setField={(name, value) => mailForm.setData(name, value)}
                                        errors={mailForm.errors}
                                        passwordSet={mail?.password_set}
                                    />

                                    <div className="flex items-center gap-2.5">
                                        <Button type="button" variant="outline" onClick={sendTest} disabled={testing || !mailReady}>
                                            {testing
                                                ? <IconLoader2 className="h-3.5 w-3.5 animate-spin" stroke={1.5} />
                                                : <IconMailFast className="h-3.5 w-3.5" stroke={1.5} />}
                                            {testing ? 'Sending…' : 'Send test email'}
                                        </Button>
                                        {!mailReady && (
                                            <span className="text-xs text-text-tertiary">Fill in the host, port and from address.</span>
                                        )}
                                    </div>
                                    {testResult && (
                                        <p className={`text-xs ${testResult.ok ? 'text-accent-700' : 'text-danger'}`}>{testResult.message}</p>
                                    )}
                                    <SmtpTestPanel result={props.flash?.smtpTest} />

                                    <div className="flex justify-between pt-1">
                                        <Button type="button" variant="outline" onClick={back}>
                                            <IconArrowLeft className="h-4 w-4" stroke={1.5} /> Back
                                        </Button>
                                        <div className="flex items-center gap-2">
                                            <Button type="button" variant="ghost" onClick={next}>Skip for now</Button>
                                            <Button type="submit" disabled={mailForm.processing}>
                                                {mailForm.processing ? 'Saving…' : 'Save & continue'}
                                                <IconArrowRight className="h-4 w-4" stroke={1.5} />
                                            </Button>
                                        </div>
                                    </div>
                                </form>
                            )}

                            {/* 4 — Finish */}
                            {step === 4 && (
                                <div className="space-y-5">
                                    <p className="text-sm text-text-secondary">
                                        Click finish to sign in as <span className="font-medium text-foreground">{adminName || adminForm.data.name || 'the administrator'}</span> and
                                        start using your knowledge base.
                                    </p>
                                    <div className="flex items-center justify-between">
                                        <Button type="button" variant="outline" onClick={back}>
                                            <IconArrowLeft className="h-4 w-4" stroke={1.5} /> Back
                                        </Button>
                                        <Button onClick={finish}>
                                            Finish setup <IconCheck className="h-4 w-4" stroke={1.5} />
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
