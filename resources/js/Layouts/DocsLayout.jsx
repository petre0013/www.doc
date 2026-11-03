import { useState, useEffect } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { toast } from 'sonner';
import { IconSearch, IconSettings, IconLogout, IconMenu2, IconX, IconAlertTriangle, IconCircleCheck } from '@tabler/icons-react';
import { Toaster } from '@/components/ui/sonner';
import { avatarStyle, initials } from '@/lib/avatar';

function NavLink({ href, children }) {
    const { url } = usePage();
    const active = url.startsWith(href);

    return (
        <Link
            href={href}
            className={
                'rounded-sm px-2.5 py-1.5 text-sm transition-colors duration-150 ' +
                (active
                    ? 'bg-accent text-accent-foreground'
                    : 'text-text-secondary hover:bg-surface-hover hover:text-foreground')
            }
        >
            {children}
        </Link>
    );
}

const NAV_LINKS = [
    { label: 'Workspaces', href: '/workspaces' },
    { label: 'Tags',       href: '/tags' },
];

// Pages render DocsLayout inline (no persistent Inertia layout), so it
// REMOUNTS on every cross-page navigation. This flag distinguishes the first
// mount of a real browser page load (module evaluates fresh → false) from a
// remount after an Inertia visit (module state survives → true), so the boot
// toast effect below can't re-fire a flash the `success` listener already
// showed — e.g. trash a workspace (Show → Index redirect) toasted twice.
let bootFlashShown = false;

// A backup notice is an error if the run failed OR its report email failed.
function noticeIsError(n) {
    return n.status === 'failed' || !!n.report_error;
}

function noticeText(n) {
    if (n.status === 'failed') {
        const why = n.error ? `: ${n.error}` : '.';
        return n.report_error
            ? `Backup failed${why} The report email also failed: ${n.report_error}`
            : `Backup failed${why}`;
    }
    return n.report_error
        ? `Backup completed, but the report email could not be sent: ${n.report_error}`
        : 'Backup completed successfully.';
}

// `wideable` opts the content area into the user's page-width preference
// (data-width on <html>, set in Settings › Profile › Appearance). Document
// view/editor pass it; admin and list pages stay boxed by design.
export default function DocsLayout({ children, wideable = false }) {
    const { auth, flash, backupNotices = [] } = usePage().props;
    const [searchQ, setSearchQ] = useState('');
    const [mobileNav, setMobileNav] = useState(false);

    function dismissNotice(id) {
        router.post(`/admin/backups/${id}/acknowledge`, {}, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    // Surface server flash as toasts (fixed-position, scroll-independent).
    // Driven by Inertia's per-visit `success` event rather than the `flash`
    // prop's identity: a repeated action (e.g. saving the same settings twice)
    // returns an identical flash payload, and Inertia may reuse the prop
    // reference — so an effect keyed on `[flash]` would fire only the first
    // time. The event fires once per completed request, so every save toasts.
    useEffect(() => {
        return router.on('success', (event) => {
            const f = event.detail.page.props.flash;
            if (f?.success) toast.success(f.success);
            if (f?.error) toast.error(f.error);
        });
    }, []);

    // Cover flash present on the very first (non-Inertia) page load, which the
    // `success` event above doesn't see. Guarded so it runs once per BROWSER
    // load, not once per layout remount (see bootFlashShown above).
    useEffect(() => {
        if (bootFlashShown) return;
        bootFlashShown = true;
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function logout(e) {
        e.preventDefault();
        router.post('/logout');
    }

    function handleSearch(e) {
        e.preventDefault();
        if (searchQ.trim()) {
            router.get('/search', { q: searchQ.trim() });
        }
    }

    return (
        <div className="min-h-screen bg-background">
            <header className="border-b border-border bg-card">
                <div className="mx-auto max-w-7xl flex h-12 items-center gap-4 px-5">
                    {/* Brand + docs nav */}
                    <div className="flex shrink-0 items-center gap-2 sm:gap-5">
                        <button
                            type="button"
                            onClick={() => setMobileNav((v) => !v)}
                            aria-label="Toggle navigation"
                            className="flex h-8 w-8 items-center justify-center rounded-sm text-text-secondary transition-colors hover:bg-surface-hover hover:text-foreground sm:hidden"
                        >
                            {mobileNav
                                ? <IconX className="h-5 w-5" stroke={1.5} />
                                : <IconMenu2 className="h-5 w-5" stroke={1.5} />}
                        </button>
                        <Link href="/workspaces" className="flex items-center gap-2">
                            <img src="/favicon.svg" className="h-5 w-5" alt="" />
                            <span className="text-[15px] font-semibold text-foreground">
                                <span className="font-normal">www.</span><span className="font-extrabold">doc</span>
                            </span>
                        </Link>
                        <nav className="hidden items-center gap-0.5 sm:flex">
                            {NAV_LINKS.map((link) => (
                                <NavLink key={link.href} href={link.href}>{link.label}</NavLink>
                            ))}
                        </nav>
                    </div>

                    {/* Search */}
                    <form onSubmit={handleSearch} className="relative flex-1 max-w-xs">
                        <IconSearch
                            className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-text-tertiary"
                            stroke={1.5}
                        />
                        <input
                            type="text"
                            value={searchQ}
                            onChange={(e) => setSearchQ(e.target.value)}
                            placeholder="Search docs…"
                            className="h-8 w-full rounded-sm border border-border bg-canvas pl-8 pr-3 text-sm text-foreground placeholder:text-text-tertiary outline-none transition-[border-color,box-shadow] duration-150 focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                        />
                    </form>

                    {/* Actions */}
                    {auth?.user && (
                        <div className="ml-auto flex shrink-0 items-center gap-1">
                            <div
                                title={auth.user.name}
                                className="flex h-7 w-7 items-center justify-center rounded-full text-[11px] font-semibold"
                                style={avatarStyle(auth.user.avatar_color)}
                            >
                                {initials(auth.user.name)}
                            </div>
                            <Link
                                href="/settings/profile"
                                title="Settings"
                                className="flex h-8 w-8 items-center justify-center rounded-sm text-text-secondary transition-colors hover:bg-surface-hover hover:text-foreground"
                            >
                                <IconSettings className="h-4 w-4" stroke={1.5} />
                            </Link>
                            <button
                                type="button"
                                onClick={logout}
                                title="Sign out"
                                className="flex h-8 w-8 items-center justify-center rounded-sm text-text-secondary transition-colors hover:bg-surface-hover hover:text-foreground"
                            >
                                <IconLogout className="h-4 w-4" stroke={1.5} />
                            </button>
                        </div>
                    )}
                </div>

                {/* Mobile nav — revealed by the hamburger on small screens */}
                {mobileNav && (
                    <nav className="border-t border-border px-3 py-2 sm:hidden">
                        {NAV_LINKS.map((link) => (
                            <Link
                                key={link.href}
                                href={link.href}
                                onClick={() => setMobileNav(false)}
                                className="block rounded-sm px-2.5 py-2 text-sm text-text-secondary transition-colors hover:bg-surface-hover hover:text-foreground"
                            >
                                {link.label}
                            </Link>
                        ))}
                    </nav>
                )}
            </header>

            {/* Persistent, dismissable backup notices (admin-only; shared prop).
                Survive refreshes until acknowledged — the durable counterpart to
                the transient toasts. */}
            {backupNotices.map((n) => {
                const isErr = noticeIsError(n);
                const Icon = isErr ? IconAlertTriangle : IconCircleCheck;
                return (
                    <div
                        key={n.id}
                        className={`flex items-start gap-2 border-b px-5 py-2.5 text-sm ${
                            isErr
                                ? 'border-danger-border bg-danger-surface text-danger'
                                : 'border-accent-200 bg-accent-50 text-accent-700'
                        }`}
                    >
                        <Icon className="mt-0.5 h-4 w-4 shrink-0" stroke={1.5} />
                        <span className="flex-1">{noticeText(n)}</span>
                        <button
                            type="button"
                            onClick={() => dismissNotice(n.id)}
                            className="shrink-0 rounded-sm p-0.5 opacity-70 transition-opacity hover:opacity-100"
                            title="Dismiss"
                        >
                            <IconX className="h-4 w-4" stroke={1.5} />
                        </button>
                    </div>
                );
            })}

            <main className={`mx-auto max-w-7xl px-5 py-6 ${wideable ? '[[data-width=full]_&]:max-w-none' : ''}`}>{children}</main>
            <Toaster />
        </div>
    );
}
