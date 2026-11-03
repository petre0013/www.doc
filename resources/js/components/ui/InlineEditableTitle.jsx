import { useState, useRef, useEffect } from 'react';
import { IconPencil } from '@tabler/icons-react';

/**
 * A title that renames in place. At rest it's plain text with an explicit pencil
 * button beside it (revealed on hover) — that button is the affordance that starts
 * editing, so a title never looks like static text you're secretly meant to click.
 * Enter or blur commits, Escape cancels; an empty/unchanged value reverts silently.
 * `onCommit(trimmed)` fires only on a real change (the caller persists it and
 * reverts on error, since the shown text follows `value` once the caller reloads).
 *
 * There's no navigation link here, so a rename can never navigate away — the whole
 * point of "clicking a title shouldn't let you leave" in Edit mode.
 */
export default function InlineEditableTitle({
    value, onCommit, className = '', inputClassName = '', ariaLabel = 'Rename',
}) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(value);
    const inputRef = useRef(null);

    // Keep the draft in sync with the source of truth whenever we're not editing
    // (a server reload or an external rename should be reflected).
    useEffect(() => { if (!editing) setDraft(value); }, [value, editing]);

    useEffect(() => {
        if (editing && inputRef.current) {
            inputRef.current.focus();
            inputRef.current.select();
        }
    }, [editing]);

    function commit() {
        const trimmed = draft.trim();
        setEditing(false);
        if (!trimmed || trimmed === value) { setDraft(value); return; } // no-op / empty → revert
        onCommit(trimmed);
    }

    function cancel() {
        setDraft(value);
        setEditing(false);
    }

    if (!editing) {
        return (
            <span className={`inline-flex min-w-0 items-center gap-1.5 ${className}`}>
                <span className="truncate" title={value}>{value}</span>
                {/* Always visible in Edit mode — the clear, explicit "rename this"
                    affordance so a title never reads as static text. */}
                <button
                    type="button"
                    onClick={() => setEditing(true)}
                    aria-label={ariaLabel}
                    title={ariaLabel}
                    className="flex h-5 w-5 shrink-0 items-center justify-center rounded-sm text-text-tertiary transition-colors hover:bg-surface-hover hover:text-accent-600"
                >
                    <IconPencil className="h-3.5 w-3.5" stroke={1.5} />
                </button>
            </span>
        );
    }

    return (
        <input
            ref={inputRef}
            value={draft}
            aria-label={ariaLabel}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={commit}
            onKeyDown={(e) => {
                // Consume the keys we act on: Escape means "cancel this edit",
                // never "close the surrounding dialog" or fire a page shortcut.
                if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); commit(); }
                else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cancel(); }
            }}
            className={`min-w-0 flex-1 rounded-sm border border-accent-400 bg-surface px-1.5 py-0.5 text-foreground outline-none ring-[3px] ring-accent-200 ${inputClassName}`}
        />
    );
}
