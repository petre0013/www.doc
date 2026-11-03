import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { toast } from 'sonner';
import { initTheme } from '@/lib/theme';

initTheme();

// An expired session (419) would otherwise surface as Inertia's raw error
// modal — an HTML dump over whatever the user was doing, with any unsaved
// editor draft trapped behind it. Keep the page (and the draft) and explain
// instead. Toaster only mounts inside DocsLayout: on pages without one (login,
// password reset, setup wizard) a toast would vanish silently, and there's no
// draft to protect — reload for a fresh CSRF token instead.
router.on('invalid', (event) => {
    if (event.detail.response?.status !== 419) return;
    event.preventDefault();
    if (!document.querySelector('[data-sonner-toaster]')) {
        window.location.reload();
        return;
    }
    toast.error('Your session has expired. Sign in again in another tab, then retry — your unsaved changes are still here.', {
        duration: 10000,
    });
});

createInertiaApp({
    title: (title) => title ? `${title} — www.doc` : 'www.doc',
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });
        return pages[`./Pages/${name}.jsx`];
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        // Seed color only — app.css overrides #nprogress with var(--accent-400)
        // so the bar follows the active accent live.
        color: '#7E9D72',
    },
});
