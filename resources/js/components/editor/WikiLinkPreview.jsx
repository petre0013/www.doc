import { useState, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { router } from '@inertiajs/react';
import { IconFileText, IconAlertTriangle, IconLoader2, IconPlus } from '@tabler/icons-react';

const CARD_WIDTH = 288;

function cardPosition(rect) {
    const left = Math.min(
        Math.max(8, rect.left),
        window.innerWidth - CARD_WIDTH - 8,
    );
    const spaceBelow = window.innerHeight - rect.bottom;
    const top = spaceBelow >= 140 ? rect.bottom + 6 : rect.top - 6;
    const anchorBottom = spaceBelow < 140; // card goes above the link

    return { left, top, anchorBottom };
}

export default function WikiLinkPreview({ canCreate = false, workspaceId = null }) {
    const [hover, setHover]     = useState(null);  // { title, href, broken, rect }
    const [preview, setPreview] = useState(null);  // { id, title, excerpt }
    const [loading, setLoading] = useState(false);
    const [creating, setCreating] = useState(false);
    const abortRef              = useRef(null);
    const lastHrefRef           = useRef(null);
    const hideTimer             = useRef(null);

    // Listen for events dispatched by the WikiLink nodeView
    useEffect(() => {
        function onShow(e) { clearTimeout(hideTimer.current); setHover(e.detail); }
        function onHide() {
            // Delay the close so the mouse can move from the link into the card.
            clearTimeout(hideTimer.current);
            hideTimer.current = setTimeout(() => setHover(null), 120);
        }

        document.addEventListener('wiki-link-preview-show', onShow);
        document.addEventListener('wiki-link-preview-hide', onHide);
        return () => {
            document.removeEventListener('wiki-link-preview-show', onShow);
            document.removeEventListener('wiki-link-preview-hide', onHide);
            clearTimeout(hideTimer.current);
        };
    }, []);

    // Fetch preview when a resolved link is hovered
    useEffect(() => {
        if (!hover || hover.broken) {
            setPreview(null);
            return;
        }

        const docId = hover.href?.match(/\/documents\/(\d+)/)?.[1];
        if (!docId) return;

        // Already fetched this doc — reuse
        if (lastHrefRef.current === hover.href && preview) return;

        abortRef.current?.abort();
        const ctrl = new AbortController();
        abortRef.current = ctrl;

        lastHrefRef.current = hover.href;
        setPreview(null);
        setLoading(true);

        fetch(`/documents/${docId}/preview`, {
            headers: { Accept: 'application/json' },
            signal: ctrl.signal,
        })
            .then((r) => r.json())
            .then((data) => { setPreview(data); setLoading(false); })
            .catch(() => setLoading(false));

        return () => ctrl.abort();
    }, [hover?.href]);

    if (!hover) return null;

    const { left, top, anchorBottom } = cardPosition(hover.rect);

    const style = {
        position:  'fixed',
        left,
        width:     CARD_WIDTH,
        zIndex:    9999,
        ...(anchorBottom
            ? { bottom: window.innerHeight - hover.rect.top + 6 }
            : { top }),
    };

    const card = hover.broken ? (
        // ── Broken / unresolved link ──────────────────────────────────────
        <div
            style={style}
            onMouseEnter={() => clearTimeout(hideTimer.current)}
            onMouseLeave={() => setHover(null)}
            className="overflow-hidden rounded-md border border-warning/40 bg-surface shadow-md"
        >
            <div className="flex items-start gap-2.5 px-3.5 py-3">
                <IconAlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning" stroke={1.5} />
                <div className="min-w-0">
                    <p className="text-[13px] font-medium text-foreground">
                        &ldquo;{hover.title}&rdquo;
                    </p>
                    <p className="mt-0.5 text-xs text-text-secondary">
                        This page doesn&rsquo;t exist yet.
                    </p>
                    {canCreate && (
                        <button
                            type="button"
                            disabled={creating}
                            onClick={() => {
                                // Guard against a double click creating two pages
                                // with the same title before the redirect lands.
                                setCreating(true);
                                router.post('/documents',
                                    { title: hover.title, workspace_id: workspaceId },
                                    { onFinish: () => setCreating(false) },
                                );
                            }}
                            className="mt-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-surface px-2 py-1 text-xs font-medium text-accent-600 hover:bg-surface-hover disabled:pointer-events-none disabled:opacity-60"
                        >
                            {creating
                                ? <IconLoader2 size={13} stroke={1.5} className="animate-spin" />
                                : <IconPlus size={13} stroke={1.5} />}
                            {creating ? 'Creating…' : 'Create page'}
                        </button>
                    )}
                </div>
            </div>
        </div>
    ) : (
        // ── Resolved link preview ─────────────────────────────────────────
        <div
            style={style}
            className="overflow-hidden rounded-md border border-border bg-surface shadow-md"
        >
            {loading && !preview ? (
                <div className="flex items-center gap-2 px-3.5 py-3 text-xs text-text-tertiary">
                    <IconLoader2 className="h-3 w-3 animate-spin" stroke={1.5} />
                    Loading…
                </div>
            ) : preview ? (
                <>
                    <div className="flex items-center gap-2 border-b border-border-subtle bg-surface-hover px-3.5 py-2.5">
                        <IconFileText className="h-3.5 w-3.5 shrink-0 text-text-tertiary" stroke={1.5} />
                        <p className="truncate text-[13px] font-medium text-foreground">
                            {preview.title}
                        </p>
                    </div>
                    {preview.excerpt && (
                        <p className="px-3.5 py-2.5 text-xs leading-relaxed text-text-secondary line-clamp-4">
                            {preview.excerpt}
                        </p>
                    )}
                </>
            ) : null}
        </div>
    );

    return createPortal(card, document.body);
}
