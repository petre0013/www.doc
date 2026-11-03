import { useState, useRef, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { IconCheck, IconLoader2 } from '@tabler/icons-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/ui/PasswordInput';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import { ThemeSegments, AccentSegments, WidthSegments } from '@/components/ThemePicker';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { AVATAR_COLORS, avatarStyle, initials } from '@/lib/avatar';
import { isEmail } from '@/lib/utils';

function SaveButton({ saving, success, disabled = false, label = 'Save changes' }) {
    return (
        <Button type="submit" disabled={saving || disabled}>
            {saving
                ? <IconLoader2 className="h-3.5 w-3.5 animate-spin" stroke={1.5} />
                : success
                ? <IconCheck className="h-3.5 w-3.5" stroke={1.5} />
                : null
            }
            {saving ? 'Saving…' : success ? 'Saved' : label}
        </Button>
    );
}

function FieldError({ error }) {
    if (!error) return null;
    return <p className="mt-1 text-xs text-danger">{error}</p>;
}

export default function ProfilePage({ user }) {
    const { flash } = usePage().props;

    // ── Profile form ──────────────────────────────────────────────────────────
    const [name,        setName]        = useState(user.name);
    const [email,       setEmail]       = useState(user.email);
    const [avatarColor, setAvatarColor] = useState(user.avatar_color ?? 'sage');
    const [profSaving,  setProfSaving]  = useState(false);
    const [profSuccess, setProfSuccess] = useState(!!flash?.profile_success);
    const [profErrors,  setProfErrors]  = useState({});

    function saveProfile(e) {
        e.preventDefault();
        setProfSaving(true);
        setProfErrors({});
        router.patch('/settings/profile', { name, email }, {
            preserveScroll: true,
            onSuccess: () => { setProfSuccess(true); setTimeout(() => setProfSuccess(false), 3000); },
            onError:   (errs) => setProfErrors(errs),
            onFinish:  () => setProfSaving(false),
        });
    }

    // ── Avatar colour (autosaves on pick, debounced) ───────────────────────────
    const [colorSaved, setColorSaved] = useState(false);
    const saveTimer  = useRef(null);
    const savedTimer = useRef(null);

    // Clean up pending timers if the user leaves the page mid-debounce.
    useEffect(() => () => {
        clearTimeout(saveTimer.current);
        clearTimeout(savedTimer.current);
    }, []);

    function pickColor(key) {
        if (key === avatarColor) return;
        setAvatarColor(key); // optimistic, immediate

        // Debounce the request so rapid swatch clicks coalesce into one save.
        clearTimeout(saveTimer.current);
        saveTimer.current = setTimeout(() => {
            // Send the persisted name/email so the request validates without pulling
            // in any unsaved edits to those fields — only the colour is committed.
            router.patch('/settings/profile', { name: user.name, email: user.email, avatar_color: key }, {
                preserveScroll: true,
                onSuccess: () => {
                    setColorSaved(true);
                    clearTimeout(savedTimer.current);
                    savedTimer.current = setTimeout(() => setColorSaved(false), 4000);
                },
                onError: () => setAvatarColor(user.avatar_color ?? 'sage'),
            });
        }, 500);
    }

    // ── Password form ─────────────────────────────────────────────────────────
    const [currentPw,  setCurrentPw]  = useState('');
    const [newPw,      setNewPw]      = useState('');
    const [confirmPw,  setConfirmPw]  = useState('');
    const [pwSaving,   setPwSaving]   = useState(false);
    const [pwSuccess,  setPwSuccess]  = useState(!!flash?.password_success);
    const [pwErrors,   setPwErrors]   = useState({});

    function savePassword(e) {
        e.preventDefault();
        setPwSaving(true);
        setPwErrors({});
        router.patch('/settings/password', {
            current_password:      currentPw,
            password:              newPw,
            password_confirmation: confirmPw,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setPwSuccess(true);
                setCurrentPw(''); setNewPw(''); setConfirmPw('');
                setTimeout(() => setPwSuccess(false), 3000);
            },
            onError:  (errs) => setPwErrors(errs),
            onFinish: () => setPwSaving(false),
        });
    }

    // Gate the submit buttons: profile only when name/email actually changed;
    // password only once all three fields are filled (all are required).
    const profileDirty = name !== user.name || email !== user.email;
    const emailInvalid = email !== '' && !isEmail(email);
    const profileSavable = profileDirty && isEmail(email);

    const pwFilled = currentPw !== '' && newPw !== '' && confirmPw !== '';
    const pwMismatch = confirmPw !== '' && newPw !== confirmPw;
    const pwSavable = pwFilled && newPw.length >= 8 && newPw === confirmPw;

    // Warn before leaving with unsaved profile (name/email) changes.
    const dirtyRef = useRef(false);
    dirtyRef.current = profileDirty;
    const { promptOpen, confirmDiscard, dismissPrompt } = useUnsavedChangesGuard({
        active: true,
        dirtyRef,
        revert: () => { setName(user.name); setEmail(user.email); },
    });

    return (
        <SettingsLayout>
            <Head title="Profile — Settings" />

            {/* ── Appearance ───────────────────────────────────────────────── */}
            <Card className="mb-6">
                <CardHeader>
                    <CardTitle className="text-sm font-semibold text-foreground">Appearance</CardTitle>
                    <CardDescription>Theme for this browser — "System" follows your OS setting.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <ThemeSegments />
                    <div className="space-y-2">
                        <p className="text-[11px] font-semibold uppercase tracking-wider text-text-tertiary">Accent</p>
                        <AccentSegments />
                    </div>
                    <div className="space-y-2">
                        <p className="text-[11px] font-semibold uppercase tracking-wider text-text-tertiary">Page width</p>
                        <WidthSegments />
                        <p className="text-xs text-text-tertiary">Applies to pages and the editor.</p>
                    </div>
                </CardContent>
            </Card>

            {/* ── Avatar ───────────────────────────────────────────────────── */}
            <Card className="mb-6">
                <CardHeader className="flex flex-row items-center justify-between space-y-0">
                    <div className="space-y-1">
                        <CardTitle className="text-sm font-semibold text-foreground">Avatar</CardTitle>
                        <CardDescription>Pick an accent colour — it saves automatically.</CardDescription>
                    </div>
                    {colorSaved && (
                        <span className="flex items-center gap-1 text-xs text-accent-600">
                            <IconCheck className="h-3.5 w-3.5" stroke={1.5} /> Saved
                        </span>
                    )}
                </CardHeader>
                <CardContent className="flex items-center gap-6">
                    <div
                        className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full text-lg font-semibold"
                        style={avatarStyle(avatarColor)}
                    >
                        {initials(name || user.name)}
                    </div>
                    <div className="flex flex-wrap gap-2.5">
                        {Object.entries(AVATAR_COLORS).map(([key, val]) => (
                            <button
                                key={key}
                                type="button"
                                title={val.label}
                                onClick={() => pickColor(key)}
                                className="h-7 w-7 rounded-full transition-transform hover:scale-110 focus:outline-none"
                                style={{
                                    backgroundColor: val.bg,
                                    boxShadow: avatarColor === key
                                        ? `0 0 0 2px white, 0 0 0 4px ${val.text}`
                                        : 'none',
                                }}
                            />
                        ))}
                    </div>
                </CardContent>
            </Card>

            {/* ── Profile information ───────────────────────────────────────── */}
            <form onSubmit={saveProfile}>
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="text-sm font-semibold text-foreground">Profile information</CardTitle>
                        <CardDescription>Update your name and email address.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div>
                            <label htmlFor="name" className="mb-1.5 block text-sm font-medium text-foreground">
                                Name
                            </label>
                            <Input
                                id="name"
                                value={name}
                                onChange={e => setName(e.target.value)}
                                placeholder="Your name"
                            />
                            <FieldError error={profErrors.name} />
                        </div>
                        <div>
                            <label htmlFor="email" className="mb-1.5 block text-sm font-medium text-foreground">
                                Email
                            </label>
                            <Input
                                id="email"
                                type="email"
                                value={email}
                                onChange={e => setEmail(e.target.value)}
                                placeholder="you@example.com"
                            />
                            {profErrors.email
                                ? <FieldError error={profErrors.email} />
                                : emailInvalid && <p className="mt-1 text-xs text-danger">Enter a valid email address.</p>}
                        </div>
                    </CardContent>
                </Card>
                <div className="mb-8 mt-4 flex items-center justify-end">
                    <SaveButton saving={profSaving} success={profSuccess} disabled={!profileSavable} />
                </div>
            </form>

            {/* ── Change password ───────────────────────────────────────────── */}
            <form onSubmit={savePassword}>
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-semibold text-foreground">Change password</CardTitle>
                        <CardDescription>Use a strong password of at least 8 characters.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div>
                            <label htmlFor="current_password" className="mb-1.5 block text-sm font-medium text-foreground">
                                Current password
                            </label>
                            <PasswordInput
                                id="current_password"
                                value={currentPw}
                                onChange={e => setCurrentPw(e.target.value)}
                                placeholder="Enter current password"
                            />
                            <FieldError error={pwErrors.current_password} />
                        </div>
                        <div>
                            <label htmlFor="new_password" className="mb-1.5 block text-sm font-medium text-foreground">
                                New password
                            </label>
                            <PasswordInput
                                id="new_password"
                                value={newPw}
                                onChange={e => setNewPw(e.target.value)}
                                placeholder="At least 8 characters"
                            />
                            <FieldError error={pwErrors.password} />
                        </div>
                        <div>
                            <label htmlFor="confirm_password" className="mb-1.5 block text-sm font-medium text-foreground">
                                Confirm new password
                            </label>
                            <PasswordInput
                                id="confirm_password"
                                value={confirmPw}
                                onChange={e => setConfirmPw(e.target.value)}
                                placeholder="Repeat new password"
                            />
                            {pwErrors.password_confirmation
                                ? <FieldError error={pwErrors.password_confirmation} />
                                : pwMismatch && <p className="mt-1 text-xs text-danger">Passwords don't match.</p>}
                        </div>
                    </CardContent>
                </Card>
                <div className="mt-4 flex items-center justify-end">
                    <SaveButton saving={pwSaving} success={pwSuccess} disabled={!pwSavable} label="Update password" />
                </div>
            </form>

            <ConfirmDialog
                open={promptOpen}
                title="Discard changes?"
                message="You have unsaved profile changes. Leaving this page will discard them."
                confirmLabel="Discard changes"
                cancelLabel="Keep editing"
                variant="danger"
                onConfirm={confirmDiscard}
                onCancel={dismissPrompt}
            />
        </SettingsLayout>
    );
}
