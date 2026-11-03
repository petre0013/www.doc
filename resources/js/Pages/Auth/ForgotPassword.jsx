import { useForm, Head, Link } from '@inertiajs/react';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { isEmail } from '@/lib/utils';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function submit(e) {
        e.preventDefault();
        post('/forgot-password');
    }

    return (
        <>
            <Head title="Reset your password" />
            <div className="min-h-screen flex items-center justify-center bg-background px-4">
                <div className="w-full max-w-md">
                    <Card>
                        <CardHeader className="px-8 pt-10 pb-0">
                            <CardTitle className="text-2xl">Forgot password?</CardTitle>
                            <CardDescription>We'll email you a link to set a new one.</CardDescription>
                        </CardHeader>
                        <CardContent className="px-8 pb-10 pt-8">
                            {status && (
                                <div className="mb-5 rounded-md border border-accent-200 bg-accent-50 px-3.5 py-2.5 text-sm text-accent-700">
                                    {status}
                                </div>
                            )}
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
                                    {errors.email
                                        ? <p className="mt-1.5 text-xs text-danger">{errors.email}</p>
                                        : data.email && !isEmail(data.email) && (
                                            <p className="mt-1.5 text-xs text-danger">Enter a valid email address.</p>
                                        )}
                                </div>
                                <Button type="submit" disabled={processing || !isEmail(data.email)} className="w-full">
                                    {processing ? 'Sending…' : 'Email password reset link'}
                                </Button>
                            </form>
                            <p className="mt-6 text-center text-sm text-text-secondary">
                                <Link href="/login" className="text-accent-600 hover:underline">Back to sign in</Link>
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
