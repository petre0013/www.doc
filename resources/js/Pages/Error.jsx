import { Head, Link } from '@inertiajs/react';
import { IconArrowLeft } from '@tabler/icons-react';

const MESSAGES = {
    403: { title: 'Forbidden', body: "You don't have permission to view this page." },
    404: { title: 'Page not found', body: "This page doesn't exist or may have been moved." },
    419: { title: 'Page expired', body: 'Your session timed out. Please go back and try again.' },
    500: { title: 'Server error', body: 'Something went wrong on our end. Please try again.' },
    503: { title: 'Unavailable', body: "We're down for maintenance. Please check back shortly." },
};

export default function ErrorPage({ status }) {
    const { title, body } = MESSAGES[status] ?? {
        title: 'Something went wrong',
        body: 'An unexpected error occurred.',
    };

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-background px-6 text-center">
            <Head title={`${status} — ${title}`} />

            <p className="text-6xl font-bold tracking-tight text-accent-600">{status}</p>
            <h1 className="mt-4 text-xl font-semibold text-foreground">{title}</h1>
            <p className="mt-2 max-w-sm text-sm text-text-secondary">{body}</p>

            <Link
                href="/"
                className="mt-6 inline-flex items-center gap-1.5 rounded-sm bg-primary px-3.5 py-2 text-sm font-medium text-text-inverse transition-opacity hover:opacity-90"
            >
                <IconArrowLeft className="h-4 w-4" stroke={1.5} />
                Back to home
            </Link>
        </div>
    );
}
