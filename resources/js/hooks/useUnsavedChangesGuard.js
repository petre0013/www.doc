import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';

/**
 * Guards an editing/reordering mode against losing unsaved work.
 *
 * While `active`, it:
 *  - warns before a browser close/refresh (native dialog), and
 *  - intercepts in-app GET navigation — a page link, breadcrumb, or sidebar nav.
 *    The visit is paused, the project's discard modal is shown, and on confirm the
 *    original destination is resumed after `revert()` restores local state.
 *
 * Only GET visits are guarded: a non-GET visit is either the owner's own save or
 * an in-page action (e.g. creating a tag) that isn't "leaving", so it must pass
 * through. Modes that expose leaving POSTs (New page/workspace) hide those
 * controls while active instead.
 *
 * @param {object}  opts
 * @param {boolean} opts.active   whether the guard is engaged
 * @param {React.MutableRefObject<boolean>} opts.dirtyRef  unsaved-changes flag
 * @param {() => void} opts.revert  leave the mode and restore the server state
 * @returns {{ promptOpen: boolean, requestLeave: () => void,
 *            confirmDiscard: () => void, dismissPrompt: () => void }}
 */
export function useUnsavedChangesGuard({ active, dirtyRef, revert }) {
    const [promptOpen, setPromptOpen] = useState(false);
    const pendingVisit = useRef(null);

    // Keep the latest revert without re-subscribing the global listener.
    const revertRef = useRef(revert);
    revertRef.current = revert;

    useEffect(() => {
        if (!active) return;

        const beforeUnload = (e) => { if (dirtyRef.current) e.preventDefault(); };
        window.addEventListener('beforeunload', beforeUnload);

        const stop = router.on('before', (event) => {
            const visit = event.detail.visit;
            // A clean state navigates normally; non-GET visits (the owner's own
            // save, or an in-page POST like creating a tag) are never "leaving".
            // Partial reloads (`only:` — e.g. a status poll) stay on the same
            // page fetching props, so they're not "leaving" either.
            if (!dirtyRef.current
                || String(visit.method).toLowerCase() !== 'get'
                || visit.only?.length) return;
            // Pause this navigation and ask; resume it only if they discard.
            pendingVisit.current = visit;
            setPromptOpen(true);
            return false;
        });

        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            stop();
        };
    }, [active, dirtyRef]);

    /** Ask to leave from a non-navigating control (e.g. a Cancel button). */
    function requestLeave() {
        if (!dirtyRef.current) {
            revertRef.current?.();
            return;
        }
        pendingVisit.current = null;
        setPromptOpen(true);
    }

    /** Discard unsaved work, then resume any paused navigation. */
    function confirmDiscard() {
        dirtyRef.current = false;
        setPromptOpen(false);
        revertRef.current?.();

        const visit = pendingVisit.current;
        pendingVisit.current = null;
        if (visit) {
            router.visit(visit.url, {
                method: visit.method,
                data: visit.data,
                headers: visit.headers,
                replace: visit.replace,
                preserveScroll: visit.preserveScroll,
                preserveState: visit.preserveState,
                only: visit.only,
                except: visit.except,
                forceFormData: visit.forceFormData,
                errorBag: visit.errorBag,
                queryStringArrayFormat: visit.queryStringArrayFormat,
            });
        }
    }

    /** Keep editing/reordering — drop any paused navigation. */
    function dismissPrompt() {
        pendingVisit.current = null;
        setPromptOpen(false);
    }

    return { promptOpen, requestLeave, confirmDiscard, dismissPrompt };
}
