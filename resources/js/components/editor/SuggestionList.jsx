import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';

/**
 * Generic suggestion dropdown for both wiki-link autocomplete and slash commands.
 *
 * `suggestion` is the TipTap suggestion props object (items, command, clientRect).
 * `keyHandlerRef` is a ref.current that is set to a keyboard handler function
 *   so that the TipTap extension can delegate keydown events here.
 * `renderItem(item)` returns the label to display for each item.
 */
export default function SuggestionList({ suggestion, keyHandlerRef, renderItem }) {
    const [selectedIndex, setSelectedIndex] = useState(0);

    useEffect(() => {
        setSelectedIndex(0);
    }, [suggestion.items]);

    // Register keydown handler every render so it captures the latest selectedIndex.
    useEffect(() => {
        keyHandlerRef.current = (event) => {
            const { items } = suggestion;

            if (event.key === 'ArrowDown') {
                setSelectedIndex((i) => Math.min(i + 1, items.length - 1));
                return true;
            }
            if (event.key === 'ArrowUp') {
                setSelectedIndex((i) => Math.max(i - 1, 0));
                return true;
            }
            if (event.key === 'Enter') {
                if (items[selectedIndex]) suggestion.command(items[selectedIndex]);
                return true;
            }
            return false;
        };

        return () => {
            keyHandlerRef.current = null;
        };
    });

    if (!suggestion.items.length) return null;

    const rect = suggestion.clientRect?.();
    if (!rect) return null;

    const style = {
        position: 'fixed',
        top: rect.bottom + 4,
        left: rect.left,
        zIndex: 9999,
        minWidth: 180,
        maxWidth: 280,
    };

    return createPortal(
        <div
            style={style}
            className="rounded-md border border-border bg-surface shadow-md overflow-hidden py-1"
        >
            {suggestion.items.map((item, index) => (
                <button
                    key={index}
                    type="button"
                    onMouseDown={(e) => {
                        e.preventDefault(); // prevent editor blur
                        suggestion.command(item);
                    }}
                    onMouseEnter={() => setSelectedIndex(index)}
                    className={`w-full text-left px-3 py-1.5 text-sm transition-colors ${
                        index === selectedIndex
                            ? 'bg-accent-100 text-accent-600'
                            : 'text-foreground hover:bg-surface-hover'
                    }`}
                >
                    {renderItem(item)}
                </button>
            ))}
        </div>,
        document.body
    );
}
