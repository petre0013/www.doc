import { useForm, Head, Link } from '@inertiajs/react';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/ui/PasswordInput';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e) {
        e.preventDefault();
        post('/login');
    }

    return (
        <>
            <Head title="Sign in" />
            <div className="min-h-screen flex items-center justify-center bg-background px-4">
                <div className="w-full max-w-md">
                    <Card>
                        <CardHeader className="px-8 pt-10 pb-0">
                            <CardTitle className="text-2xl"><span className="font-normal">www.</span><span className="font-extrabold">doc</span></CardTitle>
                            <CardDescription>Sign in to continue</CardDescription>
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
                                        placeholder="admin@example.com"
                                        required
                                    />
                                    {errors.email && <p className="mt-1.5 text-xs text-danger">{errors.email}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="password">Password</Label>
                                    <PasswordInput
                                        id="password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        autoComplete="current-password"
                                        placeholder="•••••••••"
                                        required
                                    />
                                    {errors.password && <p className="mt-1.5 text-xs text-danger">{errors.password}</p>}
                                </div>
                                <label htmlFor="remember" className="flex items-center gap-2 text-sm text-text-secondary">
                                    <input
                                        id="remember"
                                        type="checkbox"
                                        className="h-3.5 w-3.5 accent-accent-400"
                                        checked={data.remember}
                                        onChange={(e) => setData('remember', e.target.checked)}
                                    />
                                    Keep me signed in
                                </label>
                                <Button type="submit" disabled={processing} className="w-full">
                                    {processing ? 'Signing in…' : 'Sign in'}
                                </Button>
                            </form>
                            <p className="mt-6 text-center text-sm text-text-secondary">
                                <Link href="/forgot-password" className="text-accent-600 hover:underline">Forgot your password?</Link>
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
