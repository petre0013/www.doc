import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { IconX, IconLoader2 } from '@tabler/icons-react';
import { Button } from '@/components/ui/button';
import { useScrollLock } from '@/hooks/useScrollLock';

/**
 * Custom confirmation dialog matching the project design system.
 * Renders into document.body via a portal.
 *
 * Props:
 *   open          – whether the dialog is visible
 *   title         – heading text
 *   message       – body text
 *   confirmLabel  – confirm button label  (default "Confirm")
 *   cancelLabel   – cancel button label   (default "Cancel")
 *   variant       – 'danger' | 'primary'  (default "danger")
 *   busy          – request in flight: disables both buttons (and Escape/
 *                   backdrop close) so the action can't fire twice. Pass it
 *                   whenever the dialog stays open until the request finishes.
 *   onConfirm     – called when the confirm button is clicked
 *   onCancel      – called when Cancel or × is clicked
 */
export default function ConfirmDialog({
    open,
    title,
    message,
    confirmLabel = 'Confirm',
    cancelLabel  = 'Cancel',
    variant      = 'danger',
    busy         = false,
    onConfirm,
    onCancel,
}) {
    // Lock body scroll while open.
    useScrollLock(open);

    // Close on Escape
    useEffect(() => {
        if (!open || busy) return;
        function onKey(e) {
            if (e.key === 'Escape') onCancel?.();
        }
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open, busy, onCancel]);

    if (!open) return null;

    return createPortal(
        <div
            className="fixed inset-0 z-50 flex items-center justify-center p-6"
            style={{ background: 'rgba(31, 37, 32, 0.42)' }}
            onMouseDown={(e) => { if (e.target === e.currentTarget && !busy) onCancel?.(); }}
        >
            <div className="w-full max-w-md overflow-hidden rounded-[14px] bg-surface"
                 style={{ boxShadow: 'var(--shadow-lg)' }}>

                {/* Header */}
                <div className="flex items-center justify-between border-b border-border-subtle px-5 py-4">
                    <h2 className="text-[15px] font-medium text-foreground">{title}</h2>
                    <button
                        type="button"
                        onClick={onCancel}
                        disabled={busy}
                        className="flex h-7 w-7 items-center justify-center rounded-sm text-text-tertiary transition-colors hover:bg-surface-hover hover:text-foreground disabled:pointer-events-none disabled:opacity-50"
                    >
                        <IconX className="h-4 w-4" stroke={1.5} />
                    </button>
                </div>

                {/* Body */}
                <div className="px-5 py-4">
                    <p className="text-sm leading-relaxed text-text-secondary">{message}</p>
                </div>

                {/* Footer */}
                <div className="flex justify-end gap-2 border-t border-border-subtle bg-canvas px-5 py-3.5">
                    <Button type="button" variant="outline" onClick={onCancel} disabled={busy}>
                        {cancelLabel}
                    </Button>
                    <Button
                        type="button"
                        variant={variant === 'danger' ? 'destructive' : 'default'}
                        onClick={onConfirm}
                        disabled={busy}
                    >
                        {busy && <IconLoader2 className="h-3.5 w-3.5 animate-spin" stroke={1.5} />}
                        {confirmLabel}
                    </Button>
                </div>
            </div>
        </div>,
        document.body
    );
}
