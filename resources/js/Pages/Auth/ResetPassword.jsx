import { useForm, Head } from '@inertiajs/react';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/ui/PasswordInput';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { isEmail } from '@/lib/utils';

export default function ResetPassword({ token, email }) {
    const { data, setData, post, processing, errors } = useForm({
        token,
        email: email || '',
        password: '',
        password_confirmation: '',
    });

    function submit(e) {
        e.preventDefault();
        post('/reset-password');
    }

    const pwMismatch = data.password_confirmation !== '' && data.password !== data.password_confirmation;
    const canReset = isEmail(data.email) && data.password.length >= 8 && data.password === data.password_confirmation;

    return (
        <>
            <Head title="Set a new password" />
            <div className="min-h-screen flex items-center justify-center bg-background px-4">
                <div className="w-full max-w-md">
                    <Card>
                        <CardHeader className="px-8 pt-10 pb-0">
                            <CardTitle className="text-2xl">Set a new password</CardTitle>
                            <CardDescription>Choose a new password for your account.</CardDescription>
                        </CardHeader>
                        <CardContent className="px-8 pb-10 pt-8">
                            <form onSubmit={submit} className="space-y-5">
                                <div>
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        autoComplete="email"
                                        required
                                    />
                                    {errors.email && <p className="mt-1.5 text-xs text-danger">{errors.email}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="password">New password</Label>
                                    <PasswordInput
                                        id="password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        autoComplete="new-password"
                                        required
                                    />
                                    {errors.password && <p className="mt-1.5 text-xs text-danger">{errors.password}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="password_confirmation">Confirm new password</Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        value={data.password_confirmation}
                                        onChange={(e) => setData('password_confirmation', e.target.value)}
                                        autoComplete="new-password"
                                        required
                                    />
                                    {pwMismatch && <p className="mt-1.5 text-xs text-danger">Passwords don't match.</p>}
                                </div>
                                <Button type="submit" disabled={processing || !canReset} className="w-full">
                                    {processing ? 'Resetting…' : 'Reset password'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
