import { useState } from 'react';
import { Head, useForm, router, usePage } from '@inertiajs/react';
import { IconTrash, IconLoader2, IconInfoCircle } from '@tabler/icons-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/ui/PasswordInput';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import { avatarStyle, initials } from '@/lib/avatar';
import { isEmail } from '@/lib/utils';

function RoleSelect({ value, onChange, roles, disabled }) {
    return (
        <select
            value={value}
            disabled={disabled}
            onChange={(e) => onChange(e.target.value)}
            className="ui-select h-8 rounded-sm border border-border bg-surface px-2 text-sm capitalize text-foreground disabled:cursor-not-allowed disabled:opacity-50"
        >
            {roles.map((role) => (
                <option key={role} value={role} className="capitalize">{role}</option>
            ))}
        </select>
    );
}

function FieldError({ error }) {
    return error ? <p className="mt-1 text-xs text-danger">{error}</p> : null;
}

export default function Users() {
    const { users, roles, auth } = usePage().props;
    const [confirm, setConfirm] = useState(null); // user pending deletion
    const [deleting, setDeleting] = useState(false);

    const form = useForm({ name: '', email: '', password: '', password_confirmation: '', role: 'editor' });

    function createUser(e) {
        e.preventDefault();
        form.post('/admin/users', { preserveScroll: true, onSuccess: () => form.reset() });
    }

    const f = form.data;
    const emailInvalid = f.email !== '' && !isEmail(f.email);
    const passwordsMismatch = f.password_confirmation !== '' && f.password !== f.password_confirmation;
    const canCreate = f.name.trim() !== '' && isEmail(f.email)
        && f.password.length >= 8 && f.password === f.password_confirmation;

    function changeRole(user, role) {
        router.patch(`/admin/users/${user.id}`, { role }, { preserveScroll: true });
    }

    function deleteUser() {
        setDeleting(true);
        router.delete(`/admin/users/${confirm.id}`, {
            preserveScroll: true,
            onFinish: () => { setDeleting(false); setConfirm(null); },
        });
    }

    return (
        <SettingsLayout>
            <Head title="Users — Admin" />

            {/* User list */}
            <Card className="mb-6">
                <CardHeader>
                    <CardTitle className="text-sm font-semibold text-foreground">Team members</CardTitle>
                    <CardDescription className="flex items-center gap-1.5">
                        Manage who has access to this knowledge base.
                        <TooltipProvider>
                            <Tooltip delayDuration={200}>
                                <TooltipTrigger type="button" className="inline-flex items-center justify-center text-text-tertiary hover:text-text-secondary focus:outline-none cursor-help">
                                    <IconInfoCircle className="h-4 w-4" stroke={1.5} />
                                </TooltipTrigger>
                                <TooltipContent 
                                    side="right" 
                                    sideOffset={6}
                                    className="max-w-[260px] space-y-2"
                                >
                                    <p><strong>Admin:</strong> Full access. Can delete content, manage settings, and handle backups.</p>
                                    <p><strong>Editor:</strong> Can write and edit content. Cannot delete anything.</p>
                                    <p><strong>Viewer:</strong> Read-only access.</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="divide-y divide-border">
                        {users.map((user) => {
                    const isSelf = user.id === auth.user.id;
                    return (
                        <div key={user.id} className="flex items-center gap-3 px-4 py-3">
                            <div
                                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold"
                                style={avatarStyle(user.avatar_color)}
                            >
                                {initials(user.name)}
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="truncate font-medium text-foreground" title={user.name}>
                                    {user.name}
                                    {isSelf && <span className="ml-1.5 text-xs font-normal text-text-tertiary">(you)</span>}
                                </p>
                                <p className="truncate text-xs text-text-secondary" title={user.email}>{user.email}</p>
                            </div>

                            <RoleSelect
                                value={user.role ?? 'viewer'}
                                roles={roles}
                                onChange={(role) => changeRole(user, role)}
                            />

                            <button
                                type="button"
                                onClick={() => setConfirm(user)}
                                disabled={isSelf}
                                title={isSelf ? 'You cannot delete your own account' : 'Delete user'}
                                className="flex h-8 w-8 items-center justify-center rounded-sm text-text-tertiary transition-colors hover:bg-surface-hover hover:text-danger disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:text-text-tertiary"
                            >
                                <IconTrash className="h-4 w-4" stroke={1.5} />
                            </button>
                        </div>
                    );
                        })}
                    </div>
                </CardContent>
            </Card>

            {/* Add a user */}
            <form onSubmit={createUser}>
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-semibold text-foreground">Add a user</CardTitle>
                        <CardDescription>Create a new account with a specific role.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label className="mb-1 block text-sm font-medium text-foreground">Name</label>
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        <FieldError error={form.errors.name} />
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium text-foreground">Email</label>
                        <Input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        {form.errors.email
                            ? <FieldError error={form.errors.email} />
                            : emailInvalid && <p className="mt-1 text-xs text-danger">Enter a valid email address.</p>}
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium text-foreground">Password</label>
                        <PasswordInput value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                        <FieldError error={form.errors.password} />
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium text-foreground">Confirm password</label>
                        <PasswordInput value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} />
                        {passwordsMismatch && <p className="mt-1 text-xs text-danger">Passwords don't match.</p>}
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium text-foreground">Role</label>
                        <RoleSelect value={form.data.role} roles={roles} onChange={(role) => form.setData('role', role)} />
                    </div>
                </div>
            </CardContent>
                </Card>
                <div className="mt-4 flex items-center justify-end">
                    <Button type="submit" disabled={form.processing || !canCreate}>
                        {form.processing && <IconLoader2 className="h-3.5 w-3.5 animate-spin" stroke={1.5} />}
                        {form.processing ? 'Adding…' : 'Add user'}
                    </Button>
                </div>
            </form>

            <ConfirmDialog
                open={Boolean(confirm)}
                busy={deleting}
                title="Delete user"
                message={confirm ? `Delete ${confirm.name}? This permanently removes their account.` : ''}
                confirmLabel="Delete user"
                onConfirm={deleteUser}
                onCancel={() => setConfirm(null)}
            />
        </SettingsLayout>
    );
}
