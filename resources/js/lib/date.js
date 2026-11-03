// European date formatting (dd.mm.yyyy). One place so every page agrees and the
// separator/leading-zeros stay consistent regardless of the browser locale.

function toDate(value) {
    const d = value instanceof Date ? value : new Date(value);
    return isNaN(d.getTime()) ? null : d;
}

/** "dd.mm.yyyy" — e.g. 27.06.2026. Returns '' for empty/invalid input. */
export function formatDate(value) {
    const d = toDate(value);
    if (!d) return '';
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    return `${dd}.${mm}.${d.getFullYear()}`;
}

/** "dd.mm.yyyy, HH:mm" — 24-hour clock. */
export function formatDateTime(value) {
    const d = toDate(value);
    if (!d) return '';
    const hh = String(d.getHours()).padStart(2, '0');
    const min = String(d.getMinutes()).padStart(2, '0');
    return `${formatDate(d)}, ${hh}:${min}`;
}

/**
 * Relative age for list rows — "just now", "5m ago", "3h ago", "12d ago",
 * then the absolute date once it's older than ~30 days. Returns null for
 * empty/invalid input; callers render their own placeholder (`?? '—'`).
 */
export function timeAgo(value) {
    const d = toDate(value);
    if (!d) return null;
    const diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diff < 60)      return 'just now';
    if (diff < 3600)    return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400)   return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 2592000) return `${Math.floor(diff / 86400)}d ago`;
    return formatDate(d);
}
