import { useEffect } from 'react';

// How many locks are currently engaged, and the body overflow we replaced.
// Ref-counted so stacked modals (e.g. a confirm over the progress modal) don't
// release the lock until the LAST one closes.
let lockCount = 0;
let savedOverflow = '';

/**
 * Locks <body> scroll while `active` is true. Safe to use from several modals at
 * once — the original overflow is restored only when the final lock releases.
 *
 * @param {boolean} active whether this consumer wants the scroll locked
 */
export function useScrollLock(active) {
    useEffect(() => {
        if (!active) return;

        if (lockCount === 0) {
            savedOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
        }
        lockCount += 1;

        return () => {
            lockCount -= 1;
            if (lockCount === 0) {
                document.body.style.overflow = savedOverflow;
            }
        };
    }, [active]);
}
